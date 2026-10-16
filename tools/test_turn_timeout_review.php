<?php
declare(strict_types=1);
require __DIR__.'/test_turn_timeout.php';
use Berserk\Core\{BattleWaitResolver,CardInstance,Command,Engine,GameState,TurnTimer,TurnTimeoutEligibility as TE};

// Continuations survive persistence even with their outer choice already removed.
foreach (['coin_post_combat_queue','coin_post_combat_continuation',
    'pending_any_death_degasca_sequence','pending_nokami_wound_degasca_sequence',
    'pending_degasca_deferred_sequences','pending_degasca_sequence',
    'turn_instant_stack','turn_instant_result'] as $key) {
    $s=iwState();$s->battle[$key]=['continuation'=>['type'=>'strike_after']];
    $s=GameState::fromArray($s->toArray());$before=serialize($s);
    iwAssert(BattleWaitResolver::current($s)!==null && !TE::check($s,1200)['allowed']
        && serialize($s)===$before,'Persisted continuation blocks auto-end: '.$key);
}
foreach (['pending_choice','pending_wounds_resolution','deadeat_queue','instant_resolution','waiting_resolve_after_heal'] as $key) {
    $s=iwState();$s->battle['strike']=['state'=>'resolving',$key=>['remaining'=>[1]]];
    iwAssert(!TE::check($s,1200)['allowed'],'Nested strike continuation: '.$key);
}
$s=iwState();$s->battle['coin_post_combat_history']=['finished'=>true];
$s->battle['degasca_messages']=['finished'];
iwAssert(TE::check($s,1200)['allowed'],'History is not a live continuation');

// Compare actual Engine behavior either side of expiry. Flying cards have null
// coordinates; the existing strike rules still allow this corner position.
foreach ([1000,1200] as $time) {
    $s=iwState();$s->getCard(1)->row=1;$s->getCard(1)->col=1;
    $s->getCard(1)->prop=['forced_strike'=>true,'unanswer'=>true];
    $s->getCard(2)->row=2;$s->getCard(2)->col=1;$s->getCard(2)->closed=true;
    $s->addCard(new CardInstance(instanceId:3,ukid:'flying',owner:'player',zone:CardInstance::ZONE_FLYING,
        hp:20,hpMax:20,closed:true));
    $engine=new Engine(null,static fn():int=>$time);
    iwAssert(TE::forcedAttack($s,'host'),'Real field target creates compulsory obligation');
    iwApply($engine,$s,'host','strike',['card_id'=>1,'target_id'=>3]);
}
// Refused workflow parameters stay with their existing handler, not a second
// validator in the timeout guard. Use a settled timer to isolate game mutations.
function reviewReject(Engine $engine,GameState $s,string $actor,string $type,array $payload=[]): void {
    TurnTimer::settle($s,1200);$before=serialize($s);
    $result=$engine->apply($s,$actor,new Command($type,$payload));
    iwAssert(!$result->success && serialize($s)===$before,'Mutation-free workflow refusal: '.$type);
}
$time=1000;$engine=new Engine(null,static function()use(&$time):int{return $time;});
$s=iwState();iwApply($engine,$s,'host','action',['card_id'=>1,'action_key'=>'marker']);$time=1200;
reviewReject($engine,$s,'host','choose_cell_marker',['row'=>99,'col'=>99]);
reviewReject($engine,$s,'player','choose_cell_marker',['row'=>3,'col'=>2]);
reviewReject($engine,$s,'host','action',['card_id'=>1,'action_key'=>'marker']);
iwApply($engine,$s,'host','choose_cell_marker',['row'=>3,'col'=>2]);

$time=1000;$s=iwState();
$s->getCard(1)->prop=['instants'=>[['key'=>'hit','trigger'=>'turn','target'=>'self','effect'=>['type'=>'damage','value'=>1]]]];
iwApply($engine,$s,'host','open_turn_instants');$time=1200;
reviewReject($engine,$s,'host','play_turn_instant',['card_id'=>2,'instant_key'=>'hit']);
reviewReject($engine,$s,'host','play_turn_instant',['card_id'=>1,'instant_key'=>'absent']);
reviewReject($engine,$s,'player','pass_turn_instant');
reviewReject($engine,$s,'host','open_turn_instants');
iwApply($engine,$s,'host','play_turn_instant',['card_id'=>1,'instant_key'=>'hit']);

