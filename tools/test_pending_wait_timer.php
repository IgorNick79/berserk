<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, BattleWaitResolver, CardInstance, Command, Engine, GameState, InstantProcessor, TurnProcessor, TurnTimer};
use Berserk\Core\Choice\ChoiceRegistry;
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function pwAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function pwState(): GameState {
    $s = new GameState(-random_int(1000000000, 2000000000), 101, 202);
    $s->status = 'battle'; $s->players['host']->side = 1; $s->players['player']->side = 2;
    $s->battle = ['active'=>'host', 'turn'=>1, 'strike'=>null, 'hidden_row_revealed'=>true];
    foreach ([1=>'host', 2=>'player'] as $id=>$owner) {
        $s->addCard(new CardInstance(instanceId:$id, ukid:'wait_'.$id, owner:$owner,
            zone:CardInstance::ZONE_FIELD, row:$id===1?3:4, col:3, hp:20, hpMax:20,
            move:1, moveMax:1, strikeWeak:1, strikeMedium:2, strikeStrong:3));
    }
    TurnTimer::prepare($s, 'host'); TurnTimer::resume($s, 1, 'start_phase', 1000);
    return $s;
}
function pwWait(GameState $s, ?string $kind, array $players = [], ?string $source = null): void {
    $before = serialize($s);
    $wait = BattleWaitResolver::current($s);
    pwAssert(serialize($s)===$before, 'Projection must be entirely read-only');
    pwAssert(($wait['kind']??null)===$kind, 'Unexpected kind '.json_encode($wait));
    pwAssert(($wait['required_players']??[])===$players, 'Unexpected required players '.json_encode($wait));
    if ($source!==null) pwAssert($wait['source']===$source, 'Unexpected priority '.json_encode($wait));
    if ($kind==='unknown') pwAssert(!empty($wait['reason']), 'Unknown requires diagnostics');
}

// The fixtures are an explicit contract for every registered key. Card IDs deliberately
// disagree with a misleading owner/active field to catch fallback heuristics.
$owned = [
    'pending_deal_linked_recruit','pending_deal_scout_recruit','pending_deal_variable_recruit',
    'pending_coin_ability','pending_first_strike_jump','pending_dice_choice','pending_after_strike_execute','pending_cell_marker_pick',
    'pending_instant_pick','pending_combat_pick','pending_forced_strike_adjacent','pending_ranged_attack_redirect',
    'pending_incarnation','pending_valhalla_pick','pending_whip','pending_kobold_heal','pending_talion_incarnation',
    'pending_holvert_open','pending_anubisar_chain','pending_wound_transfer','pending_segger_shield',
    'pending_dverg_gift','pending_shaksi_roll','pending_heal_instead_wound','pending_attack_series_choice',
    'pending_wild_healer_move','pending_forced_directional_move','pending_forced_strike','pending_row_pick',
    'pending_opponent_row_marker','pending_row_spell_pick','pending_gate_pick','pending_greed_teleport',
    'pending_destroy_self_and_target','pending_particle_pick','pending_blood_storm','pending_life_gift','pending_teleport_target','pending_ranged_neighbor_teleport',
];
$cardOwned = ['pending_coin_spend'=>'attacker_id','pending_multi_heal'=>'attacker_id',
    'pending_multi_discharge'=>'attacker_id','pending_self_wound'=>'attacker_id','pending_blood_tap'=>'attacker_id',
    'pending_grezy'=>'attacker_id','pending_card_choice'=>'card_id','pending_revive'=>'healer_id'];
