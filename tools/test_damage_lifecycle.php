<?php
// tools/test_damage_lifecycle.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\TurnPhaseProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function dlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dlState(CardInstance ...$cards): GameState
{
    $state = new GameState(9001, 101, 202);
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

function dlCard(array $overrides = []): CardInstance
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
        type: $overrides['type'] ?? 'creature',
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        armor: $overrides['armor'] ?? 0,
        armorMax: $overrides['armorMax'] ?? 0,
        prop: $overrides['prop'] ?? [],
        markers: $overrides['markers'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function dlOnDeathDamageProp(): array
{
    return [
        'on_death' => [[
            'type' => 'damage',
            'target' => 'near',
            'value' => 1,
        ]],
    ];
}

// self_destroy is now a full death and triggers on_death.
$self = dlCard([
    'instanceId' => 1,
    'row' => 3,
    'col' => 3,
    'hp' => 1,
    'prop' => dlOnDeathDamageProp(),
]);
$hostBackup = dlCard(['instanceId' => 2, 'row' => 5, 'col' => 5]);
$enemy = dlCard([
    'instanceId' => 3,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 4,
    'hp' => 5,
]);
$enemyBackup = dlCard([
    'instanceId' => 4,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 1,
    'col' => 1,
]);
$state = dlState($self, $hostBackup, $enemy, $enemyBackup);
$state->battle['strike'] = [
    'kind' => 'impact',
    'attacker_id' => 1,
    'target_id' => 1,
    'defender_id' => null,
    'state' => 'results',
    'confirmed' => [],
];
(new Engine())->forceDeath($state, $self, 'self_destroy', $self);
dlAssert($self->dying && $self->hp === 0, 'self_destroy source should be dying.');
dlAssert($enemy->hp === 4, 'self_destroy should trigger on_death damage.');
dlAssert(!empty($state->battle['strike']['death_triggers']), 'self_destroy should record death trigger.');

// execute goes through the same lifecycle, including on_death and deadeat.
$attacker = dlCard([
    'instanceId' => 10,
    'row' => 3,
    'col' => 3,
    'hp' => 3,
    'hpMax' => 6,
    'prop' => ['deadeat' => true],
]);
$target = dlCard([
    'instanceId' => 11,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 4,
    'hp' => 1,
    'prop' => dlOnDeathDamageProp(),
]);
$playerBackup = dlCard([
    'instanceId' => 12,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 1,
    'col' => 1,
]);
$state = dlState($attacker, $target, $playerBackup);
$state->battle['strike'] = [
    'kind' => 'execute',
    'attacker_id' => 10,
    'target_id' => 11,
    'defender_id' => null,
    'state' => 'results',
    'confirmed' => [],
];
(new Engine())->forceDeath($state, $target, 'execute', $attacker);
dlAssert($target->dying && $target->hp === 0, 'execute target should be dying.');
dlAssert($attacker->hp === 2, 'execute target on_death should damage adjacent attacker before deadeat flush.');
dlAssert(!empty($state->battle['strike']['deadeat_queue']), 'execute should queue deadeat.');
(new Engine())->flushDeadeatQueue($state);
dlAssert($attacker->hp === 6, 'execute deadeat should still heal via queued lifecycle.');

// damage_on_dice is routed through DamageResolver, so damage flags are populated.
$mary = dlCard(['instanceId' => 20, 'row' => 5, 'col' => 5]);
$hit = dlCard([
    'instanceId' => 21,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 3,
    'hp' => 2,
]);
$state = dlState($mary, $hit);
$state->battle['strike'] = [
    'attacker_id' => 21,
    'target_id' => 20,
    'defender_id' => 20,
    'attack_dice' => 3,
    'defend_dice' => 1,
];
$error = (new Engine())->applyCombatEffect($state, [
    'type' => 'damage_on_dice',
    'value' => 3,
    'damage' => 2,
], $mary, GameState::PLAYER_HOST);
dlAssert($error === null, $error ?? 'damage_on_dice should apply.');
dlAssert($hit->dying && $hit->hp === 0, 'damage_on_dice lethal hit should use death lifecycle.');
dlAssert((int) ($hit->flags['damage_taken_this_turn'] ?? 0) === 2, 'damage_on_dice should populate damage flags.');

// Start-phase poison uses Engine::applyDamage and still moves a killed card to graveyard without a strike.
$poisoned = dlCard([
    'instanceId' => 30,
    'hp' => 1,
    'markers' => ['poison' => ['value' => 2]],
]);
$hostBackup = dlCard(['instanceId' => 31, 'row' => 5, 'col' => 5]);
$enemy = dlCard(['instanceId' => 32, 'owner' => GameState::PLAYER_PLAYER, 'row' => 1, 'col' => 1]);
$state = dlState($poisoned, $hostBackup, $enemy);
$state->battle['turn_phase'] = [
    'phase' => 'start',
    'active_key' => GameState::PLAYER_HOST,
    'passive_key' => GameState::PLAYER_PLAYER,
    'side' => 'passive',
    'passive_queue' => [['id' => 'poison', 'type' => 'poison', 'label' => 'Яд']],
    'active_queue' => [],
    'sub' => null,
    'pending_ack' => null,
];
$result = (new TurnPhaseProcessor($state, new Engine()))->runTask(GameState::PLAYER_PLAYER, new Command('turn_task', [
    'task_id' => 'poison',
]));
dlAssert($result->success, $result->error ?? 'poison task should run.');
dlAssert($poisoned->zone === CardInstance::ZONE_GRAVEYARD, 'poison death without strike should move to graveyard.');

echo "Damage lifecycle regression tests passed.\n";
