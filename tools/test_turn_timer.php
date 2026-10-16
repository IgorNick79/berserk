<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Autoloader.php';
use Berserk\Core\{Autoloader, GameState, Engine, TurnProcessor, TurnPhaseProcessor, TurnTimer, Command, CardInstance};
Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');
function ttAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$now = 1000;
$engine = new Engine(null, static function () use (&$now): int { return $now; });
$s = new GameState(-918273, 101, 202);
$s->status = 'battle';
$s->players['host']->side = 1;
$s->players['player']->side = 2;
$turn = new TurnProcessor($s, $engine);
$turn->startBattle();
ttAssert($s->battle['turn_timer'] === [
    'period_id'=>1, 'owner'=>'host', 'status'=>'running', 'remaining_seconds'=>180,
    'anchor_at'=>1000, 'pause_reason'=>null,
], 'First host period starts after empty start phase');
$now = 1030;
$turn->afterStartPhase('host');
ttAssert($s->battle['turn_timer']['remaining_seconds'] === 150, 'Repeated start cannot refund');
$json = json_encode($s->toArray(), JSON_THROW_ON_ERROR);
$s = GameState::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
$turn = new TurnProcessor($s, $engine);
$before = $s->toArray();
$snap = TurnTimer::snapshot($s, 1040);
ttAssert($snap['remaining_seconds'] === 140 && $snap['server_now'] === 1040, 'Restored anchor counts elapsed time');
ttAssert($s->toArray() === $before, 'Snapshot is read-only');
TurnTimer::pause($s, 1, 'foreign_wait', 1040);
TurnTimer::pause($s, 1, 'foreign_wait', 1080);
ttAssert(TurnTimer::snapshot($s, 2000)['remaining_seconds'] === 140, 'Pause does not consume time');
$s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
TurnTimer::resume($s, 1, 'wrong_wait', 2000);
ttAssert($s->battle['turn_timer']['status'] === 'paused', 'Only matching pause can resume');
TurnTimer::resume($s, 1, 'foreign_wait', 2000);
TurnTimer::resume($s, 1, 'foreign_wait', 2010);
TurnTimer::settle($s, 2010);
ttAssert($s->battle['turn_timer']['remaining_seconds'] === 130, 'Resume is idempotent and keeps budget');
TurnTimer::settle($s, 2005);
ttAssert($s->battle['turn_timer']['remaining_seconds'] === 130, 'Backwards clock cannot refund');
TurnTimer::settle($s, 2140);
ttAssert($s->battle['turn_timer']['status'] === 'expired' && $s->battle['turn_timer']['remaining_seconds'] === 0, 'Exact deadline expires');
$s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
TurnTimer::settle($s, 2140);
ttAssert($s->battle['turn_timer']['status'] === 'expired', 'Expired state survives serialization and repeated settlement');
TurnTimer::resume($s, 1, 'foreign_wait', 2200);
ttAssert($s->battle['active'] === 'host' && $s->battle['turn_timer']['status'] === 'expired', 'Expiry neither advances turn nor resumes');
$now = 2200;
$turn = new TurnProcessor($s, $engine);
ttAssert($turn->endTurn('host', new Command('end_turn'))->success, 'Normal end turn remains available');
ttAssert($s->battle['turn_timer']['period_id'] === 2 && $s->battle['turn_timer']['owner'] === 'player', 'Guest gets separate period');
ttAssert($s->battle['turn_timer']['remaining_seconds'] === 180, 'Next period gets full budget');
(new TurnProcessor($s, $engine))->afterStartPhase('host');
ttAssert($s->battle['turn_timer']['period_id'] === 2, 'Stale start callback cannot replace a newer period');
TurnTimer::pause($s, 1, 'stale_wait', 2200);
ttAssert($s->battle['turn_timer']['status'] === 'running', 'Stale period operation ignored');
$now = 2210;
$turn->endTurn('player', new Command('end_turn'));
ttAssert($s->battle['turn_timer']['period_id'] === 3 && $s->battle['turn_timer']['owner'] === 'host', 'Next host period is unique');
$now = 2220;
$engine->apply($s, 'player', new Command('resign'));
ttAssert($s->battle['turn_timer']['status'] === 'paused' && $s->battle['turn_timer']['pause_reason'] === 'battle_finished', 'Battle completion freezes timer');
ttAssert(TurnTimer::snapshot($s, 9000)['remaining_seconds'] === 170, 'Finished battle consumes no further time');

