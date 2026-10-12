<?php
// tools/test_battle_cancel_action_ui.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\GameState;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function cancelActionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cancelActionCard(array $o): CardInstance
{
    $card = new CardInstance(
        instanceId: $o['instanceId'],
        ukid: $o['ukid'],
        owner: $o['owner'],
        zone: CardInstance::ZONE_FIELD,
        row: $o['row'],
        col: $o['col'],
        hp: $o['hp'] ?? 5,
        hpMax: $o['hpMax'] ?? 5,
        closed: $o['closed'] ?? false,
        revealed: $o['revealed'] ?? true,
        element: $o['element'] ?? 'neutral',
        move: 1,
        moveMax: 1,
        strikeWeak: 1,
        strikeMedium: 2,
        strikeStrong: 3,
        armor: 0,
        coins: $o['coins'] ?? 0,
    );
    $card->prop = $o['prop'] ?? [];

    return $card;
}

function cancelActionState(array $attackerProp = [], bool $withEnemy = true): GameState
{
    $state = new GameState(190, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
    ];

    $state->addCard(cancelActionCard([
        'instanceId' => 1,
        'ukid' => 'attacker',
        'owner' => GameState::PLAYER_HOST,
        'row' => 3,
        'col' => 3,
        'prop' => $attackerProp,
    ]));

    if ($withEnemy) {
        $state->addCard(cancelActionCard([
            'instanceId' => 2,
            'ukid' => 'enemy',
            'owner' => GameState::PLAYER_PLAYER,
            'row' => 3,
            'col' => 4,
        ]));
    }

    return $state;
}

function cancelActionRender(GameState $state, array $get): array
{
    $_GET = $get;
    $_SESSION = [];

    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));
    return $screen->prepare($state, GameState::PLAYER_HOST, 'host', null, [
        'attacker' => ['name' => 'Атакующий', 'health' => 5, 'element' => 'neutral'],
        'enemy' => ['name' => 'Цель', 'health' => 5, 'element' => 'neutral'],
    ])['data'];
}

$strikeHtml = cancelActionRender(cancelActionState(), ['sel' => 1, 'mode' => 'strike']);
cancelActionAssert(
    str_contains($strikeHtml['panel_html'], 'Отменить действие'),
    'Explicit strike target mode should render cancel action.'
);
cancelActionAssert(
    preg_match('/href="([^"]*)">Отменить действие<\/a>/', $strikeHtml['panel_html'], $matches) === 1,
    'Cancel action should be rendered as a link.'
);
cancelActionAssert(
    $matches[1] === '?first&game=190',
    'Cancel action should link to the clean battle URL without selected card or mode.'
);
cancelActionAssert(
    str_contains($strikeHtml['field_html'], 'cmd=strike'),
    'Target highlight links should remain available while cancel action is shown.'
);

$defaultHtml = cancelActionRender(cancelActionState(), ['sel' => 1]);
cancelActionAssert(
    !str_contains($defaultHtml['panel_html'], 'Отменить действие'),
    'Selecting a card without explicit target mode should not render cancel action.'
);

$uchrState = cancelActionState(['actions' => [['type' => 'uchr']]], false);
$uchrState->addCard(cancelActionCard([
    'instanceId' => 2,
    'ukid' => 'enemy',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 4,
]));
$uchrState->addCard(cancelActionCard([
    'instanceId' => 3,
    'ukid' => 'enemy',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 5,
]));
$uchrHtml = cancelActionRender($uchrState, ['sel' => 1, 'mode' => 'uchr']);
cancelActionAssert(
    str_contains($uchrHtml['panel_html'], 'Отменить действие'),
    'UCHR target mode should render cancel action.'
);

$actionState = cancelActionState([
    'actions' => [
        ['type' => 'shot', 'key' => 'shot'],
    ],
]);
$actionHtml = cancelActionRender($actionState, ['sel' => 1, 'mode' => 'action:shot']);
cancelActionAssert(
    str_contains($actionHtml['panel_html'], 'Отменить действие'),
    'Targeted action mode should render cancel action.'
);

$noTargetsHtml = cancelActionRender(
    cancelActionState(['actions' => [['type' => 'shot', 'key' => 'shot']]], false),
    ['sel' => 1, 'mode' => 'action:shot']
);
cancelActionAssert(
    str_contains($noTargetsHtml['panel_html'], 'Отменить действие')
        && str_contains($noTargetsHtml['panel_html'], 'Нет целей'),
    'Targeted action mode without available targets should still allow canceling selection.'
);

$teleportHtml = cancelActionRender(
    cancelActionState(['actions' => [['type' => 'teleport_target', 'key' => 'gate']]]),
    ['sel' => 1, 'mode' => 'action:gate']
);
cancelActionAssert(
    str_contains($teleportHtml['panel_html'], 'Отменить действие'),
    'Actions with preliminary card target selection should render cancel action.'
);

$pendingState = cancelActionState(['actions' => [['type' => 'dive', 'key' => 'dive']]]);
$pendingState->battle['pending_dive'] = [
    'owner' => GameState::PLAYER_HOST,
    'attacker_id' => 1,
    'target_id' => 2,
    'action' => ['type' => 'dive', 'key' => 'dive'],
    'cells' => [['row' => 2, 'col' => 4]],
];
$pendingHtml = cancelActionRender($pendingState, ['sel' => 1, 'mode' => 'action:dive']);
cancelActionAssert(
    !str_contains($pendingHtml['panel_html'], 'Отменить действие'),
    'Cancel target selection should not be shown while a server-side pending choice is active.'
);

$registryPendingState = cancelActionState(['actions' => [['type' => 'shot', 'key' => 'shot']]]);
$registryPendingState->battle['pending_multi_discharge'] = [
    'attacker_id' => 1,
    'action' => ['type' => 'multi_discharge', 'key' => 'triple'],
    'candidates' => [2],
    'max_targets' => 3,
    'value' => 1,
];
$registryPendingHtml = cancelActionRender($registryPendingState, ['sel' => 1, 'mode' => 'action:shot']);
cancelActionAssert(
    !str_contains($registryPendingHtml['panel_html'], 'Отменить действие'),
    'Cancel target selection should not be shown while a ChoiceRegistry pending is active.'
);

$bloodStormHtml = cancelActionRender(
    cancelActionState(['actions' => [['type' => 'blood_storm', 'key' => 'storm']]]),
    ['sel' => 1]
);
cancelActionAssert(
    str_contains($bloodStormHtml['panel_html'], 'cmd=action')
        && str_contains($bloodStormHtml['panel_html'], 'action_key=storm')
        && !str_contains($bloodStormHtml['panel_html'], 'Отменить действие'),
    'Blood storm should be rendered as an immediate action command, not a target-selection mode.'
);

$immediateHtml = cancelActionRender(
    cancelActionState(['actions' => [['type' => 'become_fly', 'key' => 'become_fly']]]),
    ['sel' => 1, 'mode' => 'action:become_fly']
);
cancelActionAssert(
    !str_contains($immediateHtml['panel_html'], 'Отменить действие'),
    'Immediate actions should not render cancel target selection action.'
);

echo "Battle cancel action UI tests passed.\n";
