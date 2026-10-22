<?php
declare(strict_types=1);
require __DIR__.'/test_matriarch_order.php';
use Berserk\Core\{CardInstance,Command,CombatContinuation,Engine,StrikeResolver,InstantProcessor,InformationalWaitManager};

foreach(['auto','push'] as $case){
 $s=cs();$s->getCard(1)->prop=$case==='auto'?['auto'=>[['trigger'=>'strike_hit','type'=>'shot','value'=>1,'range'=>0]]]:['strike_effects'=>[['push'=>2]]];
 $s->getCard(3)->prop['instants']=[instant('heal',['type'=>'heal','value'=>1])];
 start($s);cmd($s,'player','pass_turn_instant');cmd($s,'host','confirm_strike');cmd($s,'player','confirm_strike');
 ca(CombatContinuation::active($s),$case.' holds frame');
 if($case==='auto')cmd($s,'host','choose_auto_target',['target_id'=>0]);
 else cmd($s,'player','choose_push_choice',['choice'=>'damage']);
 finish($s);ca(!empty($s->battle['turn_instant_stack']),$case.' preserves after window');
 cmd($s,'player','pass_turn_instant');ca(!CombatContinuation::active($s),$case.' completion');
}
echo "tagged auto and push preserve after lifecycle passed\n";

$s=cs();$s->getCard(1)->prop=['execute'=>1,'after_strike_execute_limit'=>1];
$s->addCard(new CardInstance(instanceId:8,ukid:'execute',owner:'player',zone:'field',row:4,col:4,hp:1,hpMax:1));
$s->addCard(new CardInstance(instanceId:9,ukid:'execute2',owner:'player',zone:'field',row:4,col:2,hp:1,hpMax:1));
start($s);finish($s);ca(!empty($s->battle['pending_after_strike_execute']),'execute obligation');
ca(CombatContinuation::active($s),'root waits for execute');$s=cr($s);cmd($s,'host','choose_after_strike_execute',['target_id'=>8]);finish($s);
ca(!CombatContinuation::active($s),'execute completion');echo "execute/reload passed\n";

// No-close combat ability is available to the effectively-open defender; tap is not free.
$s=os();$s->getCard(5)->prop['stamina']=true;
$cap=['key'=>'cap','name'=>'cap','trigger'=>'combat','phase'=>'setter','target'=>'self','no_close'=>true,'effect'=>['type'=>'damage_cap','value'=>1]];
$tap=$cap;$tap['key']='tapcap';unset($tap['no_close']);
$redirect=$cap;$redirect['key']='redirect';$redirect['effect']=['type'=>'redirect_strike'];
$s->getCard(2)->prop['instants']=[$cap,$tap,$redirect];activate($s);chooseOrder($s,2);
ca(($s->battle['strike']['state']??'')==='waiting_instant','combat instant window');
$instants=(new InstantProcessor($s,new Engine()))->getInstants('player','combat','combat');
ca(array_column(array_column($instants,'payload'),'key')===['cap'],'UI allows no-close, hides tap and redirect');
$bad=(new Engine())->apply($s,'player',new Command('combat_instant_play',['card_id'=>2,'instant_key'=>'tapcap']));ca(!$bad->success,'server rejects unaffordable tap');
cmd($s,'player','combat_instant_play',['card_id'=>2,'instant_key'=>'cap']);
for($i=0;$i<12&&($s->battle['strike']['state']??'')==='waiting_instant';$i++)cmd($s,$s->battle['strike']['instant_priority'],'combat_instant_pass');
if(($s->battle['strike']['state']??'')==='waiting_choice')cmd($s,'host','choose_strike_mode',['mode'=>'normal']);
finish($s);ca($s->getCard(2)->closed,'no physical opening');ca(($s->getCard(2)->flags['instant_uses_this_turn']['cap']??0)===1,'own usage resource consumed');
echo "combat no_close/tap/redirect availability and server admission passed\n";

// A real after effect kills the next snapshot participant; no second battle may be created.
$s=os();$s->getCard(3)->prop['instants']=[['key'=>'kill','trigger'=>'turn','target'=>'enemy','effect'=>['type'=>'damage','value'=>100]]];
activate($s);chooseOrder($s,2);chooseOrder($s,1);cmd($s,'player','pass_turn_instant');finish($s);
cmd($s,'player','play_turn_instant',['card_id'=>3,'instant_key'=>'kill']);cmd($s,'player','choose_instant_pick',['target_id'=>5]);cmd($s,'player','pass_turn_instant');
$s=cr($s);cmd($s,'player','turn_instant_result_ok');ca(empty($s->battle['matriarch_order'])&&empty($s->battle['strike']),'real instant death excludes next');
echo "real after death of next participant passed\n";

