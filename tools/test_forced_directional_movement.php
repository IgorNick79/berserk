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
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;

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
    [GameState::PLAYER_HOST, 3, 3, 3, 4, 4, 3, 4, 4],
    [GameState::PLAYER_HOST, 3, 3, 3, 2, 4, 3, 4, 2],
    [GameState::PLAYER_HOST, 3, 3, 4, 3, 4, 3, 5, 3],
    [GameState::PLAYER_HOST, 3, 3, 2, 3, 4, 3, 3, 3],
    [GameState::PLAYER_PLAYER, 4, 3, 4, 4, 2, 3, 2, 4],
    [GameState::PLAYER_PLAYER, 4, 3, 4, 2, 2, 3, 2, 2],
    [GameState::PLAYER_PLAYER, 4, 3, 5, 3, 2, 3, 3, 3],
    [GameState::PLAYER_PLAYER, 4, 3, 3, 3, 2, 3, 1, 3],
] as [$owner, $fromRow, $fromCol, $toRow, $toCol, $targetRow, $targetCol, $expectedRow, $expectedCol]) {
    $deltaRow = $toRow - $fromRow;
    $deltaCol = $toCol - $fromCol;
    $source = fdmCard(['instanceId' => 101, 'owner' => $owner, 'row' => $fromRow, 'col' => $fromCol, 'prop' => fdmProp()]);
    $targetOwner = $owner === GameState::PLAYER_HOST ? GameState::PLAYER_PLAYER : GameState::PLAYER_HOST;
    $target = fdmCard(['instanceId' => 102, 'owner' => $targetOwner, 'row' => $targetRow, 'col' => $targetCol]);
    $directionState = fdmState($source, $target);
    $directionState->battle['active'] = $owner;

    fdmMove($directionState, $source, $toRow, $toCol);
    fdmApply($directionState, $targetOwner, new Command('choose_forced_directional_move', ['target_id' => 102]));

    fdmAssert(
        $target->row === $expectedRow && $target->col === $expectedCol,
        "Forced movement should preserve source board delta ({$deltaRow},{$deltaCol}) for {$owner}."
    );
}

$source = fdmCard(['instanceId' => 1, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'prop' => fdmProp()]);
$target = fdmCard(['instanceId' => 2, 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 3]);
$state = fdmState($source, $target);
$state->battle['active'] = GameState::PLAYER_PLAYER;
fdmMove($state, $source, 4, 2);
$pending = fdmPending($state);
fdmAssert($pending['owner'] === GameState::PLAYER_HOST, 'Opponent should own the pending choice.');
fdmAssert(($pending['delta_row'] ?? null) === 0 && ($pending['delta_col'] ?? null) === -1, 'Source board delta should be stored in pending.');
fdmAssert(!isset($pending['stage']) && !isset($pending['selected_id']), 'Forced movement pending should be one-stage.');

