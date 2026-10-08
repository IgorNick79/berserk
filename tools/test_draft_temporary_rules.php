<?php
// tools/test_draft_temporary_rules.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Db;
use Berserk\Core\GameSettings;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\DraftCopyRules;
use Berserk\Core\Prepare\DraftProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function stateWithDraft(array $hostPicked = [], array $playerPicked = [], string $turn = GameState::PLAYER_HOST): GameState
{
    $state = new GameState(random_int(1000, 9999), 1, 2);
    $state->status = 'draft';
    $state->draft = [
        'pool'   => array_merge(['a', 'b', 'c'], array_map(fn($i) => 'p' . $i, range(1, 60))),
        'grid'   => ['g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9'],
        'turn'   => $turn,
        'picked' => [
            GameState::PLAYER_HOST => $hostPicked,
            GameState::PLAYER_PLAYER => $playerPicked,
        ],
        'history' => [],
        'recycle' => [],
        'grid_mode' => GameSettings::DRAFT_GRID_MODE_CONTINUOUS,
        'consecutive_passes' => 0,
        'round_first_player' => GameState::PLAYER_HOST,
        'round_picks' => 0,
    ];
    return $state;
}

function minimumDraftState(
    array $hostPicked,
    array $playerPicked,
    array $grid,
    array $pool = [],
    array $recycle = [],
    string $turn = GameState::PLAYER_HOST,
    string $gridMode = GameSettings::DRAFT_GRID_MODE_CONTINUOUS,
): GameState {
    $state = new GameState(random_int(1000, 9999), 1, 2);
    $state->status = 'draft';
    $state->settings = GameSettings::fromArray(['draft' => ['grid_mode' => $gridMode]]);
    $state->draft = [
        'pool' => $pool,
        'grid' => array_pad(array_slice($grid, 0, 9), 9, null),
        'turn' => $turn,
        'picked' => [
            GameState::PLAYER_HOST => $hostPicked,
            GameState::PLAYER_PLAYER => $playerPicked,
        ],
        'history' => [],
        'recycle' => $recycle,
        'grid_mode' => $gridMode,
        'consecutive_passes' => 0,
        'round_first_player' => GameState::PLAYER_HOST,
        'round_picks' => 0,
    ];
    return $state;
}

function draftCardCount(GameState $state): int
{
    return count($state->draft['pool'] ?? [])
        + count(array_filter($state->draft['grid'] ?? [], fn($ukid) => $ukid !== null))
        + count($state->draft['recycle'] ?? [])
        + count($state->draft['picked'][GameState::PLAYER_HOST] ?? [])
        + count($state->draft['picked'][GameState::PLAYER_PLAYER] ?? []);
}

assertTrue(DraftCopyRules::deckLimit(['prop' => []]) === DraftCopyRules::NORMAL_DECK_LIMIT, 'Missing horde prop should use normal deck limit');
assertTrue(DraftCopyRules::deckLimit(['prop' => ['horde' => false]]) === DraftCopyRules::NORMAL_DECK_LIMIT, 'False horde prop should use normal deck limit');
assertTrue(DraftCopyRules::deckLimit(['prop' => ['horde' => true]]) === DraftCopyRules::HORDE_DECK_LIMIT, 'True horde prop should use horde deck limit');
assertTrue(DraftCopyRules::poolLimit(['prop' => ['horde' => true]]) === DraftCopyRules::HORDE_POOL_LIMIT, 'True horde prop should use horde pool limit');

function processorWithoutDb(GameState $state): DraftProcessor
{
    $ref = new ReflectionClass(DraftProcessor::class);
    $processor = $ref->newInstanceWithoutConstructor();

    $stateProp = $ref->getProperty('state');
    $stateProp->setAccessible(true);
    $stateProp->setValue($processor, $state);

    return $processor;
}

// Continuous pass chain replaces a mutually rejected grid.
$state = stateWithDraft();
$processor = processorWithoutDb($state);
$initialCount = draftCardCount($state);
$initialGrid = $state->draft['grid'];

