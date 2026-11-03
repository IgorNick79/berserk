<?php
declare(strict_types=1);
require __DIR__.'/test_turn_timer.php';
use Berserk\Core\{GameState,GameSettings,CardInstance,TurnProcessor,TurnTimer,Engine,Command,MatchPause,TurnTimeoutEligibility,DamageResolver};
use Berserk\Lobby\RoomSetup;
$checks=0;function tm(bool $ok,string $why):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function tmState(int $host,int $player,array $input=[]): GameState {
    $s=new GameState(41,1,2);$s->status='battle';$s->players['host']->side=1;$s->players['player']->side=2;
    $s->settings=GameSettings::fromArray(RoomSetup::parse('lucktest',$input+['turn_time_mode'=>'adaptive'])['settings']);
    foreach(['host'=>$host,'player'=>$player] as $owner=>$count)for($i=0;$i<$count;$i++)$s->addCard(new CardInstance(instanceId:$s->nextInstanceId(),ukid:'card_'.$owner.'_'.$i,owner:$owner,zone:'field',row:($owner==='host'?1:4)+intdiv($i,5),col:$i%5+1,hp:5,hpMax:5));
    return $s;
}
$time=10000;$e=new Engine(null,static function()use(&$time){return $time;});
foreach([10,6,2,1] as $count){$s=tmState($count,2);(new TurnProcessor($s,$e))->startBattle();tm(TurnTimer::snapshot($s,$time)['limit_seconds']===$count*30,'adaptive count '.$count);
 $time+=5;TurnTimer::settle($s,$time);$before=$s->battle['turn_timer'];TurnTimer::prepare($s,'host');(new TurnProcessor($s,$e))->afterStartPhase('host');tm($s->battle['turn_timer']===$before,'same turn is not reset');
 $s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));tm(TurnTimer::snapshot($s,$time)['limit_seconds']===$count*30,'restore stored limit');}
$excluded=tmState(6,2);$excluded->battle=['active'=>'host','turn'=>1];foreach(['deck','hand','squad','graveyard','exile'] as $zone)$excluded->addCard(new CardInstance(instanceId:$excluded->nextInstanceId(),ukid:'outside_'.$zone,owner:'host',zone:$zone,hp:5,hpMax:5));$excluded->addCard(new CardInstance(instanceId:$excluded->nextInstanceId(),ukid:'dead',owner:'host',zone:'field',hp:0,hpMax:5));$excluded->addCard(new CardInstance(instanceId:$excluded->nextInstanceId(),ukid:'dying',owner:'host',zone:'field',hp:5,hpMax:5,dying:true));$excluded->addCard(new CardInstance(instanceId:$excluded->nextInstanceId(),ukid:'artifact',owner:'host',zone:'field',hp:5,hpMax:5,type:'artifact'));TurnTimer::prepare($excluded,'host');tm($excluded->battle['turn_timer']['limit_seconds']===180,'only live owned field creatures counted');
$growth=tmState(6,2);$growthTurn=new TurnProcessor($growth,$e);$growthTurn->startBattle();$growth->addCard(new CardInstance(instanceId:$growth->nextInstanceId(),ukid:'new_creature',owner:'host',zone:'field',row:2,col:2,hp:5,hpMax:5));TurnTimer::prepare($growth,'host');tm($growth->battle['turn_timer']['limit_seconds']===180,'appearance alone cannot extend current turn');tm($growthTurn->endTurn('host',new Command('end_turn'))->success,'growth host end');tm($growthTurn->endTurn('player',new Command('end_turn'))->success,'growth player end');tm($growth->battle['turn_timer']['limit_seconds']===210,'appearance counted only next own turn');
$s=tmState(6,2);$s->getCard(1)->type='fly';(new TurnProcessor($s,$e))->startBattle();tm($s->getCard(1)->zone==='flying'&&$s->battle['turn_timer']['limit_seconds']===180,'flying creatures count');
$s->getCard(2)->zone='graveyard';$s->getCard(2)->hp=0;$s->addCard(new CardInstance(instanceId:$s->nextInstanceId(),ukid:'summoned',owner:'host',zone:'field',row:2,col:5,hp:5,hpMax:5));
TurnTimer::prepare($s,'host');tm($s->battle['turn_timer']['limit_seconds']===180,'death and summon do not change current limit');
$p=new TurnProcessor($s,$e);tm($p->endTurn('host',new Command('end_turn'))->success,'host ends');tm($s->battle['turn_timer']['limit_seconds']===60,'other owner count');
$s->getCard(3)->zone='graveyard';$s->getCard(3)->hp=0;tm($p->endTurn('player',new Command('end_turn'))->success,'opponent ends');tm($s->battle['turn_timer']['limit_seconds']===150,'death on opponent turn affects next host turn');
foreach(['none','fixed','adaptive'] as $mode){$s=tmState(6,2,['turn_time_mode'=>$mode,'turn_fixed_seconds'=>'225']);(new TurnProcessor($s,$e))->startBattle();
 if($mode==='none')tm(TurnTimer::snapshot($s,$time)===null,'none has no clock');else tm(TurnTimer::snapshot($s,$time)['limit_seconds']===($mode==='fixed'?225:180),'mode period limit');
 tm(MatchPause::apply($s,'host',new Command('request_pause',['duration_seconds'=>60]),$time)->success,'pause request '.$mode);$request=$s->battle['match_pause']['request_id'];
 tm(MatchPause::apply($s,'player',new Command('accept_pause',['request_id'=>$request]),$time+1)->success,'pause accept');$frozen=TurnTimer::snapshot($s,$time+2);
 foreach(['host','player'] as $owner)tm(MatchPause::apply($s,$owner,new Command('pause_ready',['request_id'=>$request]),$time+3)->success,'ready');
 tm(($mode==='none')===!isset($s->battle['turn_timer']),'pause never invents a clock');if($frozen)tm(TurnTimer::snapshot($s,$time+3)['limit_seconds']===$frozen['limit_seconds'],'pause keeps period limit');}
