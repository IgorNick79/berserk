<?php
declare(strict_types=1);
namespace Berserk\Core;
final class AlvalindProcessor {
 public const MARKER='alvalind';
 public function __construct(private GameState $s,private Engine $e){}
 public static function count(CardInstance $c):int{return (int)($c->markers[self::MARKER]['value']??0);}
 public static function config(CardInstance $c):?array {
  $p=$c->effectiveProp();$miss=$p['on_ally_strike_miss']??null;$threshold=$p['on_counter_threshold']??null;$end=$p['turn_end_discharge']??null;
  if(!is_array($miss)&&!is_array($end))return null;
  return ['miss'=>$miss,'end'=>$end,'threshold'=>$threshold['value']??PHP_INT_MAX,'recoil'=>$threshold['self_damage']['value']??0,'base'=>$end['value']??1,'per_counter'=>$end['bonus_per_counter']['value']??0];
 }
 public function targets(CardInstance $source):array {
  $cfg=self::config($source);$action=['type'=>'discharge','value'=>(int)($cfg['base']??1)];
  return array_filter((new CoinAbilities($this->s,$this->e))->dischargeTargets($source,$action),function($allowed,$id)use($source){
   $t=$this->s->getCard((int)$id);return empty(self::config($source)['end']['exclude_adjacent'])||$source->zone!=='field'||$t->zone!=='field'||max(abs($source->row-$t->row),abs($source->col-$t->col))>1;
  },ARRAY_FILTER_USE_BOTH);
 }
 public function beforeEnd(string $owner):bool {
  $key=($this->s->battle['turn']??0).':'.$owner;
  if(($this->s->battle['alvalind_end_done'][$owner]??null)===$key)return false;
  if(!empty($this->s->battle['alvalind_end']))return true;
  foreach($this->s->cards as $c){
   if($c->owner!==$owner||!CardHealing::live($c)||empty(self::config($c)['end']))continue;
   $targets=$this->targets($c);if(!$targets)continue;$cfg=self::config($c);
   $id='alvalind-'.($this->s->battle['alvalind_sequence']=(int)($this->s->battle['alvalind_sequence']??0)+1);
   $p=['id'=>$id,'owner'=>$owner,'source_id'=>$c->instanceId,'turn_key'=>$key,'value'=>(int)($cfg['base']??1)+(int)($cfg['per_counter']??2)*self::count($c),'targets'=>array_map('intval',array_keys($targets))];
   $this->s->battle['alvalind_end']=$p+['stage'=>'choice','jobs'=>[],'next_choice'=>0];$this->s->battle['pending_alvalind_end']=$p;return true;
  }return false;
 }
 public function choose(string $owner,Command $cmd):Result {
  $p=$this->s->battle['pending_alvalind_end']??null;$id=(int)$cmd->get('target_id',0);
  if(!$p||$p['owner']!==$owner||$cmd->get('operation_id')!==$p['id'])return Result::error('Устаревший выбор разряда');
  $source=$this->s->getCard($p['source_id']);
  if(!CardHealing::live($source)||!isset($this->targets($source)[$id]))return Result::error('Недопустимая цель');
  unset($this->s->battle['pending_alvalind_end']);$this->s->battle['alvalind_end']['stage']='resolving';
  $r=(new ActionResolver($this->s,$this->e))->triggeredDischarge($source,$this->s->getCard($id),['type'=>'discharge','name'=>'Разряд Алвалинд','value'=>$p['value']]);
  if(!$r->success){$this->s->battle['pending_alvalind_end']=$p;$this->s->battle['alvalind_end']['stage']='choice';return $r;}
  $this->s->bumpVersion();return $r;
 }
 public function advanceEnd():void {
  $p=$this->s->battle['alvalind_end']??null;
  if(!$p||$p['stage']!=='resolving'||$this->s->status!=='battle'||$this->s->winner!==null)return;
  if(!empty($this->s->battle['strike']['pending_choice'])||CardHealing::waiting($this->s))return;
  foreach($this->s->battle as $k=>$v)if(str_starts_with($k,'pending_')&&!empty($v))return;
  while(!empty($this->s->battle['alvalind_end']['jobs'])){
   $job=array_shift($this->s->battle['alvalind_end']['jobs']);(new DamageResolver($this->s))->executeWormDeathJob($this->e,$job);
   if(!empty($this->s->battle['strike']['pending_choice'])||CardHealing::waiting($this->s))return;
   foreach($this->s->battle as $k=>$v)if(str_starts_with($k,'pending_')&&!empty($v))return;
  }
  if(CombatContinuation::busy($this->s))return;
  $source=$this->s->getCard($p['source_id']);unset($source->markers[self::MARKER]);
  $this->s->battle['alvalind_end_done'][$p['owner']]=$p['turn_key'];unset($this->s->battle['alvalind_end']);
  $this->e->checkGameOver($this->s);
  if($this->s->status==='battle')(new TurnProcessor($this->s,$this->e))->endTurn($p['owner'],new Command('end_turn'));
 }
 /** Called only once the strike's result, wound window and local follow-ups are complete. */
 public function completedStrike(array $strike):bool {
  if(($strike['kind']??'strike')!=='strike'||!empty($this->s->battle['strike']['alvalind_counted']))return false;
  $this->s->battle['strike']['alvalind_counted']=true;
  foreach(['attacker_id','defender_id'] as $field){
   if($field==='defender_id' && empty($strike['defend_dice']))continue;
   $id=$strike[$field]??null;if(!$id&&$field==='defender_id')$id=$strike['target_id']??null;
   $actor=$this->s->getCard((int)$id);if(!$actor||$actor->owner!==($this->s->battle['active']??null)||!in_array($actor->type,['creature','fly'],true))continue;
   if((int)($strike['actual_strike_wounds'][$actor->instanceId]??0)>0)continue;
   foreach($this->s->cards as $source){
    $cfg=self::config($source);if(!$cfg||empty($cfg['miss'])||$source->owner!==$actor->owner||!CardHealing::live($source))continue;
    $value=self::count($source)+1;$source->markers[self::MARKER]=['value'=>$value];
    if($value<(int)($cfg['threshold']??3))continue;
    unset($source->markers[self::MARKER]);
    $this->s->battle['alvalind_recoil']=['id'=>'recoil-'.($this->s->battle['alvalind_recoil_sequence']=(int)($this->s->battle['alvalind_recoil_sequence']??0)+1),
     'source_id'=>$source->instanceId,'stage'=>'adjacent_wounds','jobs'=>[],'next_choice'=>0,'saved_strike'=>$this->s->battle['strike']];
    $this->s->battle['strike']=['kind'=>'alvalind_recoil','action_name'=>'Всё пропало','state'=>'results','worm_operation'=>$this->s->battle['alvalind_recoil']['id'],'attacker_id'=>$source->instanceId,'target_id'=>$source->instanceId,'defender_id'=>null,
     'attack_dice'=>0,'defend_dice'=>0,'confirmed'=>[],'damage'=>0,'result'=>['attack'=>'','defend'=>'','winner'=>''],'final'=>['attack'=>'','defend'=>'','decreased'=>false]];
    $this->e->applyDamage($this->s,$source,(int)($cfg['recoil']??2),'impact',$source);return true;
   }
  }return false;
 }
 public function advanceRecoil():void {
  if(empty($this->s->battle['alvalind_recoil'])||$this->s->status!=='battle')return;
  while(true){
   if(!empty($this->s->battle['strike']['pending_choice'])||CardHealing::waiting($this->s))return;
   foreach($this->s->battle as $k=>$v)if(str_starts_with($k,'pending_')&&!empty($v))return;
   $o=&$this->s->battle['alvalind_recoil'];
   if($o['jobs']){(new DamageResolver($this->s))->executeWormDeathJob($this->e,array_shift($o['jobs']));continue;}
   $saved=$o['saved_strike'];$this->s->battle['strike']=null;$this->e->finalizeDying($this->s);
   unset($this->s->battle['alvalind_recoil']);$this->s->battle['strike']=$saved;
   if($this->s->status==='battle')(new StrikeResolver($this->s,$this->e))->continueAfterStrikeResultAck($saved);return;
  }
 }
}
