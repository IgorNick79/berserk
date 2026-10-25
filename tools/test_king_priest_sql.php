<?php
declare(strict_types=1);
// Also reruns the accepted persistence/mandatory journal regressions on their
// guarded, isolated InnoDB server. Never connects using production config.
require __DIR__ . '/test_effective_features_sql.php';

use Berserk\Core\{Command, EffectiveFeatures, Engine, GameRepository, GameState,
    InformationalWaitManager, MemoryLoss, Result};
use Berserk\Core\BattleHistory\ExperimentalHistory;

$kingSqlBefore = $checks;
function ksSeed(): array {
    global $plain, $db;
    $row = $plain->create(101, 202); $s = efState();
    $s->gameId = $row->gameId; $s->persistenceVersion = $row->persistenceVersion; $s->persistenceJson = $row->persistenceJson;
    $s->status = 'place'; $s->firstPlayer = 'host';
    $s->players['host']->side = 1; $s->players['player']->side = 2; $s->players['player']->confirm('place');
    $s->getCard(2)->prop = json_decode(file_get_contents(__DIR__ . '/../docs/king-priest.prop.json'), true, 512, JSON_THROW_ON_ERROR);
    $s->getCard(2)->coins = 3; $s->getCard(3)->hp = 15;
    $catalog = [];
    foreach ($s->cards as $c) $catalog[$c->ukid] = ['revision' => 'stage2b-sql', 'name' => $c->ukid,
        'image' => '', 'description' => '', 'type' => 'creature', 'element' => 'neutral', 'classes' => [], 'price' => 0];
    $plain->save($s);
    $repo = new GameRepository($db, new ExperimentalHistory([$s->gameId], $catalog, [$s->gameId]));
    fsExecute($repo, $s, 'confirm_place');
    for ($i = 0; $i < 10 && !empty($s->battle['turn_phase']); $i++) fsExecute($repo, $s, 'turn_ack');
    efCheck($s->status === 'battle' && $s->getCard(2)->coins === 3, 'real initial capture with save_coins');
    return [$repo, $s];
}
function ksApply(GameState $w, array $payload): Result {
    efCheck($w->historyRecorder !== null, 'new ability has mandatory recorder under lock');
    $r = (new Engine())->apply($w, 'host', new Command('action', $payload));
    efCheck($r->success, 'real action succeeds: ' . $r->error);
    return $r;
}
function ksAck(GameRepository $repo, GameState &$s): void {
    global $journal;
    foreach (['host', 'player'] as $owner) {
        $payload = ['wait_id' => InformationalWaitManager::token($s)];
        $entry = $journal->registerProcessing($s->gameId, $owner, 'kp_ack_' . bin2hex(random_bytes(8)), 'confirm_strike', $payload, $s->persistenceVersion);
        $repo->executeLockedCommand($s, $journal, (int) $entry['id'], static function(GameState $w) use ($owner, $payload): Result {
            $r = (new Engine())->apply($w, $owner, new Command('confirm_strike', $payload));
            efCheck($r->success, 'real result ACK'); return $r;
        });
        $s = $repo->findById($s->gameId);
    }
}

foreach (['memory_loss', 'heal'] as $key) {
    [$repo, $s] = ksSeed();
    $payload = ['card_id' => 2, 'target_id' => 3, 'action_key' => $key];
    $commandVersion = $s->persistenceVersion;
    $entry = fsRegister($s, 'action', $payload); $before = fsSnapshot($s);
    foreach (['json', 'sql', 'journal'] as $fault) {
        $failed = false;
        try {
            $repo->executeLockedCommand($s, $journal, (int) $entry['id'], static function(GameState $w) use ($payload, $fault, $db): Result {
                $r = ksApply($w, $payload);
                if ($fault === 'json') $w->battle['invalid_json_fixture'] = NAN;
                elseif ($fault === 'sql') $w->status = str_repeat('invalid_status_', 20);
                else $db->query("UPDATE battle_history_matches SET recording_status='disabled' WHERE game_id=" . $w->gameId);
                return $r;
            });
        } catch (TypeError $e) {
            if ($fault !== 'json' || !str_contains($e->getMessage(), 'Db::escape()')) throw $e;
            $failed = true;
        } catch (\Berserk\Core\DbQueryException $e) {
            if ($fault !== 'sql' || $e->getCode() !== 1406) throw $e;
            $failed = true;
        } catch (LogicException $e) {
            if ($fault !== 'journal') throw $e;
            $failed = true;
        }
        efCheck($failed && fsSnapshot($s) === $before, "$key/$fault atomic rollback of coins/tap/effect/usage/heal/journal");
        efCheck($journal->findById((int) $entry['id'])['status'] === 'processing', 'same command can retry after rollback');
    }
    $repo->executeLockedCommand($s, $journal, (int) $entry['id'], static fn(GameState $w): Result => ksApply($w, $payload));
    $s = $repo->findById($s->gameId);
    efCheck($s->getCard(2)->closed && $s->getCard(2)->coins === ($key === 'memory_loss' ? 2 : 3), 'saved exact cost/tap ' . $key);
    efCheck($s->getCard(3)->hp === ($key === 'heal' ? 17 : 15), 'saved actual health ' . $key);
    efCheck(EffectiveFeatures::suppressed($s->getCard(3)) === ($key === 'memory_loss')
        && !empty($s->getCard(2)->flags[MemoryLoss::USED]) === ($key === 'memory_loss'), 'saved link and once flag ' . $key);
    efCheck($journal->findById((int) $entry['id'])['status'] === 'committed', 'journal and ability committed together');
    $after = fsSnapshot($s);
    $duplicate = $journal->registerProcessing($s->gameId, 'host', $entry['command_id'], 'action', $payload, $commandVersion);
    efCheck(!$duplicate['is_new'] && $duplicate['status'] === 'committed' && fsSnapshot($s) === $after, 'same HTTP command ID never executes again ' . $key);
    ksAck($repo, $s);
    $before = fsSnapshot($s);
    $retry = fsRegister($s, 'action', $payload);
    $repo->executeLockedCommand($s, $journal, (int) $retry['id'], static function(GameState $w) use ($payload): Result {
        $r = (new Engine())->apply($w, 'host', new Command('action', $payload));
        efCheck(!$r->success, 'retry with new ID rejected'); return $r;
    });
    efCheck(fsSnapshot($s) === $before, 'new command ID after ACK cannot bypass closure ' . $key);
    $events = $db->fetchAll('SELECT event_type FROM battle_history_events WHERE game_id=' . $s->gameId);
    efCheck(in_array('ability.applied', array_column($events, 'event_type'), true), 'actual ability event in mandatory live journal ' . $key);
}
efCheck(fsSchema($db, $tables) === $schemaBefore, 'Stage 2B SQL schema unchanged');
echo 'King-priest SQL: ', $checks - $kingSqlBefore, " new checks passed; real isolated MariaDB/InnoDB, PHP CLI\n";
