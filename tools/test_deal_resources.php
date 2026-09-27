<?php
// tools/test_deal_resources.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
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

function addDealCard(
    GameState $state,
    string $owner,
    string $ukid,
    string $zone,
    int $price,
    bool $elite,
    array $prop = [],
    string $element = 'neutral',
): CardInstance {
    $card = new CardInstance(
        instanceId: $state->nextInstanceId(),
        ukid: $ukid,
        owner: $owner,
        zone: $zone,
        hp: 5,
        hpMax: 5,
        element: $element,
        price: $price,
        elite: $elite,
        prop: $prop,
    );
    $state->addCard($card);
    return $card;
}

function dealState(array $resources = ['gold' => 24, 'silver' => 22]): GameState
{
    $state = new GameState(1, 1, 2);
    $state->status = 'deal';
    $state->getPlayer(GameState::PLAYER_HOST)->resources = $resources;
    return $state;
}

function addSquadPrices(GameState $state, array $prices, array $eliteByPrice = []): array
{
    $cards = [];
    foreach ($prices as $i => $price) {
        $cards[] = addDealCard(
            $state,
            GameState::PLAYER_HOST,
            'cost_' . $price . '_' . $i,
            CardInstance::ZONE_SQUAD,
            (int) $price,
            (bool) ($eliteByPrice[(int) $price] ?? false),
        );
    }
    return $cards;
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

function findCardByUkid(GameState $state, string $ukid): CardInstance
{
    foreach ($state->cards as $card) {
        if ($card->ukid === $ukid) {
            return $card;
        }
    }

    throw new RuntimeException("Card not found: {$ukid}");
}

$marauderProp = ['deal' => ['resource_modifier' => ['elite_gold' => 1]]];
$quartermasterProp = ['deal' => ['cost_modifier' => ['free_if_squad_has_costs' => [3, 4, 5, 6, 7, 8]]]];
$teechProp = ['deal' => ['resource_modifier' => ['elite_gold' => 2], 'squad_constraint' => ['max_elemental_cards' => 3]]];
$freeWarriorProp = ['deal' => ['recruit_choice' => ['extra_cost' => ['min' => 0, 'max' => 4, 'resource' => 'silver'], 'instance_buff' => ['attack_per_x' => 1, 'health' => 2]]]];

// Successful recruit: ordinary cost is paid from silver, then elite gold bonus is derived from squad.
$state = dealState();
addDealCard($state, GameState::PLAYER_HOST, 's1_171', CardInstance::ZONE_HAND, 3, false, $marauderProp);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_171']));
assertTrue($result->success, $result->error ?? 'Marauder recruit should succeed');

$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['gold_total'] === 25, 'Marauder should add one elite gold after recruit');
assertTrue($calc['gold_left'] === 25, 'Marauder should not spend elite gold');
assertTrue($calc['silver_left'] === 19, 'Marauder should still pay ordinary silver cost');
assertTrue($calc['elite_gold_bonus'] === 1, 'Marauder bonus should be reported');

// Return removes the card from squad, so the derived bonus disappears.
$result = $processor->unpickCard(GameState::PLAYER_HOST, new Command('unpick_card', ['ukid' => 's1_171']));
assertTrue($result->success, $result->error ?? 'Marauder return should succeed');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['gold_total'] === 24, 'Returned Marauder should remove elite gold bonus');
assertTrue($calc['gold_left'] === 24, 'Returned Marauder should restore base elite gold');
assertTrue($calc['silver_left'] === 22, 'Returned Marauder should restore ordinary silver');

// Repeated recruit/return must not generate resources.
for ($i = 0; $i < 3; $i++) {
    $result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_171']));
    assertTrue($result->success, 'Repeated Marauder recruit should succeed');
    $result = $processor->unpickCard(GameState::PLAYER_HOST, new Command('unpick_card', ['ukid' => 's1_171']));
    assertTrue($result->success, 'Repeated Marauder return should succeed');
}
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['gold_total'] === 24 && $calc['silver_left'] === 22, 'Repeated recruit/return should not generate resources');

// Failed recruit does not grant any bonus.
$state = dealState();
addDealCard($state, GameState::PLAYER_HOST, 's1_171', CardInstance::ZONE_HAND, 3, false, $marauderProp);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'missing']));
assertTrue(!$result->success, 'Failed recruit should fail');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['elite_gold_bonus'] === 0, 'Failed recruit should not grant Marauder bonus');

