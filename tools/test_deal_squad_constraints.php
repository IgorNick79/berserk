<?php
// tools/test_deal_squad_constraints.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\PrepareProcessor;
use Berserk\Core\Prepare\SquadRules;
use Berserk\View\Screen\BattleScreen;
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

function squadConstraintState(): GameState
{
    $state = new GameState(random_int(1000, 9999), 1, 2);
    $state->status = 'deal';
    $state->getPlayer(GameState::PLAYER_HOST)->resources = ['gold' => 50, 'silver' => 50];
    $state->getPlayer(GameState::PLAYER_PLAYER)->resources = ['gold' => 50, 'silver' => 50];
    return $state;
}

function addSquadConstraintCard(
    GameState $state,
    string $owner,
    string $ukid,
    string $zone,
    int $price,
    string $type = 'creature',
    bool $single = false,
    string $element = 'neutral',
): CardInstance {
    $card = new CardInstance(
        instanceId: $state->nextInstanceId(),
        ukid: $ukid,
        owner: $owner,
        zone: $zone,
        hp: 5,
        hpMax: 5,
        price: $price,
        elite: false,
        type: $type,
        element: $element,
        move: $type === 'fly' ? 0 : 1,
        moveMax: $type === 'fly' ? 0 : 1,
        strikeWeak: 1,
        strikeMedium: 1,
        strikeStrong: 1,
        single: $single,
    );
    $state->addCard($card);
    return $card;
}

function cardInfoFor(array $cards): array
{
    $info = [];
    foreach ($cards as $card) {
        $info[$card->ukid] = [
            'name' => $card->ukid,
            'price' => $card->price,
            'health' => $card->hpMax,
            'move' => $card->moveMax,
            'elite' => $card->elite,
            'element' => $card->element,
            'strike' => ['weak' => $card->strikeWeak, 'medium' => $card->strikeMedium, 'strong' => $card->strikeStrong],
        ];
    }
    return $info;
}

function cardsInZone(GameState $state, string $owner, string $zone): array
{
    return $state->getCardsInZone($owner, $zone);
}

$limit = SquadRules::FLYING_COST_LIMIT;
$cheapFlyCost = intdiv($limit, 5);
$state = squadConstraintState();
$processor = new PrepareProcessor($state);

$flyers = [];
for ($i = 1; $i <= 5; $i++) {
    $flyers[] = addSquadConstraintCard($state, GameState::PLAYER_HOST, 'fly_' . $i, CardInstance::ZONE_HAND, $cheapFlyCost, 'fly');
}

for ($i = 0; $i < 3; $i++) {
    $result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => $flyers[$i]->ukid]));
    assertTrue($result->success, 'Three flying cards below limit should be recruitable');
}
assertTrue(SquadRules::flyingCost($state, GameState::PLAYER_HOST) < $limit, 'Three cheap flyers should stay below flying cost limit');

$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => $flyers[3]->ukid]));
assertTrue($result->success, 'Fourth flying card below limit should be recruitable');

$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => $flyers[4]->ukid]));
assertTrue($result->success, 'Fifth flying card exactly at limit should be recruitable');
assertTrue(SquadRules::flyingCost($state, GameState::PLAYER_HOST) === $limit, 'Five cheap flyers should reach flying cost limit exactly');

$tooMuchFly = addSquadConstraintCard($state, GameState::PLAYER_HOST, 'fly_over', CardInstance::ZONE_HAND, 1, 'fly');
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => $tooMuchFly->ukid]));
assertTrue(!$result->success, 'Flying candidate above limit should be rejected server-side');
assertTrue($tooMuchFly->zone === CardInstance::ZONE_HAND, 'Rejected flying candidate should remain in hand');

$ordinary = addSquadConstraintCard($state, GameState::PLAYER_HOST, 'ordinary_at_limit', CardInstance::ZONE_HAND, 1);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => $ordinary->ukid]));
assertTrue($result->success, 'Non-flying candidate should be allowed while flying total is at limit');

$result = $processor->unpickCard(GameState::PLAYER_HOST, new Command('unpick_card', ['ukid' => $flyers[0]->ukid]));
assertTrue($result->success, 'Returning a flying card should be allowed');
assertTrue(SquadRules::flyingCost($state, GameState::PLAYER_HOST) === $limit - $cheapFlyCost, 'Returning flyer should reduce flying cost');
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => $tooMuchFly->ukid]));
assertTrue($result->success, 'Previously rejected flyer should be allowed after flying cost is reduced');

$uniqueState = squadConstraintState();
$uniqueProcessor = new PrepareProcessor($uniqueState);
$uniqueA1 = addSquadConstraintCard($uniqueState, GameState::PLAYER_HOST, 'unique_a', CardInstance::ZONE_HAND, 2, single: true);
$uniqueA2 = addSquadConstraintCard($uniqueState, GameState::PLAYER_HOST, 'unique_a', CardInstance::ZONE_HAND, 2, single: true);
$uniqueB = addSquadConstraintCard($uniqueState, GameState::PLAYER_HOST, 'unique_b', CardInstance::ZONE_HAND, 2, single: true);
$nonUnique1 = addSquadConstraintCard($uniqueState, GameState::PLAYER_HOST, 'common_dup', CardInstance::ZONE_HAND, 2);
$nonUnique2 = addSquadConstraintCard($uniqueState, GameState::PLAYER_HOST, 'common_dup', CardInstance::ZONE_HAND, 2);

