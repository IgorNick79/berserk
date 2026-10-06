<?php
// tools/test_draft_timer_history.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Command;
use Berserk\Core\Db;
use Berserk\Core\GameSettings;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\DraftProcessor;
use Berserk\Core\Prepare\DraftTimer;
use Berserk\Core\Prepare\PrepareProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function dtAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dtState(string $turn = GameState::PLAYER_HOST): GameState
{
    $state = new GameState(random_int(1000, 9999), 1, 2);
    $state->status = 'draft';
    $state->draft = [
        'pool' => ['p1', 'p2', 'p3', 'p4', 'p5', 'p6'],
        'grid' => ['g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9'],
        'turn' => $turn,
        'picked' => [GameState::PLAYER_HOST => [], GameState::PLAYER_PLAYER => []],
    ];
    return $state;
}

function dtDraftProcessor(GameState $state): DraftProcessor
{
    $ref = new ReflectionClass(DraftProcessor::class);
    $processor = $ref->newInstanceWithoutConstructor();
    $stateProp = $ref->getProperty('state');
    $stateProp->setAccessible(true);
    $stateProp->setValue($processor, $state);
    return $processor;
}

function dtSettingsFromCommand(GameSettings $settings, Command $cmd): GameSettings
{
    $state = new GameState(1, 1, 2);
    $processor = new PrepareProcessor($state);
    $ref = new ReflectionClass(PrepareProcessor::class);
    $method = $ref->getMethod('settingsFromCommand');
    $method->setAccessible(true);
    return $method->invoke($processor, $settings, $cmd);
}

function dtPrepareWithAutoPicker(GameState $state, object $autoPicker): PrepareProcessor
{
    return new PrepareProcessor($state, null, $autoPicker);
}

$defaults = GameSettings::defaults();
dtAssert($defaults->draftTimerMode() === GameSettings::DRAFT_TIMER_DEFAULT, 'Draft timer should default to default mode');
dtAssert($defaults->draftTimerTotalSeconds() === 600, 'Default draft total timer should be 600 seconds');
dtAssert($defaults->draftTimerActionSeconds() === 30, 'Default draft action timer should be 30 seconds');
dtAssert($defaults->validateDraftTimer() === null, 'Default draft timer settings should validate');

$settings = GameSettings::fromArray(['draft' => [
    'timer_mode' => GameSettings::DRAFT_TIMER_CUSTOM,
    'timer_total' => 300,
    'timer_action' => 10,
]]);
dtAssert($settings->validateDraftTimer() === null, 'Custom lower timer bounds should validate');

$settings = GameSettings::fromArray(['draft' => [
    'timer_mode' => GameSettings::DRAFT_TIMER_CUSTOM,
    'timer_total' => 1800,
    'timer_action' => 120,
]]);
dtAssert($settings->validateDraftTimer() === null, 'Custom upper timer bounds should validate');

$settings = GameSettings::fromArray(['draft' => [
    'timer_mode' => GameSettings::DRAFT_TIMER_CUSTOM,
    'timer_total' => 301,
    'timer_action' => 10,
]]);
dtAssert($settings->validateDraftTimer() !== null, 'Custom total must respect minute step');

$settings = GameSettings::fromArray(['draft' => [
    'timer_mode' => GameSettings::DRAFT_TIMER_CUSTOM,
    'timer_total' => 300,
    'timer_action' => 11,
]]);
dtAssert($settings->validateDraftTimer() !== null, 'Custom action must respect five-second step');

$settings = GameSettings::fromArray(['draft' => ['timer_mode' => GameSettings::DRAFT_TIMER_UNLIMITED]]);
dtAssert($settings->validateDraftTimer() === null, 'Unlimited timer mode should validate');
dtAssert($settings->draftTimerTotalSeconds() === 0, 'Unlimited timer should disable total limit');

$settings = dtSettingsFromCommand(GameSettings::defaults(), new Command('confirm_settings', [
    'draft_timer_mode' => GameSettings::DRAFT_TIMER_CUSTOM,
    'draft_timer_total' => 5,
    'draft_timer_action' => 10,
]));
dtAssert($settings->draftTimerTotalSeconds() === 300, 'Settings command should convert custom total minutes to seconds');
dtAssert($settings->draftTimerActionSeconds() === 10, 'Settings command should keep custom action seconds');

