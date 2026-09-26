<?php
// tools/test_movement_effects.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Movement\MovementResolver;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function movementState(CardInstance ...$cards): GameState
{
    $state = new GameState(2, 101, 202);
    $state->status = 'battle';
    $state->battle = ['active' => GameState::PLAYER_HOST];

    foreach ($cards as $card) {
        $state->addCard($card);
    }

    return $state;
}

function movementCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'] ?? 1,
        ukid: $overrides['ukid'] ?? 'test-card',
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 1,
        hpMax: $overrides['hpMax'] ?? 1,
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        prop: $overrides['prop'] ?? [],
        markers: $overrides['markers'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function moveCard(GameState $state, int $cardId, int $row, int $col): void
{
    $resolver = new MovementResolver($state, new Engine());
    $result = $resolver->move(GameState::PLAYER_HOST, new Command('move', [
        'card_id' => $cardId,
        'row' => $row,
        'col' => $col,
    ]));

    assertTrue($result->success, $result->error ?? 'Move failed.');
}

$mover = movementCard([
    'instanceId' => 1,
    'row' => 3,
    'col' => 3,
    'prop' => [
        'can_move_diagonal' => true,
        'actions' => [['type' => 'magic']],
    ],
]);
$airin = movementCard([
    'instanceId' => 2,
    'ukid' => 'airin-like',
    'row' => 1,
    'col' => 1,
    'prop' => ['airin_trigger' => true],
]);
$state = movementState($mover, $airin);
moveCard($state, 1, 2, 2);
assertTrue(
    ($airin->flags['airin_triggered_this_turn'] ?? 0) === 1,
    'Airin trigger should increment when a magical creature becomes adjacent.'
);
assertTrue(
    ($airin->modifiers[0]['stat'] ?? null) === 'ability_discharge',
    'Airin trigger should add an ability_discharge modifier.'
);

$alreadyAdjacentMover = movementCard([
    'instanceId' => 3,
    'row' => 2,
    'col' => 1,
    'prop' => [
        'actions' => [['type' => 'magic']],
    ],
]);
$alreadyAdjacentAirin = movementCard([
    'instanceId' => 4,
    'ukid' => 'airin-like',
    'row' => 1,
    'col' => 1,
    'prop' => ['airin_trigger' => true],
]);
$alreadyAdjacentState = movementState($alreadyAdjacentMover, $alreadyAdjacentAirin);
moveCard($alreadyAdjacentState, 3, 2, 2);
assertTrue(
    empty($alreadyAdjacentAirin->flags['airin_triggered_this_turn']),
    'Airin trigger should not fire when the mover was already adjacent.'
);

$nonMagicalMover = movementCard([
    'instanceId' => 5,
    'row' => 3,
    'col' => 3,
    'prop' => ['can_move_diagonal' => true],
]);
$nonMagicalAirin = movementCard([
    'instanceId' => 6,
    'ukid' => 'airin-like',
    'row' => 1,
    'col' => 1,
    'prop' => ['airin_trigger' => true],
]);
$nonMagicalState = movementState($nonMagicalMover, $nonMagicalAirin);
moveCard($nonMagicalState, 5, 2, 2);
assertTrue(
    empty($nonMagicalAirin->flags['airin_triggered_this_turn']),
    'Airin trigger should not fire for a non-magical mover.'
);

$limitedMover = movementCard([
    'instanceId' => 7,
    'row' => 3,
    'col' => 3,
    'prop' => [
        'can_move_diagonal' => true,
        'actions' => [['type' => 'magic']],
    ],
]);
$limitedAirin = movementCard([
    'instanceId' => 8,
    'ukid' => 'airin-like',
    'row' => 1,
    'col' => 1,
    'prop' => ['airin_trigger' => true],
    'flags' => ['airin_triggered_this_turn' => 2],
]);
$limitedState = movementState($limitedMover, $limitedAirin);
moveCard($limitedState, 7, 2, 2);
assertTrue(
    count($limitedAirin->modifiers) === 0,
    'Airin trigger should not add modifiers after reaching the per-turn limit.'
);

echo "Movement effect regression tests passed.\n";
