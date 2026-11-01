<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
Berserk\Core\Autoloader::register();Berserk\Core\Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
use Berserk\Core\{GameState,GameChat,MatchPause};
use Berserk\View\Screen\{TurnScreen,SideScreen};
function rollAssert(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
foreach(['host','player'] as $winner)foreach(['host','player'] as $viewer){
 $s=new GameState(1,1,2);$s->status='turn';$s->firstPlayer=$winner;$s->getPlayer('host')->dice=$winner==='host'?6:2;$s->getPlayer('player')->dice=$winner==='player'?6:2;
 $before=serialize($s->toArray());$d=(new TurnScreen())->prepare($s,$viewer,$viewer,null)['data'];
 rollAssert(str_contains($d['panel_html'],$winner===$viewer?'Ты выбираешь':'Оппонент выбирает'),'Roll winner perspective');
 rollAssert(str_contains($d['panel_actions_html'],'confirm_turn'),'Available confirmation');
 rollAssert($before===serialize($s->toArray()),'Turn renderer does not mutate game');
 $s->getPlayer($viewer)->confirm('turn');$d=(new TurnScreen())->prepare($s,$viewer,$viewer,null)['data'];rollAssert(str_contains($d['panel_actions_html'],'Ожидание оппонента')&&!str_contains($d['panel_actions_html'],'confirm_turn'),'Confirmed viewer waits');
 $s->status='side';$before=serialize($s->toArray());$d=(new SideScreen())->prepare($s,$viewer,$viewer,null)['data'];rollAssert(str_contains($d['panel_html'],'pregame-side-choice')===($viewer===$winner),'Only winner gets side choice');rollAssert(str_contains($d['panel_html'],'panel-dice-face'),'Side retains real dice results');rollAssert($before===serialize($s->toArray()),'Side renderer does not mutate game');
}
foreach(['battle','view','turn','side'] as $stage)rollAssert(GameChat::canRead($stage)&&GameChat::canSend($stage)&&MatchPause::supportsStage($stage),'Allowed stage '.$stage);
foreach(['deck','place','deal','settings','draft'] as $stage)rollAssert(!GameChat::canSend($stage)&&!MatchPause::supportsStage($stage),'Unchanged unsupported stage '.$stage);
rollAssert(GameChat::canRead('game_over')&&!GameChat::canSend('game_over')&&!MatchPause::supportsStage('game_over'),'Finished stage remains read only');
echo "Roll presentation and stage contracts passed.\n";