// PanelSpec survives reload; player identity and choice token are preserved in rendered controls.
$s=os();activate($s);$s=cr($s);$handler=\Berserk\Core\Choice\ChoiceRegistry::current($s);
$spec=$handler->spec($s,'host',[],'?game=9901&first','host');$html=\Berserk\View\Ui\Panel::render($spec);
ca(str_contains($html,'choice_id=')&&str_contains($html,'Приказ матриарха'),'target panel renders token');
chooseOrder($s,2);$s=cr($s);$spec=$handler->spec($s,'host',[],'?game=9901&first','host');$html=\Berserk\View\Ui\Panel::render($spec);
ca(str_contains($html,'следующий участник')&&str_contains($html,'cc1')&&str_contains($html,'cc5'),'participant panel renders distinct cards');
echo "InfoPanel target/participant/reload passed\n";

// Two levels of real forced combats, through before/after stacks, under the actual order.
$s=os();$force=['key'=>'force','trigger'=>'turn','target'=>'enemy_open_creature','no_close'=>true,'effect'=>['type'=>'forced_strike_adjacent']];
$s->getCard(3)->prop['instants']=[$force];
$s->addCard(new CardInstance(instanceId:7,ukid:'nestedforce',owner:'player',zone:'field',row:6,col:2,hp:20,hpMax:20,prop:['instants'=>[$force]]));
$s->addCard(new CardInstance(instanceId:8,ukid:'nestedvictim',owner:'player',zone:'field',row:1,col:2,hp:20,hpMax:20,closed:true));
$s->addCard(new CardInstance(instanceId:9,ukid:'nestedattacker',owner:'host',zone:'field',row:2,col:1,hp:20,hpMax:20,strikeWeak:1,strikeMedium:2,strikeStrong:3));
activate($s);chooseOrder($s,2);chooseOrder($s,1);cmd($s,'player','pass_turn_instant');finish($s);
cmd($s,'player','play_turn_instant',['card_id'=>3,'instant_key'=>'force']);cmd($s,'player','choose_instant_pick',['target_id'=>5]);
cmd($s,'player','choose_forced_strike_adjacent',['target_id'=>2]);cmd($s,'player','pass_turn_instant');
ca(($s->battle['turn_instant_stack']['phase']??'')==='before','child before window');
cmd($s,'player','play_turn_instant',['card_id'=>7,'instant_key'=>'force']);cmd($s,'player','choose_instant_pick',['target_id'=>9]);
cmd($s,'player','choose_forced_strike_adjacent',['target_id'=>8]);cmd($s,'player','pass_turn_instant');
ca(count($s->battle['combat_continuation']['frames'])===3,'root/child/grandchild frames');
ca(empty($s->battle['strike']['matriarch_order']),'grandchild does not inherit combat policy');
ca(($s->getCard(5)->flags['attacks_used_this_turn']??0)===0,'future order participant has not attacked before nested resolution');
for($i=0;$i<60&&!empty($s->battle['matriarch_order']);$i++){
 $s=cr($s);
 if(!empty($s->battle['turn_instant_stack']))cmd($s,$s->battle['turn_instant_stack']['priority'],'pass_turn_instant');
 elseif(!empty($s->battle['turn_instant_result']))cmd($s,'player','turn_instant_result_ok');
 elseif(!empty($s->battle['strike']))finish($s);
 else throw new RuntimeException('Order stalled: '.json_encode($s->battle));
}
ca(empty($s->battle['matriarch_order'])&&empty($s->battle['combat_continuation']),'deep nested order drains completely');
ca(($s->getCard(1)->flags['attacks_used_this_turn']??0)===0,'order root remains free');
ca(($s->getCard(5)->flags['attacks_used_this_turn']??0)===1,'child paid its attack, subsequent order battle did not');
echo "order/child/grandchild/reload/policy isolation passed\n";

