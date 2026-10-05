<?php
// tools/test_turn_instant_stack.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\InstantProcessor;
use Berserk\Core\TurnPhaseProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function tisAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tisCard(array $overrides): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'],
        ukid: $overrides['ukid'] ?? ('card_' . $overrides['instanceId']),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 5,
        hpMax: $overrides['hpMax'] ?? ($overrides['hp'] ?? 5),
        type: $overrides['type'] ?? 'creature',
        move: 1,
        moveMax: 1,
        strikeWeak: 1,
        strikeMedium: 2,
        strikeStrong: 3,
        prop: $overrides['prop'] ?? [],
        flags: $overrides['flags'] ?? [],
        coins: $overrides['coins'] ?? 0,
    );
}

function tisState(CardInstance ...$cards): GameState
{
    $state = new GameState(9301, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
    ];
    foreach ($cards as $card) {
        $state->addCard($card);
    }
    return $state;
}

function tisInstant(string $key, string $name, array $effect, string $target = 'self', int $uses = 1): array
{
    return [
        'key' => $key,
        'name' => $name,
        'trigger' => 'turn',
        'target' => $target,
        'uses_per_turn' => $uses,
        'effect' => $effect,
    ];
}

// Basic A -> B -> C ordering resolves full global LIFO C -> B -> A.
$a = tisCard([
    'instanceId' => 1,
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['instants' => [tisInstant('a', 'A', ['type' => 'damage', 'value' => 1])]],
]);
$b = tisCard([
    'instanceId' => 2,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'prop' => ['instants' => [tisInstant('b', 'B', ['type' => 'damage', 'value' => 1])]],
]);
$c = tisCard([
    'instanceId' => 3,
    'owner' => GameState::PLAYER_HOST,
    'col' => 4,
    'prop' => ['instants' => [tisInstant('c', 'C', ['type' => 'damage', 'value' => 1])]],
]);
$state = tisState($a, $b, $c);
$ip = new InstantProcessor($state, new Engine());
tisAssert($ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'manual'], 'turn'), 'Manual turn stack should open.');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 1, 'instant_key' => 'a']))->success, 'A declaration should succeed.');
tisAssert($a->hp === 5, 'A effect must not apply at declaration.');
tisAssert(($state->battle['turn_instant_stack']['priority'] ?? null) === GameState::PLAYER_PLAYER, 'Priority should pass to opponent after A.');
tisAssert($ip->playTurnInstant(GameState::PLAYER_PLAYER, new Command('play_turn_instant', ['card_id' => 2, 'instant_key' => 'b']))->success, 'B declaration should succeed.');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 3, 'instant_key' => 'c']))->success, 'C declaration should succeed.');
tisAssert(count($state->battle['turn_instant_stack']['stack'] ?? []) === 3, 'Three turn instants should be ordered before resolution.');
tisAssert($ip->passTurnInstant(GameState::PLAYER_PLAYER)->success, 'First pass should succeed.');
tisAssert($ip->passTurnInstant(GameState::PLAYER_HOST)->success, 'Second pass should resolve stack.');
$summary = $state->battle['instant_result']['summary'] ?? [];
tisAssert(array_column($summary, 'label') === ['C', 'B', 'A'], 'Turn stack should resolve full LIFO.');
tisAssert(empty($state->battle['turn_instant_stack']), 'Turn stack should close after resolution.');
tisAssert($a->hp === 4 && $b->hp === 4 && $c->hp === 4, 'Effects should apply during resolution.');

// Pass reset: pass -> opponent instant resets pass-state.
$a = tisCard([
    'instanceId' => 1,
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['instants' => [tisInstant('a', 'A', ['type' => 'damage', 'value' => 1])]],
]);
$c = tisCard([
    'instanceId' => 3,
    'owner' => GameState::PLAYER_HOST,
    'col' => 4,
    'prop' => ['instants' => [tisInstant('c', 'C', ['type' => 'damage', 'value' => 1])]],
]);
$state = tisState($a, $c);
$ip = new InstantProcessor($state, new Engine());
$ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'manual'], 'turn');
$ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 1, 'instant_key' => 'a']));
$ip->passTurnInstant(GameState::PLAYER_PLAYER);
tisAssert(($state->battle['turn_instant_stack']['passed'] ?? []) === [GameState::PLAYER_PLAYER], 'First pass should be stored.');
$ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 3, 'instant_key' => 'c']));
tisAssert(($state->battle['turn_instant_stack']['passed'] ?? []) === [], 'New instant should reset pass-state.');

