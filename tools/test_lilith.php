<?php
declare(strict_types=1);
require __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,CardInstance,Engine,Command,GraveRitual,IncarnationTokenProcessor,CardStats,PairedRanged,InformationalWaitManager};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$n=0;function lc($v,$m){global $n;++$n;if(!$v)throw new RuntimeException($m);}
function ls():GameState {$s=new GameState(-970,1,2);$s->status='battle';$s->battle=['active'=>'host','turn'=>2,'strike'=>null,'hidden_row_revealed'=>true];foreach([[1,'host',3,3],[2,'player',4,3],[3,'host',2,2]]as[$id,$o,$r,$c])$s->addCard(new CardInstance(instanceId:$id,ukid:'test'.$id,owner:$o,zone:'field',row:$r,col:$c,hp:20,hpMax:20,strikeWeak:1,strikeMedium:2,strikeStrong:3));return $s;}
function ritualAck(GameState $s,Engine $e):void {if(!empty($s->battle['pending_grave_ritual']['result'])){$p=$s->battle['pending_grave_ritual'];lc($e->apply($s,$p['owner'],new Command('choose_grave_ritual',['operation_id'=>$p['id'],'ack'=>1]))->success,'ritual result acknowledgement');}}
$cfg=['type'=>'grave_ritual','demon_class'=>'Демон','undead_class'=>'Нежить','strong'=>1,'ova'=>1];
$scenarios=json_decode(file_get_contents(__DIR__.'/../debug/scenarios/lilith.json'),true);
foreach($scenarios['classes'] as $classes){$s=ls();$e=new Engine();$c=new CardInstance(instanceId:10,ukid:'deck',owner:'host',zone:'deck',classes:$classes);$s->addCard($c);$s->getCard(3)->classes=['Демон'];$r=new GraveRitual($s,$e);$r->start($s->getCard(1),$cfg);lc($c->zone==='graveyard','milled through zone manager');
 lc(GraveRitual::bonus($s,$s->getCard(3),'ova')===(in_array('Нежить',$classes,true)?1:0),'independent undead buff');
 if(count($classes)===2){lc($c->markers['incarnation']['value']===1,'newly milled dual class token');lc(!isset($c->markers['incarnation']['threshold']),'no artificial threshold');}
 ritualAck($s,$e);$r->start($s->getCard(1),$cfg);lc(count($s->battle['grave_ritual_done'])===1,'ritual idempotent');
}
foreach($scenarios['incarnation'] as $inc){$s=ls();$e=new Engine();$c=$s->getCard(3);$c->zone='graveyard';if($inc!==null)$c->prop=['incarnation'=>$inc];(new IncarnationTokenProcessor($s,$e))->grant($c);lc($c->markers['incarnation']['value']===1,'real token');lc(isset($c->markers['incarnation']['threshold'])===($inc!==null),'threshold only with ability');}
$s=ls();$s->getCard(2)->row=6;$s->getCard(3)->row=1;$e=new Engine();$action=['type'=>'paired_ranged','effects'=>[['type'=>'discharge','value'=>1],['type'=>'throw','value'=>1]]];$s->getCard(1)->prop=['actions'=>[$action+['key'=>'pair']]];
$open=$e->apply($s,'host',new Command('action',['card_id'=>1,'action_key'=>'pair']));lc($open->success,'real action opens: '.$open->error);
foreach([2,3] as $id){$p=$s->battle[PairedRanged::PENDING];$cmd=new Command('choose_paired_ranged',['operation_id'=>$p['id'],'stage'=>$p['stage'],'target_id'=>$id]);lc($e->apply($s,'host',$cmd)->success,'select distinct target');lc(!$e->apply($s,'host',$cmd)->success,'replay rejected');}
for($i=0;$i<8&&!empty($s->battle['strike']);$i++){$owner=in_array('host',$s->battle['strike']['confirmed']??[],true)?'player':'host';$s=GameState::fromArray($s->toArray());lc($e->apply($s,$owner,new Command('confirm_strike',['wait_id'=>InformationalWaitManager::token($s)]))->success,'ACK child');}
lc($s->getCard(2)->hp===19&&$s->getCard(3)->hp===19,'both native effects');lc($s->getCard(1)->closed,'one tap');lc(empty($s->battle['paired_ranged']),'operation completed');
// A lethal first effect pauses the second effect through a serialized death choice.
foreach([1,25] as $deathDamage){
$s=ls();$s->getCard(2)->row=6;$s->getCard(3)->row=1;$s->getCard(2)->hp=1;$s->getCard(2)->prop=['on_death'=>[['type'=>'damage','target'=>'choice','value'=>$deathDamage]]];$s->getCard(1)->prop=['actions'=>[$action+['key'=>'pair']]];$e=new Engine();
lc($e->apply($s,'host',new Command('action',['card_id'=>1,'action_key'=>'pair']))->success,'lethal pair start');
foreach([2,3] as $id){$p=$s->battle[PairedRanged::PENDING];lc($e->apply($s,'host',new Command('choose_paired_ranged',['operation_id'=>$p['id'],'stage'=>$p['stage'],'target_id'=>$id]))->success,'lethal select');}
// Native result confirmations may precede the queued death choice.
for($i=0;$i<12&&!empty($s->battle['paired_ranged']);$i++){
 $s=GameState::fromArray(json_decode(json_encode($s->toArray()),true));
 if(!empty($s->battle['strike']['pending_choice'])){$p=$s->battle['strike']['pending_choice'];lc($s->getCard(3)->hp===20,'throw waits for death choice');$cmd=new Command('choose_paired_death',['choice_id'=>$p['choice_id'],'target_id'=>3]);$owner='player';}
 elseif(!empty($s->battle['strike'])){$owner=in_array('host',$s->battle['strike']['confirmed']??[],true)?'player':'host';$cmd=new Command('confirm_strike',['wait_id'=>InformationalWaitManager::token($s)]);}
 else {(new PairedRanged($s,$e))->advance();continue;}
 $r=$e->apply($s,$owner,$cmd);lc($r->success,'nested continuation: '.$r->error);
}
lc(empty($s->battle['paired_ranged']),'nested pair completes');lc($deathDamage===25?$s->getCard(3)->zone==='graveyard':$s->getCard(3)->hp===18,'throw resumes or skips disappeared target');
}

