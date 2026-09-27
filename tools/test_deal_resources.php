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
): CardInstance {
    $card = new CardInstance(
        instanceId: $state->nextInstanceId(),
        ukid: $ukid,
        owner: $owner,
        zone: $zone,
        hp: 5,
        hpMax: 5,
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

$marauderProp = ['deal' => ['on_recruit' => ['elite_gold' => 1]]];

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

echo "Deal resource tests passed.\n";

// Deal UI contract smoke: card action is in preview, status is in bottom panel.
$_GET['card'] = 'hand:s1_171';
$state = dealState();
addDealCard($state, GameState::PLAYER_HOST, 's1_171', CardInstance::ZONE_HAND, 3, false, $marauderProp);
$tpl = new Template(__DIR__ . '/../templates/');
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
]);

assertTrue(str_contains($screen['data']['preview_html'], 'В отряд'), 'Recruit action should be rendered in card preview');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Выбрано:'), 'Bottom panel should render selected count');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Золото:'), 'Bottom panel should render gold resources');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Серебро:'), 'Bottom panel should render silver resources');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Степи') === false, 'Hand-only element should not appear in squad badges');
assertTrue(!str_contains($screen['data']['bottom_panel_html'], 'В отряд'), 'Recruit action should not be rendered in bottom panel');
unset($_GET['card']);

echo "Deal UI smoke tests passed.\n";
