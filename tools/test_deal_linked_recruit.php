<?php
// tools/test_deal_linked_recruit.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\PrepareProcessor;
use Berserk\Core\ResourceCalculator;
use Berserk\Core\ZoneManager;
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

function linkedRecruitProp(int $maxEliteCost = 7): array
{
    return [
        'zom' => true,
        'damage_reduction' => [
            ['types' => ['shot', 'throw'], 'value' => 1],
        ],
        'actions' => [
            ['type' => 'discharge', 'name' => 'Разряд', 'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 2]],
        ],
        'deal' => [
            'linked_recruit' => [
                'max_elite_cost' => $maxEliteCost,
                'companion_payment_resource' => 'silver',
                'requires_empty_squad' => true,
            ],
        ],
    ];
}

function dealState(array $resources = ['gold' => 24, 'silver' => 22]): GameState
{
    $state = new GameState(1, 1, 2);
    $state->status = 'deal';
    $state->getPlayer(GameState::PLAYER_HOST)->resources = $resources;
    $state->getPlayer(GameState::PLAYER_PLAYER)->resources = $resources;
    return $state;
}

function addCardForLinkedTest(
    GameState $state,
    string $owner,
    string $ukid,
    string $zone,
    int $price,
    bool $elite,
    array $prop = [],
    string $type = 'creature',
    int $health = 5,
): CardInstance {
    $card = new CardInstance(
        instanceId: $state->nextInstanceId(),
        ukid: $ukid,
        owner: $owner,
        zone: $zone,
        hp: $health,
        hpMax: $health,
        price: $price,
        elite: $elite,
        type: $type,
        prop: $prop,
        move: 1,
        moveMax: 1,
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

function namesForLinkedTest(): array
{
    return [
        'linnet' => [
            'name' => 'Линнет',
            'price' => 2,
            'health' => 5,
            'move' => 1,
            'elite' => false,
            'element' => 'Нейтральная',
            'strike' => ['weak' => 1, 'medium' => 2, 'strong' => 3],
        ],
        'gobrah' => [
            'name' => 'Гобрах',
            'price' => 6,
            'health' => 6,
            'move' => 1,
            'elite' => true,
            'element' => 'Нейтральная',
            'strike' => ['weak' => 2, 'medium' => 3, 'strong' => 4],
        ],
    ];
}

// Pending shows only valid same-hand elite creature candidates by prop max cost.
$state = dealState();
$linnet = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'linnet', CardInstance::ZONE_HAND, 2, false, linkedRecruitProp());
$gobrah = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'gobrah', CardInstance::ZONE_HAND, 6, true);
$cost7 = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'cost7', CardInstance::ZONE_HAND, 7, true);
$cost8 = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'cost8', CardInstance::ZONE_HAND, 8, true);
$silver = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'silver', CardInstance::ZONE_HAND, 6, false);
$spell = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'spell', CardInstance::ZONE_HAND, 6, true, [], 'instant');
$other = addCardForLinkedTest($state, GameState::PLAYER_PLAYER, 'other', CardInstance::ZONE_HAND, 6, true);
$alreadySquad = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'already_squad', CardInstance::ZONE_SQUAD, 6, true);
(new ZoneManager($state))->toHand($alreadySquad);
(new ZoneManager($state))->toSquad($alreadySquad);
$processor = new PrepareProcessor($state);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'linnet']));
assertTrue(!$result->success, 'Linked recruit should be unavailable when squad is not empty');
(new ZoneManager($state))->toHand($alreadySquad);
$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'linnet']));
assertTrue($result->success, $result->error ?? 'Linked recruit should open pending at beginning');
$pendingIds = $state->battle['pending_deal_linked_recruit']['candidate_ids'] ?? [];
assertTrue(in_array($gobrah->instanceId, $pendingIds, true), 'Cost 6 elite creature should be a candidate');
assertTrue(in_array($cost7->instanceId, $pendingIds, true), 'Cost 7 elite creature should be a candidate');
assertTrue(!in_array($cost8->instanceId, $pendingIds, true), 'Cost 8 elite creature should not be a candidate');
assertTrue(!in_array($silver->instanceId, $pendingIds, true), 'Silver card should not be a candidate');
assertTrue(!in_array($spell->instanceId, $pendingIds, true), 'Non-creature should not be a candidate');
assertTrue(!in_array($other->instanceId, $pendingIds, true), 'Other player card should not be a candidate');
assertTrue(!in_array($linnet->instanceId, $pendingIds, true), 'Source should not be its own candidate');