fdmApplyFails($state, GameState::PLAYER_HOST, new Command('end_turn'), 'Pending should block unrelated commands.');
fdmApplyFails($state, GameState::PLAYER_HOST, new Command('choose_dice_choice', ['choice' => 'attack_plus']), 'Pending should block commands belonging to other ChoiceHandlers.');
fdmApply($state, GameState::PLAYER_HOST, new Command('choose_forced_directional_move', ['target_id' => 2]));
fdmAssertNoPending($state, 'Successful forced movement should clear pending.');
fdmAssert($target->row === 2 && $target->col === 2, 'Manual bug regression: target 2.3 should move to 2.2.');
fdmAssert(!($target->row === 2 && $target->col === 4), 'Manual bug regression: target 2.3 must not move to 2.4.');
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
$rooted = fdmCard(['instanceId' => 10, 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 5, 'markers' => ['rooted' => ['sources' => [99]]]]);
$blocked = fdmCard(['instanceId' => 11, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 2]);
$blocker = fdmCard(['instanceId' => 12, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 1]);
$markerBlocked = fdmCard(['instanceId' => 13, 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 4]);
$boundary = fdmCard(['instanceId' => 14, 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 1]);
$eligibilityState = fdmState($closed, $rooted, $blocked, $blocker, $markerBlocked, $boundary);
$eligibilityState->cell_markers['5_3'] = ['type' => 'test'];
$resolver = new ForcedMovementResolver($eligibilityState, new Engine());
$eligibleIds = array_map(fn(CardInstance $card) => $card->instanceId, $resolver->eligibleTargets(GameState::PLAYER_PLAYER, 0, -1));
fdmAssert(in_array(9, $eligibleIds, true), 'Closed creature should be eligible.');
fdmAssert(!in_array(10, $eligibleIds, true), 'Rooted creature should not be eligible.');
fdmAssert(!in_array(11, $eligibleIds, true), 'Occupied destination should not be eligible.');
fdmAssert(!in_array(13, $eligibleIds, true), 'Cell marker should prevent eligibility.');
fdmAssert(!in_array(14, $eligibleIds, true), 'Field boundary should prevent eligibility.');

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
fdmApplyFails($invalidState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['row' => 4, 'col' => 4]), 'Client-supplied cell without a target should be rejected.');

$staleBlocker = fdmCard(['instanceId' => 18, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 5]);
$invalidState->addCard($staleBlocker);
fdmApply($invalidState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['target_id' => 17]));
fdmAssertNoPending($invalidState, 'Stale target should clear pending.');
fdmAssert(fdmModifierCount($invalidSource, 'next_action_bonus') === 1, 'Stale target should apply fallback.');

$declineSource = fdmCard(['instanceId' => 19, 'row' => 3, 'col' => 3, 'prop' => fdmProp()]);
$declineTarget = fdmCard(['instanceId' => 20, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$declineState = fdmState($declineSource, $declineTarget);
fdmMove($declineState, $declineSource, 3, 4);
fdmApply($declineState, GameState::PLAYER_PLAYER, new Command('cancel_pending'));
fdmAssert(fdmModifierCount($declineSource, 'next_action_bonus') === 1, 'Decline should apply fallback.');
fdmApplyFails($declineState, GameState::PLAYER_PLAYER, new Command('cancel_pending'), 'Repeated cancel should not resolve again.');
fdmAssert(fdmModifierCount($declineSource, 'next_action_bonus') === 1, 'Repeated cancel should not duplicate fallback.');

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
fdmAssert(empty($effectTarget->flags['movement_direction_bonus']), 'Forced movement should not trigger movement_direction_bonus.');
fdmAssert(empty($effectState->battle[ForcedMovementResolver::PENDING_KEY]), 'Forced movement should not recursively open this mechanic.');

$airinSource = fdmCard(['instanceId' => 25, 'row' => 3, 'col' => 1, 'prop' => fdmProp()]);
$magicalTarget = fdmCard([
    'instanceId' => 26,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 5,
    'col' => 3,
    'prop' => ['actions' => [['type' => 'magic']]],
]);
$airin = fdmCard([
    'instanceId' => 27,
    'ukid' => 'airin-like',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 3,
    'prop' => ['airin_trigger' => true],
]);
$airinState = fdmState($airinSource, $magicalTarget, $airin);
fdmMove($airinState, $airinSource, 2, 1);
fdmApply($airinState, GameState::PLAYER_PLAYER, new Command('choose_forced_directional_move', ['target_id' => 26]));
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
fdmAssert(($throwState->battle['strike']['next_action_bonus'] ?? 0) === 2, 'Server result should expose applied next_action_bonus.');
$throwHtml = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render(
    $throwState,
    GameState::PLAYER_HOST,
    'host',
    [
        'fdm-card' => ['name' => 'Thrower'],
    ],
    '?first&game=4'
);
fdmAssert(
    str_contains($throwHtml, '<p class="bonus">+2 к метанию</p>'),
    'Throw result UI should display next_action_bonus using the existing bonus paragraph.'
);
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
