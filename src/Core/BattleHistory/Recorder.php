<?php
declare(strict_types=1);
namespace Berserk\Core\BattleHistory;
use Berserk\Core\{GameState,Command,Result,CardInstance};

/** Transaction-local passive observer. Open identities are reloaded from actions, never stored as pending. */
final class Recorder
{
    private array $actions=[];
    private array $events=[];
    private array $captures=[];
    private array $open=[];
    private array $new=[];
    private int $ordinal=0;
    private bool $uncertifiedCombat=false;
    private bool $observed=false;
    private bool $terminal=false;
    private bool $covered=false;
    private bool $gap=false;
    private string $actor='system';
    private string $command='';
    private ReplayStateBuilder $builder;

    public function __construct(private int $gameId, private int $journalId, array $catalog, array $openActions, private bool $liveJournal=false, private ?\Closure $journalName=null)
    {
        $this->builder=new ReplayStateBuilder($catalog);
        foreach ($openActions as $row) {
            $type=$row['action_type'];
            if (!in_array($type,['combat','turn_end','battle_ready'],true) || isset($this->open[$type])) throw new \LogicException('Unrecognized experimental open scope');
            $this->open[$type]=$row['action_id'];
            if ($type==='combat' && (!empty($row['contains_gap']) || !empty(json_decode($row['completion_json']??'{}',true)['event_only']))) $this->uncertifiedCombat=true;
        }
    }
    private int $commandSource=0;
    private array $statusLedger=[];
    private array $cellLedger=[];
    /** A focused observation of declared resources; never recalculates effective characteristics. */
    private function resourceState(CardInstance $card): array
    {
        $mods=[];
        foreach ($card->modifiers as $m) {
            $stat=$m['stat']??'';
            if (!in_array($stat,['ova','ovz','ability_strike','ability_discharge','armor','move','direct','zoal','zoa','zor','zom','zov','zoo','zoz','unanswer','shield_light','action_range','next_action_bonus','shot_bonus','strike_value','damage_reduction','clumsy','hp_max','temporary_health','regeneration','execute','strike','cannot_attack','next_strike_bonus','health'],true)) continue;
            if (!is_numeric($m['value']??null) && !is_bool($m['value']??null)) continue;
            $mods[]=['stat'=>$stat,'value'=>(int)$m['value'],'expire'=>is_numeric($m['expire']??null)||in_array($m['expire']??null,['end_of_turn','end_of_opponent_turn','end_of_battle','permanent'],true)?$m['expire']:null];
        }
        $markers=[];
        foreach (['poison','stun','rooted','spider_web','hunt','prey','sand_claws','strangulation','incarnation','valhalla'] as $kind) if (isset($card->markers[$kind])) {
            $m=$card->markers[$kind];$markers[$kind]=['value'=>is_array($m)?(int)($m['value']??1):1];
        }
        $granted=[];foreach($card->flags as$k=>$a)if(str_starts_with((string)$k,'granted_action:')&&is_array($a)&&empty($a['used'])){
            $type=$a['type']??'';if(in_array($type,['strike','execute','shot','throw','discharge','heal','jump'],true))$granted[$type]=($granted[$type]??0)+1;
        }
        $traits=[];foreach(['zoal','zoa','zov','zot','zor','zoz','zom','direct','unanswer','coins_receive_deny']as$k)$traits[$k]=!empty($card->prop[$k]);
        $known=[];foreach(['public','host','player']as$v)$known[$v]=$this->builder->known($card,$v);
        return ['conditions'=>['memory_loss'=>!empty($card->flags[\Berserk\Core\EffectiveFeatures::MEMORY_LOSS]),'unique_loss'=>!empty($card->flags['stitched_unique_loss'])],'granted'=>$granted,'traits'=>$traits,'coin_unit'=>!empty($card->prop['on_ally_strike_miss'])?'miss_counter':'coins','coins'=>$card->coins,'limit'=>(int)($card->prop['coins']['max_value']??0),'mods'=>$mods,'markers'=>$markers,'known'=>$known,'zone'=>$card->zone,'six_ready'=>!empty($card->flags[\Berserk\Core\CardRoll::READY]),'base'=>['strike_weak'=>$card->strikeWeak,'strike_medium'=>$card->strikeMedium,'strike_strong'=>$card->strikeStrong,'move_max'=>$card->moveMax,'armor_max'=>$card->armorMax]];
    }
    public function statusBaseline(GameState $state): void
    {
        if (!$this->liveJournal) return;
        $this->statusLedger=[];foreach($state->cards as$c)$this->statusLedger[$c->instanceId]=$this->resourceState($c);
        $this->cellLedger=$this->visibleCellMarkers($state);
    }
    private function visibleCellMarkers(GameState $state): array
    {
        $out=[];
        foreach(array_keys($state->cell_markers)as$key)foreach(\Berserk\Core\ZoneManager::markersAt($state,$key)as$m){
            $kind=$m['type']??'';if(!in_array($kind,['bomb','reduce_attack','row_spell','gate','tremor','lantern_light','bonfire'],true))continue;
            $out[$key][$kind]=($out[$key][$kind]??0)+1;
        }
        return $out;
    }
    /** Checkpoints at completed native effect boundaries, with a command tail for direct processors. */
    public function statusCheckpoint(GameState $state,string $reason='effect',?CardInstance $cause=null): void
    {
        if (!$this->liveJournal) return;
        foreach($state->cards as$c){
            $after=$this->resourceState($c);$before=$this->statusLedger[$c->instanceId]??null;$this->statusLedger[$c->instanceId]=$after;
            if($before===null||!in_array($state->status,['battle','game_over'],true)||!in_array($c->zone,['field','flying'],true)||!in_array($before['zone'],['field','flying'],true))continue;
            $views=[];foreach(['public','host','player']as$v)if($before['known'][$v]&&$after['known'][$v])$views[]=$v;
            $base=['target_id'=>$c->instanceId,'reason'=>$reason,'_cause_id'=>$cause?->instanceId??$this->commandSource,'_visible_views'=>$views];
            foreach($after['conditions']as$condition=>$value)if($before['conditions'][$condition]!==$value)$this->liveEvent($state,'status.condition',$base+['condition'=>$condition,'active'=>$value],[$c->instanceId]);
            foreach(array_unique([...array_keys($before['granted']),...array_keys($after['granted'])])as$type){$a=$before['granted'][$type]??0;$b=$after['granted'][$type]??0;if($a!==$b)$this->liveEvent($state,'capability.changed',$base+['action_type'=>$type,'before'=>$a,'after'=>$b,'_private_owner'=>$c->owner],[$c->instanceId]);}
            foreach($after['traits']as$trait=>$value)if($before['traits'][$trait]!==$value)$this->liveEvent($state,'trait.changed',$base+['trait'=>$trait,'active'=>$value],[$c->instanceId]);
            if(!$before['six_ready']&&$after['six_ready'])$this->liveEvent($state,'capability.ready_six',$base,[$c->instanceId]);
            if($before['coins']!==$after['coins'])$this->liveEvent($state,'coins.changed',$base+['before'=>$before['coins'],'after'=>$after['coins'],'delta'=>$after['coins']-$before['coins'],'unit'=>$after['coin_unit']],[$c->instanceId]);
            foreach($after['base']as$stat=>$value)if($before['base'][$stat]!==$value)$this->liveEvent($state,'characteristic.changed',$base+['stat'=>$stat,'before'=>$before['base'][$stat],'after'=>$value],[$c->instanceId]);
            if($before['limit']!==$after['limit'])$this->liveEvent($state,'coins.limit',$base+['before'=>$before['limit'],'after'=>$after['limit']],[$c->instanceId]);
            // Multisets preserve two independently applied equal modifiers, without exposing source metadata.
            $old=$before['mods'];$new=$after['mods'];
            foreach($old as$i=>$m)foreach($new as$j=>$n)if($m===$n||($m['stat']===$n['stat']&&$m['value']===$n['value']&&is_numeric($m['expire'])&&is_numeric($n['expire']))){unset($old[$i],$new[$j]);break;}
            foreach([['removed',$old],['added',$new]]as[$operation,$list])foreach($list as$m)$this->liveEvent($state,'modifier.changed',$base+['operation'=>$operation]+$m,[$c->instanceId]);
            foreach(array_unique([...array_keys($before['markers']),...array_keys($after['markers'])])as$kind){
                $a=$before['markers'][$kind]['value']??null;$b=$after['markers'][$kind]['value']??null;
                if($a!==$b)$this->liveEvent($state,'marker.changed',$base+['marker'=>$kind,'before'=>$a,'after'=>$b],[$c->instanceId]);
            }
        }
        $cells=$this->visibleCellMarkers($state);
        if(in_array($state->status,['battle','game_over'],true))foreach(array_unique([...array_keys($this->cellLedger),...array_keys($cells)])as$key){
            foreach(array_unique([...array_keys($this->cellLedger[$key]??[]),...array_keys($cells[$key]??[])])as$kind){
                $before=$this->cellLedger[$key][$kind]??0;$after=$cells[$key][$kind]??0;
                if($before!==$after)$this->liveEvent($state,'cell_marker.changed',['cell'=>array_map('intval',explode('_',(string)$key)),'marker'=>$kind,'before'=>$before,'after'=>$after,'reason'=>$reason]);
            }
        }
        $this->cellLedger=$cells;
    }
    public function instantResult(GameState $state,array $item,bool $applied,string $reason,array $result,?CardInstance $source,?CardInstance $target=null): void
    {
        $type=$item['effect']['type']??'';
        if($source && (!$applied || ($type==='dice_choice'&&($result['operation']??'')!=='reroll'&&($result['attack_before']??null)===($result['attack_after']??null)&&($result['defend_before']??null)===($result['defend_after']??null)))) {
            $this->liveEvent($state,'instant.inactive',['source_id'=>$source->instanceId,'reason'=>str_contains($reason,'промах')?'miss':(str_contains($reason,'защищ')?'protected':(str_contains($reason,'цель')?'target_unavailable':'no_change')),'_private_owner'=>$source->owner],[$source->instanceId]);return;
        }
        // These results already have a single native B.1–B.3 writer.
        if(in_array($type,['dice_choice','damage_on_dice','heal','heal_turn_wounds','redistribute_wounds','damage','marker','open','close_target'],true))return;
        $fact=[];
        if($type==='forced_strike_adjacent'&&$applied&&$target)$fact=['effect'=>'forced_strike_adjacent'];
        if(in_array($type,['strike_level','strike_value'],true)&&isset($result['before'],$result['after'])&&$result['before']!==$result['after']){
            $strike=$state->battle['single_roll']??$state->battle['strike']??[];
            $id=(int)(($result['side']??'attack')==='attack'?($strike['attacker_id']??0):(($strike['defender_id']??0)?:($strike['target_id']??0)));
            $target=$state->getCard($id);$fact=['effect'=>$type,'before'=>$result['before'],'after'=>$result['after']];
        }elseif($type==='damage_cap'&&($result['before_cap']??null)!==($result['after_cap']??null)){
            $strike=$state->battle['single_roll']??$state->battle['strike']??[];$target=$state->getCard((int)($strike['target_id']??0));
            $fact=['effect'=>'damage_cap','after'=>(int)$result['after_cap']];
        }elseif($type==='redirect_strike'&&($result['before_target_id']??0)!==($result['after_target_id']??0)){
            $target=$state->getCard((int)($result['after_target_id']??0));$fact=['effect'=>'redirect_strike'];
        }
        if($applied&&$fact&&$target){$this->liveEvent($state,'instant.result',['target_id'=>$target->instanceId,'source_id'=>$source?->instanceId,'ability'=>is_string($item['label']??null)&&strlen($item['label'])<=512?$item['label']:'Инстант']+$fact,[$target->instanceId]);return;}
        // Failed/no-op effects are private and normalized: never publish internal error text.
        if($source&&(!$applied||(in_array($type,['strike_level','strike_value','damage_cap','redirect_strike'],true)&&!$fact)))$this->liveEvent($state,'instant.inactive',['source_id'=>$source->instanceId,'_private_owner'=>$source->owner],[$source->instanceId]);
    }
    public function commandStarted(GameState $state,string $actor,Command $cmd): void
    {
        $this->commandSource=(int)$cmd->get('card_id',0);
        $this->statusBaseline($state);
        $this->observed=true;$this->actor=$actor;$this->command=$cmd->type;
        $this->covered=in_array($cmd->type,['set_sound_enabled','set_lucktest_url_roll',
            ...\Berserk\Core\InstantReservation::COMMANDS],true);$this->gap=false;
    }
    public function commandFinished(GameState $state,Result $result): void
    {
        if (!$result->success) return;
        $this->statusCheckpoint($state,'action');
        // Preparation placement is outside the text journal; keep the replay-only policy unchanged.
        $outsideText=$this->liveJournal && !in_array($state->status,['battle','game_over'],true);
        // Technical continuation is covered only by a known existing root, never by absence of pending.
        if (!$outsideText && !$this->covered && !(in_array($this->command,['choose_defender','choose_strike_mode','confirm_strike','turn_ack'],true) && $this->open)
            && !in_array($this->command,['confirm_place','pathfinder_choice'],true)) $this->unsupported($state,'uncovered_command:'.$this->command);
        if (isset($this->open['journal_effect'])) $this->close($state,'journal_effect','settled',false);
        if ($state->status==='game_over' && !$this->terminal) {
            foreach (array_keys($this->open) as $type) $this->close($state,$type,'interrupted',false,['reason'=>'match_terminal']);
            $id=$this->begin($state,'match_terminal',['winner'=>$state->winner]);
            $this->close($state,'match_terminal','settled',true,['winner'=>$state->winner],true);
            $this->terminal=true;
        }
    }
    public function batch(GameState $state): HistoryBatch
    {
        if (!$this->observed) $this->unsupported($state,'execution_without_engine_observation');
        return new HistoryBatch(array_values($this->actions),$this->events,$this->captures,$this->terminal,true);
    }
    private function begin(GameState $state,string $type,array $payload=[],array $refs=[]): string
    {
        if (isset($this->open[$type])) throw new \LogicException('Overlapping supported history scope');
        $id='a'.substr(hash('sha256',$this->gameId.':'.$this->journalId.':'.(++$this->ordinal)),0,48);
        $this->open[$type]=$id;$this->new[$id]=true;
        $n=$this->emit($state,$id,$type.'.started',$payload,$refs);
        $this->actions[$id]=['id'=>$id,'root'=>$id,'parent'=>null,'type'=>$type,'initiator'=>$this->actor,
            'status'=>'open','start'=>$n,'completion'=>['experimental'=>true]];
        $this->covered=true;
        return $id;
    }
    private function emit(GameState $state,string $id,string $type,array $payload,array $refs=[]): int
    {
        if ($this->liveJournal) {
            $payload['_journal']=['turn'=>(int)($state->battle['turn']??0),'active'=>$state->battle['active']??null,'cards'=>[]];
            foreach ($refs as $ref) {
                $card=$state->getCard((int)$ref);
                if ($card) {
                    $name=$this->journalName!==null ? ($this->journalName)($card) : ($this->builder->definition($card)['name']??null);
                    // A missing definition must not erase an otherwise visible action. No ukid or raw prop fallback.
                    if (!is_string($name) || trim($name)==='' || strlen($name)>512 || !preg_match('//u',$name)) $name='Карта';
                    $payload['_journal']['cards'][(string)$ref]=['name'=>$name,'owner'=>$card->owner];
                }
            }
        }
        $e=['action'=>$id,'type'=>$type,'version'=>1,'payload'=>$payload,'at'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')];
        foreach (['public','host','player'] as $view) {
            $safe=true;
            foreach ($refs as $ref) { $c=$state->getCard((int)$ref); if (!$c || !$this->builder->known($c,$view)) $safe=false; }
            if (isset($payload['_private_owner']) && $view!==$payload['_private_owner']) $safe=false;
            $projection=$payload;
            if(isset($payload['_visible_views'])&&!in_array($view,$payload['_visible_views'],true))$safe=false;
            unset($projection['_visible_views']);
            if(isset($payload['_cause_id'])){
                $cause=$state->getCard((int)$payload['_cause_id']);
                if($cause&&$this->builder->known($cause,$view)){
                    $n=$this->journalName!==null?($this->journalName)($cause):($this->builder->definition($cause)['name']??null);
                    if(is_string($n)&&trim($n)!==''&&strlen($n)<=512&&preg_match('//u',$n))$projection['_journal']['cause_name']=$n;
                }
                unset($projection['_cause_id']);
            }
            if (str_starts_with($type,'attack.') || $type==='instant.result' || str_starts_with($type,'chain.') || $type==='combat.death') {
                $source=$state->getCard((int)($payload['source_id']??0));
                if ($source && $this->builder->known($source,$view)) {
                    $n=$this->journalName!==null?($this->journalName)($source):($this->builder->definition($source)['name']??null);
                    $projection['_journal']['cards'][(string)$source->instanceId]=['name'=>is_string($n)&&trim($n)!==''&&strlen($n)<=512&&preg_match('//u',$n)?$n:'Карта','owner'=>$source->owner];
                } else {
                    // Even additional JSON fields must not identify the hidden card or its ability.
                    unset($projection['source_id'],$projection['ability']);
                    if($type==='combat.death')unset($projection['cause']);
                    if(str_starts_with($type,'attack.')) unset($projection['attack_type'],$projection['level'],$projection['die'],$projection['value'],$projection['modifiers']);
                    if (($projection['outcome']??null)==='executed') $projection['outcome']='completed';
                }
            }
            if ($type==='card.transformed') {
                $projection['before']=$payload['_before_forms'][$view]??null;
                unset($projection['_before_forms']);
            }
            if($type==='health.transfer_out') {
                $projection['recipients']=[];
                foreach(($payload['recipient_ids']??[]) as $recipientId) {
                    $recipient=$state->getCard((int)$recipientId);
                    if($recipient && $this->builder->known($recipient,$view)) {
                        $n=$this->journalName!==null?($this->journalName)($recipient):($this->builder->definition($recipient)['name']??null);
                        $projection['recipients'][]=is_string($n)&&trim($n)!==''&&strlen($n)<=512&&preg_match('//u',$n)?$n:'Карта';
                    }
                }
                unset($projection['recipient_ids']);
            }
            $e[$view]=$safe?$projection:($this->liveJournal?null:['redacted'=>true]);
        }
        // Within-command causality is explicit. Cross-command ownership is the persistent root identity.
        for ($i=count($this->events)-1;$i>=0;$i--) if ($this->events[$i]['action']===$id) {$e['cause_order']=$i+1;break;}
        $this->events[]=$e;return count($this->events);
    }
    private function close(GameState $state,string $type,string $status,bool $capture,array $payload=[],bool $final=false): void
    {
        $id=$this->open[$type]??null;if ($id===null) return;
        $n=$this->emit($state,$id,$type.'.'.$status,$payload);
        if (isset($this->new[$id])) {$this->actions[$id]['status']=$status;$this->actions[$id]['end']=$n;$this->actions[$id]['completion']=$payload;}
        else $this->actions[$id]=['id'=>$id,'status'=>$status,'end'=>$n,'completion'=>$payload];
        if ($capture) $this->capture($state,$id,$type,$n,false,$final);
        unset($this->open[$type]);$this->covered=true;
    }
    private function capture(GameState $state,string $id,string $type,int $through,bool $initial=false,bool $final=false): void
    {
        $c=['action'=>$id,'boundary'=>$id.':'.$type,'type'=>$type,'through'=>$through,'initial'=>$initial,'final'=>$final];
        foreach (['state'=>'server','public'=>'public','host'=>'host','player'=>'player'] as $key=>$view) $c[$key]=$this->builder->build($state,$view);
        // Serialize now, not after the remaining Engine execution.
        $this->captures[]=json_decode(ReplayState::encode($c),true,512,JSON_THROW_ON_ERROR);
    }
    public function unsupported(GameState $state,string $reason): void
    {
        if ($this->gap) return;$this->gap=true;
        foreach (array_keys($this->open) as $type) {
            if ($this->liveJournal && $type==='combat') { $this->uncertifiedCombat=true; continue; }
            $this->close($state,$type,'interrupted',false,['reason'=>'unsupported_scope']);
        }
        $this->begin($state,'recording_gap',['reason'=>$reason]);
        $this->close($state,'recording_gap','settled',false,['reason'=>$reason]);
    }
    private function eligible(GameState $state): bool
    {
        if ($this->builder->simple($state)) return true;
        $this->unsupported($state,'uncertified_mechanic_or_display');return false;
    }
    public function liveEvent(GameState $state,string $type,array $payload=[],array $refs=[]): void
    {
        if (!$this->liveJournal) return;
        $covered=$this->covered;
        $scope=isset($this->open['combat'])?'combat':(isset($this->open['turn_end'])?'turn_end':(isset($this->open['battle_ready'])?'battle_ready':'journal_effect'));
        if (!isset($this->open[$scope])) $this->begin($state,$scope);
        $this->emit($state,$this->open[$scope],$type,$payload,$refs);
        $this->covered=$covered;
    }
    /** Health facts carry only the observed target. Hidden sources never suppress a visible result. */
    public function healthEvent(GameState $state, CardInstance $target, string $type, array $facts): void
    {
        $this->liveEvent($state,$type,['target_id'=>$target->instanceId]+$facts,[$target->instanceId]);
    }
    /** A visible outcome survives an unknown source; source details are projected independently. */
    public function attackEvent(GameState $state, ?CardInstance $source, CardInstance $target, string $type, string $phase, array $facts=[]): void
    {
        $this->liveEvent($state,'attack.'.$phase,['target_id'=>$target->instanceId,'source_id'=>$source?->instanceId,'attack_type'=>$type]+$facts,[$target->instanceId]);
    }
    /** Call only at an actual public mechanism boundary, never when opening a pending/ACK. */
    public function die(GameState $state, CardInstance $card, string $phase, int $value, array $facts=[]): void
    {
        $this->liveEvent($state,'dice.'.$phase,['card_id'=>$card->instanceId,'value'=>$value]+$facts,[$card->instanceId]);
    }
    public function movement(GameState $state, \Berserk\Core\Movement\MovementContext $c): void
    {
        if ([$c->fromRow,$c->fromCol]===[$c->toRow,$c->toCol]) return;
        $display=$c->movementType;
        if ($display==='jump') foreach ($c->card->effectiveProp()['actions']??[] as $a) if (($a['type']??'')==='jump') { if ((int)($a['range']??0)>=10) $display='teleport';break; }
        $this->liveEvent($state,'movement.completed',['card_id'=>$c->card->instanceId,'from'=>[$c->fromRow,$c->fromCol],'to'=>[$c->toRow,$c->toCol],'movement_type'=>$c->movementType,'display_kind'=>$display,'origin'=>$c->origin],[$c->card->instanceId]);
    }
    /** Snapshot only the identity already known in each view before a transformation. */
    public function formBefore(GameState $state,CardInstance $card): array
    {
        $forms=[];
        foreach (['public','host','player'] as $view) $forms[$view]=$this->builder->known($card,$view)?['type'=>$card->type,'zone'=>$card->zone]:null;
        return $forms;
    }
    public function transformed(GameState $state,CardInstance $card,array $before): void
    {
        if (($before[$card->owner]??null)===['type'=>$card->type,'zone'=>$card->zone]) return;
        $this->liveEvent($state,'card.transformed',['card_id'=>$card->instanceId,'after'=>['type'=>$card->type,'zone'=>$card->zone],'_before_forms'=>$before],[$card->instanceId]);
    }
    public function zoneChanged(GameState $state,CardInstance $card,string $from,string $reason=''): void
    {
        if (!in_array($state->status,['battle','game_over'],true) || $from===$card->zone) return;
        $this->liveEvent($state,'zone.changed',['card_id'=>$card->instanceId,'from_zone'=>$from,'to_zone'=>$card->zone,'position'=>$card->row!==null?[$card->row,$card->col]:null,'reason'=>$reason],[$card->instanceId]);
    }
    public function chainEvent(GameState $state,?CardInstance $source,?CardInstance $target,string $ability,string $phase): void
    {
        if(!$source&&!$target)return;
        $refs=$target?[$target->instanceId]:($source?[$source->instanceId]:[]);
        $this->liveEvent($state,'chain.'.$phase,['source_id'=>$source?->instanceId,'target_id'=>$target?->instanceId,'ability'=>$ability],$refs);
    }
    /** Prophecy deliberately knows the viewed deck cards; this exception is owner-only and immutable. */
    public function prophecyCards(GameState $state,string $owner,string $type,array $cards,array $facts=[]): void
    {
        $names=[];
        foreach($cards as$card){
            if(!$card instanceof CardInstance||$card->owner!==$owner)continue;
            $n=$this->journalName!==null?($this->journalName)($card):($this->builder->definition($card)['name']??null);
            $names[]=is_string($n)&&trim($n)!==''&&strlen($n)<=512&&preg_match('//u',$n)?$n:'Карта';
        }
        $this->liveEvent($state,$type,['_private_owner'=>$owner,'cards'=>$names]+$facts);
    }
    public function healed(GameState $state,CardInstance $target,int $before,?CardInstance $source,string $ability): void
    {
        if ($target->hp <= $before) return;
        // Only self keyword recovery has an intrinsically visible reason. Do not reveal a hidden healer's ability.
        $reason=$source === $target && in_array($ability,['regeneration','deadeat','vampire'],true)?$ability:'heal';
        $this->healthEvent($state,$target,'card.healed',['amount'=>$target->hp-$before,'hp_before'=>$before,'hp_after'=>$target->hp,'ability'=>$reason]);
    }
    public function initial(GameState $state): void
    {
        $id=$this->begin($state,'initial');$this->close($state,'initial','settled',false);
        $this->capture($state,$id,'initial',count($this->events),true);
        if ($this->eligible($state)) $this->begin($state,'battle_ready');
    }
    public function startTurnFinished(GameState $state): void
    {
        $this->liveEvent($state,'turn.started',['active'=>$state->battle['active'],'turn'=>$state->battle['turn']]);
        if (!$this->eligible($state)) return;
        $type=isset($this->open['turn_end'])?'turn_end':'battle_ready';
        $this->close($state,$type,'settled',true,['active'=>$state->battle['active'],'turn'=>$state->battle['turn']]);
    }
    public function turnEnding(GameState $state,string $actor,?int $endingTurn=null): void
    {
        if ($this->eligible($state)) $this->begin($state,'turn_end',['ending'=>$actor]);
        $this->liveEvent($state,'turn.ended',['ending'=>$actor,'turn'=>$endingTurn??$state->battle['turn']]);
    }
    public function moved(GameState $state,CardInstance $card,int $fromRow,int $fromCol,bool $textRecorded=false): void
    {
        if (!$this->eligible($state)) {
            $this->liveEvent($state,'move.started',['card_id'=>$card->instanceId,'text_recorded'=>$textRecorded,'from'=>[$fromRow,$fromCol],'to'=>[$card->row,$card->col]],[$card->instanceId]); return;
        }
        $id=$this->begin($state,'move',['card_id'=>$card->instanceId,'text_recorded'=>$textRecorded,'from'=>[$fromRow,$fromCol],'to'=>[$card->row,$card->col],'move'=>$card->move],[$card->instanceId]);
        $this->close($state,'move','settled',true);
    }
    /** Additional live declaration; replay eligibility and boundaries stay unchanged. */
    public function forcedCombatStarted(GameState $state,array $strike): void
    {
        if($this->liveJournal)$this->combatStarted($state,$strike);
    }
    public function combatStarted(GameState $state,array $strike): void
    {
        if ($this->liveJournal) {
            // Unknown nested mechanics must never abort gameplay to enforce an observer scope.
            $nested=isset($this->open['combat']);
            if ($nested) $this->close($state,'combat','interrupted',false,['reason'=>'nested_combat_outside_coverage']);
            $this->uncertifiedCombat=$nested;
            $id=$this->begin($state,'combat',['attacker_id'=>$strike['attacker_id'],'target_id'=>$strike['target_id'],'attack_type'=>'strike'],[$strike['attacker_id'],$strike['target_id']]);
            if ($nested) $this->unsupported($state,'nested_combat_outside_coverage');
            if (!$this->eligible($state) || $this->uncertifiedCombat) $this->actions[$id]['completion']['event_only']=true;
            return;
        }
        if ($this->eligible($state)) $this->begin($state,'combat',['attacker_id'=>$strike['attacker_id'],'target_id'=>$strike['target_id'],'attack_type'=>'strike'],[$strike['attacker_id'],$strike['target_id']]);
    }
    public function combatEvent(GameState $state,string $type,array $payload,array $refs=[]): void
    {
        if ($this->liveJournal) {
            if($type==='combat.death')$refs=[(int)$payload['card_id']];
            if (!$this->builder->simple($state)) $this->unsupported($state,'uncertified_mechanic_or_display');
            $this->liveEvent($state,$type,$payload,$refs); return;
        }
        if (!$this->eligible($state)) return;
        if (isset($this->open['combat'])) {$this->emit($state,$this->open['combat'],$type,$payload,$refs);$this->covered=true;}
    }
    public function combatFinished(GameState $state): void
    {
        if ($this->liveJournal) {
            $eligible=$this->eligible($state);
            $this->close($state,'combat','settled',$eligible && !$this->uncertifiedCombat); return;
        }
        if ($this->eligible($state)) $this->close($state,'combat','settled',true);
    }
    public function seriesEnded(GameState $state,CardInstance $card): void
    {
        if (!$this->eligible($state)) {
            $this->liveEvent($state,'series_end.started',['card_id'=>$card->instanceId,'closed'=>$card->closed],[$card->instanceId]);
            return;
        }
        $this->begin($state,'series_end',['card_id'=>$card->instanceId,'closed'=>$card->closed],[$card->instanceId]);
        $this->close($state,'series_end','settled',true);
    }
}
