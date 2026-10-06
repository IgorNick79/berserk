<?php
// tools/test_friendly_fire.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\BattleHelper;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\InstantProcessor;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function friendlyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function friendlyCard(
    int $id,
    string $ukid,
    string $owner,
    int $row,
    int $col,
    array $prop = [],
): CardInstance {
    return new CardInstance(
        instanceId: $id,
        ukid: $ukid,
        owner: $owner,
        zone: CardInstance::ZONE_FIELD,
        row: $row,
        col: $col,
        hp: 3,
        hpMax: 3,
        move: 1,
        moveMax: 1,
        strikeWeak: 1,
        strikeMedium: 1,
        strikeStrong: 1,
        prop: $prop,
    );
}

function friendlyState(array $attackerProp = []): GameState
{
    $state = new GameState(91, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
    ];

    $state->addCard(friendlyCard(1, 'attacker', GameState::PLAYER_HOST, 3, 3, $attackerProp));
    $state->addCard(friendlyCard(2, 'enemy', GameState::PLAYER_PLAYER, 3, 4));
    $state->addCard(friendlyCard(3, 'ally_near', GameState::PLAYER_HOST, 3, 2));
    $state->addCard(friendlyCard(4, 'ally_far', GameState::PLAYER_HOST, 6, 5));

    return $state;
}

function friendlyCombatInstantProp(): array
{
    return [
        'instants' => [[
            'key' => 'guard_flash',
            'name' => 'Сторожевой отблеск',
            'trigger' => 'combat',
            'target' => 'self',
            'combat_phase' => 'value',
            'effect' => ['type' => 'damage_cap', 'value' => 1],
        ]],
    ];
}

function friendlyPrepareStrike(GameState $state, bool $friendlyFire): void
{
    $state->battle['strike'] = [
        'attacker_id' => 1,
        'target_id' => $friendlyFire ? 3 : 2,
        'defender_id' => null,
        'state' => 'resolving',
        'attack_dice' => 2,
        'defend_dice' => $friendlyFire ? 0 : 1,
        'attack_mod' => 0,
        'defend_mod' => 0,
        'result' => ['attack' => 'weak', 'defend' => '', 'winner' => 'attack'],
        'confirmed' => [],
        'defenders' => [],
        'redirect_candidates' => [],
        'friendly_fire' => $friendlyFire,
    ];
}

function friendlyRender(GameState $state, array $query): array
{
    $_GET = $query;
    $_SESSION = [];
    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));

    return $screen->prepare($state, GameState::PLAYER_HOST, 'host', null, [
        'attacker' => ['name' => 'Атакующий', 'health' => 3],
        'enemy' => ['name' => 'Враг', 'health' => 3],
        'ally_near' => ['name' => 'Свой "рядовой"', 'health' => 3],
        'ally_far' => ['name' => 'Дальний союзник', 'health' => 3],
    ])['data'];
}

$state = friendlyState();

$targetsWithoutMode = BattleHelper::getAttackTargets(
    $state,
    $state->getCard(1),
    'strike',
    GameState::PLAYER_HOST,
    false
);
friendlyAssert(isset($targetsWithoutMode[2]), 'Enemy strike target should remain available without friendly-fire mode.');
friendlyAssert(!isset($targetsWithoutMode[3]), 'Friendly strike target should be hidden without explicit friendly-fire mode.');

$targetsWithMode = BattleHelper::getAttackTargets(
    $state,
    $state->getCard(1),
    'strike',
    GameState::PLAYER_HOST,
    true
);
friendlyAssert(isset($targetsWithMode[3]), 'Adjacent friendly strike target should be available with explicit mode.');
friendlyAssert(!isset($targetsWithMode[1]), 'Self-targeting should never be available.');

$htmlWithoutMode = friendlyRender($state, ['sel' => 1]);
friendlyAssert(
    !str_contains($htmlWithoutMode['field_html'], 'friendly-attack-target'),
    'Battle UI should not show friendly targets from plain selection.'
);
friendlyAssert(
    !str_contains($htmlWithoutMode['field_html'], 'cmd=strike&card_id=1&target_id=3'),
    'Plain selection should not produce a friendly strike link.'
);

