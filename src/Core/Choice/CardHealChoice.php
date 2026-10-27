<?php
declare(strict_types=1);
namespace Berserk\Core\Choice;
use Berserk\Core\{CardHealing,GameState,Engine,Command,Result};
use Berserk\View\Ui\PanelSpec;
final class CardHealChoice implements ChoiceHandlerInterface
{
 public function pendingKey():string{return CardHealing::PENDING;}
 public function commandTypes():array{return ['choose_card_heal'];}
 public function spec(GameState $state,string $playerKey,array $cardsInfo,string $baseUrl,string $role):?PanelSpec{
  $p=$state->battle[$this->pendingKey()]??null;if(!$p)return null;
  if($p['owner']!==$playerKey)return new PanelSpec(title:'Мрачная дворянка — излечение',isMine:false);
  $op=$p['operation'];$mode=$op['checks'][$op['index']]['mode'];
  $suffix='&cmd=choose_card_heal&heal_token='.rawurlencode($p['token']);
  return new PanelSpec(title:'Мрачная дворянка: '.($mode==='block'?'заблокировать':'удвоить').' излечение на '.$op['modified'].'?',buttons:[
   ['label'=>'Применить','url'=>$baseUrl.$suffix.'&apply=yes'],['label'=>'Отказаться','url'=>$baseUrl.$suffix.'&apply=no','class'=>'skip']]);
 }
 public function apply(GameState $state,Engine $engine,string $playerKey,Command $cmd):Result{return(new CardHealing($state,$engine))->choose($playerKey,$cmd);}
}