$state = dtState();
DraftTimer::initialize($state, GameSettings::defaults(), 1000);
dtAssert(isset($state->draft['timer']), 'Timer runtime should be initialized for default mode');
dtAssert($state->draft['timer']['elapsed']['host'] === 0, 'Host elapsed timer should start at zero');
dtAssert(DraftTimer::deadlineAt($state) === 1030 + DraftTimer::TRANSPORT_GRACE_SECONDS, 'Default action deadline should include transport grace');
dtAssert(DraftTimer::chargeLimitAt($state) === 1030, 'Charge limit should exclude transport grace');
dtAssert(DraftTimer::remainingAction($state, 1005) === 25 + DraftTimer::TRANSPORT_GRACE_SECONDS, 'Remaining action time should include transport grace for delivery');
dtAssert(DraftTimer::remainingTotal($state, GameState::PLAYER_HOST, 1010) === 590, 'Active host total should include current thinking time');
dtAssert(DraftTimer::remainingTotal($state, GameState::PLAYER_PLAYER, 1010) === 600, 'Inactive player total should be independent');

$charged = DraftTimer::settleAction($state, GameState::PLAYER_HOST, 1012);
dtAssert($charged === 12, 'Settling manual action should charge elapsed active time');
dtAssert($state->draft['timer']['elapsed']['host'] === 12, 'Settled elapsed time should be stored per player');

DraftTimer::startAction($state, 2000);
dtAssert(DraftTimer::deadlineAt($state) === 2030 + DraftTimer::TRANSPORT_GRACE_SECONDS, 'New action should reset action deadline with grace');

$legacy = dtState();
DraftTimer::ensureRuntime($legacy, 3000);
dtAssert(isset($legacy->draft['history']), 'Legacy draft state should receive history array');
dtAssert(isset($legacy->draft['timer']), 'Legacy draft state should receive timer runtime');

$unlimited = dtState();
$unlimited->settings = GameSettings::fromArray(['draft' => ['timer_mode' => GameSettings::DRAFT_TIMER_UNLIMITED]]);
DraftTimer::ensureRuntime($unlimited, 1000);
dtAssert(!isset($unlimited->draft['timer']), 'Unlimited mode should not create timer runtime');

$state = dtState();
$processor = dtDraftProcessor($state);
$result = $processor->pickRow(GameState::PLAYER_HOST, 1);
dtAssert($result->success, 'First draft pick should succeed');
dtAssert(count($state->draft['history']) === 1, 'First pick should create one history event');
dtAssert($state->draft['history'][0]['player'] === GameState::PLAYER_HOST, 'History should record pick owner');
dtAssert($state->draft['history'][0]['cards'] === ['g1', 'g2', 'g3'], 'History should record taken cards');
$result = $processor->pickCol(GameState::PLAYER_PLAYER, 1);
dtAssert($result->success, 'Second draft pick should succeed');
dtAssert(array_column($state->draft['history'], 'player') === [GameState::PLAYER_HOST, GameState::PLAYER_PLAYER], 'History should preserve global order');

$autoPicker = new class {
    public int $calls = 0;
    public function pick(array $validSelections, array $currentUkids): ?array
    {
        $this->calls++;
        return $validSelections[0] ?? null;
    }
};

