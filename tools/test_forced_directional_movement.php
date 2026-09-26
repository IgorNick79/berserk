<?php
// tools/test_forced_directional_movement.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Movement\ForcedMovementResolver;
use Berserk\Core\Movement\MovementResolver;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function fdmAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fdmState(CardInstance ...$cards): GameState
{
    $state = new GameState(4, 101, 202);
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

function fdmCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'] ?? 1,
        ukid: $overrides['ukid'] ?? 'fdm-card',
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 10,
        hpMax: $overrides['hpMax'] ?? 10,
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 3,
        moveMax: $overrides['moveMax'] ?? 3,
        prop: $overrides['prop'] ?? [],
        modifiers: $overrides['modifiers'] ?? [],
        markers: $overrides['markers'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function fdmProp(array $overrides = []): array
{
    return array_replace_recursive([
        'force_opponent_directional_move' => [
            'distance' => 1,
            'fallback' => [
                'modifier' => [
                    'stat' => 'next_action_bonus',
                    'value' => 2,
                    'types' => ['throw'],
                    'consume' => true,
                    'expire' => 'end_of_turn',
                ],
            ],
        ],
    ], $overrides);
}

function fdmMove(GameState $state, CardInstance $card, int $row, int $col): void
{
    $result = (new MovementResolver($state, new Engine()))->move($card->owner, new Command('move', [
        'card_id' => $card->instanceId,
        'row' => $row,
        'col' => $col,
    ]));

    fdmAssert($result->success, $result->error ?? 'Move failed.');
}

function fdmJump(GameState $state, CardInstance $card, int $row, int $col): void
{
    $result = (new MovementResolver($state, new Engine()))->jump($card->owner, new Command('jump', [
        'card_id' => $card->instanceId,
        'row' => $row,
        'col' => $col,
    ]));

    fdmAssert($result->success, $result->error ?? 'Jump failed.');
}

function fdmApply(GameState $state, string $playerKey, Command $cmd): void
{
    $result = (new Engine())->apply($state, $playerKey, $cmd);
    fdmAssert($result->success, $result->error ?? 'Command failed.');
}

function fdmApplyFails(GameState $state, string $playerKey, Command $cmd, string $message): void
{
    $result = (new Engine())->apply($state, $playerKey, $cmd);
    fdmAssert(!$result->success, $message);
}

function fdmPending(GameState $state): array
{
    $pending = $state->battle[ForcedMovementResolver::PENDING_KEY] ?? null;
    fdmAssert(is_array($pending), 'Forced directional movement pending should exist.');
    return $pending;
}

function fdmModifierCount(CardInstance $card, string $stat): int
{
    $count = 0;
    foreach ($card->modifiers as $modifier) {
        if (($modifier['stat'] ?? null) === $stat) {
            $count++;
        }
    }
    return $count;
}

function fdmAssertNoPending(GameState $state, string $message): void
{
    fdmAssert(empty($state->battle[ForcedMovementResolver::PENDING_KEY]), $message);
}

foreach ([
    [GameState::PLAYER_HOST, 1, 0, 'forward', [1, 0], [-1, 0]],
    [GameState::PLAYER_HOST, -1, 0, 'backward', [-1, 0], [1, 0]],
    [GameState::PLAYER_HOST, 0, 1, 'right', [0, 1], [0, -1]],
    [GameState::PLAYER_HOST, 0, -1, 'left', [0, -1], [0, 1]],
    [GameState::PLAYER_PLAYER, -1, 0, 'forward', [-1, 0], [1, 0]],
    [GameState::PLAYER_PLAYER, 1, 0, 'backward', [1, 0], [-1, 0]],
    [GameState::PLAYER_PLAYER, 0, -1, 'right', [0, -1], [0, 1]],
    [GameState::PLAYER_PLAYER, 0, 1, 'left', [0, 1], [0, -1]],
] as [$owner, $dr, $dc, $direction, $sourceDelta, $opponentDelta]) {
    fdmAssert(
        ForcedMovementResolver::relativeDirectionForDelta($owner, $dr, $dc) === $direction,
        "Relative direction should match {$owner} {$direction}."
    );
    fdmAssert(
        ForcedMovementResolver::boardDeltaForPlayer($owner, $direction) === $sourceDelta,
        "Source board delta should match {$owner} {$direction}."
    );
    $opponent = $owner === GameState::PLAYER_HOST ? GameState::PLAYER_PLAYER : GameState::PLAYER_HOST;
    fdmAssert(
        ForcedMovementResolver::boardDeltaForPlayer($opponent, $direction) === $opponentDelta,
        "Opponent board delta should mirror {$owner} {$direction}."
    );
}

$source = fdmCard(['instanceId' => 1, 'row' => 3, 'col' => 3, 'prop' => fdmProp()]);
$target = fdmCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$state = fdmState($source, $target);
fdmMove($state, $source, 3, 4);
$pending = fdmPending($state);
fdmAssert($pending['owner'] === GameState::PLAYER_PLAYER, 'Opponent should own the pending choice.');
fdmAssert($pending['relative_direction'] === 'right', 'Host right move should be stored as relative right.');

fdmApplyFails($state, GameState::PLAYER_HOST, new Command('end_turn'), 'Pending should block unrelated commands.');
fdmApply($state, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['target_id' => 2]));
$pending = fdmPending($state);
fdmAssert($pending['stage'] === 'cell' && $pending['selected_id'] === 2, 'Stage 1 should select a card.');
fdmApply($state, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['row' => 4, 'col' => 3]));
fdmAssertNoPending($state, 'Successful forced movement should clear pending.');
fdmAssert($target->row === 4 && $target->col === 3, 'Opponent target should move to mirrored right destination.');
fdmAssert($target->move === 3, 'Forced movement should not consume move.');
fdmAssert(empty($target->flags['moved_this_turn']), 'Forced movement should not set moved_this_turn.');

