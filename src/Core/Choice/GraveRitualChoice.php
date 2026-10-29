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
  parse_str((string)parse_url($base,PHP_URL_QUERY),$hidden);$hidden['cmd']='choose_grave_ritual';$hidden['operation_id']=$p['id'];$items=[];
  foreach($p['candidates'] as $id){$c=$s->getCard($id);$items[]=CreatureChoice::item($c,($info[$c->ukid]['name']??$c->ukid).' #'.$id,$viewer);}
  return new PanelSpec(title:'Выберите нежить на кладбище',form:['type'=>'radio','name'=>'target_id','items'=>$items,'hidden'=>$hidden,'submit'=>'Выбрать']);
 }
 public function apply(GameState $s,Engine $e,string $owner,Command $cmd):Result {
  $r=(new GraveRitual($s,$e))->choose($owner,$cmd);return $r;
 }
}
