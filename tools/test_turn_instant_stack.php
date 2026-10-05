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
        closed: $overrides['closed'] ?? false,
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

function tisAckTurnResult(GameState $state): void
{
    $result = (new InstantProcessor($state, new Engine()))->ackTurnInstantResult(GameState::PLAYER_HOST);
    tisAssert($result->success, $result->error ?? 'Turn instant result OK should succeed.');
}

// Same player can declare multiple turn instants before passing; opponent can answer with multiple too.
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
tisAssert(($state->battle['turn_instant_stack']['priority'] ?? null) === GameState::PLAYER_HOST, 'Declaration should retain priority for A owner.');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 3, 'instant_key' => 'c']))->success, 'C declaration by same player should succeed without pass.');
tisAssert(($state->battle['turn_instant_stack']['priority'] ?? null) === GameState::PLAYER_HOST, 'Declaration should retain priority for C owner.');
tisAssert(array_column($state->battle['turn_instant_stack']['stack'] ?? [], 'label') === ['A', 'C'], 'Same-player declarations should keep declaration order.');
tisAssert($ip->passTurnInstant(GameState::PLAYER_HOST)->success, 'Host pass should transfer priority.');
tisAssert(($state->battle['turn_instant_stack']['priority'] ?? null) === GameState::PLAYER_PLAYER, 'Pass should transfer priority to opponent with legal instants.');
tisAssert($ip->playTurnInstant(GameState::PLAYER_PLAYER, new Command('play_turn_instant', ['card_id' => 2, 'instant_key' => 'b']))->success, 'B declaration should succeed.');
tisAssert(($state->battle['turn_instant_stack']['priority'] ?? null) === GameState::PLAYER_PLAYER, 'Opponent declaration should retain opponent priority.');
tisAssert(count($state->battle['turn_instant_stack']['stack'] ?? []) === 3, 'Three turn instants should be ordered before resolution.');
tisAssert($ip->passTurnInstant(GameState::PLAYER_PLAYER)->success, 'Opponent pass should auto-resolve when Host has no legal instants.');
$summary = $state->battle['instant_result']['summary'] ?? [];
tisAssert(array_column($summary, 'label') === ['B', 'C', 'A'], 'Turn stack should resolve full LIFO.');
tisAssert(empty($state->battle['turn_instant_stack']), 'Turn stack should close after resolution.');
tisAssert(!empty($state->battle['turn_instant_result']), 'Resolved non-empty turn stack should wait for result OK.');
tisAssert($a->hp === 4 && $b->hp === 4 && $c->hp === 4, 'Effects should apply during resolution.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render($state, GameState::PLAYER_HOST, 'host', [
    'card_1' => ['name' => 'A-card'],
    'card_2' => ['name' => 'B-card'],
    'card_3' => ['name' => 'C-card'],
], '/battle?game=9301');
tisAssert(str_contains($html, 'Разрешение инстантов'), 'InfoPanel should render turn instant result title.');
tisAssert(strpos($html, 'B-card') < strpos($html, 'C-card') && strpos($html, 'C-card') < strpos($html, 'A-card'), 'Result UI should preserve LIFO resolution order.');
tisAssert(str_contains($html, 'cmd=turn_instant_result_ok'), 'Result UI should render OK command.');
tisAckTurnResult($state);
tisAssert(empty($state->battle['turn_instant_result']), 'Result OK should clear turn instant result.');

