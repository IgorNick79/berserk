<?php
declare(strict_types=1);
namespace Berserk\Core;

final class MatriarchOrderProcessor
{
    public const PENDING = 'pending_matriarch_order';
    public function __construct(private GameState $state, private Engine $engine) {}

    public static function live(?CardInstance $card): bool
    {
        return $card !== null && $card->zone === CardInstance::ZONE_FIELD && !$card->dying
            && $card->hp > 0 && $card->row !== null && $card->col !== null
            && in_array($card->type, ['creature','fly'], true);
    }

    public static function adjacent(CardInstance $a, CardInstance $b): bool
    {
        return $a->instanceId !== $b->instanceId && abs($a->row-$b->row)<=1 && abs($a->col-$b->col)<=1;
    }

    public static function available(GameState $state, CardInstance $source): bool
    {
        return $state->status === 'battle' && $state->winner === null
            && ($state->battle['active'] ?? null) === $source->owner && self::live($source)
            && !CardStats::isDisabled($source) && $source->coins >= 1
            && empty($state->battle['matriarch_order']) && empty($state->battle['strike'])
            && !CombatContinuation::active($state);
    }

    /** Snapshot eligibility: protection is deliberately evaluated once, not per battle. */
    public static function recipients(GameState $state, string $owner, CardInstance $target): array
    {
        $ids=[];
        foreach($state->cards as $card) {
            if (self::live($card) && $card->owner === $owner && self::adjacent($card,$target)
                && !CardStats::hasStamina($state,$card)) $ids[]=$card->instanceId;
        }
        return $ids;
    }

    public static function targets(GameState $state, CardInstance $source): array
    {
        $ids=[];
        foreach($state->cards as $card) {
            if(self::live($card) && $card->owner !== $source->owner
                && self::recipients($state,$source->owner,$card)) $ids[]=$card->instanceId;
        }
        return $ids;
    }

    public function start(CardInstance $source, array $action): Result
    {
        if(!self::available($this->state,$source) || Choice\ChoiceRegistry::current($this->state)!==null
            || CombatContinuation::busy($this->state)) return Result::error('Приказ матриарха недоступен');
        $targets=self::targets($this->state,$source);
        if(!$targets) return Result::error('Нет доступных целей приказа');
        $this->state->battle[self::PENDING]=['owner'=>$source->owner,'source_id'=>$source->instanceId,
            'action_key'=>$action['key']??$action['type'],'kind'=>'target','ids'=>$targets,'id'=>bin2hex(random_bytes(16))];
        $this->state->bumpVersion();
        return Result::ok(['matriarch_order_target']);
    }

    public function choose(string $owner, Command $cmd): Result
    {
        $p=$this->state->battle[self::PENDING]??null;
        if(!$p || $p['owner']!==$owner || !hash_equals($p['id'],(string)$cmd->get('choice_id','')))
            return Result::error('Выбор приказа устарел');
        if($cmd->type==='cancel_pending') {
            if($p['kind']!=='target')return Result::error('Применённый приказ нельзя отменить');
            unset($this->state->battle[self::PENDING]);$this->state->bumpVersion();
            return Result::ok(['matriarch_order_cancelled']);
        }
        $id=(int)$cmd->get('target_id',0);
        if(!in_array($id,$p['ids'],true))return Result::error('Недопустимый выбор приказа');
        if($p['kind']==='target') {
            $source=$this->state->getCard($p['source_id']);$target=$this->state->getCard($id);
            if(!$source || !self::available($this->state,$source) || !self::live($target) || $target->owner===$owner)
                return Result::error('Источник или цель недоступны');
            $action=null;
            foreach($source->effectiveProp()['actions']??[] as $candidate)if(($candidate['key']??$candidate['type']??'')===$p['action_key'])$action=$candidate;
            if(!$action || ($action['type']??'')!=='impact' || ($action['effect']??'')!=='matriarch_order')
                return Result::error('Способность приказа недоступна');
            $ids=self::recipients($this->state,$owner,$target);
            if(!$ids)return Result::error('Нет получателей приказа');
            unset($this->state->battle[self::PENDING]);
            $source->coins-=1;$source->closed=true;
            $this->state->battle['matriarch_order']=['order_id'=>$p['id'],'source_id'=>$source->instanceId,'owner'=>$owner,
                'target_id'=>$id,'initial_ids'=>$ids,'completed_ids'=>[],'excluded'=>[], 'current_id'=>null,
                'step'=>0,'stage'=>'choosing_participant','cost_paid'=>true,'combat_ref'=>null];
            $this->advance();$this->state->bumpVersion();return Result::ok(['matriarch_order_applied']);
        }
        $order=$this->state->battle['matriarch_order']??null;
        if(!$order || $order['stage']!=='choosing_participant' || $p['order_id']!==$order['order_id'])
            return Result::error('Стадия приказа изменилась');
        $available=$this->remaining();
        unset($this->state->battle[self::PENDING]);
        if(!in_array($id,$available,true)) {
            $this->advance();$this->state->bumpVersion();return Result::ok(['matriarch_order_choices_refreshed']);
        }
        $this->launch($id);$this->state->bumpVersion();return Result::ok(['matriarch_order_combat']);
    }