// Validation uses normal cost before the adding card's own deal bonus.
$state = dealState(['gold' => 0, 'silver' => 0]);
addDealCard($state, GameState::PLAYER_HOST, 'bonus_elite', CardInstance::ZONE_HAND, 1, true, $marauderProp);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'bonus_elite']));
assertTrue(!$result->success, 'Adding card own deal bonus must not pay its own recruit cost');
assertTrue(ResourceCalculator::compute($state, GameState::PLAYER_HOST)['elite_gold_bonus'] === 0, 'Rejected card should stay out of derived bonus');

// Deal cost_modifier: candidate is free only when the squad already has every required cost.
$state = dealState();
addSquadPrices($state, [3, 4, 5, 6, 7, 8]);
$quartermaster = addDealCard($state, GameState::PLAYER_HOST, 'quartermaster', CardInstance::ZONE_HAND, 4, false, $quartermasterProp);
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $quartermaster) === 0, 'Full cost set should make Quartermaster free');
assertTrue($quartermaster->price === 4, 'Effective cost must not mutate base CardInstance price');

$state = dealState();
addSquadPrices($state, [4, 5, 6, 7, 8]);
$quartermaster = addDealCard($state, GameState::PLAYER_HOST, 'quartermaster', CardInstance::ZONE_HAND, 4, false, $quartermasterProp);
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $quartermaster) === 4, 'Missing 3 should keep base cost');

$state = dealState();
addSquadPrices($state, [3, 5, 6, 7, 8]);
$quartermaster = addDealCard($state, GameState::PLAYER_HOST, 'quartermaster', CardInstance::ZONE_HAND, 4, false, $quartermasterProp);
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $quartermaster) === 4, 'Candidate price 4 must not satisfy its own missing 4 condition');

$state = dealState();
addSquadPrices($state, [3, 4, 5, 6, 8]);
$quartermaster = addDealCard($state, GameState::PLAYER_HOST, 'quartermaster', CardInstance::ZONE_HAND, 4, false, $quartermasterProp);
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $quartermaster) === 4, 'Missing 7 should keep base cost');

$state = dealState();
addSquadPrices($state, [3, 4, 5, 6, 7]);
$quartermaster = addDealCard($state, GameState::PLAYER_HOST, 'quartermaster', CardInstance::ZONE_HAND, 4, false, $quartermasterProp);
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $quartermaster) === 4, 'Missing 8 should keep base cost');

$state = dealState();
addSquadPrices($state, [3, 3, 4, 5, 6, 7, 8]);
$quartermaster = addDealCard($state, GameState::PLAYER_HOST, 'quartermaster', CardInstance::ZONE_HAND, 4, false, $quartermasterProp);
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $quartermaster) === 0, 'Duplicate costs should still satisfy the required set');

$state = dealState();
addSquadPrices($state, [3, 4, 5, 6, 7, 8], [3 => true, 5 => true, 8 => true]);
$quartermaster = addDealCard($state, GameState::PLAYER_HOST, 'quartermaster', CardInstance::ZONE_HAND, 4, false, $quartermasterProp);
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $quartermaster) === 0, 'Gold/silver mix should not affect required cost matching');

$state = dealState();
$cards = addSquadPrices($state, [3, 4, 5, 6, 7, 8]);
$quartermaster = addDealCard($state, GameState::PLAYER_HOST, 'quartermaster', CardInstance::ZONE_HAND, 4, false, $quartermasterProp);
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $quartermaster) === 0, 'Quartermaster should start free before return');
$processor = new PrepareProcessor($state);
$result = $processor->unpickCard(GameState::PLAYER_HOST, new Command('unpick_card', ['ukid' => $cards[4]->ukid]));
assertTrue($result->success, $result->error ?? 'Returning required price 7 card should succeed');
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $quartermaster) === 4, 'Returning a required price should restore Quartermaster base cost');

$state = dealState(['gold' => 0, 'silver' => 33]);
addSquadPrices($state, [3, 4, 5, 6, 7, 8]);
addDealCard($state, GameState::PLAYER_HOST, 'quartermaster', CardInstance::ZONE_HAND, 4, false, $quartermasterProp);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'quartermaster']));
assertTrue($result->success, $result->error ?? 'Quartermaster should be recruitable with only enough resources for the existing squad');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['silver_left'] === 0, 'Free Quartermaster should not spend silver');

$state = dealState(['gold' => 0, 'silver' => 29]);
addSquadPrices($state, [3, 5, 6, 7, 8]);
addDealCard($state, GameState::PLAYER_HOST, 'quartermaster', CardInstance::ZONE_HAND, 4, false, $quartermasterProp);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'quartermaster']));
assertTrue(!$result->success, 'Missing required 4 should make Quartermaster unaffordable when only base squad resources are available');