// Full native end-turn -> own start ritual with both class branches.
$s=ls();$s->battle['active']='player';$s->getCard(1)->prop=['turn_start'=>[$cfg]];$s->getCard(3)->classes=['Демон'];$c=new CardInstance(instanceId:10,ukid:'dual',owner:'host',zone:'deck',classes:['Демон','Нежить']);$s->addCard($c);$e=new Engine();
lc($e->apply($s,'player',new Command('end_turn'))->success,'native turn lifecycle');
for($i=0;$i<12&&!empty($s->battle['turn_phase']['pending_ack']);$i++)lc($e->apply($s,'host',new Command('turn_ack'))->success,'native phase ack');
ritualAck($s,$e);lc($s->getCard(10)->zone==='graveyard','native start mills');lc($s->getCard(10)->markers['incarnation']['value']===1,'native start dual token');lc(CardStats::getOva($s,$s->getCard(3))===1,'native start ova');lc(CardStats::getStrikeValue($s,$s->getCard(3),$s->getCard(2),'strong')===4,'native strong bonus');
$s->addCard(new CardInstance(instanceId:11,ukid:'late',owner:'host',zone:'field',row:2,col:1,hp:5,hpMax:5,classes:['Демон']));lc(CardStats::getOva($s,$s->getCard(11))===1,'late arriving demon');
lc($e->apply($s,'host',new Command('end_turn'))->success,'buff owner ends turn');lc(GraveRitual::bonus($s,$s->getCard(3),'ova')===0,'bonus expires at own end');
// Tremor is resolved before the ritual's graveyard choice, neither continuation is lost.
$s=ls();$s->battle['active']='player';$s->getCard(1)->prop=['turn_start'=>[$cfg]];
$s->addCard(new CardInstance(instanceId:10,ukid:'worm',owner:'host',zone:'deck',classes:['Демон','Нежить'],prop:['tremor_on_graveyard'=>true]));
$s->addCard(new CardInstance(instanceId:11,ukid:'undead',owner:'host',zone:'graveyard',classes:['Нежить']));$e=new Engine();lc($e->apply($s,'player',new Command('end_turn'))->success,'mixed start');
for($i=0;$i<10&&!empty($s->battle['turn_phase']['pending_ack']);$i++)lc($e->apply($s,'host',new Command('turn_ack'))->success,'mixed ack');
lc(!empty($s->battle['pending_tremor'])&&!empty($s->battle['grave_ritual']),'tremor owns first choice');$s=GameState::fromArray($s->toArray());$p=$s->battle['pending_tremor'];
lc($e->apply($s,'host',new Command('choose_tremor_cell',['marker_id'=>$p['id'],'row'=>2,'col'=>2]))->success,'tremor placement');lc(!empty($s->battle['pending_grave_ritual']),'ritual choice resumes');
$p=$s->battle['pending_grave_ritual'];lc($e->apply($s,'host',new Command('choose_grave_ritual',['operation_id'=>$p['id'],'target_id'=>11]))->success,'ritual selection');ritualAck($s,$e);lc(empty($s->battle['grave_ritual'])&&empty($s->battle['pending_tremor']),'both roots completed');
// Cancellation at either selection stage is free and replay safe.
foreach([0,1] as $stage){$s=ls();$s->getCard(2)->row=6;$s->getCard(3)->row=1;$e=new Engine();$s->getCard(1)->prop=['actions'=>[$action+['key'=>'pair']]];
 lc($e->apply($s,'host',new Command('action',['card_id'=>1,'action_key'=>'pair']))->success,'cancel start');
 if($stage){$p=$s->battle[PairedRanged::PENDING];lc($e->apply($s,'host',new Command('choose_paired_ranged',['operation_id'=>$p['id'],'stage'=>0,'target_id'=>2]))->success,'cancel second stage');}
 $p=$s->battle[PairedRanged::PENDING];$cmd=new Command('choose_paired_ranged',['operation_id'=>$p['id'],'stage'=>$p['stage'],'cancel'=>1]);lc($e->apply($s,'host',$cmd)->success,'cancel');lc(!$e->apply($s,'host',$cmd)->success,'cancel replay');lc(!$s->getCard(1)->closed&&$s->getCard(2)->hp===20&&$s->getCard(3)->hp===20&&empty($s->battle[PairedRanged::PENDING]),'cancel no cost');}
// Pair legality considers each effect's defenses, not just the number of cards.
foreach([['zot','zot',false],['zor','zor',false],['zoda','zoda',false],['zoda',null,false],['zot','zor',true],[null,null,true]] as [$a,$b,$allowed]){
 $s=ls();$s->getCard(2)->row=6;$s->getCard(3)->row=1;if($a)$s->getCard(2)->prop=[$a=>true];if($b)$s->getCard(3)->prop=[$b=>true];$s->getCard(1)->prop=['actions'=>[$action+['key'=>'pair']]];$e=new Engine();
 $r=$e->apply($s,'host',new Command('action',['card_id'=>1,'action_key'=>'pair']));lc($r->success===$allowed,'protected pair availability');lc(!$s->getCard(1)->closed,'availability has no tap cost');
}
echo "PASS $n checks; FAIL 0\n";