$result = $processor->pass(GameState::PLAYER_HOST);
assertTrue($result->success, 'Host pass should succeed');
assertTrue($state->draft['turn'] === GameState::PLAYER_PLAYER, 'Host pass should switch turn to player');
assertTrue($state->draft['consecutive_passes'] === 1, 'First pass should increment consecutive pass counter');
assertTrue($state->draft['grid'] === $initialGrid, 'First pass should keep current grid');

$result = $processor->pass(GameState::PLAYER_PLAYER);
assertTrue($result->success, 'Player pass should succeed after host pass');
assertTrue($state->draft['turn'] === GameState::PLAYER_HOST, 'Player pass should switch turn to host');
assertTrue($state->draft['consecutive_passes'] === 0, 'Second pass should reset consecutive pass counter');
assertTrue($state->draft['grid'] !== $initialGrid, 'Second consecutive pass should replace the whole grid');
assertTrue(count($state->draft['recycle']) === 9, 'Second pass should recycle rejected grid cards');
assertTrue(draftCardCount($state) === $initialCount, 'Second pass should conserve draft card count');

$state = stateWithDraft();
$processor = processorWithoutDb($state);
$result = $processor->pass(GameState::PLAYER_HOST);
assertTrue($result->success, 'Host pass should succeed before a pick');
$result = $processor->pickRow(GameState::PLAYER_PLAYER, 1);
assertTrue($result->success, 'Pick after pass should succeed');
assertTrue($state->draft['consecutive_passes'] === 0, 'Pick should reset pass chain');
$result = $processor->pass(GameState::PLAYER_HOST);
assertTrue($result->success, 'Pass should be available after pick reset');
assertTrue($state->draft['consecutive_passes'] === 1, 'Pass after pick starts a fresh pass chain');

// DraftProcessor exposes the same row/column selections for auto-pick logic.
$state = stateWithDraft();
$processor = processorWithoutDb($state);
$selections = $processor->validSelections();
assertTrue(count($selections) === 6, '3x3 full grid should expose 3 rows and 3 columns');
$result = $processor->pickSelection(GameState::PLAYER_HOST, $selections[0], 'auto_picked');
assertTrue($result->success, 'Auto selection should be applied through draft processor');
assertTrue(count($state->draft['picked'][GameState::PLAYER_HOST]) === 3, 'Auto selection should pick three cards');
assertTrue($state->draft['grid'][0] === 'a', 'Auto selection should refill first selected position from pool');
assertTrue($state->draft['turn'] === GameState::PLAYER_PLAYER, 'Auto selection should switch turn');

// Recycle is used after the pool is exhausted.
$state = minimumDraftState(
    array_fill(0, 27, 'h'),
    array_fill(0, 30, 'p'),
    ['g1', 'g2', 'g3', null, null, null, null, null, null],
    [],
    ['r1', 'r2', 'r3'],
);
$processor = processorWithoutDb($state);
$result = $processor->pickRow(GameState::PLAYER_HOST, 1);
assertTrue($result->success, 'Pick should refill from recycle when pool is empty');
assertTrue(count(array_filter($state->draft['grid'], fn($ukid) => $ukid !== null)) === 3, 'Recycle should refill emptied positions');
assertTrue(draftCardCount($state) === 63, 'Recycle refill should conserve cards');

