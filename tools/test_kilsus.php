<?php
// tools/test_kilsus.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\Command;
use Berserk\Core\Dice;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;
use Berserk\Core\TurnProcessor;
use Berserk\Core\Choice\ChoiceRegistry;
use Berserk\Core\Movement\MovementResolver;
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function kilsusProp(): array
{
    return [
        'ova' => ['value' => 1],
        'execute' => 0,
        'after_strike_execute_limit' => 2,
        'on_move' => [[
            'stat' => 'execute',
            'type' => 'modifier',
            'value' => 1,
            'expire' => 'end_of_turn',
        ]],
        'block_strike_answer' => true,
    ];
}

function kilsusState(CardInstance ...$cards): GameState
{
    $state = new GameState(18, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
        'hidden_row_revealed' => true,
    ];

    foreach ($cards as $card) {
        $state->addCard($card);
    }

    return $state;
}

function kilsusCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'] ?? 1,
        ukid: $overrides['ukid'] ?? 's1_18',
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 11,
        hpMax: $overrides['hpMax'] ?? 11,
        move: $overrides['move'] ?? 3,
        moveMax: $overrides['moveMax'] ?? 3,
        strikeWeak: $overrides['strikeWeak'] ?? 2,
        strikeMedium: $overrides['strikeMedium'] ?? 3,
        strikeStrong: $overrides['strikeStrong'] ?? 4,
        prop: $overrides['prop'] ?? kilsusProp(),
        flags: $overrides['flags'] ?? [],
        modifiers: $overrides['modifiers'] ?? [],
        closed: $overrides['closed'] ?? false,
    );
}

function enemyCard(int $id, int $row, int $col, int $hp = 12, array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $id,
        ukid: $overrides['ukid'] ?? ('enemy_' . $id),
        owner: $overrides['owner'] ?? GameState::PLAYER_PLAYER,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $row,
        col: $col,
        hp: $hp,
        hpMax: $overrides['hpMax'] ?? $hp,
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        strikeWeak: $overrides['strikeWeak'] ?? 1,
        strikeMedium: $overrides['strikeMedium'] ?? 2,
        strikeStrong: $overrides['strikeStrong'] ?? 3,
        prop: $overrides['prop'] ?? [],
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
    );
}

function moveKilsus(GameState $state, int $row, int $col): void
{
    $result = (new MovementResolver($state, new Engine()))->move(
        GameState::PLAYER_HOST,
        new Command('move', ['card_id' => 1, 'row' => $row, 'col' => $col])
    );
    assertTrue($result->success, $result->error ?? 'Kilsus move failed.');
}

function strikeAndConfirm(GameState $state, int $targetId): void
{
    $_SESSION['debug_roll'] = '6,1';
    unset($_SESSION['debug_roll_state']);
    Dice::init();

    $resolver = new StrikeResolver($state, new Engine());
    $result = $resolver->declare(GameState::PLAYER_HOST, new Command('strike', [
        'card_id' => 1,
        'target_id' => $targetId,
    ]));
    assertTrue($result->success, $result->error ?? 'Kilsus strike failed.');

    if (($state->battle['strike']['state'] ?? '') === 'waiting_defender') {
        $result = $resolver->chooseDefender(GameState::PLAYER_PLAYER, new Command('choose_defender', ['defender_id' => 0]));
        assertTrue($result->success, $result->error ?? 'Defender choice failed.');
    }

    $result = $resolver->confirmStrike(GameState::PLAYER_HOST, new Command('confirm_strike'));
    assertTrue($result->success, $result->error ?? 'Host confirm failed.');

    $result = $resolver->confirmStrike(GameState::PLAYER_PLAYER, new Command('confirm_strike'));
    assertTrue($result->success, $result->error ?? 'Player confirm failed.');
}

function clearStrikeResult(GameState $state): void
{
    if (empty($state->battle['strike']) || ($state->battle['strike']['state'] ?? '') !== 'results') {
        return;
    }

    $resolver = new StrikeResolver($state, new Engine());
    foreach ([GameState::PLAYER_HOST, GameState::PLAYER_PLAYER] as $playerKey) {
        if (empty($state->battle['strike'])) return;
        $confirmed = $state->battle['strike']['confirmed'] ?? [];
        if (in_array($playerKey, $confirmed, true)) continue;
        $result = $resolver->confirmStrike($playerKey, new Command('confirm_strike'));
        assertTrue($result->success, $result->error ?? 'Strike clear failed.');
    }
}

