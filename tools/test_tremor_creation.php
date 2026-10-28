<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,ZoneManager,Tremor,MemoryLoss,BattleWaitResolver,TurnPhaseProcessor};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
set_error_handler(function($n,$m,$f,$l){if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
$n=0;function ck($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
$fixture=json_decode(file_get_contents(__DIR__.'/../debug/scenarios/tremor-creation.json'),true,512,JSON_THROW_ON_ERROR);
foreach($fixture['placement_cases'] as $case){
 $s=new GameState(-100,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null];$e=new Engine();
 foreach($fixture['cards'] as $i=>$c)$s->addCard(new CardInstance(instanceId:$i+1,ukid:$c['ukid'],owner:$c['owner'],zone:'field',row:$c['row'],col:$c['col'],hp:5,hpMax:5,prop:$c['prop']));
 foreach(['host','player'] as $i=>$owner)$s->addCard(new CardInstance(instanceId:10+$i,ukid:'sentinel',owner:$owner,zone:'field',row:6,col:5,hp:10,hpMax:10));
 $e->forceDeath($s,$s->getCard(1),'destroy');
 ck(isset($s->battle[Tremor::PENDING]),'death creates pending');ck($s->getCard(1)->flags[Tremor::USED]===true,'persistent flag');
 $id=$s->battle[Tremor::PENDING]['id'];
 $e->forceDeath($s,$s->getCard(2),'destroy');ck(count($s->battle[Tremor::PENDING]['queue'])===1,'second owner queued');
 $s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));
 ck(BattleWaitResolver::current($s)['required_players']===['host'],'wait owner');
 $cmd=new Command('choose_tremor_cell',['marker_id'=>$id,'row'=>$case['row'],'col'=>$case['col']]);
 ck(!$e->apply($s,'player',$cmd)->success,'wrong owner');
 ck(!$e->apply($s,'host',new Command('choose_tremor_cell',['marker_id'=>$id,'row'=>7,'col'=>1]))->success,'bounds');
 ck(!$e->apply($s,'host',new Command('end_turn'))->success,'mandatory choice blocks end turn');
 ck($e->apply($s,'host',$cmd)->success,'placement');
 ck(!$e->apply($s,'host',$cmd)->success,'replay cannot consume next pending');
 $next=$s->battle[Tremor::PENDING]['id'];ck($next!==$id,'unique IDs');
 ck($e->apply($s,'player',new Command('choose_tremor_cell',['marker_id'=>$next,'row'=>$case['row'],'col'=>$case['col']]))->success,'second placement');
 $markers=ZoneManager::markersAt($s,$case['row'].'_'.$case['col']);ck(count($markers)===2,'two markers same cell');ck(array_column($markers,'owner')===['host','player'],'owners');
 ck(!isset($s->battle[Tremor::PENDING]),'queue exhausted');
 $card=$s->getCard(1);(new ZoneManager($s))->toField($card,2,2);$card->hp=5;$card->flags=MemoryLoss::persistentFlags($card);
 $e->forceDeath($s,$card,'destroy');ck(!isset($s->battle[Tremor::PENDING]),'repeat death no new marker');
 $s=GameState::fromArray($s->toArray());ck(count(ZoneManager::markersAt($s,$case['row'].'_'.$case['col']))===2,'markers survive reload');
 // A fresh instance, and a suspended native phase continuation.
 $s->addCard(new CardInstance(instanceId:30,ukid:'worm',owner:'host',zone:'field',row:2,col:3,hp:5,hpMax:5,prop:['tremor_on_graveyard'=>true]));
 (new ZoneManager($s))->toGraveyard($s->getCard(30));
 $s->battle['turn_phase']=['phase'=>'start','active_key'=>'host','passive_key'=>'player','side'=>'passive','passive_queue'=>[],'active_queue'=>[['id'=>'test','type'=>'test','label'=>'Test']],'sub'=>null,'pending_ack'=>null];
 (new TurnPhaseProcessor($s,$e))->resume();ck($s->battle[Tremor::PENDING]['resume_turn'],'continuation deferred');
 $id=$s->battle[Tremor::PENDING]['id'];ck($e->apply($s,'host',new Command('choose_tremor_cell',['marker_id'=>$id,'row'=>1,'col'=>1]))->success,'resume placement');
 ck(($s->battle['turn_phase']['pending_ack']['label']??'')==='Test','native phase resumed');
}
echo "PASS $n checks; FAIL 0\n";