$state = dealState();
addSquadPrices($state, [3, 4, 5, 6, 7, 8]);
$ordinary = addDealCard($state, GameState::PLAYER_HOST, 'ordinary', CardInstance::ZONE_HAND, 4, false);
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $ordinary) === 4, 'Ordinary card without cost_modifier should keep base cost');

$state = dealState();
addSquadPrices($state, [3, 4, 5, 6, 7, 8]);
addDealCard($state, GameState::PLAYER_HOST, 's1_171', CardInstance::ZONE_SQUAD, 3, false, $marauderProp);
$quartermaster = addDealCard($state, GameState::PLAYER_HOST, 'quartermaster', CardInstance::ZONE_HAND, 4, false, $quartermasterProp);
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST, adding: $quartermaster);
assertTrue($calc['elite_gold_bonus'] === 1, 'Marauder resource_modifier should still apply with cost_modifier candidate');
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $quartermaster) === 0, 'Marauder interaction should not prevent Quartermaster free cost');

// Teech: +2 elite gold, and while Teech is in squad the resulting squad may not exceed three elemental cards besides Teech.
$state = dealState(['gold' => 20, 'silver' => 20]);
addDealCard($state, GameState::PLAYER_HOST, 'elemental_a', CardInstance::ZONE_SQUAD, 3, false, [], 'plains');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_b', CardInstance::ZONE_SQUAD, 3, false, [], 'forests');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_c', CardInstance::ZONE_SQUAD, 3, false, [], 'mountains');
addDealCard($state, GameState::PLAYER_HOST, 's1_199', CardInstance::ZONE_HAND, 7, true, $teechProp, 'swamps');
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_199']));
assertTrue($result->success, $result->error ?? 'Teech recruit should succeed when the resulting squad has Teech plus three elemental cards');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['elite_gold_bonus'] === 2, 'Teech should grant two elite gold with three elemental cards besides Teech');
assertTrue($calc['gold_total'] === 22, 'Teech should increase elite gold total by two');

$state = dealState(['gold' => 20, 'silver' => 20]);
addDealCard($state, GameState::PLAYER_HOST, 's1_199', CardInstance::ZONE_SQUAD, 7, true, $teechProp, 'swamps');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_a', CardInstance::ZONE_SQUAD, 3, false, [], 'plains');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_b', CardInstance::ZONE_SQUAD, 3, false, [], 'forests');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_c', CardInstance::ZONE_SQUAD, 3, false, [], 'mountains');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_d', CardInstance::ZONE_HAND, 3, false, [], 'forests');
$beforeSquad = zoneCount($state, GameState::PLAYER_HOST, CardInstance::ZONE_SQUAD);
$beforeHand = zoneCount($state, GameState::PLAYER_HOST, CardInstance::ZONE_HAND);
$beforeCalc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'elemental_d']));
assertTrue(!$result->success, 'Teech should reject recruiting a fourth elemental card');
assertTrue(zoneCount($state, GameState::PLAYER_HOST, CardInstance::ZONE_SQUAD) === $beforeSquad, 'Failed fourth elemental recruit should leave squad unchanged');
assertTrue(zoneCount($state, GameState::PLAYER_HOST, CardInstance::ZONE_HAND) === $beforeHand, 'Failed fourth elemental recruit should leave candidate in hand');
$afterCalc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($afterCalc['gold_left'] === $beforeCalc['gold_left'] && $afterCalc['silver_left'] === $beforeCalc['silver_left'], 'Failed fourth elemental recruit should leave resources unchanged');
assertTrue($afterCalc['elite_gold_bonus'] === 2, 'Failed fourth elemental recruit should not disable Teech bonus');

$state = dealState(['gold' => 20, 'silver' => 20]);
addDealCard($state, GameState::PLAYER_HOST, 'elemental_a', CardInstance::ZONE_SQUAD, 3, false, [], 'plains');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_b', CardInstance::ZONE_SQUAD, 3, false, [], 'forests');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_c', CardInstance::ZONE_SQUAD, 3, false, [], 'mountains');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_d', CardInstance::ZONE_SQUAD, 3, false, [], 'forests');
addDealCard($state, GameState::PLAYER_HOST, 's1_199', CardInstance::ZONE_HAND, 7, true, $teechProp, 'swamps');
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_199']));
assertTrue(!$result->success, 'Recruiting Teech should be rejected when resulting squad already has too many elemental cards');

