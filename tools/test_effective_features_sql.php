<?php
declare(strict_types=1);
// Existing disposable schema only: no production config, migrations or DDL.
if (PHP_SAPI !== 'cli' || !in_array('--isolated', $argv, true)) {
    fwrite(STDERR, "Use --isolated against stage2a-final-validation/mysql-data on port 33991\n"); exit(2);
}
require __DIR__ . '/test_effective_features.php';

use Berserk\Core\{Command, CommandJournalRepository, Db, DbQueryException, EffectiveFeatures, Engine, GameRepository,
    GameState, IncarnationTokenProcessor, Result, StaleStateException, ZoneManager};

$sqlChecksBefore = $checks;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new Db(['host' => '127.0.0.1:33991', 'user' => 'root', 'pass' => '', 'name' => 'berserk_lucktest_stage3_c7a111282b']);
$proof = $db->fetchOne('SELECT @@port AS port,@@datadir AS dir,VERSION() AS version,DATABASE() AS db');
efCheck((int) $proof['port'] === 33991
    && str_ends_with(strtolower(str_replace('\\', '/', $proof['dir'])), '/stage2a-final-validation/mysql-data/'), 'verified disposable SQL server');
$tables = ['games', 'battle_command_journal', 'battle_history_matches', 'battle_history_actions',
    'battle_history_events', 'battle_history_steps', 'battle_history_batches'];
function fsSchema(Db $db, array $tables): array {
    $result = [];
    foreach ($tables as $table) {
        $status = $db->fetchOne("SHOW TABLE STATUS LIKE '$table'");
        efCheck($status !== null && strtolower($status['Engine']) === 'innodb', 'InnoDB ' . $table);
        $row = $db->fetchOne("SHOW CREATE TABLE $table");
        // AUTO_INCREMENT is data-dependent, not a structural schema change.
        $result[$table] = hash('sha256', preg_replace('/AUTO_INCREMENT=\d+ /', '', array_values($row)[1]));
    }
    return $result;
}
$schemaBefore = fsSchema($db, $tables);
$plain = new GameRepository($db); $journal = new CommandJournalRepository($db);
function fsRegister(GameState $s, string $type, array $payload = []): array {
    global $journal;
    return $journal->registerProcessing($s->gameId, 'host', 'stage2a_' . bin2hex(random_bytes(10)), $type, $payload, $s->persistenceVersion);
}
function fsExecute(GameRepository $repo, GameState &$s, string $type, array $payload = []): array {
    global $journal;
    $entry = fsRegister($s, $type, $payload);
    $repo->executeLockedCommand($s, $journal, (int) $entry['id'], static function(GameState $w) use ($type, $payload): Result {
        efCheck($w->historyRecorder !== null, 'mandatory recorder installed under lock');
        return (new Engine())->apply($w, 'host', new Command($type, $payload));
    });
    $s = $repo->findById($s->gameId);
    efCheck($journal->findById((int) $entry['id'])['status'] === 'committed', 'command committed');
    return $entry;
}
/** Install only the starting fixture under the native mandatory-journal lock. */
function fsStart(GameState $created, GameState $fixture, array $catalog): array {
    global $db, $journal;
    $repo = new GameRepository($db, $catalog);
    $entry = fsRegister($created, 'confirm_place');
    $repo->executeLockedCommand($created, $journal, (int) $entry['id'], static function(GameState $w) use ($fixture): Result {
        efCheck($w->journalSchema === 1 && $w->historyRecorder !== null, 'native creation marker and mandatory recorder retained');
        $w->cards = $fixture->cards; $w->players = $fixture->players;
        $w->nextInstanceId = $fixture->nextInstanceId;
        $w->status = 'place'; $w->firstPlayer = 'host'; $w->battle = $fixture->battle;
        return (new Engine())->apply($w, 'host', new Command('confirm_place'));
    });
    $s = $repo->findById($created->gameId);
    efCheck($journal->findById((int) $entry['id'])['status'] === 'committed', 'initial native command committed');
    for ($i = 0; $i < 10 && !empty($s->battle['turn_phase']); $i++) fsExecute($repo, $s, 'turn_ack');
    return [$repo, $s];
}
function fsSeed(): array {
    global $plain, $db;
    $row = $plain->create(101, 202); $s = efState();
    $s->gameId = $row->gameId; $s->persistenceVersion = $row->persistenceVersion; $s->persistenceJson = $row->persistenceJson;
    $s->status = 'place'; $s->firstPlayer = 'host';
    $s->players['host']->side = 1; $s->players['player']->side = 2; $s->players['player']->confirm('place');
    $s->getCard(2)->prop = ['on_incarnation_token' => ['value' => 2]];
    $catalog = [];
    foreach ($s->cards as $card) $catalog[$card->ukid] = ['revision' => 'stage2a-sql', 'name' => $card->ukid,
        'image' => '', 'description' => '', 'type' => 'creature', 'element' => 'neutral', 'classes' => [], 'price' => 0];
    [$repo, $s] = fsStart($row, $s, $catalog);
    efCheck($s->status === 'battle', 'real initial battle captured');
    return [$repo, $s];
}
function fsSnapshot(GameState $s): array {
    global $db;
    return ['game' => $db->fetchOne('SELECT json,version FROM games WHERE ind=' . $s->gameId),
        'meta' => $db->fetchOne('SELECT * FROM battle_history_matches WHERE game_id=' . $s->gameId),
        'events' => $db->fetchOne('SELECT COUNT(*) AS n FROM battle_history_events WHERE game_id=' . $s->gameId)['n'],
        'batches' => $db->fetchOne('SELECT COUNT(*) AS n FROM battle_history_batches WHERE game_id=' . $s->gameId)['n']];
}

