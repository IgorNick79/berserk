<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,ZoneManager,Tremor,TurnPhaseProcessor,TurnTimer,BattleWaitResolver};
use Berserk\Core\Choice\TremorChoice;
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
set_error_handler(function($n,$m,$f,$l){if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
$n=0;function ck($ok,$message){global $n;$n++;if(!$ok)throw new RuntimeException($message);}
function fixture(string $owner,array $from,bool $worm=true):GameState {
 $s=new GameState(-500,1,2);$s->status='battle';$s->battle=['active'=>$s->getOpponentKey($owner),'turn'=>2,'strike'=>null];
 foreach(['host','player'] as $i=>$o)$s->addCard(new CardInstance(instanceId:$i+1,ukid:'live',owner:$o,zone:'field',row:2,col:2,hp:10,hpMax:10));
 if($worm)$s->addCard(new CardInstance(instanceId:3,ukid:'worm',owner:$owner,zone:'graveyard',hp:0,hpMax:5,prop:['tremor_on_graveyard'=>true]));
 foreach([['a',$owner,$from],['b',$owner,$from],['enemy',$s->getOpponentKey($owner),[2,2]]] as [$id,$o,$xy])ZoneManager::addMarker($s,implode('_',$xy),['type'=>'tremor','id'=>$id,'owner'=>$o,'source'=>$o,'row'=>$xy[0],'col'=>$xy[1]]);
 return $s;
}
function command(GameState $s,array $to):Command {$p=$s->battle[Tremor::PENDING];return new Command('choose_tremor_cell',['marker_id'=>$p['id'],'choice_id'=>$p['choice_id'],'row'=>$to[0],'col'=>$to[1]]);}
$data=json_decode(file_get_contents(__DIR__.'/../debug/scenarios/tremor-movement.json'),true,512,JSON_THROW_ON_ERROR);
foreach($data['owners'] as $owner)foreach($data['cases'] as $case){
 $s=fixture($owner,$case['from']);$active=$s->battle['active'];$e=new Engine(clock:static fn()=>1030);
 TurnTimer::prepare($s,$active);TurnTimer::resume($s,1,'start_phase',1000);
 (new TurnPhaseProcessor($s,$e))->beginStartPhase($active);
 ck(($s->battle[Tremor::PENDING]['id']??'')==='a','native passive task');
 ck(BattleWaitResolver::current($s)['required_players']===[$owner],'passive owner');
 TurnTimer::settle($s,1030);TurnTimer::reconcileWaiting($s,1030);
 ck($s->battle['turn_timer']['status']==='paused','timer paused for opponent');
 ck(TurnTimer::snapshot($s,1100)['remaining_seconds']===$s->battle['turn_timer']['remaining_seconds'],'waiting consumes no extra time');
 $spec=(new TremorChoice())->spec($s,$owner,[],'?game=1',$owner);
 ck(count($spec->buttons)===($case['from']===[1,1]?4:9),'only legal UI destinations');
 $cmd=command($s,$case['to']);ck(!$e->apply($s,$active,$cmd)->success,'foreign owner rejected');
 $bad=clone $cmd;$bad->payload['marker_id']='enemy';ck(!$e->apply($s,$owner,$bad)->success,'foreign marker rejected');
 $s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));
 $before=$s->cell_markers;$result=$e->apply($s,$owner,$cmd);ck($result->success===$case['ok'],'destination validation '.$case['name']);
 if(!$case['ok']){ck($s->cell_markers===$before,'invalid command no marker mutation');$cmd=command($s,$case['from']);ck($e->apply($s,$owner,$cmd)->success,'skip after invalid');}
 ck($s->battle[Tremor::PENDING]['id']==='b','next marker');
 $expected=$case['ok']?$case['to']:$case['from'];$actual=array_values(array_filter(Tremor::ownedMarkers($s,$owner),fn($m)=>$m['id']==='a'))[0];
 ck([$actual['row'],$actual['col']]===$expected && $actual['cell']===implode('_',$expected),'authoritative destination and cell key');
 ck(!$e->apply($s,$owner,$cmd)->success,'replay rejected');
 ck($e->apply($s,$owner,command($s,[2,2]))->success, 'occupied and stacked destination');
 // If [2,2] is too far, finish by staying.
 if(isset($s->battle[Tremor::PENDING]))ck($e->apply($s,$owner,command($s,$case['from']))->success,'stay');
 ck(!isset($s->battle[Tremor::PENDING]),'movement queue complete');ck(!isset($s->battle['turn_phase']),'phase continued');
 ck(count(Tremor::ownedMarkers($s,$owner))===2,'marker count preserved');
 ck(Tremor::ownedMarkers($s,$active)[0]['row']===2,'enemy unchanged');
 Tremor::startMoves($s,$owner);ck(!isset($s->battle[Tremor::PENDING]),'same start cannot repeat');
 $s->battle['turn']++;(new TurnPhaseProcessor($s,$e))->beginStartPhase($active);
 ck(isset($s->battle[Tremor::PENDING]),'next start offers marker again');
 ck(!$e->apply($s,$owner,$cmd)->success,'old command rejected in new turn');
}
$absent=$data['without_worm'];$s=fixture($absent['owner'],$absent['from'],$absent['worm']);$e=new Engine();(new TurnPhaseProcessor($s,$e))->beginStartPhase('player');
ck(!isset($s->battle[Tremor::PENDING]),'no graveyard worm no movement');ck(count(Tremor::ownedMarkers($s,'host'))===2,'markers persist without worm');
$leaving=$data['worm_leaves_during_pending'];$s=fixture($leaving['owner'],$leaving['from']);(new TurnPhaseProcessor($s,$e))->beginStartPhase('player');$s->getCard(3)->zone='exile';
ck(!$e->apply($s,'host',command($s,$leaving['rejected_destination']))->success,'revalidate graveyard');ck($e->apply($s,'host',command($s,[3,3]))->success,'can skip when worm gone');
echo "PASS $n checks; FAIL 0\n";
