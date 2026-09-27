<?php
// tools/test_deal_resources.php

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

$marauderProp = ['deal' => ['resource_modifier' => ['elite_gold' => 1]]];
$quartermasterProp = ['deal' => ['cost_modifier' => ['free_if_squad_has_costs' => [3, 4, 5, 6, 7, 8]]]];
$teechProp = ['deal' => ['resource_modifier' => ['elite_gold' => 2], 'squad_constraint' => ['max_elemental_cards' => 3]]];

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

echo "Deal UI smoke tests passed.\n";
