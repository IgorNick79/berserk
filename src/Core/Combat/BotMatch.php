<?php
declare(strict_types=1);

namespace Berserk\Core\Combat;

use Berserk\Auth\GameAccess;
use Berserk\Core\{Command, CommandJournalRepository, Db, DbQueryException, Engine, GameRepository, GameState, Result, StaleStateException};
use Berserk\Core\Legal\LegalActions;
use Berserk\Core\Observation\PlayerObservation;

/** Bounded server-side work driven by the host's authenticated battle polling. */
final class BotMatch
{
    public static function bound(Db $db, int $game, int $host, mixed $configuredBot): ?array
    {
        if (!is_int($configuredBot) || $configuredBot <= 0 || $configuredBot === $host) return null;
        return $db->fetchOne("SELECT bot_account_id,bot_visited FROM lobby_rooms
            WHERE state='started' AND game_id=".$game." AND creator_account_id=".$host."
            AND accepted_account_id=".$configuredBot." AND bot_account_id=".$configuredBot);
    }

    public static function tick(Db $db, GameRepository $repo, GameState $state, int $host, int $bot): array
    {
        $journal = new CommandJournalRepository($db);
        for ($step = 0; $step < 4 && $state->status === 'battle'; $step++) {
            $binding = self::bound($db, $state->gameId, $host, $bot);
            if ($binding === null || GameAccess::resolve($db, $state->gameId, $bot) !== 'player') {
                return [$state, 'bot_unavailable'];
            }
            $version = $state->persistenceVersion;
            if ($version === null) return [$state, 'bot_unavailable'];
            $period = $state->battle['instant_reservation']['period_id'] ?? null;
            $saved = json_decode($binding['bot_visited'] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
            $visited = ($saved['period'] ?? null) === $period && $period !== null ? $saved['cells'] : [];
            $now = time();
            $observation = PlayerObservation::build($state, 'player');
            $legal = LegalActions::build($state, 'player', $now);
            foreach ($observation['cards'] as $card) {
                if ($card['owner'] === 'player' && $card['zone'] === 'field') {
                    $visited[$card['id']][$card['row'].':'.$card['col']] = true;
                }
            }
            $decision = CombatDecisionPlanner::decide($observation, $legal, $visited);
            $action = $decision['action'];
            if ($action === null) {
                if ($decision['reason'] === 'waiting' || $legal['decision'] === 'paused') return [$state, null];
                $reason = $db->escape(substr($version.':'.$decision['reason'], 0, 128));
                $db->query("UPDATE lobby_rooms r JOIN games g ON g.ind=r.game_id
                    SET r.bot_diagnostic='".$reason."' WHERE r.game_id=".$state->gameId." AND r.bot_account_id=".$bot
                    ." AND g.version=".$version." AND NOT (r.bot_diagnostic <=> '".$reason."')");
                return [$state, 'bot_unsupported'];
            }
            if (!in_array($action, $legal['actions'], true)) return [$state, 'bot_unavailable'];

            // One opportunity per persisted version, even if two poll requests race.
            $id = 'bot:'.$version;
            if (($prior = $journal->find($state->gameId, 'player', $id)) !== null) {
                return [$repo->findById($state->gameId) ?? $state, $prior['status'] === 'processing' ? 'bot_pending' : 'bot_stopped'];
            }
            $entry = $journal->registerProcessing($state->gameId, 'player', $id, $action['type'], $action['payload'], $version);
            if (!$entry['is_new']) return [$repo->findById($state->gameId) ?? $state, 'bot_pending'];
            $command = new Command($action['type'], $action['payload']);
            try {
                $repo->executeLockedCommand($state, $journal, (int) $entry['id'],
                    static function (GameState $locked) use ($db, $command, $host, $bot, $period, $visited): Result {
                        if (self::bound($db, $locked->gameId, $host, $bot) === null
                            || GameAccess::resolve($db, $locked->gameId, $bot) !== 'player') {
                            return Result::error('bot_binding_changed');
                        }
                        $result = (new Engine($db))->apply($locked, 'player', $command);
                        if ($result->success) {
                            $memory = $db->escape(json_encode(['period'=>$period, 'cells'=>$visited], JSON_THROW_ON_ERROR));
                            $db->query("UPDATE lobby_rooms SET bot_diagnostic=NULL, bot_visited='".$memory."' WHERE game_id=".$locked->gameId
                                ." AND bot_account_id=".$bot." AND creator_account_id=".$host);
                        }
                        return $result;
                    }, 'bot_command_rejected');
            } catch (StaleStateException) {
                $latest = $repo->findById($state->gameId) ?? $state;
                $journal->markConflict((int) $entry['id'], $latest->persistenceVersion ?? $version, 'stale_state');
                return [$latest, null];
            } catch (DbQueryException) {
                // executeLockedCommand only rethrows a SQL failure after a certain rollback.
                $journal->markFailed((int) $entry['id'], $version, 'bot_persistence_failed');
                return [$repo->findById($state->gameId) ?? $state, 'bot_stopped'];
            }
            $state = $repo->findById($state->gameId) ?? $state;
            if (($journal->findById((int) $entry['id'])['status'] ?? null) !== 'committed') {
                return [$state, 'bot_stopped'];
            }
        }
        return [$state, null];
    }
}
