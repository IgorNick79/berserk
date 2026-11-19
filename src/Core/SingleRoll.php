<?php
declare(strict_types=1);
namespace Berserk\Core;

/** One persisted card-owned die and a native post-roll continuation; owns no independent stack. */
final class SingleRoll
{
    public function __construct(private GameState $state, private Engine $engine) {}

    public function begin(CardInstance $source, CardInstance $target, string $type, array $action,
        string $handler, array $returnTo = []): Result
    {
        if (!in_array($type,['tap','magic','shot','throw','discharge','uchr'],true)
            || $handler !== ($type==='uchr'?'uchr':'damage')) throw new \LogicException('Unsupported single roll');
        if (isset($this->state->battle['single_roll'])) return Result::error('single_roll_already_pending');
        $this->state->historyRecorder?->attackEvent($this->state,$source,$target,$type,'declared');
        $dice = CardRoll::roll($this->state, $source, $type);
        ActionResolution::bind($this->state, $action, $source, $type);
        $this->state->battle['single_roll'] = [
            'kind'=>$type, 'attacker_id'=>$source->instanceId, 'target_id'=>$target->instanceId,
            'owner'=>$source->owner, 'target_owner'=>$target->owner,
            'original_dice'=>$dice, 'attack_dice'=>$dice, 'attack_mod'=>0,
            'stage'=>'prepared', 'state'=>'prepared',
            'resources'=>['base_paid'=>true,'coins_at_roll'=>$source->coins,'closed_at_roll'=>$source->closed],
            ActionResolution::ID=>$action[ActionResolution::ID],
            'return_to'=>['handler'=>$handler,'action'=>$action]+$returnTo,
            'allow_windows'=>empty($this->state->battle['strike'])&&empty($this->state->battle['turn_instant_stack'])&&empty($this->state->battle['turn_instant_result']),
        ];
        // A resolving parent owns execution. Do not open another independent window above it.
        $nested = !empty($this->state->battle['strike']) || !empty($this->state->battle['turn_instant_stack'])
            || !empty($this->state->battle['turn_instant_result']);
        if (!$nested && (new InstantProcessor($this->state,$this->engine))->openWindow('combat',$source->owner,null,'dice')) {
            $this->state->battle['single_roll']['stage']='window';
            $this->state->bumpVersion();
            return Result::ok(['single_roll_waiting']);
        }
        return $this->resume();
    }

    public function suspendPassive(CardInstance $source, string $kind, int $dice, array $returnTo): bool
    {
        if (!in_array($kind,['shaksi','row_spell'],true)) throw new \LogicException('Unsupported passive roll');
        if (!empty($this->state->battle['single_roll']) || !empty($this->state->battle['strike'])
            || !empty($this->state->battle['turn_instant_stack'])) return false;
        $action=[];ActionResolution::bind($this->state,$action,$source,$kind,'passive');
        $this->state->battle['single_roll']=[
            'kind'=>$kind,'attacker_id'=>$source->instanceId,'target_id'=>$source->instanceId,
            'owner'=>$source->owner,'target_owner'=>$source->owner,'original_dice'=>$dice,'attack_dice'=>$dice,'attack_mod'=>0,
            'stage'=>'prepared','state'=>'prepared',ActionResolution::ID=>$action[ActionResolution::ID],
            'resources'=>['preparation_done'=>true],'return_to'=>$returnTo,
        ];
        if ((new InstantProcessor($this->state,$this->engine))->openWindow('combat',$source->owner,null,'dice')) {
            $this->state->battle['single_roll']['stage']='window';return true;
        }
        unset($this->state->battle['single_roll']);return false;
    }

    public function suspendValue(array $locals, string $handler): bool
    {
        if (empty($locals['action']['_instant_windows_allowed'])
            || !in_array($locals['type'],['tap','magic','uchr'],true)) return false;
        $this->state->battle['single_roll']=[
            'kind'=>$locals['type'],'attacker_id'=>$locals['cardId'],'target_id'=>$locals['targetId'],
            'owner'=>$locals['playerKey'],'target_owner'=>$this->state->getCard($locals['targetId'])->owner,
            'original_dice'=>$locals['action']['_primary_roll_original']??$locals['dice'],'attack_dice'=>$locals['dice'],'attack_mod'=>0,
            'stage'=>'window','state'=>'prepared','computed_value'=>$locals['val'],
            'resources'=>['base_paid'=>true,'variable_paid'=>true,'coins_spent'=>$locals['coinsSpent']??0],
            ActionResolution::ID=>$locals['action'][ActionResolution::ID]??0,
            'return_to'=>['handler'=>$handler,'locals'=>$locals],
        ];
        if ((new InstantProcessor($this->state,$this->engine))->openWindow('combat',$locals['playerKey'],null,'main')) return true;
        unset($this->state->battle['single_roll']);return false;
    }

