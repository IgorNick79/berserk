<?php
// tools/test_storm_caller.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\TurnProcessor;
use Berserk\Core\ZoneManager;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function scAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function scStormProp(): array
{
    return [
        'actions' => [
            [
                'key' => 'frost_mist_rain',
                'name' => 'Мороз, мгла и ливень',
                'type' => 'mark_opponent_row',
                'row_count' => 3,
                'marker' => [
                    'type' => 'reduce_attack',
                    'value' => 1,
                    'timing' => 'end_of_opponent_turn',
                    'expire' => 1,
                    'filter' => [
                        'enemy' => true,
                        'elite' => false,
                    ],
                ],
            ],
            [
                'key' => 'storm_discharge',
                'name' => 'Разряд',
                'type' => 'discharge',
                'strike' => ['weak' => 1, 'medium' => 2, 'strong' => 2],
                'range' => 4,
            ],
        ],
    ];
}

function scShotProp(int $value = 1): array
{
    return [
        'actions' => [
            [
                'key' => 'shot',
                'name' => 'Выстрел',
                'type' => 'shot',
                'value' => $value,
                'range' => 4,
            ],
        ],
        'coins' => [
            'shot' => [
                'value' => 1,
                'spend' => 'all',
            ],
        ],
    ];
}

function scImpactProp(): array
{
    return [
        'actions' => [
            [
                'key' => 'impact',
                'name' => 'Воздействие',
                'type' => 'impact',
                'value' => 5,
                'target' => 'all_near',
                'filter' => 'enemy',
            ],
        ],
    ];
}

function scCard(array $overrides): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'],
        ukid: $overrides['ukid'] ?? ('card_' . $overrides['instanceId']),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        slot: $overrides['slot'] ?? 0,
        hp: $overrides['hp'] ?? 10,
        hpMax: $overrides['hpMax'] ?? ($overrides['hp'] ?? 10),
        elite: $overrides['elite'] ?? false,
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        armor: $overrides['armor'] ?? 0,
        strikeWeak: $overrides['strikeWeak'] ?? 5,
        strikeMedium: $overrides['strikeMedium'] ?? 5,
        strikeStrong: $overrides['strikeStrong'] ?? 5,
        coins: $overrides['coins'] ?? 0,
        prop: $overrides['prop'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function scStorm(array $overrides = []): CardInstance
{
    return scCard(array_merge([
        'instanceId' => 1,
        'ukid' => 's1_51',
        'owner' => GameState::PLAYER_HOST,
        'row' => 3,
        'col' => 3,
        'hp' => 7,
        'hpMax' => 7,
        'strikeWeak' => 1,
        'strikeMedium' => 2,
        'strikeStrong' => 2,
        'prop' => scStormProp(),
    ], $overrides));
}

function scState(CardInstance ...$cards): GameState
{
    $state = new GameState(51, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
        'hidden_row_revealed' => true,
    ];
    foreach ($cards as $card) {
        $state->cards[$card->instanceId] = $card;
    }
    return $state;
}

function scStartStormRow(GameState $state, Engine $engine, int $row = 1): void
{
    $result = $engine->apply($state, GameState::PLAYER_HOST, new Command('action', [
        'card_id' => 1,
        'target_id' => 1,
        'action_key' => 'frost_mist_rain',
    ]));
    scAssert($result->success, 'Storm row action should start: ' . ($result->error ?? ''));
    scAssert(!empty($state->battle['pending_opponent_row_marker']), 'Storm row action should create pending.');

    $result = $engine->apply($state, GameState::PLAYER_HOST, new Command('choose_opponent_row_marker', [
        'row' => $row,
    ]));
    scAssert($result->success, 'Storm row choice should resolve: ' . ($result->error ?? ''));
    scAssert(empty($state->battle['pending_opponent_row_marker']), 'Storm row pending should clear after choice.');
}

$engine = new Engine();

// Pending can be cancelled before any cost, tap, used-row flag, marker, wound, or movement change.
$storm = scStorm();
$ally = scCard(['instanceId' => 2, 'row' => 3, 'col' => 2, 'move' => 1, 'moveMax' => 1]);
$state = scState($storm, $ally);
$result = $engine->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'target_id' => 1,
    'action_key' => 'frost_mist_rain',
]));
scAssert($result->success, 'Storm row action should create cancellable pending.');
scAssert(!empty($state->battle['pending_opponent_row_marker']), 'Pending should exist.');
scAssert(empty($state->cell_markers), 'No markers should be placed before row confirmation.');
$result = $engine->apply($state, GameState::PLAYER_HOST, new Command('cancel_pending'));
scAssert($result->success, 'Storm row pending should accept cancel_pending.');
scAssert(empty($state->battle['pending_opponent_row_marker']), 'Pending should be absent after cancel.');
scAssert(empty($state->cell_markers), 'Cancel should leave no markers.');
scAssert($storm->closed === false, 'Cancel should not close source card.');
scAssert(empty($storm->flags['opponent_row_markers_used'] ?? []), 'Cancel should not mark a row as used.');
scAssert($ally->hp === 10 && $ally->move === 1 && $ally->moveMax === 1, 'Cancel should not change potential targets.');