$queues = ['pending_incarnation_wound','pending_nokami_wound','pending_intrigue_start'];
foreach (['host','player'] as $owner) {
    foreach (array_merge($owned,array_keys($cardOwned),$queues,['pending_any_death','pending_degasca_sequence']) as $key) {
        $s = pwState(); $s->battle['active'] = $s->getOpponentKey($owner);
        $source = $owner==='host'?1:2;
        if (in_array($key,$owned,true)) $p=['owner'=>$owner];
        elseif (isset($cardOwned[$key])) $p=[$cardOwned[$key]=>$source,'owner'=>$s->getOpponentKey($owner)];
        elseif (in_array($key,$queues,true)) $p=[['owner'=>$owner],['owner'=>$s->getOpponentKey($owner)]];
        elseif ($key==='pending_any_death') $p=[['source_id'=>$source,'owner'=>$s->getOpponentKey($owner)]];
        else $p=['owner'=>$owner,'stage'=>'choose_card'];
        $s->battle[$key]=$p;
        pwWait($s,'decision',[$owner]);
        TurnTimer::settle($s,1030); TurnTimer::reconcileWaiting($s,1030);
        $clock=$s->battle['turn_timer'];
        pwAssert($clock['period_id']===1 && $clock['remaining_seconds']===150, 'Pause cannot reset period/budget: '.$key);
        pwAssert($clock['status']===($owner==='player'?'paused':'running'), 'Wrong timer mode: '.$key);
        pwAssert(TurnTimer::snapshot($s,1100)['remaining_seconds']===($owner==='player'?150:80), 'Wrong elapsed projection: '.$key);
    }
}
$covered=array_merge($owned,array_keys($cardOwned),$queues,['pending_any_death','pending_degasca_sequence',
    'pending_incarnation_ack','turn_phase','turn_instant_result','turn_instant_stack']);
foreach (ChoiceRegistry::all() as $handler) pwAssert(in_array($handler->pendingKey(),$covered,true),'New handler requires ownership coverage: '.get_class($handler));

// Merge integration: Swamp Children switches decision owner between jump and pull.
foreach ([1030,1200] as $now) {
    $s=pwState();$engine=new Engine(null,static function()use(&$now):int{return $now;});
    $jump=new Berserk\Core\FirstStrikeJump($s,$engine);
    pwAssert($jump->openAfterCombat($s->getCard(2),['range'=>2,'pull_adjacent_enemy'=>true]),'Real mandatory jump opens');
    TurnTimer::reconcileWaiting($s,$now);
    pwWait($s,'decision',['player'],'pending_first_strike_jump');
    $expired=$now===1200;
    pwAssert($s->battle['turn_timer']['status']===($expired?'expired':'paused'),'Opponent jump pauses only a live timer');
    $now+=10;$p=$s->battle['pending_first_strike_jump'];
    pwAssert($engine->apply($s,'player',new Command('choose_first_strike_movement',[
        'choice_id'=>$p['id'],'row'=>2,'col'=>3]))->success,'Jump completes, including after zero');
    pwWait($s,'decision',['host'],'pending_first_strike_jump');
    pwAssert($s->battle['turn_timer']['status']===($expired?'expired':'running'),'Own pull resumes the same timer');
    $now+=10;$p=$s->battle['pending_first_strike_jump'];
    pwAssert($engine->apply($s,'host',new Command('choose_first_strike_movement',[
        'choice_id'=>$p['id'],'target_id'=>1]))->success,'Pull completes, including after zero');
    pwAssert(empty($s->battle['pending_first_strike_jump'])
        && TurnTimer::snapshot($s,$now)['remaining_seconds']===($expired?0:140),'Decision handoff preserves budget');
}

foreach (['waiting_defender','waiting_redirect','waiting_choice','waiting_auto_target','waiting_push_choice',
    'waiting_close_or_damage','waiting_ally_modifier','waiting_instant'] as $stage) {
    $s=pwState();$s->battle['strike']=['state'=>$stage,'attacker_id'=>1,'choice_winner'=>'defend',
        'instant_priority'=>'player','instant_participants'=>['host','player']];
    $nested=['waiting_auto_target'=>['pending_auto',['source_id'=>2]],'waiting_push_choice'=>['pending_push',['defender_id'=>2]],
        'waiting_close_or_damage'=>['pending_close_or_damage',['defender_id'=>2]],'waiting_ally_modifier'=>['pending_ally_modifier',['source_id'=>2]]];
    if(isset($nested[$stage])) $s->battle['strike'][$nested[$stage][0]]=$nested[$stage][1];
    pwWait($s,'decision',['player']);
}
foreach (['results','waiting_push_ack'] as $stage) foreach ([[],['host'],['player'],['host','player']] as $confirmed) {
    $s=pwState();$s->battle['strike']=['state'=>$stage,'confirmed'=>$confirmed];
    pwWait($s,'informational',array_values(array_diff(['host','player'],$confirmed)));
    TurnTimer::reconcileWaiting($s,1000);
    pwAssert($s->battle['turn_timer']['status']===(in_array('player',$confirmed,true)?'running':'paused'),'Only unconfirmed opponent pauses result');
}
$s=pwState();$s->battle['strike']=['state'=>'results','confirmed'=>[], 'pending_choice'=>['died_id'=>1]];
pwWait($s,'decision',['host'],'strike.pending_choice');
TurnTimer::reconcileWaiting($s,1000);pwAssert($s->battle['turn_timer']['status']==='running','Nested own death decision overrides parent ACK');
$s->battle['pending_combat_pick']=['owner'=>'player'];pwWait($s,'decision',['player'],'pending_combat_pick');
$s->battle['pending_coin_ability']=['owner'=>'host'];pwWait($s,'decision',['host'],'pending_coin_ability');

