<?php
// tools/test_deal_scout_recruit.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\PrepareProcessor;
use Berserk\Core\ResourceCalculator;
use Berserk\View\Screen\DealScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dealState(array $resources = ['gold' => 24, 'silver' => 22]): GameState
{
    $state = new GameState(1, 1, 2);
    $state->status = 'deal';
    $state->getPlayer(GameState::PLAYER_HOST)->resources = $resources;
    $state->getPlayer(GameState::PLAYER_PLAYER)->resources = $resources;
    return $state;
}

function addDealCard(
    GameState $state,
    string $owner,
    string $ukid,
    string $zone,
    int $price,
    bool $elite,
    array $prop = [],
    string $element = 'neutral',
    int $health = 5,
): CardInstance {
    $card = new CardInstance(
        instanceId: $state->nextInstanceId(),
        ukid: $ukid,
        owner: $owner,
        zone: $zone,
        hp: $health,
        hpMax: $health,
        element: $element,
        price: $price,
        elite: $elite,
        prop: $prop,
    );
    $state->addCard($card);
    return $card;
}

function zoneCount(GameState $state, string $owner, string $zone): int
{
    $count = 0;
    foreach ($state->cards as $card) {
        if ($card->owner === $owner && $card->zone === $zone) {
            $count++;
        }
    }
    return $count;
}

function scoutProp(int $discountPerElite = 1): array
{
    return [
        'zot' => true,
        'deal' => [
            'scout_recruit' => [
                'reveal_count' => 2,
                'discount_per_elite' => $discountPerElite,
                'discount_resource' => 'silver',
                'requires_empty_squad' => true,
                'lock_return_after_recruit' => true,
            ],
        ],
    ];
}

function recruitScoutWithOpponentCards(
    array $opponentElite,
    array $resources = ['gold' => 24, 'silver' => 22],
    int $discountPerElite = 1,
): array {
    $state = dealState($resources);
    $scout = addDealCard($state, GameState::PLAYER_HOST, 's1_187', CardInstance::ZONE_HAND, 6, false, scoutProp($discountPerElite));

    foreach ($opponentElite as $i => $elite) {
        addDealCard(
            $state,
            GameState::PLAYER_PLAYER,
            'opp_' . $i,
            CardInstance::ZONE_HAND,
            $elite ? 3 : 2,
            $elite,
        );
    }

    $processor = new PrepareProcessor($state);
    $result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_187']));
    assertTrue($result->success, $result->error ?? 'Scout should open confirm pending');

    $result = $processor->confirmDealScoutRecruit(GameState::PLAYER_HOST);
    assertTrue($result->success, $result->error ?? 'Scout confirm should reveal opponent cards');

    return [$state, $processor, $scout];
}

// First click opens cancellable confirmation and does not recruit.
$state = dealState();
$scout = addDealCard($state, GameState::PLAYER_HOST, 's1_187', CardInstance::ZONE_HAND, 6, false, scoutProp());
addDealCard($state, GameState::PLAYER_PLAYER, 'opp_elite', CardInstance::ZONE_HAND, 3, true);
addDealCard($state, GameState::PLAYER_PLAYER, 'opp_common', CardInstance::ZONE_HAND, 2, false);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_187']));
assertTrue($result->success, $result->error ?? 'Scout should open pending instead of recruiting immediately');
assertTrue($scout->zone === CardInstance::ZONE_HAND, 'Scout must remain in hand before confirmation');
assertTrue(($state->battle['pending_deal_scout_recruit']['step'] ?? null) === 'confirm', 'Scout should start with confirm pending');

