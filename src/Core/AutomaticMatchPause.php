<?php
declare(strict_types=1);
namespace Berserk\Core;

/** HTTP-only deadline producer. No scheduler and no client-owned timestamps. */
final class AutomaticMatchPause
{
    public function __construct(private Db $db, private GameRepository $repo, private Engine $engine) {}
    public function check(GameState $state, string $requester): AutomaticCommandResult
    {
        if (!in_array($requester,['host','player'],true) || !MatchPause::due($state,$this->engine->serverNow())) {
            return new AutomaticCommandResult($state,true,'not_due');
        }
        if ($state->persistenceVersion===null) return new AutomaticCommandResult($state,false,'missing_version');
        $p=$state->battle['match_pause'];
        $journal=new CommandJournalRepository($this->db);
        // A stable version-scoped identity also makes concurrent HTTP producers coalesce.
        $id='mpx:'.$p['generation'].':'.$state->persistenceVersion;
        // A definitely rolled-back failure may retry. All contenders derive the same next ID.
        while (true) {
            $previous=$journal->find($state->gameId,$p['initiator'],$id);
            if ($previous===null || !in_array($previous['status'],[CommandJournalRepository::STATUS_FAILED,CommandJournalRepository::STATUS_CONFLICT],true)) break;
            $id='mpx:'.$p['generation'].':'.$state->persistenceVersion.':'.dechex((int)$previous['id']);
        }
        $a=$journal->registerProcessing($state->gameId,$p['initiator'],$id,'expire_pause',[], $state->persistenceVersion);
        // Recovery is restricted to this automatic command and reuses its journal row.
        // The executor locks journal FIRST, then game, before revalidating the version
        // and deadline. A live executor either finishes before us, or waits and later
        // observes our terminal journal status. No age-based guess or second ID.
        if ($a['is_new'] || $a['status'] === CommandJournalRepository::STATUS_PROCESSING) {
            try {
                $this->repo->executeLockedCommand($state,$journal,(int)$a['id'],function(GameState $locked)use($p):Result {
                    if (($locked->battle['match_pause']['request_id']??null)!==$p['request_id']) return Result::error('pause_stale_request');
                    return MatchPause::expire($locked,$this->engine->serverNow());
                });
            } catch (CommandJournalException $e) {
                // Another executor may have committed/rejected while we waited for its lock.
                $resolved = $journal->findById((int)$a['id']);
                if ($resolved === null || !$journal->isTerminalStatus((string)$resolved['status'])) throw $e;
            } catch (StaleStateException) {
                $fresh=$this->repo->findById($state->gameId);
                $journal->markConflict((int)$a['id'],$fresh?->persistenceVersion ?? $state->persistenceVersion,'stale_state');
            } catch (TransactionCommitUnknownException|TransactionRollbackUnknownException) {
                return new AutomaticCommandResult($this->repo->findById($state->gameId)??$state,false,'unknown_transaction');
            } catch (DbQueryException) {
                $journal->markFailed((int)$a['id'],$state->persistenceVersion,'persistence_failed');
            }
        }
        $fresh=$this->repo->findById($state->gameId)??$state;
        $record=$journal->findById((int)$a['id']);
        return new AutomaticCommandResult($fresh,$record!==null && $record['status']!==CommandJournalRepository::STATUS_PROCESSING,
            (string)($record['status']??'unknown'));
    }
}