$state = dealState(['gold' => 20, 'silver' => 20]);
addDealCard($state, GameState::PLAYER_HOST, 's1_199', CardInstance::ZONE_SQUAD, 7, true, $teechProp, 'swamps');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_a', CardInstance::ZONE_SQUAD, 3, false, [], 'plains');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_b', CardInstance::ZONE_SQUAD, 3, false, [], 'forests');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_c', CardInstance::ZONE_SQUAD, 3, false, [], 'mountains');
addDealCard($state, GameState::PLAYER_HOST, 'neutral_a', CardInstance::ZONE_HAND, 3, false, [], 'neutral');
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'neutral_a']));
assertTrue($result->success, $result->error ?? 'Teech should allow recruiting a neutral card at three elemental cards');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['elite_gold_bonus'] === 2, 'Neutral recruit should not remove Teech bonus');

$state = dealState(['gold' => 20, 'silver' => 20]);
addDealCard($state, GameState::PLAYER_HOST, 's1_199', CardInstance::ZONE_SQUAD, 7, true, $teechProp, 'swamps');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_a', CardInstance::ZONE_SQUAD, 3, false, [], 'plains');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_b', CardInstance::ZONE_SQUAD, 3, false, [], 'forests');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_c', CardInstance::ZONE_SQUAD, 3, false, [], 'mountains');
$processor = new PrepareProcessor($state);
$result = $processor->unpickCard(GameState::PLAYER_HOST, new Command('unpick_card', ['ukid' => 's1_199']));
assertTrue($result->success, $result->error ?? 'Returning Teech should succeed');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['elite_gold_bonus'] === 0, 'Returning Teech should remove his elite gold bonus');

$state = dealState(['gold' => 20, 'silver' => 20]);
addDealCard($state, GameState::PLAYER_HOST, 's1_199', CardInstance::ZONE_SQUAD, 7, true, $teechProp, 'swamps');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_a', CardInstance::ZONE_SQUAD, 3, false, [], 'plains');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_b', CardInstance::ZONE_SQUAD, 3, false, [], 'forests');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_c', CardInstance::ZONE_SQUAD, 3, false, [], 'mountains');
$processor = new PrepareProcessor($state);
$result = $processor->unpickCard(GameState::PLAYER_HOST, new Command('unpick_card', ['ukid' => 'elemental_b']));
assertTrue($result->success, $result->error ?? 'Returning an elemental card should not be blocked by Teech constraint');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['elite_gold_bonus'] === 2, 'Returning an elemental card should keep Teech bonus while Teech remains in squad');

$state = dealState(['gold' => 20, 'silver' => 20]);
addDealCard($state, GameState::PLAYER_HOST, 's1_199', CardInstance::ZONE_SQUAD, 7, true, $teechProp, 'swamps');
addDealCard($state, GameState::PLAYER_HOST, 's1_171', CardInstance::ZONE_SQUAD, 3, false, $marauderProp, 'neutral');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['elite_gold_bonus'] === 3, 'Marauder and Teech resource modifiers should stack');

$state = dealState(['gold' => 20, 'silver' => 20]);
$strictTeechProp = ['deal' => ['resource_modifier' => ['elite_gold' => 2], 'squad_constraint' => ['max_elemental_cards' => 2]]];
addDealCard($state, GameState::PLAYER_HOST, 's1_199', CardInstance::ZONE_SQUAD, 7, true, $strictTeechProp, 'swamps');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_a', CardInstance::ZONE_SQUAD, 3, false, [], 'plains');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_b', CardInstance::ZONE_SQUAD, 3, false, [], 'forests');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_b', CardInstance::ZONE_HAND, 3, false, [], 'forests');
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'elemental_b']));
assertTrue(!$result->success, 'Teech elemental limit should be read from prop value');