// The prophecy source is stored server-side; a client card_id cannot substitute it.
$s=iwState();$s->getCard(1)->prop=['prophecy'=>1];
$s->addCard(new CardInstance(instanceId:3,ukid:'peek',owner:'host',zone:CardInstance::ZONE_DECK,order:1));
(new Berserk\Core\TurnProcessor($s,$engine))->triggerProphecyForCard($s->getCard(1),'host');
reviewReject($engine,$s,'player','close_prophecy');
reviewReject($engine,$s,'host','transform_seeker',['card_id'=>2]);
reviewReject($engine,$s,'host','summon_start',['card_id'=>2]);
reviewReject($engine,$s,'host','reorder_card_up',['card_id'=>2]);
reviewReject($engine,$s,'host','grezy_continue');
iwAssert(!TE::check($s,1200)['allowed'],'Prophecy remains a blocker after rejected parameters');
$savedSession=$_SESSION??[];
try {
    $_SESSION['debug_roll']='6,1';unset($_SESSION['debug_roll_state']);Berserk\Core\Dice::init();
    $s=iwState();$s->getCard(1)->prop=['forced_strike'=>true];$s->getCard(2)->closed=true;
    $s->getCard(2)->coins=1;$s->getCard(2)->prop=['coin_block'=>['cost'=>1,'defense'=>'zoa','types'=>['strike']]];
    iwApply($engine,$s,'host','strike',['card_id'=>1,'target_id'=>2]);
    reviewReject($engine,$s,'player','choose_coin_ability',['choice'=>'yes','choice_id'=>'old']);
    reviewReject($engine,$s,'host','choose_coin_ability',['choice'=>'yes','choice_id'=>$s->battle['pending_coin_ability']['id']]);
    iwApply($engine,$s,'player','choose_coin_ability',['choice'=>'yes','choice_id'=>$s->battle['pending_coin_ability']['id']]);

    $s=iwState();$s->getCard(1)->prop=['forced_strike'=>true,'start_pre_battle'=>[['effect'=>['type'=>'degasca_study']]],
        'strike_effects'=>[['levels'=>['strong'],'type'=>'degasca_sequence']]];$s->getCard(2)->closed=true;
    $s->addCard(new CardInstance(instanceId:3,ukid:'learn_marker',owner:'host',zone:CardInstance::ZONE_DECK,order:1,
        prop:['strike_effects'=>[['levels'=>['strong'],'marker'=>['type'=>'stun','value'=>1]]]]));
    $_SESSION['debug_roll']='6,1';unset($_SESSION['debug_roll_state']);Berserk\Core\Dice::init();
    iwApply($engine,$s,'host','strike',['card_id'=>1,'target_id'=>2]);
    iwApply($engine,$s,'host','confirm_strike');iwApply($engine,$s,'player','confirm_strike');
    reviewReject($engine,$s,'host','choose_degasca_card',['card_id'=>2]);
    reviewReject($engine,$s,'player','choose_degasca_card',['card_id'=>3]);
    iwApply($engine,$s,'host','choose_degasca_card',['card_id'=>3]);
    iwAssert(TE::check($s,1200)['allowed'],'Valid Degasca restoration still finishes');

    $time=1000;$s=iwState();$s->getCard(1)->prop=['attack_series'=>true,'unanswer'=>true];
    $s->addCard(new CardInstance(instanceId:3,ukid:'series',owner:'player',zone:CardInstance::ZONE_FIELD,row:3,col:4,hp:20,hpMax:20));
    iwApply($engine,$s,'host','strike',['card_id'=>1,'target_id'=>2]);
    iwApply($engine,$s,'host','confirm_strike');iwApply($engine,$s,'player','confirm_strike');$time=1200;
    reviewReject($engine,$s,'host','attack_series_next',['target_id'=>1,'card_id'=>2]);
    reviewReject($engine,$s,'player','attack_series_end');
    reviewReject($engine,$s,'host','strike',['card_id'=>2,'target_id'=>1]);
    iwApply($engine,$s,'host','attack_series_next',['card_id'=>2,'target_id'=>3]);
    iwAssert($s->battle['strike']['attacker_id']===1,'Series ignores substituted source and uses pending source');
} finally {$_SESSION=$savedSession;Berserk\Core\Dice::init();}
foreach (['host','player'] as $actor) {
    $s=iwState($actor);$s->getCard(1)->prop=['forced_strike'=>true];$s->getCard(2)->closed=true;
    $time=1200;
    reviewReject($engine,$s,$actor,'end_turn');
    reviewReject($engine,$s,$actor,'strike',['card_id'=>2,'target_id'=>1]);
    reviewReject($engine,$s,$actor,'strike',['card_id'=>1,'target_id'=>1]);
    $s->getCard(2)->closed=false;
    iwAssert(!TE::forcedAttack($s,$actor),'No compulsory closed target');
    reviewReject($engine,$s,$actor,'strike',['card_id'=>1,'target_id'=>2]);
}
echo "T3 review continuation, forced-target parity and workflow routing tests passed\n";