$s=tmState(2,2);(new TurnProcessor($s,$e))->startBattle();$id=$s->battle['turn_timer']['period_id'];TurnTimer::settle($s,$time+60);tm($s->battle['turn_timer']['status']==='expired','expiry exact');
$s->battle['turn_phase']=['phase'=>'start'];tm(!TurnTimeoutEligibility::check($s,$time+60)['allowed'],'mandatory phase blocks timeout');unset($s->battle['turn_phase']);TurnTimer::prepare($s,'host');tm($s->battle['turn_timer']['period_id']===$id&&$s->battle['turn_timer']['remaining_seconds']===0,'expired request cannot refill');
$s=tmState(0,1);(new DamageResolver($s))->checkGameOver();TurnTimer::prepare($s,'host');tm($s->status==='game_over'&&$s->winner==='player'&&!isset($s->battle['turn_timer']),'zero resolved by existing elimination, no zero clock');
foreach([['turn_time_mode'=>'fixed','turn_fixed_seconds'=>'59'],['turn_time_mode'=>'fixed','turn_fixed_seconds'=>'601'],['turn_time_mode'=>'fixed','turn_fixed_seconds'=>'61'],['turn_time_mode'=>'adaptive','turn_creature_seconds'=>'9'],['turn_time_mode'=>'adaptive','turn_creature_seconds'=>'121'],['turn_time_mode'=>'adaptive','turn_creature_seconds'=>'15'],['turn_time_mode'=>'bad'],['turn_time_mode'=>null],['turn_seconds'=>'0','turn_fixed_seconds'=>'-1'],['turn_time_mode'=>[]],['turn_fixed_seconds'=>'1.5'],['turn_creature_seconds'=>'-10']] as $bad){try{RoomSetup::parse('sealed',$bad);tm(false,'bad time accepted');}catch(DomainException){tm(true,'bad time rejected');}}
foreach([60,225,600] as $limit){$settings=RoomSetup::parse('sealed',['turn_time_mode'=>'fixed','turn_fixed_seconds'=>(string)$limit]);tm($settings['settings']['battle']['turn_seconds']===$limit,'fixed boundaries and step');}
tm(GameSettings::fromArray([])->turnTimeMode()==='fixed','historical fixed default');tm(GameSettings::fromArray(['battle'=>['turn_seconds'=>0]])->turnTimeMode()==='none','historical explicit none');
foreach(['fixed','adaptive'] as $mode){$recorded=tmState(6,2,['turn_time_mode'=>$mode,'turn_fixed_seconds'=>'225']);$recorded->battle=['active'=>'host','turn'=>1];TurnTimer::prepare($recorded,'host');
 $recorded->historyRecorder=new Berserk\Core\BattleHistory\Recorder(41,1,[],[],true);$recorded->historyRecorder->commandStarted($recorded,'host',new Command('turn_ack'));
 $processor=new TurnProcessor($recorded,$e);$processor->afterStartPhase('host');$processor->afterStartPhase('host');$events=$recorded->historyRecorder->batch($recorded)->data()['events'];tm(count(array_filter($events,fn($event)=>$event['type']==='turn.started'))===1,'Recorder first turn once '.$mode);
 $recorded=GameState::fromArray($recorded->toArray());$recorded->historyRecorder=new Berserk\Core\BattleHistory\Recorder(41,2,[],[],true);$recorded->historyRecorder->commandStarted($recorded,'host',new Command('turn_ack'));(new TurnProcessor($recorded,$e))->afterStartPhase('host');tm($recorded->historyRecorder->batch($recorded)->data()['events']===[],'Recorder restore no duplicate '.$mode);
}
echo "Time modes: $checks checks passed (real core, in-memory state).\n";
