<?php
// tools/test_shaksi.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\ActionResolver;
use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Dice;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Movement\MovementResolver;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function shAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function shRolls(string $value): void
{
    $_SESSION = [];
    $_SESSION['debug_roll'] = $value;
    unset($_SESSION['debug_roll_state']);
    Dice::init();
}

function shCard(array $o): CardInstance
{
    return new CardInstance(
        instanceId: $o['instanceId'],
        ukid: $o['ukid'] ?? ('card_' . $o['instanceId']),
        owner: $o['owner'] ?? GameState::PLAYER_HOST,
        zone: $o['zone'] ?? CardInstance::ZONE_FIELD,
        row: $o['row'] ?? 3,
        col: $o['col'] ?? 3,
        hp: $o['hp'] ?? 8,
        hpMax: $o['hpMax'] ?? ($o['hp'] ?? 8),
        type: $o['type'] ?? 'creature',
        closed: $o['closed'] ?? false,
        move: $o['move'] ?? 1,
        moveMax: $o['moveMax'] ?? ($o['move'] ?? 1),
        strikeWeak: $o['strikeWeak'] ?? 1,
        strikeMedium: $o['strikeMedium'] ?? 2,
        strikeStrong: $o['strikeStrong'] ?? 3,
        coins: $o['coins'] ?? 0,
        prop: $o['prop'] ?? [],
        modifiers: $o['modifiers'] ?? [],
        markers: $o['markers'] ?? [],
        flags: $o['flags'] ?? [],
    );
}

function shState(CardInstance ...$cards): GameState
{
    $state = new GameState(185, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
    ];

    foreach ($cards as $card) {
        $state->addCard($card);
    }

    return $state;
}

function shaksiProp(): array
{
    return [
        'zoz' => true,
        'actions' => [
            ['type' => 'jump', 'range' => 3],
        ],
        'on_jump_roll' => [
            '1-2' => ['type' => 'damage', 'action_type' => 'impact', 'value' => 2, 'default_side' => 'enemy'],
            '3-4' => ['type' => 'heal', 'action_type' => 'impact', 'value' => 3, 'default_side' => 'own', 'include_self' => true],
            '5-6' => ['type' => 'get_coin', 'action_type' => 'impact', 'value' => 1, 'default_side' => 'own'],
        ],
    ];
}

function shJump(GameState $state, CardInstance $shaksi, int $row = 3, int $col = 4): void
{
    $result = (new MovementResolver($state, new Engine()))->jump(
        GameState::PLAYER_HOST,
        new Command('jump', ['card_id' => $shaksi->instanceId, 'row' => $row, 'col' => $col])
    );
    shAssert($result->success, $result->error ?? 'Shaksi jump should succeed.');
}

$shaksi = shCard([
    'instanceId' => 1,
    'ukid' => 's1_185',
    'row' => 3,
    'col' => 1,
    'prop' => shaksiProp(),
]);
$enemy = shCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5, 'hp' => 8, 'hpMax' => 8]);
$state = shState($shaksi, $enemy);
shRolls('2,6');
shJump($state, $shaksi);
$pending = $state->battle['pending_shaksi_roll'] ?? null;
shAssert(is_array($pending), 'Damage roll should open Shaksi pending choice.');
shAssert((int) ($pending['dice'] ?? 0) === 2, 'Shaksi pending should store the consumed dice.');
shAssert(($pending['effect']['type'] ?? '') === 'damage', 'Roll 1-2 should choose damage effect.');
shAssert(Dice::remaining() === [6], 'Shaksi jump should consume exactly one debug roll.');
$result = (new ActionResolver($state, new Engine()))->chooseShaksiRoll(
    GameState::PLAYER_HOST,
    new Command('choose_shaksi_roll', ['target_id' => $enemy->instanceId])
);
shAssert($result->success, $result->error ?? 'Shaksi damage choice should resolve.');
shAssert($enemy->hp === 6, 'Shaksi damage roll should deal 2 impact damage.');
shAssert(empty($state->battle['pending_shaksi_roll']), 'Shaksi pending should clear after damage choice.');

$shaksi = shCard([
    'instanceId' => 10,
    'ukid' => 's1_185',
    'row' => 3,
    'col' => 1,
    'hp' => 5,
    'hpMax' => 8,
    'prop' => shaksiProp(),
]);
$ally = shCard(['instanceId' => 11, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 5, 'hp' => 4, 'hpMax' => 8]);
$state = shState($shaksi, $ally);
shRolls('4');
shJump($state, $shaksi);
$pending = $state->battle['pending_shaksi_roll'] ?? null;
shAssert(is_array($pending), 'Heal roll should open Shaksi pending choice.');
shAssert(($pending['effect']['type'] ?? '') === 'heal', 'Roll 3-4 should choose heal effect.');
shAssert(in_array($shaksi->instanceId, $pending['candidates'] ?? [], true), 'Heal roll should include wounded Shaksi.');
$result = (new ActionResolver($state, new Engine()))->chooseShaksiRoll(
    GameState::PLAYER_HOST,
    new Command('choose_shaksi_roll', ['target_id' => $shaksi->instanceId])
);
shAssert($result->success, $result->error ?? 'Shaksi self-heal choice should resolve.');
shAssert($shaksi->hp === 8, 'Shaksi heal roll should heal self by up to 3.');

$shaksi = shCard([
    'instanceId' => 20,
    'ukid' => 's1_185',
    'row' => 3,
    'col' => 1,
    'prop' => shaksiProp(),
]);
$ally = shCard([
    'instanceId' => 21,
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 5,
    'coins' => 0,
    'prop' => ['coins' => ['max_value' => 1]],
]);
$state = shState($shaksi, $ally);
shRolls('6');
shJump($state, $shaksi);
$pending = $state->battle['pending_shaksi_roll'] ?? null;
shAssert(is_array($pending), 'Coin roll should open Shaksi pending choice.');
shAssert(($pending['effect']['type'] ?? '') === 'get_coin', 'Roll 5-6 should choose get_coin effect.');
$result = (new ActionResolver($state, new Engine()))->chooseShaksiRoll(
    GameState::PLAYER_HOST,
    new Command('choose_shaksi_roll', ['target_id' => $ally->instanceId])
);
shAssert($result->success, $result->error ?? 'Shaksi coin choice should resolve.');
shAssert($ally->coins === 1, 'Shaksi coin roll should give one coin.');

$shaksi = shCard([
    'instanceId' => 30,
    'ukid' => 's1_185',
    'row' => 3,
    'col' => 1,
    'prop' => shaksiProp(),
]);
$state = shState($shaksi);
shRolls('2');
shJump($state, $shaksi);
shAssert(empty($state->battle['pending_shaksi_roll']), 'Shaksi should not open pending when no adjacent candidates exist.');

echo "Shaksi tests passed.\n";
