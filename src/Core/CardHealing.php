<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Only explicit card healing enters this operation. HP maintenance and keyword recovery never do. */
final class CardHealing
{
    public const PENDING = 'pending_card_heal';
    public function __construct(private GameState $state, private Engine $engine) {}

    public static function startTurn(GameState $s,string $owner): void
    {
        foreach($s->cards as $card)unset($card->flags['on_heal_wound_used_this_turn']);
        $s->battle['card_healing']=['turn_key'=>($s->battle['turn']??0).':'.$owner,
            'first_used'=>false,'next_id'=>0,'events'=>[]];
    }

    public static function waiting(GameState $s): bool
    {return !empty($s->battle[self::PENDING]) || !empty($s->battle['pending_heal_instead_wound']['card_heal']);}

    public static function live(?CardInstance $c): bool
    { return $c !== null && EffectiveFeatures::onBattlefield($c) && !$c->dying && $c->hp > 0; }

    public function begin(?CardInstance $source, CardInstance $target, int $amount, string $ability, array $returnTo = [], array $options = []): ?array
    {
        if (!empty($this->state->battle[self::PENDING]) || !empty($this->state->battle['pending_heal_instead_wound']['card_heal']))
            throw new \LogicException('Unfinished card healing operation');
        $active=$this->state->battle['active']??'host';$turn=(int)($this->state->battle['turn']??0);
        $phase=$this->state->battle['turn_phase']??[];
        // Native endTurn preselects the next active player before running the ending turn's effects.
        if(($phase['phase']??null)==='end') {
            $active=$phase['active_key'];if($active==='player')--$turn;
        }
        $key = $turn . ':' . $active;
        $ledger = &$this->state->battle['card_healing'];
        if (($ledger['turn_key'] ?? null) !== $key) $ledger = ['turn_key'=>$key, 'first_used'=>false, 'next_id'=>0, 'events'=>[]];
        unset($ledger['last']);
        $first = !$ledger['first_used']; $ledger['first_used'] = true;
        $op = ['id'=>$key . ':' . ++$ledger['next_id'], 'source_id'=>$source?->instanceId, 'target_id'=>$target->instanceId,
            'declared'=>max(0,$amount), 'modified'=>max(0,$amount), 'ability'=>$ability, 'return_to'=>$returnTo,
            'options'=>$options, 'action_id'=>$options['action_id']??ActionResolution::parent($this->state), 'first'=>$first, 'checks'=>[], 'index'=>0, 'decisions'=>[], 'message'=>null];
        if($op['action_id']!==null)$op[ActionResolution::ID]=$op['action_id'];
        $this->event($op,'declared');
        if ($first && $op['declared']>0 && self::live($target)) {
            foreach ([$active,$this->state->getOpponentKey($active)] as $owner) {
                foreach ($this->state->cards as $c) {
                    $mode = $this->mode($c,$target);
                    if ($c->owner === $owner && $mode !== null) {
                        $op['checks'][]=['card_id'=>$c->instanceId,'owner'=>$owner,'mode'=>$mode]; break;
                    }
                }
            }
        }
        $this->event($op,'noble_checks',['checks'=>$op['checks'],'active_owner'=>$active]);
        $modes = array_column($op['checks'],'mode');
        if (in_array('double',$modes,true) && in_array('block',$modes,true)) {
            $op['modified']=0; $op['index']=count($op['checks']);
            $op['message']='Мрачная дворянка заблокировала излечение. Удвоение другой Дворянкой не изменяет результат.';
            $this->event($op,'automatic_conflict');
        }
        return $this->advance($op);
    }

    private function mode(?CardInstance $noble, CardInstance $target): ?string
    {
        if (!self::live($noble) || empty($noble->effectiveProp()['first_card_heal'])) return null;
        $threshold=(int)($noble->effectiveProp()['first_card_heal']['threshold']??8);
        if ($noble->hp>$threshold && $noble->owner!==$target->owner) return 'block';
        if ($noble->hp<$threshold && $noble->owner===$target->owner) return 'double';
        return null;
    }

    private function event(array $op,string $type,array $data=[]): void
    {
        $this->state->battle['card_healing']['events'][]=['operation_id'=>$op['id'],'type'=>$type,
            'source_id'=>$op['source_id'],'target_id'=>$op['target_id'],'action_id'=>$op['action_id'],'declared'=>$op['declared'],'modified'=>$op['modified']] + $data;
    }

