<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Local continuation owned by the existing incarnation pending. */
final class WormIncarnation
{
    public function __construct(private GameState $state, private Engine $engine) {}
    public static function operation(GameState $s): ?array { return $s->battle['pending_incarnation']['worm_operation'] ?? null; }
    public static function defersElimination(GameState $s): bool {
        $o=self::operation($s);
        return $s->status==='battle' && $s->winner===null && $o
            && $o['worm_id']===($s->battle['pending_incarnation']['current']??null) && in_array($o['stage'],['destroy','effects','placing','adjacent_wounds'],true);
    }
    public function start(CardInstance $worm,int $row,int $col,string $marker): Result {
        if(self::operation($this->state))return Result::error('Инкарнация уже выполняется');
        $ids=[];foreach($this->state->cards as $c)if($c->zone==='field'&&$c->row===$row&&$c->col===$col)$ids[]=$c->instanceId;
        $id='worm-' . ($this->state->battle['worm_sequence']=(int)($this->state->battle['worm_sequence']??0)+1);
        $this->state->battle['pending_incarnation']['worm_operation']=['id'=>$id,'worm_id'=>$worm->instanceId,'row'=>$row,'col'=>$col,
            'marker_id'=>$marker,'targets'=>$ids,'index'=>0,'stage'=>'destroy','jobs'=>[],'next_choice'=>0,'placed'=>false,'consumed'=>false];
        $this->advance();$this->state->bumpVersion();return Result::ok(['worm_incarnation_started']);
    }
    public function advance(): void {
        if(!self::operation($this->state)||$this->state->status!=='battle'||$this->state->winner!==null)return;
        while(self::operation($this->state)) {
            $o=&$this->state->battle['pending_incarnation']['worm_operation'];
            if(!empty($this->state->battle['strike']['pending_choice'])||CardHealing::waiting($this->state))return;
            foreach($this->state->battle as $key=>$value)if(str_starts_with($key,'pending_')&&$key!=='pending_incarnation'&&!empty($value))return;
            if($o['jobs']) {
                $job=array_shift($o['jobs']);
                (new DamageResolver($this->state))->executeWormDeathJob($this->engine,$job);
                continue;
            }
            if($o['stage']==='adjacent_wounds') {
                if($o['wound_pending']) {
                    $this->engine->flushDeadeatQueue($this->state);$this->state->battle['strike']=null;
                    $this->engine->finalizeDying($this->state);$o['wound_pending']=false;
                    continue;
                }
                if($o['adjacent_index']>=count($o['adjacent_ids'])) {
                    $o['stage']='complete';$this->engine->checkGameOver($this->state);
                    $worm=$this->state->getCard($o['worm_id']);$row=$o['row'];$col=$o['col'];
                    unset($this->state->battle['pending_incarnation']['worm_operation']);
                    (new TurnProcessor($this->state,$this->engine))->finishIncarnation($worm->owner,$worm,$row,$col);return;
                }
                $id=$o['adjacent_ids'][$o['adjacent_index']++];
                $target=$this->state->getCard($id);
                if(!CardHealing::live($target)||$target->zone!=='field')continue;
                $o['wound_pending']=true;
                $this->state->battle['strike']=['kind'=>'worm_incarnation','worm_operation'=>$o['id'],'state'=>'results',
                    'attacker_id'=>$o['worm_id'],'target_id'=>$id,'defender_id'=>null,'confirmed'=>[],
                    'result'=>['attack'=>'','defend'=>'','winner'=>''],'final'=>['attack'=>'','defend'=>'','decreased'=>false],
                    'attack_dice'=>0,'defend_dice'=>0,'damage'=>0];
                $this->engine->applyDamage($this->state,$target,1,'impact',$this->state->getCard($o['worm_id']));continue;
            }
            if($o['stage']==='effects') {
                $this->engine->flushDeadeatQueue($this->state);
                $this->state->battle['strike']=null;
                $this->engine->finalizeDying($this->state);
                $o['index']++;$o['stage']='destroy';continue;
            }
            if($o['index']<count($o['targets'])) {
                $target=$this->state->getCard($o['targets'][$o['index']]);
                if(!$target||!in_array($target->zone,['field','flying'],true)||$target->dying){$o['index']++;continue;}
                $o['stage']='effects';
                $this->state->battle['strike']=['kind'=>'worm_incarnation','worm_operation'=>$o['id'],'state'=>'results',
                    'attacker_id'=>$o['worm_id'],'target_id'=>$target->instanceId,'defender_id'=>null,'confirmed'=>[],
                    'result'=>['attack'=>'','defend'=>'','winner'=>''],'final'=>['attack'=>'','defend'=>'','decreased'=>false],
                    'attack_dice'=>0,'defend_dice'=>0,'damage'=>0];
                $this->engine->forceDeath($this->state,$target,'destroy',$this->state->getCard($o['worm_id']));continue;
            }
            // Re-entrant effects may have populated the cell. Never overlap an occupant.
            foreach($this->state->cards as $c)if($c->zone==='field'&&$c->row===$o['row']&&$c->col===$o['col']) {
                if(!in_array($c->instanceId,$o['targets'],true)){$o['targets'][]=$c->instanceId;continue 2;}
                throw new \LogicException('Worm destination was not cleared');
            }
            $o['stage']='placing';
            $r=(new TurnProcessor($this->state,$this->engine))->chooseIncarnationCell($this->state->getCard($o['worm_id'])->owner,
                new Command('choose_incarnation_cell',['incarnation_id'=>$o['worm_id'],'row'=>$o['row'],'col'=>$o['col']]));
            if(!$r->success)throw new \LogicException('Worm placement failed: '.$r->error);
            continue;
        }
    }
}
