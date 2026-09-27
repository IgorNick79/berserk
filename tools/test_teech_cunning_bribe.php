<?php
// tools/test_teech_cunning_bribe.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function teechProp(): array
{
    return [
        'ova' => 1,
        'direct' => true,
        'zor' => true,
        'deal' => [
            'resource_modifier' => ['elite_gold' => 2],
            'squad_constraint' => ['max_elemental_cards' => 3],
        ],
        'strike_effects' => [[
            'levels' => ['strong'],
            'wound_target_by_behind_weak_strike' => true,
        ]],
    ];
}

function battleState(): GameState
{
    $state = new GameState(1, 101, 202);
    $state->status = 'battle';
    $state->battle = ['active' => GameState::PLAYER_HOST];
    return $state;
}

function battleCard(array $overrides): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'],
        ukid: $overrides['ukid'] ?? 'card_' . $overrides['instanceId'],
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'],
        col: $overrides['col'],
        hp: $overrides['hp'] ?? 20,
        hpMax: $overrides['hpMax'] ?? 20,
        closed: $overrides['closed'] ?? false,
        strikeWeak: $overrides['strikeWeak'] ?? 1,
        strikeMedium: $overrides['strikeMedium'] ?? 2,
        strikeStrong: $overrides['strikeStrong'] ?? 4,
        prop: $overrides['prop'] ?? [],
        modifiers: $overrides['modifiers'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function resolveTeechStrike(
    string $level,
    int $attackerRow,
    int $attackerCol,
    int $targetRow,
    int $targetCol,
    ?array $behind = null,
    string $behindOwner = GameState::PLAYER_PLAYER,
): array {
    $state = battleState();
    $attacker = battleCard([
        'instanceId' => 1,
        'ukid' => 's1_199',
        'owner' => GameState::PLAYER_HOST,
        'row' => $attackerRow,
        'col' => $attackerCol,
        'strikeWeak' => 1,
        'strikeMedium' => 2,
        'strikeStrong' => 4,
        'prop' => teechProp(),
    ]);
    $target = battleCard([
        'instanceId' => 2,
        'ukid' => 'target',
        'owner' => GameState::PLAYER_PLAYER,
        'row' => $targetRow,
        'col' => $targetCol,
        'hp' => 20,
        'hpMax' => 20,
        'strikeWeak' => 1,
        'strikeMedium' => 1,
        'strikeStrong' => 1,
    ]);

    $state->addCard($attacker);
    $state->addCard($target);

    $behindCard = null;
    if ($behind !== null) {
        $behindCard = battleCard(array_merge([
            'instanceId' => 3,
            'ukid' => 'behind',
            'owner' => $behindOwner,
            'hp' => 20,
            'hpMax' => 20,
            'closed' => false,
            'strikeWeak' => 2,
            'strikeMedium' => 3,
            'strikeStrong' => 5,
        ], $behind));
        $state->addCard($behindCard);
    }

    $state->battle['strike'] = [
        'attacker_id' => $attacker->instanceId,
        'target_id' => $target->instanceId,
        'defender_id' => null,
        'state' => 'results',
        'confirmed' => [],
    ];

    (new StrikeResolver($state, new Engine()))->apply(['attack' => $level, 'defend' => ''], false);

    return [$state, $attacker, $target, $behindCard];
}

[$state, , $target, $behind] = resolveTeechStrike('strong', 3, 3, 4, 3, [
    'row' => 5,
    'col' => 3,
    'strikeWeak' => 2,
]);
assertTrue($target->hp === 14, 'Forward 3.3 -> 4.3 -> 5.3 should add behind weak strike damage.');
assertTrue(($state->battle['strike']['damage_total'] ?? null) === 4, 'Normal strong strike damage should remain intact.');
assertTrue(($state->battle['strike']['behind_weak_strike_damage']['damage'] ?? null) === 2, 'Behind weak strike damage should be recorded.');
assertTrue($behind->hp === 20 && !$behind->closed && empty($behind->flags), 'Behind card should not attack, close, spend actions, or take damage.');

[, , $target] = resolveTeechStrike('strong', 3, 3, 2, 3, [
    'row' => 1,
    'col' => 3,
    'strikeWeak' => 3,
]);
assertTrue($target->hp === 13, 'Reverse 3.3 -> 2.3 -> 1.3 should use the reverse behind cell.');

[, , $target] = resolveTeechStrike('strong', 3, 3, 4, 4, [
    'row' => 5,
    'col' => 5,
    'strikeWeak' => 2,
]);
assertTrue($target->hp === 14, 'Diagonal attack direction should use target plus normalized attack vector.');

[, , $target] = resolveTeechStrike('strong', 3, 3, 4, 3, [
    'row' => 5,
    'col' => 3,
    'strikeWeak' => 2,
], GameState::PLAYER_HOST);
assertTrue($target->hp === 14, 'Friendly card behind target should be a valid weak strike source.');

[, , $target] = resolveTeechStrike('strong', 3, 3, 4, 3, [
    'row' => 5,
    'col' => 3,
    'strikeWeak' => 2,
], GameState::PLAYER_PLAYER);
assertTrue($target->hp === 14, 'Enemy card behind target should be a valid weak strike source.');

[$state, , $target] = resolveTeechStrike('strong', 3, 3, 4, 3, null);
assertTrue($target->hp === 16, 'Empty behind cell should not add extra damage.');
assertTrue(empty($state->battle['strike']['behind_weak_strike_damage']), 'Empty behind cell should not record extra damage.');

[, , $target] = resolveTeechStrike('strong', 2, 3, 1, 3, null);
assertTrue($target->hp === 16, 'Behind cell outside board should not add extra damage or error.');

[, , $target] = resolveTeechStrike('weak', 3, 3, 4, 3, [
    'row' => 5,
    'col' => 3,
    'strikeWeak' => 2,
]);
assertTrue($target->hp === 19, 'Weak strike should deal only normal weak damage and not trigger Teech effect.');

[, , $target] = resolveTeechStrike('medium', 3, 3, 4, 3, [
    'row' => 5,
    'col' => 3,
    'strikeWeak' => 2,
]);
assertTrue($target->hp === 18, 'Medium strike should deal only normal medium damage and not trigger Teech effect.');

[, , $target] = resolveTeechStrike('strong', 3, 3, 4, 3, [
    'row' => 5,
    'col' => 3,
    'strikeWeak' => 2,
    'modifiers' => [[
        'stat' => 'ability_strike',
        'value' => 3,
        'expire' => 'permanent',
        'source' => 'test',
    ]],
]);
assertTrue($target->hp === 11, 'Modified effective weak strike of behind card should be used.');

echo "Teech cunning bribe tests passed.\n";