$state = dealState(['gold' => 20, 'silver' => 20]);
$warrior = addDealCard($state, GameState::PLAYER_HOST, 's1_190', CardInstance::ZONE_HAND, 2, true, $freeWarriorProp);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_190']));
assertTrue($result->success, $result->error ?? 'Free Warrior recruit should open pending choice');
assertTrue(isset($state->battle['pending_deal_variable_recruit']), 'Free Warrior should store pending choice');
assertTrue($warrior->zone === CardInstance::ZONE_HAND, 'Free Warrior should remain in hand until X is confirmed');
$result = $processor->chooseDealVariableRecruit(GameState::PLAYER_HOST, new Command('choose_deal_variable_recruit', ['x' => 3]));
assertTrue($result->success, $result->error ?? 'Free Warrior X choice should recruit card');
assertTrue($warrior->zone === CardInstance::ZONE_SQUAD, 'Free Warrior should move to squad after X confirmation');
assertTrue(($warrior->flags['deal_variable_recruit']['x'] ?? null) === 3, 'Free Warrior should store chosen X on the instance');
assertTrue(($warrior->flags['deal_variable_recruit']['extra_cost'] ?? null) === 3, 'Free Warrior should store extra recruit cost');
assertTrue($warrior->hpMax === 7 && $warrior->hp === 7, 'Free Warrior should gain +2 HP on the instance');
assertTrue(!empty(array_filter($warrior->modifiers, fn($m) => ($m['source'] ?? null) === 'deal_variable_recruit' && ($m['stat'] ?? null) === 'ability_strike' && (int) ($m['value'] ?? 0) === 3)), 'Free Warrior should gain a permanent strike modifier');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['gold_left'] === 18, 'Free Warrior should spend only base elite cost from gold');
assertTrue($calc['silver_left'] === 17, 'Free Warrior should spend chosen X from silver');

$result = $processor->unpickCard(GameState::PLAYER_HOST, new Command('unpick_card', ['ukid' => 's1_190']));
assertTrue($result->success, $result->error ?? 'Free Warrior return should succeed');
assertTrue($warrior->zone === CardInstance::ZONE_HAND, 'Returned Free Warrior should go back to hand');
assertTrue(empty($warrior->flags['deal_variable_recruit']), 'Returned Free Warrior should forget chosen X');
assertTrue($warrior->hpMax === 5 && $warrior->hp === 5, 'Returned Free Warrior should lose Deal HP bonus');
assertTrue(empty(array_filter($warrior->modifiers, fn($m) => ($m['source'] ?? null) === 'deal_variable_recruit')), 'Returned Free Warrior should lose Deal strike modifier');

$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_190']));
assertTrue($result->success, 'Free Warrior should open pending choice again after return');
$result = $processor->chooseDealVariableRecruit(GameState::PLAYER_HOST, new Command('choose_deal_variable_recruit', ['x' => 1]));
assertTrue($result->success, $result->error ?? 'Free Warrior should be recruitable again with a new X');
assertTrue(($warrior->flags['deal_variable_recruit']['x'] ?? null) === 1, 'Free Warrior should store the new X after re-recruit');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['gold_left'] === 18 && $calc['silver_left'] === 19, 'Free Warrior re-recruit should spend only the new silver X');

$state = dealState(['gold' => 20, 'silver' => 20]);
$warrior = addDealCard($state, GameState::PLAYER_HOST, 's1_190', CardInstance::ZONE_HAND, 2, true, $freeWarriorProp);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_190']));
assertTrue($result->success, 'Free Warrior should open pending before cancellation');
$result = $processor->cancelDealVariableRecruit(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Free Warrior pending should be cancellable');
assertTrue($warrior->zone === CardInstance::ZONE_HAND && empty($warrior->flags['deal_variable_recruit']), 'Cancelled Free Warrior should remain unchanged in hand');

$state = dealState(['gold' => 20, 'silver' => 3]);
addDealCard($state, GameState::PLAYER_HOST, 's1_190', CardInstance::ZONE_HAND, 2, true, $freeWarriorProp);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_190']));
assertTrue($result->success, 'Free Warrior should open pending before affordability check');
$beforeSquad = zoneCount($state, GameState::PLAYER_HOST, CardInstance::ZONE_SQUAD);
$beforeHand = zoneCount($state, GameState::PLAYER_HOST, CardInstance::ZONE_HAND);
$result = $processor->chooseDealVariableRecruit(GameState::PLAYER_HOST, new Command('choose_deal_variable_recruit', ['x' => 4]));
assertTrue(!$result->success, 'Free Warrior X should be rejected if extra silver is unaffordable');
assertTrue(zoneCount($state, GameState::PLAYER_HOST, CardInstance::ZONE_SQUAD) === $beforeSquad, 'Failed Free Warrior recruit should leave squad unchanged');
assertTrue(zoneCount($state, GameState::PLAYER_HOST, CardInstance::ZONE_HAND) === $beforeHand, 'Failed Free Warrior recruit should leave card in hand');
assertTrue(empty(findCardByUkid($state, 's1_190')->flags['deal_variable_recruit']), 'Failed Free Warrior recruit should not persist choice buff');

$state = dealState(['gold' => 100, 'silver' => 3]);
addDealCard($state, GameState::PLAYER_HOST, 's1_190', CardInstance::ZONE_HAND, 2, true, $freeWarriorProp);
$processor = new PrepareProcessor($state);
$processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_190']));
$result = $processor->chooseDealVariableRecruit(GameState::PLAYER_HOST, new Command('choose_deal_variable_recruit', ['x' => 4]));
assertTrue(!$result->success, 'Large elite gold should not compensate missing silver extra cost');

$state = dealState(['gold' => 20, 'silver' => 20]);
$warrior = addDealCard($state, GameState::PLAYER_HOST, 's1_190', CardInstance::ZONE_HAND, 2, true, $freeWarriorProp);
$processor = new PrepareProcessor($state);
$processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_190']));
$result = $processor->chooseDealVariableRecruit(GameState::PLAYER_HOST, new Command('choose_deal_variable_recruit', ['x' => 0]));
assertTrue($result->success, $result->error ?? 'Free Warrior X=0 should be valid');
assertTrue($warrior->hpMax === 7 && $warrior->hp === 7, 'Free Warrior X=0 should still grant +2 HP');
assertTrue(empty(array_filter($warrior->modifiers, fn($m) => ($m['source'] ?? null) === 'deal_variable_recruit')), 'Free Warrior X=0 should not add a zero attack modifier');
assertTrue(ResourceCalculator::compute($state, GameState::PLAYER_HOST)['gold_left'] === 18, 'Free Warrior X=0 should spend only effective base cost');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['silver_left'] === 20 && $calc['silver_extra_spent'] === 0, 'Free Warrior X=0 should not spend silver extra cost');

