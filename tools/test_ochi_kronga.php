<?php
declare(strict_types=1);
require __DIR__.'/test_action_resolution.php';
use Berserk\Core\{ActionResolution,CardInstance,Command,Engine,GameState,OchiKronga,StrikeResolver,TurnTimer,ZoneManager};
function os():GameState {
 $s=cs();foreach($s->cards as $c)$c->element='forests';
 $s->addCard(new CardInstance(instanceId:9,ukid:'unrelated-fixture-id',owner:'host',zone:'field',row:1,col:2,hp:10,hpMax:10,closed:true,element:'neutral',prop:[OchiKronga::PROP=>['filter'=>'forests'],'direct'=>true,'zoz'=>true]));
 return $s;
}
function oe(GameState $s,string $type,int $actor=1,array $facts=[],string $origin='ordinary'):array {
 ActionResolution::beginCommand($s);
 if(in_array($type,['strike','shot','bomb_shot'],true))$facts+=['hit'=>true];
 if($type==='heal')$facts+=['hp_restored'=>1];
 ActionResolution::effect($s,$s->getCard($actor),$type,$origin,$facts);
 $batch=$s->battle[ActionResolution::KEY]['notifications'];return $batch[array_key_last($batch)];
}
function om(GameState $s,int $id=9):array{return $s->getCard($id)->flags[OchiKronga::STATE]??[];}
function ot(GameState $s):void {foreach(['strike','shot','heal']as$t)oe($s,$t);}
foreach([['strike','shot','heal'],['strike','heal','shot'],['shot','strike','heal'],['shot','heal','strike'],['heal','strike','shot'],['heal','shot','strike']]as$order){
 $s=os();foreach($order as$t)oe($s,$t);ca(!$s->getCard(9)->closed&&om($s)['uses']===1&&om($s)['sequence']===[],'permutation '.implode('/',$order));
 ca($s->getCard(9)->element==='neutral','filter is ability-local');
}
$s=os();oe($s,'strike');oe($s,'shot');oe($s,'strike');ca(om($s)['sequence']===['strike'],'duplicate starts fresh');oe($s,'heal');ca($s->getCard(9)->closed,'old prefix discarded');oe($s,'shot');ca(!$s->getCard(9)->closed,'new prefix completes');
foreach(['miss','blocked','cancelled']as$outcome){$s=os();oe($s,'strike');$c=[];ActionResolution::bind($s,$c,$s->getCard(2),'shot');ActionResolution::resolve($s,$c,$outcome,['hit'=>false]);oe($s,'shot');oe($s,'heal');ca(om($s)['uses']===1,'ineffective outsider '.$outcome.' ignored');}


