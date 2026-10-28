<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,ZoneManager,Tremor,TurnProcessor};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
set_error_handler(function($n,$m,$f,$l){if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
$n=0;function ck($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
$d=json_decode(file_get_contents(__DIR__.'/../debug/scenarios/tremor-incarnation.json'),true,512,JSON_THROW_ON_ERROR);
foreach($d['cases'] as $case){
 $s=new GameState(-401,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null];$e=new Engine();
 foreach([1,2] as $id){$s->addCard(new CardInstance(instanceId:$id,ukid:'worm',owner:'host',zone:'field',row:2,col:$id,hp:5,hpMax:5,prop:$d['prop'],flags:[Tremor::USED=>true]));(new ZoneManager($s))->toGraveyard($s->getCard($id));}
 foreach(['host','player'] as $i=>$owner)$s->addCard(new CardInstance(instanceId:10+$i,ukid:'alive',owner:$owner,zone:'field',row:6,col:$i+1,hp:10,hpMax:10));
 if($case['full_back'])for($c=1;$c<=5;$c++)$s->addCard(new CardInstance(instanceId:20+$c,ukid:'block',owner:'host',zone:'field',row:1,col:$c,hp:10,hpMax:10));
 foreach([['b','3_3'],['a','3_3'],['other','4_4'],['occupied','6_1']] as [$id,$cell]){[$r,$c]=array_map('intval',explode('_',$cell));ZoneManager::addMarker($s,$cell,['type'=>'tremor','id'=>$id,'owner'=>$case['marker_owner'],'row'=>$r,'col'=>$c]);}
 // Stage 4B permits occupants; a blocking bonfire still tests an illegal destination.
 ZoneManager::addMarker($s,'6_1',['type'=>'bonfire']);
 for($i=0;$i<3;$i++){(new TurnProcessor($s,$e))->processIncarnation('host');ck(empty($s->battle['pending_incarnation']),'not ready before four tokens');}
 ck($s->getCard(1)->markers['incarnation']['value']===3,'native token count');
 (new TurnProcessor($s,$e))->processIncarnation('host');ck($s->battle['pending_incarnation']['queue']===[1,2],'two worms ready including full back');
 $s->battle['turn_phase']=['phase'=>'start','active_key'=>'host','passive_key'=>'player','side'=>'active','active_queue'=>[],'passive_queue'=>[],'sub'=>null,'pending_ack'=>null];
 $cmd=new Command('choose_incarnation_cell',['incarnation_id'=>1,'row'=>3,'col'=>3]);
 $before=$s->cell_markers;
 foreach([[2,5],[6,1],[7,3]] as [$r,$c])ck(!$e->apply($s,'host',new Command('choose_incarnation_cell',['incarnation_id'=>1,'row'=>$r,'col'=>$c]))->success,'reject unmarked blocked outside');
 ck($s->cell_markers===$before,'rejections preserve markers');
 ck(!$e->apply($s,'player',$cmd)->success,'owner validation');
 $targets=\Berserk\View\Ui\CellMarkerPicker::targets($s,'host','?game=1');ck(array_keys($targets)===['3_3','4_4'],'only valid cells highlighted');
 $s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));
 ck($e->apply($s,'host',$cmd)->success,'incarnation succeeds');
 ck($s->getCard(1)->zone==='field'&&!$s->getCard(1)->closed&&$s->getCard(1)->hp===5,'open native arrival');
 ck(array_column(ZoneManager::markersAt($s,'3_3'),'id')===['b'],'one stable ID consumed');
 ck(count(ZoneManager::markersAt($s,'4_4'))===1,'other cell unchanged');
 ck($s->battle['pending_incarnation']['current']===2,'next worm');ck(!$e->apply($s,'host',$cmd)->success,'repeat cannot incarnate next worm');
 ck($e->apply($s,'host',new Command('choose_incarnation_cell',['incarnation_id'=>2,'row'=>4,'col'=>4]))->success,'second worm');
 ck(empty($s->battle['pending_incarnation'])&&empty($s->battle['turn_phase']),'queue and phase finish');
 ck($s->getCard(1)->flags[Tremor::USED]===true,'once flag retained');
 ck(GameState::fromArray($s->toArray())->cell_markers===$s->cell_markers,'persist final markers');
}
// Last free tunnel must not leave the second ready worm blocking the phase.
$s=new GameState(-403,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null];$e=new Engine();
foreach([1,2] as $id)$s->addCard(new CardInstance(instanceId:$id,ukid:'worm',owner:'host',zone:'graveyard',hp:0,hpMax:5,prop:$d['prop'],flags:['incarnation_ready'=>true]));
ZoneManager::addMarker($s,'3_3',['type'=>'tremor','id'=>'last','owner'=>'player']);
(new TurnProcessor($s,$e))->processIncarnation('host');
$s->battle['turn_phase']=['phase'=>'start','active_key'=>'host','passive_key'=>'player','side'=>'active','active_queue'=>[],'passive_queue'=>[],'sub'=>null,'pending_ack'=>null];
$s->getCard(1)->flags['incarnation_ready']=false;
ck(!$e->apply($s,'host',new Command('choose_incarnation_cell',['incarnation_id'=>1,'row'=>3,'col'=>3]))->success,'server rechecks readiness');
$s->getCard(1)->flags['incarnation_ready']=true;
ck($e->apply($s,'host',new Command('choose_incarnation_cell',['incarnation_id'=>1,'row'=>3,'col'=>3]))->success,'last tunnel used');
ck(empty($s->battle['pending_incarnation'])&&$s->getCard(2)->flags['incarnation_ready']&&$s->getCard(2)->zone==='graveyard','second worm stays ready without deadlock');
(new TurnProcessor($s,$e))->processIncarnation('host');ck(empty($s->battle['pending_incarnation']),'no marker no queue');
// Ordinary incarnation remains in its back row and closed by default.
$s=new GameState(-402,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>1,'strike'=>null];$e=new Engine();
$s->addCard(new CardInstance(instanceId:1,ukid:'ordinary',owner:'host',zone:'field',row:2,col:2,hp:5,hpMax:5,prop:['incarnation'=>4]));(new ZoneManager($s))->toGraveyard($s->getCard(1));
for($i=0;$i<4;$i++)(new TurnProcessor($s,$e))->processIncarnation('host');
ck(!$e->apply($s,'host',new Command('choose_incarnation_cell',['row'=>3,'col'=>3]))->success,'ordinary back row restriction');
$s->battle['turn_phase']=['phase'=>'start','active_key'=>'host','passive_key'=>'player','side'=>'active','active_queue'=>[],'passive_queue'=>[],'sub'=>null,'pending_ack'=>null];
ck($e->apply($s,'host',new Command('choose_incarnation_cell',['row'=>1,'col'=>1]))->success,'ordinary success');ck($s->getCard(1)->closed,'ordinary closed');
echo "PASS $n checks; FAIL 0\n";