    public static function neighbours(GameState $s,CardInstance $target): array
    {
        $ids=[];
        foreach($s->cards as $c) if(self::live($c) && in_array($c->type,['creature','fly'],true)
            && ZoneManager::areAdjacentFieldCards($target,$c)) $ids[]=$c->instanceId;
        return $ids;
    }

    private function advance(array $op): ?array
    {
        $target=$this->state->getCard($op['target_id']);
        while($op['index']<count($op['checks'])) {
            $check=$op['checks'][$op['index']]; $noble=$this->state->getCard($check['card_id']);
            if (!$target || $this->mode($noble,$target)!==$check['mode']) {++$op['index'];continue;}
            $this->state->battle[self::PENDING]=['owner'=>$check['owner'],'source_id'=>$check['card_id'],
                'token'=>bin2hex(random_bytes(16)),'operation'=>$op];
            $this->event($op,'noble_pending',['owner'=>$check['owner'],'mode'=>$check['mode']]);
            return null;
        }
        if (self::live($target) && $op['modified']>0 && CardStats::canHealInsteadWound($target)
            && ($ids=self::neighbours($this->state,$target))) {
            $this->state->battle['pending_heal_instead_wound']=['owner'=>$target->owner,'source_id'=>$op['source_id'],
                'target_id'=>$target->instanceId,'heal_value'=>$op['modified'],'candidates'=>$ids,
                'token'=>bin2hex(random_bytes(16)),'card_heal'=>$op];
            $this->event($op,'satyr_pending',['owner'=>$target->owner]);return null;
        }
        return $this->finish($op);
    }

    public function choose(string $owner,Command $cmd): Result
    {
        $p=$this->state->battle[self::PENDING]??null;
        if(!$p || $p['owner']!==$owner || !hash_equals($p['token'],(string)$cmd->get('heal_token','')))
            return Result::error('Выбор излечения устарел');
        $apply=$cmd->get('apply');if(!in_array($apply,['yes','no'],true))return Result::error('Неверный выбор');
        $op=$p['operation'];$check=$op['checks'][$op['index']];$target=$this->state->getCard($op['target_id']);
        $mode=$target?$this->mode($this->state->getCard($check['card_id']),$target):null;
        unset($this->state->battle[self::PENDING]);
        if($apply==='yes' && $mode===$check['mode']) $op['modified']=$mode==='block'?0:$op['modified']*2;
        $op['decisions'][]=['owner'=>$owner,'mode'=>$check['mode'],'applied'=>$apply==='yes'&&$mode===$check['mode']];
        $this->event($op,'noble_decided',end($op['decisions']));++$op['index'];
        $result=$this->advance($op);$this->state->bumpVersion();
        return $result===null?Result::ok(['card_heal_waiting']):$this->resume($op,$result);
    }

    public function chooseSatyr(string $owner,Command $cmd): Result
    {
        $p=$this->state->battle['pending_heal_instead_wound']??null;
        if(empty($p['card_heal']) || $p['owner']!==$owner || !hash_equals($p['token'],(string)$cmd->get('heal_token','')))
            return Result::error('Выбор замены излечения устарел');
        $op=$p['card_heal'];$satyr=$this->state->getCard($op['target_id']);$victim=$this->state->getCard((int)$cmd->get('target_id',0));
        $cancel=$cmd->type==='cancel_pending'||(int)$cmd->get('target_id',0)===0
            || !self::live($satyr) || !CardStats::canHealInsteadWound($satyr);
        if(!$cancel && (!self::live($satyr)||!CardStats::canHealInsteadWound($satyr)||!$victim
            || !in_array($victim->instanceId,self::neighbours($this->state,$satyr),true)))return Result::error('Цель замены недоступна');
        unset($this->state->battle['pending_heal_instead_wound']);
        $wounds=0;
        if(!$cancel) {
            $op['replacement_target_id']=$victim->instanceId;
            $satyr->flags['on_heal_wound_used_this_turn']=1+(int)($satyr->flags['on_heal_wound_used_this_turn']??0);
            $before=$victim->hp;$this->engine->applyDamage($this->state,$victim,$op['modified'],'impact',$satyr);
            $wounds=max(0,$before-$victim->hp);
            $this->event($op,'satyr_replaced',['victim_id'=>$victim->instanceId,'wounds'=>$wounds]);
        } else $this->event($op,'satyr_declined');
        if(self::waiting($this->state)) {
            self::defer($this->state,['handler'=>'healing','operation'=>$op,'replaced'=>!$cancel,'wounds'=>$wounds]);
            $this->state->bumpVersion();return Result::ok(['card_heal_replacement_waiting']);
        }
        $r=$this->finish($op,!$cancel,$wounds);$this->state->bumpVersion();return $this->resume($op,$r);
    }