foreach (['active','passive'] as $side) foreach ([false,true] as $sub) {
    $s=pwState();$s->battle['turn_phase']=['phase'=>'end','side'=>$side,'active_key'=>'host','passive_key'=>'player',
        'active_queue'=>[['id'=>'a'],['id'=>'b']], 'passive_queue'=>[['id'=>'c'],['id'=>'d']],
        'sub'=>$sub?['parent_type'=>'prophecy','remaining'=>[['id'=>'p']]]:null];
    pwWait($s,'decision',[$side==='active'?'host':'player']);
}
foreach (['host','player'] as $priority) {
    $s=pwState();$s->battle['turn_instant_stack']=['state'=>'ordering','priority'=>$priority,'participants'=>['host','player']];
    pwWait($s,'decision',[$priority]);
    $s->battle['pending_instant_pick']=['owner'=>$s->getOpponentKey($priority)];
    pwWait($s,'decision',[$s->getOpponentKey($priority)],'pending_instant_pick');
}
foreach ([null,[]] as $participants) {
    $s=pwState();$s->battle['turn_instant_stack']=['state'=>'ordering','priority'=>'player','participants'=>$participants];
    pwWait($s,'decision',['player']);
    unset($s->battle['turn_instant_stack']);
    $s->battle['strike']=['state'=>'waiting_instant','attacker_id'=>1,'instant_priority'=>'host','friendly_fire'=>true,'instant_participants'=>$participants];
    pwWait($s,'decision',['host']);
    $s->battle['strike']['instant_priority']='player';pwWait($s,'unknown');
}
foreach ([['reorder_mode'=>true],['summon_mode'=>true,'summon_step'=>'cell'],
    ['summon_mode'=>true,'summon_step'=>'creature'],['actions'=>[['cmd'=>'transform_seeker'],['cmd'=>'close_prophecy']]],
    ['actions'=>[['cmd'=>'summon_start'],['cmd'=>'close_prophecy']]],['actions'=>[['cmd'=>'reorder_start'],['cmd'=>'close_prophecy']]]] as $p) {
    $s=pwState();$s->battle['pending_prophecy']=['owner'=>'player']+$p;pwWait($s,'decision',['player']);
    $s->battle['strike']=['state'=>'results','confirmed'=>[]];pwWait($s,'decision',['player']);
}
$s=pwState();$s->battle['pending_dive']=['owner'=>'player'];pwWait($s,'decision',['player'],'pending_dive');