$state = dealState(['gold' => 20, 'silver' => 20]);
$warrior = addDealCard($state, GameState::PLAYER_HOST, 's1_190', CardInstance::ZONE_HAND, 2, true, $freeWarriorProp);
$processor = new PrepareProcessor($state);
$processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_190']));
$result = $processor->chooseDealVariableRecruit(GameState::PLAYER_HOST, new Command('choose_deal_variable_recruit', ['x' => 4]));
assertTrue($result->success, $result->error ?? 'Free Warrior X=4 should be valid');
assertTrue(($warrior->flags['deal_variable_recruit']['x'] ?? null) === 4, 'Free Warrior X=4 should be stored');
assertTrue(!empty(array_filter($warrior->modifiers, fn($m) => ($m['source'] ?? null) === 'deal_variable_recruit' && (int) ($m['value'] ?? 0) === 4)), 'Free Warrior X=4 should grant +4 attack modifier');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['gold_left'] === 18, 'Free Warrior X=4 should spend only base cost from gold');
assertTrue($calc['silver_left'] === 16 && $calc['silver_extra_spent'] === 4, 'Free Warrior X=4 should spend four silver extra cost');

$restored = GameState::fromArray($state->toArray());
$restoredWarrior = findCardByUkid($restored, 's1_190');
assertTrue(($restoredWarrior->flags['deal_variable_recruit']['x'] ?? null) === 4, 'Free Warrior choice should survive GameState serialization');
assertTrue($restoredWarrior->hpMax === 7 && $restoredWarrior->hp === 7, 'Free Warrior HP buff should survive GameState serialization');
assertTrue(!empty(array_filter($restoredWarrior->modifiers, fn($m) => ($m['source'] ?? null) === 'deal_variable_recruit' && (int) ($m['value'] ?? 0) === 4)), 'Free Warrior attack buff should survive GameState serialization');

$state = dealState(['gold' => 100, 'silver' => 40]);
addSquadPrices($state, [3, 4, 5, 6, 7, 8]);
$freeQuartermasterProp = ['deal' => ['cost_modifier' => ['free_if_squad_has_costs' => [3, 4, 5, 6, 7, 8]], 'recruit_choice' => ['extra_cost' => ['min' => 1, 'max' => 2, 'resource' => 'silver'], 'instance_buff' => ['attack_per_x' => 1, 'health' => 2]]]];
$choiceCard = addDealCard($state, GameState::PLAYER_HOST, 'choice_quartermaster', CardInstance::ZONE_HAND, 4, true, $freeQuartermasterProp);
$baselineCalc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'choice_quartermaster']));
assertTrue($result->success, 'Choice card should open pending when its base effective cost is free');
$result = $processor->chooseDealVariableRecruit(GameState::PLAYER_HOST, new Command('choose_deal_variable_recruit', ['x' => 0]));
assertTrue(!$result->success, 'Choice min value should be read from prop');
$result = $processor->chooseDealVariableRecruit(GameState::PLAYER_HOST, new Command('choose_deal_variable_recruit', ['x' => 2]));
assertTrue($result->success, $result->error ?? 'Choice max value should be read from prop');
assertTrue(ResourceCalculator::compute($state, GameState::PLAYER_HOST)['silver_left'] === $baselineCalc['silver_left'] - 2, 'Choice extra silver cost should be added after effective base cost modifiers');
assertTrue(ResourceCalculator::effectiveRecruitCost($state, GameState::PLAYER_HOST, $choiceCard) === 2, 'Choice effective cost should be base modifier result plus X');
assertTrue($choiceCard->price === 4, 'Choice extra cost must not mutate CardInstance price');

