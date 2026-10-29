<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{GameState,Engine,Command,Result,DeckReorderProcessor,TurnPhaseProcessor};
use Berserk\View\Ui\PanelSpec;
final class DeckReorderChoice implements ChoiceHandlerInterface {
 public function pendingKey():string{return DeckReorderProcessor::PENDING;}
 public function commandTypes():array{return ['choose_deck_reorder'];}
 public function spec(GameState $s,string $viewer,array $info,string $base,string $role):?PanelSpec {
  $p=$s->battle[$this->pendingKey()]??null;if(!$p)return null;
  if($p['owner']!==$viewer)return new PanelSpec(title:'Просмотр колоды',isMine:false);
  $url=$base.'&cmd=choose_deck_reorder&operation_id='.rawurlencode($p['id']).'&revision='.$p['revision'];$buttons=[];$cards=[];$text=[];
  if($p['stage']==='offer')foreach(['show'=>'Показать','cancel'=>'Отмена'] as $action=>$label)$buttons[]=['label'=>$label,'url'=>$url.'&action='.$action,'class'=>$action==='cancel'?'seer-cancel':''];
  else {
   $tpl=new \Berserk\View\Template(dirname(__DIR__,3).'/templates');
   foreach($p['stage']==='view'?$p['remaining']:[] as $id){$c=$s->getCard($id);$i=$info[$c->ukid]??[];
    $cards[]=$tpl->parse('includes/prophecy_card.tpl',['image_html'=>preg_match('/\A[a-zA-Z0-9_-]+\z/D',$c->ukid)?'<a class="panel-card-preview" href="/assets/cards/s1/original/'.rawurlencode($c->ukid).'.png" target="_blank" rel="noopener"><img src="/assets/cards/s1/'.rawurlencode($c->ukid).'.webp" alt="'.htmlspecialchars(($i['name']??$c->ukid),ENT_QUOTES).'" style="max-width:100%;height:auto"></a>':'','name'=>htmlspecialchars(($i['name']??$c->ukid),ENT_QUOTES),'price'=>$i['price']??0,'health'=>$i['health']??0,'weak'=>$i['strike']['weak']??0,'medium'=>$i['strike']['medium']??0,'strong'=>$i['strike']['strong']??0,'elite'=>!empty($i['elite'])?'elite':'']);
   }
   if($p['stage']==='view'){
    parse_str((string)parse_url($base,PHP_URL_QUERY),$params);$hidden='';foreach($params as $k=>$v)if(is_scalar($v)&&!in_array($k,['cmd','action','direction','revision','operation_id'],true))$hidden.='<input type="hidden" name="'.htmlspecialchars((string)$k,ENT_QUOTES).'" value="'.htmlspecialchars((string)$v,ENT_QUOTES).'">';$text[]='<form method="get" action="">'.$hidden.'<input type="hidden" name="cmd" value="choose_deck_reorder"><input type="hidden" name="operation_id" value="'.htmlspecialchars($p['id'],ENT_QUOTES).'"><input type="hidden" name="revision" value="'.$p['revision'].'"><p>Положить в колоду</p><div class="seer-directions"><label><input type="radio" name="direction" value="top" checked>Наверх</label><label><input type="radio" name="direction" value="bottom">Вниз</label></div><div class="seer-actions"><button class="button" name="action" value="next">Далее</button><button class="button seer-finish" name="action" value="finish">Закончить</button></div></form>';
   }else{foreach($p['remaining'] as $id){$c=$s->getCard($id);$buttons[]=['label'=>($info[$c->ukid]['name']??$c->ukid).' #'.$id,'url'=>$url.'&action=pick&card_id='.$id];}$buttons[]=['label'=>'Закончить','url'=>$url.'&action=finish','class'=>'seer-finish'];}
  }
  if($cards){$text=array_merge(['<div class="seer-card-grid prophecy-cards">'.implode('',$cards).'</div>'],$text);$cards=[];}
  return new PanelSpec(title:$p['stage']==='order'?'Укажите порядок карт':'Просмотр верхних карт колоды',cards:$cards,text:$text,buttons:$buttons);
 }
 public function apply(GameState $s,Engine $e,string $viewer,Command $cmd):Result {
  $r=(new DeckReorderProcessor($s,$e))->choose($viewer,$cmd);
  if($r->success&&empty($s->battle[$this->pendingKey()])&&!empty($s->battle['turn_phase']))(new TurnPhaseProcessor($s,$e))->resume();return $r;
 }
}
