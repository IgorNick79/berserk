<?php
declare(strict_types=1);
require __DIR__.'/test_wizard_timer.php';
use Berserk\Core\{GameState,Engine,Command,TurnProcessor,TurnTimer,CardInstance,InformationalWaitManager};
use Berserk\Core\BattleHistory\Recorder;
foreach([0,180] as $seconds){
 $s=new GameState(61,1,2);$s->status='battle';$s->players['host']->side=1;$s->settings->battle['turn_seconds']=$seconds;
 $s->addCard(new CardInstance(instanceId:1,ukid:'runes',owner:'host',zone:'field',row:3,col:2,hp:20,hpMax:20,flags:['row_spell_pending'=>['row'=>3,'action'=>['strike'=>['weak'=>1,'medium'=>1,'strong'=>1]]]]));
 $s->addCard(new CardInstance(instanceId:2,ukid:'enemy',owner:'player',zone:'field',row:4,col:2,hp:20,hpMax:20));
 $now=1000;$engine=new Engine(null,static function()use(&$now){return $now;});$events=[];$serial=1;
 (new TurnProcessor($s,$engine))->startBattle();cw(!empty($s->battle['pending_row_spell_pick']),'real initial choice '.$seconds);
 $run=function(string $side,string $type,array $payload=[],bool $success=true)use(&$s,$engine,&$events,&$serial):void{
  $s=GameState::fromArray($s->toArray());$s->historyRecorder=new Recorder(61,++$serial,[],[],true);
  cw($engine->apply($s,$side,new Command($type,$payload))->success===$success,'command '.$type);
  foreach($s->historyRecorder->batch($s)->data()['events'] as $event)$events[]=$event['type'];
 };
 $run('host','choose_row_spell_targets',['target_ids'=>[2]]);cw(!empty($s->battle['strike']),'nested combat '.$seconds);
 if(!$seconds)cw(isset($s->battle['untimed_start_pending']),'explicit pending start identity');
 $run('host','confirm_strike',['wait_id'=>InformationalWaitManager::token($s)]);cw(count(array_filter($events,fn($e)=>$e==='turn.started'))===0,'first ACK not start');
 $run('player','confirm_strike',['wait_id'=>InformationalWaitManager::token($s)]);cw(count(array_filter($events,fn($e)=>$e==='turn.started'))===1,'final ACK records once '.$seconds);
 $run('player','confirm_strike',['wait_id'=>InformationalWaitManager::token($s)],false);cw(count(array_filter($events,fn($e)=>$e==='turn.started'))===1,'retry not duplicate '.$seconds);
 cw(($seconds===0)===(TurnTimer::snapshot($s,$now)===null),'clock semantics '.$seconds);$sequences[$seconds]=$events;
}
cw($sequences[0]===$sequences[180],'identical nested event order');
echo "Nested start continuation tests passed\n";

foreach([0,180] as $seconds){
 $s=new GameState(62,1,2);$s->status='battle';$s->players['host']->side=1;$s->settings->battle['turn_seconds']=$seconds;$s->nextInstanceId=10;
 $s->addCard(new CardInstance(instanceId:1,ukid:'choices',owner:'host',zone:'field',row:3,col:2,hp:20,hpMax:20,prop:['pre_turn_start'=>['options'=>[['modifiers'=>[]]]],'prophecy'=>1,'turn_start'=>[['type'=>'whip','value'=>1],['type'=>'damage','target'=>'opposite','value'=>1]]]));
 $s->addCard(new CardInstance(instanceId:2,ukid:'deck',owner:'host',zone:'deck',hp:5,hpMax:5));
 $s->addCard(new CardInstance(instanceId:3,ukid:'opponent',owner:'player',zone:'field',row:4,col:2,hp:20,hpMax:20));
 $engine=new Engine(null,static fn()=>1000);(new TurnProcessor($s,$engine))->startBattle();$started=0;
 foreach([['choose_card_option',['option_index'=>0]],['turn_task',['task_id'=>'prophecy']],['turn_sub',['sub_id'=>'prophecy_1']],['close_prophecy',[]],['turn_task',['task_id'=>'turn_start_1_0']],['choose_whip_target',['target_id'=>1]],['turn_ack',[]]] as $index=>[$type,$payload]){
  $s=GameState::fromArray($s->toArray());$s->historyRecorder=new Recorder(62,$index+1,[],[],true);cw($engine->apply($s,'host',new Command($type,$payload))->success,'multi pending '.$type);
  $started+=count(array_filter($s->historyRecorder->batch($s)->data()['events'],fn($e)=>$e['type']==='turn.started'));
  cw($started===($type==='turn_ack'?1:0),'start only after final nested ACK');
 }
}
echo "Multiple pending continuation tests passed\n";

foreach([0,180] as $seconds)foreach(['host','player'] as $first){
 $s=new GameState(71,1,2);$s->status='battle';$s->players[$first]->side=1;$s->settings->battle['turn_seconds']=$seconds;
 foreach([1=>'host',2=>'player'] as $id=>$owner)$s->addCard(new CardInstance(instanceId:$id,ukid:'plain_'.$id,owner:$owner,zone:'field',row:$id===1?3:4,col:2,hp:20,hpMax:20));
 $engine=new Engine(null,static fn()=>1000);(new TurnProcessor($s,$engine))->startBattle();
 for($round=0;$round<4;$round++){$owner=$s->battle['active'];$s->historyRecorder=new Recorder(71,$round+1,[],[],true);cw($engine->apply($s,$owner,new Command('end_turn'))->success,'real end turn '.$seconds.' '.$first);cw($s->battle['active']!==$owner,'owner alternates');cw(count(array_filter($s->historyRecorder->batch($s)->data()['events'],fn($e)=>$e['type']==='turn.started'))===1,'one next-turn record');cw(($seconds===0)===!isset($s->battle['turn_timer']),'real transition preserves clock mode');$s=GameState::fromArray($s->toArray());}
}
echo "Real turn transition tests passed\n";