// A real queued start/end phase: active switches before the old phase completes.
$s = new GameState(-918274, 101, 202);
$s->status = 'battle';
$s->players['host']->side = 1;
$s->addCard(new CardInstance(instanceId:1, ukid:'timer_fixture', owner:'host',
    zone:CardInstance::ZONE_FIELD, row:2, col:2, hp:5, hpMax:5,
    prop:['turn_end'=>[['type'=>'heal', 'value'=>1]], 'prophecy'=>true]));
$now = 3000;
$turn = new TurnProcessor($s, $engine);
$turn->startBattle();
ttAssert(!empty($s->battle['turn_phase']) && $s->battle['turn_timer']['status'] === 'paused', 'Start phase does not spend time');
(new TurnProcessor($s, $engine))->afterStartPhase('host');
ttAssert($s->battle['turn_timer']['status'] === 'paused', 'An early callback cannot bypass an existing start phase');
$now = 3100;
(new TurnPhaseProcessor($s, $engine))->closeSub('host', new Command('close_turn_sub'));
ttAssert($s->battle['turn_timer']['anchor_at'] === 3100, 'Starts at phase finish');
$now = 3110;
$turn->endTurn('host', new Command('end_turn'));
ttAssert($s->battle['active'] === 'player' && $s->battle['turn_timer']['owner'] === 'host'
    && $s->battle['turn_timer']['status'] === 'paused', 'Early active switch does not transfer clock');
$now = 3300;
(new TurnPhaseProcessor($s, $engine))->ackPending('host');
ttAssert($s->battle['turn_timer']['period_id'] === 2 && $s->battle['turn_timer']['owner'] === 'player'
    && $s->battle['turn_timer']['anchor_at'] === 3300, 'Next period starts only after lifecycle completes');
$legacy = new GameState(1, 2, 3);
$legacy->status = 'battle';
$legacy->battle = ['active'=>'host', 'turn'=>4];
ttAssert(TurnTimer::snapshot($legacy, 4000) === null && !isset($legacy->battle['turn_timer']), 'Legacy load never invents a period');
$guestFirst = new GameState(-918275, 101, 202);
$guestFirst->status = 'battle';
$guestFirst->players['player']->side = 1;
(new TurnProcessor($guestFirst, $engine))->startBattle();
ttAssert($guestFirst->battle['turn_timer']['period_id'] === 1
    && $guestFirst->battle['turn_timer']['owner'] === 'player', 'Guest can own the first period');
// The only gameplay transition always alternates, including when guest moves first.
foreach (['host', 'player'] as $first) {
    $s = new GameState(-918277, 101, 202);
    $s->status = 'battle';
    $s->players[$first]->side = 1;
    $now = 4000;
    (new TurnProcessor($s, $engine))->startBattle();
    for ($id = 1; $id <= 6; $id++) {
        $owner = $s->battle['active'];
        ttAssert($s->battle['turn_timer']['period_id'] === $id
            && $s->battle['turn_timer']['owner'] === $owner, 'Every accepted turn has its own sequential period');
        $now += 10;
        $s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
        (new TurnProcessor($s, $engine))->afterStartPhase($owner);
        ttAssert($s->battle['turn_timer']['period_id'] === $id
            && $s->battle['turn_timer']['remaining_seconds'] === 170, 'Reload and repeated start preserve period and elapsed time');
        ttAssert($engine->apply($s, $owner, new Command('end_turn'))->success, 'Accepted end advances through existing lifecycle');
        ttAssert($s->battle['active'] === $s->getOpponentKey($owner)
            && $s->battle['turn_timer']['period_id'] === $id + 1, 'Next owner is always the opponent');
        ttAssert(!$engine->apply($s, $owner, new Command('end_turn'))->success
            && $s->battle['turn_timer']['period_id'] === $id + 1, 'Repeated end command cannot create another period');
    }
}

// Repeating the end-phase continuation while the next turn waits is the same period.
$s = new GameState(-918280, 101, 202);
$s->status = 'battle';
$s->players['host']->side = 1;
$s->addCard(new CardInstance(instanceId:1, ukid:'timer_host', owner:'host',
    zone:CardInstance::ZONE_FIELD, row:3, col:2, hp:20, hpMax:20));
$s->addCard(new CardInstance(instanceId:2, ukid:'timer_guest_choice', owner:'player',
    zone:CardInstance::ZONE_FIELD, row:4, col:2, hp:20, hpMax:20,
    prop:['pre_turn_start'=>['options'=>[['modifiers'=>[]]]]]));
