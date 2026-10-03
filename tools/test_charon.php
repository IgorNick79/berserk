<?php
// tools/test_charon.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function chAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function chCharonProp(): array
{
    return [
        'actions' => [
            [
                'key' => 'last_journey',
                'name' => 'Последний путь',
                'type' => 'destroy_self_and_target',
                'coins' => 3,
                'target' => 'any_creature',
            ],
            [
                'type' => 'discharge',
                'value' => 1,
            ],
        ],
        'save_coins' => true,
    ];
}

function chCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'],
        ukid: $overrides['ukid'] ?? ('card_' . $overrides['instanceId']),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        slot: $overrides['slot'] ?? 0,
        hp: $overrides['hp'] ?? 5,
        hpMax: $overrides['hpMax'] ?? ($overrides['hp'] ?? 5),
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        coins: $overrides['coins'] ?? 0,
        prop: $overrides['prop'] ?? [],
    );
}

function chCharon(array $overrides = []): CardInstance
{
    return chCard(array_merge([
        'instanceId' => 1,
        'ukid' => 's1_150',
        'row' => 3,
        'col' => 1,
        'hp' => 5,
        'hpMax' => 5,
        'coins' => 3,
        'prop' => chCharonProp(),
    ], $overrides));
}

function chState(CardInstance ...$cards): GameState
{
    $state = new GameState(150, 101, 202);
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

function chStart(GameState $state): \Berserk\Core\Result
{
    return (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
        'card_id' => 1,
        'action_key' => 'last_journey',
    ]));
}

foreach ([0, 1, 2] as $coins) {
    $charon = chCharon(['coins' => $coins]);
    $target = chCard([
        'instanceId' => 2,
        'owner' => GameState::PLAYER_PLAYER,
        'row' => 6,
        'col' => 5,
    ]);
    $state = chState($charon, $target);
    $result = chStart($state);
    chAssert(!$result->success, "Last Journey should be unavailable with {$coins} coins.");
    chAssert(empty($state->battle['pending_destroy_self_and_target']), 'Failed start should not create pending.');
}

$charon = chCharon(['coins' => 3]);
$target = chCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5]);
$state = chState($charon, $target);
$result = chStart($state);
chAssert($result->success, $result->error ?? 'Last Journey should start with 3 coins.');
chAssert(!empty($state->battle['pending_destroy_self_and_target']), 'Last Journey should create pending.');
chAssert(!empty($charon->prop['save_coins']), 'Charon prop should preserve coins between turns.');

$closed = chCharon(['coins' => 3, 'closed' => true]);
$state = chState($closed, $target);
$result = chStart($state);
chAssert(!$result->success, 'Closed Charon should not start Last Journey.');

$charon = chCharon(['coins' => 3]);
$farEnemy = chCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5]);
$own = chCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 2]);
$enemyFly = chCard([
    'instanceId' => 4,
    'owner' => GameState::PLAYER_PLAYER,
    'zone' => CardInstance::ZONE_FLYING,
    'type' => 'fly',
    'slot' => 1,
]);
$ownFly = chCard([
    'instanceId' => 5,
    'owner' => GameState::PLAYER_HOST,
    'zone' => CardInstance::ZONE_FLYING,
    'type' => 'fly',
    'slot' => 1,
]);
$state = chState($charon, $farEnemy, $own, $enemyFly, $ownFly);
$result = chStart($state);
chAssert($result->success, $result->error ?? 'Last Journey should start for target tests.');
$ids = $state->battle['pending_destroy_self_and_target']['target_ids'] ?? [];
foreach ([2, 3, 4, 5] as $id) {
    chAssert(in_array($id, $ids, true), "Target {$id} should be a legal Last Journey target.");
}
chAssert(!in_array(1, $ids, true), 'Charon should not target himself.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_destroy_self_and_target', [
    'target_id' => 1,
]));
chAssert(!$result->success, 'Server should reject Charon as his own target.');

$charon = chCharon(['coins' => 3]);
$target = chCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5]);
$state = chState($charon, $target);
$result = chStart($state);
chAssert($result->success, $result->error ?? 'Last Journey should start before cancel.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('cancel_pending'));
chAssert($result->success, $result->error ?? 'Last Journey cancel should be accepted.');
chAssert(empty($state->battle['pending_destroy_self_and_target']), 'Cancel should clear Last Journey pending.');
chAssert(!$charon->closed, 'Cancel should leave Charon open.');
chAssert($charon->coins === 3, 'Cancel should not spend coins.');
chAssert($charon->zone === CardInstance::ZONE_FIELD && $target->zone === CardInstance::ZONE_FIELD, 'Cancel should not destroy cards.');
$result = chStart($state);
chAssert($result->success, $result->error ?? 'Last Journey should be redeclarable after cancel.');

$charon = chCharon(['coins' => 3]);
$victim = chCard([
    'instanceId' => 2,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 4,
    'prop' => [
        'on_death' => [[
            'type' => 'damage',
            'target' => 'near',
            'value' => 1,
        ]],
    ],
]);
$onDeathWitness = chCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 5, 'hp' => 5]);
$anyDeathWitness = chCard([
    'instanceId' => 4,
    'owner' => GameState::PLAYER_HOST,
    'row' => 1,
    'col' => 1,
    'coins' => 0,
    'prop' => [
        'coins' => ['max_value' => 10],
        'on_any_death' => [
            'side' => 'any',
            'cause' => 'any',
            'value' => 1,
            'effect' => 'get_coin',
        ],
    ],
]);
$state = chState($charon, $victim, $onDeathWitness, $anyDeathWitness);
$result = chStart($state);
chAssert($result->success, $result->error ?? 'Last Journey should start before resolution.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_destroy_self_and_target', [
    'target_id' => 2,
]));
chAssert($result->success, $result->error ?? 'Last Journey target choice should resolve.');
chAssert($charon->zone === CardInstance::ZONE_GRAVEYARD, 'Charon should go to graveyard.');
chAssert($victim->zone === CardInstance::ZONE_GRAVEYARD, 'Target should go to graveyard.');
chAssert($charon->coins === 0, 'Charon coins should be cleared in graveyard.');
chAssert(empty($state->battle['pending_destroy_self_and_target']), 'Resolution should clear pending.');
chAssert(($state->battle['strike']['kind'] ?? null) === 'destroy_self_and_target', 'Resolution should expose InfoPanel result.');
chAssert($onDeathWitness->hp === 4, 'Target on_death should trigger through destroy pipeline.');
chAssert($anyDeathWitness->coins === 2, 'on_any_death should see both Charon and target deaths.');

$charon = chCharon(['coins' => 3]);
$dischargeTarget = chCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5, 'hp' => 5]);
$state = chState($charon, $dischargeTarget);
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'discharge',
    'target_id' => 2,
]));
chAssert($result->success, $result->error ?? 'Charon discharge should use standard action resolver.');
chAssert($charon->closed, 'Standard discharge should close Charon.');
chAssert(($state->battle['strike']['kind'] ?? null) === 'discharge', 'Discharge should expose standard strike kind.');

echo "Charon regression tests passed.\n";
