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
        ['key' => 'shot', 'type' => 'shot', 'name' => 'Выстрел', 'value' => 1, 'range' => 6],
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

echo "Friendly fire tests passed.\n";
