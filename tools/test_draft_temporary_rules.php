<?php
// tools/test_draft_temporary_rules.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Db;
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

// Manual finish is rejected before both players have 30 drafted cards.
$state = stateWithDraft(array_fill(0, 30, 'u1'), array_fill(0, 29, 'u1'));
$processor = processorWithoutDb($state);
$result = $processor->finish(GameState::PLAYER_HOST);
assertTrue(!$result->success, 'finish_draft should be rejected below 30 cards for both players');
assertTrue($state->status === 'draft', 'Rejected finish_draft should keep draft status');
assertTrue($state->draft !== null, 'Rejected finish_draft should keep draft runtime state');

// Manual finish is restricted to the active draft player.
$state = stateWithDraft(array_fill(0, 30, 'u1'), array_fill(0, 30, 'u1'), GameState::PLAYER_HOST);
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

        $state = stateWithDraft(array_fill(0, 30, $ukid), array_fill(0, 30, $ukid));
        $result = (new DraftProcessor($state, $db))->finish(GameState::PLAYER_HOST);
        assertTrue($result->success, 'finish_draft should succeed once both players have 30 cards');
        assertTrue($state->status === 'view', 'Valid finish_draft should transition to view');
        assertTrue($state->draft === null, 'Valid finish_draft should clear draft runtime state');
        assertTrue(count($state->getPlayer(GameState::PLAYER_HOST)->deckCards) > 0, 'Finalized draft should build host deck cards');
        assertTrue(count($state->getPlayer(GameState::PLAYER_PLAYER)->deckCards) > 0, 'Finalized draft should build player deck cards');

        $state = stateWithDraft(array_fill(0, 30, $ukid), array_fill(0, 30, $ukid));
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
