<?php
declare(strict_types=1);
namespace Berserk\Core;
/** Private, serializable deck operation. Deck order is committed atomically. */
final class DeckReorderProcessor {
 public const PENDING='pending_deck_reorder';
 public function __construct(private GameState $s,private Engine $e){}
 public function start(CardInstance $source,int $count=3):bool {
  if(!CardHealing::live($source)||CardStats::isDisabled($source)||!empty($this->s->battle[self::PENDING]))return false;
  if(!(new ProphecyProcessor($this->s,$this->e))->peek($source->owner,$count))return false;
  $seq=$this->s->battle['deck_reorder_sequence']=(int)($this->s->battle['deck_reorder_sequence']??0)+1;
  $this->s->battle[self::PENDING]=['id'=>'deck-reorder-'.$seq,'owner'=>$source->owner,'source_id'=>$source->instanceId,'stage'=>'offer','revision'=>0,'count'=>$count,'remaining'=>[],'selected'=>[]];return true;
 }
 public function choose(string $owner,Command $cmd):Result {
  $p=$this->s->battle[self::PENDING]??null;
  if(!$p||$p['owner']!==$owner||$cmd->get('operation_id')!==$p['id']||(int)$cmd->get('revision',-1)!==$p['revision'])return Result::error('Устаревший выбор');
  $action=$cmd->get('action');
  if($p['stage']==='offer'){
   if($action==='cancel'){unset($this->s->battle[self::PENDING]);$this->s->bumpVersion();return Result::ok();}
   if($action!=='show')return Result::error('Неверное действие');
   $peek=(new ProphecyProcessor($this->s,$this->e))->peek($owner,$p['count']);
   $p['remaining']=array_map(static fn($c)=>$c->instanceId,$peek['cards']??[]);$p['stage']='view';
  }elseif($p['stage']==='view'){
   $direction=$cmd->get('direction');if(!in_array($direction,['top','bottom'],true)||!in_array($action,['next','finish'],true))return Result::error('Неверное направление');
   $p['direction']=$direction;$p['stage']='order';
  }elseif($p['stage']==='order'){
   if($action==='pick'){$id=(int)$cmd->get('card_id');if(!in_array($id,$p['remaining'],true))return Result::error('Недопустимая карта');$p['selected'][]=$id;$p['remaining']=array_values(array_diff($p['remaining'],[$id]));}
   elseif($action!=='finish')return Result::error('Неверное действие');
  }
  if($action==='finish'||($p['stage']==='order'&&!$p['remaining'])){
   shuffle($p['remaining']);$edge=array_merge($p['selected'],$p['remaining']);
   $deck=(new ProphecyProcessor($this->s,$this->e))->peek($owner,PHP_INT_MAX);
   $rest=array_values(array_filter(array_map(static fn($c)=>$c->instanceId,$deck['cards']??[]),static fn($id)=>!in_array($id,$edge,true)));
   $ids=$p['direction']==='top'?array_merge($edge,$rest):array_merge($rest,array_reverse($edge));
   foreach($ids as $order=>$id)$this->s->getCard($id)->order=$order;
   unset($this->s->battle[self::PENDING]);
  }else{++$p['revision'];$this->s->battle[self::PENDING]=$p;}
  $this->s->bumpVersion();return Result::ok();
 }
}