[$repo, $s] = fsSeed();
// Stage 2B does not exist yet: install the infrastructure effect and real token
// reaction in a locked test callback after a real move. The same CAS/Recorder
// transaction must persist all three, or none of them.
$move = ['card_id' => 1, 'row' => 2, 'col' => 1]; $entry = fsRegister($s, 'move', $move);
$before = fsSnapshot($s);
$prepare = static function(GameState $w) use ($move): Result {
    efCheck($w->historyRecorder !== null, 'recorder during effect fixture');
    $r = (new Engine())->apply($w, 'host', new Command('move', $move));
    efCheck($r->success, 'fixture move');
    (new IncarnationTokenProcessor($w, new Engine()))->onTokenGranted($w->getCard(2), 1);
    EffectiveFeatures::applyMemoryLoss($w, $w->getCard(1), $w->getCard(2));
    return $r;
};
$failed = false;
try {
    $repo->executeLockedCommand($s, $journal, (int) $entry['id'], static function(GameState $w) use ($prepare): Result {
        $r = $prepare($w); $w->battle['invalid_json_fixture'] = NAN; return $r;
    });
} catch (TypeError $e) {
    // Existing save() passes failed json_encode() to Db::escape(string).
    if (!str_contains($e->getMessage(), 'Db::escape()')) throw $e;
    $failed = true;
}
efCheck($failed && fsSnapshot($s) === $before, 'save error rolls back effect, pending, move and recorder atomically');
efCheck($journal->findById((int) $entry['id'])['status'] === 'processing', 'failed transaction did not commit command');
$failed = false;
try {
    $repo->executeLockedCommand($s, $journal, (int) $entry['id'], static function(GameState $w) use ($prepare): Result {
        $r = $prepare($w); $w->status = str_repeat('invalid_status_', 20); return $r;
    });
} catch (DbQueryException $e) {
    if ($e->getCode() !== 1406) throw $e;
    $failed = true;
}
efCheck($failed && fsSnapshot($s) === $before, 'real SQL save error rolls back suppression, pending, move and observer');
$repo->executeLockedCommand($s, $journal, (int) $entry['id'], $prepare);
$s = $repo->findById($s->gameId);
efCheck(EffectiveFeatures::suppressed($s->getCard(2)) && isset($s->battle['pending_incarnation_wound']), 'effect and pending survive actual SQL reload');