foreach ([0, 1, 2, 3] as $steps) {
    $kilsus = kilsusCard(['move' => 3, 'moveMax' => 3]);
    $state = kilsusState($kilsus, enemyCard(2, 4, 3, 12));

    $path = [[3, 4], [3, 5], [2, 5]];
    for ($i = 0; $i < $steps; $i++) {
        moveKilsus($state, $path[$i][0], $path[$i][1]);
    }

    assertTrue(
        CardStats::getStat($kilsus, 'execute') === $steps,
        "Kilsus execute should be {$steps} after {$steps} adjacent moves."
    );
}

$state = kilsusState(
    kilsusCard(['move' => 3, 'moveMax' => 3]),
    enemyCard(2, 4, 3, 12),
    enemyCard(3, 5, 5, 12)
);
moveKilsus($state, 3, 4);
moveKilsus($state, 3, 5);
moveKilsus($state, 2, 5);
assertTrue(CardStats::getStat($state->getCard(1), 'execute') === 3, 'Extra movement should accumulate execute 3.');

$state = kilsusState(kilsusCard(), enemyCard(2, 4, 3, 20));
strikeAndConfirm($state, 2);
assertTrue(empty($state->battle['pending_after_strike_execute']), 'Kilsus without movement should not open execute pending.');
assertTrue(empty($state->battle['strike']), 'Kilsus without movement should not auto execute.');

$state = kilsusState(kilsusCard(), enemyCard(2, 4, 3, 20), enemyCard(3, 5, 5, 5));
moveKilsus($state, 3, 4);
strikeAndConfirm($state, 2);
assertTrue(empty($state->battle['pending_after_strike_execute']), 'No legal execute targets should avoid pending.');
assertTrue(empty($state->battle['strike']), 'No legal execute targets should not create execute result.');

$state = kilsusState(kilsusCard(), enemyCard(2, 4, 3, 20), enemyCard(3, 4, 5, 1));
moveKilsus($state, 3, 4);
strikeAndConfirm($state, 2);
assertTrue(($state->battle['strike']['kind'] ?? null) === 'execute', 'Single legal target should be executed automatically.');
assertTrue($state->getCard(3)->dying, 'Auto execute should mark the target dying.');
assertTrue(($state->getCard(1)->flags['after_strike_execute_used_this_turn'] ?? 0) === 1, 'Auto execute should spend one trigger.');

$state = kilsusState(kilsusCard(), enemyCard(2, 4, 3, 20), enemyCard(3, 4, 5, 1), enemyCard(4, 3, 5, 1));
moveKilsus($state, 3, 4);
strikeAndConfirm($state, 2);
$pending = $state->battle['pending_after_strike_execute'] ?? null;
assertTrue(is_array($pending), 'Multiple legal targets should open mandatory execute pending.');
assertTrue(($pending['value'] ?? null) === 1, 'Pending should store actual execute value.');
assertTrue(ChoiceRegistry::current($state)?->pendingKey() === 'pending_after_strike_execute', 'ChoiceRegistry should route Kilsus pending.');
$spec = ChoiceRegistry::current($state)->spec($state, GameState::PLAYER_HOST, [
    's1_18' => ['name' => 'Килсус'],
    'enemy_3' => ['name' => 'Раненый 1'],
    'enemy_4' => ['name' => 'Раненый 2'],
], '/battle?game=18&first=', 'host');
assertTrue($spec !== null && str_contains($spec->title, 'Килсус: выберите цель добивания на 1'), 'InfoPanel title should name Kilsus execute value.');
assertTrue(($spec->buttons[0]['label'] ?? '') === 'Чужой: Раненый 1', 'Pending target label should include ownership.');
assertTrue($spec->form === null, 'Mandatory execute choice should not render a cancellable form.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_after_strike_execute', ['target_id' => 3]));
assertTrue($result->success, $result->error ?? 'Choosing Kilsus execute target should succeed.');
assertTrue($state->getCard(3)->dying, 'Chosen target should be executed.');

// Protected neighbors must not create a mandatory choice or consume the trigger.
$state = kilsusState(kilsusCard(), enemyCard(2, 4, 3, 20), enemyCard(3, 4, 5, 1, ['prop' => ['stamina' => true]]));
moveKilsus($state, 3, 4);
strikeAndConfirm($state, 2);
assertTrue(empty($state->battle['pending_after_strike_execute']) && empty($state->battle['strike']), 'Stamina-only target should finish combat normally.');
assertTrue(!$state->getCard(3)->dying, 'Stamina must prevent automatic execution.');
assertTrue(empty($state->getCard(1)->flags['after_strike_execute_used_this_turn']), 'Blocked execution must not consume a trigger.');

