<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader,GameState,Engine,TurnTimer,Command,MatchPause as MP,InformationalWaitManager as IW,TurnTimeoutEligibility};
Autoloader::register();Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/');
$count=0;
function mpAssert(bool $ok,string $message):void {global $count;++$count;if(!$ok)throw new RuntimeException($message);}
function mpFixture():GameState {
    $s=new GameState(-19001,1,2);$s->status='battle';
    $s->battle=['active'=>'host','turn'=>1,'strike'=>null];
    TurnTimer::prepare($s,'host');TurnTimer::resume($s,1,'start_phase',1000);return $s;
}
function mpCommand(GameState $s,string $actor,string $type,int $now,array $payload=[]):\Berserk\Core\Result {
    if($type!=='request_pause')$payload+=['request_id'=>$s->battle['match_pause']['request_id']??'missing'];
    return (new Engine(null,static fn():int=>$now))->apply($s,$actor,new Command($type,$payload));
}
function mpRequest(GameState $s,string $actor='host',int $now=1010,int $duration=60):void {
    mpAssert(mpCommand($s,$actor,'request_pause',$now,['duration_seconds'=>$duration])->success,'Request by '.$actor);
}
foreach(['host','player'] as $actor){
    $s=mpFixture();mpRequest($s,$actor);$p=$s->battle['match_pause'];
    mpAssert($p['mode']==='requested' && $p['request_deadline_at']===1030,'Request identity/deadline');
    mpAssert(TurnTimer::snapshot($s,9999)['remaining_seconds']===170,'Request freezes clock');
    $before=$s->toArray();
    foreach(['end_turn','confirm_strike','action','pass_turn_instant','cancel_pending'] as $cmd)
        mpAssert(!mpCommand($s,$actor,$cmd,1011)->success,'Blocked '.$cmd);
    mpAssert($s->toArray()===$before,'Rejected commands never mutate');
    mpAssert(!mpCommand($s,$actor,'accept_pause',1011)->success,'Initiator cannot accept');
    mpAssert(!mpCommand($s,$s->getOpponentKey($actor),'accept_pause',1011,['request_id'=>'old'])->success,'Stale request rejected');
    mpAssert(!mpCommand($s,'outsider','pause_ready',1011)->success,'Nonparticipant rejected');
    mpAssert(mpCommand($s,$s->getOpponentKey($actor),'accept_pause',1015)->success,'Accept');
    mpAssert($s->battle['match_pause']['pause_deadline_at']===1075 && $s->battle['match_pause']['used'][$actor]===1,'Accept counts once');
    mpAssert(!mpCommand($s,$s->getOpponentKey($actor),'accept_pause',1016)->success,'No duplicate acceptance');
    mpAssert(mpCommand($s,'host','pause_ready',1020)->success,'First ready');
    mpAssert(mpCommand($s,'host','pause_ready',1021)->success && MP::blocking($s),'Ready is set, not toggle');
    mpAssert(mpCommand($s,'player','pause_ready',1022)->success,'Second ready');
    mpAssert($s->battle['match_pause']['mode']==='idle' && TurnTimer::snapshot($s,1032)['remaining_seconds']===160,'Early resume preserves budget');
    $event=$s->battle['match_pause']['last_resume'];
    mpAssert($event['resumed_at']===1022 && $event['reason']==='both_ready','Resume event');
    mpAssert(!MP::expire($s,2000)->success && $event===$s->battle['match_pause']['last_resume'],'Only one exit/event');
}
foreach(['reject_pause','cancel_pause_request','timeout'] as $type){
    $s=mpFixture();mpRequest($s);
    if($type==='timeout') mpAssert(MP::expire($s,1100)->success,'Request timeout');
    else mpAssert(mpCommand($s,$type==='reject_pause'?'player':'host',$type,1020)->success,'Request exit');
    $at=$type==='timeout'?1030:1020;
    mpAssert($s->battle['match_pause']['cooldown_until']['host']===$at+300 && $s->battle['match_pause']['used']['host']===0,'Cooldown and no accepted charge');
    mpAssert(TurnTimer::snapshot($s,1100)['remaining_seconds']===170-(1100-$at),'No offline request refund');
    mpAssert(!mpCommand($s,'host','request_pause',$at+299,['duration_seconds'=>60])->success,'Cooldown enforced');
}
foreach([0,59,61,601,'60.0',[],true,'00060'] as $duration){
    $s=mpFixture();$before=$s->toArray();
    mpAssert(!mpCommand($s,'host','request_pause',1010,['duration_seconds'=>$duration])->success && $before===$s->toArray(),'Invalid duration no mutation');
}
$s=mpFixture();mpRequest($s);mpAssert(!mpCommand($s,'player','request_pause',1011,['duration_seconds'=>60])->success,'No simultaneous second request');
mpAssert(!mpCommand($s,'player','accept_pause',1030)->success,'Late acceptance does not win deadline');
mpAssert(MP::expire($s,2000)->success && $s->battle['match_pause']['last_result']['at']===1030,'Timeout logical timestamp');

$s=mpFixture();mpRequest($s);mpCommand($s,'player','accept_pause',1015);
mpAssert(!mpCommand($s,'host','pause_ready',1075)->success,'Deadline beats readiness at equality');
mpAssert(MP::expire($s,1100)->success,'Late HTTP materializes expiry');
mpAssert(TurnTimer::snapshot($s,1100)['remaining_seconds']===145,'No extra 25 seconds from late HTTP');
mpAssert($s->battle['match_pause']['last_resume']['resumed_at']===1075,'Logical resume deadline');

