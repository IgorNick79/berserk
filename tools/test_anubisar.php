<?php
// tools/test_anubisar.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function anAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function anProp(): array
{
    return [
        'armor' => ['value' => 1],
        'has_armor' => true,
        'has_line' => true,
        'strike_effects' => [[
            'levels' => ['strong'],
            'chain_damage' => [
                'value' => 1,
                'filter' => 'enemy',
                'max_targets' => 2,
            ],
        ]],
        'inherit_line_bonuses' => [
            'source_filter' => [
                'element' => 'plains',
                'relation' => 'same_line',
            ],
            'replace_subject' => true,
        ],
    ];
}

function anState(CardInstance ...$cards): GameState
{
    $state = new GameState(2601, 101, 202);
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

function anCard(array $o): CardInstance
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
        type: $o['type'] ?? 'creature',
        closed: $o['closed'] ?? false,
        element: $o['element'] ?? 'neutral',
        move: $o['move'] ?? 1,
        moveMax: $o['moveMax'] ?? 1,
        armor: $o['armor'] ?? 0,
        armorMax: $o['armorMax'] ?? 0,
        strikeWeak: $o['strikeWeak'] ?? 1,
        strikeMedium: $o['strikeMedium'] ?? 2,
        strikeStrong: $o['strikeStrong'] ?? 3,
        prop: $o['prop'] ?? [],
        flags: $o['flags'] ?? [],
    );
}

function anAnubisar(array $o = []): CardInstance
{
    return anCard(array_replace([
        'instanceId' => 26,
        'ukid' => 's1_26',
        'row' => 3,
        'col' => 3,
        'hp' => 6,
        'hpMax' => 6,
        'element' => 'neutral',
        'strikeWeak' => 2,
        'strikeMedium' => 3,
        'strikeStrong' => 5,
        'prop' => anProp(),
    ], $o));
}

function anEnemy(int $id, int $row, int $col, array $o = []): CardInstance
{
    return anCard(array_replace([
        'instanceId' => $id,
        'owner' => GameState::PLAYER_PLAYER,
        'row' => $row,
        'col' => $col,
        'hp' => 5,
        'hpMax' => 5,
        'prop' => [],
    ], $o));
}

function anSteppeSource(string $ukid, int $id, int $row = 3, int $col = 4, array $extra = []): CardInstance
{
    $props = [
        's1_15' => ['has_line' => true, 'on_successful_strike' => ['key' => 'holvert_open', 'type' => 'open_line_ally_cannot_attack', 'uses_per_turn' => 1]],
        's1_16' => ['ability' => ['line' => true, 'only' => ['strike'], 'level' => ['weak'], 'value' => 1], 'block_weak_strike' => true],
        's1_17' => ['zot' => ['line' => true], 'actions' => [['type' => 'shot', 'strike' => ['weak' => 1, 'medium' => 2, 'strong' => 3]]]],
        's1_19' => ['damage_reduction' => [['types' => ['shot', 'throw', 'discharge', 'uchr'], 'value' => 1], ['line' => true, 'types' => ['strike'], 'value' => 1]], 'aura_plains_ranged' => ['types' => ['shot', 'throw', 'discharge', 'uchr'], 'value' => 1]],
        's1_29' => ['zov' => true, 'armor' => ['line' => true, 'check' => true, 'value' => 2], 'answer' => ['type' => 'tap', 'types' => ['strike', 'tap', 'magic'], 'value' => 2], 'has_line' => true, 'has_armor' => true],
        's1_33' => ['ova' => ['value' => 2], 'zom' => true, 'zoal' => ['line' => true], 'ability' => ['value' => 1, 'condition' => 'enemy_front_row_empty'], 'can_attack_flying' => ['condition' => 'enemy_front_row_empty']],
        's1_34' => ['armor' => ['value' => 2], 'ability' => ['line' => true, 'only' => ['strike'], 'level' => ['strong'], 'value' => 1], 'attacks_per_turn' => 2],
    ];
    return anCard(array_replace([
        'instanceId' => $id,
        'ukid' => $ukid,
        'owner' => GameState::PLAYER_HOST,
        'row' => $row,
        'col' => $col,
        'element' => 'plains',
        'prop' => $props[$ukid],
    ], $extra));
}

$enemy = anEnemy(2, 3, 4);