// Discrete grid: first pick does not refill, second pick ends the round.
$state = minimumDraftState(
    array_fill(0, 25, 'h'),
    array_fill(0, 25, 'p'),
    ['g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9'],
    array_map(fn($i) => 'p' . $i, range(1, 20)),
    [],
    GameState::PLAYER_HOST,
    GameSettings::DRAFT_GRID_MODE_DISCRETE,
);
$processor = processorWithoutDb($state);
$initialCount = draftCardCount($state);
$result = $processor->pass(GameState::PLAYER_HOST);
assertTrue(!$result->success, 'Pass should be server-side rejected in discrete draft');
$result = $processor->pickRow(GameState::PLAYER_HOST, 1);
assertTrue($result->success, 'Discrete first row pick should succeed');
assertTrue($state->draft['picked'][GameState::PLAYER_HOST] === array_merge(array_fill(0, 25, 'h'), ['g1', 'g2', 'g3']), 'Discrete first pick should take three cards');
assertTrue($state->draft['grid'][0] === null && $state->draft['grid'][1] === null && $state->draft['grid'][2] === null, 'Discrete first pick should not refill selected row');
assertTrue($state->draft['turn'] === GameState::PLAYER_PLAYER, 'Discrete first pick should pass turn to second player');
$result = $processor->pickSelection(GameState::PLAYER_PLAYER, ['positions' => [0, 1, 2]], 'auto_picked');
assertTrue(!$result->success, 'Discrete empty line should be rejected');
$result = $processor->pickCol(GameState::PLAYER_PLAYER, 1);
assertTrue($result->success, 'Discrete second player should be able to pick a partially empty column');
assertTrue(array_slice($state->draft['picked'][GameState::PLAYER_PLAYER], -2) === ['g4', 'g7'], 'Discrete second pick should take only remaining cards in selected line');
assertTrue($state->draft['round_first_player'] === GameState::PLAYER_PLAYER, 'Discrete round first player should alternate');
assertTrue($state->draft['turn'] === GameState::PLAYER_PLAYER, 'New discrete round should start with the alternated first player');
assertTrue(count(array_filter($state->draft['grid'], fn($ukid) => $ukid !== null)) === 9, 'Discrete second pick should deal a fresh grid');
assertTrue(draftCardCount($state) === $initialCount, 'Discrete round reset should conserve draft card count');

// minimum_pool_distribution_safety: do not allow 31-29 / 32-28 traps at 60 cards.
$state = minimumDraftState(
    array_fill(0, 28, 'h'),
    array_fill(0, 29, 'p'),
    ['g1', 'g2', 'g3', null, null, null, null, null, null],
);
$processor = processorWithoutDb($state);
$result = $processor->pickRow(GameState::PLAYER_HOST, 1);
assertTrue(!$result->success, 'minimum_pool_distribution_safety: 31-29 should be rejected');
assertTrue(count($state->draft['picked'][GameState::PLAYER_HOST]) === 28, 'Rejected 31-29 attempt should not mutate host picks');
assertTrue(max(array_map(fn($selection) => count($selection['cards']), $processor->validSelections())) === 1, 'minimum_pool_distribution_safety: auto should only receive safe 31-29 avoidance selections');

$state = minimumDraftState(
    array_fill(0, 29, 'h'),
    array_fill(0, 28, 'p'),
    ['g1', 'g2', 'g3', null, null, null, null, null, null],
);
$processor = processorWithoutDb($state);
$result = $processor->pickRow(GameState::PLAYER_HOST, 1);
assertTrue(!$result->success, 'minimum_pool_distribution_safety: 32-28 should be rejected');
assertTrue(max(array_map(fn($selection) => count($selection['cards']), $processor->validSelections())) === 1, 'minimum_pool_distribution_safety: auto should only receive safe 32-28 avoidance selections');

$state = minimumDraftState(
    array_fill(0, 29, 'h'),
    array_fill(0, 28, 'p'),
    ['g1', 'g2', null, null, null, null, null, null, null],
    ['p1'],
);
$processor = processorWithoutDb($state);
$result = $processor->pickRow(GameState::PLAYER_HOST, 1);
assertTrue(!$result->success, 'minimum_pool_distribution_safety: 31-28 with one remaining card should be rejected');

$state = minimumDraftState(
    array_fill(0, 27, 'h'),
    array_fill(0, 30, 'p'),
    ['g1', 'g2', 'g3', null, null, null, null, null, null],
);
$processor = processorWithoutDb($state);
$valid = $processor->validSelections();
assertTrue(in_array(['positions' => [0, 1, 2], 'cards' => ['g1', 'g2', 'g3']], $valid, true), 'minimum_pool_distribution_safety: valid 30-30 should be reachable');