$s=mpFixture();mpAssert(!mpCommand($s,'host','request_pause',1180,['duration_seconds'=>60])->success,'Eligible expired turn cannot be suspended');
$s=mpFixture();$s->battle['pending_new_unknown']=['owner'=>'host'];
mpAssert(!mpCommand($s,'host','request_pause',1010,['duration_seconds'=>60])->success,'Unknown wait fails closed');

// A real registered pending remains byte-for-byte intact; no CoinAbilities::advance on pause commands.
$s=mpFixture();$s->battle['pending_cell_marker_pick']=['owner'=>'host','sentinel'=>['target'=>2]];
$s->battle['coin_post_combat_continuation']=['type'=>'test_do_not_execute'];
$pending=$s->battle['pending_cell_marker_pick'];$continuation=$s->battle['coin_post_combat_continuation'];
mpRequest($s);mpCommand($s,'player','accept_pause',1011);mpCommand($s,'host','pause_ready',1012);mpCommand($s,'player','pause_ready',1013);
mpAssert($pending===$s->battle['pending_cell_marker_pick'] && $continuation===$s->battle['coin_post_combat_continuation'],'Pending and continuation not advanced');

// Existing opponent wait and 10-second informational ACK retain their original budgets and identity.
$s=mpFixture();$s->battle['strike']=['state'=>'results','confirmed'=>[]];IW::publish($s);IW::reconcile($s,1000);TurnTimer::reconcileWaiting($s,1000);
$timer=$s->battle['turn_timer'];$wait=IW::record($s);mpAssert($timer['pause_reason']==='opponent_wait' && $wait!==null,'Fixture has bound ACK');
mpRequest($s,'host',1003);mpCommand($s,'player','accept_pause',1005);
mpAssert(IW::due($s,5000)===null && !TurnTimeoutEligibility::check($s,5000)['allowed'],'Auto ACK/end blocked');
$before=$s->battle;IW::reconcile($s,5000);TurnTimer::reconcileWaiting($s,5000);mpAssert($s->battle===$before,'Reconcilers cannot change suspended state');
mpCommand($s,'host','pause_ready',1010);mpCommand($s,'player','pause_ready',1011);
mpAssert($s->battle['turn_timer']===$timer,'Already stopped timer not started');
$restored=IW::record($s);mpAssert($restored['wait_id']===$wait['wait_id'] && $restored['deadline_at']===1018,'ACK remaining 7 seconds preserved');
mpAssert(IW::due($s,1017)===null && IW::due($s,1018)!==null,'ACK exact restored deadline');

// Expired clock with a safe mandatory choice may pause, but it never gets a new budget.
$s=mpFixture();$s->battle['pending_cell_marker_pick']=['owner'=>'host'];mpRequest($s,'host',1200);
mpCommand($s,'player','reject_pause',1201);mpAssert($s->battle['turn_timer']['status']==='expired','Expired mandatory clock stays expired');

foreach(['requested','active'] as $mode){
    $s=mpFixture();mpRequest($s);if($mode==='active')mpCommand($s,'player','accept_pause',1011);
    // Even a malformed clock must not put ExpiredCommandGuard ahead of surrender during pause.
    $s->battle['turn_timer']['remaining_seconds']=-1;
    mpAssert(mpCommand($s,'host','resign',1012)->success,'Resign during '.$mode);
    mpAssert($s->status==='game_over' && $s->winner==='player' && !MP::blocking($s),'Terminal pause close');
    mpAssert($s->battle['match_pause']['last_resume']===null && !MP::due($s,99999),'No terminal resume event');
    mpAssert(!mpCommand($s,'player','accept_pause',1013)->success,'Cannot resurrect completed match');
}
$s=mpFixture();
for($i=0;$i<2;++$i){mpRequest($s,'host',1010+$i*10);mpCommand($s,'player','accept_pause',1011+$i*10);mpCommand($s,'host','pause_ready',1012+$i*10);mpCommand($s,'player','pause_ready',1013+$i*10);}
mpAssert(!mpCommand($s,'host','request_pause',1040,['duration_seconds'=>60])->success,'Two accepted pauses per initiator');
mpRequest($s,'player',1040); // independent limit
$before=$s->toArray();$projection=MP::snapshot($s,'player',1041);
$copy=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));
mpAssert(MP::snapshot($copy,'player',1041)===$projection && $s->toArray()===$before,'Refresh projection read-only and stable');
mpAssert(!isset($projection['suspended_timer'],$projection['suspended_wait']) && $projection['allowed_commands']===['cancel_pause_request'],'Public projection excludes internals');
mpAssert(MP::snapshot($s,'host',1041)['allowed_commands']===['accept_pause','reject_pause'],'Recipient projection');

// Zero remaining ACK restores zero, even when request arrived after its old deadline.
$s=mpFixture();$s->battle['strike']=['state'=>'results','confirmed'=>[]];IW::publish($s);IW::reconcile($s,1000);TurnTimer::reconcileWaiting($s,1000);
mpRequest($s,'host',1015);mpCommand($s,'player','reject_pause',1020);
mpAssert(IW::record($s)['deadline_at']===1020 && IW::due($s,1020)!==null,'Overdue ACK restores zero budget');
// Every legal duration is minute-stepped; the cooldown boundary is inclusive.
foreach([60,120,180,240,300,360,420,480,540,600] as $duration){$s=mpFixture();mpRequest($s,'host',1010,$duration);}
$s=mpFixture();TurnTimer::pause($s,1,'foreign_wait',1000);mpRequest($s);mpCommand($s,'player','reject_pause',1020);
mpAssert(!mpCommand($s,'host','request_pause',1319,['duration_seconds'=>60])->success,'Cooldown one second before boundary');
mpAssert(mpCommand($s,'host','request_pause',1320,['duration_seconds'=>60])->success,'Cooldown exactly at boundary');
echo "Match pause unit tests passed ($count assertions)\n";
