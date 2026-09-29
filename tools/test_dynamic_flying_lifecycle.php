<?php
// tools/test_dynamic_flying_lifecycle.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\DamageResolver;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\ZoneManager;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function dynFlyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dynFlyState(): GameState
{
    $state = new GameState(random_int(1000, 9999), 1, 2);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
        'hidden_row_revealed' => true,
    ];
    $state->getPlayer(GameState::PLAYER_HOST)->side = 1;
    $state->getPlayer(GameState::PLAYER_PLAYER)->side = 2;
    $state->addCard(new CardInstance(
        instanceId: $state->nextInstanceId(),
        ukid: 'opponent_anchor',
        owner: GameState::PLAYER_PLAYER,
        zone: CardInstance::ZONE_FIELD,
        row: 4,
        col: 3,
        hp: 5,
        hpMax: 5,
        type: 'creature',
        closed: false,
        revealed: true,
    ));
    return $state;
}

function dynFlyCard(GameState $state, array $overrides = []): CardInstance
{
    $type = (string) ($overrides['type'] ?? 'creature');
    $card = new CardInstance(
        instanceId: $overrides['instanceId'] ?? $state->nextInstanceId(),
        ukid: $overrides['ukid'] ?? ('dyn_fly_' . random_int(1000, 9999)),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 5,
        hpMax: $overrides['hpMax'] ?? 5,
        price: $overrides['price'] ?? 3,
        type: $type,
        move: $type === 'fly' ? 0 : 1,
        moveMax: $type === 'fly' ? 0 : 1,
        closed: $overrides['closed'] ?? false,
        revealed: $overrides['revealed'] ?? true,
        prop: $overrides['prop'] ?? [],
    );
    $state->addCard($card);
    return $card;
}

function dynFlyAddFlyer(GameState $state, string $ukid): CardInstance
{
    $card = dynFlyCard($state, [
        'ukid' => $ukid,
        'type' => 'fly',
        'zone' => CardInstance::ZONE_FIELD,
    ]);
    (new ZoneManager($state))->toFlying($card);
    return $card;
}

function dynFlyLordOfSkies(GameState $state, string $ukid = 's1_68'): CardInstance
{
    return dynFlyCard($state, [
        'ukid' => $ukid,
        'type' => 'creature',
        'zone' => CardInstance::ZONE_FIELD,
        'row' => 3,
        'col' => 4,
        'prop' => [
            'actions' => [[
                'key' => 'become_fly',
                'name' => 'Получить полёт',
                'self' => true,
                'type' => 'become_fly',
            ]],
        ],
    ]);
}

function dynFlyCards(GameState $state): array
{
    $cards = $state->getCardsInZone(GameState::PLAYER_HOST, CardInstance::ZONE_FLYING);
    usort($cards, fn(CardInstance $a, CardInstance $b) => $a->slot <=> $b->slot);
    return $cards;
}

function dynFlySlots(GameState $state): array
{
    return array_map(fn(CardInstance $card) => $card->slot, dynFlyCards($state));
}

function dynFlyIds(GameState $state): array
{
    return array_map(fn(CardInstance $card) => $card->instanceId, dynFlyCards($state));
}

function dynFlyCardInfo(array $cards): array
{
    $info = [];
    foreach ($cards as $card) {
        $info[$card->ukid] = [
            'name' => $card->ukid,
            'price' => $card->price,
            'health' => $card->hpMax,
            'move' => $card->moveMax,
            'elite' => false,
            'element' => 'neutral',
            'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 1],
        ];
    }
    return $info;
}

$state = dynFlyState();
$existing = [
    dynFlyAddFlyer($state, 'existing_fly_1'),
    dynFlyAddFlyer($state, 'existing_fly_2'),
    dynFlyAddFlyer($state, 'existing_fly_3'),
];
$lord = dynFlyLordOfSkies($state);

$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => $lord->instanceId,
    'target_id' => $lord->instanceId,
    'action_key' => 'become_fly',
]));
dynFlyAssert($result->success, $result->error ?? 'Lord of Skies become_fly action should succeed');
dynFlyAssert(count(dynFlyCards($state)) === 4, 'Three existing flyers plus Lord of Skies should become four flyers');
dynFlyAssert(dynFlySlots($state) === [1, 2, 3, 4], 'Four flyers should have normalized slots');
dynFlyAssert($lord->zone === CardInstance::ZONE_FLYING && $lord->slot === 4, 'Lord of Skies should append as fourth flying card');
dynFlyAssert($lord->type === 'fly', 'Lord of Skies should change type to fly');

$tpl = new Template(__DIR__ . '/../templates/');
$screen = (new BattleScreen($tpl))->prepare($state, GameState::PLAYER_HOST, 'host', null, dynFlyCardInfo(array_merge($existing, [$lord])));
foreach (array_merge($existing, [$lord]) as $card) {
    dynFlyAssert(str_contains($screen['data']['fly_zones_html'], $card->ukid), 'BattleScreen should render every flying card');
}

$state->battle['strike'] = null;
$middle = $existing[1];
(new DamageResolver($state))->forceDeath($middle, 'execute', $lord);
dynFlyAssert($middle->zone === CardInstance::ZONE_GRAVEYARD, 'Dead middle flyer should move to graveyard');
dynFlyAssert(count(dynFlyCards($state)) === 3, 'Flying zone should shrink after middle flyer dies');
dynFlyAssert(dynFlySlots($state) === [1, 2, 3], 'Flying slots should normalize after middle flyer dies');
dynFlyAssert(dynFlyIds($state) === [$existing[0]->instanceId, $existing[2]->instanceId, $lord->instanceId], 'Flying identity/order should remain instance based after normalization');

$newFly = dynFlyLordOfSkies($state, 'new_become_fly_after_death');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => $newFly->instanceId,
    'target_id' => $newFly->instanceId,
    'action_key' => 'become_fly',
]));
dynFlyAssert($result->success, $result->error ?? 'Next become_fly action after normalization should succeed');
dynFlyAssert($newFly->slot === 4, 'Next flying card should receive the next normalized slot');

$fiveState = dynFlyState();
for ($i = 1; $i <= 5; $i++) {
    dynFlyAddFlyer($fiveState, 'five_fly_' . $i);
}
$sixth = dynFlyLordOfSkies($fiveState, 's1_68');
$result = (new Engine())->apply($fiveState, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => $sixth->instanceId,
    'target_id' => $sixth->instanceId,
    'action_key' => 'become_fly',
]));
dynFlyAssert($result->success, $result->error ?? 'become_fly should work with five existing flyers');
dynFlyAssert(count(dynFlyCards($fiveState)) === 6, 'Five existing flyers plus become_fly should become six flyers');
dynFlyAssert(dynFlySlots($fiveState) === [1, 2, 3, 4, 5, 6], 'Six flyers should have normalized slots');

$fiveState->battle['strike'] = null;
$last = dynFlyCards($fiveState)[5];
(new DamageResolver($fiveState))->forceDeath($last, 'execute', $sixth);
dynFlyAssert(count(dynFlyCards($fiveState)) === 5, 'Flying zone should shrink when the last flyer dies');
dynFlyAssert(dynFlySlots($fiveState) === [1, 2, 3, 4, 5], 'Slots should stay normalized after last flyer dies');

$restored = GameState::fromArray($fiveState->toArray());
dynFlyAssert(count(dynFlyCards($restored)) === 5, 'Serialization should preserve flying cards');
dynFlyAssert(dynFlySlots($restored) === [1, 2, 3, 4, 5], 'Serialization should preserve normalized flying slots');

echo "Dynamic flying lifecycle tests passed.\n";
