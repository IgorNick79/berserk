<?php
// tools/test_riala_movement.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Movement\MovementResolver;
use Berserk\Core\TurnProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function rialaAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rialaState(CardInstance ...$cards): GameState
{
    $state = new GameState(3, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
        'hidden_row_revealed' => true,
    ];

    foreach ($cards as $card) {
        $state->addCard($card);
    }

    return $state;
}

function rialaCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'] ?? 1,
        ukid: $overrides['ukid'] ?? 'test-riala',
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 8,
        hpMax: $overrides['hpMax'] ?? 8,
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 6,
        moveMax: $overrides['moveMax'] ?? 6,
        prop: $overrides['prop'] ?? rialaMovementDirectionBonus(),
        markers: $overrides['markers'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function rialaMovementDirectionBonus(array $overrides = []): array
{
    $config = [
        'same_direction' => [
            'moves' => 2,
            'modifier' => [
                'stat' => 'direct',
                'value' => true,
            ],
        ],
        'different_directions' => [
            'count' => 2,
            'modifier' => [
                'stat' => 'ova',
                'value' => 2,
            ],
        ],
        'expire' => 'end_of_turn',
    ];

    foreach ($overrides as $key => $value) {
        $config[$key] = $value;
    }

    return ['movement_direction_bonus' => $config];
}

function rialaMove(GameState $state, CardInstance $card, int $row, int $col): void
{
    $resolver = new MovementResolver($state, new Engine());
    $result = $resolver->move($card->owner, new Command('move', [
        'card_id' => $card->instanceId,
        'row' => $row,
        'col' => $col,
    ]));

    rialaAssert($result->success, $result->error ?? 'Move failed.');
}

function rialaJump(GameState $state, CardInstance $card, int $row, int $col): void
{
    $resolver = new MovementResolver($state, new Engine());
    $result = $resolver->jump($card->owner, new Command('jump', [
        'card_id' => $card->instanceId,
        'row' => $row,
        'col' => $col,
    ]));

    rialaAssert($result->success, $result->error ?? 'Jump failed.');
}

function rialaModifierCount(CardInstance $card, string $stat): int
{
    $count = 0;
    foreach ($card->modifiers as $modifier) {
        if (($modifier['source'] ?? null) === 'movement_direction_bonus'
            && ($modifier['stat'] ?? null) === $stat) {
            $count++;
        }
    }

    return $count;
}

function rialaOvaValue(CardInstance $card): int
{
    $value = 0;
    foreach ($card->modifiers as $modifier) {
        if (($modifier['source'] ?? null) === 'movement_direction_bonus'
            && ($modifier['stat'] ?? null) === 'ova') {
            $value += (int) ($modifier['value'] ?? 0);
        }
    }

    return $value;
}

function rialaAssertBonuses(
    CardInstance $card,
    int $directCount,
    int $ovaCount,
    string $message,
): void {
    rialaAssert(
        rialaModifierCount($card, 'direct') === $directCount,
        $message . ' direct count mismatch.'
    );
    rialaAssert(
        rialaModifierCount($card, 'ova') === $ovaCount,
        $message . ' ova count mismatch.'
    );
    if ($ovaCount > 0) {
        rialaAssert(
            rialaOvaValue($card) === 2,
            $message . ' ova value should be +2.'
        );
    }
}

$oneMove = rialaCard();
$oneMoveState = rialaState($oneMove);
rialaMove($oneMoveState, $oneMove, 3, 4);
rialaAssertBonuses($oneMove, 0, 0, 'One movement should grant no bonuses.');

$sameDirection = rialaCard();
$sameDirectionState = rialaState($sameDirection);
rialaMove($sameDirectionState, $sameDirection, 3, 4);
rialaMove($sameDirectionState, $sameDirection, 3, 5);
rialaAssertBonuses($sameDirection, 1, 0, 'Two moves in the same direction should grant direct only.');

$differentDirections = rialaCard();
$differentDirectionsState = rialaState($differentDirections);
rialaMove($differentDirectionsState, $differentDirections, 3, 4);
rialaMove($differentDirectionsState, $differentDirections, 3, 3);
rialaAssertBonuses($differentDirections, 0, 1, 'Two different directions should grant ova only.');

$rightRightLeft = rialaCard();
$rightRightLeftState = rialaState($rightRightLeft);
rialaMove($rightRightLeftState, $rightRightLeft, 3, 4);
rialaMove($rightRightLeftState, $rightRightLeft, 3, 5);
rialaMove($rightRightLeftState, $rightRightLeft, 3, 4);
rialaAssertBonuses($rightRightLeft, 1, 1, 'Right, right, left should grant both bonuses.');

$rightLeftRight = rialaCard();
$rightLeftRightState = rialaState($rightLeftRight);
rialaMove($rightLeftRightState, $rightLeftRight, 3, 4);
rialaMove($rightLeftRightState, $rightLeftRight, 3, 3);
rialaMove($rightLeftRightState, $rightLeftRight, 3, 4);
rialaAssertBonuses($rightLeftRight, 1, 1, 'Right, left, right should eventually grant both bonuses.');

$jumpingRiala = rialaCard([
    'prop' => [
        'movement_direction_bonus' => rialaMovementDirectionBonus()['movement_direction_bonus'],
        'actions' => [['type' => 'jump', 'range' => 2]],
    ],
]);
$jumpingState = rialaState($jumpingRiala);
rialaJump($jumpingState, $jumpingRiala, 3, 4);
rialaAssertBonuses($jumpingRiala, 0, 0, 'Jump should not count for Riala movement.');
rialaAssert(
    empty($jumpingRiala->flags['movement_direction_bonus']),
    'Jump should not update Riala movement direction history.'
);

$duplicates = rialaCard();
$duplicatesState = rialaState($duplicates);
rialaMove($duplicatesState, $duplicates, 3, 4);
rialaMove($duplicatesState, $duplicates, 3, 5);
rialaMove($duplicatesState, $duplicates, 3, 4);
rialaMove($duplicatesState, $duplicates, 3, 3);
rialaAssertBonuses($duplicates, 1, 1, 'Further movement should not duplicate Riala bonuses.');

$normalCard = rialaCard([
    'prop' => [],
]);
$normalState = rialaState($normalCard);
rialaMove($normalState, $normalCard, 3, 4);
rialaMove($normalState, $normalCard, 3, 5);
rialaAssertBonuses($normalCard, 0, 0, 'Non-Riala cards should not receive Riala bonuses.');
rialaAssert(
    empty($normalCard->flags['movement_direction_bonus']),
    'Non-Riala cards should not track Riala directions.'
);

$customConfig = rialaCard([
    'col' => 2,
    'prop' => rialaMovementDirectionBonus([
        'same_direction' => [
            'moves' => 3,
            'modifier' => [
                'stat' => 'ova',
                'value' => 3,
            ],
        ],
        'different_directions' => [
            'count' => 3,
            'modifier' => [
                'stat' => 'ability_strike',
                'value' => 4,
            ],
        ],
    ]),
]);
$customConfigState = rialaState($customConfig);
rialaMove($customConfigState, $customConfig, 3, 3);
rialaMove($customConfigState, $customConfig, 3, 4);
rialaAssert(
    rialaModifierCount($customConfig, 'ova') === 0,
    'Custom same-direction threshold should not trigger before configured move count.'
);
rialaMove($customConfigState, $customConfig, 3, 5);
rialaAssert(
    rialaModifierCount($customConfig, 'ova') === 1
    && rialaOvaValue($customConfig) === 3,
    'Custom same-direction modifier stat/value should come from configuration.'
);
rialaAssert(
    rialaModifierCount($customConfig, 'ability_strike') === 0,
    'Custom different-directions threshold should not trigger before configured direction count.'
);

$cleanup = rialaCard();
$cleanupState = rialaState($cleanup);
rialaMove($cleanupState, $cleanup, 3, 4);
rialaMove($cleanupState, $cleanup, 3, 5);
(new TurnProcessor($cleanupState, new Engine()))->afterEndPhase(GameState::PLAYER_HOST, GameState::PLAYER_PLAYER);
rialaAssertBonuses($cleanup, 0, 0, 'End of turn should expire Riala bonuses.');
rialaAssert(
    empty($cleanup->flags['movement_direction_bonus']),
    'End of turn should clear Riala movement runtime flags.'
);

echo "Riala movement regression tests passed.\n";