$state = minimumDraftState(
    array_fill(0, 30, 'h'),
    array_fill(0, 30, 'p'),
    ['g1', 'g2', 'g3', null, null, null, null, null, null],
    array_map(fn($i) => 'extra' . $i, range(1, 9)),
);
$processor = processorWithoutDb($state);
$result = $processor->pickRow(GameState::PLAYER_HOST, 1);
assertTrue($result->success, 'Pool above minimum should allow taking more than 30 cards');
assertTrue(count($state->draft['picked'][GameState::PLAYER_HOST]) === 33, 'No hard cap at 30 should be applied');

// Valid auto selections are restricted to current rows/columns.
$state = stateWithDraft();
$processor = processorWithoutDb($state);
$result = $processor->pickSelection(GameState::PLAYER_HOST, ['positions' => [0, 3, 6]], 'auto_picked');
assertTrue($result->success, 'Valid auto column selection should be accepted');
assertTrue(count($state->draft['picked'][GameState::PLAYER_HOST]) === 3, 'Valid column should pick three cards');
assertTrue($state->draft['turn'] === GameState::PLAYER_PLAYER, 'Valid column should switch turn');

$state = stateWithDraft();
$processor = processorWithoutDb($state);
$before = $state->draft;
$result = $processor->pickSelection(GameState::PLAYER_HOST, ['positions' => [0, 4, 8]], 'auto_picked');
assertTrue(!$result->success, 'Diagonal auto selection should be rejected');
assertTrue($state->draft === $before, 'Rejected diagonal selection should not mutate draft state');

$state = stateWithDraft();
$processor = processorWithoutDb($state);
$before = $state->draft;
$result = $processor->pickSelection(GameState::PLAYER_HOST, ['positions' => [0, 1, 4]], 'auto_picked');
assertTrue(!$result->success, 'Arbitrary auto selection should be rejected');
assertTrue($state->draft === $before, 'Rejected arbitrary selection should not mutate draft state');

$state = stateWithDraft();
$processor = processorWithoutDb($state);
$before = $state->draft;
$result = $processor->pickSelection(GameState::PLAYER_HOST, ['positions' => []], 'auto_picked');
assertTrue(!$result->success, 'Empty auto selection should be rejected');
assertTrue($state->draft === $before, 'Rejected empty selection should not mutate draft state');