// Choosing host logical row 1 marks physical opponent row 4, coexisting with other markers.
$storm = scStorm();
$state = scState($storm);
ZoneManager::addMarker($state, '4_1', ['type' => 'bonfire', 'source' => GameState::PLAYER_HOST]);
scStartStormRow($state, $engine, 1);
for ($col = 1; $col <= 5; $col++) {
    $markers = ZoneManager::markersAt($state, "4_{$col}");
    scAssert(count(array_filter($markers, fn($m) => ($m['type'] ?? '') === 'reduce_attack')) === 1, 'Chosen row cells should get reduce_attack marker.');
}
scAssert(count(ZoneManager::markersAt($state, '4_1')) === 2, 'Storm marker should coexist with existing cell marker.');
scAssert(!empty($storm->flags['opponent_row_markers_used']['frost_mist_rain'][1]), 'Chosen row should be recorded as used.');
scAssert($storm->closed === true, 'Confirmed row spell should close source card.');

// Used rows persist after marker expiration and next use offers only remaining rows.
$state->battle['active'] = GameState::PLAYER_PLAYER;
$result = (new TurnProcessor($state, $engine))->endTurn(GameState::PLAYER_PLAYER, new Command('end_turn'));
scAssert($result->success, 'Opponent end turn should resolve.');
scAssert(empty(ZoneManager::markersAt($state, '4_1')) || count(array_filter(ZoneManager::markersAt($state, '4_1'), fn($m) => ($m['type'] ?? '') === 'reduce_attack')) === 0, 'Storm markers should expire at opponent end turn.');
$storm->closed = false;
$state->battle['strike'] = null;
$state->battle['active'] = GameState::PLAYER_HOST;
$result = $engine->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'target_id' => 1,
    'action_key' => 'frost_mist_rain',
]));
scAssert($result->success, 'Storm row action should restart after expiration.');
scAssert(($state->battle['pending_opponent_row_marker']['rows'] ?? []) === [2, 3], 'Used logical row should no longer be offered.');

// Attack value is reduced after bonuses: base 1 + four coins = 5, then storm marker => 1.
$storm = scStorm();
$attacker = scCard([
    'instanceId' => 2,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 3,
    'coins' => 4,
    'prop' => scShotProp(1),
]);
$target = scCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 1, 'col' => 3, 'hp' => 10, 'hpMax' => 10]);
$state = scState($storm, $attacker, $target);
scStartStormRow($state, $engine, 1);
$state->battle['active'] = GameState::PLAYER_PLAYER;
$state->battle['strike'] = null;
$result = $engine->apply($state, GameState::PLAYER_PLAYER, new Command('action', [
    'card_id' => 2,
    'target_id' => 3,
    'action_key' => 'shot',
]));
scAssert($result->success, 'Enemy shot should resolve under storm marker: ' . ($result->error ?? ''));
scAssert($target->hp === 9, 'Storm marker should reduce 5 attack value to 1 actual wound.');
scAssert(($state->battle['strike']['coin_bonus'] ?? 0) === 4, 'Coin bonus should be calculated before storm reduction.');
$event = $state->battle['strike']['attack_value_reduction'][0] ?? null;
scAssert($event !== null && $event['from'] === 5 && $event['to'] === 1, 'InfoPanel event should record reduction from 5 to 1.');