$result = $processor->cancelDealLinkedRecruit(GameState::PLAYER_HOST);
assertTrue($result->success, $result->error ?? 'Linked recruit pending should be cancellable');
assertTrue($linnet->zone === CardInstance::ZONE_HAND && $gobrah->zone === CardInstance::ZONE_HAND, 'Cancel should leave both cards in hand');
assertTrue(empty($linnet->flags['deal_linked_recruit']) && empty($gobrah->flags['deal_linked_recruit']), 'Cancel should not create relationship');

// Confirm atomically recruits source and companion; companion pays N silver instead of N gold.
$state = dealState(['gold' => 0, 'silver' => 10]);
$linnet = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'linnet', CardInstance::ZONE_HAND, 2, false, linkedRecruitProp());
$gobrah = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'gobrah', CardInstance::ZONE_HAND, 6, true);
$processor = new PrepareProcessor($state);
$processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'linnet']));
$result = $processor->chooseDealLinkedRecruit(GameState::PLAYER_HOST, new Command('choose_deal_linked_recruit', ['companion_id' => $gobrah->instanceId]));
assertTrue($result->success, $result->error ?? 'Linked recruit should recruit both cards');
assertTrue($linnet->zone === CardInstance::ZONE_SQUAD && $gobrah->zone === CardInstance::ZONE_SQUAD, 'Linked recruit should move both cards to squad');
assertTrue(($linnet->flags['deal_linked_recruit']['role'] ?? null) === 'source', 'Source should store source role');
assertTrue(($gobrah->flags['deal_linked_recruit']['role'] ?? null) === 'companion', 'Companion should store companion role');
assertTrue((int) $linnet->flags['deal_linked_recruit']['linked_instance_id'] === $gobrah->instanceId, 'Source should link to exact companion instance');
assertTrue((int) $gobrah->flags['deal_linked_recruit']['linked_instance_id'] === $linnet->instanceId, 'Companion should link to exact source instance');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['gold_left'] === 0, 'Linked companion should not spend elite gold');
assertTrue($calc['silver_left'] === 2, 'Linked companion should spend its numeric cost as silver plus Linnet normal silver cost');
assertTrue($gobrah->price === 6 && $gobrah->elite, 'Linked recruit must not mutate companion static price or elite flag');

$restored = GameState::fromArray($state->toArray());
$restoredLinnet = $restored->getCard($linnet->instanceId);
$restoredGobrah = $restored->getCard($gobrah->instanceId);
assertTrue((int) $restoredLinnet->flags['deal_linked_recruit']['linked_instance_id'] === $restoredGobrah->instanceId, 'Relationship should survive serialization on source');
assertTrue((int) $restoredGobrah->flags['deal_linked_recruit']['linked_instance_id'] === $restoredLinnet->instanceId, 'Relationship should survive serialization on companion');

$result = $processor->unpickCard(GameState::PLAYER_HOST, new Command('unpick_card', ['ukid' => 'gobrah']));
assertTrue(!$result->success, 'Linked companion cannot be returned separately');
$result = $processor->unpickCard(GameState::PLAYER_HOST, new Command('unpick_card', ['ukid' => 'linnet']));
assertTrue($result->success, $result->error ?? 'Returning source should succeed');
assertTrue($linnet->zone === CardInstance::ZONE_HAND && $gobrah->zone === CardInstance::ZONE_HAND, 'Returning source should return both linked cards');
assertTrue(empty($linnet->flags['deal_linked_recruit']) && empty($gobrah->flags['deal_linked_recruit']), 'Returning source should clear both relationship flags');
$calc = ResourceCalculator::compute($state, GameState::PLAYER_HOST);
assertTrue($calc['gold_left'] === 0 && $calc['silver_left'] === 10, 'Returning source should restore resources by recalculation');

$result = $processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'linnet']));
assertTrue($result->success, 'Linked recruit should be available again after returning both cards');
$result = $processor->chooseDealLinkedRecruit(GameState::PLAYER_HOST, new Command('choose_deal_linked_recruit', ['companion_id' => $gobrah->instanceId]));
assertTrue($result->success, $result->error ?? 'Linked recruit should recreate a clean relationship after return');
assertTrue((int) $gobrah->flags['deal_linked_recruit']['converted_cost'] === 6, 'Recreated relationship should store converted numeric cost');