// Unknowns must not invent ownership, pause a running clock or release a paused one.
$unknowns=[
    ['pending_incarnation_ack'=>true], ['turn_instant_result'=>['summary'=>[]]],
    ['turn_phase'=>['pending_ack'=>['label'=>'result']]], ['pending_prophecy'=>['owner'=>'player','actions'=>[['cmd'=>'close_prophecy']]]],
    ['pending_prophecy'=>['owner'=>'player','actions'=>[['cmd'=>'grezy_continue']]]],
    ['pending_prophecy'=>['owner'=>'player','summon_mode'=>true,'summon_step'=>'new_stage']],
    ['pending_future'=>['owner'=>'player']], ['pending_whip'=>['owner'=>'spectator']], ['pending_whip'=>true],
    ['pending_card_choice'=>['card_id'=>999]], ['pending_incarnation_wound'=>[['owner'=>null]]],
    ['turn_phase'=>['side'=>'invalid']], ['turn_phase'=>['side'=>'active','active_queue'=>'broken']],
    ['strike'=>['state'=>'new_stage']], ['strike'=>true], ['strike'=>['state'=>'waiting_auto_target']],
    ['strike'=>['state'=>'results','pending_auto'=>['source_id'=>2]]],
    ['strike'=>['state'=>'results','pending_future'=>['owner'=>'player']]],
    ['strike'=>['state'=>'results','confirmed'=>[['host']]]],
    ['strike'=>['state'=>'waiting_instant','instant_priority'=>'player','instant_participants'=>['host']]],
    ['turn_instant_stack'=>['state'=>'ordering','priority'=>'player','participants'=>[['player']]]],
    ['turn_instant_stack'=>['state'=>'resolving']], ['pending_degasca_sequence'=>['owner'=>'player','stage'=>'effects']],
    ['pending_degasca_deferred_sequences'=>[['owner'=>'player']]], ['coin_post_combat_continuation'=>['attacker_id'=>1]],
];
foreach ($unknowns as $fragment) foreach (['running','paused'] as $mode) {
    $s=pwState();$s->battle=array_replace($s->battle,$fragment);
    if ($mode==='paused') TurnTimer::pause($s,1,'opponent_wait',1000);
    pwWait($s,'unknown');$before=$s->battle['turn_timer'];
    TurnTimer::reconcileWaiting($s,1100);pwAssert($s->battle['turn_timer']===$before,'Unknown changed clock mode or budget');
}
foreach (['start_phase','start_resolution','end_phase','battle_finished','other_lifecycle'] as $reason) {
    $s=pwState();TurnTimer::pause($s,1,$reason,1010);$before=$s->battle['turn_timer'];
    TurnTimer::reconcileWaiting($s,1100);pwAssert($s->battle['turn_timer']===$before,'Special pause must survive no wait');
    $s->battle['pending_whip']=['owner'=>'player'];TurnTimer::reconcileWaiting($s,1200);
    pwAssert($s->battle['turn_timer']===$before,'Special pause must not be overwritten by opponent wait');
}
$s=pwState();unset($s->battle['turn_timer']);$before=serialize($s);TurnTimer::reconcileWaiting($s,1000);
pwAssert(serialize($s)===$before,'Legacy state must not allocate a clock');

// End-to-end commands, controlled server clock, no sleep or replacement gameplay executor.
$now=1000;$engine=new Engine(null,static function () use (&$now): int {return $now;});
function pwApply(Engine $engine,GameState $s,string $owner,string $type,array $payload=[]): void {
    if ($type==='confirm_strike') $payload['wait_id']=\Berserk\Core\InformationalWaitManager::token($s);
    $r=$engine->apply($s,$owner,new Command($type,$payload));pwAssert($r->success,$type.': '.$r->error);
}
foreach (['host','player'] as $firstAck) {
    $now=1010;$s=pwState();$s->getCard(1)->prop['actions']=[['key'=>'bonfire','type'=>'place_cell_marker','marker'=>'bonfire']];
    pwApply($engine,$s,'host','action',['card_id'=>1,'action_key'=>'bonfire']);
    pwAssert($s->battle['turn_timer']['status']==='running','Own newly opened pending spends time');
    $now=1020;pwApply($engine,$s,'host','cancel_pending');
    pwAssert($s->battle['turn_timer']['remaining_seconds']===160,'Own cancellation cannot refund time');
    pwApply($engine,$s,'host','action',['card_id'=>1,'action_key'=>'bonfire']);
    $now=1030;pwApply($engine,$s,'host','choose_cell_marker',['row'=>3,'col'=>2]);
    pwAssert($s->battle['turn_timer']['pause_reason']==='opponent_wait','New result pauses for opponent ACK');
    $now=1200;pwApply($engine,$s,$firstAck,'confirm_strike');
    pwAssert($s->battle['turn_timer']['status']===($firstAck==='player'?'running':'paused'),'ACK order controls remaining obligation');
    if ($firstAck==='player') pwAssert(!empty($s->battle['strike']),'Combat itself must not pause clock');
    $now=1210;pwApply($engine,$s,$s->getOpponentKey($firstAck),'confirm_strike');
    pwAssert(empty($s->battle['strike']) && $s->battle['turn_timer']['status']==='running','Combat closes through existing Engine');
    pwAssert($s->battle['turn_timer']['remaining_seconds']===($firstAck==='player'?140:150),'ACK wait must not consume paused budget');
}

