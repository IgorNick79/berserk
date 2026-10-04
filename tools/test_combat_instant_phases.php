<?php
// tools/test_combat_instant_phases.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\InstantProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function cipAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cipCard(array $overrides): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'],
        ukid: $overrides['ukid'] ?? ('card_' . $overrides['instanceId']),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 5,
        hpMax: $overrides['hpMax'] ?? ($overrides['hp'] ?? 5),
        type: 'creature',
        move: 1,
        moveMax: 1,
        strikeWeak: $overrides['strikeWeak'] ?? 1,
        strikeMedium: $overrides['strikeMedium'] ?? 2,
        strikeStrong: $overrides['strikeStrong'] ?? 3,
        prop: $overrides['prop'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function cipState(CardInstance ...$cards): GameState
{
    $state = new GameState(9101, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => [
            'attacker_id' => 1,
            'target_id' => 2,
            'defender_id' => null,
            'state' => 'waiting_instant',
            'attack_dice' => 2,
            'defend_dice' => 0,
            'attack_mod' => 0,
            'defend_mod' => 0,
            'result' => ['attack' => 'weak', 'defend' => '', 'winner' => 'attack'],
            'confirmed' => [],
            'instant_phase' => 'combat',
            'instant_priority' => GameState::PLAYER_HOST,
            'instant_passed' => [],
            'instant_next_sequence' => 0,
            'instant_stack' => [],
        ],
    ];

    foreach ($cards as $card) {
        $state->addCard($card);
    }

    return $state;
}

$attacker = cipCard(['instanceId' => 1, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$target = cipCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]);
$mary = cipCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$ost = cipCard(['instanceId' => 4, 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 3]);
$state = cipState($attacker, $target, $mary, $ost);

$state->battle['strike']['instant_stack'] = [
    [
        'card_id' => $mary->instanceId,
        'effect' => ['type' => 'damage_on_dice', 'value' => 1, 'damage' => 2],
        'target_id' => $mary->instanceId,
        'player' => GameState::PLAYER_PLAYER,
        'label' => 'Черная метка',
        'phase' => 'dice',
        'sequence' => 0,
    ],
    [
        'card_id' => $ost->instanceId,
        'effect' => ['type' => 'strike_level', 'mode' => 'set', 'value' => 'strong'],
        'target_id' => $ost->instanceId,
        'player' => GameState::PLAYER_HOST,
        'label' => 'Дар силы',
        'phase' => 'power',
        'sequence' => 1,
    ],
];

(new InstantProcessor($state, new Engine()))->resolveStack();
$summary = $state->battle['strike']['instant_summary'] ?? [];
cipAssert(($summary[0]['phase'] ?? null) === 'dice', 'Dice phase should resolve before power despite global LIFO.');
cipAssert(($summary[1]['phase'] ?? null) === 'power', 'Power phase should resolve after dice.');
cipAssert(($state->battle['strike']['result']['attack'] ?? null) === 'strong', 'Power instant should still apply.');

$attacker = cipCard(['instanceId' => 1, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'hp' => 5]);
$target = cipCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]);
$mary = cipCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$catcher = cipCard(['instanceId' => 4, 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 4]);
$state = cipState($attacker, $target, $mary, $catcher);
$state->battle['strike']['instant_stack'] = [
    [
        'card_id' => $mary->instanceId,
        'effect' => ['type' => 'damage_on_dice', 'value' => 1, 'damage' => 2],
        'target_id' => $mary->instanceId,
        'player' => GameState::PLAYER_PLAYER,
        'label' => 'Черная метка',
        'phase' => 'dice',
        'sequence' => 0,
    ],
    [
        'card_id' => $catcher->instanceId,
        'effect' => ['type' => 'dice_choice'],
        'target_id' => $catcher->instanceId,
        'player' => GameState::PLAYER_PLAYER,
        'label' => 'Удача',
        'phase' => 'dice',
        'sequence' => 1,
    ],
];

(new InstantProcessor($state, new Engine()))->resolveStack();
cipAssert(!empty($state->battle['pending_dice_choice']), 'Fortune Catcher should pause resolution for dice choice.');
cipAssert(count($state->battle['strike']['instant_resolution']['queue'] ?? []) === 2, 'Paused queue should keep Catcher and Mary.');

$result = (new InstantProcessor($state, new Engine()))->applyDiceChoice(
    GameState::PLAYER_PLAYER,
    'minus:enemy'
);
cipAssert($result->success, $result->error ?? 'Dice choice should resolve.');
cipAssert(empty($state->battle['pending_dice_choice']), 'Dice pending should close after choice.');
cipAssert($attacker->hp === 3, 'Mary should see the modified die and damage the attacker after Catcher.');
cipAssert(empty($state->battle['strike']['instant_resolution'] ?? []), 'Resolution queue should be exhausted.');

echo "Combat instant phase tests passed.\n";