$htmlWithMode = friendlyRender($state, ['sel' => 1, 'mode' => 'strike']);
friendlyAssert(
    str_contains($htmlWithMode['field_html'], 'friendly-attack-target'),
    'Explicit strike mode should mark friendly target with friendly class.'
);
friendlyAssert(
    str_contains($htmlWithMode['field_html'], 'cmd=strike&card_id=1&target_id=3'),
    'Explicit strike mode should produce a friendly strike link.'
);
friendlyAssert(
    str_contains($htmlWithMode['field_html'], 'onclick="return confirm(')
        && str_contains($htmlWithMode['field_html'], 'Атаковать свою карту'),
    'Friendly target link should require browser confirmation.'
);

$strikeState = friendlyState();
$result = (new Engine())->apply($strikeState, GameState::PLAYER_HOST, new Command('strike', [
    'card_id' => 1,
    'target_id' => 3,
]));
friendlyAssert($result->success, $result->error ?? 'Friendly strike should be accepted when target is valid.');
friendlyAssert(($strikeState->battle['strike']['friendly_fire'] ?? false) === true, 'Friendly strike should be marked in strike state.');
friendlyAssert(($strikeState->battle['strike']['defend_dice'] ?? null) === 0, 'Friendly strike should be unanswered.');
friendlyAssert(empty($strikeState->battle['strike']['defenders'] ?? []), 'Friendly strike should not offer defenders.');

$farStrikeState = friendlyState();
$farResult = (new Engine())->apply($farStrikeState, GameState::PLAYER_HOST, new Command('strike', [
    'card_id' => 1,
    'target_id' => 4,
]));
friendlyAssert(!$farResult->success, 'Out-of-range friendly strike should be rejected.');

$actionProp = [
    'actions' => [
        ['key' => 'shot', 'type' => 'shot', 'name' => 'Выстрел', 'value' => 1, 'range' => 6, 'near_shot' => true],
    ],
];
$actionState = friendlyState($actionProp);
$actionTargets = BattleHelper::getAttackTargets(
    $actionState,
    $actionState->getCard(1),
    'action:shot',
    GameState::PLAYER_HOST,
    true
);
friendlyAssert(isset($actionTargets[4]), 'Explicit offensive action mode should include in-range friendly targets.');

$actionResult = (new Engine())->apply($actionState, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'shot',
    'target_id' => 4,
]));
friendlyAssert($actionResult->success, $actionResult->error ?? 'Friendly ranged action should be accepted.');
friendlyAssert(($actionState->battle['strike']['defend_dice'] ?? null) === 0, 'Friendly ranged action should remain one-die only.');
friendlyAssert(($actionState->getCard(4)?->hp ?? 0) === 2, 'Friendly ranged action should apply damage to the selected ally.');

$shortRangeProp = [
    'actions' => [
        ['key' => 'shot', 'type' => 'shot', 'name' => 'Короткий выстрел', 'value' => 1, 'range' => 2],
    ],
];
$forgedRangeState = friendlyState($shortRangeProp);
$forgedRangeTargets = BattleHelper::getAttackTargets(
    $forgedRangeState,
    $forgedRangeState->getCard(1),
    'action:shot',
    GameState::PLAYER_HOST,
    true
);
friendlyAssert(!isset($forgedRangeTargets[4]), 'Out-of-range friendly action target should be absent from authoritative targets.');

$forgedRangeResult = (new Engine())->apply($forgedRangeState, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'shot',
    'target_id' => 4,
]));
friendlyAssert(!$forgedRangeResult->success, 'Forged direct action against out-of-range ally should be rejected.');
friendlyAssert(($forgedRangeState->getCard(4)?->hp ?? 0) === 3, 'Rejected forged action should not damage ally.');
friendlyAssert(empty($forgedRangeState->battle['strike']), 'Rejected forged action should not create strike state.');