// New declaration after a pass resets the pass-chain when the other player can still respond.
$a = tisCard([
    'instanceId' => 101,
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['instants' => [tisInstant('a', 'A', ['type' => 'damage', 'value' => 1])]],
]);
$d = tisCard([
    'instanceId' => 102,
    'owner' => GameState::PLAYER_HOST,
    'col' => 4,
    'prop' => ['instants' => [tisInstant('d', 'D', ['type' => 'damage', 'value' => 1])]],
]);
$b = tisCard([
    'instanceId' => 103,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'prop' => ['instants' => [tisInstant('b', 'B', ['type' => 'damage', 'value' => 1])]],
]);
$state = tisState($a, $d, $b);
$ip = new InstantProcessor($state, new Engine());
$ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'manual'], 'turn');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 101, 'instant_key' => 'a']))->success, 'Host A should stack.');
tisAssert($ip->passTurnInstant(GameState::PLAYER_HOST)->success, 'Host pass should transfer priority before Player response.');
tisAssert($ip->playTurnInstant(GameState::PLAYER_PLAYER, new Command('play_turn_instant', ['card_id' => 103, 'instant_key' => 'b']))->success, 'Player B response should stack.');
tisAssert(($state->battle['turn_instant_stack']['passed'] ?? []) === [], 'Player declaration should reset pass-chain.');
tisAssert($ip->passTurnInstant(GameState::PLAYER_PLAYER)->success, 'Player pass after declaration should not resolve while Host has D.');
tisAssert(!empty($state->battle['turn_instant_stack']), 'Stack should remain open after only one new consecutive pass.');
tisAssert(($state->battle['turn_instant_stack']['priority'] ?? null) === GameState::PLAYER_HOST, 'Priority should return to Host after Player pass.');
tisAssert($ip->passTurnInstant(GameState::PLAYER_HOST)->success, 'Host second consecutive pass should resolve.');
tisAssert(array_column($state->battle['instant_result']['summary'] ?? [], 'label') === ['B', 'A'], 'Reset pass-chain scenario should resolve B then A.');
tisAckTurnResult($state);

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
$html = $panel->render($state, GameState::PLAYER_HOST, 'host', $cardsInfo, '/battle?game=9301');
tisAssert(str_contains($html, 'instant-stack__who') && str_contains($html, 'Оппонент') && str_contains($html, 'Ты'), 'Turn stack UI should distinguish owner and opponent entries.');
$html = $panel->render($state, GameState::PLAYER_PLAYER, 'player', $cardsInfo, '/battle?game=9301');
tisAssert(($state->battle['turn_instant_stack']['priority'] ?? null) === GameState::PLAYER_HOST, 'Priority should stay with declarer after ordering an instant.');
tisAssert(str_contains($html, 'Ожидание выбора оппонента'), 'Opponent should see waiting UI while Host keeps priority.');

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

// Oyuun-style multiple declarations keep priority but still obey uses_per_turn.
$oyuun = tisCard([
    'instanceId' => 6,
    'ukid' => 'oyuun_limit',
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['instants' => [[
        'key' => 'battle_frenzy',
        'name' => 'Боевое исступление',
        'trigger' => 'turn',
        'target' => 'ally',
        'uses_per_turn' => 2,
        'effect' => ['type' => 'open', 'condition' => 'target_closed', 'damage' => 1],
    ]]],
]);
$allyA = tisCard(['instanceId' => 7, 'owner' => GameState::PLAYER_HOST, 'col' => 4, 'closed' => true]);
$allyB = tisCard(['instanceId' => 8, 'owner' => GameState::PLAYER_HOST, 'col' => 5, 'closed' => true]);
$state = tisState($oyuun, $allyA, $allyB);
$ip = new InstantProcessor($state, new Engine());
$ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'manual'], 'turn');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 6, 'instant_key' => 'battle_frenzy']))->success, 'Oyuun first declaration should open picker.');
tisAssert($ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 7]))->success, 'Oyuun first target should stack.');
tisAssert(($state->battle['turn_instant_stack']['priority'] ?? null) === GameState::PLAYER_HOST, 'Oyuun should retain priority after first target.');
$second = $ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 6, 'instant_key' => 'battle_frenzy']));
tisAssert(!$second->success, 'Same Oyuun cannot be declared again while already in stack.');
tisAssert((int) ($oyuun->flags['instant_uses_this_turn']['battle_frenzy'] ?? 0) === 1, 'Rejected stacked Oyuun should not spend another use.');

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
$ip->passTurnInstant(GameState::PLAYER_HOST);
tisAssert($target->hp === 3, 'Targeted instant should apply during LIFO resolution.');
tisAssert(!empty($state->battle['turn_instant_result']), 'Targeted stack should show result before OK.');
tisAckTurnResult($state);

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
$ip->passTurnInstant(GameState::PLAYER_HOST);
$summary = $state->battle['instant_result']['summary'] ?? [];
tisAssert(($summary[0]['applied'] ?? true) === false, 'Invalid target should resolve as no-op.');
tisAssert(($summary[0]['reason'] ?? '') !== '', 'No-op should keep a reason.');
tisAssert($source->closed, 'No-op resolved instant should still close source.');
tisAssert(empty($source->flags['in_stack']), 'No-op resolved instant should clear in_stack.');
tisAssert($source->coins === 0, 'No-op should not refund reserved cost.');
tisAssert((int) ($source->flags['instant_uses_this_turn']['paid_zap'] ?? 0) === 1, 'No-op should not roll back uses_per_turn.');
tisAssert(!empty($state->battle['turn_instant_result']), 'No-op stack should still show result before OK.');
tisAckTurnResult($state);