$jumpSource = fdmCard([
    'instanceId' => 3,
    'row' => 3,
    'col' => 3,
    'prop' => fdmProp(['actions' => [['type' => 'jump', 'range' => 1]]]),
]);
$jumpTarget = fdmCard(['instanceId' => 4, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$jumpState = fdmState($jumpSource, $jumpTarget);
fdmJump($jumpState, $jumpSource, 3, 4);
fdmAssertNoPending($jumpState, 'Jump should not trigger forced directional movement.');

$diagonalSource = fdmCard([
    'instanceId' => 5,
    'row' => 3,
    'col' => 3,
    'prop' => fdmProp(['can_move_diagonal' => true]),
]);
$diagonalTarget = fdmCard(['instanceId' => 6, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$diagonalState = fdmState($diagonalSource, $diagonalTarget);
fdmMove($diagonalState, $diagonalSource, 2, 2);
fdmAssertNoPending($diagonalState, 'Diagonal movement should not trigger forced directional movement.');

$normalSource = fdmCard(['instanceId' => 7, 'row' => 3, 'col' => 3]);
$normalTarget = fdmCard(['instanceId' => 8, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$normalState = fdmState($normalSource, $normalTarget);
fdmMove($normalState, $normalSource, 3, 4);
fdmAssertNoPending($normalState, 'Card without prop should not trigger forced directional movement.');

$closed = fdmCard(['instanceId' => 9, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4, 'closed' => true]);
$rooted = fdmCard(['instanceId' => 10, 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 4, 'markers' => ['rooted' => ['sources' => [99]]]]);
$blocked = fdmCard(['instanceId' => 11, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 2]);
$blocker = fdmCard(['instanceId' => 12, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 1]);
$boundary = fdmCard(['instanceId' => 13, 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 1]);
$eligibilityState = fdmState($closed, $rooted, $blocked, $blocker, $boundary);
$eligibilityState->cell_markers['5_3'] = ['type' => 'test'];
$resolver = new ForcedMovementResolver($eligibilityState, new Engine());
$eligibleIds = array_map(fn(CardInstance $card) => $card->instanceId, $resolver->eligibleTargets(GameState::PLAYER_PLAYER, 'right'));
fdmAssert(in_array(9, $eligibleIds, true), 'Closed creature should be eligible.');
fdmAssert(!in_array(10, $eligibleIds, true), 'Rooted creature should not be eligible.');
fdmAssert(!in_array(11, $eligibleIds, true), 'Occupied destination should not be eligible.');
fdmAssert(!in_array(13, $eligibleIds, true), 'Cell marker should prevent eligibility.');

$fallbackSource = fdmCard(['instanceId' => 14, 'row' => 3, 'col' => 3, 'prop' => fdmProp()]);
$fallbackTarget = fdmCard(['instanceId' => 15, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 1]);
$fallbackState = fdmState($fallbackSource, $fallbackTarget);
fdmMove($fallbackState, $fallbackSource, 3, 4);
fdmAssertNoPending($fallbackState, 'No legal targets should avoid meaningless pending.');
fdmAssert(fdmModifierCount($fallbackSource, 'next_action_bonus') === 1, 'No legal targets should apply fallback.');

$invalidSource = fdmCard(['instanceId' => 16, 'row' => 3, 'col' => 3, 'prop' => fdmProp()]);
$invalidTarget = fdmCard(['instanceId' => 17, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$invalidState = fdmState($invalidSource, $invalidTarget);
fdmMove($invalidState, $invalidSource, 3, 4);
fdmApplyFails($invalidState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['target_id' => 16]), 'Opponent cannot select another player card.');
fdmApplyFails($invalidState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['target_id' => 999]), 'Invalid card id should be rejected.');
fdmApply($invalidState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['target_id' => 17]));
fdmApplyFails($invalidState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['row' => 4, 'col' => 4]), 'Wrong cell should be rejected.');

$staleBlocker = fdmCard(['instanceId' => 18, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 3]);
$invalidState->addCard($staleBlocker);
fdmApply($invalidState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['row' => 4, 'col' => 3]));
fdmAssertNoPending($invalidState, 'Stale target should clear pending.');
fdmAssert(fdmModifierCount($invalidSource, 'next_action_bonus') === 1, 'Stale target should apply fallback.');

$declineSource = fdmCard(['instanceId' => 19, 'row' => 3, 'col' => 3, 'prop' => fdmProp()]);
$declineTarget = fdmCard(['instanceId' => 20, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$declineState = fdmState($declineSource, $declineTarget);
fdmMove($declineState, $declineSource, 3, 4);
fdmApply($declineState, GameState::PLAYER_PLAYER, new Command('cancel_pending'));
fdmAssert(fdmModifierCount($declineSource, 'next_action_bonus') === 1, 'Decline at card stage should apply fallback.');
fdmApplyFails($declineState, GameState::PLAYER_PLAYER, new Command('cancel_pending'), 'Repeated cancel should not resolve again.');
fdmAssert(fdmModifierCount($declineSource, 'next_action_bonus') === 1, 'Repeated cancel should not duplicate fallback.');

$declineCellSource = fdmCard(['instanceId' => 21, 'row' => 3, 'col' => 3, 'prop' => fdmProp()]);
$declineCellTarget = fdmCard(['instanceId' => 22, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$declineCellState = fdmState($declineCellSource, $declineCellTarget);
fdmMove($declineCellState, $declineCellSource, 3, 4);
fdmApply($declineCellState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['target_id' => 22]));
fdmApply($declineCellState, GameState::PLAYER_PLAYER, new Command('cancel_pending'));
fdmAssert(fdmModifierCount($declineCellSource, 'next_action_bonus') === 1, 'Decline at cell stage should apply fallback.');

$effectSource = fdmCard(['instanceId' => 23, 'row' => 3, 'col' => 3, 'prop' => fdmProp()]);
$effectTarget = fdmCard([
    'instanceId' => 24,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 4,
    'prop' => [
        'movement_direction_bonus' => [
            'same_direction' => [
                'moves' => 1,
                'modifier' => ['stat' => 'ova', 'value' => 9],
            ],
        ],
        'force_opponent_directional_move' => [
            'distance' => 1,
            'fallback' => [
                'modifier' => [
                    'stat' => 'next_action_bonus',
                    'value' => 2,
                    'types' => ['throw'],
                    'consume' => true,
                    'expire' => 'end_of_turn',
                ],
            ],
        ],
    ],
]);
$effectState = fdmState($effectSource, $effectTarget);
fdmMove($effectState, $effectSource, 3, 4);
fdmApply($effectState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['target_id' => 24]));
fdmApply($effectState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['row' => 4, 'col' => 3]));
fdmAssert(empty($effectTarget->flags['movement_direction_bonus']), 'Forced movement should not trigger movement_direction_bonus.');
fdmAssert(empty($effectState->battle[ForcedMovementResolver::PENDING_KEY]), 'Forced movement should not recursively open this mechanic.');

$airinSource = fdmCard(['instanceId' => 25, 'row' => 3, 'col' => 3, 'prop' => fdmProp()]);
$magicalTarget = fdmCard([
    'instanceId' => 26,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 4,
    'prop' => ['actions' => [['type' => 'magic']]],
]);
$airin = fdmCard([
    'instanceId' => 27,
    'ukid' => 'airin-like',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 2,
    'prop' => ['airin_trigger' => true],
]);
$airinState = fdmState($airinSource, $magicalTarget, $airin);
fdmMove($airinState, $airinSource, 3, 4);
fdmApply($airinState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['target_id' => 26]));
fdmApply($airinState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['row' => 4, 'col' => 3]));
fdmAssert(($airin->flags['airin_triggered_this_turn'] ?? 0) === 1, 'Forced magical movement should trigger Airin.');

$alreadyAirinSource = fdmCard(['instanceId' => 28, 'row' => 3, 'col' => 3, 'prop' => fdmProp()]);
$alreadyMagicalTarget = fdmCard([
    'instanceId' => 29,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 4,
    'prop' => ['actions' => [['type' => 'magic']]],
]);
$alreadyAirin = fdmCard([
    'instanceId' => 30,
    'ukid' => 'airin-like',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 3,
    'prop' => ['airin_trigger' => true],
]);
$alreadyAirinState = fdmState($alreadyAirinSource, $alreadyMagicalTarget, $alreadyAirin);
fdmMove($alreadyAirinState, $alreadyAirinSource, 3, 4);
fdmApply($alreadyAirinState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['target_id' => 29]));
fdmApply($alreadyAirinState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['row' => 4, 'col' => 3]));
fdmAssert(empty($alreadyAirin->flags['airin_triggered_this_turn']), 'Airin should not trigger when already adjacent.');

