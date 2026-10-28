<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,CardRoll};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
set_error_handler(function($n,$m,$f,$l){if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
$n=0;function ck($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
$d=json_decode(file_get_contents(__DIR__.'/../debug/scenarios/abyss-movement.json'),true);
foreach($d['cases'] as $case)foreach(['host','player'] as $owner)foreach([false,true] as $lethal){
 $s=new GameState(-600,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null];$e=new Engine();
 $s->addCard(new CardInstance(instanceId:1,ukid:'abyss',owner:'host',zone:'field',row:3,col:2,hp:10,hpMax:10,move:2,moveMax:2,prop:['zoz'=>true,'adjacent_move_wound_cheap'=>true,'next_six_on_enemy_death_or_ally_incarnation'=>true]));
 $s->addCard(new CardInstance(instanceId:2,ukid:'neighbor',owner:$owner,zone:'field',row:4,col:3,hp:$lethal?1:5,hpMax:5,price:$case['price'],type:$case['type'],prop:$lethal?['on_death'=>[['type'=>'damage','target'=>'choice','value'=>1]]]:[]));
 $s->addCard(new CardInstance(instanceId:3,ukid:'sentinel',owner:$owner==='host'?'player':'host',zone:'field',row:6,col:5,hp:10,hpMax:10));
 $cmd=new Command('move',['card_id'=>1,'row'=>3,'col'=>3]);ck($e->apply($s,'host',$cmd)->success,'native move');
 ck(!$e->apply($s,'host',$cmd)->success,'move replay rejected');
 if($lethal&&$case['wounded']) {
  ck(isset($s->battle['strike']['pending_choice']),'death choice');ck($s->getCard(2)->dying,'dying retained');
  $s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));$pc=$s->battle['strike']['pending_choice'];
  $target=$owner==='host'?3:1;$choice=new Command('choose_abyss_death',['choice_id'=>$pc['choice_id'],'target_id'=>$target]);
  ck($e->apply($s,$owner,$choice)->success,'child choice');ck(!$e->apply($s,$owner,$choice)->success,'child replay rejected');
  ck($s->getCard(2)->zone==='graveyard','death finalized');
  if($owner==='player')ck(!empty($s->getCard(1)->flags[CardRoll::READY]),'movement death arms six');
 }else ck($s->getCard(2)->hp===($lethal?1:5)-($case['wounded']?1:0),'price and type filter');
 if($case['wounded']) {
  $report=end($s->battle['abyss_movement_results']);ck($report['targets'][0]['damage']===1,'actual wound recorded');

  foreach(!empty($s->battle['abyss_movement']['result'])?['host','player']:[] as $viewer){
   $panel=(new \Berserk\Core\Choice\AbyssMovementChoice())->spec($s,$viewer,['abyss'=>['name'=>'Повелитель бездны'],'neighbor'=>['name'=>'Гном']],'?game=1',$viewer);
   $html=\Berserk\View\Ui\Panel::render($panel);
   ck(str_contains($html,($owner===$viewer?'Своя карта':'Карта противника').' — Гном #2: −1 ❤️ HP'),'visible ownership and wounds');
  }
 }
 if(!empty($s->battle['abyss_movement']['result'])){
  $r=$s->battle['abyss_movement']['result'];
  $spec=(new \Berserk\Core\Choice\AbyssMovementChoice())->spec($s,'host',[],'?game=1','host');
  ck($spec->buttons[0]['label']==='Продолжить','result requires confirmation');
  $s=GameState::fromArray($s->toArray());
  $ack=new Command('ack_abyss_movement',['operation_id'=>$r['operation_id']]);
  ck(!$e->apply($s,'player',$ack)->success,'opponent cannot acknowledge owner result');
  ck($e->apply($s,'host',$ack)->success,'result acknowledged');
  ck(!$e->apply($s,'host',$ack)->success,'ack replay rejected');
  ck(empty($s->battle['abyss_movement_results'][$r['operation_id']]),'message removed after ACK');
 }
 ck(empty($s->battle['abyss_movement']),'continuation completed');ck(empty($s->battle['strike']),'no combat window');
}
// Native movement-effect entry point used by forced moves and teleports.
foreach([[0,0],[1,0],[1,1],[2,0]] as [$dr,$dc]) {
 $s=new GameState(-601,1,2);$s->status='battle';$s->battle=['active'=>'player','turn'=>2,'strike'=>['kind'=>'parent','state'=>'results','marker'=>'keep']];$e=new Engine();
 $s->addCard(new CardInstance(instanceId:1,ukid:'abyss',owner:'host',zone:'field',row:2+$dr,col:2+$dc,hp:10,hpMax:10,prop:['adjacent_move_wound_cheap'=>true]));
 $s->addCard(new CardInstance(instanceId:2,ukid:'neighbor',owner:'player',zone:'field',row:3+$dr,col:2+$dc,hp:5,hpMax:5,price:4));
 $parent=$s->battle['strike'];
 (new \Berserk\Core\Movement\MovementEffectResolver($s,$e))->applyForcedPositionChange(new \Berserk\Core\Movement\MovementContext(
  card:$s->getCard(1),playerKey:'player',fromRow:2,fromCol:2,toRow:2+$dr,toCol:2+$dc,movementType:'forced_move'));
 (new \Berserk\Core\AbyssMovement($s,$e))->advance();
 ck($s->getCard(2)->hp===(max($dr,$dc)===1?4:5),'forced neighbor distance');
 ck($s->battle['strike']===$parent,'originating result preserved');
}
echo "PASS $n checks; FAIL 0\n";
