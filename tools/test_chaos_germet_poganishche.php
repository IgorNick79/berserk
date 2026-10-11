<?php
// tools/test_chaos_germet_poganishche.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\ActionResolver;
use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;
use Berserk\Core\TurnProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function cgpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cgpCard(array $o): CardInstance
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
        price: $o['price'] ?? 5,
        elite: $o['elite'] ?? false,
        type: $o['type'] ?? 'creature',
        closed: $o['closed'] ?? false,
        element: $o['element'] ?? 'neutral',
        move: $o['move'] ?? 1,
        moveMax: $o['moveMax'] ?? ($o['move'] ?? 1),
        armor: $o['armor'] ?? 0,
        armorMax: $o['armorMax'] ?? 0,
        strikeWeak: $o['strikeWeak'] ?? 1,
        strikeMedium: $o['strikeMedium'] ?? 2,
        strikeStrong: $o['strikeStrong'] ?? 3,
        prop: $o['prop'] ?? [],
        modifiers: $o['modifiers'] ?? [],
        markers: $o['markers'] ?? [],
        flags: $o['flags'] ?? [],
    );
}

function cgpState(CardInstance ...$cards): GameState
{
    $state = new GameState(196169164, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
    ];
    $state->getPlayer(GameState::PLAYER_HOST)->side = 1;
    $state->getPlayer(GameState::PLAYER_PLAYER)->side = 2;
    foreach ($cards as $card) {
        $state->addCard($card);
    }
    return $state;
}

function cgpHasModifier(CardInstance $card, string $stat, int $value): bool
{
    foreach ($card->modifiers as $m) {
        if (($m['stat'] ?? '') === $stat && (int) ($m['value'] ?? 0) === $value) {
            return true;
        }
    }
    return false;
}

function cgpGermetProp(): array
{
    return [
        'start' => [
            'side' => 0,
            'type' => 'element_bundle',
            'rules' => [
                ['element' => 'plains', 'modifiers' => [['stat' => 'move', 'value' => 1]]],
                ['element' => 'mountains', 'modifiers' => [['stat' => 'armor', 'value' => 1]]],
                ['element' => 'swamps', 'modifiers' => [['stat' => 'regeneration', 'value' => 1]]],
                ['element' => 'forests', 'modifiers' => [['stat' => 'ova', 'value' => 1], ['stat' => 'ovz', 'value' => 1]]],
                ['element' => 'dark', 'modifiers' => [['stat' => 'ability_strike', 'value' => 1, 'levels' => ['medium', 'strong']]]],
            ],
        ],
    ];
}

$germet = cgpCard(['instanceId' => 1, 'ukid' => 's1_196', 'prop' => cgpGermetProp()]);
$state = cgpState(
    $germet,
    cgpCard(['instanceId' => 2, 'element' => 'plains', 'row' => 2, 'col' => 1]),
    cgpCard(['instanceId' => 3, 'element' => 'mountains', 'row' => 2, 'col' => 2]),
    cgpCard(['instanceId' => 4, 'element' => 'swamps', 'row' => 2, 'col' => 3]),
    cgpCard(['instanceId' => 5, 'element' => 'forests', 'row' => 2, 'col' => 4]),
    cgpCard(['instanceId' => 6, 'element' => 'dark', 'row' => 2, 'col' => 5]),
);
(new TurnProcessor($state, new Engine()))->applyStartEffects();
cgpAssert(cgpHasModifier($germet, 'move', 1), 'Germet should gain +1 move.');
cgpAssert(cgpHasModifier($germet, 'armor', 1), 'Germet should gain armor modifier.');
cgpAssert(cgpHasModifier($germet, 'regeneration', 1), 'Germet should gain regeneration.');
cgpAssert(cgpHasModifier($germet, 'ova', 1), 'Germet should gain ova.');
cgpAssert(cgpHasModifier($germet, 'ovz', 1), 'Germet should gain ovz.');
cgpAssert(CardStats::getAbilityBonus($state, $germet, $state->getCard(2), 'strike', 'weak') === 0, 'Germet weak strike should not get dark bonus.');
cgpAssert(CardStats::getAbilityBonus($state, $germet, $state->getCard(2), 'strike', 'medium') === 1, 'Germet medium strike should get dark bonus.');

$attacker = cgpCard(['instanceId' => 10, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'strikeWeak' => 1]);
$chaos = cgpCard([
    'instanceId' => 11,
    'ukid' => 's1_169',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 3,
    'closed' => true,
    'strikeWeak' => 4,
    'strikeMedium' => 5,
    'strikeStrong' => 7,
    'prop' => ['defend_while_closed' => true],
]);
$state = cgpState($attacker, $chaos);
$state->battle['strike'] = [
    'kind' => 'strike',
    'attacker_id' => $attacker->instanceId,
    'target_id' => $chaos->instanceId,
    'defender_id' => null,
    'state' => 'results',
    'attack_dice' => 1,
    'defend_dice' => 6,
    'attack_mod' => 0,
    'defend_mod' => 0,
    'final' => ['attack' => 'weak', 'defend' => 'strong', 'decreased' => false],
];
(new StrikeResolver($state, new Engine()))->recalcTable();
cgpAssert(($state->battle['strike']['result']['defend'] ?? '') === 'medium', 'Closed Chaos Lord should keep defend dice.');

$poganishche = cgpCard([
    'instanceId' => 20,
    'ukid' => 's1_164',
    'zone' => CardInstance::ZONE_GRAVEYARD,
    'row' => null,
    'col' => null,
    'hp' => 0,
    'hpMax' => 12,
    'prop' => [
        'incarnation' => 3,
        'on_incarnation_token' => ['type' => 'wound_any_creature', 'value' => 1],
    ],
    'markers' => ['incarnation' => ['value' => 0, 'threshold' => 3]],
]);
$enemyA = cgpCard(['instanceId' => 21, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 2, 'hp' => 5, 'hpMax' => 5]);
$enemyB = cgpCard(['instanceId' => 22, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'hp' => 5, 'hpMax' => 5]);
$state = cgpState($poganishche, $enemyA, $enemyB);
(new TurnProcessor($state, new Engine()))->processIncarnation(GameState::PLAYER_HOST);
$pending = $state->battle['pending_incarnation_wound'][0] ?? null;
cgpAssert(is_array($pending), 'Poganishche should open grave-grip pending choice.');
cgpAssert((int) ($pending['count'] ?? 0) === 1, 'First incarnation token should require one target.');
$result = (new ActionResolver($state, new Engine()))->chooseIncarnationWound(
    GameState::PLAYER_HOST,
    new Command('choose_incarnation_wound', ['target_ids' => [$enemyA->instanceId]])
);
cgpAssert($result->success, $result->error ?? 'Poganishche wound choice should succeed.');
cgpAssert($enemyA->hp === 4, 'Chosen target should take 1 damage.');
cgpAssert($enemyB->hp === 5, 'Unchosen target should not take damage.');
cgpAssert(empty($state->battle['pending_incarnation_wound']), 'Poganishche pending queue should clear.');

echo "Chaos/Germet/Poganishche tests passed.\n";