$now = 4200;
(new TurnProcessor($s, $engine))->startBattle();
ttAssert($engine->apply($s, 'host', new Command('end_turn'))->success, 'Next guest turn reaches pre-turn pending');
$s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
$pausedPeriod = $s->battle['turn_timer'];
$now = 4300;
(new TurnProcessor($s, $engine))->afterEndPhase('host', 'player');
ttAssert($s->battle['turn_timer'] === $pausedPeriod && $pausedPeriod['period_id'] === 2,
    'Repeated restored end-phase continuation cannot allocate or reset the waiting guest period');
ttAssert($engine->apply($s, 'player', new Command('choose_card_option', ['option_index'=>0]))->success,
    'Guest pre-turn pending resolves');
ttAssert($s->battle['turn_timer']['period_id'] === 2 && $s->battle['turn_timer']['anchor_at'] === 4300,
    'Guest pending resumes the same period');

// Pre-turn choice -> queued prophecy -> target pending -> result ACK -> ready.
$s = new GameState(-918278, 101, 202);
$s->status = 'battle';
$s->players['host']->side = 1;
$s->nextInstanceId = 10;
$s->addCard(new CardInstance(instanceId:1, ukid:'timer_start_choices', owner:'host',
    zone:CardInstance::ZONE_FIELD, row:3, col:2, hp:20, hpMax:20,
    prop:['pre_turn_start'=>['options'=>[['modifiers'=>[]]]], 'prophecy'=>1,
        'turn_start'=>[['type'=>'whip', 'value'=>1], ['type'=>'damage', 'target'=>'opposite', 'value'=>1]]]));
$s->addCard(new CardInstance(instanceId:2, ukid:'timer_deck', owner:'host',
    zone:CardInstance::ZONE_DECK, hp:5, hpMax:5));
$s->addCard(new CardInstance(instanceId:3, ukid:'timer_opponent', owner:'player',
    zone:CardInstance::ZONE_FIELD, row:4, col:2, hp:20, hpMax:20));
$now = 4500;
(new TurnProcessor($s, $engine))->startBattle();
ttAssert(!empty($s->battle['pending_card_choice']) && empty($s->battle['turn_phase'])
    && $s->battle['turn_timer']['status'] === 'paused', 'Pre-turn pending precedes start phase');
$s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
$now = 4510;
ttAssert($engine->apply($s, 'host', new Command('choose_card_option', ['option_index'=>0]))->success, 'Resolve pre-turn choice');
ttAssert($engine->apply($s, 'host', new Command('turn_task', ['task_id'=>'prophecy']))->success, 'Select queued prophecy');
ttAssert($engine->apply($s, 'host', new Command('turn_sub', ['sub_id'=>'prophecy_1']))->success, 'Prophecy opens pending');
ttAssert(!empty($s->battle['pending_prophecy']) && $s->battle['turn_timer']['status'] === 'paused', 'Prophecy is still a start wait');
$s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
$now = 4520;
ttAssert($engine->apply($s, 'host', new Command('close_prophecy'))->success, 'Close restored prophecy');
ttAssert($engine->apply($s, 'host', new Command('turn_task', ['task_id'=>'turn_start_1_0']))->success, 'Select next start task');
ttAssert(!empty($s->battle['pending_whip']) && $s->battle['turn_timer']['status'] === 'paused', 'Next pending continues same paused period');
$now = 4530;
ttAssert($engine->apply($s, 'host', new Command('choose_whip_target', ['target_id'=>1]))->success, 'Resolve start target choice');
ttAssert(!empty($s->battle['turn_phase']['pending_ack']) && $s->battle['turn_timer']['status'] === 'paused', 'Start result ACK still delays clock');
$s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
$now = 4540;
ttAssert($engine->apply($s, 'host', new Command('turn_ack'))->success, 'Last start result ACK finishes phase');
ttAssert($s->battle['turn_timer']['period_id'] === 1 && $s->battle['turn_timer']['anchor_at'] === 4540, 'All start waits share one period and full budget');
$now = 4550;
ttAssert(!$engine->apply($s, 'host', new Command('turn_ack'))->success
    && $s->battle['turn_timer']['remaining_seconds'] === 170, 'Repeated ACK cannot restart or refund the timer');

