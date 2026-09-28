<?php
// tools/test_talion.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;
use Berserk\Core\TurnProcessor;
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function talionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function talionProp(): array
{
    return [
        'ova' => ['value' => 1],
        'armor' => ['value' => 1],
        'incarnation' => 4,
        'on_strong_strike' => ['type' => 'incarnation_token_graveyard'],
    ];
}

function talionState(CardInstance ...$cards): GameState
{
    $state = new GameState(149, 101, 202);
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

function talionCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'] ?? 1,
        ukid: $overrides['ukid'] ?? 's1_149',
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 9,
        hpMax: $overrides['hpMax'] ?? 9,
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        armor: $overrides['armor'] ?? 0,
        armorMax: $overrides['armorMax'] ?? 0,
        strikeWeak: $overrides['strikeWeak'] ?? 2,
        strikeMedium: $overrides['strikeMedium'] ?? 3,
        strikeStrong: $overrides['strikeStrong'] ?? 5,
        prop: $overrides['prop'] ?? talionProp(),
        markers: $overrides['markers'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function talionCreature(int $id, string $owner, string $zone, array $overrides = []): CardInstance
{
    return talionCard(array_replace([
        'instanceId' => $id,
        'ukid' => 'card_' . $id,
        'owner' => $owner,
        'zone' => $zone,
        'row' => $zone === CardInstance::ZONE_FIELD ? 4 : null,
        'col' => $zone === CardInstance::ZONE_FIELD ? 3 : null,
        'hp' => $zone === CardInstance::ZONE_GRAVEYARD ? 0 : 10,
        'hpMax' => 10,
        'strikeWeak' => 1,
        'strikeMedium' => 2,
        'strikeStrong' => 3,
        'prop' => [],
        'markers' => [],
    ], $overrides));
}

function talionCardsInfo(): array
{
    return [
        's1_149' => ['name' => 'Талион'],
        'card_2' => ['name' => 'Цель'],
        'card_3' => ['name' => 'Дубликат'],
        'card_4' => ['name' => 'Дубликат'],
        'card_5' => ['name' => 'Без Инкарнации'],
        'card_6' => ['name' => 'Чужой труп'],
    ];
}

function talionApplyStrike(GameState $state, string $level): void
{
    $state->battle['strike'] = [
        'attacker_id' => 1,
        'target_id' => 2,
        'defender_id' => null,
        'state' => 'results',
        'attack_dice' => 6,
        'defend_dice' => 0,
        'confirmed' => [],
    ];

    (new StrikeResolver($state, new Engine()))->apply([
        'attack' => $level,
        'defend' => '',
        'winner' => 'attack',
    ], false);
}

function talionApply(GameState $state, string $playerKey, Command $cmd): \Berserk\Core\Result
{
    return (new Engine())->apply($state, $playerKey, $cmd);
}

$talion = talionCard();
talionAssert(CardStats::getStat($talion, 'ova') === 1, 'Talion should have OVA 1 from prop.');
talionAssert(CardStats::getStat($talion, 'armor') === 1, 'Talion should have armor 1 from prop.');
talionAssert(($talion->prop['incarnation'] ?? null) === 4, 'Talion should have Incarnation 4.');

$state = talionState(
    talionCard(),
    talionCreature(2, GameState::PLAYER_PLAYER, CardInstance::ZONE_FIELD),
    talionCreature(3, GameState::PLAYER_HOST, CardInstance::ZONE_GRAVEYARD, [
        'prop' => ['incarnation' => 3],
        'markers' => ['incarnation' => ['value' => 1, 'threshold' => 3, 'open' => false]],
    ])
);
talionApplyStrike($state, 'medium');
talionAssert(($state->getCard(3)->markers['incarnation']['value'] ?? null) === 1, 'Medium strike should not add Talion token.');
talionAssert(empty($state->battle['pending_talion_incarnation']), 'Medium strike should not open Talion pending.');

$state = talionState(
    talionCard(),
    talionCreature(2, GameState::PLAYER_PLAYER, CardInstance::ZONE_FIELD),
    talionCreature(3, GameState::PLAYER_HOST, CardInstance::ZONE_GRAVEYARD, [
        'prop' => ['incarnation' => 3],
        'markers' => ['incarnation' => ['value' => 1, 'threshold' => 3, 'open' => false]],
    ])
);
talionApplyStrike($state, 'weak');
talionAssert(($state->getCard(3)->markers['incarnation']['value'] ?? null) === 1, 'Weak strike should not add Talion token.');
talionAssert(empty($state->battle['pending_talion_incarnation']), 'Weak strike should not open Talion pending.');

$state = talionState(
    talionCard(),
    talionCreature(2, GameState::PLAYER_PLAYER, CardInstance::ZONE_FIELD),
    talionCreature(6, GameState::PLAYER_PLAYER, CardInstance::ZONE_GRAVEYARD, [
        'prop' => ['incarnation' => 2],
        'markers' => ['incarnation' => ['value' => 0, 'threshold' => 2, 'open' => false]],
    ])
);
talionApplyStrike($state, 'strong');
talionAssert(empty($state->battle['pending_talion_incarnation']), 'Empty own graveyard should not open Talion pending.');
talionAssert(($state->getCard(6)->markers['incarnation']['value'] ?? null) === 0, 'Enemy graveyard creature should not receive Talion token.');

$state = talionState(
    talionCard(),
    talionCreature(2, GameState::PLAYER_PLAYER, CardInstance::ZONE_FIELD),
    talionCreature(3, GameState::PLAYER_HOST, CardInstance::ZONE_GRAVEYARD, [
        'prop' => ['incarnation' => 3],
        'markers' => ['incarnation' => ['value' => 2, 'threshold' => 3, 'open' => false]],
    ])
);
talionApplyStrike($state, 'strong');
talionAssert($state->getCard(2)->hp === 5, 'Strong strike test should deal real wounds.');
talionAssert(($state->getCard(3)->markers['incarnation']['value'] ?? null) === 3, 'Strong strike with real wounds should add Talion token.');
talionAssert(empty($state->getCard(3)->flags['incarnation_ready']), 'Talion token should not immediately incarnate a ready card.');
talionAssert(empty($state->getCard(3)->markers['talion_token']), 'Talion should use the existing incarnation marker storage.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    talionCardsInfo(),
    '/battle?game=149&first='
);
talionAssert(str_contains($html, 'Талион'), 'InfoPanel should mention Talion token source.');
talionAssert(str_contains($html, 'получает жетон инкарнации'), 'InfoPanel should explain Talion token.');
talionAssert(str_contains($html, '3/3'), 'InfoPanel should show incarnation progress.');
$events = (new TurnProcessor($state, new Engine()))->processIncarnation(GameState::PLAYER_HOST);
talionAssert(($state->getCard(3)->flags['incarnation_ready'] ?? false) === true, 'Normal start-turn incarnation phase should process cards that reached threshold.');
talionAssert(($state->battle['pending_incarnation']['current'] ?? null) === 3, 'Normal start-turn incarnation phase should queue ready non-fly creatures.');
talionAssert($events === [], 'Queued incarnation creature should be removed from immediate events.');

$state = talionState(
    talionCard(),
    talionCreature(2, GameState::PLAYER_PLAYER, CardInstance::ZONE_FIELD, [
        'prop' => ['damage_reduction' => [['types' => ['strike'], 'value' => 99]]],
    ]),
    talionCreature(3, GameState::PLAYER_HOST, CardInstance::ZONE_GRAVEYARD, [
        'prop' => ['incarnation' => 3],
        'markers' => ['incarnation' => ['value' => 2, 'threshold' => 3, 'open' => false]],
    ])
);
talionApplyStrike($state, 'strong');
talionAssert(($state->battle['strike']['damage_total'] ?? null) === 0, 'Strong strike test should be reduced to 0 damage.');
talionAssert(($state->getCard(3)->markers['incarnation']['value'] ?? null) === 2, 'Strong strike with 0 actual damage should not add Talion token.');
talionAssert(empty($state->battle['pending_talion_incarnation']), 'Strong strike with 0 actual damage should not open Talion pending.');

$state = talionState(
    talionCard(),
    talionCreature(2, GameState::PLAYER_PLAYER, CardInstance::ZONE_FIELD, [
        'prop' => ['zoa' => true],
    ]),
    talionCreature(3, GameState::PLAYER_HOST, CardInstance::ZONE_GRAVEYARD, [
        'prop' => ['incarnation' => 3],
        'markers' => ['incarnation' => ['value' => 2, 'threshold' => 3, 'open' => false]],
    ])
);
talionApplyStrike($state, 'strong');
talionAssert(($state->getCard(2)->hp ?? null) === 10, 'Blocked strong strike should deal no wounds.');
talionAssert(($state->getCard(3)->markers['incarnation']['value'] ?? null) === 2, 'Blocked strong strike should not add Talion token.');
talionAssert(empty($state->battle['pending_talion_incarnation']), 'Blocked strong strike should not open Talion pending.');

$state = talionState(
    talionCard(),
    talionCreature(2, GameState::PLAYER_PLAYER, CardInstance::ZONE_FIELD),
    talionCreature(3, GameState::PLAYER_HOST, CardInstance::ZONE_GRAVEYARD, [
        'prop' => ['incarnation' => 3],
        'markers' => ['incarnation' => ['value' => 1, 'threshold' => 3, 'open' => false]],
    ]),
    talionCreature(4, GameState::PLAYER_HOST, CardInstance::ZONE_GRAVEYARD, [
        'prop' => ['incarnation' => 4],
        'markers' => ['incarnation' => ['value' => 0, 'threshold' => 4, 'open' => false]],
    ]),
    talionCreature(5, GameState::PLAYER_HOST, CardInstance::ZONE_GRAVEYARD),
    talionCreature(6, GameState::PLAYER_PLAYER, CardInstance::ZONE_GRAVEYARD, [
        'prop' => ['incarnation' => 2],
        'markers' => ['incarnation' => ['value' => 0, 'threshold' => 2, 'open' => false]],
    ])
);
talionApplyStrike($state, 'strong');
$pending = $state->battle['pending_talion_incarnation'] ?? null;
talionAssert(is_array($pending), 'Multiple own graveyard creatures should open mandatory Talion pending.');
talionAssert(($pending['owner'] ?? null) === GameState::PLAYER_HOST, 'Talion pending should belong to source owner.');
talionAssert(($pending['candidate_ids'] ?? []) === [3, 4, 5], 'Talion candidates should include own graveyard creature instances only.');
$cancel = talionApply($state, GameState::PLAYER_HOST, new Command('cancel_pending'));
talionAssert(!$cancel->success, 'Talion mandatory pending should not accept cancel_pending.');
talionAssert(!empty($state->battle['pending_talion_incarnation']), 'Rejected cancel should leave Talion pending intact.');
talionAssert(($state->getCard(3)->markers['incarnation']['value'] ?? null) === 1, 'Rejected cancel should not change first candidate tokens.');
talionAssert(empty($state->getCard(5)->markers['incarnation']), 'Rejected cancel should not add token to no-incarnation candidate.');

$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    talionCardsInfo(),
    '/battle?game=149&first='
);
talionAssert(str_contains($html, 'Дубликат #3'), 'Talion choice should distinguish duplicate names by instance id.');
talionAssert(str_contains($html, 'Дубликат #4'), 'Talion choice should list the second duplicate instance.');
talionAssert(str_contains($html, 'без Инкарнации'), 'Talion choice should mark cards without Incarnation.');
talionAssert(!str_contains($html, 'cancel_pending'), 'Talion choice should not render a cancel command.');

$result = talionApply($state, GameState::PLAYER_HOST, new Command('choose_talion_incarnation', ['target_id' => 4]));
talionAssert($result->success, $result->error ?? 'Talion choice should succeed.');
talionAssert(empty($state->battle['pending_talion_incarnation']), 'Chosen Talion pending should be cleared.');
talionAssert(($state->getCard(4)->markers['incarnation']['value'] ?? null) === 1, 'Chosen duplicate instance should receive the token.');
talionAssert(($state->getCard(3)->markers['incarnation']['value'] ?? null) === 1, 'Unchosen duplicate instance should keep previous tokens.');

$state = talionState(
    talionCard(['prop' => talionProp() + ['actions' => [['type' => 'uchr', 'value' => 9]]]]),
    talionCreature(2, GameState::PLAYER_PLAYER, CardInstance::ZONE_FIELD, ['row' => 5, 'col' => 3]),
    talionCreature(3, GameState::PLAYER_HOST, CardInstance::ZONE_GRAVEYARD, [
        'prop' => ['incarnation' => 3],
        'markers' => ['incarnation' => ['value' => 0, 'threshold' => 3, 'open' => false]],
    ])
);
$result = talionApply($state, GameState::PLAYER_HOST, new Command('uchr', ['card_id' => 1, 'target_id' => 2]));
talionAssert($result->success, $result->error ?? 'Talion test uchr should resolve.');
talionAssert(($state->getCard(3)->markers['incarnation']['value'] ?? null) === 0, 'Non-strike damage should not add Talion token.');
talionAssert(empty($state->battle['pending_talion_incarnation']), 'Non-strike damage should not open Talion pending.');

$state = talionState(
    talionCard(),
    talionCreature(2, GameState::PLAYER_PLAYER, CardInstance::ZONE_FIELD),
    talionCreature(5, GameState::PLAYER_HOST, CardInstance::ZONE_GRAVEYARD)
);
talionApplyStrike($state, 'strong');
talionAssert(($state->getCard(5)->markers['incarnation']['value'] ?? null) === 1, 'No-incarnation creature can receive a Talion token.');
talionAssert(($state->getCard(5)->markers['incarnation']['threshold'] ?? null) === 0, 'No-incarnation token marker should have no threshold.');
$events = (new TurnProcessor($state, new Engine()))->processIncarnation(GameState::PLAYER_HOST);
talionAssert(($state->getCard(5)->markers['incarnation']['value'] ?? null) === 1, 'Start-turn incarnation should ignore no-incarnation Talion token markers.');
talionAssert(empty($state->getCard(5)->flags['incarnation_ready']), 'No-incarnation card should not become incarnation-ready.');
talionAssert($events === [], 'No-incarnation card should not create incarnation events.');

echo "Talion tests passed\n";