// Queue head ownership, reload, repeat rejection, foreign cancellation and next pending.
foreach (['player','host'] as $secondOwner) {
    $s=pwState();$s->battle['pending_any_death']=[['source_id'=>2,'candidates'=>[1]],['source_id'=>$secondOwner==='host'?1:2,'candidates'=>[1]]];
    TurnTimer::reconcileWaiting($s,1000);$now=1100;
    $s=GameState::fromArray(json_decode(json_encode($s->toArray(),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));
    pwAssert(TurnTimer::snapshot($s,1100)['remaining_seconds']===180,'Reload preserves pause');
    pwApply($engine,$s,'player','choose_any_death_target',['target_id'=>0]);
    pwAssert($s->battle['turn_timer']['status']===($secondOwner==='player'?'paused':'running'),'Next queue head owns clock decision');
    $now=1120;pwApply($engine,$s,$secondOwner,'choose_any_death_target',['target_id'=>0]);
    pwAssert($s->battle['turn_timer']['status']==='running','Empty queue releases pause');
}
$s=pwState();$s->battle['pending_cell_marker_pick']=['owner'=>'player'];TurnTimer::reconcileWaiting($s,1000);
$now=1100;pwAssert(!$engine->apply($s,'host',new Command('cancel_pending'))->success,'Foreign cancellation rejected');
pwAssert($s->battle['turn_timer']['status']==='paused','Failed command cannot release pause');
pwApply($engine,$s,'player','cancel_pending');pwAssert($s->battle['turn_timer']['anchor_at']===1100,'Foreign cancellation resumes same period');
pwAssert(!$engine->apply($s,'player',new Command('cancel_pending'))->success,'Repeated operation remains rejected');

// Real combat declaration transfers the defender decision and combat instant priority.
$s=pwState();$s->addCard(new CardInstance(instanceId:3,ukid:'defender',owner:'player',zone:CardInstance::ZONE_FIELD,
    row:4,col:4,hp:20,hpMax:20,strikeWeak:1,strikeMedium:2,strikeStrong:3));
$now=1010;pwApply($engine,$s,'host','strike',['card_id'=>1,'target_id'=>2]);
pwAssert($s->battle['strike']['state']==='waiting_defender' && $s->battle['turn_timer']['status']==='paused','Declared combat waits on opponent');
$now=1100;pwApply($engine,$s,'player','choose_defender',['defender_id'=>0]);
pwAssert($s->battle['turn_timer']['remaining_seconds']===170,'Defender decision did not consume paused time');

$s=pwState();
foreach ([1,2] as $id) $s->getCard($id)->prop['instants']=[['key'=>'test','name'=>'test','trigger'=>'turn','target'=>'self','uses_per_turn'=>1,'effect'=>['type'=>'heal','value'=>1]]];
$now=1010;pwApply($engine,$s,'host','open_turn_instants');
pwAssert($s->battle['turn_timer']['status']==='running','Own instant priority runs');
$now=1020;pwApply($engine,$s,'host','pass_turn_instant');
pwAssert($s->battle['turn_instant_stack']['priority']==='player' && $s->battle['turn_timer']['status']==='paused','Turn priority transfers to opponent');
$now=1200;pwApply($engine,$s,'player','pass_turn_instant');
pwAssert(empty($s->battle['turn_instant_stack']) && $s->battle['turn_timer']['status']==='running'
    && $s->battle['turn_timer']['remaining_seconds']===160,'Empty stack resumes without ACK or new budget');

$s=pwState();$s->battle['strike']=['state'=>'waiting_instant','attacker_id'=>1,'target_id'=>2,'defender_id'=>null,'instant_priority'=>'host',
    'instant_participants'=>['host','player'],'instant_passed'=>[],'instant_stack'=>[],'instant_phase'=>'combat'];
$now=1010;pwApply($engine,$s,'host','combat_instant_pass');
pwAssert($s->battle['strike']['instant_priority']==='player' && $s->battle['turn_timer']['status']==='paused','Combat instant priority pauses');
$s->getCard(2)->prop['instants']=[['key'=>'enemy_pick','name'=>'pick','trigger'=>'combat','combat_phase'=>'main',
    'target'=>'enemy','effect'=>['type'=>'damage_cap','value'=>1]]];
$now=1100;pwApply($engine,$s,'player','combat_instant_play',['card_id'=>2,'instant_key'=>'enemy_pick']);
pwWait($s,'decision',['player'],'pending_combat_pick');
pwAssert($s->battle['turn_timer']['remaining_seconds']===170,'Nested enemy instant pick keeps pause');
$now=1200;pwApply($engine,$s,'player','choose_combat_pick',['target_id'=>1]);
pwAssert($s->battle['turn_timer']['status']==='paused','Completing pick must not resume while enemy still has priority');

// Removing a higher-priority choice must reveal, not bypass, the nested death decision.
$s=pwState();$s->battle['strike']=['state'=>'results','confirmed'=>[],
    'pending_choice'=>['died_id'=>2,'died_ukid'=>'wait_2','effect'=>['type'=>'damage','value'=>1],'candidates'=>[1]]];
$s->battle['pending_cell_marker_pick']=['owner'=>'player'];TurnTimer::reconcileWaiting($s,1000);
$now=1100;pwApply($engine,$s,'player','cancel_pending');pwWait($s,'decision',['player'],'strike.pending_choice');
pwAssert($s->battle['turn_timer']['status']==='paused','Nested enemy death decision prevents premature resume');
$now=1200;pwApply($engine,$s,'player','choose_death_target',['target_id'=>1]);
pwWait($s,'informational',['host','player']);
pwAssert($s->battle['turn_timer']['status']==='paused','Parent ACK remains after nested decision');

// Unknown after a successful command must preserve an existing pause, then a definitive ACK can release it.
$s=pwState();TurnTimer::pause($s,1,'opponent_wait',1000);
$s->battle['pending_cell_marker_pick']=['owner'=>'player'];$s->battle['pending_incarnation_ack']=true;
$now=1050;pwApply($engine,$s,'player','cancel_pending');pwWait($s,'unknown');
pwAssert($s->battle['turn_timer']['pause_reason']==='opponent_wait','Unknown cannot cause premature resume');
$now=1100;pwApply($engine,$s,'host','incarnation_ack');pwAssert($s->battle['turn_timer']['status']==='running','Definitive absence of wait releases pause');

$s=pwState();$s->battle['pending_whip']=['owner'=>'player'];TurnTimer::reconcileWaiting($s,1010);
pwAssert(TurnTimer::snapshot($s,9999)['remaining_seconds']===170,'Foreign wait cannot expire a paused timer');
$now=9999;pwApply($engine,$s,'player','resign');
pwAssert($s->status==='game_over' && $s->battle['turn_timer']['pause_reason']==='battle_finished','Battle completion owns its special pause');
$s=pwState();TurnTimer::settle($s,1180);$s->battle['pending_whip']=['owner'=>'player'];TurnTimer::reconcileWaiting($s,1180);
pwAssert($s->battle['turn_timer']['status']==='expired' && $s->battle['active']==='host','Already expired clock cannot restart or end turn');
$s=pwState();$now=1010;pwApply($engine,$s,'host','end_turn');
pwAssert($s->battle['turn_timer']['period_id']===2 && $s->battle['turn_timer']['owner']==='player','Existing end/start lifecycle creates next period');
$s=pwState();$s->getCard(2)->prop['pre_turn_start']=['options'=>[['modifiers'=>[]]]];
$now=1020;pwApply($engine,$s,'host','end_turn');
pwAssert($s->battle['turn_timer']['pause_reason']==='start_phase','Next period preserves start pause despite own pending');
$now=1200;pwApply($engine,$s,'player','choose_card_option',['option_index'=>0]);
pwAssert($s->battle['turn_timer']['anchor_at']===1200 && $s->battle['turn_timer']['remaining_seconds']===180,'Start effects finish through existing lifecycle');
$s=pwState();unset($s->battle['turn_timer']);
$s->getCard(1)->prop['actions']=[['key'=>'bonfire','type'=>'place_cell_marker','marker'=>'bonfire']];
pwApply($engine,$s,'host','action',['card_id'=>1,'action_key'=>'bonfire']);
pwApply($engine,$s,'host','choose_cell_marker',['row'=>3,'col'=>2]);
pwAssert(!isset($s->battle['turn_timer']),'Engine commands must not allocate a legacy clock');

echo "Pending ownership/read-only projection/Engine timer tests passed (all 57 registered handlers covered)\n";
