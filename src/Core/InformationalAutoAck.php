<?php
declare(strict_types=1);
namespace Berserk\Core;

/** HTTP-triggered, server-only producer of ordinary journaled confirm_strike commands. */
final class InformationalAutoAck
{
    public function __construct(private Db $db, private GameRepository $repo, private Engine $engine) {}

    public function check(GameState $state, string $requester): GameState
    {
        return $this->checkOutcome($state, $requester)->state;
    }

    public function checkOutcome(GameState $state, string $requester): AutomaticCommandResult
    {
        if (!in_array($requester, ['host', 'player'], true)) return new AutomaticCommandResult($state, true, 'invalid_requester');
        $wait = InformationalWaitManager::due($state, $this->engine->serverNow());
        if ($wait === null || $state->persistenceVersion === null) return new AutomaticCommandResult($state, true, 'not_due'); // No journal or write on normal reads.
        $journal = new CommandJournalRepository($this->db);
        if ($journal->findProcessingAutoAck($state->gameId, $wait['wait_id']) !== null) return new AutomaticCommandResult($this->reload($state), false, 'processing');
        $actor = $wait['required_player'];
        $commandId = 'p2a:' . $wait['wait_id'] . ':' . $state->persistenceVersion;
        $command = new Command('confirm_strike', ['wait_id'=>(string) $wait['wait_id']]);
        $attempt = $journal->registerProcessing($state->gameId, $actor, $commandId, $command->type, $command->payload, $state->persistenceVersion);
        if (!$attempt['is_new']) return new AutomaticCommandResult($this->reload($state), $attempt['status'] !== CommandJournalRepository::STATUS_PROCESSING, (string) $attempt['status']);
        $id = (int) $attempt['id'];
        try {
            $this->repo->executeLockedCommand($state, $journal, $id, function (GameState $locked) use ($wait, $actor, $command, $journal, $id): Result {
                $current = InformationalWaitManager::due($locked, $this->engine->serverNow());
                if ($current !== $wait || $journal->findProcessingAutoAck($locked->gameId, $wait['wait_id'], $id) !== null) {
                    return Result::error('auto_ack_not_current');
                }
                return $this->engine->apply($locked, $actor, $command);
            });
        } catch (StaleStateException) {
            $fresh = $this->reload($state);
            $journal->markConflict($id, $fresh->persistenceVersion, 'stale_state', 'Auto ACK state changed');
        } catch (TransactionCommitUnknownException|TransactionRollbackUnknownException) {
            // Never turn an unknown outcome into failed or launch a competing command.
            // A fresh read observes atomic game+journal commit, or leaves processing for recovery.
            return new AutomaticCommandResult($this->reload($state), false, 'unknown_transaction');
        } catch (DbQueryException) {
            // Repository reached this catch only after an acknowledged rollback.
            $journal->markFailed($id, $state->persistenceVersion, 'persistence_failed');
        }
        return new AutomaticCommandResult($this->reload($state), true, 'completed');
    }

    private function reload(GameState $fallback): GameState
    {
        return $this->repo->findById($fallback->gameId) ?? $fallback;
    }
}