$payload = ['target_ids' => [3]]; $choiceEntry = fsRegister($s, 'choose_incarnation_wound', $payload);
$before = fsSnapshot($s);
$failed = false;
try {
    $repo->executeLockedCommand($s, $journal, (int) $choiceEntry['id'], static function(GameState $w) use ($db, $payload): Result {
        $r = (new Engine())->apply($w, 'host', new Command('choose_incarnation_wound', $payload));
        efCheck($r->success, 'suppressed choice resolved before injected append failure');
        // Data-only fault inside this disposable transaction, no schema changes.
        $db->query("UPDATE battle_history_matches SET recording_status='disabled' WHERE game_id=" . $w->gameId);
        return $r;
    });
} catch (LogicException $e) { $failed = true; }
efCheck($failed && fsSnapshot($s) === $before, 'mandatory journal failure rolls back CAS save and pending consumption');
$stale = $repo->findById($s->gameId);
$repo->executeLockedCommand($s, $journal, (int) $choiceEntry['id'], static fn(GameState $w): Result =>
    (new Engine())->apply($w, 'host', new Command('choose_incarnation_wound', ['target_ids' => [3]])));
$s = $repo->findById($s->gameId);
efCheck(empty($s->battle['pending_incarnation_wound']) && $s->getCard(3)->hp === 20
    && EffectiveFeatures::suppressed($s->getCard(2)), 'successful retry commits only the suppressed result');
efCheck($journal->findById((int) $choiceEntry['id'])['status'] === 'committed', 'mandatory journal and game commit together');
$after = fsSnapshot($s);
$duplicate = $journal->registerProcessing($s->gameId, 'host', $choiceEntry['command_id'], 'choose_incarnation_wound', $payload, $stale->persistenceVersion);
efCheck(!$duplicate['is_new'] && $duplicate['status'] === 'committed' && fsSnapshot($s) === $after, 'accepted duplicate returns committed result without execution');
$conflictEntry = fsRegister($stale, 'choose_incarnation_wound', $payload); $ran = false; $conflict = false;
try {
    $repo->executeLockedCommand($stale, $journal, (int) $conflictEntry['id'], static function(GameState $w) use (&$ran): Result {
        $ran = true; return Result::ok();
    });
} catch (StaleStateException $e) { $conflict = true; }
efCheck($conflict && !$ran && fsSnapshot($s) === $after, 'CAS conflict rejects before execution and preserves recorder/game');
[$restoredRepo, $restored] = fsSeed();
$restoredEntry = fsRegister($restored, 'move', $move);
$restoredRepo->executeLockedCommand($restored, $journal, (int) $restoredEntry['id'], static function(GameState $w) use ($prepare): Result {
    $r = $prepare($w);
    (new ZoneManager($w))->toHand($w->getCard(1));
    return $r;
});
$restored = $restoredRepo->findById($restored->gameId);
efCheck(!EffectiveFeatures::suppressed($restored->getCard(2)) && isset($restored->battle['pending_incarnation_wound']), 'release and pending persisted together');
fsExecute($restoredRepo, $restored, 'choose_incarnation_wound', ['target_ids' => [3]]);
efCheck($restored->getCard(3)->hp === 18 && empty($restored->battle['pending_incarnation_wound']), 'restored unique effect executes once after real SQL reload');
efCheck(fsSchema($db, $tables) === $schemaBefore, 'SQL schema unchanged');
echo json_encode(['environment' => $proof, 'new_checks' => $checks - $sqlChecksBefore,
    'status' => 'PASS', 'fpm' => 'not used; PHP CLI with mysqli and real isolated MariaDB/InnoDB'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