// Failed confirm should be atomic.
$state = dealState(['gold' => 0, 'silver' => 7]);
$linnet = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'linnet', CardInstance::ZONE_HAND, 2, false, linkedRecruitProp());
$gobrah = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'gobrah', CardInstance::ZONE_HAND, 6, true);
$processor = new PrepareProcessor($state);
$processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'linnet']));
$result = $processor->chooseDealLinkedRecruit(GameState::PLAYER_HOST, new Command('choose_deal_linked_recruit', ['companion_id' => $gobrah->instanceId]));
assertTrue(!$result->success, 'Linked recruit should fail if combined silver is insufficient');
assertTrue($linnet->zone === CardInstance::ZONE_HAND && $gobrah->zone === CardInstance::ZONE_HAND, 'Failed linked recruit should leave both cards in hand');
assertTrue(empty($linnet->flags['deal_linked_recruit']) && empty($gobrah->flags['deal_linked_recruit']), 'Failed linked recruit should not persist relationship');

$state = dealState(['gold' => 0, 'silver' => 20]);
$linnet = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'linnet', CardInstance::ZONE_HAND, 1, true, linkedRecruitProp());
$gobrah = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'gobrah', CardInstance::ZONE_HAND, 6, true);
$processor = new PrepareProcessor($state);
$processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'linnet']));
$result = $processor->chooseDealLinkedRecruit(GameState::PLAYER_HOST, new Command('choose_deal_linked_recruit', ['companion_id' => $gobrah->instanceId]));
assertTrue(!$result->success, 'Linked recruit should fail if source normal cost cannot be paid');
assertTrue($linnet->zone === CardInstance::ZONE_HAND && $gobrah->zone === CardInstance::ZONE_HAND, 'Source cost failure should be atomic');

// UI smoke: pending panel, Deal hints and companion return lock.
$state = dealState(['gold' => 0, 'silver' => 10]);
$linnet = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'linnet', CardInstance::ZONE_HAND, 2, false, linkedRecruitProp());
$gobrah = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'gobrah', CardInstance::ZONE_HAND, 6, true);
$processor = new PrepareProcessor($state);
$processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'linnet']));
$tpl = new Template(__DIR__ . '/../templates/');
$screen = (new DealScreen($tpl))->prepare($state, GameState::PLAYER_HOST, 'host', null, namesForLinkedTest());
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'choose_deal_linked_recruit'), 'Linked recruit pending should render its command');
assertTrue(str_contains($screen['data']['bottom_panel_html'], 'Гобрах'), 'Linked recruit pending should list valid companion by name');
$processor->chooseDealLinkedRecruit(GameState::PLAYER_HOST, new Command('choose_deal_linked_recruit', ['companion_id' => $gobrah->instanceId]));
$_GET['card'] = 'squad:gobrah';
$screen = (new DealScreen($tpl))->prepare($state, GameState::PLAYER_HOST, 'host', null, namesForLinkedTest());
assertTrue(!str_contains($screen['data']['preview_html'], 'Вернуть'), 'Linked companion preview should not offer return');
assertTrue(str_contains($screen['data']['preview_html'], 'Отдельно вернуть нельзя'), 'Linked companion preview should explain return lock');
unset($_GET['card']);

// Deal -> Place -> Battle should preserve the pair.
$state = dealState(['gold' => 0, 'silver' => 10]);
$linnet = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'linnet', CardInstance::ZONE_HAND, 2, false, linkedRecruitProp());
$gobrah = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'gobrah', CardInstance::ZONE_HAND, 6, true);
$processor = new PrepareProcessor($state);
$engine = new Engine();
$processor->pickCard(GameState::PLAYER_HOST, new Command('pick_card', ['ukid' => 'linnet']));
$processor->chooseDealLinkedRecruit(GameState::PLAYER_HOST, new Command('choose_deal_linked_recruit', ['companion_id' => $gobrah->instanceId]));
$processor->confirmDeal(GameState::PLAYER_HOST);
$processor->confirmDeal(GameState::PLAYER_PLAYER);
assertTrue($state->status === 'place', 'Deal confirmations should move to place');
$processor->placeCard(GameState::PLAYER_HOST, new Command('place_card', ['card_id' => $linnet->instanceId, 'row' => 1, 'col' => 2]));
$processor->placeCard(GameState::PLAYER_HOST, new Command('place_card', ['card_id' => $gobrah->instanceId, 'row' => 1, 'col' => 3]));
$engine->apply($state, GameState::PLAYER_HOST, new Command('confirm_place'));
$engine->apply($state, GameState::PLAYER_PLAYER, new Command('confirm_place'));
assertTrue($state->status === 'battle', 'Place confirmations should move to battle');
assertTrue((int) $linnet->flags['deal_linked_recruit']['linked_instance_id'] === $gobrah->instanceId, 'Battle should preserve source link');
assertTrue((int) $gobrah->flags['deal_linked_recruit']['linked_instance_id'] === $linnet->instanceId, 'Battle should preserve companion link');