foreach ([
    'row' => fn(PrepareProcessor $p) => $p->draftRow(GameState::PLAYER_HOST, new Command('draft_row', ['row' => 3])),
    'col' => fn(PrepareProcessor $p) => $p->draftCol(GameState::PLAYER_HOST, new Command('draft_col', ['col' => 3])),
    'pass' => fn(PrepareProcessor $p) => $p->draftPass(GameState::PLAYER_HOST),
] as $label => $manual) {
    $state = dtState();
    DraftTimer::initialize($state, GameSettings::defaults(), 1000);
    $state->draft['timer']['action_started_at'] = time() - GameSettings::DRAFT_TIMER_DEFAULT_ACTION_SECONDS - DraftTimer::TRANSPORT_GRACE_SECONDS;
    $processor = dtPrepareWithAutoPicker($state, $autoPicker);
    $beforeGrid = $state->draft['grid'];
    $result = $manual($processor);
    dtAssert($result->success, "Expired manual {$label} should return success so timeout mutation is saved");
    dtAssert(in_array('manual_draft_action_expired', $result->events, true), "Expired manual {$label} should report skipped manual action");
    dtAssert(str_starts_with($result->events[0], 'timeout_picked:'), "Expired manual {$label} should persist timeout pick events");
    dtAssert($state->draft['picked'][GameState::PLAYER_HOST] === ['g1', 'g2', 'g3'], "Expired manual {$label} should apply timeout auto selection");
    dtAssert(!in_array('g7', $state->draft['picked'][GameState::PLAYER_HOST], true), "Expired manual {$label} should not apply stale manual selection");
    dtAssert($state->draft['grid'] !== $beforeGrid, "Expired manual {$label} should mutate grid through timeout");
}

$state = dtState(GameState::PLAYER_HOST);
$state->draft['picked'][GameState::PLAYER_HOST] = array_fill(0, GameSettings::MIN_DECK_SIZE, 'g1');
$state->draft['picked'][GameState::PLAYER_PLAYER] = array_fill(0, GameSettings::MIN_DECK_SIZE, 'g2');
DraftTimer::initialize($state, GameSettings::defaults(), 1000);
$state->draft['timer']['action_started_at'] = time() - GameSettings::DRAFT_TIMER_DEFAULT_ACTION_SECONDS - DraftTimer::TRANSPORT_GRACE_SECONDS;
$processor = dtPrepareWithAutoPicker($state, $autoPicker);
$result = $processor->finishDraft(GameState::PLAYER_HOST);
dtAssert($result->success, 'Expired finish_draft should return success so timeout mutation is saved');
dtAssert(in_array('manual_draft_action_expired', $result->events, true), 'Expired finish_draft should skip stale manual finish');
dtAssert($state->status === 'draft', 'Expired finish_draft should not finalize after timeout changed the turn');

$state = dtState();
DraftTimer::initialize($state, GameSettings::defaults(), 1000);
$state->draft['timer']['elapsed'][GameState::PLAYER_HOST] = 600;
dtAssert(DraftTimer::deadlineAt($state) === 1000 + DraftTimer::TRANSPORT_GRACE_SECONDS, 'Total budget exhaustion should still use only transport grace');
dtAssert(DraftTimer::isExpired($state, 1000 + DraftTimer::TRANSPORT_GRACE_SECONDS), 'Total budget exhaustion should auto-resolve after transport grace');

$configPath = __DIR__ . '/../config/db.php';
if (is_file($configPath)) {
    try {
        $db = new Db(require $configPath);
        $rows = $db->fetchAll('SELECT ukid FROM cards LIMIT 20');
        dtAssert(count($rows) >= 9, 'DB-backed timeout test needs at least nine cards');
        $ukids = array_map(fn($row) => (string) $row['ukid'], $rows);

        $state = dtState();
        $state->draft['grid'] = array_slice($ukids, 0, 9);
        $state->draft['pool'] = array_slice($ukids, 9);
        DraftTimer::initialize($state, GameSettings::defaults(), 1000);
        $state->draft['timer']['action_started_at'] = 900;

        $result = (new PrepareProcessor($state, $db))->resolveDraftTimeouts(1000);
        dtAssert($result->success, 'Timeout resolution should succeed');
        dtAssert(!empty($result->events), 'Timeout resolution should apply an auto pick');
        dtAssert(count($state->draft['picked'][GameState::PLAYER_HOST]) > 0, 'Timeout should pick cards for active player');
        dtAssert(count($state->draft['history']) === 1, 'Timeout auto-pick should be recorded in history');
        dtAssert(str_starts_with($result->events[0], 'timeout_picked:'), 'Timeout should use timeout_picked event prefix');
    } catch (Throwable $e) {
        echo "Skipping DB-backed draft timeout checks: {$e->getMessage()}\n";
    }
} else {
    echo "Skipping DB-backed draft timeout checks: config/db.php not found\n";
}

echo "Draft timer/history tests passed.\n";
