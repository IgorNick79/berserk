<?php
declare(strict_types=1);
namespace Berserk\Core;
final class GraveRitual {
 public const PENDING='pending_grave_ritual';
 public function __construct(private GameState $s,private Engine $e){}
 public static function bonus(GameState $s,CardInstance $c,string $stat):int {
  $n=0;foreach($s->battle['class_turn_bonuses']??[] as $b)if($b['owner']===$c->owner&&$b['turn']===($s->battle['turn']??0)&&EffectiveFeatures::onBattlefield($c)&&CardStats::hasClass($c,$b['class']))$n+=(int)($b[$stat]??0);return $n;
 }
 public function start(CardInstance $source,array $cfg):array {
  $id='ritual:'.($this->s->battle['turn']??0).':'.$source->instanceId;
  if(in_array($id,$this->s->battle['grave_ritual_done']??[],true))return ['skip_ack'=>true];
  $this->s->battle['grave_ritual_done'][]=$id;
  $peek=(new ProphecyProcessor($this->s,$this->e))->peek($source->owner,1);if(!$peek)return ['skip_ack'=>true];
  $card=$peek['cards'][0];$demon=CardStats::hasClass($card,$cfg['demon_class']);$undead=CardStats::hasClass($card,$cfg['undead_class']);
  $this->s->battle['grave_ritual']=['id'=>$id,'owner'=>$source->owner,'source_id'=>$source->instanceId,'stage'=>'milled','demon'=>$demon,'undead'=>$undead,'cfg'=>$cfg];
  (new ZoneManager($this->s))->toGraveyard($card);
  $this->advance();if(!empty($this->s->battle['grave_ritual']))$this->s->battle['grave_ritual_waiting']=true;return ['skip_ack'=>true];
 }
 public function advance():void {
  $p=$this->s->battle['grave_ritual']??null;if(!$p)return;
  foreach($this->s->battle as $k=>$v)if(str_starts_with($k,'pending_')&&!empty($v))return;
  $cfg=$p['cfg'];
  if($p['stage']==='milled'){
   $this->s->battle['grave_ritual']['stage']='token';
   if($p['undead'])$this->s->battle['class_turn_bonuses'][]=['owner'=>$p['owner'],'turn'=>$this->s->battle['turn'],'class'=>$cfg['demon_class'],'strong'=>(int)($cfg['strong']??1),'ova'=>(int)($cfg['ova']??1)];
   if($p['demon']){
    $ids=[];foreach($this->s->cards as $c)if($c->owner===$p['owner']&&$c->zone==='graveyard'&&CardStats::hasClass($c,$cfg['undead_class']))$ids[]=$c->instanceId;
    if(count($ids)>1){$this->s->battle[self::PENDING]=['id'=>$p['id'],'owner'=>$p['owner'],'candidates'=>$ids];return;}
    if($ids)(new IncarnationTokenProcessor($this->s,$this->e))->grant($this->s->getCard($ids[0]));
   }
  }
  foreach($this->s->battle as $k=>$v)if(str_starts_with($k,'pending_')&&!empty($v))return;
  unset($this->s->battle['grave_ritual']);
  if(!empty($this->s->battle['grave_ritual_waiting'])){unset($this->s->battle['grave_ritual_waiting']);(new TurnPhaseProcessor($this->s,$this->e))->resume();}
 }
 public function choose(string $owner,Command $cmd):Result {
  $p=$this->s->battle[self::PENDING]??null;$c=$this->s->getCard((int)$cmd->get('target_id'));
  if(!$p||$p['owner']!==$owner||$cmd->get('operation_id')!==$p['id']||!$c||!in_array($c->instanceId,$p['candidates'],true)||$c->zone!=='graveyard'||$c->owner!==$owner)return Result::error('Устаревший выбор нежити');
  unset($this->s->battle[self::PENDING]);(new IncarnationTokenProcessor($this->s,$this->e))->grant($c);$this->advance();$this->s->bumpVersion();return Result::ok();
 }
}