// Leaving battlefield kills only the linked companion through zone lifecycle.
(new ZoneManager($state))->toGraveyard($linnet);
assertTrue($linnet->zone === CardInstance::ZONE_GRAVEYARD, 'Linnet should go to graveyard');
assertTrue($gobrah->zone === CardInstance::ZONE_GRAVEYARD, 'Linked companion should die when Linnet leaves battlefield');

$state = dealState();
$linnetA = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'linnet_a', CardInstance::ZONE_FIELD, 2, false, linkedRecruitProp());
$companionA = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'companion_a', CardInstance::ZONE_FIELD, 6, true);
$linnetB = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'linnet_b', CardInstance::ZONE_FIELD, 2, false, linkedRecruitProp());
$companionB = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'companion_b', CardInstance::ZONE_FIELD, 6, true);
$linnetA->flags['deal_linked_recruit'] = ['role' => 'source', 'linked_instance_id' => $companionA->instanceId];
$companionA->flags['deal_linked_recruit'] = ['role' => 'companion', 'linked_instance_id' => $linnetA->instanceId, 'payment_resource' => 'silver', 'converted_cost' => 6];
$linnetB->flags['deal_linked_recruit'] = ['role' => 'source', 'linked_instance_id' => $companionB->instanceId];
$companionB->flags['deal_linked_recruit'] = ['role' => 'companion', 'linked_instance_id' => $linnetB->instanceId, 'payment_resource' => 'silver', 'converted_cost' => 6];
(new ZoneManager($state))->toGraveyard($linnetA);
assertTrue($companionA->zone === CardInstance::ZONE_GRAVEYARD, 'First pair companion should die');
assertTrue($linnetB->zone === CardInstance::ZONE_FIELD && $companionB->zone === CardInstance::ZONE_FIELD, 'Second pair should be unaffected');

$state = dealState();
$linnet = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'linnet', CardInstance::ZONE_FIELD, 2, false, linkedRecruitProp());
$gobrah = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'gobrah', CardInstance::ZONE_FIELD, 6, true);
$linnet->flags['deal_linked_recruit'] = ['role' => 'source', 'linked_instance_id' => $gobrah->instanceId];
$gobrah->flags['deal_linked_recruit'] = ['role' => 'companion', 'linked_instance_id' => $linnet->instanceId, 'payment_resource' => 'silver', 'converted_cost' => 6];
(new ZoneManager($state))->toGraveyard($gobrah);
assertTrue($linnet->zone === CardInstance::ZONE_FIELD, 'Companion dying first should not affect Linnet');
$gobrahAfterFirstDeath = $gobrah->toArray();
(new ZoneManager($state))->toGraveyard($linnet);
assertTrue($linnet->zone === CardInstance::ZONE_GRAVEYARD && $gobrah->zone === CardInstance::ZONE_GRAVEYARD, 'Linnet leaving after companion death should not error or duplicate state');
assertTrue($gobrah->toArray() === $gobrahAfterFirstDeath, 'Source leaving after companion death should not mutate already-graveyard companion');

$state = dealState();
$linnet = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'linnet', CardInstance::ZONE_FIELD, 2, false, linkedRecruitProp());
$gobrah = addCardForLinkedTest($state, GameState::PLAYER_HOST, 'gobrah', CardInstance::ZONE_FIELD, 6, true);
$linnet->flags['deal_linked_recruit'] = ['role' => 'source', 'linked_instance_id' => $gobrah->instanceId];
$gobrah->flags['deal_linked_recruit'] = ['role' => 'companion', 'linked_instance_id' => $linnet->instanceId, 'payment_resource' => 'silver', 'converted_cost' => 6];
$state->battle['strike'] = ['state' => 'results'];
(new ZoneManager($state))->toGraveyard($linnet);
assertTrue($gobrah->zone === CardInstance::ZONE_FIELD && $gobrah->dying, 'During strike cascade linked companion should be marked dying but remain on field until flush');
(new ZoneManager($state))->flushDying();
assertTrue($gobrah->zone === CardInstance::ZONE_GRAVEYARD && !$gobrah->dying, 'flushDying should move linked companion through canonical graveyard transition without recursion');

echo "Deal linked recruit tests passed.\n";
