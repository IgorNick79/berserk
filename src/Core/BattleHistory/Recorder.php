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
    public function commandStarted(GameState $state,string $actor,Command $cmd): void
    {
        $this->observed=true;$this->actor=$actor;$this->command=$cmd->type;
        $this->covered=in_array($cmd->type,[\Berserk\Core\MatchPlayerSettings::COMMAND,'set_lucktest_url_roll'],true);$this->gap=false;
    }
    public function commandFinished(GameState $state,Result $result): void
    {
        if (!$result->success) return;
        // Preparation placement is outside the text journal; keep the replay-only policy unchanged.
        $outsideText=$this->liveJournal && !in_array($state->status,['battle','game_over'],true);
        // Technical continuation is covered only by a known existing root, never by absence of pending.
        if (!$outsideText && !$this->covered && !(in_array($this->command,['choose_defender','choose_strike_mode','confirm_strike','turn_ack'],true) && $this->open)
            && $this->command!=='confirm_place') $this->unsupported($state,'uncovered_command:'.$this->command);
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
            $e[$view]=$safe?$payload:($this->liveJournal?null:['redacted'=>true]);
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
    public function healed(GameState $state,CardInstance $target,int $before,?CardInstance $source,string $ability): void
    {
        if ($target->hp <= $before) return;
        $this->liveEvent($state,'card.healed',['target_id'=>$target->instanceId,'source_id'=>$source?->instanceId,
            'amount'=>$target->hp-$before,'ability'=>$ability],array_values(array_unique(array_filter([$target->instanceId,$source?->instanceId]))));
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
    public function moved(GameState $state,CardInstance $card,int $fromRow,int $fromCol): void
    {
        if (!$this->eligible($state)) {
            $this->liveEvent($state,'move.started',['card_id'=>$card->instanceId,'from'=>[$fromRow,$fromCol],'to'=>[$card->row,$card->col]],[$card->instanceId]); return;
        }
        $id=$this->begin($state,'move',['card_id'=>$card->instanceId,'from'=>[$fromRow,$fromCol],'to'=>[$card->row,$card->col],'move'=>$card->move],[$card->instanceId]);
        $this->close($state,'move','settled',true);
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
        if (!$this->eligible($state)) return;
        $this->begin($state,'series_end',['card_id'=>$card->instanceId,'closed'=>$card->closed],[$card->instanceId]);
        $this->close($state,'series_end','settled',true);
    }
}
