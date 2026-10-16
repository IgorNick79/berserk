<?php
declare(strict_types=1);
// Reuse real Engine fixtures and re-run P2 regressions before testing expiry policy.
require __DIR__.'/test_informational_wait.php';
use Berserk\Core\{GameState,Engine,Command,CardInstance,TurnTimer,TurnTimeoutEligibility as TE,ExpiredCommandGuard as Guard,InformationalWaitManager as IW,Dice};

$now=1180;$e=new Engine(null,static function()use(&$now):int{return $now;});
foreach(['host','player'] as $owner){
    $s=iwState($owner);$before=serialize($s);$eligible=TE::check($s,$now);
    iwAssert($eligible['allowed'] && $eligible['period_id']===1 && $eligible['owner']===$owner && serialize($s)===$before,'Read-only running expiry for both owners');
    iwAssert(!TE::check($s,1179)['allowed'],'Before exact deadline');
    iwAssert(TE::check($s,1180,2,$owner)['reason']==='period_changed','Wrong period');
    iwAssert(TE::check($s,1180,1,$s->getOpponentKey($owner))['reason']==='period_changed','Wrong owner');
    iwAssert(TE::check($s,1180,null,null,true)['reason']==='processing','Journal concurrency input');
    foreach(['action','strike','move','jump','uchr','gain_coin','open_turn_instants','play_turn_instant',
        'combat_instant_play','choose_forced_strike','attack_series_next','choose_coin_ability'] as $type){
        $before=serialize($s);$r=$e->apply($s,$owner,new Command($type,['card_id'=>1,'target_id'=>2,'action_key'=>'marker']));
        iwAssert(!$r->success && str_contains($r->error??'','Время хода истекло') && serialize($s)===$before,'No mutation for new/alternative route '.$type);
    }
    iwApply($e,$s,$owner,'end_turn');
    iwAssert($s->battle['active']===$s->getOpponentKey($owner) && $s->battle['turn_timer']['period_id']===2,'Ordinary Engine lifecycle starts next period');
}
foreach(['paused','missing','malformed','owner_mismatch','phase','unknown','continuation','finished'] as $case){
    $s=iwState();
    switch($case){
        case 'paused':TurnTimer::pause($s,1,'opponent_wait',1001);break;
        case 'missing':unset($s->battle['turn_timer']);break;
        case 'malformed':unset($s->battle['turn_timer']['anchor_at']);break;
        case 'owner_mismatch':$s->battle['active']='player';break;
        case 'phase':$s->battle['turn_phase']=['phase'=>'end'];break;
        case 'unknown':$s->battle['pending_future']=['owner'=>'host'];break;
        case 'continuation':$s->battle['coin_post_combat_continuation']=['attacker_id'=>1];break;
        case 'finished':$s->winner='player';$s->status='game_over';break;
    }
    $before=serialize($s);iwAssert(!TE::check($s,2000)['allowed'] && serialize($s)===$before,'Blocked/pure '.$case);
}
foreach(['period_id'=>0,'owner'=>[],'status'=>'bad','remaining_seconds'=>181,'anchor_at'=>'1000','pause_reason'=>'end_phase'] as $key=>$value){
    $s=iwState();$s->battle['turn_timer'][$key]=$value;$before=serialize($s);
    iwAssert(TE::clock($s,2000)===null && !$e->apply($s,'host',new Command('action'))->success && serialize($s)===$before,'Malformed clock safely rejected '.$key);
}
foreach(['absent','null'] as $legacy){
    $s=iwState();if($legacy==='absent')unset($s->battle['turn_timer']);else $s->battle['turn_timer']=null;
    iwAssert($e->apply($s,'host',new Command('action',['card_id'=>1,'action_key'=>'marker']))->success,'Legacy without a timer keeps ordinary manual commands');
}
$s=iwState();TurnTimer::settle($s,1180);iwAssert(TE::check($s,2000)['allowed'],'Persisted expired');
$s=GameState::fromArray($s->toArray());iwAssert(TE::check($s,2000)['allowed'],'Expired survives reload');
$s->battle['turn_timer']['pause_reason']='end_phase';iwAssert(!TE::check($s,2000)['allowed'],'Malformed expired end_phase must not launch timeout');
$s=iwState();TurnTimer::settle($s,1180);
$s->battle['strike']=['state'=>'results','confirmed'=>[],'attacker_id'=>1,'target_id'=>2];
$s->battle['pending_prophecy']=['owner'=>'host','actions'=>[['cmd'=>'close_prophecy']]];
iwAssert(Guard::error($s,'host',new Command('close_prophecy'),1200)===null
    && Guard::error($s,'host',new Command('confirm_strike'),1200)!==null,'Prophecy suspends strike in the existing priority order');

// An own target choice consumes its last second; result and owner ACK remain manual.
$now=1000;$s=iwState();iwApply($e,$s,'host','action',['card_id'=>1,'action_key'=>'marker']);
$now=1200;iwApply($e,$s,'host','choose_cell_marker',['row'=>3,'col'=>2]);
iwAssert($s->battle['turn_timer']['status']==='expired' && !TE::check($s,$now)['allowed'],'Own choice completes after zero but result blocks');
iwApply($e,$s,'player','confirm_strike');
iwAssert(!TE::check($s,$now)['allowed'],'Own informational ACK never automatic');
iwApply($e,$s,'host','confirm_strike');iwAssert(TE::check($s,$now)['allowed'],'Final ACK removes last blocker');

