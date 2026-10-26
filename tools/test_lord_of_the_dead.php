<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,CardInstance,Command,EffectiveFeatures,Engine,GameState,InstantProcessor,TurnPhaseProcessor,TurnProcessor,ZoneManager};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
set_error_handler(static function(int $n,string $m,string $f,int $l):bool {if(error_reporting()&$n)throw new ErrorException($m,0,$n,$f,$l);return false;});
$lordChecks=0;
function ldCheck(bool $ok,string $message):void {global $lordChecks;++$lordChecks;if(!$ok)throw new RuntimeException($message);}
function ldProp():array {return json_decode(file_get_contents(__DIR__.'/../docs/lord-of-the-dead.prop.json'),true,512,JSON_THROW_ON_ERROR);}
function ldCard(int $id,string $owner='host',array $prop=[]):CardInstance {
 return new CardInstance(instanceId:$id,ukid:'ld_arbitrary_'.$id,owner:$owner,zone:'field',row:3,col:$id,hp:20,hpMax:20,closed:true,
 move:2,moveMax:2,strikeWeak:1,strikeMedium:2,strikeStrong:3,prop:$prop);
}
function ldState(string $owner='host',string $incType='creature'):GameState {
 $s=new GameState(-261027,101,202);$s->status='battle';$s->firstPlayer='host';$s->players['host']->side=1;$s->players['player']->side=2;
 $s->battle=['active'=>$owner,'turn'=>5,'hidden_row_revealed'=>true,'strike'=>null];
 $s->addCard(ldCard(1,$owner,ldProp()));$s->addCard(ldCard(2,$s->getOpponentKey($owner)));
 $inc=ldCard(3,$owner,['incarnation'=>['turns'=>1,'open'=>true,'type'=>$incType]]);
 (new ZoneManager($s))->toGraveyard($inc);$s->addCard($inc);return $s;
}
function ldReload(GameState $s):GameState {return GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));}
function ldCommand(GameState $s,string $type,array $args=[],?string $owner=null):Berserk\Core\Result {
 return(new Engine())->apply($s,$owner??$s->battle['active'],new Command($type,$args));
}
function ldBegin(GameState $s):void {(new TurnProcessor($s,new Engine()))->processIncarnation($s->battle['active']);}
function ldChoose(GameState $s,int $col=1):Berserk\Core\Result {return ldCommand($s,'choose_incarnation_cell',['row'=>$s->battle['active']==='host'?1:6,'col'=>$col]);}
function ldBonuses(CardInstance $c):array {return array_values(array_filter($c->modifiers,static fn(array $m):bool=>($m['stack_key']??'')==='ally_incarnation_move'));}
function ldDrain(GameState $s):void {
 for($i=0;$i<30&&!empty($s->battle['turn_phase']);++$i){$tp=$s->battle['turn_phase'];$owner=$tp[$tp['side'].'_key'];
  if(!empty($tp['pending_ack']))$r=ldCommand($s,'turn_ack',[],$owner);
  elseif(!empty($tp['sub']))$r=ldCommand($s,'turn_sub_close',[],$owner);
  else {$queue=$tp[$tp['side'].'_queue'];if(!$queue)throw new RuntimeException('Unexpected empty phase');$r=ldCommand($s,'turn_task',['task_id'=>$queue[0]['id']],$owner);}
  ldCheck($r->success,'Drain phase: '.$r->error);
 }ldCheck(empty($s->battle['turn_phase']),'Phase completes');
}
function ldEnd(GameState $s):void {$r=ldCommand($s,'end_turn');ldCheck($r->success,'End turn '.$r->error);ldDrain($s);}
// Preserve the exact pre-existing definition, not a replacement implementation.
$bone=['key'=>'bone','name'=>'Кость','effect'=>['type'=>'damage','value'=>1],'target'=>'enemy','trigger'=>'turn'];
ldCheck(ldProp()['instants']===[$bone],'Bone configuration preserved verbatim');
foreach(['host','player']as$owner)foreach(['creature','fly']as$type)foreach([false,true]as$closed){
 $s=ldState($owner,$type);$s->getCard(1)->closed=$closed;$s->getCard(1)->flags['attacks_used_this_turn']=2;ldBegin($s);
 if($type==='creature') {ldCheck($s->getCard(1)->closed===$closed,'No early opening before cell selection');ldCheck(ldChoose($s)->success,'Successful ground incarnation');}
 $lord=$s->getCard(1);$c=$s->getCard(3);
 ldCheck(!$lord->closed,'Source opens or stays open');ldCheck($lord->flags['attacks_used_this_turn']===($closed?0:2),'Use normal opening only for a closed source');
 ldCheck($c->effectiveMove()===3&&$c->move===3&&$c->moveMax===2,'Current/effective movement +1; base untouched');
 ldCheck(count(ldBonuses($c))===1&&ldBonuses($c)[0]['expire']===2,'Single modifier with two own ends');
 ldCheck(empty($s->battle['pending_incarnation'])&&!isset($s->battle['pending_lord_of_the_dead']),'No additional pending');
 $s=ldReload($s);ldCheck($s->getCard(3)->effectiveMove()===3,'Bonus survives reload');
 $s->getCard(1)->closed=true;ldBegin($s);ldCheck($s->getCard(1)->closed&&count(ldBonuses($s->getCard(3)))===1,'Processing again cannot replay completed incarnation');
 ldCheck(!ldChoose($s)->success&&$s->getCard(1)->closed,'Cell retry cannot reopen source');
}
// Eligibility and independently configurable abilities.
foreach(['enemy','hand','graveyard','deck','exile','dying','zero_hp','suppressed','only_open','only_move']as$case){
 $s=ldState();$lord=$s->getCard(1);
 if($case==='enemy')$lord->owner='player';elseif(in_array($case,['hand','graveyard','deck','exile'],true))$lord->zone=$case;
 elseif($case==='dying')$lord->dying=true;elseif($case==='zero_hp')$lord->hp=0;
 elseif($case==='suppressed')EffectiveFeatures::applyMemoryLoss($s,$s->getCard(2),$lord);
 elseif($case==='only_open')unset($lord->prop['on_ally_incarnation']['move_bonus']);elseif($case==='only_move')unset($lord->prop['on_ally_incarnation']['open_self']);
 ldBegin($s);ldCheck(ldChoose($s)->success,'Eligibility incarnation '.$case);
 ldCheck($lord->closed!==($case==='only_open'),'Opening eligibility '.$case);
 ldCheck($s->getCard(3)->effectiveMove()===($case==='only_move'?3:2),'Movement eligibility '.$case);
}
foreach(['artifact','land']as$type){$s=ldState();$s->getCard(3)->type=$type;ldBegin($s);ldCheck(ldChoose($s)->success,'Non-creature lifecycle');ldCheck($s->getCard(1)->closed&&ldBonuses($s->getCard(3))===[],'Only creature incarnation triggers');}
$s=ldState();$s->getCard(1)->zone='flying';ldBegin($s);ldChoose($s);ldCheck(!$s->getCard(1)->closed&&$s->getCard(3)->effectiveMove()===3,'Battlefield includes flying source');
// Invalid or abandoned selection grants neither opening nor bonus.
$s=ldState();ldBegin($s);ldCheck(!ldCommand($s,'choose_incarnation_cell',['row'=>2,'col'=>1])->success,'Invalid row rejected');
ldCheck(!ldCommand($s,'choose_incarnation_cell',['row'=>1,'col'=>6])->success,'Invalid column rejected');
ldCheck(!ldCommand($s,'choose_incarnation_cell',['row'=>1,'col'=>1],'player')->success,'Wrong player rejected');
ldCheck($s->getCard(1)->closed&&ldBonuses($s->getCard(3))===[],'Rejected commands grant nothing');
unset($s->battle['pending_incarnation']);$s=ldReload($s);ldCheck($s->getCard(1)->closed&&ldBonuses($s->getCard(3))===[],'Abandoned selection grants nothing');
// Two lords and two returning creatures: every source opens, recipients remain independent.
$s=ldState();$s->addCard(ldCard(4,'host',ldProp()));$other=ldCard(5,'host',['incarnation'=>['turns'=>1,'open'=>true]]);(new ZoneManager($s))->toGraveyard($other);$s->addCard($other);
ldBegin($s);ldCheck(ldChoose($s)->success,'First of two incarnations');ldCheck(!$s->getCard(1)->closed&&!$s->getCard(4)->closed,'Both lords open');
$s->getCard(1)->closed=true;$s->getCard(4)->closed=true;$s=ldReload($s);ldCheck(ldChoose($s,2)->success,'Second incarnation after reload');
foreach([3,5]as$id)ldCheck(count(ldBonuses($s->getCard($id)))===1&&$s->getCard($id)->effectiveMove()===3,'Non-stacking independent recipient '.$id);
ldCheck(!$s->getCard(1)->closed&&!$s->getCard(4)->closed,'Both lords trigger on each incarnation');
// Suppression or departure of a source does not revoke a lawful previously granted modifier.
EffectiveFeatures::applyMemoryLoss($s,$s->getCard(2),$s->getCard(1));(new ZoneManager($s))->toHand($s->getCard(4));
ldCheck($s->getCard(3)->effectiveMove()===3&&$s->getCard(5)->effectiveMove()===3,'Granted bonus independent of source lifetime/suppression');
// Other independent movement modifiers are untouched; spent movement is not refilled.
$s->getCard(3)->modifiers[]=['stat'=>'move','value'=>2,'expire'=>'permanent','source'=>'player'];$s->getCard(3)->move--;
ldCheck($s->getCard(3)->effectiveMove()===5&&$s->getCard(3)->move===2,'Independent modifier and spent movement respected');
// Existing zone cleanup, including a legitimate repeated return fixture, renews only this instance.
$s=ldState();ldBegin($s);ldChoose($s);$s->getCard(3)->modifiers[0]['expire']=1;
(new ZoneManager($s))->toGraveyard($s->getCard(3));ldCheck(ldBonuses($s->getCard(3))===[],'Exit clears bonus');
// Current rules exile a previously incarnated card on death; permit a repeated return only in this synthetic fixture.
$s->getCard(3)->zone='graveyard';$s->getCard(3)->prop=['incarnation'=>['turns'=>1,'open'=>true]];$s->getCard(3)->markers=['incarnation'=>['value'=>0,'threshold'=>1]];unset($s->getCard(3)->flags['incarnated']);
ldBegin($s);ldChoose($s);ldCheck(count(ldBonuses($s->getCard(3)))===1&&ldBonuses($s->getCard(3))[0]['expire']===2,'Repeated incarnation has one fresh full duration');
// Actual turn ends, for both owners, with reload between every transition.
foreach(['host','player']as$owner){
 $s=ldState($owner);ldBegin($s);ldChoose($s);$c=$s->getCard(3);$c->modifiers[]=['stat'=>'move','value'=>2,'expire'=>'permanent'];
 ldEnd($s);$s=ldReload($s);ldCheck(ldBonuses($s->getCard(3))[0]['expire']===1&&$s->getCard(3)->effectiveMove()===5,'Survives current own end '.$owner);
 ldEnd($s);$s=ldReload($s);ldCheck(ldBonuses($s->getCard(3))[0]['expire']===1&&$s->getCard(3)->move===5,'Active throughout next own turn '.$owner);
 ldEnd($s);$s=ldReload($s);ldCheck(ldBonuses($s->getCard(3))===[]&&$s->getCard(3)->effectiveMove()===4&&$s->getCard(3)->moveMax===2,'Expires at next own end only '.$owner);
 ldEnd($s);ldCheck($s->getCard(3)->move===4,'Next refill contains only independent modifier '.$owner);
}
// Full native phase sequence, preserving bone and its tap commitment.
function ldSequenceStart():GameState {$s=ldState();$s->getCard(1)->closed=false;(new TurnPhaseProcessor($s,new Engine()))->beginStartPhase('host');return $s;}
function ldSequenceBone(GameState $s):void {
 ldCheck(ldCommand($s,'turn_task',['task_id'=>'instants'])->success,'Choose instant task before incarnation');
 ldCheck(ldCommand($s,'turn_sub',['sub_id'=>'instant_1_bone'])->success,'Declare unchanged bone');
 ldCheck(ldCommand($s,'choose_instant_pick',['target_id'=>2])->success,'Bone target selected');
 ldCheck(ldCommand($s,'pass_turn_instant')->success,'Resolve existing instant stack');
 ldCheck($s->getCard(2)->hp===19&&$s->getCard(1)->closed,'Bone damage once, source closed');
}
$s=ldSequenceStart();ldSequenceBone($s);$s=ldReload($s);
ldCheck(ldCommand($s,'turn_instant_result_ok')->success,'Acknowledge bone before incarnation');
ldCheck(!empty($s->battle['pending_incarnation'])&&$s->getCard(1)->closed,'Incarnation begins only after bone closes source');
ldCheck(ldChoose($s)->success,'Native sequence incarnation');
ldCheck(!$s->getCard(1)->closed&&$s->getCard(3)->effectiveMove()===3,'Native sequence bonus and automatic opening');
ldCheck($s->getCard(2)->hp===19&&($s->getCard(1)->flags['instant_uses_this_turn']['bone']??0)===1,'No replay of bone or reserved use');
ldCheck(empty($s->battle['pending_incarnation'])&&empty($s->battle['turn_instant_stack'])&&empty($s->battle['turn_instant_result'])&&empty($s->battle['turn_phase']),'Native continuation completes without stuck pending');
$s=ldReload($s);$s->getCard(1)->closed=true;ldCheck(!ldChoose($s)->success&&$s->getCard(1)->closed&&$s->getCard(2)->hp===19,'Sequence retry has no repeated opening or instant');
echo "Lord of the dead: $lordChecks checks passed\n";