// Dynamic cell behavior: leaving the row removes the effect, entering the row gains it.
$storm = scStorm();
$attacker = scCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 3, 'prop' => scShotProp(5)]);
$target = scCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 1, 'col' => 3, 'hp' => 10, 'hpMax' => 10]);
$state = scState($storm, $attacker, $target);
scStartStormRow($state, $engine, 1);
$state->battle['active'] = GameState::PLAYER_PLAYER;
$state->battle['strike'] = null;
$result = $engine->apply($state, GameState::PLAYER_PLAYER, new Command('action', [
    'card_id' => 2,
    'target_id' => 3,
    'action_key' => 'shot',
]));
scAssert($result->success, 'Shot outside marked row should resolve: ' . ($result->error ?? ''));
scAssert($target->hp === 5, 'Attacker outside marked row should not be reduced.');

$attacker->closed = false;
$attacker->row = 4;
$target->hp = 10;
$state->battle['strike'] = null;
$result = $engine->apply($state, GameState::PLAYER_PLAYER, new Command('action', [
    'card_id' => 2,
    'target_id' => 3,
    'action_key' => 'shot',
]));
scAssert($result->success, 'Shot after entering marked row should resolve.');
scAssert($target->hp === 9, 'Attacker entering marked row should be reduced dynamically.');

// Elite and owner filters.
$storm = scStorm();
$elite = scCard([
    'instanceId' => 2,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 3,
    'elite' => true,
    'prop' => scShotProp(5),
]);
$friendly = scCard([
    'instanceId' => 3,
    'owner' => GameState::PLAYER_HOST,
    'row' => 4,
    'col' => 4,
    'prop' => scShotProp(5),
]);
$target = scCard(['instanceId' => 4, 'owner' => GameState::PLAYER_HOST, 'row' => 1, 'col' => 3, 'hp' => 20, 'hpMax' => 20]);
$enemyTarget = scCard(['instanceId' => 5, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 4, 'hp' => 20, 'hpMax' => 20]);
$state = scState($storm, $elite, $friendly, $target, $enemyTarget);
scStartStormRow($state, $engine, 1);
$state->battle['active'] = GameState::PLAYER_PLAYER;
$state->battle['strike'] = null;
$result = $engine->apply($state, GameState::PLAYER_PLAYER, new Command('action', [
    'card_id' => 2,
    'target_id' => 4,
    'action_key' => 'shot',
]));
scAssert($result->success, 'Elite shot should resolve.');
scAssert($target->hp === 15, 'Elite attacker should ignore elite=false marker filter.');

$state->battle['active'] = GameState::PLAYER_HOST;
$state->battle['strike'] = null;
$result = $engine->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 3,
    'target_id' => 5,
    'action_key' => 'shot',
]));
scAssert($result->success, 'Friendly owner shot should resolve.');
scAssert($enemyTarget->hp === 15, 'Marker should not reduce owner friendly creatures.');

// Non-attack damage effects do not use storm reduction.
$storm = scStorm();
$caster = scCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'prop' => scImpactProp()]);
$target = scCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 5, 'col' => 3, 'hp' => 10, 'hpMax' => 10]);
$state = scState($storm, $caster, $target);
scStartStormRow($state, $engine, 1);
$state->battle['active'] = GameState::PLAYER_PLAYER;
$state->battle['strike'] = null;
$result = $engine->apply($state, GameState::PLAYER_PLAYER, new Command('action', [
    'card_id' => 2,
    'target_id' => 2,
    'action_key' => 'impact',
]));
scAssert($result->success, 'Impact should resolve.');
scAssert($target->hp === 5, 'Impact damage should not be reduced by storm marker.');
scAssert(empty($state->battle['strike']['attack_value_reduction']), 'Impact should not create attack reduction InfoPanel event.');

$storm = scStorm();
$poisonTarget = scCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'hp' => 10, 'hpMax' => 10]);
$state = scState($storm, $poisonTarget);
scStartStormRow($state, $engine, 1);
$engine->applyDamage($state, $poisonTarget, 5, 'poison', $storm);
scAssert($poisonTarget->hp === 5, 'Poison damage should not be reduced by storm marker.');

echo "Storm caller tests passed\n";
