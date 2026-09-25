<?php
// tools/test_battle_helper_movement.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\BattleHelper;
use Berserk\Core\CardInstance;
use Berserk\Core\GameState;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSameArray(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function battleStateWith(CardInstance $card, array $cellMarkers = []): GameState
{
    $state = new GameState(1, 101, 202);
    $state->status = 'battle';
    $state->battle = ['active' => GameState::PLAYER_HOST];
    $state->cell_markers = $cellMarkers;
    $state->addCard($card);

    return $state;
}

function fieldCard(array $overrides = []): CardInstance
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

$rootedMover = fieldCard([
    'markers' => ['rooted' => ['sources' => [99]]],
]);
$rootedMoveState = battleStateWith($rootedMover);
assertSameArray(
    [],
    BattleHelper::getMoveCells($rootedMoveState, $rootedMover),
    'Rooted card must not receive normal move cells.'
);

$rootedJumper = fieldCard([
    'prop' => ['actions' => [['type' => 'jump', 'range' => 2]]],
    'markers' => ['rooted' => ['sources' => [99]]],
]);
$rootedJumpState = battleStateWith($rootedJumper);
assertSameArray(
    [],
    BattleHelper::getJumpCells($rootedJumpState, $rootedJumper),
    'Rooted card must not receive jump cells.'
);

$jumper = fieldCard([
    'prop' => ['actions' => [['type' => 'jump', 'range' => 1]]],
]);
$openJumpState = battleStateWith($jumper);
$openJumpCells = BattleHelper::getJumpCells($openJumpState, $jumper);
assertTrue(
    isset($openJumpCells['3_4']),
    'Unoccupied cell inside jump range should be a valid jump target.'
);

$markedJumpState = battleStateWith($jumper, [
    '3_4' => ['type' => 'bonfire'],
]);
$markedJumpCells = BattleHelper::getJumpCells($markedJumpState, $jumper);
assertTrue(
    !isset($markedJumpCells['3_4']),
    'Cell marker must exclude that cell from jump targets.'
);

echo "BattleHelper movement regression tests passed.\n";