$nonMagicalAirinSource = fdmCard(['instanceId' => 31, 'row' => 3, 'col' => 3, 'prop' => fdmProp()]);
$nonMagicalTarget = fdmCard(['instanceId' => 32, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$nonMagicalAirin = fdmCard([
    'instanceId' => 33,
    'ukid' => 'airin-like',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 2,
    'prop' => ['airin_trigger' => true],
]);
$nonMagicalAirinState = fdmState($nonMagicalAirinSource, $nonMagicalTarget, $nonMagicalAirin);
fdmMove($nonMagicalAirinState, $nonMagicalAirinSource, 3, 4);
fdmApply($nonMagicalAirinState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['target_id' => 32]));
fdmApply($nonMagicalAirinState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['row' => 4, 'col' => 3]));
fdmAssert(empty($nonMagicalAirin->flags['airin_triggered_this_turn']), 'Airin should not trigger for non-magical forced movement.');

$thrower = fdmCard([
    'instanceId' => 34,
    'row' => 3,
    'col' => 4,
    'prop' => [
        'actions' => [[
            'type' => 'throw',
            'range' => 9,
            'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 1],
            'no_close' => true,
        ]],
    ],
    'modifiers' => [[
        'stat' => 'next_action_bonus',
        'value' => 2,
        'types' => ['throw'],
        'consume' => true,
        'expire' => 'end_of_turn',
    ]],
]);
$victim = fdmCard(['instanceId' => 35, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5, 'hp' => 10, 'hpMax' => 10]);
$throwState = fdmState($thrower, $victim);
fdmApply($throwState, GameState::PLAYER_HOST, new Command('action', ['card_id' => 34, 'target_id' => 35, 'action_key' => 'throw']));
fdmAssert(($throwState->battle['strike']['damage_total'] ?? 0) === 3, 'First throw should receive next_action_bonus.');
fdmAssert(fdmModifierCount($thrower, 'next_action_bonus') === 0, 'Next throw bonus should be consumed.');
$throwState->battle['strike'] = null;
fdmApply($throwState, GameState::PLAYER_HOST, new Command('action', ['card_id' => 34, 'target_id' => 35, 'action_key' => 'throw']));
fdmAssert(($throwState->battle['strike']['damage_total'] ?? 0) === 1, 'Second throw should not receive consumed bonus.');

$expiring = fdmCard([
    'instanceId' => 36,
    'modifiers' => [[
        'stat' => 'next_action_bonus',
        'value' => 2,
        'types' => ['throw'],
        'consume' => true,
        'expire' => 'end_of_turn',
    ]],
]);
$expireState = fdmState($expiring);
(new Berserk\Core\TurnProcessor($expireState, new Engine()))->afterEndPhase(GameState::PLAYER_HOST, GameState::PLAYER_PLAYER);
fdmAssert(fdmModifierCount($expiring, 'next_action_bonus') === 0, 'Unused next_action_bonus should expire at end_of_turn.');

echo "Forced directional movement regression tests passed.\n";
