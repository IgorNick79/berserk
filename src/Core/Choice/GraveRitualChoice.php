<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{GameState,Engine,Command,Result,GraveRitual,TurnPhaseProcessor};
use Berserk\View\Ui\{PanelSpec,CreatureChoice};
final class GraveRitualChoice implements ChoiceHandlerInterface {
 public function pendingKey():string{return GraveRitual::PENDING;}
 public function commandTypes():array{return ['choose_grave_ritual'];}
 public function spec(GameState $s,string $viewer,array $info,string $base,string $role):?PanelSpec {
  $p=$s->battle[$this->pendingKey()]??null;if(!$p)return null;if($p['owner']!==$viewer)return new PanelSpec(title:'Жетон инкарнации',isMine:false);
  if(!empty($p['result'])){
   $source=$s->getCard($p['source_id']);$card=$s->getCard($p['milled_id']);
   $text=[htmlspecialchars($info[$card->ukid]['name']??$card->ukid,ENT_QUOTES).' — отправлена с верха колоды на кладбище.'];
   if(!empty($p['token_id'])){$target=$s->getCard($p['token_id']);$text[]=htmlspecialchars($info[$target->ukid]['name']??$target->ukid,ENT_QUOTES).' — получила жетон инкарнации.';}
   if($p['undead'])$text[]='Ваши демоны: сильный удар +'.(int)($p['cfg']['strong']??1).', ОВА +'.(int)($p['cfg']['ova']??1).' до конца хода.';
   return new PanelSpec(title:$info[$source->ukid]['name']??'Лилит и Эйдерик',text:$text,buttons:[['label'=>'Продолжить','url'=>$base.'&cmd=choose_grave_ritual&operation_id='.rawurlencode($p['id']).'&ack=1']]);
  }
  parse_str((string)parse_url($base,PHP_URL_QUERY),$hidden);$hidden['cmd']='choose_grave_ritual';$hidden['operation_id']=$p['id'];$items=[];
  foreach($p['candidates'] as $id){$c=$s->getCard($id);$items[]=CreatureChoice::item($c,($info[$c->ukid]['name']??$c->ukid).' #'.$id,$viewer);}
  return new PanelSpec(title:'Выберите нежить на кладбище',form:['type'=>'radio','name'=>'target_id','items'=>$items,'hidden'=>$hidden,'submit'=>'Выбрать']);
 }
 public function apply(GameState $s,Engine $e,string $owner,Command $cmd):Result {
  $r=(new GraveRitual($s,$e))->choose($owner,$cmd);return $r;
 }
}