// Санкторум: only line weak +1 is inherited, weak-block is not.
$state = anState(anAnubisar(), anSteppeSource('s1_16', 16), anEnemy(2, 4, 4));
anAssert(CardStats::getStrikeValue($state, $state->getCard(26), $state->getCard(2), 'weak') === 3, 'Anubisar should inherit Sanctorem weak strike +1.');
anAssert(empty($state->getCard(26)->prop['block_weak_strike']), 'Anubisar should not copy Sanctorem non-line weak block.');
$state->getCard(16)->row = 5;
anAssert(CardStats::getStrikeValue($state, $state->getCard(26), $state->getCard(2), 'weak') === 2, 'Inherited Sanctorem bonus should disappear when line breaks.');

// Минотавр: only line strong +1 is inherited, extra attack is not.
$state = anState(anAnubisar(), anSteppeSource('s1_34', 34), anEnemy(2, 4, 4));
anAssert(CardStats::getStrikeValue($state, $state->getCard(26), $state->getCard(2), 'strong') === 6, 'Anubisar should inherit Minotaur strong strike +1.');
anAssert(empty($state->getCard(26)->prop['attacks_per_turn']), 'Anubisar should not copy Minotaur attacks_per_turn.');

// Арбалетчик: line ZOT is inherited, shot action/coins are not.
$state = anState(anAnubisar(), anSteppeSource('s1_17', 17), anEnemy(2, 4, 4, ['type' => 'creature']));
anAssert(CardStats::hasDefense($state, $state->getCard(26), 'throw', $state->getCard(2)), 'Anubisar should inherit Arbalester line ZOT.');
anAssert(!CardStats::hasDefense($state, $state->getCard(26), 'shot', $state->getCard(2)), 'Anubisar should not inherit Arbalester shot defense.');
anAssert(empty($state->getCard(26)->prop['actions']), 'Anubisar should not copy Arbalester shot action.');

// Щитоносец: line strike reduction is inherited, ranged aura is not copied.
$state = anState(anAnubisar(), anSteppeSource('s1_19', 19), anEnemy(2, 4, 4, ['strikeWeak' => 2]));
anAssert(CardStats::getDamageReduction($state, $state->getCard(2), $state->getCard(26), 'strike') === 1, 'Anubisar should inherit Shieldbearer line strike reduction.');
anAssert(CardStats::getDamageReduction($state, $state->getCard(2), $state->getCard(26), 'shot') === 0, 'Anubisar should not copy Shieldbearer non-line ranged reduction.');

// Пеший латник: line armor 2 is inherited over Anubisar base armor 1, ordinary ZOV is not.
$state = anState(anAnubisar(), anSteppeSource('s1_29', 29), anEnemy(2, 4, 4));
anAssert(CardStats::computeArmor($state, $state->getCard(26)) === 2, 'Anubisar should inherit Footman line armor 2.');
anAssert(!CardStats::hasDefense($state, $state->getCard(26), 'shot', $state->getCard(2)), 'Anubisar should not inherit Footman ordinary ZOV.');
$state->getCard(29)->row = 5;
anAssert(CardStats::computeArmor($state, $state->getCard(26)) === 1, 'Anubisar should keep base armor 1 after Footman line breaks.');

// Скелос: line ZOAL is inherited, ordinary OVA/strike/fly attack are not.
$fly = anEnemy(2, 4, 4, ['type' => 'fly']);
$state = anState(anAnubisar(), anSteppeSource('s1_33', 33), $fly);
anAssert(CardStats::hasDefense($state, $state->getCard(26), 'strike', $fly), 'Anubisar should inherit Skelos line ZOAL against flying attacker.');
anAssert(CardStats::getOva($state, $state->getCard(26), $fly) === 0, 'Anubisar should not copy Skelos OVA.');
anAssert(!CardStats::canAttackFlying($state, $state->getCard(26)), 'Anubisar should not copy Skelos ordinary can_attack_flying.');