    private function finish(array $op,bool $replaced=false,int $wounds=0): array
    {
        $target=$this->state->getCard($op['target_id']);$source=$this->state->getCard((int)$op['source_id']);
        $before=$target?->hp??0;$opened=false;
        if(!$replaced && self::live($target) && ($op['modified']>0 || !empty($op['options']['full']))) {
            $config=$target->effectiveProp()['on_heal_instead_open']??null;
            if(!empty($op['options']['allow_open']) && is_array($config) && $target->hp >= $target->hpMax
                && (empty($config['once_per_turn'])||empty($target->flags['on_heal_open_used_this_turn']))) {
                ActionResolution::effect($this->state,$target,'open','passive',['target_id'=>$target->instanceId]);
                $this->engine->openCard($target);$target->flags['on_heal_open_used_this_turn']=true;$opened=true;
            } else $target->hp=min($target->hpMax,$target->hp+$op['modified']);
        }
        $r=['operation_id'=>$op['id'],'declared'=>$op['declared'],'modified'=>$op['modified'],
            'restored'=>max(0,($target?->hp??0)-$before),'replaced'=>$replaced,'wounds'=>$wounds,
            'opened'=>$opened,'message'=>$op['message'],'before'=>$before,'target_id'=>$op['target_id'],
            'replacement_target_id'=>$op['replacement_target_id']??null];
        if($target && empty($op['options']['active'])) {
            if($source)ActionResolution::healed($this->state,$source,$target,$before,$op['ability'],$op['options']['parent']??null);
            $this->state->historyRecorder?->healed($this->state,$target,$before,$source,$op['ability']);
        }
        if($op['message']!==null)$this->state->battle['card_healing']['messages'][]=$op['message'];
        $this->event($op,'completed',$r);
        $this->state->battle['card_healing']['last']=$r;
        return $r;
    }

    public static function defer(GameState $state,array $to): void
    {
        if(!empty($state->battle[self::PENDING])) $state->battle[self::PENDING]['operation']['followers'][]=$to;
        elseif(!empty($state->battle['pending_heal_instead_wound']['card_heal']))$state->battle['pending_heal_instead_wound']['card_heal']['followers'][]=$to;
        else throw new \LogicException('No healing owns continuation');
    }

    private function resume(array $op,array $result): Result
    {
        $return=$this->dispatch($op['return_to'],$result);
        $followers=$op['followers']??[];
        while($followers) {
            if(self::waiting($this->state)) {
                foreach($followers as $f)self::defer($this->state,$f);
                return $return;
            }
            $return=$this->dispatch(array_shift($followers),$result);
        }
        return $return;
    }

    private function dispatch(array $to,array $result): Result
    {
        if(!$to)return Result::ok(['card_heal_completed']);
        return match($to['handler']) {
            'healing'=>$this->resume($to['operation'],$this->finish($to['operation'],$to['replaced'],$to['wounds'])),
            'action'=>(new ActionResolver($this->state,$this->engine))->continueCardHealing($to,$result),
            'strike'=>(new StrikeResolver($this->state,$this->engine))->continueCardHealing($to,$result),
            'instant'=>(new InstantProcessor($this->state,$this->engine))->continueCardHealing($to,$result),
            'death'=>(new DamageResolver($this->state))->continueCardHealing($this->engine,$to,$result),
            'turn'=>(new TurnPhaseProcessor($this->state,$this->engine))->continueCardHealing($to,$result),
            'valhalla'=>(new ValhallaProcessor($this->state,$this->engine))->continueCardHealing($to,$result),
            default=>throw new \LogicException('Unknown healing continuation'),
        };
    }
}