// Copy limits are checked atomically before mutating the draft state.
$state = stateWithDraft(['dup', 'dup']);
$state->draft['grid'] = ['dup', 'dup', 'safe', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9'];
$processor = processorWithoutDb($state);
$before = $state->draft;
$result = $processor->pickRow(GameState::PLAYER_HOST, 1);
assertTrue(!$result->success, 'Picking two extra normal copies over limit should be rejected');
assertTrue(str_contains($result->error ?? '', 'Слишком много копий'), 'Copy limit rejection should explain the exceeded limit');
assertTrue($state->draft === $before, 'Rejected copy-limit pick should be atomic');
assertTrue(count(array_filter($processor->validSelections(), fn($selection) => $selection['positions'] === [0, 1, 2])) === 0, 'Auto selections should filter copy-limit violating rows');

$state = stateWithDraft();
$state->draft['grid'] = ['triple', 'triple', 'triple', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9'];
$processor = processorWithoutDb($state);
$result = $processor->pickRow(GameState::PLAYER_HOST, 1);
assertTrue($result->success, 'Exactly three normal copies in one line should be allowed');
assertTrue(count(array_filter($state->draft['picked'][GameState::PLAYER_HOST], fn($ukid) => $ukid === 'triple')) === 3, 'Allowed triple line should be picked');

$deckLimit = GameSettings::MIN_DECK_SIZE;

// Manual finish is rejected before both players have enough drafted cards.
$state = stateWithDraft(array_fill(0, $deckLimit, 'u1'), array_fill(0, $deckLimit - 1, 'u1'));
$processor = processorWithoutDb($state);
$result = $processor->finish(GameState::PLAYER_HOST);
assertTrue(!$result->success, 'finish_draft should be rejected below deck limit for both players');
assertTrue($state->status === 'draft', 'Rejected finish_draft should keep draft status');
assertTrue($state->draft !== null, 'Rejected finish_draft should keep draft runtime state');

// Manual finish is restricted to the active draft player.
$state = stateWithDraft(array_fill(0, $deckLimit, 'u1'), array_fill(0, $deckLimit, 'u1'), GameState::PLAYER_HOST);
$processor = processorWithoutDb($state);
$result = $processor->finish(GameState::PLAYER_PLAYER);
assertTrue(!$result->success, 'finish_draft should be rejected when it is not the requester turn');
assertTrue($state->status === 'draft', 'Wrong-turn finish_draft should keep draft status');

// Exhausted pool with insufficient cards returns an error instead of finalizing.
$state = stateWithDraft(array_fill(0, 10, 'u1'), array_fill(0, 10, 'u1'));
$state->draft['pool'] = [];
$state->draft['grid'] = array_fill(0, 9, null);
$processor = processorWithoutDb($state);
$result = $processor->pass(GameState::PLAYER_HOST);
assertTrue(!$result->success, 'Exhausted pool with insufficient cards should fail');
assertTrue($state->status === 'draft', 'Invalid exhausted draft should not transition to view');
assertTrue($state->draft !== null, 'Invalid exhausted draft should keep draft runtime state');

// DB-backed finalization checks, when local DB config is usable.
$configPath = __DIR__ . '/../config/db.php';
if (is_file($configPath)) {
    $db = new Db(require $configPath);
    $row = $db->fetchOne('SELECT ukid FROM cards LIMIT 1');
    assertTrue($row !== null && !empty($row['ukid']), 'DB-backed draft test needs at least one card');
    $ukid = (string) $row['ukid'];

    $missingUkid = '__missing_copy_rule_card__';
    $state = stateWithDraft();
    $state->draft['grid'] = [$missingUkid, 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9'];
    $result = (new DraftProcessor($state, $db))->pickRow(GameState::PLAYER_HOST, 1);
    assertTrue(!$result->success, 'Production copy-limit check should reject missing card metadata');
    assertTrue(str_contains($result->error ?? '', 'Не найдены данные карты'), 'Missing card metadata should produce an explicit error');

    $state = stateWithDraft(array_fill(0, $deckLimit, $ukid), array_fill(0, $deckLimit, $ukid));
    $result = (new DraftProcessor($state, $db))->finish(GameState::PLAYER_HOST);
    assertTrue($result->success, 'finish_draft should succeed once both players have deck limit cards');
    assertTrue($state->status === 'view', 'Valid finish_draft should transition to view');
    assertTrue($state->draft === null, 'Valid finish_draft should clear draft runtime state');
    assertTrue(count($state->getPlayer(GameState::PLAYER_HOST)->deckCards) > 0, 'Finalized draft should build host deck cards');
    assertTrue(count($state->getPlayer(GameState::PLAYER_PLAYER)->deckCards) > 0, 'Finalized draft should build player deck cards');
    assertTrue(count($state->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_DECK)) === $deckLimit, 'Finalized draft should create host deck instances');
    assertTrue(count($state->getCardsInZone(GameState::PLAYER_PLAYER, CardInstance::ZONE_DECK)) === $deckLimit, 'Finalized draft should create player deck instances');

    $state = stateWithDraft(array_fill(0, $deckLimit, $ukid), array_fill(0, $deckLimit, $ukid));
    $state->draft['pool'] = [];
    $state->draft['grid'] = array_fill(0, 9, null);
    $result = (new DraftProcessor($state, $db))->pass(GameState::PLAYER_HOST);
    assertTrue($result->success, 'Exhausted pool with enough cards should auto-finalize');
    assertTrue($state->status === 'view', 'Valid exhausted draft should transition to view');
    assertTrue($state->draft === null, 'Valid exhausted draft should clear draft runtime state');
} else {
    echo "Skipping DB-backed draft finalization checks: config/db.php not found\n";
}

echo "Temporary draft rule tests passed.\n";
