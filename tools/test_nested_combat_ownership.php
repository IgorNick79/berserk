<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, BattleWaitResolver, CardInstance, Command, Dice, Engine, GameState, TurnTimer};
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function ncAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function ncApply(Engine $engine, GameState $s, string $actor, string $type, array $payload=[]): void {
    if ($type==='confirm_strike') $payload['wait_id']=\Berserk\Core\InformationalWaitManager::token($s);
    $r=$engine->apply($s,$actor,new Command($type,$payload));
    ncAssert($r->success,$type.': '.$r->error);
}
function ncProjection(GameState $s, string $source, string $owner): void {
    $before=serialize($s);$p=BattleWaitResolver::current($s);
    ncAssert(serialize($s)===$before,'Projection mutates state');
    ncAssert($p['kind']==='decision' && $p['source']===$source && $p['required_players']===[$owner],
        'Wrong actual command owner: '.json_encode($p));
}
function ncState(string $attacker, string $case): GameState {
    $s=new GameState(-random_int(1000000000,2000000000),101,202);$s->status='battle';
    $s->players[$attacker]->side=1;$s->players[$s->getOpponentKey($attacker)]->side=2;
    $s->battle=['active'=>$attacker,'turn'=>1,'strike'=>null,'hidden_row_revealed'=>true];
    $prop=match($case) {
        'auto'=>['auto'=>[['trigger'=>'strike_hit','type'=>'shot','value'=>1,'range'=>0]]],
        'push'=>['strike_effects'=>[['push'=>2]]],
        'close'=>['strike_effects'=>[['close_or_damage'=>['damage'=>2]]]],
        'ally'=>['strike_effects'=>[['grant_ally_modifier'=>['filter'=>'has_magic','modifier'=>['stat'=>'damage_reduction','value'=>1]]]]],
        'death'=>[],
    };
    foreach ([1=>[$attacker,3,3],2=>[$s->getOpponentKey($attacker),4,3],
        3=>[$attacker,2,2],4=>[$s->getOpponentKey($attacker),6,5]] as $id=>[$owner,$row,$col]) {
        $hp=$case==='death' && $id===2?1:20;
        $properties=$id===1?$prop:($id===3?['actions'=>[['key'=>'magic','type'=>'magic','value'=>1]]]:[]);
        if ($case==='death' && $id===2) $properties=['on_death'=>[['target'=>'choice','type'=>'damage','value'=>1]]];
        $s->addCard(new CardInstance(instanceId:$id,ukid:'nested_'.$id,owner:$owner,zone:CardInstance::ZONE_FIELD,
            row:$row,col:$col,hp:$hp,hpMax:$hp,move:1,moveMax:1,strikeWeak:1,strikeMedium:2,strikeStrong:3,prop:$properties));
    }
    TurnTimer::prepare($s,$attacker);TurnTimer::resume($s,1,'start_phase',1000);
    return $s;
}