// Lack of current usefulness must not block Chronos declaration; Oyuun can create the state before LIFO reaches it.
$chronos = tisCard([
    'instanceId' => 40,
    'ukid' => 'chronos',
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['instants' => [tisInstant('time_return', 'Возврат во времени', ['type' => 'heal_turn_wounds'], 'ally')]],
]);
$oyuun = tisCard([
    'instanceId' => 41,
    'ukid' => 'oyuun',
    'owner' => GameState::PLAYER_HOST,
    'col' => 4,
    'prop' => ['instants' => [tisInstant('battle_frenzy', 'Боевое исступление', ['type' => 'open', 'condition' => 'target_closed', 'damage' => 1], 'ally', 2)]],
]);
$ally = tisCard([
    'instanceId' => 42,
    'ukid' => 'ally',
    'owner' => GameState::PLAYER_HOST,
    'col' => 5,
    'hp' => 5,
    'hpMax' => 5,
    'closed' => true,
]);
$state = tisState($chronos, $oyuun, $ally);
$ip = new InstantProcessor($state, new Engine());
$instants = $ip->getInstants(GameState::PLAYER_HOST, 'turn', 'turn');
tisAssert(in_array('time_return', array_map(fn($i) => (string) (($i['payload']['key'] ?? '')), $instants), true), 'Chronos should be declaration-legal with no current turn wounds.');
$ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'manual'], 'turn');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 40, 'instant_key' => 'time_return']))->success, 'Chronos declaration should open target picker.');
tisAssert($ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 42]))->success, 'Chronos should stack even before the target has turn wounds.');
tisAssert(($state->battle['turn_instant_stack']['priority'] ?? null) === GameState::PLAYER_HOST, 'Chronos declaration should keep Host priority.');
tisAssert(($state->battle['turn_instant_stack']['priority'] ?? null) === GameState::PLAYER_HOST, 'Chronos should prevent premature auto-resolution while Host still has Oyuun.');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 41, 'instant_key' => 'battle_frenzy']))->success, 'Oyuun declaration should open target picker above Chronos.');
tisAssert($ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 42]))->success, 'Oyuun should stack above Chronos.');
tisAssert($ip->passTurnInstant(GameState::PLAYER_HOST)->success, 'Host pass should auto-finish when opponent has no declarations.');
$summary = $state->battle['instant_result']['summary'] ?? [];
tisAssert(array_column($summary, 'label') === ['Боевое исступление', 'Возврат во времени'], 'Chronos combo should resolve in LIFO order.');
tisAssert($ally->hp === 5, 'Chronos should heal the turn wound created by Oyuun above it.');
tisAssert(!empty($state->battle['turn_instant_result']), 'Chronos combo should show result before continuation.');
tisAckTurnResult($state);

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
$ip->passTurnInstant(GameState::PLAYER_PLAYER);
tisAssert($victim->hp === 4, 'Bone should resolve through the common turn stack.');
tisAssert(!empty($state->battle['turn_instant_result']), 'Turn phase instant should wait on result before continuation.');
tisAssert(!empty($state->battle['turn_phase']['sub']['pending_id']), 'TurnPhaseProcessor should stay suspended until result OK.');
tisAckTurnResult($state);
tisAssert(empty($state->battle['turn_phase']['sub']['pending_id']), 'Resolved instant task should be removed from pending slot.');
tisAssert(count($state->battle['turn_phase']['sub']['remaining'] ?? []) === 1, 'Remaining start-phase tasks should not be lost.');
tisAssert(($state->battle['turn_phase']['sub']['remaining'][0]['id'] ?? '') === 'dummy_remaining', 'Start phase should continue from the existing remaining task.');

