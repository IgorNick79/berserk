<?php
declare(strict_types=1);
namespace Berserk\Core;
final class PairedRanged {
 public const PENDING='pending_paired_ranged';
 public function __construct(private GameState $s,private Engine $e){}
 public function targets(CardInstance $source,array $effect):array{return array_filter((new CoinAbilities($this->s,$this->e))->dischargeTargets($source,$effect),fn($v,$id)=>!CardStats::hasDefense($this->s,$this->s->getCard((int)$id),$effect['type'],$source),ARRAY_FILTER_USE_BOTH);}
 public function start(CardInstance $source,array $action):Result {
  $effects=$action['effects']??[];if(count($effects)!==2)return Result::error('Неверная конфигурация');
  $first=$this->targets($source,$effects[0]);$second=$this->targets($source,$effects[1]);$valid=[];
  foreach($first as $id=>$v)if(array_diff(array_keys($second),[$id]))$valid[]=(int)$id;
  if(!$valid)return Result::error('Нет двух различных целей');
  $seq=$this->s->battle['paired_sequence']=(int)($this->s->battle['paired_sequence']??0)+1;
  $this->s->battle[self::PENDING]=['id'=>'paired-'.$seq,'owner'=>$source->owner,'source_id'=>$source->instanceId,'action'=>$action,'stage'=>0,'candidates'=>$valid,'selected'=>[]];$this->s->bumpVersion();return Result::ok();
 }
 public function choose(string $owner,Command $cmd):Result {
  $p=$this->s->battle[self::PENDING]??null;if(!$p||$p['owner']!==$owner||$p['id']!==$cmd->get('operation_id')||(int)$cmd->get('stage',-1)!==$p['stage'])return Result::error('Устаревший выбор');
  if($cmd->get('cancel')){unset($this->s->battle[self::PENDING]);$this->s->bumpVersion();return Result::ok();}
  $source=$this->s->getCard($p['source_id']);$id=(int)$cmd->get('target_id');
  if(!CardHealing::live($source)||$source->closed||!in_array($id,$p['candidates'],true)||!isset($this->targets($source,$p['action']['effects'][$p['stage']])[$id]))return Result::error('Недопустимая цель');
  $p['selected'][]=$id;
  if($p['stage']===0){$p['stage']=1;$p['candidates']=array_values(array_diff(array_map('intval',array_keys($this->targets($source,$p['action']['effects'][1]))),[$id]));$this->s->battle[self::PENDING]=$p;}
  else{
   // Revalidate the first target before committing the single action cost.
   if(!isset($this->targets($source,$p['action']['effects'][0])[$p['selected'][0]]))return Result::error('Первая цель недоступна');
   unset($this->s->battle[self::PENDING]);$source->closed=true;
   $this->s->battle['paired_ranged']=$p+['index'=>0,'jobs'=>[],'next_choice'=>0];$this->s->battle['paired_ranged']['stage']='resolving';$this->advance();
  }
  $this->s->bumpVersion();return Result::ok();
 }
 public function advance():void {
  if(empty($this->s->battle['paired_ranged'])||$this->s->status!=='battle')return;
  $o=&$this->s->battle['paired_ranged'];
  if(!empty($this->s->battle['strike']['pending_choice'])||CardHealing::waiting($this->s))return;
  foreach($this->s->battle as $key=>$v)if(str_starts_with($key,'pending_')&&!empty($v))return;
  while($o['jobs']){(new DamageResolver($this->s))->executeWormDeathJob($this->e,array_shift($o['jobs']));if(!empty($this->s->battle['strike']['pending_choice'])||CardHealing::waiting($this->s))return;foreach($this->s->battle as $k=>$v)if(str_starts_with($k,'pending_')&&!empty($v))return;}
  if(CombatContinuation::busy($this->s))return;
  while($o['index']<2){$index=$o['index']++;$source=$this->s->getCard($o['source_id']);$effect=$o['action']['effects'][$index];$target=$this->s->getCard($o['selected'][$index]);
   if(!$source||!CardHealing::live($target)||!isset($this->targets($source,$effect)[$target->instanceId]))continue;
   (new ActionResolver($this->s,$this->e))->triggeredRanged($source,$target,$effect);return;
  }
  unset($this->s->battle['paired_ranged']);$this->e->checkGameOver($this->s);
 }
}