// uses_per_turn is reserved at declaration, so unresolved entries cannot bypass the limit.
$limited = tisCard([
    'instanceId' => 5,
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['instants' => [tisInstant('once', 'Once', ['type' => 'damage', 'value' => 1], 'self', 1)]],
]);
$state = tisState($limited);
$ip = new InstantProcessor($state, new Engine());
$ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'manual'], 'turn');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 5, 'instant_key' => 'once']))->success, 'First limited declaration should succeed.');
$state->battle['turn_instant_stack']['priority'] = GameState::PLAYER_HOST;
$again = $ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 5, 'instant_key' => 'once']));
tisAssert(!$again->success, 'Second unresolved declaration should not bypass uses_per_turn.');

// Targeted declaration stores target and waits for LIFO resolution.
$source = tisCard([
    'instanceId' => 10,
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['instants' => [tisInstant('zap', 'Zap', ['type' => 'damage', 'value' => 2], 'enemy')]],
]);
$target = tisCard(['instanceId' => 11, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4]);
$state = tisState($source, $target);
$ip = new InstantProcessor($state, new Engine());
$ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'manual'], 'turn');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 10, 'instant_key' => 'zap']))->success, 'Targeted instant should open target picker.');
tisAssert($target->hp === 5, 'Targeted instant must not apply before target selection.');
tisAssert($ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 11]))->success, 'Target selection should add stack entry.');
tisAssert($target->hp === 5, 'Targeted instant must not apply at target selection.');
$ip->passTurnInstant(GameState::PLAYER_PLAYER);
$ip->passTurnInstant(GameState::PLAYER_HOST);
tisAssert($target->hp === 3, 'Targeted instant should apply during LIFO resolution.');

// Lord of the Dead style start-phase interrupt: ordinary key "bone", no special handler.
$lord = tisCard([
    'instanceId' => 20,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'prop' => ['instants' => [tisInstant('bone', 'Кость', ['type' => 'damage', 'value' => 1], 'enemy')]],
]);
$victim = tisCard(['instanceId' => 21, 'owner' => GameState::PLAYER_HOST, 'hp' => 5]);
$state = tisState($lord, $victim);
$state->battle['turn_phase'] = [
    'phase' => 'start',
    'active_key' => GameState::PLAYER_HOST,
    'passive_key' => GameState::PLAYER_PLAYER,
    'side' => 'passive',
    'passive_queue' => [],
    'active_queue' => [],
    'sub' => [
        'parent_type' => 'instants',
        'remaining' => [
            [
                'id' => 'instant_20_bone',
                'card_id' => 20,
                'ukid' => 'lord',
                'label' => 'Кость',
                'payload' => $lord->prop['instants'][0],
            ],
            [
                'id' => 'dummy_remaining',
                'card_id' => 20,
                'ukid' => 'lord',
                'label' => 'Оставшаяся задача',
                'payload' => $lord->prop['instants'][0],
            ],
        ],
        'can_close' => true,
    ],
    'pending_ack' => null,
];
$result = (new TurnPhaseProcessor($state, new Engine()))->runSub(
    GameState::PLAYER_PLAYER,
    new Command('turn_sub', ['sub_id' => 'instant_20_bone'])
);
tisAssert($result->success, $result->error ?? 'Bone start-phase declaration should succeed.');
tisAssert($victim->hp === 5, 'Bone must not apply before stack pass resolution.');
tisAssert(!empty($state->battle['turn_phase']['sub']['pending_id']), 'TurnPhaseProcessor should preserve selected instant subtask while stack is open.');
$ip = new InstantProcessor($state, new Engine());
$ip->chooseTurnTarget(GameState::PLAYER_PLAYER, new Command('choose_instant_pick', ['target_id' => 21]));
$ip->passTurnInstant(GameState::PLAYER_HOST);
$ip->passTurnInstant(GameState::PLAYER_PLAYER);
tisAssert($victim->hp === 4, 'Bone should resolve through the common turn stack.');
tisAssert(empty($state->battle['turn_phase']['sub']['pending_id']), 'Resolved instant task should be removed from pending slot.');
tisAssert(count($state->battle['turn_phase']['sub']['remaining'] ?? []) === 1, 'Remaining start-phase tasks should not be lost.');
tisAssert(($state->battle['turn_phase']['sub']['remaining'][0]['id'] ?? '') === 'dummy_remaining', 'Start phase should continue from the existing remaining task.');

echo "Turn instant stack tests passed.\n";
