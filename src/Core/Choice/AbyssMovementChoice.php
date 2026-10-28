<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{GameState,Engine,Command,Result,DamageResolver};
use Berserk\View\Ui\PanelSpec;
final class AbyssMovementChoice implements ChoiceHandlerInterface {
 public function pendingKey():string{return 'abyss_movement';}
 public function commandTypes():array{return ['choose_abyss_death','ack_abyss_movement'];}
 public function isActive(GameState $s):bool{return !empty($s->battle['abyss_movement']['result']) || (!empty($s->battle['abyss_movement']['current'])&&!empty($s->battle['strike']['pending_choice']));}
 public function spec(GameState $s,string $playerKey,array $cardsInfo,string $baseUrl,string $role):?PanelSpec {
  $result=$s->battle['abyss_movement']['result']??null;
  if($result){
   $source=$s->getCard($result['source_id']);$name=$cardsInfo[$source->ukid]['name']??$source->ukid;
   $parts=[];foreach($result['targets'] as $hit){$target=$s->getCard($hit['id']);
    $parts[]=($hit['owner']===$playerKey?'Своя карта':'Карта противника').' — '.($cardsInfo[$target->ukid]['name']??$target->ukid).' #'.$hit['id'].': '.($hit['damage']?'−'.$hit['damage'].' ❤️ HP':'урон предотвращён');}
   return new PanelSpec(title:$name.' — ранения после перемещения',isMine:$source->owner===$playerKey,
    waitText:implode(', ',$parts).' — ожидание подтверждения',text:[htmlspecialchars(implode(', ',$parts),ENT_QUOTES,'UTF-8')],
    buttons:[['label'=>'Продолжить','url'=>$baseUrl.'&cmd=ack_abyss_movement&operation_id='.rawurlencode($result['operation_id'])]]);
  }
  $p=$s->battle['strike']['pending_choice']??null;if(!$p)return null;
  $source=$s->getCard($p['died_id']);$title=($cardsInfo[$source->ukid]['name']??$source->ukid).' — предсмертная способность';
  if($source->owner!==$playerKey)return new PanelSpec(title:$title,isMine:false);
  $buttons=[];foreach($p['candidates'] as $id)$buttons[]=['label'=>($cardsInfo[$s->getCard($id)->ukid]['name']??'Карта').' #'.$id,
   'url'=>$baseUrl.'&cmd=choose_abyss_death&choice_id='.rawurlencode($p['choice_id']).'&target_id='.$id];
  return new PanelSpec(title:$title,buttons:$buttons);
 }
 public function apply(GameState $s,Engine $e,string $playerKey,Command $cmd):Result {
  if(!$this->isActive($s))return Result::error('Нет ожидающего выбора');
  if($cmd->type==='ack_abyss_movement'){
   $r=$s->battle['abyss_movement']['result']??null;
   if(!$r||$cmd->get('operation_id')!==$r['operation_id']||$s->getCard($r['source_id'])->owner!==$playerKey)return Result::error('Неверное подтверждение');
   unset($s->battle['abyss_movement']['result'],$s->battle['abyss_movement_results'][$r['operation_id']]);
   if(empty($s->battle['abyss_movement']['queue']))unset($s->battle['abyss_movement']);
   $s->bumpVersion();return Result::ok(['abyss_result_confirmed']);
  }
  if(!empty($s->battle['abyss_movement']['result']))return Result::error('Подтвердите результат');

  return(new DamageResolver($s))->chooseDeathTarget($playerKey,$cmd);
 }
}