// Start-phase instants retain the phase until stack resolution and result OK.
$s = new GameState(-918279, 101, 202);
$s->status = 'battle';
$s->players['host']->side = 1;
$s->addCard(new CardInstance(instanceId:1, ukid:'timer_instant', owner:'host',
    zone:CardInstance::ZONE_FIELD, row:3, col:2, hp:20, hpMax:20,
    prop:['instants'=>[['key'=>'clock_test', 'name'=>'Clock test', 'trigger'=>'turn',
        'target'=>'self', 'uses_per_turn'=>1, 'effect'=>['type'=>'damage', 'value'=>1]]]]));
$s->addCard(new CardInstance(instanceId:2, ukid:'timer_opponent', owner:'player',
    zone:CardInstance::ZONE_FIELD, row:4, col:2, hp:20, hpMax:20));
$now = 4600;
(new TurnProcessor($s, $engine))->startBattle();
ttAssert($engine->apply($s, 'host', new Command('turn_sub', ['sub_id'=>'instant_1_clock_test']))->success, 'Declare start instant');
ttAssert(!empty($s->battle['turn_instant_stack']) && $s->battle['turn_timer']['status'] === 'paused', 'Instant stack keeps start clock paused');
$s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
$now = 4610;
ttAssert($engine->apply($s, 'host', new Command('pass_turn_instant'))->success, 'Resolve start instant stack');
ttAssert(!empty($s->battle['turn_instant_result']) && $s->battle['turn_timer']['status'] === 'paused', 'Instant result still delays start');
$s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
$now = 4620;
ttAssert($engine->apply($s, 'host', new Command('turn_instant_result_ok'))->success, 'Acknowledge instant result');
ttAssert(empty($s->battle['turn_phase']) && $s->battle['turn_timer']['anchor_at'] === 4620, 'Clock starts after instant continuation finishes phase');
$now = 4630;
ttAssert(!$engine->apply($s, 'host', new Command('turn_instant_result_ok'))->success
    && $s->battle['turn_timer']['remaining_seconds'] === 170, 'Repeated instant result command cannot restart period');

// A start task can finish its phase while its combat result still needs both ACKs.
$s = new GameState(-918276, 101, 202);
$s->status = 'battle';
$s->players['host']->side = 1;
$s->addCard(new CardInstance(instanceId:1, ukid:'timer_runes', owner:'host',
    zone:CardInstance::ZONE_FIELD, row:3, col:2, hp:20, hpMax:20,
    flags:['row_spell_pending'=>['row'=>3, 'action'=>[
        'strike'=>['weak'=>1, 'medium'=>1, 'strong'=>1],
    ]]]));
$s->addCard(new CardInstance(instanceId:2, ukid:'timer_enemy', owner:'player',
    zone:CardInstance::ZONE_FIELD, row:4, col:2, hp:20, hpMax:20));
$now = 5000;
(new TurnProcessor($s, $engine))->startBattle();
ttAssert(!empty($s->battle['pending_row_spell_pick']), 'Runes open a real start-phase pending');
$now = 5100;
ttAssert($engine->apply($s, 'host', new Command('choose_row_spell_targets', ['target_ids'=>[2]]))->success,
    'Runes targets can be selected');
ttAssert(empty($s->battle['turn_phase']) && !empty($s->battle['strike']), 'Phase finishes before combat result ACKs');
ttAssert($s->battle['turn_timer']['status'] === 'paused', 'Combat results are still start waits');
$s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
$now = 5200;
ttAssert($engine->apply($s, 'host', new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]))->success, 'First combat result ACK');
ttAssert($s->battle['turn_timer']['status'] === 'paused', 'One ACK cannot start clock');
$now = 5250;
ttAssert(!$engine->apply($s, 'host', new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]))->success
    && $s->battle['turn_timer']['status'] === 'paused', 'Repeated first ACK cannot start deferred clock');
$s = GameState::fromArray(json_decode(json_encode($s->toArray()), true));
$now = 5300;
ttAssert($engine->apply($s, 'player', new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]))->success, 'Second combat result ACK');
ttAssert(empty($s->battle['strike']) && $s->battle['turn_timer']['status'] === 'running'
    && $s->battle['turn_timer']['anchor_at'] === 5300, 'Clock starts after final start combat result ACK');
$now = 5310;
ttAssert(!$engine->apply($s, 'player', new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($s)]))->success
    && $s->battle['turn_timer']['period_id'] === 1 && $s->battle['turn_timer']['remaining_seconds'] === 180
    && TurnTimer::snapshot($s, $now)['remaining_seconds'] === 170,
    'Rejected final ACK is mutation-free; read-only snapshot still accounts for elapsed time');
echo "OK turn timer\n";