// Both ordinary and protected wounded cards exist: only ordinary cards enter pending.
$state = kilsusState(kilsusCard(), enemyCard(2, 4, 3, 20), enemyCard(3, 4, 5, 1), enemyCard(4, 3, 5, 1), enemyCard(5, 2, 4, 1, ['prop' => ['stamina' => true]]));
moveKilsus($state, 3, 4);
strikeAndConfirm($state, 2);
assertTrue(($state->battle['pending_after_strike_execute']['candidate_ids'] ?? []) === [3, 4], 'Stamina target must be excluded from pending candidates.');
$state->getCard(3)->prop['stamina'] = true;
$choice = ChoiceRegistry::current($state);
$spec = $choice->spec($state, GameState::PLAYER_HOST, [], '/battle?game=18&first=', 'host');
assertTrue(count($spec->buttons) === 1 && str_contains($spec->buttons[0]['url'], 'target_id=4'), 'Newly protected target must disappear from pending UI.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_after_strike_execute', ['target_id' => 3]));
assertTrue(!$result->success && !$state->getCard(3)->dying, 'Newly protected pending target must be rejected.');
assertTrue(empty($state->getCard(1)->flags['after_strike_execute_used_this_turn']), 'Rejected pending target must not consume a trigger.');

$kilsus = kilsusCard([
    'move' => 0,
    'moveMax' => 0,
    'prop' => kilsusProp() + ['attacks_per_turn' => 3, 'no_close_after_attack' => true],
    'modifiers' => [['stat' => 'execute', 'value' => 1, 'expire' => 'end_of_turn']],
]);
$state = kilsusState(
    $kilsus,
    enemyCard(2, 4, 3, 20),
    enemyCard(3, 3, 2, 1),
    enemyCard(4, 4, 2, 5),
    enemyCard(5, 4, 4, 5)
);
strikeAndConfirm($state, 2);
assertTrue(($state->battle['strike']['kind'] ?? null) === 'execute', 'First execute in turn should fire.');
clearStrikeResult($state);
$state->getCard(4)->hp = 1;
$state->getCard(1)->closed = false;
strikeAndConfirm($state, 2);
assertTrue(($state->battle['strike']['kind'] ?? null) === 'execute', 'Second execute in turn should fire.');
clearStrikeResult($state);
$state->getCard(5)->hp = 1;
$state->getCard(1)->closed = false;
strikeAndConfirm($state, 2);
assertTrue(empty($state->battle['strike']) && empty($state->battle['pending_after_strike_execute']), 'Third strike in same turn should not trigger execute.');

(new TurnProcessor($state, new Engine()))->continueStartTurn(GameState::PLAYER_HOST);
assertTrue(($state->getCard(1)->flags['after_strike_execute_used_this_turn'] ?? -1) === 0, 'New owner turn should reset execute trigger limit.');

$state = kilsusState(kilsusCard(), enemyCard(2, 4, 3, 20));
$engine = new Engine();
$kilsus = $state->getCard(1);
$enemy = $state->getCard(2);
$engine->applyDamage($state, $kilsus, 2, 'strike', $enemy);
assertTrue($kilsus->hp === 9, 'Normal strike damage should wound Kilsus.');
$engine->applyDamage($state, $kilsus, 2, 'answer', $enemy);
assertTrue($kilsus->hp === 9, 'Answer strike damage should be blocked by block_strike_answer.');

$state = kilsusState(kilsusCard(), enemyCard(2, 4, 3, 20, ['prop' => ['ovz' => ['value' => 2]]]));
$_SESSION['debug_roll'] = '6,1';
unset($_SESSION['debug_roll_state']);
Dice::init();
$result = (new StrikeResolver($state, new Engine()))->declare(GameState::PLAYER_HOST, new Command('strike', [
    'card_id' => 1,
    'target_id' => 2,
]));
assertTrue($result->success, $result->error ?? 'Kilsus strike should be declared.');
assertTrue(($state->battle['strike']['defend_dice'] ?? null) === 1, 'block_strike_answer should not make Kilsus unanswerable.');
assertTrue(CardStats::getOva($state, $state->getCard(1), $state->getCard(2)) === 1, 'Kilsus OVA 1 should be active.');
$result = (new StrikeResolver($state, new Engine()))->chooseStrikeMode(GameState::PLAYER_HOST, new Command('choose_strike_mode', [
    'mode' => 'normal',
]));
assertTrue($result->success, $result->error ?? 'Kilsus strike mode choice should apply.');
assertTrue(($state->battle['strike']['defend_damage_total'] ?? null) === 0, 'Blocked answer strike should report zero actual damage.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    [
        's1_18' => ['name' => 'Килсус'],
        'enemy_2' => ['name' => 'Защитник'],
    ],
    '/battle?game=18&first='
);
assertTrue(str_contains($html, 'Килсус'), 'InfoPanel should name Kilsus in answer block message.');
assertTrue(str_contains($html, 'ответный удар заблокирован'), 'InfoPanel should show answer strike block message.');

echo "Kilsus regression tests passed.\n";