$result = $processor->cancelDealScoutRecruit(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Scout confirm pending should be cancellable');
assertTrue($scout->zone === CardInstance::ZONE_HAND, 'Cancelled scout should stay in hand');
assertTrue(empty($state->battle['pending_deal_scout_recruit']), 'Cancelled scout should clear pending state');

// Confirm reveals exactly two existing opponent hand instances once.
[$state, $processor, $scout] = recruitScoutWithOpponentCards([true, false]);
$pending = $state->battle['pending_deal_scout_recruit'] ?? [];
assertTrue(($pending['step'] ?? null) === 'reveal', 'Confirmed scout should move to reveal pending');
assertTrue(count((array) ($pending['revealed_ids'] ?? [])) === 2, 'Scout should reveal two opponent hand instances');
assertTrue((int) ($pending['elite_count'] ?? 0) === 1, 'Scout should count revealed elite cards');
assertTrue((int) ($pending['discount'] ?? 0) === 1, 'Scout discount should come from revealed elite count');
$restored = GameState::fromArray($state->toArray());
assertTrue(($restored->battle['pending_deal_scout_recruit']['revealed_ids'] ?? []) === $pending['revealed_ids'], 'Scout reveal result should survive serialization without rerolling');

$result = $processor->cancelDealScoutRecruit(GameState::PLAYER_HOST);
assertTrue(!$result->success, 'Scout reveal pending should not be cancellable');

$screen = (new DealScreen(new Template(__DIR__ . '/../templates/')))->prepare($state, GameState::PLAYER_HOST, 'host', null, [
    's1_187' => [
        'name' => 'Лазутчица',
        'price' => 6,
        'health' => 5,
        'move' => 1,
        'elite' => false,
        'element' => 'Нейтральная',
        'strike' => ['weak' => 1, 'medium' => 2, 'strong' => 3],
    ],
    'opp_0' => [
        'name' => 'Золотая проверка',
        'price' => 3,
        'health' => 5,
        'move' => 1,
        'elite' => true,
        'element' => 'Нейтральная',
        'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 1],
    ],
    'opp_1' => [
        'name' => 'Серебряная проверка',
        'price' => 2,
        'health' => 5,
        'move' => 1,
        'elite' => false,
        'element' => 'Нейтральная',
        'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 1],
    ],
]);
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Закрыть'), 'Scout reveal panel should ask only to close');
assertTrue(!str_contains($screen['data']['bottom_panel_html'], 'Отмена'), 'Scout reveal panel should not render cancel action');

// Closing the reveal performs mandatory recruit with silver discount and locks return.
$result = $processor->closeDealScoutRecruit(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Closing scout reveal should recruit the card');
assertTrue($scout->zone === CardInstance::ZONE_SQUAD, 'Scout should move to squad after reveal close');
assertTrue(empty($state->battle['pending_deal_scout_recruit']), 'Scout pending should be cleared after mandatory recruit');
assertTrue(($scout->flags['deal_scout_recruit']['elite_count'] ?? null) === 1, 'Scout should store reveal elite count on the instance');
assertTrue(($scout->flags['deal_scout_recruit']['silver_discount'] ?? null) === 1, 'Scout should store silver discount on the instance');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['silver_left'] === 17, 'Scout should spend ordinary cost minus silver discount');
assertTrue($calc['gold_left'] === 24, 'Scout silver discount should not refund or spend gold');
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $scout) === 5, 'Scout effective cost should show discounted silver cost');

$result = $processor->unpickCard(GameState::PLAYER_HOST, new Command('unpick_card', ['ukid' => 's1_187']));
assertTrue(!$result->success, 'Scout should not be returnable after confirmed recruit');
assertTrue($scout->zone === CardInstance::ZONE_SQUAD, 'Rejected scout return should leave card in squad');

$result = $processor->reshuffle(GameState::PLAYER_HOST);
assertTrue(!$result->success, 'Scout lock should block reshuffle after recruit');

// Two elite cards give two silver discount; zero elite cards give none.
[$state, $processor, $scout] = recruitScoutWithOpponentCards([true, true]);
$processor->closeDealScoutRecruit(GameState::PLAYER_HOST);
assertTrue(($scout->flags['deal_scout_recruit']['silver_discount'] ?? null) === 2, 'Scout should read discount amount from revealed elite cards');
assertTrue(ResourceCalculator::compute($state, GameState::PLAYER_HOST)['silver_left'] === 18, 'Two elite cards should reduce a 6 silver cost to 4');

[$state, $processor, $scout] = recruitScoutWithOpponentCards([false, false]);
$processor->closeDealScoutRecruit(GameState::PLAYER_HOST);
assertTrue(($scout->flags['deal_scout_recruit']['silver_discount'] ?? null) === 0, 'Scout should give no discount without revealed elite cards');
assertTrue(ResourceCalculator::compute($state, GameState::PLAYER_HOST)['silver_left'] === 16, 'No elite cards should keep full silver cost');

// Discount cannot make silver cost negative and discount_per_elite is data-driven.
[$state, $processor, $scout] = recruitScoutWithOpponentCards([true, true], ['gold' => 24, 'silver' => 2], 5);
$processor->closeDealScoutRecruit(GameState::PLAYER_HOST);
assertTrue(($scout->flags['deal_scout_recruit']['silver_discount'] ?? null) === 10, 'Scout should store data-driven discount even if cost floors at zero');
assertTrue(ResourceCalculator::compute($state, GameState::PLAYER_HOST)['silver_left'] === 2, 'Scout discount should not make silver cost negative');

// Beginning-only rule is enforced server-side.
$state = dealState();
addDealCard($state, GameState::PLAYER_HOST, 'already_squad', CardInstance::ZONE_SQUAD, 1, false);
$scout = addDealCard($state, GameState::PLAYER_HOST, 's1_187', CardInstance::ZONE_HAND, 6, false, scoutProp());
addDealCard($state, GameState::PLAYER_PLAYER, 'opp_elite', CardInstance::ZONE_HAND, 3, true);
addDealCard($state, GameState::PLAYER_PLAYER, 'opp_common', CardInstance::ZONE_HAND, 2, false);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_187']));
assertTrue(!$result->success, 'Scout should be recruitable only at the beginning of Deal recruit');
assertTrue($scout->zone === CardInstance::ZONE_HAND, 'Rejected beginning-only scout should stay in hand');

echo "Deal scout recruit tests passed.\n";
