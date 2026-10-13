<?php
// tools/test_segger_aristocrat.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\ActionResolver;
use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;
use Berserk\Core\TurnProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function saAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function saCard(array $o): CardInstance
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
        type: $o['type'] ?? 'creature',
        closed: $o['closed'] ?? false,
        move: $o['move'] ?? 1,
        moveMax: $o['moveMax'] ?? ($o['move'] ?? 1),
        strikeWeak: $o['strikeWeak'] ?? 1,
        strikeMedium: $o['strikeMedium'] ?? 2,
        strikeStrong: $o['strikeStrong'] ?? 3,
        prop: $o['prop'] ?? [],
        modifiers: $o['modifiers'] ?? [],
        markers: $o['markers'] ?? [],
        flags: $o['flags'] ?? [],
    );
}

function saState(CardInstance ...$cards): GameState
{
    $state = new GameState(192194, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
    ];
    $state->getPlayer(GameState::PLAYER_HOST)->side = 1;
    $state->getPlayer(GameState::PLAYER_PLAYER)->side = 2;

    foreach ($cards as $card) {
        $state->addCard($card);
    }

    return $state;
}

function saAristocratProp(): array
{
    return [
        'actions' => [['type' => 'uchr', 'value' => 1]],
        'start_pre_battle' => [
            [
                'side' => 1,
                'count' => 2,
                'effect' => ['type' => 'poison', 'value' => 1],
            ],
            [
                'side' => 2,
                'count' => 2,
                'effect' => ['type' => 'close'],
            ],
        ],
    ];
}

$aristocrat = saCard([
    'instanceId' => 1,
    'ukid' => 's1_194',
    'owner' => GameState::PLAYER_HOST,
    'prop' => saAristocratProp(),
]);
$enemyA = saCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 2]);
$enemyB = saCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3]);
$ally = saCard(['instanceId' => 4, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 4]);
$state = saState($aristocrat, $enemyA, $enemyB, $ally);
(new TurnProcessor($state, new Engine()))->startBattle();
$pending = $state->battle['pending_intrigue_start'][0] ?? null;
saAssert(is_array($pending), 'Host-side Aristocrat should open pre-battle intrigue.');
saAssert(($pending['owner'] ?? null) === GameState::PLAYER_HOST, 'Host Aristocrat intrigue should belong to host.');
saAssert(($pending['effect']['type'] ?? '') === 'poison', 'Side 1 Aristocrat should use poison intrigue.');
saAssert((int) ($pending['count'] ?? 0) === 2, 'Aristocrat intrigue should require two targets.');

$result = (new ActionResolver($state, new Engine()))->chooseIntrigueStart(
    GameState::PLAYER_HOST,
    new Command('choose_intrigue_start', ['target_ids' => [$enemyA->instanceId, $enemyB->instanceId]])
);
saAssert($result->success, $result->error ?? 'Poison intrigue should resolve.');
saAssert(($enemyA->markers['poison']['value'] ?? null) === 1, 'First chosen target should get poison 1.');
saAssert(($enemyB->markers['poison']['value'] ?? null) === 1, 'Second chosen target should get poison 1.');
saAssert(empty($ally->markers['poison']), 'Unchosen ally should not get poison.');
saAssert(empty($state->battle['pending_intrigue_start']), 'Intrigue queue should clear after the final choice.');