// Рубаки Холверта: inherited successful strike trigger uses Anubisar as the subject.
$closedAlly = anCard(['instanceId' => 40, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 2, 'closed' => true, 'prop' => ['has_line' => true]]);
$state = anState(anAnubisar(), anSteppeSource('s1_15', 15, 3, 4), $closedAlly, anEnemy(2, 4, 4));
$state->battle['strike'] = ['attacker_id' => 26, 'target_id' => 2, 'defender_id' => null, 'state' => 'results', 'attack_dice' => 6, 'defend_dice' => 0, 'confirmed' => []];
(new StrikeResolver($state, new Engine()))->apply(['attack' => 'strong', 'defend' => '', 'winner' => 'attack'], false);
anAssert(!$closedAlly->closed, 'Inherited Holvert trigger should open a line ally of Anubisar.');
anAssert(!empty(array_filter($closedAlly->modifiers, fn(array $m): bool => ($m['stat'] ?? '') === 'cannot_attack')), 'Inherited Holvert trigger should prevent the opened ally from attacking.');

// Multiple sources and duplicates stack through existing rules.
$state = anState(anAnubisar(), anSteppeSource('s1_16', 16, 3, 4), anSteppeSource('s1_16', 116, 3, 2), anSteppeSource('s1_34', 34, 2, 3), anEnemy(2, 4, 4));
anAssert(CardStats::getStrikeValue($state, $state->getCard(26), $state->getCard(2), 'weak') === 4, 'Duplicate Sanctorem sources should stack weak strike bonuses.');
anAssert(CardStats::getStrikeValue($state, $state->getCard(26), $state->getCard(2), 'strong') === 6, 'Different line sources should combine independently.');

// Non-steppe and different line group are ignored.
$nonSteppe = anCard(['instanceId' => 50, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 4, 'element' => 'forests', 'prop' => ['ability' => ['line' => true, 'only' => ['strike'], 'level' => ['weak'], 'value' => 9]]]);
$farSteppe = anSteppeSource('s1_16', 51, 5, 5);
$state = anState(anAnubisar(), $nonSteppe, $farSteppe, anEnemy(2, 4, 4));
anAssert(CardStats::getStrikeValue($state, $state->getCard(26), $state->getCard(2), 'weak') === 2, 'Anubisar should ignore non-steppe and out-of-line sources.');
$farSteppe->row = 3;
$farSteppe->col = 4;
anAssert(CardStats::getStrikeValue($state, $state->getCard(26), $state->getCard(2), 'weak') === 3, 'Anubisar should dynamically gain a source that joins his line.');
$farSteppe->dying = true;
anAssert(CardStats::getStrikeValue($state, $state->getCard(26), $state->getCard(2), 'weak') === 2, 'Anubisar should dynamically lose a dying source.');

// Chain damage: strong strike damages up to two enemy creatures adjacent to the attacked target.
$state = anState(
    anAnubisar(),
    anEnemy(2, 4, 4, ['hp' => 5]),
    anEnemy(3, 4, 3, ['hp' => 1]),
    anEnemy(4, 5, 4, ['hp' => 5]),
    anEnemy(5, 5, 5, ['hp' => 5]),
    anCard(['instanceId' => 6, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 5, 'prop' => []])
);
$state->battle['strike'] = ['attacker_id' => 26, 'target_id' => 2, 'defender_id' => null, 'state' => 'results', 'attack_dice' => 6, 'defend_dice' => 0, 'confirmed' => []];
(new StrikeResolver($state, new Engine()))->apply(['attack' => 'strong', 'defend' => '', 'winner' => 'attack'], false);
anAssert($state->getCard(3)->hp === 0 && $state->getCard(3)->dying, 'Chain damage should use the normal damage/death path.');
anAssert($state->getCard(4)->hp === 4, 'Chain damage should damage the second adjacent enemy.');
anAssert($state->getCard(5)->hp === 5, 'Chain damage should not exceed max_targets 2.');
anAssert($state->getCard(6)->hp === 10, 'Chain damage should not hit own creatures.');
anAssert(!empty($state->battle['strike']['chain_damage']), 'Chain damage should be recorded on the strike result.');

$state = anState(anAnubisar(), anEnemy(2, 4, 4), anEnemy(3, 4, 3));
$state->battle['strike'] = ['attacker_id' => 26, 'target_id' => 2, 'defender_id' => null, 'state' => 'results', 'attack_dice' => 4, 'defend_dice' => 0, 'confirmed' => []];
(new StrikeResolver($state, new Engine()))->apply(['attack' => 'medium', 'defend' => '', 'winner' => 'attack'], false);
anAssert(empty($state->battle['strike']['chain_damage']), 'Chain damage should not trigger on medium strike.');

echo "Anubisar tests passed\n";
