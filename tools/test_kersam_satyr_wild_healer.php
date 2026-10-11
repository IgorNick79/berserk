<?php
// tools/test_kersam_satyr_wild_healer.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\ActionResolver;
use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Movement\ForcedMovementResolver;
use Berserk\Core\Movement\MovementResolver;
use Berserk\Core\StrikeResolver;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function kswAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function kswState(CardInstance ...$cards): GameState
{
    $state = new GameState(819191146, 101, 202);
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

function kswCard(array $o): CardInstance
{
    return new CardInstance(
        instanceId: $o['instanceId'],
        ukid: $o['ukid'] ?? ('card_' . $o['instanceId']),
        owner: $o['owner'] ?? GameState::PLAYER_HOST,
        zone: $o['zone'] ?? CardInstance::ZONE_FIELD,
        row: $o['row'] ?? 3,
        col: $o['col'] ?? 3,
        hp: $o['hp'] ?? 5,
        hpMax: $o['hpMax'] ?? ($o['hp'] ?? 5),
        type: $o['type'] ?? 'creature',
        closed: $o['closed'] ?? false,
        move: $o['move'] ?? 3,
        moveMax: $o['moveMax'] ?? ($o['move'] ?? 3),
        prop: $o['prop'] ?? [],
        flags: $o['flags'] ?? [],
    );
}

function kswWildHealer(int $id, int $row, int $col, string $owner = GameState::PLAYER_HOST): CardInstance
{
    return kswCard([
        'instanceId' => $id,
        'ukid' => 's1_91',
        'owner' => $owner,
        'row' => $row,
        'col' => $col,
        'prop' => [
            'on_move_heal_adjacent' => ['value' => 1],
            'actions' => [['type' => 'jump', 'range' => 4]],
        ],
    ]);
}

function kswAssertWildHealerPending(GameState $state, string $owner, int $healerId, int $candidateId): void
{
    $pending = $state->battle['pending_wild_healer_move'] ?? null;
    kswAssert(is_array($pending), 'Wild Healer should open a heal choice after movement.');
    kswAssert(($pending['owner'] ?? null) === $owner, 'Wild Healer choice should belong to the moved card owner.');
    kswAssert((int) ($pending['card_id'] ?? 0) === $healerId, 'Wild Healer pending source should be the moved healer.');
    kswAssert(in_array($candidateId, $pending['candidates'] ?? [], true), 'Wild Healer pending should include adjacent target.');
}

$healer = kswWildHealer(1, 3, 2);
$ally = kswCard(['instanceId' => 2, 'row' => 3, 'col' => 4, 'hp' => 4, 'hpMax' => 5]);
$state = kswState($healer, $ally);
$result = (new MovementResolver($state, new Engine()))->move(GameState::PLAYER_HOST, new Command('move', [
    'card_id' => $healer->instanceId,
    'row' => 3,
    'col' => 3,
]));
kswAssert($result->success, $result->error ?? 'Normal movement should succeed.');
kswAssertWildHealerPending($state, GameState::PLAYER_HOST, $healer->instanceId, $ally->instanceId);

$healer = kswWildHealer(3, 3, 2);
$ally = kswCard(['instanceId' => 4, 'row' => 3, 'col' => 4, 'hp' => 4, 'hpMax' => 5]);
$state = kswState($healer, $ally);
$result = (new ForcedMovementResolver($state, new Engine()))->move($healer, 0, 1);
kswAssert($result->success, $result->error ?? 'Forced movement should succeed.');
kswAssertWildHealerPending($state, GameState::PLAYER_HOST, $healer->instanceId, $ally->instanceId);

$healer = kswWildHealer(5, 3, 2);
$ally = kswCard(['instanceId' => 6, 'row' => 3, 'col' => 4, 'hp' => 4, 'hpMax' => 5]);
$state = kswState($healer, $ally);
$state->battle['strike'] = [
    'state' => 'waiting_push_choice',
    'pending_push' => [
        'defender_id' => $healer->instanceId,
        'new_row' => 3,
        'new_col' => 3,
        'damage' => 2,
    ],
    'confirmed' => [],
];
$result = (new StrikeResolver($state, new Engine()))->choosePushChoice(
    GameState::PLAYER_HOST,
    new Command('choose_push_choice', ['choice' => 'move'])
);
kswAssert($result->success, $result->error ?? 'Push movement choice should succeed.');
kswAssertWildHealerPending($state, GameState::PLAYER_HOST, $healer->instanceId, $ally->instanceId);

$healer = kswWildHealer(13, 3, 2);
$ally = kswCard(['instanceId' => 14, 'row' => 3, 'col' => 4, 'hp' => 4, 'hpMax' => 5]);
$diver = kswCard([
    'instanceId' => 15,
    'row' => null,
    'col' => null,
    'zone' => CardInstance::ZONE_FLYING,
]);
$state = kswState($healer, $ally, $diver);
$state->battle['pending_dive'] = [
    'owner' => GameState::PLAYER_HOST,
    'attacker_id' => $diver->instanceId,
    'target_id' => $healer->instanceId,
    'action' => [
        'name' => 'Test dive',
        'strike' => ['weak' => 0, 'medium' => 0, 'strong' => 0],
    ],
    'cells' => [['row' => 3, 'col' => 3]],
];
$result = (new ActionResolver($state, new Engine()))->chooseDiveCell(
    GameState::PLAYER_HOST,
    new Command('choose_dive_cell', ['row' => 3, 'col' => 3])
);
kswAssert($result->success, $result->error ?? 'Dive movement should succeed.');
kswAssertWildHealerPending($state, GameState::PLAYER_HOST, $healer->instanceId, $ally->instanceId);

$healer = kswWildHealer(7, 3, 2);
$ally = kswCard(['instanceId' => 8, 'row' => 3, 'col' => 4, 'hp' => 4, 'hpMax' => 5]);
$teleporter = kswCard(['instanceId' => 9, 'row' => 1, 'col' => 1]);
$state = kswState($healer, $ally, $teleporter);
$state->battle['pending_teleport_target'] = [
    'owner' => GameState::PLAYER_HOST,
    'attacker_id' => $teleporter->instanceId,
    'target_id' => $healer->instanceId,
    'action' => ['name' => 'Test teleport'],
    'cells' => [['row' => 3, 'col' => 3]],
];
$result = (new ActionResolver($state, new Engine()))->chooseTeleportTargetCell(
    GameState::PLAYER_HOST,
    new Command('choose_teleport_cell', ['row' => 3, 'col' => 3])
);
kswAssert($result->success, $result->error ?? 'Target teleport should succeed.');
kswAssertWildHealerPending($state, GameState::PLAYER_HOST, $healer->instanceId, $ally->instanceId);

$healer = kswWildHealer(10, 3, 3, GameState::PLAYER_HOST);
$satyr = kswCard([
    'instanceId' => 11,
    'ukid' => 's1_81',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 4,
    'hp' => 5,
    'hpMax' => 5,
    'prop' => ['on_heal_instead_wound' => ['uses_per_turn' => 3]],
]);
$woundTarget = kswCard(['instanceId' => 12, 'row' => 4, 'col' => 4]);
$state = kswState($healer, $satyr, $woundTarget);
$state->battle['pending_wild_healer_move'] = [
    'owner' => GameState::PLAYER_HOST,
    'card_id' => $healer->instanceId,
    'value' => 1,
    'candidates' => [$satyr->instanceId],
];
$result = (new ActionResolver($state, new Engine()))->chooseWildHealerHeal(
    GameState::PLAYER_HOST,
    new Command('choose_wild_healer_heal', ['target_id' => $satyr->instanceId])
);
kswAssert($result->success, $result->error ?? 'Wild Healer should be able to target Satyr Leader.');
$pending = $state->battle['pending_heal_instead_wound'] ?? null;
kswAssert(is_array($pending), 'Satyr Leader should open heal-instead-wound choice.');
kswAssert(($pending['owner'] ?? null) === GameState::PLAYER_PLAYER, 'Satyr Leader choice should belong to its owner.');

$result = (new ActionResolver($state, new Engine()))->chooseHealInsteadWound(
    GameState::PLAYER_PLAYER,
    new Command('cancel_pending', [])
);
kswAssert($result->success, $result->error ?? 'Satyr Leader cancel should succeed.');
kswAssert(empty($satyr->flags['on_heal_wound_used_this_turn']), 'Cancelling Satyr Leader choice should not spend a use.');

echo "Kersam/Satyr/Wild Healer tests passed.\n";