$state = dealState(['gold' => 20, 'silver' => 20]);
addDealCard($state, GameState::PLAYER_HOST, 's1_199', CardInstance::ZONE_SQUAD, 7, true, $teechProp, 'swamps');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_a', CardInstance::ZONE_SQUAD, 3, false, [], 'plains');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_b', CardInstance::ZONE_SQUAD, 3, false, [], 'forests');
addDealCard($state, GameState::PLAYER_HOST, 'elemental_c', CardInstance::ZONE_SQUAD, 3, false, [], 'mountains');
$warrior = addDealCard($state, GameState::PLAYER_HOST, 's1_190', CardInstance::ZONE_HAND, 2, true, $freeWarriorProp, 'forests');
$processor = new PrepareProcessor($state);
$processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_190']));
$result = $processor->chooseDealVariableRecruit(GameState::PLAYER_HOST, new Command('choose_deal_variable_recruit', ['x' => 2]));
assertTrue(!$result->success, 'Free Warrior choice should not bypass squad constraints');
assertTrue($warrior->zone === CardInstance::ZONE_HAND && empty($warrior->flags['deal_variable_recruit']), 'Constraint rejection should leave Free Warrior unchanged in hand');

$state = dealState(['gold' => 20, 'silver' => 20]);
$warrior = addDealCard($state, GameState::PLAYER_HOST, 's1_190', CardInstance::ZONE_HAND, 2, true, $freeWarriorProp);
$target = addDealCard($state, GameState::PLAYER_PLAYER, 'target', CardInstance::ZONE_FIELD, 1, false);
$processor = new PrepareProcessor($state);
$processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_190']));
$processor->chooseDealVariableRecruit(GameState::PLAYER_HOST, new Command('choose_deal_variable_recruit', ['x' => 4]));
$processor->confirmDeal(GameState::PLAYER_HOST);
$processor->confirmDeal(GameState::PLAYER_PLAYER);
assertTrue($state->status === 'place', 'Deal confirmations should move to place');
$result = $processor->placeCard(GameState::PLAYER_HOST, new Command('place_card', ['card_id' => $warrior->instanceId, 'row' => 1, 'col' => 2]));
assertTrue($result->success, $result->error ?? 'Free Warrior should be placeable after Deal');
$result = $processor->confirmPlace(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Host placement confirmation should succeed');
$result = $processor->confirmPlace(GameState::PLAYER_PLAYER);
assertTrue($result->success, $result->error ?? 'Player placement confirmation should succeed');
assertTrue($state->status === 'battle', 'Place confirmations should move to battle');
assertTrue($warrior->hpMax === 7 && $warrior->hp === 7, 'Free Warrior HP buff should survive Deal to Battle transition');
assertTrue(CardStats::getAbilityBonus($state, $warrior, $target, 'strike') === 4, 'Free Warrior attack buff should affect battle ability bonus');

echo "Deal resource tests passed.\n";

// Deal UI contract smoke: card action is in preview, status is in bottom panel.
$_GET['card'] = 'hand:s1_171';
$state = dealState();
addDealCard($state, GameState::PLAYER_HOST, 's1_171', CardInstance::ZONE_HAND, 3, false, $marauderProp);
$tpl = new Template(__DIR__ . '/../templates/');
$elementLabels = ['plains' => 'Степи', 'forests' => 'Леса'];
$screen = (new DealScreen($tpl))->prepare($state, GameState::PLAYER_HOST, 'host', null, [
    's1_171' => [
        'name' => 'Мародер',
        'price' => 3,
        'health' => 5,
        'move' => 1,
        'elite' => false,
        'element' => 'Степи',
        'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 2],
    ],
], $elementLabels);

assertTrue(str_contains($screen['data']['preview_html'], 'В отряд'), 'Recruit action should be rendered in card preview');
assertTrue(!str_contains($screen['data']['preview_html'], 'prepare-card-preview__actions'), 'Preview action should render inside card info');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Выбрано:'), 'Bottom panel should render selected count');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Золото:'), 'Bottom panel should render gold resources');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Серебро:'), 'Bottom panel should render silver resources');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Степи') === false, 'Hand-only element should not appear in squad badges');
assertTrue(!str_contains($screen['data']['bottom_panel_html'], 'В отряд'), 'Recruit action should not be rendered in bottom panel');
unset($_GET['card']);

