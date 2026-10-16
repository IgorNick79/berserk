<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Server producer; the ordinary Engine/TurnProcessor and shared Journal/CAS do the work. */
final class AutomaticTurnEnd
{
    public function __construct(private Db $db, private GameRepository $repo, private Engine $engine) {}

    public function check(GameState $state, string $requester): AutomaticCommandResult
    {
        if(!in_array($requester,['host','player'],true)) return new AutomaticCommandResult($state,true,'invalid_requester');
        $eligible=TurnTimeoutEligibility::check($state,$this->engine->serverNow());
        if(!$eligible['allowed']) return new AutomaticCommandResult($state,true,$eligible['reason']);
        if($state->persistenceVersion===null) return new AutomaticCommandResult($state,true,'missing_persistence_version');
        $period=$eligible['period_id'];$actor=$eligible['owner'];
        $journal=new CommandJournalRepository($this->db);
        if($journal->findProcessingTurnEnd($state->gameId,$period)!==null) return $this->fresh($state,false,'processing');
        $commandId='t3e:'.$period.':'.$actor.':'.$state->persistenceVersion;
        $previous=$journal->latestTurnEndAttempt($state->gameId,$period,$actor,$state->persistenceVersion);
        if($previous!==null) {
            if(!in_array($previous['status'],[CommandJournalRepository::STATUS_FAILED,CommandJournalRepository::STATUS_CONFLICT],true)) {
                return $this->fresh($state,$previous['status']!==CommandJournalRepository::STATUS_PROCESSING,(string)$previous['status']);
            }
            // A definitely rolled-back failure may retry, but only after a fresh
            // read/eligibility check. The previous journal row supplies a stable
            // retry identity: concurrent requests choose the same next attempt.
            $fresh=$this->fresh($state,true,'retry_check');
            $check=TurnTimeoutEligibility::check($fresh->state,$this->engine->serverNow(),$period,$actor,
                $journal->findProcessingTurnEnd($state->gameId,$period)!==null);
            if(!$fresh->certain || !$check['allowed'] || $fresh->state->persistenceVersion!==$state->persistenceVersion) {
                return new AutomaticCommandResult($fresh->state,$fresh->certain && $check['reason']!=='processing','retry_state_changed');
            }
            $state=$fresh->state;
            $commandId.=':'.dechex((int)$previous['id']);
        }
        $command=new Command('end_turn',['period_id'=>$period]);
        $attempt=$journal->registerProcessing($state->gameId,$actor,$commandId,$command->type,$command->payload,$state->persistenceVersion);
        if(!$attempt['is_new']) return $this->fresh($state,$attempt['status']!==CommandJournalRepository::STATUS_PROCESSING,(string)$attempt['status']);
        $id=(int)$attempt['id'];
        try {
            $this->repo->executeLockedCommand($state,$journal,$id,function(GameState $locked)use($period,$actor,$journal,$id,$command):Result {
                $eligible=TurnTimeoutEligibility::check($locked,$this->engine->serverNow(),$period,$actor,
                    $journal->findProcessingTurnEnd($locked->gameId,$period,$id)!==null);
                if(!$eligible['allowed']) return Result::error('auto_end_'.$eligible['reason']);
                return $this->engine->apply($locked,$actor,$command);
            });
        } catch(StaleStateException) {
            $fresh=$this->fresh($state,true,'conflict');
            $journal->markConflict($id,$fresh->state->persistenceVersion,'stale_state','Auto end state changed');
            return $fresh;
        } catch(TransactionCommitUnknownException|TransactionRollbackUnknownException) {
            return $this->fresh($state,false,'unknown_transaction');
        } catch(DbQueryException) {
            $journal->markFailed($id,$state->persistenceVersion,'persistence_failed');
            return $this->fresh($state,true,'failed');
        }
        $record=$journal->findById($id);
        return $this->fresh($state,$record!==null && $record['status']!==CommandJournalRepository::STATUS_PROCESSING,(string)($record['status']??'unknown'));
    }

    private function fresh(GameState $state, bool $certain, string $reason): AutomaticCommandResult
    {
        $fresh=$this->repo->findById($state->gameId);
        return new AutomaticCommandResult($fresh??$state,$certain && $fresh!==null,$reason);
    }
}
