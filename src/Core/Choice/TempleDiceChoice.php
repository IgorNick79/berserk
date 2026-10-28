<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{GameState,Engine,Command,Result,StrikeResolver};
use Berserk\View\Ui\{PanelSpec,DiceValue};
final class TempleDiceChoice implements ChoiceHandlerInterface {
 public function pendingKey():string{return 'pending_temple_dice';}
 public function commandTypes():array{return ['choose_temple_dice'];}
 public function spec(GameState $s,string $viewer,array $info,string $base,string $role):?PanelSpec {
  $p=$s->battle[$this->pendingKey()]??null;if(!$p)return null;$st=$s->battle['strike'];
  $html='<div class="temple-dice">';
  foreach(['attack'=>'Атакующий','defend'=>'Защищающийся']as$side=>$label){
   $html.='<span>'.$label.': ';
   foreach($st['temple_rolls'][$side]['values']??[$st[$side==='attack'?'attack_dice':'defend_dice']] as $die)$html.=DiceValue::render($die);
   $html.='</span>';
  }$html.='</div>';
  if($p['owner']!==$viewer)$html.='<p class="wait">Ожидание выбора Воина храма</p>';
  $buttons=[];if($p['owner']===$viewer)foreach($p['values']as$v)$buttons[]=['label'=>'Выбрать '.$v,'url'=>$base.'&cmd=choose_temple_dice&id='.rawurlencode($p['id']).'&value='.$v];
  return new PanelSpec(title:'Воин храма — выбор боевого кубика',text:[$html],buttons:$buttons,
   waitText:'Ожидание выбора Воина храма',isMine:true);
 }
 public function apply(GameState $s,Engine $e,string $owner,Command $cmd):Result {
  $p=$s->battle[$this->pendingKey()]??null;$v=(int)$cmd->get('value',0);
  if(!$p||$p['owner']!==$owner||$cmd->get('id')!==$p['id']||!in_array($v,$p['values'],true))return Result::error('Неверный выбор кубика');
  $s->battle['strike'][$p['side']==='attack'?'attack_dice':'defend_dice']=$v;
  $s->battle['strike']['temple_rolls'][$p['side']]['chosen']=$v;
  if($p['queue']){$next=array_shift($p['queue']);$next['queue']=$p['queue'];$next['id']='temple-'.++$s->battle['temple_sequence'];$s->battle[$this->pendingKey()]=$next;}
  else{unset($s->battle[$this->pendingKey()]);(new StrikeResolver($s,$e))->continueTempleDice();}
  $s->bumpVersion();return Result::ok(['temple_dice_chosen']);
 }
}
