<?php
// tools/test_adelaide_gray_elf_cards.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\ActionResolver;
use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\Command;
use Berserk\Core\Dice;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;
use Berserk\Core\TurnProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function agAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function agRolls(string $value): void
{
    $_SESSION = [];
    $_SESSION['debug_roll'] = $value;
    unset($_SESSION['debug_roll_state']);
    Dice::init();
}

function agCard(array $o): CardInstance
{
    return new CardInstance(
        instanceId: $o['instanceId'],
        ukid: $o['ukid'] ?? ('card_' . $o['instanceId']),
        owner: $o['owner'] ?? GameState::PLAYER_HOST,
        zone: $o['zone'] ?? CardInstance::ZONE_FIELD,
        row: $o['row'] ?? 3,
        col: $o['col'] ?? 3,
        hp: $o['hp'] ?? 8,
        hpMax: $o['hpMax'] ?? ($o['hp'] ?? 8),
        price: $o['price'] ?? 0,
        type: $o['type'] ?? 'creature',
        closed: $o['closed'] ?? false,
        move: $o['move'] ?? 1,
        moveMax: $o['moveMax'] ?? ($o['move'] ?? 1),
        strikeWeak: $o['strikeWeak'] ?? 1,
        strikeMedium: $o['strikeMedium'] ?? 2,
        strikeStrong: $o['strikeStrong'] ?? 3,
        coins: $o['coins'] ?? 0,
        prop: $o['prop'] ?? [],
        modifiers: $o['modifiers'] ?? [],
        markers: $o['markers'] ?? [],
        flags: $o['flags'] ?? [],
    );
}

function agState(CardInstance ...$cards): GameState
{
    $state = new GameState(99182102, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
    ];

    foreach ($cards as $card) {
        $state->addCard($card);
    }

    return $state;
}

function adelaideProp(): array
{
    return [
        'ova' => [
            'value' => 1,
            'condition' => ['type' => 'column', 'values' => [1, 5]],
        ],
        'ovz' => [
            'value' => 1,
            'condition' => ['type' => 'column', 'values' => [3]],
        ],
        'ability' => [
            'only' => ['strike'],
            'level' => ['medium', 'strong'],
            'value' => 1,
            'condition' => ['type' => 'column', 'values' => [1, 5]],
        ],
        'actions' => [['type' => 'jump', 'range' => 3]],
        'damage_reduction' => [[
            'types' => ['strike'],
            'value' => 1,
            'levels' => ['weak'],
            'condition' => ['type' => 'column', 'values' => [3]],
        ]],
    ];
}

$enemy = agCard([
    'instanceId' => 2,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 3,
]);
$adelaideEdge = agCard([
    'instanceId' => 1,
    'ukid' => 's1_182',
    'col' => 1,
    'prop' => adelaideProp(),
]);
$state = agState($adelaideEdge, $enemy);
agAssert(CardStats::getOva($state, $adelaideEdge, $enemy) === 1, 'Adelaide Sailors should get OVA in edge columns.');
agAssert(CardStats::getOvz($state, $adelaideEdge) === 0, 'Adelaide Sailors should not get OVZ outside center column.');
agAssert(CardStats::getAbilityBonus($state, $adelaideEdge, $enemy, 'strike', 'medium') === 1, 'Adelaide Sailors should boost medium strike in edge columns.');
agAssert(CardStats::getAbilityBonus($state, $adelaideEdge, $enemy, 'strike', 'weak') === 0, 'Adelaide Sailors should not boost weak strike.');

$adelaideCenter = agCard([
    'instanceId' => 3,
    'ukid' => 's1_182',
    'col' => 3,
    'prop' => adelaideProp(),
]);
$state = agState($adelaideCenter, $enemy);
agAssert(CardStats::getOva($state, $adelaideCenter, $enemy) === 0, 'Adelaide Sailors should not get OVA in center column.');
agAssert(CardStats::getOvz($state, $adelaideCenter) === 1, 'Adelaide Sailors should get OVZ in center column.');
agAssert(CardStats::getDamageReduction($state, $enemy, $adelaideCenter, 'strike', 'weak') === 1, 'Adelaide Sailors should reduce weak strike in center column.');
agAssert(CardStats::getDamageReduction($state, $enemy, $adelaideCenter, 'strike', 'medium') === 0, 'Adelaide Sailors should not reduce medium strike.');

$etun = agCard([
    'instanceId' => 10,
    'ukid' => 's1_98',
    'row' => 3,
    'col' => 3,
    'strikeWeak' => 1,
    'prop' => ['ignore' => ['block_weak_strike']],
]);
$weakBlocker = agCard([
    'instanceId' => 11,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 3,
    'hp' => 8,
    'hpMax' => 8,
    'prop' => ['block_weak_strike' => true],
]);
$state = agState($etun, $weakBlocker);
agRolls('1,1');
$result = (new StrikeResolver($state, new Engine()))->declare(
    GameState::PLAYER_HOST,
    new Command('strike', ['card_id' => $etun->instanceId, 'target_id' => $weakBlocker->instanceId])
);
agAssert($result->success, $result->error ?? 'Etun weak strike should start.');
agAssert($weakBlocker->hp === 7, 'Etun should ignore block_weak_strike and deal weak strike damage.');