$s=os();oe($s,'strike');oe($s,'heal',2,['hp_restored'=>0],'passive');ca(om($s)['sequence']===['strike'],'zero HP ignored even outsider');oe($s,'shot');oe($s,'heal');ca(om($s)['uses']===1,'positive HP counts');
foreach(['move','jump','teleport','teleport_target','forced_move']as$t){$s=os();oe($s,'strike');oe($s,$t,2);oe($s,'shot');oe($s,'heal');ca(om($s)['uses']===1,'any actor movement ignored '.$t);}
foreach(['strike','shot','heal']as$t){$s=os();oe($s,'strike',5);$s->getCard(1)->element='mountains';oe($s,$t);ca(om($s)['sequence']===[],'nonforest productive action clears '.$t);}
foreach(['cast','discharge','impact','gain_coin','bomb_shot']as$t){$s=os();oe($s,'strike');oe($s,$t);ca(om($s)['sequence']===[],'other independent action clears '.$t);}
$s=os();oe($s,'strike');oe($s,'answer',2,['hit'=>true],'response');oe($s,'shot');oe($s,'heal');ca(om($s)['uses']===1,'response ignored');
$s=os();$s->getCard(9)->element='forests';oe($s,'strike');oe($s,'heal',9);ca(om($s)['sequence']===[],'self excluded');
$s=os();$s->getCard(1)->type='artifact';oe($s,'strike',5);oe($s,'shot');ca(om($s)['sequence']===[],'non-creature excluded');
$s=os();for($i=0;$i<3;$i++){$s->getCard(9)->closed=true;ot($s);ca(om($s)['uses']===min(2,$i+1),'per turn use cap');ca($s->getCard(9)->closed===($i===2),'third cannot open');}
$s=os();$s->getCard(9)->closed=false;$s->getCard(9)->flags['attacks_used_this_turn']=7;ot($s);ca(om($s)['uses']===0&&om($s)['sequence']===[]&&$s->getCard(9)->flags['attacks_used_this_turn']===7,'open card never reopened nor charged');
$s->getCard(9)->closed=true;ot($s);ca(om($s)['uses']===1,'later closed card can trigger');
$s=os();$copy=CardInstance::fromArray($s->getCard(9)->toArray());$copy->instanceId=10;$copy->col=3;$s->addCard($copy);ot($s);
ca(!$s->getCard(9)->closed&&!$s->getCard(10)->closed&&om($s,9)['uses']===1&&om($s,10)['uses']===1,'independent eyes share original action once');
$s=os();oe($s,'strike');$event=oe($s,'shot');$before=om($s);OchiKronga::onResolution($s,$event);ca(om($s)===$before,'duplicate delivery ignored');$s=cr($s);oe($s,'heal');ca(om($s)['uses']===1,'state survives reload');
// Native turn periods distinguish host/player even if round number is unchanged.
$s=os();ot($s);$first=om($s)['period'];$s->battle['active']='player';TurnTimer::prepare($s,'player');oe($s,'strike');ca(om($s)['uses']===1,'opponent turn cannot trigger');
$s->battle['active']='host';TurnTimer::prepare($s,'host');OchiKronga::startOwnTurn($s,'host');ca(om($s)['period']!==$first&&om($s)['uses']===0&&om($s)['sequence']===[],'new own period reset without relying on round');
OchiKronga::startOwnTurn($s,'host');oe($s,'strike');OchiKronga::startOwnTurn($s,'host');ca(om($s)['sequence']===['strike'],'resume start does not reset twice');
// active already changed during end phase, but its next period has not begun.
$s=os();$s->battle['active']='player';$s->getCard(9)->owner='player';ot($s);ca(om($s)===[],'old clock end phase not new owner turn');
foreach(['toHand','toDeck','toSquad','toSideboard','toGraveyard','toExile']as$method){$s=os();oe($s,'strike');(new ZoneManager($s))->$method($s->getCard(9));ca(om($s)===[],'leave resets '.$method);$s->getCard(9)->hp=10;(new ZoneManager($s))->toField($s->getCard(9),1,2);oe($s,'shot');oe($s,'heal');ca($s->getCard(9)->closed||om($s)['uses']===0,'return cannot retain old prefix');}
$s=os();oe($s,'strike');$card=$s->getCard(9);$s->removeCard(9);ca(!isset($card->flags[OchiKronga::STATE]),'remove instance resets retained object');$s->addCard($card);oe($s,'shot');ca(om($s)['sequence']===['shot'],'same object returns clean');
$s=os();oe($s,'strike');(new ZoneManager($s))->toFlying($s->getCard(9));oe($s,'shot');oe($s,'heal');ca(om($s)['uses']===1,'field/flying transition remains in play');
// Actual strike + vampire notifications, including a productive nonforest vampire.
foreach(['forests','mountains']as$element){$s=os();oe($s,'shot',5);$s->getCard(1)->element=$element;$s->getCard(1)->hp=20;$s->getCard(1)->prop['vampire']=true;
 $s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'confirmed'=>[],'state'=>'waiting_choice'];
 (new StrikeResolver($s,new Engine()))->apply(['attack'=>'weak','defend'=>'','winner'=>'attack'],false);
 ca(om($s)['uses']===($element==='forests'?1:0),'native vampire '.$element);ca($s->getCard(1)->hp===21,'vampire native HP preserved');}