$aristocrat = saCard([
    'instanceId' => 10,
    'ukid' => 's1_194',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 3,
    'prop' => saAristocratProp(),
]);
$hostA = saCard(['instanceId' => 11, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 2]);
$hostB = saCard(['instanceId' => 12, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$playerAlly = saCard(['instanceId' => 13, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$state = saState($aristocrat, $hostA, $hostB, $playerAlly);
(new TurnProcessor($state, new Engine()))->startBattle();
$pending = $state->battle['pending_intrigue_start'][0] ?? null;
saAssert(is_array($pending), 'Player-side Aristocrat should open pre-battle intrigue.');
saAssert(($pending['owner'] ?? null) === GameState::PLAYER_PLAYER, 'Player Aristocrat intrigue should belong to player.');
saAssert(($pending['effect']['type'] ?? '') === 'close', 'Side 2 Aristocrat should use close intrigue.');
saAssert(!in_array($playerAlly->instanceId, $pending['candidates'] ?? [], true), 'Close intrigue should not target own creatures.');

$result = (new ActionResolver($state, new Engine()))->chooseIntrigueStart(
    GameState::PLAYER_PLAYER,
    new Command('choose_intrigue_start', ['target_ids' => [$hostA->instanceId, $hostB->instanceId]])
);
saAssert($result->success, $result->error ?? 'Close intrigue should resolve.');
saAssert($hostA->closed && $hostB->closed, 'Chosen enemies should be closed.');
saAssert(($hostA->markers['stun']['value'] ?? null) === 1, 'Closed target should receive stun marker.');
saAssert(!$playerAlly->closed, 'Own ally should not be closed by side 2 intrigue.');

$lonelyAristocrat = saCard([
    'instanceId' => 20,
    'ukid' => 's1_194',
    'owner' => GameState::PLAYER_PLAYER,
    'prop' => saAristocratProp(),
]);
$singleEnemy = saCard(['instanceId' => 21, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 2]);
$state = saState($lonelyAristocrat, $singleEnemy);
(new TurnProcessor($state, new Engine()))->startBattle();
saAssert(empty($state->battle['pending_intrigue_start']), 'Intrigue should not open when fewer than count targets exist.');

$segger = saCard([
    'instanceId' => 30,
    'ukid' => 's1_192',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'strikeWeak' => 1,
    'strikeMedium' => 2,
    'strikeStrong' => 4,
    'prop' => [
        'on_strike_ally_shield' => [
            'types' => ['strike', 'tap', 'uchr', 'shot', 'throw', 'impact'],
        ],
    ],
]);
$leftAlly = saCard(['instanceId' => 31, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 2]);
$rightAlly = saCard(['instanceId' => 32, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 4]);
$farAlly = saCard(['instanceId' => 33, 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 3]);
$target = saCard(['instanceId' => 34, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3]);
$state = saState($segger, $leftAlly, $rightAlly, $farAlly, $target);
$state->battle['strike'] = [
    'attacker_id' => $segger->instanceId,
    'target_id' => $target->instanceId,
    'state' => 'results',
    'final' => ['attack' => 'strong', 'defend' => '', 'decreased' => false],
    'strike_hit' => true,
    'confirmed' => [],
];
$result = (new StrikeResolver($state, new Engine()))->continueAfterStrikeResultAck($state->battle['strike']);
saAssert($result && $result->success, $result?->error ?? 'Segger shield choice should open.');
$pending = $state->battle['pending_segger_shield'] ?? null;
saAssert(is_array($pending), 'Segger should open shield choice after a successful strike.');
saAssert((int) ($pending['max_value'] ?? 0) === 4, 'Segger max shield should use the strike value.');
saAssert(in_array($leftAlly->instanceId, $pending['candidates'] ?? [], true), 'Left ally should be shield candidate.');
saAssert(in_array($rightAlly->instanceId, $pending['candidates'] ?? [], true), 'Right ally should be shield candidate.');
saAssert(!in_array($farAlly->instanceId, $pending['candidates'] ?? [], true), 'Non-horizontal ally should not be shield candidate.');

$result = (new ActionResolver($state, new Engine()))->chooseSeggerShield(
    GameState::PLAYER_HOST,
    new Command('choose_segger_shield', ['value' => 3])
);
saAssert($result->success, $result->error ?? 'Segger shield choice should resolve.');
foreach ([$leftAlly, $rightAlly] as $allyCard) {
    $modifier = $allyCard->modifiers[0] ?? null;
    saAssert(($modifier['stat'] ?? '') === 'damage_reduction', 'Adjacent ally should receive damage_reduction.');
    saAssert((int) ($modifier['value'] ?? 0) === 3, 'Adjacent ally shield value should match chosen value.');
    saAssert(($modifier['types'] ?? []) === ['strike', 'tap', 'uchr', 'shot', 'throw', 'impact'], 'Shield should use prop types.');
    saAssert(($modifier['timing'] ?? '') === 'not_source_turn', 'Shield should expire on the next opponent turn.');
}
saAssert(empty($farAlly->modifiers), 'Far ally should not receive Segger shield.');
saAssert(($state->battle['strike']['state'] ?? '') === 'results', 'Strike should return to results after Segger choice.');

echo "Segger/Aristocrat tests passed.\n";