$s=os();$s->getCard(4)->row=3;$s->getCard(4)->col=2;
activate($s);chooseOrder($s,2);ca(count($s->battle['matriarch_order']['initial_ids'])===3,'three initial participants');
$s->addCard(new CardInstance(instanceId:9,ukid:'late',owner:'host',zone:'field',row:4,col:4,hp:20,hpMax:20,strikeWeak:1,strikeMedium:2,strikeStrong:3));
$_SESSION['debug_roll']=implode(',',array_merge(...array_fill(0,12,[6,1])));unset($_SESSION['debug_roll_state']);\Berserk\Core\Dice::init();
chooseOrder($s,5);finish($s);ca(($s->battle[\Berserk\Core\MatriarchOrderProcessor::PENDING]['ids']??[])===[1,4],'owner chooses remaining order');
chooseOrder($s,4);finish($s);ca(empty($s->battle['matriarch_order']),'last participant automatic');
ca($s->getCard(2)->hp===91,'exactly three real battles, late arrival not added');
ca(!$s->getCard(1)->closed&&!$s->getCard(5)->closed&&$s->getCard(4)->closed,'physical states preserved');
cmd($s,'host','strike',['card_id'=>1,'target_id'=>2]);
ca(($s->battle['strike']['defend_dice']??null)===0,'ordinary closed defender restored');
ca($s->getCard(1)->closed&&($s->getCard(1)->flags['attacks_used_this_turn']??0)===1,'ordinary attack remains available and consumes resource');finish($s);
echo "three participants/player order/late arrival/ordinary attack regression passed\n";

$s=os();$s->getCard(5)->prop['stamina']=true;
$s->addCard(new CardInstance(instanceId:9,ukid:'ragnar-any-id',owner:'host',zone:'graveyard',hp:0,hpMax:10,
 prop:['ragnar_first_strike_unanswer'=>true],flags:['valhalla_active'=>true]));
activate($s);chooseOrder($s,2);ca(!empty($s->battle['strike']['ragnar_unanswer'])&&($s->battle['strike']['defend_dice']??null)===0,'Ragnar applies against effectively open defender');
ca(!empty($s->getCard(9)->flags['ragnar_unanswer_used_this_turn']),'independent Ragnar resource consumed');finish($s);
echo "order Ragnar opening trigger and independent resource passed\n";

$s=os();$catalog=[];foreach($s->cards as $card)$catalog[$card->ukid]=['revision'=>'test','name'=>$card->ukid,'image'=>'','description'=>'','type'=>'creature','element'=>'neutral','classes'=>[],'price'=>0];
$s->historyRecorder=new \Berserk\Core\BattleHistory\Recorder($s->gameId,1,$catalog,[],true);
activate($s);chooseOrder($s,2);chooseOrder($s,1);
for($i=0;$i<20&&!empty($s->battle['strike']);$i++){
 if(($s->battle['strike']['state']??'')==='waiting_choice')cmd($s,'host','choose_strike_mode',['mode'=>'normal']);
 else foreach(['host','player'] as $p)if(!empty($s->battle['strike'])&&!in_array($p,$s->battle['strike']['confirmed']??[],true))cmd($s,$p,'confirm_strike');
}
$history=$s->historyRecorder->batch($s)->data();$combats=array_values(array_filter($history['actions'],static fn($a)=>$a['type']==='combat'));
ca(count($combats)===2,'two distinct history combat actions');foreach($combats as $a)ca($a['status']==='settled','history combat completed');
echo "separate completed native history combat actions passed\n";

$s=os();$s->getCard(2)->hp=1;$s->getCard(2)->prop['on_death']=[['target'=>'choice','type'=>'damage','value'=>100]];
activate($s);chooseOrder($s,2);chooseOrder($s,1);
ca(!empty($s->battle['strike']['pending_choice'])&&!empty($s->battle['matriarch_order']),'death pending retains current order combat');
$s=cr($s);cmd($s,'player','choose_death_target',['target_id'=>5]);finish($s);
ca(empty($s->battle['matriarch_order'])&&$s->getCard(5)->zone==='graveyard','death pending resolved before target death stops order');
echo "target death/nested death pending/grave/reload passed\n";

$s=os();$s->getCard(5)->prop['stamina']=true;$s->getCard(2)->closed=false;
$s->getCard(2)->prop['instants']=[['key'=>'luck','trigger'=>'combat','phase'=>'dice','target'=>'self','no_close'=>true,'effect'=>['type'=>'dice_choice']]];
activate($s);chooseOrder($s,2);cmd($s,'player','combat_instant_play',['card_id'=>2,'instant_key'=>'luck']);
for($i=0;$i<10&&empty($s->battle['pending_dice_choice']);$i++)cmd($s,$s->battle['strike']['instant_priority'],'combat_instant_pass');
ca(!empty($s->battle['pending_dice_choice']),'dice pending');$s=cr($s);cmd($s,'player','choose_dice_choice',['choice'=>'plus:own']);
ca(!$s->getCard(2)->closed,'no_close survives paused dice choice');finish($s);
echo "no_close dice pending/reload preserves physical openness passed\n";