$interceptState = friendlyState($actionProp);
$interceptState->addCard(friendlyCard(5, 'interceptor', GameState::PLAYER_PLAYER, 5, 5, [
    'ranged_intercept' => true,
]));
$interceptTargets = BattleHelper::getAttackTargets(
    $interceptState,
    $interceptState->getCard(1),
    'action:shot',
    GameState::PLAYER_HOST,
    true
);
friendlyAssert(isset($interceptTargets[5]), 'Ranged interceptor should become the authoritative target.');
friendlyAssert(!isset($interceptTargets[4]), 'Friendly target excluded by interceptor rules should not be authoritative.');

$interceptResult = (new Engine())->apply($interceptState, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'shot',
    'target_id' => 4,
]));
friendlyAssert(!$interceptResult->success, 'Forged direct action should not bypass ranged interceptor targeting.');
friendlyAssert(($interceptState->getCard(4)?->hp ?? 0) === 3, 'Rejected interceptor bypass should not damage ally.');

$selfTargetState = friendlyState($actionProp);
$selfTargetResult = (new Engine())->apply($selfTargetState, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'shot',
    'target_id' => 1,
]));
friendlyAssert(!$selfTargetResult->success, 'Attacker should not be able to friendly-fire itself.');

$enemyActionState = friendlyState($actionProp);
$enemyActionResult = (new Engine())->apply($enemyActionState, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'shot',
    'target_id' => 2,
]));
friendlyAssert($enemyActionResult->success, $enemyActionResult->error ?? 'Enemy ranged action should keep existing behavior.');
friendlyAssert(($enemyActionState->getCard(2)?->hp ?? 0) === 2, 'Enemy ranged action should still damage enemy target.');

$friendlyInstantState = friendlyState();
$friendlyInstantState->getCard(4)->prop = friendlyCombatInstantProp();
$friendlyInstantState->getCard(2)->prop = friendlyCombatInstantProp();
friendlyPrepareStrike($friendlyInstantState, true);
(new InstantProcessor($friendlyInstantState, new Engine()))->openWindow('combat', GameState::PLAYER_HOST);
friendlyAssert(
    ($friendlyInstantState->battle['strike']['state'] ?? '') === 'waiting_instant',
    'Friendly strike should open a combat instant window when the attacker side has an instant.'
);
friendlyAssert(
    ($friendlyInstantState->battle['strike']['instant_priority'] ?? null) === GameState::PLAYER_HOST,
    'Friendly strike combat instant priority should stay on the attacker side.'
);
friendlyAssert(
    ($friendlyInstantState->battle['strike']['instant_participants'] ?? []) === [GameState::PLAYER_HOST],
    'Friendly strike combat instant window should include only the attacker side.'
);
$blockedOpponentPass = (new InstantProcessor($friendlyInstantState, new Engine()))->passCombat(GameState::PLAYER_PLAYER);
friendlyAssert(!$blockedOpponentPass->success, 'Opponent should not be able to pass a friendly strike instant window.');
$hostPass = (new InstantProcessor($friendlyInstantState, new Engine()))->passCombat(GameState::PLAYER_HOST);
friendlyAssert($hostPass->success, $hostPass->error ?? 'Attacker side should be able to pass a one-sided instant window.');
friendlyAssert(
    ($friendlyInstantState->battle['strike']['state'] ?? '') !== 'waiting_instant',
    'One-sided friendly strike instant window should resolve after the attacker side passes.'
);

$opponentOnlyInstantState = friendlyState();
$opponentOnlyInstantState->getCard(2)->prop = friendlyCombatInstantProp();
friendlyPrepareStrike($opponentOnlyInstantState, true);
(new InstantProcessor($opponentOnlyInstantState, new Engine()))->openWindow('combat', GameState::PLAYER_HOST);
friendlyAssert(
    ($opponentOnlyInstantState->battle['strike']['state'] ?? '') !== 'waiting_instant',
    'Friendly strike should not open a combat instant window for opponent-only instants.'
);

echo "Friendly fire tests passed.\n";