// Real forced strike after zero, preserving target choice and ordinary combat checks.
$sessionBefore=$_SESSION??[];
try{
    $now=1200;$s=iwState();$s->getCard(1)->prop=['forced_strike'=>true];$s->getCard(2)->closed=true;
    iwAssert(TE::check($s,$now)['reason']==='forced_attack','Forced obligation blocks timeout end');
    $_SESSION['debug_roll']='6,1';unset($_SESSION['debug_roll_state']);Dice::init();
    iwApply($e,$s,'host','strike',['card_id'=>1,'target_id'=>2]);
    iwApply($e,$s,'host','confirm_strike');iwApply($e,$s,'player','confirm_strike');
    iwAssert(TE::check($s,$now)['allowed'],'Resolved compulsory attack releases timeout');

    $s=iwState();$s->getCard(1)->prop=['forced_strike'=>true];$s->getCard(2)->closed=true;$s->getCard(2)->coins=1;
    $s->getCard(2)->prop=['coin_block'=>['cost'=>1,'defense'=>'zoa','types'=>['strike']]];
    iwApply($e,$s,'host','strike',['card_id'=>1,'target_id'=>2]);
    iwApply($e,$s,'player','choose_coin_ability',['choice'=>'yes','choice_id'=>$s->battle['pending_coin_ability']['id']]);
    iwApply($e,$s,'host','confirm_strike');iwApply($e,$s,'player','confirm_strike');
    iwAssert(TE::check($s,$now)['allowed'],'Coin block continuation after expiry');

    $s=iwState();$s->getCard(1)->prop=['forced_strike'=>true,'start_pre_battle'=>[['effect'=>['type'=>'degasca_study']]],
        'strike_effects'=>[['levels'=>['strong'],'type'=>'degasca_sequence']]];$s->getCard(2)->closed=true;
    $s->addCard(new CardInstance(instanceId:3,ukid:'learn_marker',owner:'host',zone:CardInstance::ZONE_DECK,order:1,
        prop:['strike_effects'=>[['levels'=>['strong'],'marker'=>['type'=>'stun','value'=>1]]]]));
    $_SESSION['debug_roll']='6,1';unset($_SESSION['debug_roll_state']);Dice::init();
    iwApply($e,$s,'host','strike',['card_id'=>1,'target_id'=>2]);iwApply($e,$s,'host','confirm_strike');iwApply($e,$s,'player','confirm_strike');
    iwApply($e,$s,'host','choose_degasca_card',['card_id'=>3]);
    iwAssert(TE::check($s,$now)['allowed'],'Degasca restoration after expiry');

    // Start a series before deadline; only its registered continuation survives expiry.
    $now=1000;$s=iwState();$s->getCard(1)->prop=['attack_series'=>true,'unanswer'=>true];
    $s->addCard(new CardInstance(instanceId:3,ukid:'series_target',owner:'player',zone:CardInstance::ZONE_FIELD,row:3,col:4,hp:20,hpMax:20));
    iwApply($e,$s,'host','strike',['card_id'=>1,'target_id'=>2]);iwApply($e,$s,'host','confirm_strike');iwApply($e,$s,'player','confirm_strike');
    iwAssert(!empty($s->battle['pending_attack_series_choice']),'Series real pending');
    $now=1200;iwApply($e,$s,'host','attack_series_next',['target_id'=>3]);
    iwApply($e,$s,'host','confirm_strike');iwApply($e,$s,'player','confirm_strike');
    iwAssert(TE::check($s,$now)['allowed'],'Series completes its workflow after expiry');

    // Existing turn stack may add instants; a new independent window is forbidden.
    $now=1000;$s=iwState();$s->getCard(1)->prop=['instants'=>[['key'=>'hit','trigger'=>'turn','target'=>'self','effect'=>['type'=>'damage','value'=>1]]]];
    iwApply($e,$s,'host','open_turn_instants');$now=1200;
    iwApply($e,$s,'host','play_turn_instant',['card_id'=>1,'instant_key'=>'hit']);
    while(!empty($s->battle['turn_instant_stack']) && ($s->battle['turn_instant_stack']['state']??'')==='ordering')
        iwApply($e,$s,$s->battle['turn_instant_stack']['priority'],'pass_turn_instant');
    if(!empty($s->battle['turn_instant_result']))iwApply($e,$s,'host','turn_instant_result_ok');
    iwAssert(TE::check($s,$now)['allowed'],'Opened stack resolves without auto-pass');

    // New period is allocated only after end-phase ACK; old expired period cannot end twice.
    $s=iwState();$s->getCard(1)->prop=['turn_end'=>[['type'=>'heal','value'=>1]]];
    iwApply($e,$s,'host','end_turn');
    iwAssert($s->battle['active']==='player' && $s->battle['turn_timer']['period_id']===1 && !empty($s->battle['turn_phase']),'End phase can remain pending');
    iwAssert(!TE::check($s,$now)['allowed'],'Ending phase blocks repeat');
    iwApply($e,$s,'host','turn_ack');iwAssert($s->battle['turn_timer']['period_id']===2,'Phase continuation prepares next timer');
}finally{$_SESSION=$sessionBefore;Dice::init();}
$s=iwState();$now=1200;iwApply($e,$s,'host','resign');iwAssert($s->status==='game_over','Resign remains available');
echo "T3 eligibility/expired command guard/forced strike/series/instants/end-phase tests passed\n";