$grayElf = agCard([
    'instanceId' => 20,
    'ukid' => 's1_99',
    'row' => 3,
    'col' => 3,
    'coins' => 1,
    'prop' => [
        'actions' => [[
            'key' => 'fire_arrow',
            'name' => 'Огненная стрела',
            'type' => 'shot',
            'coins' => 1,
            'value' => 5,
            'ignore' => ['zov', 'zoda', 'zoa', 'zoan', 'damage_reduction'],
        ]],
        'save_coins' => true,
    ],
]);
$protectedTarget = agCard([
    'instanceId' => 21,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 5,
    'hp' => 8,
    'hpMax' => 8,
    'prop' => [
        'zov' => true,
        'damage_reduction' => [[
            'types' => ['shot'],
            'value' => 2,
        ]],
    ],
]);
$state = agState($grayElf, $protectedTarget);
agRolls('6');
$result = (new ActionResolver($state, new Engine()))->handle(
    GameState::PLAYER_HOST,
    new Command('action', [
        'card_id' => $grayElf->instanceId,
        'target_id' => $protectedTarget->instanceId,
        'action_key' => 'fire_arrow',
    ])
);
agAssert($result->success, $result->error ?? 'Gray Elf fire arrow should resolve.');
agAssert($protectedTarget->hp === 3, 'Gray Elf fire arrow should ignore zov and shot damage_reduction.');
agAssert($grayElf->coins === 0, 'Gray Elf fire arrow should spend one coin.');

$kele = agCard([
    'instanceId' => 30,
    'ukid' => 's1_102',
    'row' => 3,
    'col' => 3,
    'hp' => 6,
    'hpMax' => 8,
    'strikeWeak' => 3,
    'prop' => ['on_strike_self_heal' => ['value' => 2, 'no_close_if_full' => true]],
]);
$target = agCard([
    'instanceId' => 31,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 3,
    'hp' => 8,
    'hpMax' => 8,
]);
$state = agState($kele, $target);
agRolls('1,1');
$result = (new StrikeResolver($state, new Engine()))->declare(
    GameState::PLAYER_HOST,
    new Command('strike', ['card_id' => $kele->instanceId, 'target_id' => $target->instanceId])
);
agAssert($result->success, $result->error ?? 'Kele strike should resolve.');
$resolver = new StrikeResolver($state, new Engine());
agAssert($resolver->confirmStrike(GameState::PLAYER_HOST, new Command('confirm_strike', []))->success, 'Kele owner confirm should succeed.');
agAssert($resolver->confirmStrike(GameState::PLAYER_PLAYER, new Command('confirm_strike', []))->success, 'Kele defender confirm should succeed.');
agAssert($kele->hp === 8, 'Kele should heal itself after a successful strike.');
agAssert(!$kele->closed, 'Kele should stay open when self-heal reaches full HP.');

$dverg = agCard([
    'instanceId' => 40,
    'ukid' => 's1_90',
    'row' => 3,
    'col' => 3,
    'prop' => [
        'start' => ['hp' => 3, 'side' => 2, 'type' => 'get_hp'],
        'actions' => [['key' => 'dverg_gift', 'name' => 'Дух мухомора', 'self' => true, 'type' => 'dverg_gift']],
    ],
]);
$ally = agCard([
    'instanceId' => 41,
    'row' => 3,
    'col' => 4,
    'strikeWeak' => 2,
    'strikeMedium' => 3,
    'strikeStrong' => 4,
]);
$enemy = agCard([
    'instanceId' => 42,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 4,
]);
$state = agState($dverg, $ally, $enemy);
$result = (new ActionResolver($state, new Engine()))->handle(
    GameState::PLAYER_HOST,
    new Command('action', [
        'card_id' => $dverg->instanceId,
        'target_id' => $dverg->instanceId,
        'action_key' => 'dverg_gift',
    ])
);
agAssert($result->success, $result->error ?? 'Dverg gift should open target choice.');
agAssert(in_array($ally->instanceId, $state->battle['pending_dverg_gift']['candidates'] ?? [], true), 'Dverg gift should offer an open ally.');
$result = (new ActionResolver($state, new Engine()))->chooseDvergGiftTarget(
    GameState::PLAYER_HOST,
    new Command('choose_dverg_gift', ['target_id' => $ally->instanceId])
);
agAssert($result->success, $result->error ?? 'Dverg gift target choice should resolve.');
agAssert($dverg->closed, 'Dverg should close after giving the gift.');
agAssert(($ally->flags['granted_action:dverg_magic']['strike']['strong'] ?? null) === 5, 'Dverg gift should grant strike +1 magic action.');
agRolls('6');
$result = (new ActionResolver($state, new Engine()))->handle(
    GameState::PLAYER_HOST,
    new Command('action', [
        'card_id' => $ally->instanceId,
        'target_id' => $enemy->instanceId,
        'action_key' => 'dverg_magic',
    ])
);
agAssert($result->success, $result->error ?? 'Granted Dverg magic should resolve.');
agAssert($enemy->hp === 3, 'Granted Dverg magic should use the granted strong value.');
agAssert(!empty($ally->flags['granted_action:dverg_magic']['used']), 'Granted Dverg magic should be marked used.');
(new TurnProcessor($state, new Engine()))->afterEndPhase(GameState::PLAYER_HOST, GameState::PLAYER_PLAYER);
agAssert(empty($ally->flags['granted_action:dverg_magic']), 'Granted Dverg magic should clear at end of owner turn.');

echo "Adelaide/Gray Elf card tests passed.\n";