    public function confirm(string $actor, Command $cmd): Result
    {
        $error=InformationalWaitManager::validateAck($this->state,$actor,$cmd->get('wait_id'));
        if ($error!==null) return Result::error($error);
        if (($this->state->battle['single_roll']['stage']??null)!=='results') return Result::error('No roll results');
        $this->state->battle['single_roll']['confirmed'][]=$actor;
        if (count($this->state->battle['single_roll']['confirmed'])===2) {
            $this->state->battle['single_roll']['stage']='window';
            InformationalWaitManager::publish($this->state);
            $this->resume();
        }
        $this->state->bumpVersion();
        return Result::ok(['single_roll_confirmed']);
    }

    public function resume(): Result
    {
        $roll=$this->state->battle['single_roll']??null;
        if (!$roll || !in_array($roll['stage'],['prepared','window'],true)) return Result::error('No resumable roll');
        // The previous stack has already resolved and both players acknowledged it.
        // Offer fresh decisions for the new dice without repeating any resolved item.
        if (!empty($roll['reroll_response_pending'])) {
            unset($this->state->battle['single_roll']['reroll_response_pending']);
            if ((new InstantProcessor($this->state, $this->engine))->openWindow('combat', $roll['owner'], null, 'dice')) {
                unset($this->state->battle['single_roll']['instant_summary']);
                $this->state->battle['single_roll']['confirmed'] = [];
                return Result::ok(['single_roll_waiting']);
            }
        }
        // Consume before dispatch: re-entry and a second command cannot apply this tail twice.
        unset($this->state->battle['single_roll']);
        $source=$this->state->getCard($roll['attacker_id']);
        $target=$this->state->getCard($roll['target_id']);
        if ($roll['stage']==='window' && ($this->state->status!=='battle' || $this->state->winner!==null
            || !$source || !$target || $source->dying || $target->dying || $source->hp<=0 || $target->hp<=0
            || $source->owner!==$roll['owner'] || $target->owner!==$roll['target_owner']
            || !in_array($source->zone,['field','flying'],true) || !in_array($target->zone,['field','flying'],true))) {
            if ($target && in_array($roll['return_to']['handler'],['damage','uchr','damage_value','uchr_value'],true)) $this->state->historyRecorder?->attackEvent($this->state,$source,$target,$roll['kind'],'result',['outcome'=>'cancelled','value'=>0]);
            if($source) ActionResolution::resolve($this->state,$roll,'cancelled',['hit'=>false]);
            if (in_array($roll['return_to']['handler'],['damage_value','uchr_value'],true)) (new ActionResolver($this->state,$this->engine))->cancelAttackValue($roll);
            $this->engine->finalizeDying($this->state);
            if ($roll['kind']==='row_spell') (new TurnPhaseProcessor($this->state,$this->engine))->resume();
            return Result::ok(['single_roll_cancelled']);
        }
        if ($source && in_array($roll['return_to']['handler'],['damage','uchr'],true))
            $this->state->historyRecorder?->die($this->state,$source,'final',(int)$roll['attack_dice']+(int)($roll['attack_mod']??0),['original'=>(int)$roll['original_dice'],'modifier'=>(int)($roll['attack_mod']??0)]);
        if (in_array($roll['return_to']['handler'],['damage_value','uchr_value'],true))
            return (new ActionResolver($this->state,$this->engine))->resumeAttackValue($roll);
        if (isset($roll['return_to']['action'])) {
            $roll['return_to']['action']['_instant_windows_allowed']=$roll['allow_windows']??true;
            $roll['return_to']['action']['_primary_roll_original']=$roll['original_dice'];
        }
        if ($roll['kind']==='shaksi') {
            (new Movement\MovementResolver($this->state,$this->engine))->continueJumpRoll($source,$roll['owner'],$roll['return_to']['config'],$roll['attack_dice']);
            return Result::ok(['shaksi_roll_resumed']);
        }
        if ($roll['kind']==='row_spell') {
            $to=$roll['return_to'];
            (new TurnPhaseProcessor($this->state,$this->engine))->continueRowSpellRoll($source,$roll['owner'],$to['action'],$to['x'],$to['enemies'],$to['events'],$roll['attack_dice']);
            return Result::ok(['row_spell_roll_resumed']);
        }
        return (new ActionResolver($this->state,$this->engine))->resumeSingleRoll($roll);
    }
}