// Empty stack should not create an empty result screen.
$solo = tisCard([
    'instanceId' => 50,
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['instants' => [tisInstant('solo', 'Solo', ['type' => 'damage', 'value' => 1])]],
]);
$state = tisState($solo);
$ip = new InstantProcessor($state, new Engine());
tisAssert($ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'manual'], 'turn'), 'Window with a legal instant should open.');
tisAssert($ip->passTurnInstant(GameState::PLAYER_HOST)->success, 'Passing an empty stack should succeed.');
tisAssert(empty($state->battle['turn_instant_stack']), 'Empty stack pass should close ordering.');
tisAssert(empty($state->battle['turn_instant_result']), 'Empty stack should not create a result screen.');

// A declaration-legal but currently no-op Chronos prevents implicit auto-pass.
$host = tisCard([
    'instanceId' => 60,
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['instants' => [tisInstant('host', 'Host', ['type' => 'damage', 'value' => 1])]],
]);
$chronos = tisCard([
    'instanceId' => 61,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'prop' => ['instants' => [tisInstant('time_return', 'Возврат во времени', ['type' => 'heal_turn_wounds'], 'ally')]],
]);
$ally = tisCard(['instanceId' => 62, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$state = tisState($host, $chronos, $ally);
$ip = new InstantProcessor($state, new Engine());
tisAssert($ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'manual'], 'turn'), 'Window should open with Host and Chronos options.');
tisAssert($ip->passTurnInstant(GameState::PLAYER_HOST)->success, 'Host pass should hand priority to Player.');
tisAssert(!empty($state->battle['turn_instant_stack']), 'Chronos declaration legality should prevent implicit auto-pass.');
tisAssert(($state->battle['turn_instant_stack']['priority'] ?? null) === GameState::PLAYER_PLAYER, 'Player should keep priority with declaration-legal Chronos.');

// strike_before continuation waits until result OK.
$source = tisCard([
    'instanceId' => 70,
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['instants' => [tisInstant('pulse', 'Pulse', ['type' => 'damage', 'value' => 1])]],
]);
$state = tisState($source);
$state->battle['strike'] = [
    'state' => 'paused_before',
    'redirect_candidates' => [70],
];
$ip = new InstantProcessor($state, new Engine());
$ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'strike_before'], 'before');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 70, 'instant_key' => 'pulse']))->success, 'strike_before instant should stack.');
tisAssert($ip->passTurnInstant(GameState::PLAYER_HOST)->success, 'strike_before pass should resolve with implicit second pass.');
tisAssert(($state->battle['strike']['state'] ?? '') === 'paused_before', 'strike_before continuation must not run before result OK.');
tisAckTurnResult($state);
tisAssert(($state->battle['strike']['state'] ?? '') === 'waiting_redirect', 'strike_before continuation should run after result OK.');

// strike_after continuation waits until result OK and double OK is harmless.
$source = tisCard([
    'instanceId' => 80,
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['instants' => [tisInstant('after', 'After', ['type' => 'damage', 'value' => 1])]],
]);
$state = tisState($source);
$state->battle['strike'] = ['state' => 'results'];
$ip = new InstantProcessor($state, new Engine());
$ip->openTurnStackWindow(GameState::PLAYER_HOST, ['type' => 'strike_after'], 'after');
tisAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 80, 'instant_key' => 'after']))->success, 'strike_after instant should stack.');
tisAssert($ip->passTurnInstant(GameState::PLAYER_HOST)->success, 'strike_after pass should resolve with implicit second pass.');
tisAssert(!empty($state->battle['strike']), 'strike_after continuation must not clear strike before result OK.');
tisAckTurnResult($state);
tisAssert(empty($state->battle['strike']), 'strike_after continuation should clear strike after result OK.');
$again = (new InstantProcessor($state, new Engine()))->ackTurnInstantResult(GameState::PLAYER_HOST);
tisAssert(!$again->success, 'Second result OK must not repeat continuation.');

echo "Turn instant stack tests passed.\n";
