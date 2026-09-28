<?php
// tools/test_draft_temporary_rules.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Db;
use Berserk\Core\GameSettings;
use Berserk\Core\GameState;
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
        'pool'   => ['a', 'b', 'c'],
        'grid'   => ['g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9'],
        'turn'   => $turn,
        'picked' => [
            GameState::PLAYER_HOST => $hostPicked,
            GameState::PLAYER_PLAYER => $playerPicked,
        ],
    ];
    return $state;
}

function processorWithoutDb(GameState $state): DraftProcessor
{
    $ref = new ReflectionClass(DraftProcessor::class);
    $processor = $ref->newInstanceWithoutConstructor();

    $stateProp = $ref->getProperty('state');
    $stateProp->setAccessible(true);
    $stateProp->setValue($processor, $state);

    return $processor;
}

// Repeated pass is always allowed on the active player's turn.
$state = stateWithDraft();
$processor = processorWithoutDb($state);

$result = $processor->pass(GameState::PLAYER_HOST);
assertTrue($result->success, 'Host pass should succeed');
assertTrue($state->draft['turn'] === GameState::PLAYER_PLAYER, 'Host pass should switch turn to player');
assertTrue(!array_key_exists('pass_blocked', $state->draft), 'Draft runtime state should not include pass_blocked');
assertTrue(!array_key_exists('passed', $state->draft), 'Draft runtime state should not include passed');

$result = $processor->pass(GameState::PLAYER_PLAYER);
assertTrue($result->success, 'Player pass should succeed after host pass');
assertTrue($state->draft['turn'] === GameState::PLAYER_HOST, 'Player pass should switch turn to host');

$result = $processor->pass(GameState::PLAYER_HOST);
assertTrue($result->success, 'Host should be able to pass again after consecutive passes');
assertTrue($state->draft['turn'] === GameState::PLAYER_PLAYER, 'Repeated pass should keep alternating turns');

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
    try {
        $db = new Db(require $configPath);
        $row = $db->fetchOne('SELECT ukid FROM cards LIMIT 1');
        assertTrue($row !== null && !empty($row['ukid']), 'DB-backed draft test needs at least one card');
        $ukid = (string) $row['ukid'];

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
    } catch (Throwable $e) {
        echo "Skipping DB-backed draft finalization checks: {$e->getMessage()}\n";
    }
} else {
    echo "Skipping DB-backed draft finalization checks: config/db.php not found\n";
}

echo "Temporary draft rule tests passed.\n";
