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
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;

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

// InfoPanel renders the turn instant stack choice through TurnInstantStackChoice::spec().
$stacked = tisCard([
    'instanceId' => 30,
    'ukid' => 'stacked',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'flags' => ['in_stack' => true],
    'prop' => ['instants' => [tisInstant('stacked', 'Stacked', ['type' => 'damage', 'value' => 1])]],
]);
$available = tisCard([
    'instanceId' => 31,
    'ukid' => 'available',
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['instants' => [tisInstant('spark', 'Spark', ['type' => 'damage', 'value' => 1])]],
]);
$state = tisState($stacked, $available);
$state->battle['turn_instant_stack'] = [
    'state' => 'ordering',
    'priority' => GameState::PLAYER_HOST,
    'phase' => 'turn',
    'stack' => [[
        'card_id' => 30,
        'player' => GameState::PLAYER_PLAYER,
        'label' => 'Stacked',
        'effect' => ['type' => 'damage', 'value' => 1],
    ]],
    'passed' => [],
    'context' => ['type' => 'manual'],
];
$cardsInfo = [
    'stacked' => ['name' => 'Stacked Mage'],
    'available' => ['name' => 'Available Mage'],
];
$panel = new InfoPanel(new Template(__DIR__ . '/../templates/'));
$html = $panel->render($state, GameState::PLAYER_HOST, 'host', $cardsInfo, '/battle?game=9301');
tisAssert(str_contains($html, 'Стек инстантов хода'), 'InfoPanel should render turn instant stack title.');
tisAssert(str_contains($html, 'Stacked Mage'), 'InfoPanel should render existing turn instant stack entries.');
tisAssert(str_contains($html, 'task-card--instant'), 'InfoPanel should render available turn instant cards.');
tisAssert(str_contains($html, 'cmd=play_turn_instant'), 'InfoPanel should render play command for available turn instants.');
tisAssert(str_contains($html, 'cmd=pass_turn_instant'), 'InfoPanel should render turn instant pass command.');
tisAssert((new InstantProcessor($state, new Engine()))->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 31, 'instant_key' => 'spark']))->success, 'UI-listed instant should be playable.');
$html = $panel->render($state, GameState::PLAYER_PLAYER, 'player', $cardsInfo, '/battle?game=9301');
tisAssert(($state->battle['turn_instant_stack']['priority'] ?? null) === GameState::PLAYER_PLAYER, 'Priority should move to the second player after ordering an instant.');
tisAssert(str_contains($html, 'cmd=pass_turn_instant'), 'InfoPanel should render priority UI for the second player.');

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

// No-op still consumes the declared instant: source closes and in_stack is cleared.
$source = tisCard([
    'instanceId' => 12,
    'owner' => GameState::PLAYER_HOST,
    'coins' => 1,
    'prop' => ['instants' => [[
        'key' => 'paid_zap',
        'name' => 'Paid Zap',
        'trigger' => 'turn',
        'target' => 'enemy',
        'coins' => 1,
        'effect' => ['type' => 'damage', 'value' => 2],
    ]]],
]);
$target = tisCard(['instanceId' => 13, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4]);
$state = tisState($source, $target);
$ip = new InstantProcessor($state, new Engine());
$ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'manual'], 'turn');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 12, 'instant_key' => 'paid_zap']))->success, 'Paid targeted instant should open picker.');
tisAssert($ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 13]))->success, 'Paid targeted instant should stack.');
tisAssert($source->coins === 0, 'Cost should be reserved at declaration.');
tisAssert(!empty($source->flags['in_stack']), 'Source should be marked in_stack after declaration.');
$target->dying = true;
$target->hp = 0;
$ip->passTurnInstant(GameState::PLAYER_PLAYER);
$ip->passTurnInstant(GameState::PLAYER_HOST);
$summary = $state->battle['instant_result']['summary'] ?? [];
tisAssert(($summary[0]['applied'] ?? true) === false, 'Invalid target should resolve as no-op.');
tisAssert(($summary[0]['reason'] ?? '') !== '', 'No-op should keep a reason.');
tisAssert($source->closed, 'No-op resolved instant should still close source.');
tisAssert(empty($source->flags['in_stack']), 'No-op resolved instant should clear in_stack.');
tisAssert($source->coins === 0, 'No-op should not refund reserved cost.');
tisAssert((int) ($source->flags['instant_uses_this_turn']['paid_zap'] ?? 0) === 1, 'No-op should not roll back uses_per_turn.');

// target_not_moved is enforced server-side at target selection.
$source = tisCard([
    'instanceId' => 14,
    'owner' => GameState::PLAYER_HOST,
    'coins' => 1,
    'prop' => ['instants' => [[
        'key' => 'root_check',
        'name' => 'Root Check',
        'trigger' => 'turn',
        'target' => 'ally',
        'coins' => 1,
        'effect' => ['type' => 'marker', 'condition' => 'target_not_moved', 'marker' => ['rooted' => true]],
    ]]],
]);
$legal = tisCard(['instanceId' => 15, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 4]);
$moved = tisCard([
    'instanceId' => 16,
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 5,
    'flags' => ['moved_this_turn' => true],
]);
$state = tisState($source, $legal, $moved);
$ip = new InstantProcessor($state, new Engine());
$ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'manual'], 'turn');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 14, 'instant_key' => 'root_check']))->success, 'target_not_moved instant should open picker.');
$invalid = $ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 16]));
tisAssert(!$invalid->success, 'Moved target must be rejected server-side.');
tisAssert(count($state->battle['turn_instant_stack']['stack'] ?? []) === 0, 'Rejected moved target must not create a stack entry.');
tisAssert($source->coins === 1, 'Rejected moved target must not spend cost.');
tisAssert(empty($source->flags['instant_uses_this_turn']['root_check']), 'Rejected moved target must not spend use.');
tisAssert(empty($source->flags['in_stack']), 'Rejected moved target must not mark source in_stack.');
tisAssert($ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 15]))->success, 'Legal unmoved target should remain selectable.');
tisAssert(count($state->battle['turn_instant_stack']['stack'] ?? []) === 1, 'Legal target should add one stack entry.');

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
