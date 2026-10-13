<?php
// tools/test_hydra.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\ActionResolver;
use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function hydraAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function hydraCard(array $o): CardInstance
{
    return new CardInstance(
        instanceId: $o['instanceId'],
        ukid: $o['ukid'] ?? ('card_' . $o['instanceId']),
        owner: $o['owner'] ?? GameState::PLAYER_HOST,
        zone: $o['zone'] ?? CardInstance::ZONE_FIELD,
        row: $o['row'] ?? 3,
        col: $o['col'] ?? 3,
        hp: $o['hp'] ?? 10,
        hpMax: $o['hpMax'] ?? ($o['hp'] ?? 10),
        type: $o['type'] ?? 'creature',
        closed: $o['closed'] ?? false,
        move: $o['move'] ?? 1,
        moveMax: $o['moveMax'] ?? ($o['move'] ?? 1),
        strikeWeak: $o['strikeWeak'] ?? 3,
        strikeMedium: $o['strikeMedium'] ?? 3,
        strikeStrong: $o['strikeStrong'] ?? 3,
        prop: $o['prop'] ?? [],
        flags: $o['flags'] ?? [],
    );
}

function hydraState(CardInstance ...$cards): GameState
{
    $state = new GameState(136, 101, 202);
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

function hydraProp(): array
{
    return [
        'zoz' => true,
        'ignore' => ['damage_reduction'],
        'unanswer' => true,
        'regeneration' => 1,
        'attack_series' => true,
    ];
}

function hydraReducedTarget(int $id, int $row, int $col): CardInstance
{
    return hydraCard([
        'instanceId' => $id,
        'owner' => GameState::PLAYER_PLAYER,
        'row' => $row,
        'col' => $col,
        'hp' => 10,
        'hpMax' => 10,
        'prop' => [
            'damage_reduction' => [[
                'types' => ['strike'],
                'value' => 2,
            ]],
        ],
    ]);
}

function hydraConfirmBoth(GameState $state): void
{
    $resolver = new StrikeResolver($state, new Engine());
    $result = $resolver->confirmStrike(GameState::PLAYER_HOST, new Command('confirm_strike', []));
    hydraAssert($result->success, $result->error ?? 'Host confirm should succeed.');
    $result = $resolver->confirmStrike(GameState::PLAYER_PLAYER, new Command('confirm_strike', []));
    hydraAssert($result->success, $result->error ?? 'Player confirm should succeed.');
}

$hydra = hydraCard([
    'instanceId' => 1,
    'ukid' => 's1_136',
    'row' => 3,
    'col' => 3,
    'prop' => hydraProp(),
]);
$targetA = hydraReducedTarget(2, 4, 3);
$targetB = hydraReducedTarget(3, 3, 4);
$state = hydraState($hydra, $targetA, $targetB);

$result = (new StrikeResolver($state, new Engine()))->declare(
    GameState::PLAYER_HOST,
    new Command('strike', ['card_id' => $hydra->instanceId, 'target_id' => $targetA->instanceId])
);
hydraAssert($result->success, $result->error ?? 'Hydra first strike should start.');
hydraAssert($targetA->hp === 7, 'Hydra should ignore strike damage_reduction.');
hydraAssert(($state->battle['strike']['defend_dice'] ?? null) === 0, 'Hydra strike should be unanswered.');

hydraConfirmBoth($state);
$pending = $state->battle['pending_attack_series_choice'] ?? null;
hydraAssert(is_array($pending), 'Hydra should open attack-series choice after strike ack.');
hydraAssert(($pending['owner'] ?? null) === GameState::PLAYER_HOST, 'Hydra series choice should belong to Hydra owner.');
hydraAssert(in_array($targetB->instanceId, $pending['candidates'] ?? [], true), 'Hydra should offer an unhit adjacent enemy.');
hydraAssert(!in_array($targetA->instanceId, $pending['candidates'] ?? [], true), 'Hydra should not offer an already-hit target.');
hydraAssert(!$hydra->closed, 'Hydra should stay open while the series choice is pending.');

$result = (new ActionResolver($state, new Engine()))->chooseAttackSeriesNext(
    GameState::PLAYER_HOST,
    new Command('attack_series_next', ['target_id' => $targetB->instanceId])
);
hydraAssert($result->success, $result->error ?? 'Hydra should continue the series.');
hydraAssert($targetB->hp === 7, 'Hydra second strike should also ignore damage_reduction.');

hydraConfirmBoth($state);
hydraAssert(empty($state->battle['pending_attack_series_choice']), 'Hydra should not open a choice when no valid targets remain.');
hydraAssert($hydra->closed, 'Hydra should close after the series ends naturally.');
hydraAssert(empty($hydra->flags['attack_series_active']), 'Hydra active flag should clear after series end.');
hydraAssert((int) ($hydra->flags['attacks_used_this_turn'] ?? 0) === 1, 'Hydra should spend one attack after the series.');

$freshHydra = hydraCard([
    'instanceId' => 10,
    'ukid' => 's1_136',
    'row' => 3,
    'col' => 3,
    'prop' => hydraProp(),
]);
$farTarget = hydraReducedTarget(11, 6, 5);
$badState = hydraState($freshHydra, $farTarget);
$result = (new StrikeResolver($badState, new Engine()))->declare(
    GameState::PLAYER_HOST,
    new Command('strike', ['card_id' => $freshHydra->instanceId, 'target_id' => $farTarget->instanceId])
);
hydraAssert(!$result->success, 'Hydra invalid strike should fail.');
hydraAssert(empty($freshHydra->flags['attack_series_active']), 'Hydra should not enter series mode after an invalid strike.');
hydraAssert(empty($freshHydra->flags['attack_series_hit_ids']), 'Hydra should not record series hits after an invalid strike.');

echo "Hydra regression tests passed.\n";
