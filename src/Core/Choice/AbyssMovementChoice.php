<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{GameState,Engine,Command,Result,DamageResolver};
use Berserk\View\Ui\PanelSpec;
final class AbyssMovementChoice implements ChoiceHandlerInterface {
 public function pendingKey():string{return 'abyss_movement';}
 public function commandTypes():array{return ['choose_abyss_death'];}
 public function isActive(GameState $s):bool{return !empty($s->battle['abyss_movement']['current'])&&!empty($s->battle['strike']['pending_choice']);}
 public function spec(GameState $s,string $playerKey,array $cardsInfo,string $baseUrl,string $role):?PanelSpec {
  $p=$s->battle['strike']['pending_choice']??null;if(!$p)return null;
  $source=$s->getCard($p['died_id']);$title=($cardsInfo[$source->ukid]['name']??$source->ukid).' — предсмертная способность';
  if($source->owner!==$playerKey)return new PanelSpec(title:$title,isMine:false);
  $buttons=[];foreach($p['candidates'] as $id)$buttons[]=['label'=>($cardsInfo[$s->getCard($id)->ukid]['name']??'Карта').' #'.$id,
   'url'=>$baseUrl.'&cmd=choose_abyss_death&choice_id='.rawurlencode($p['choice_id']).'&target_id='.$id];
  return new PanelSpec(title:$title,buttons:$buttons);
 }
 public function apply(GameState $s,Engine $e,string $playerKey,Command $cmd):Result {
  if(!$this->isActive($s))return Result::error('Нет ожидающего выбора');
  return(new DamageResolver($s))->chooseDeathTarget($playerKey,$cmd);
 }
}
