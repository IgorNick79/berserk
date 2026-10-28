<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,ZoneManager,Tremor,TurnProcessor,WormIncarnation,BattleWaitResolver,TurnTimer};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
set_error_handler(function($n,$m,$f,$l){if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
$n=0;function ck($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
$d=json_decode(file_get_contents(__DIR__.'/../debug/scenarios/tremor-occupied.json'),true,512,JSON_THROW_ON_ERROR);
foreach($d['cases'] as $case){
 $s=new GameState(-410,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null];$e=new Engine(clock:static fn()=>1030);
 TurnTimer::prepare($s,'host');TurnTimer::resume($s,1,'start_phase',1000);
 $s->addCard(new CardInstance(instanceId:1,ukid:'worm',owner:'host',zone:'graveyard',hp:0,hpMax:5,prop:['incarnation'=>['turns'=>4,'tremor'=>true]],flags:['incarnation_ready'=>true]));
 foreach(['host','player'] as $i=>$o)if(empty($case['no_'.$o.'_sentinel']))$s->addCard(new CardInstance(instanceId:10+$i,ukid:'sentinel',owner:$o,zone:'field',row:6,col:1+$i,hp:20,hpMax:30));
 foreach($case['owners'] as $i=>$o){$prop=[];
 if(!empty($case['choices']))$prop=['on_death'=>[['type'=>'damage','target'=>'choice','value'=>1],['type'=>'damage','target'=>'choice','value'=>1]]];
 if(!empty($case['heal']))$prop=['on_death'=>[['type'=>'heal','target'=>'choice','value'=>2]]];
 if(!empty($case['nested'])&&$i===0)$prop['on_death'][0]['value']=10;
 $s->addCard(new CardInstance(instanceId:2+$i,ukid:'victim',owner:$o,zone:'field',row:3,col:3,hp:5,hpMax:5,prop:$prop));}
 if(!empty($case['nested']))$s->addCard(new CardInstance(instanceId:5,ukid:'nested',owner:'player',zone:'field',row:4,col:4,hp:2,hpMax:2,prop:['on_death'=>[['type'=>'damage','target'=>'choice','value'=>1]]]));
 if(!empty($case['heal']))$s->addCard(new CardInstance(instanceId:6,ukid:'noble',owner:'host',zone:'field',row:2,col:2,hp:5,hpMax:10,prop:['first_card_heal'=>['threshold'=>8]]));
 if(!empty($case['any']))$s->getCard(10)->prop=['on_any_death'=>['side'=>'enemy','optional'=>true,'effects'=>[['type'=>'grant_modifier','stat'=>'move','value'=>1]]]];
 foreach(['b','a'] as $i=>$id)ZoneManager::addMarker($s,'3_3',['type'=>'tremor','id'=>$id,'owner'=>$i?'player':'host']);
 if(!empty($case['second_worm']))$s->addCard(new CardInstance(instanceId:9,ukid:'worm2',owner:'host',zone:'graveyard',hp:0,hpMax:5,prop:['incarnation'=>['turns'=>4,'tremor'=>true]],flags:['incarnation_ready'=>true]));
 (new TurnProcessor($s,$e))->processIncarnation('host');
 $s->battle['turn_phase']=['phase'=>'start','active_key'=>'host','passive_key'=>'player','side'=>'active','active_queue'=>[],'passive_queue'=>[],'sub'=>null,'pending_ack'=>null];
 $start=new Command('choose_incarnation_cell',['incarnation_id'=>1,'row'=>3,'col'=>3]);
 ck($e->apply($s,'host',$start)->success,$case['name'].' start');
 if(!empty($case['resign'])){ck($e->apply($s,'host',new Command('resign'))->success,'resign accepted');ck($s->status==='game_over'&&$s->winner==='player','resign immediate');continue;}
 $seen=[];$healed=false;$any=false;
 for($step=0;WormIncarnation::operation($s)&&$step<30;$step++){
  $s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));
  ck($s->getCard(1)->zone==='graveyard','worm waits');ck($s->status==='battle'&&$s->winner===null,'elimination deferred across reload');ck(count(ZoneManager::markersAt($s,'3_3'))===2,'marker reserved');
  ck(!$e->apply($s,'host',$start)->success,'start replay rejected');
  $pc=$s->battle['strike']['pending_choice']??null;
  if($pc){$source=$s->getCard($pc['died_id']);ck($source->dying&&$source->zone==='field','dying kept');
   $seen[]=$source->instanceId;$target=$source->owner==='host'?11:10;
   if(!empty($case['nested'])&&$source->instanceId===2&&count($seen)===1)$target=5;
   $cmd=new Command('choose_worm_death',['choice_id'=>$pc['choice_id'],'target_id'=>$target]);$owner=$source->owner;
   ck(BattleWaitResolver::current($s)['required_players']===[$owner],'choice owner wait');
   TurnTimer::reconcileWaiting($s,1030);if($owner==='player'){ck($s->battle['turn_timer']['status']==='paused','opponent death choice pauses timer');ck(TurnTimer::snapshot($s,1100)['remaining_seconds']===$s->battle['turn_timer']['remaining_seconds'],'no time spent waiting');}
  }elseif(!empty($s->battle['pending_card_heal'])){$p=$s->battle['pending_card_heal'];$owner=$p['owner'];$cmd=new Command('choose_card_heal',['heal_token'=>$p['token'],'apply'=>'yes']);$healed=true;
  }elseif(!empty($s->battle['pending_any_death'])){$p=$s->battle['pending_any_death'][0];$owner=$s->getCard($p['source_id'])->owner;$cmd=new Command('choose_any_death_target',['target_id'=>1]);$any=true;
  }else throw new RuntimeException('Invisible continuation '.json_encode($s->battle));
  ck($e->apply($s,$owner,$cmd)->success,'child command');
  if($pc)ck(!$e->apply($s,$owner,$cmd)->success,'death replay rejected');
 }
 ck(!WormIncarnation::operation($s),'operation complete');ck($s->getCard(1)->zone==='field'&&!$s->getCard(1)->closed,'worm arrived');
 foreach($case['owners'] as $i=>$o)ck($s->getCard(2+$i)->zone==='graveyard','victim buried');
 ck(array_column(ZoneManager::markersAt($s,'3_3'),'id')===['b'],'one deterministic marker consumed');
 if(!empty($case['nested']))ck($seen===[2,5,2,3,3],'nested death before outer remaining effects and next victim');
 if(!empty($case['heal']))ck($healed&&$s->getCard(10)->hp===24,'CardHealing nested decision');
 if(!empty($case['any']))ck($any&&count($s->getCard(10)->modifiers)===1,'any death choice applied');
 if(!empty($case['second_worm'])){ck($s->battle['pending_incarnation']['current']===9,'queue continues to second worm');ck($e->apply($s,'host',new Command('choose_incarnation_cell',['incarnation_id'=>9,'row'=>3,'col'=>3]))->success,'second occupied incarnation');ck($s->getCard(1)->zone==='exile'&&$s->getCard(9)->zone==='field','native incarnated exile rule');ck(!ZoneManager::markersAt($s,'3_3'),'last marker consumed');}
 ck(empty($case['no_player_sentinel'])?$s->status==='battle':$s->winner==='host','final elimination check');
}
echo "PASS $n checks; FAIL 0\n";
