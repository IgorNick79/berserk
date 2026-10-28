<?php
declare(strict_types=1);
namespace Berserk\Core;
use Berserk\Core\Movement\MovementContext;
final class AbyssMovement {
 public function __construct(private GameState $s,private Engine $e){}
 public static function enqueue(GameState $s,MovementContext $c):void {
  if(empty($c->card->effectiveProp()['adjacent_move_wound_cheap'])||max(abs($c->deltaRow()),abs($c->deltaCol()))!==1)return;
  $ids=[];foreach($s->cards as $t)if(CardHealing::live($t)&&$t->zone==='field'&&in_array($t->type,['creature','fly'],true)&&$t->price<=4
   &&max(abs($t->row-$c->toRow),abs($t->col-$c->toCol))===1)$ids[]=$t->instanceId;
  if(!$ids)return;
  $id='abyss-'.($s->battle['abyss_sequence']=(int)($s->battle['abyss_sequence']??0)+1);
  $s->battle['abyss_movement']['queue'][]=['id'=>$id,'source_id'=>$c->card->instanceId,'targets'=>$ids,'index'=>0,'jobs'=>[],
   'stage'=>'adjacent_wounds','next_choice'=>0,'wound_pending'=>false];
 }
 public function advance():void {
  if($this->s->status!=='battle'||$this->s->winner!==null)return;
  if(empty($this->s->battle['abyss_movement']))return;
  $b=&$this->s->battle['abyss_movement'];
  if(empty($b['current'])) {
   if(empty($b['queue'])){unset($this->s->battle['abyss_movement']);return;}
   $b['current']=array_shift($b['queue']);$b['saved']=[];
   // Suspend the originating action, including its already computed choices/results.
   foreach(array_keys($this->s->battle) as $key)if($key==='strike'||$key==='turn_phase'||str_starts_with($key,'pending_')||in_array($key,['turn_instant_stack','turn_instant_result'],true)) {
    $b['saved'][$key]=$this->s->battle[$key];unset($this->s->battle[$key]);
   }
  }
  while(isset($b['current'])) {
   $o=&$b['current'];
   if(!empty($this->s->battle['strike']['pending_choice'])||CardHealing::waiting($this->s))return;
   foreach($this->s->battle as $key=>$value)if(str_starts_with($key,'pending_')&&!empty($value))return;
   if($o['jobs']){(new DamageResolver($this->s))->executeWormDeathJob($this->e,array_shift($o['jobs']));continue;}
   if($o['wound_pending']) {
    $this->e->flushDeadeatQueue($this->s);$this->s->battle['strike']=null;
    $this->e->finalizeDying($this->s);$o['wound_pending']=false;continue;
   }
   if($o['index']>=count($o['targets'])) {
    $saved=$b['saved'];unset($b['current'],$b['saved']);
    foreach($saved as $key=>$value)$this->s->battle[$key]=$value;
    $this->e->checkGameOver($this->s);
    if(empty($b['queue']))unset($this->s->battle['abyss_movement']);
    else $this->advance();
    return;
   }
   $id=$o['targets'][$o['index']++];$target=$this->s->getCard($id);
   if(!CardHealing::live($target)||$target->zone!=='field')continue;
   $o['wound_pending']=true;
   $this->s->battle['strike']=['kind'=>'abyss_movement','worm_operation'=>$o['id'],'state'=>'results',
    'attacker_id'=>$o['source_id'],'target_id'=>$id,'defender_id'=>null,'confirmed'=>[],
    'attack_dice'=>0,'defend_dice'=>0,'damage'=>0,'result'=>['attack'=>'','defend'=>'','winner'=>''],
    'final'=>['attack'=>'','defend'=>'','decreased'=>false]];
   $hpBefore=$target->hp;
   $this->e->applyDamage($this->s,$target,1,'impact',$this->s->getCard($o['source_id']));
   $this->s->battle['abyss_movement_results'][$o['id']] ??= ['source_id'=>$o['source_id'],'turn'=>$this->s->battle['turn']??0,'targets'=>[]];
   $this->s->battle['abyss_movement_results'][$o['id']]['targets'][]=['id'=>$id,'owner'=>$target->owner,'row'=>$target->row,'col'=>$target->col,'damage'=>max(0,$hpBefore-$target->hp)];
   $this->s->battle['abyss_movement_results']=array_slice($this->s->battle['abyss_movement_results'],-10,null,true);

  }
 }
}