$_GET['card'] = 'squad:s1_171';
$state = dealState();
addDealCard($state, GameState::PLAYER_HOST, 's1_171', CardInstance::ZONE_SQUAD, 3, false, $marauderProp, 'plains');
$screen = (new DealScreen($tpl))->prepare($state, GameState::PLAYER_HOST, 'host', null, [
    's1_171' => [
        'name' => 'Мародер',
        'price' => 3,
        'health' => 5,
        'move' => 1,
        'elite' => false,
        'element' => 'Степи',
        'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 2],
    ],
], $elementLabels);
assertTrue(str_contains($screen['data']['preview_html'], 'Вернуть'), 'Return action should be rendered in card preview');
assertTrue(!str_contains($screen['data']['preview_html'], 'prepare-card-preview__actions'), 'Return action should render inside card info');
unset($_GET['card']);

$state = dealState();
addDealCard($state, GameState::PLAYER_HOST, 'plains_unit', CardInstance::ZONE_SQUAD, 1, false, [], 'plains');
addDealCard($state, GameState::PLAYER_HOST, 'forest_unit', CardInstance::ZONE_SQUAD, 1, false, [], 'forests');
$screen = (new DealScreen($tpl))->prepare($state, GameState::PLAYER_HOST, 'host', null, [
    'plains_unit' => [
        'name' => 'Степной боец',
        'price' => 1,
        'health' => 5,
        'move' => 1,
        'elite' => false,
        'element' => 'Степи',
        'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 1],
    ],
    'forest_unit' => [
        'name' => 'Лесной боец',
        'price' => 1,
        'health' => 5,
        'move' => 1,
        'elite' => false,
        'element' => 'Леса',
        'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 1],
    ],
], $elementLabels);
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Степи'), 'Deal squad badges should render human-readable plains label');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Леса'), 'Deal squad badges should render human-readable forest label');
assertTrue(!str_contains($screen['data']['bottom_panel_html'], 'plains'), 'Deal squad badges should not expose internal plains code');
assertTrue(!str_contains($screen['data']['bottom_panel_html'], 'forests'), 'Deal squad badges should not expose internal forest code');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'penalty active'), 'Element penalty should use existing active penalty style');

$state = dealState();
addSquadPrices($state, [3, 4, 5, 6, 7, 8]);
addDealCard($state, GameState::PLAYER_HOST, 'quartermaster', CardInstance::ZONE_HAND, 4, false, $quartermasterProp);
$_GET['card'] = 'hand:quartermaster';
$screen = (new DealScreen($tpl))->prepare($state, GameState::PLAYER_HOST, 'host', null, [
    'quartermaster' => [
        'name' => 'Ведающий запасами',
        'price' => 4,
        'health' => 5,
        'move' => 1,
        'elite' => false,
        'element' => 'Степи',
        'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 1],
    ],
], $elementLabels);
assertTrue(str_contains($screen['data']['preview_html'], 'Цена: 0 (обычно 4)'), 'Deal preview should render effective free recruit cost');
unset($_GET['card']);

$state = dealState(['gold' => 20, 'silver' => 20]);
addDealCard($state, GameState::PLAYER_HOST, 's1_190', CardInstance::ZONE_HAND, 2, true, $freeWarriorProp);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 's1_190']));
assertTrue($result->success, 'Free Warrior UI smoke should open pending choice');
$screen = (new DealScreen($tpl))->prepare($state, GameState::PLAYER_HOST, 'host', null, [
    's1_190' => [
        'name' => 'Вольный воитель',
        'price' => 2,
        'health' => 3,
        'move' => 1,
        'elite' => true,
        'element' => 'Степи',
        'strike' => ['weak' => 0, 'medium' => 1, 'strong' => 2],
    ],
], $elementLabels);
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Вольный воитель'), 'Free Warrior pending panel should replace default Deal bottom panel');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'choose_deal_variable_recruit'), 'Free Warrior pending panel should submit the Deal choice command');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'X = 4'), 'Free Warrior pending panel should render X radio choices');
assertTrue(str_contains($screen['data']['bottom_panel_html'], '+4 серебра'), 'Free Warrior pending panel should describe silver extra cost');

echo "Deal UI smoke tests passed.\n";