$result = $uniqueProcessor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => $uniqueA1->ukid]));
assertTrue($result->success, 'First unique card instance should be recruitable');
$result = $uniqueProcessor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => $uniqueA2->ukid]));
assertTrue(!$result->success, 'Second instance of same unique card should be rejected server-side');
$result = $uniqueProcessor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => $uniqueB->ukid]));
assertTrue($result->success, 'Different unique card should be recruitable');
$result = $uniqueProcessor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => $nonUnique1->ukid]));
assertTrue($result->success, 'First non-unique duplicate should be recruitable');
$result = $uniqueProcessor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => $nonUnique2->ukid]));
assertTrue($result->success, 'Second non-unique duplicate should remain recruitable');
$result = $uniqueProcessor->unpickCard(GameState::PLAYER_HOST, new Command('unpick_card', ['ukid' => $uniqueA1->ukid]));
assertTrue($result->success, 'Returning unique card should remove duplicate lock');
$result = $uniqueProcessor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => $uniqueA2->ukid]));
assertTrue($result->success, 'Another instance of returned unique card should become recruitable');

$uiState = squadConstraintState();
$uiProcessor = new PrepareProcessor($uiState);
$uiFlyers = [];
for ($i = 1; $i <= 5; $i++) {
    $uiFlyers[] = addSquadConstraintCard($uiState, GameState::PLAYER_HOST, 'ui_fly_' . $i, CardInstance::ZONE_HAND, $cheapFlyCost, 'fly');
    $result = $uiProcessor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'ui_fly_' . $i]));
    assertTrue($result->success, 'UI setup should recruit flyers up to the limit');
}
$uiOverFly = addSquadConstraintCard($uiState, GameState::PLAYER_HOST, 'ui_fly_over', CardInstance::ZONE_HAND, 1, 'fly');

$_GET['card'] = 'hand:ui_fly_over';
$tpl = new Template(__DIR__ . '/../templates/');
$screen = (new DealScreen($tpl))->prepare(
    $uiState,
    GameState::PLAYER_HOST,
    'host',
    null,
    cardInfoFor(array_merge($uiFlyers, [$uiOverFly])),
);
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Летающие:'), 'Deal bottom panel should show flying budget');
assertTrue(str_contains($screen['data']['preview_html'], 'Нельзя взять в отряд'), 'Deal preview should explain impossible flying recruit');
assertTrue(str_contains($screen['data']['preview_html'], (string) SquadRules::FLYING_COST_LIMIT), 'Deal preview should use canonical flying limit');
assertTrue(str_contains($screen['data']['preview_html'], 'Стоимость летающих'), 'Deal preview should name flying cost rule');
assertTrue(str_contains($screen['data']['preview_html'], 'disabled'), 'Impossible recruit action should be disabled in preview');
unset($_GET['card']);

$battleState = squadConstraintState();
$battleState->status = 'place';
$battleState->getPlayer(GameState::PLAYER_HOST)->side = 1;
$battleState->getPlayer(GameState::PLAYER_PLAYER)->side = 2;
$battleFlyers = [];
for ($i = 1; $i <= 5; $i++) {
    $battleFlyers[] = addSquadConstraintCard($battleState, GameState::PLAYER_HOST, 'battle_fly_' . $i, CardInstance::ZONE_SQUAD, $cheapFlyCost, 'fly');
}
$battleIds = array_map(fn(CardInstance $card) => $card->instanceId, $battleFlyers);
$battleProcessor = new PrepareProcessor($battleState);
$cells = [[1, 2], [1, 3], [1, 4], [2, 2], [2, 3]];
foreach ($battleFlyers as $i => $card) {
    [$row, $col] = $cells[$i];
    $result = $battleProcessor->placeCard(GameState::PLAYER_HOST, new Command('place_card', ['card_id' => $card->instanceId, 'row' => $row, 'col' => $col]));
    assertTrue($result->success, 'Place should accept every recruited flying card before Battle');
}

$engine = new Engine();
$result = $engine->apply($battleState, GameState::PLAYER_HOST, new Command('confirm_place'));
assertTrue($result->success, 'Host should confirm placement with five flyers placed');
$result = $engine->apply($battleState, GameState::PLAYER_PLAYER, new Command('confirm_place'));
assertTrue($result->success, 'Player should confirm empty placement');
assertTrue($battleState->status === 'battle', 'Placement confirmations should start Battle');

$flyingAfterBattle = cardsInZone($battleState, GameState::PLAYER_HOST, CardInstance::ZONE_FLYING);
assertTrue(count($flyingAfterBattle) === 5, 'Battle should receive all five flying cards');
$afterBattleIds = array_map(fn(CardInstance $card) => $card->instanceId, $flyingAfterBattle);
sort($battleIds);
sort($afterBattleIds);
assertTrue($afterBattleIds === $battleIds, 'Flying cards should preserve instance ids through Place to Battle');
assertTrue(array_map(fn(CardInstance $card) => $card->slot, $flyingAfterBattle) === [1, 2, 3, 4, 5], 'Flying zone should allocate dynamic slots');

$restored = GameState::fromArray($battleState->toArray());
assertTrue(count(cardsInZone($restored, GameState::PLAYER_HOST, CardInstance::ZONE_FLYING)) === 5, 'Serialization should preserve all flying cards');

$battleInfo = cardInfoFor($flyingAfterBattle);
$battleScreen = (new BattleScreen($tpl))->prepare($battleState, GameState::PLAYER_HOST, 'host', null, $battleInfo);
foreach ($battleFlyers as $card) {
    assertTrue(str_contains($battleScreen['data']['fly_zones_html'], $card->ukid), 'Battle flying UI should render every flying card');
}

echo "Deal squad constraint tests passed.\n";
