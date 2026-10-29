<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{GameState,Engine,Command,Result,PairedRanged,DamageResolver};
use Berserk\View\Ui\{PanelSpec,CreatureChoice};
final class PairedRangedChoice implements ChoiceHandlerInterface {
 public function pendingKey():string{return PairedRanged::PENDING;}
 public function commandTypes():array{return ['choose_paired_ranged','choose_paired_death'];}
 public function isActive(GameState $s):bool{return !empty($s->battle[$this->pendingKey()])||(!empty($s->battle['paired_ranged'])&&!empty($s->battle['strike']['pending_choice']));}
 public function spec(GameState $s,string $viewer,array $info,string $base,string $role):?PanelSpec {
  $p=$s->battle[$this->pendingKey()]??null;$death=!$p;
  if($death){$p=$s->battle['strike']['pending_choice'];$p['owner']=$s->getCard($p['died_id'])->owner;}
  $title=$death?'Предсмертная способность':($p['stage']===0?'Выберите цель разряда':'Выберите цель метания');
  if($p['owner']!==$viewer)return new PanelSpec(title:$title,isMine:false);
  parse_str((string)parse_url($base,PHP_URL_QUERY),$hidden);$hidden['cmd']=$death?'choose_paired_death':'choose_paired_ranged';
  if($death)$hidden['choice_id']=$p['choice_id'];else{$hidden['operation_id']=$p['id'];$hidden['stage']=$p['stage'];}
  $items=[];foreach($p['candidates'] as $id){$c=$s->getCard($id);$items[]=CreatureChoice::item($c,$info[$c->ukid]['name']??$c->ukid,$viewer);}
  $buttons=$death?[]:[['label'=>'Отмена','url'=>$base.'&cmd=choose_paired_ranged&operation_id='.rawurlencode($p['id']).'&stage='.$p['stage'].'&cancel=1','class'=>'cancel']];
  return new PanelSpec(title:$title,form:['type'=>'radio','name'=>'target_id','hidden'=>$hidden,'items'=>$items,'submit'=>'Выбрать','side_toggle'=>true,'side_default'=>'enemy'],buttons:$buttons);
 }
 public function apply(GameState $s,Engine $e,string $owner,Command $cmd):Result {
  if($cmd->type==='choose_paired_death'){if(empty($s->battle['paired_ranged']))return Result::error('Устаревший выбор');return (new DamageResolver($s))->chooseDeathTarget($owner,$cmd);}
  return (new PairedRanged($s,$e))->choose($owner,$cmd);
 }
}