$now=1000;$engine=new Engine(null,static function () use (&$now): int {return $now;});
$cases=[
    'auto'=>['waiting_auto_target','pending_auto','source_id','choose_auto_target',['target_id'=>4]],
    'push'=>['waiting_push_choice','pending_push','defender_id','choose_push_choice',['choice'=>'damage']],
    'close'=>['waiting_close_or_damage','pending_close_or_damage','defender_id','choose_close_or_damage',['choice'=>'damage']],
    'ally'=>['waiting_ally_modifier','pending_ally_modifier','source_id','choose_ally_modifier',['target_id'=>3]],
    'death'=>['results','pending_choice','died_id','choose_death_target',['target_id'=>1]],
];
$sessionBefore=$_SESSION??[];
try {
    foreach (['host','player'] as $attacker) foreach ($cases as $case=>[$stage,$key,$field,$command,$payload]) {
        $s=ncState($attacker,$case);$opponent=$s->getOpponentKey($attacker);
        // Use the existing debug dice path, never modify a resolved strike or inject a pending.
        $_SESSION['debug_roll']='6,1';unset($_SESSION['debug_roll_state']);Dice::init();
        $now=1010;ncApply($engine,$s,$attacker,'strike',['card_id'=>1,'target_id'=>2]);
        ncAssert($s->battle['strike']['state']==='results','Real strike must reach results');
        $firstWait=\Berserk\Core\InformationalWaitManager::token($s);
        if ($case!=='death') {
            $now=1020;ncApply($engine,$s,$opponent,'confirm_strike');
            ncAssert($s->battle['turn_timer']['status']==='running','Opponent ACK must return control to owner');
            $now=1030;ncApply($engine,$s,$attacker,'confirm_strike');
        }
        ncAssert($s->battle['strike']['state']===$stage && !empty($s->battle['strike'][$key]),'Engine failed to create '.$case);
        $expectedOwner=in_array($case,['auto','ally'],true)?$attacker:$opponent;
        ncProjection($s,'strike.'.$key,$expectedOwner);
        ncAssert(\Berserk\Core\InformationalWaitManager::due($s,9999)===null
            && !isset($s->battle['informational_wait']),'Nested decision cannot retain a P2 deadline');
        $id=$s->battle['strike'][$key][$field];
        ncAssert($s->getCard($id)->owner===$expectedOwner,'Created pending references the authorized card');
        $missing=GameState::fromArray($s->toArray());unset($missing->cards[$id]);
        $beforeMissing=serialize($missing);$unknown=BattleWaitResolver::current($missing);
        ncAssert($unknown['kind']==='unknown' && $unknown['required_players']===[] && !empty($unknown['reason']),
            'Missing authorization card must not fall back to attacker/active');
        ncAssert(serialize($missing)===$beforeMissing,'Unknown projection must be read-only');
        $missingClock=$missing->battle['turn_timer'];TurnTimer::reconcileWaiting($missing,1030);
        ncAssert($missing->battle['turn_timer']===$missingClock,'Unknown must preserve timer mode');
        $clock=$s->battle['turn_timer'];
        ncAssert($clock['status']===($expectedOwner===$attacker?'running':'paused'),'Incorrect nested timer mode');
        if ($expectedOwner!==$attacker) ncAssert($clock['pause_reason']==='opponent_wait','Foreign decision needs opponent_wait');

        $now=1040;$pendingBefore=$s->battle['strike'][$key];
        $wrong=$engine->apply($s,$s->getOpponentKey($expectedOwner),new Command($command,$payload));
        ncAssert(!$wrong->success && $wrong->error==='Не ваш выбор','Actual command must reject wrong actor: '.$case);
        ncAssert($s->battle['strike'][$key]===$pendingBefore,'Rejected actor consumed pending');
        ncProjection($s,'strike.'.$key,$expectedOwner);

        // Serialize a real pending and re-check that no fallback to battle.active occurs.
        $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));
        ncProjection($s,'strike.'.$key,$expectedOwner);
        // T3: the same real nested workflow remains completable with a persisted
        // expired timer. Work on a clone so the P1 accounting assertions stay intact.
        $expired=GameState::fromArray($s->toArray());
        $expired->battle['turn_timer']=array_replace($expired->battle['turn_timer'],[
            'status'=>'expired','remaining_seconds'=>0,'anchor_at'=>null,'pause_reason'=>null]);
        $expiredEngine=new Engine(null,static fn():int=>1100);
        ncApply($expiredEngine,$expired,$expectedOwner,$command,$payload);
        ncAssert(empty($expired->battle['strike'][$key]),'Expired clock must allow real nested continuation: '.$case);
        if(!empty($expired->battle['strike'])){
            ncApply($expiredEngine,$expired,$opponent,'confirm_strike');
            ncApply($expiredEngine,$expired,$attacker,'confirm_strike');
        }
        ncAssert(\Berserk\Core\TurnTimeoutEligibility::check($expired,1100)['allowed'],'Nested workflow releases timeout after final ACK: '.$case);
        $now=1100;ncApply($engine,$s,$expectedOwner,$command,$payload);
        ncAssert(empty($s->battle['strike'][$key]),'Authorized decision must consume pending: '.$case);
        $expectedRemaining=$expectedOwner===$attacker?90:($case==='death'?170:160);
        ncAssert($s->battle['turn_timer']['remaining_seconds']===$expectedRemaining,'Wrong time charged for '.$case);
        ncAssert($s->battle['turn_timer']['period_id']===1 && $s->battle['turn_timer']['owner']===$attacker,'Decision replaced timer period');
        if ($case==='auto') {
            ncAssert(empty($s->battle['strike']) && $s->battle['turn_timer']['status']==='running','Auto target completion resumes combat owner');
        } else {
            $p=BattleWaitResolver::current($s);
            ncAssert($p['kind']==='informational' && $p['required_players']===['host','player'],'Nested decision must reveal parent result');
            $info=$s->battle['informational_wait'];
            ncAssert($info['started_at']===1100 && $info['deadline_at']===1110
                && (string)$info['wait_id']!==$firstWait,'Real nested continuation publishes a fresh P2 window');
            ncAssert($s->battle['turn_timer']['status']==='paused','Result still requires opponent: no premature resume');
            $now=1200;ncApply($engine,$s,$opponent,'confirm_strike');
            ncAssert($s->battle['turn_timer']['status']==='running' && $s->battle['turn_timer']['remaining_seconds']===$expectedRemaining,'Opponent ACK resumes saved budget');
            $now=1210;ncApply($engine,$s,$attacker,'confirm_strike');
            ncAssert(empty($s->battle['strike']) && $s->battle['turn_timer']['remaining_seconds']===$expectedRemaining-10,'Final ACK follows existing combat lifecycle');
        }
        echo $case.' attacker='.$attacker.' owner='.$expectedOwner." passed\n";
    }
} finally {
    $_SESSION=$sessionBefore;Dice::init();
    restore_error_handler();
}
echo "Nested combat ownership verified through Engine (10 deterministic real combat paths)\n";