    private function remaining(): array
    {
        $order=&$this->state->battle['matriarch_order'];
        $target=$this->state->getCard($order['target_id']);
        if(!self::live($target))return [];
        $ids=[];
        foreach($order['initial_ids'] as $id){
            if(in_array($id,$order['completed_ids'],true)||isset($order['excluded'][$id]))continue;
            $card=$this->state->getCard($id);
            if(!self::live($card)||$card->owner!==$order['owner']||!self::adjacent($card,$target)){
                $order['excluded'][$id]='no_longer_available';continue;
            }
            $ids[]=$id;
        }
        return $ids;
    }

    private function launch(int $id): void
    {
        // Revalidate immediately before declaration, without consulting normal action eligibility.
        if(!in_array($id,$this->remaining(),true)){$this->advance();return;}
        $o=&$this->state->battle['matriarch_order'];$o['current_id']=$id;$o['step']++;
        $o['stage']='waiting_combat';$o['combat_ref']=['kind'=>'matriarch_order','order_id'=>$o['order_id'],'step'=>$o['step']];
        $result=(new StrikeResolver($this->state,$this->engine))->declareForcedAdjacent(
            $this->state->getCard($id),$this->state->getCard($o['target_id']),false,$o['combat_ref']);
        if(!$result->success)throw new \LogicException('Validated matriarch combat could not start: '.$result->error);
    }

    public function advance(): void
    {
        if(empty($this->state->battle['matriarch_order']))return;
        if($this->state->status!=='battle'||$this->state->winner!==null){
            unset($this->state->battle['matriarch_order'],$this->state->battle[self::PENDING],$this->state->battle['combat_completion']);return;
        }
        $o=&$this->state->battle['matriarch_order'];
        if($o['stage']==='waiting_combat'){
            $completion=$this->state->battle['combat_completion']??null;
            if($completion!==$o['combat_ref'])return;
            unset($this->state->battle['combat_completion']);
            if(!in_array($o['current_id'],$o['completed_ids'],true))$o['completed_ids'][]=$o['current_id'];
            $o['current_id']=null;$o['combat_ref']=null;$o['stage']='choosing_participant';
        }
        if(CombatContinuation::busy($this->state))return;
        $ids=$this->remaining();
        if(!$ids){unset($this->state->battle['matriarch_order'],$this->state->battle[self::PENDING]);return;}
        if(count($ids)===1){$this->launch($ids[0]);return;}
        $this->state->battle[self::PENDING]=['owner'=>$o['owner'],'source_id'=>$o['source_id'],'order_id'=>$o['order_id'],
            'kind'=>'participant','ids'=>$ids,'id'=>bin2hex(random_bytes(16))];
    }
}