// Real pending multi-heal emits one element, then ACK does not produce another.
$s=os();oe($s,'strike');oe($s,'shot');$s->getCard(1)->prop['actions']=[['type'=>'heal','key'=>'h','value'=>1,'max_targets'=>3]];$s->getCard(1)->hp=29;$s->getCard(5)->hp=29;
cmd($s,'host','action',['card_id'=>1,'action_key'=>'h']);ca($s->getCard(9)->closed,'pending declaration silent');$s=cr($s);
cmd($s,'host','choose_multi_heal',['target_ids'=>[1,5]]);ca(om($s)['uses']===1,'aggregated multi completes once');finish($s);ca(om($s)['uses']===1,'ACK cannot retrigger');
// Actual hit with zero post-reduction damage still completes the sequence.
$s=os();oe($s,'shot');oe($s,'heal');$s->getCard(2)->prop['damage_reduction']=[['value'=>99]];
$s->battle['strike']=['attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'confirmed'=>[],'state'=>'waiting_choice'];
(new StrikeResolver($s,new Engine()))->apply(['attack'=>'weak','defend'=>'','winner'=>'attack'],false);
ca(om($s)['uses']===1&&$s->getCard(2)->hp===30,'native prevented hit qualifies');
echo "Ochi Kronga: permutations, reset/ignore rules, native hits/heal/vampire, cap/open, instances, turns, leave/reload/ACK passed\n";

// A real before/after window and forced child. Opening belongs to the root resolution,
// not to later combat-frame completion, and no priority/target/ACK retriggers it.
$s=os();oe($s,'shot');oe($s,'heal');
$s->getCard(3)->prop['instants']=[['key'=>'force','name'=>'force','trigger'=>'turn','target'=>'enemy_open_creature','no_close'=>true,'effect'=>['type'=>'forced_strike_adjacent']]];
start($s);ca($s->getCard(9)->closed,'before window has not executed root');
$all=[];arCmd($s,'player','pass_turn_instant',[],$all);ca(om($s)['uses']===1&&!$s->getCard(9)->closed,'opens at root before after window');arDrain($s,$all);
arCmd($s,'player','play_turn_instant',['card_id'=>3,'instant_key'=>'force'],$all);
arCmd($s,'player','choose_instant_pick',['target_id'=>5],$all);
arCmd($s,'player','choose_forced_strike_adjacent',['target_id'=>2],$all);
arCmd($s,'player','pass_turn_instant',[],$all);
for($i=0;$i<30&&\Berserk\Core\CombatContinuation::active($s);$i++){
 $s=cr($s);
 if(!empty($s->battle['turn_instant_stack']))arCmd($s,$s->battle['turn_instant_stack']['priority'],'pass_turn_instant',[],$all);
 elseif(!empty($s->battle['turn_instant_result']))arCmd($s,'player','turn_instant_result_ok',[],$all);
 elseif(!empty($s->battle['strike']))arDrain($s,$all);
 else \Berserk\Core\CombatContinuation::settle($s,new Engine());
}
ca(!\Berserk\Core\CombatContinuation::active($s)&&om($s)['uses']===1,'nested completion and reload do not repeat opening');
// Pending reply ACK must be silent for the ability.
$used=om($s)['uses'];$r=(new Engine())->apply($s,'host',new Command('confirm_strike'));
ca(!$r->success&&om($s)['uses']===$used,'repeated ACK cannot open again');
echo "Ochi Kronga: native nested after/forced/reload/ACK passed\n";

// The real end_turn lifecycle resets on the next own turn, not on an ACK or the opponent's turn.
$s=os();$s->battle['hidden_row_revealed']=true;ot($s);$period=om($s)['period'];
cmd($s,'host','end_turn');ca($s->battle['active']==='player'&&om($s)['uses']===1,'native opponent transition preserves previous accounting');
$s=cr($s);cmd($s,'player','end_turn');ca($s->battle['active']==='host'&&om($s)['period']!==$period&&om($s)['uses']===0&&om($s)['sequence']===[],'native own turn resets once');
echo "Ochi Kronga: native end_turn/player/end_turn/host lifecycle passed\n";
