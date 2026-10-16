<?php
// tools/test_battle_cancel_action_ui.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
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

function cancelActionRender(GameState $state, array $get, string $viewer = GameState::PLAYER_HOST): array
{
    $_GET = $get;
    $_SESSION = [];

    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));
    return $screen->prepare($state, $viewer, $viewer, null, [
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

// Engine writes logs: run this suite in an isolated copy; lifecycle fixtures use
// negative synthetic IDs so they cannot address an existing database game.
foreach (['host', 'player'] as $owner) {
    $state = cancelActionState(['actions' => [['type'=>'shot','key'=>'shot','value'=>1,'range'=>2]]]);
    $state->gameId = -random_int(1000000000, 2000000000);
    $opponent = $state->getOpponentKey($owner);
    $state->battle['active'] = $owner;
    $state->getCard(1)->owner = $owner;
    $state->getCard(2)->owner = $opponent;
    $state->getCard(2)->col = 5;
    $ui = ['sel'=>1,'mode'=>'action:shot'];
    $engine = new Engine();
    cancelActionAssert(str_contains(cancelActionRender($state,$ui,$owner)['panel_html'],'Отменить действие'), 'Open card must retain cancel before acting: '.$owner);
    cancelActionAssert(!str_contains(cancelActionRender($state,$ui,$opponent)['panel_html'],'Отменить действие'), 'Passive player must not receive target-mode cancel');
    $before = serialize($state->toArray());
    cancelActionAssert(!str_contains(cancelActionRender($state,[],$owner)['panel_html'],'Отменить действие') && serialize($state->toArray())===$before, 'Clean cancel URL clears UI selection without changing game state');
    cancelActionAssert(!str_contains(cancelActionRender($state,['sel'=>2],$owner)['panel_html'],'Отменить действие'), 'Switching cards clears target-mode cancel');
    $rejected = $engine->apply($state,$owner,new Command('action',['card_id'=>1,'target_id'=>999,'action_key'=>'shot']));
    cancelActionAssert(!$rejected->success && !$state->getCard(1)->closed, 'Invalid target must reject action without closing source');
    cancelActionAssert(str_contains(cancelActionRender($state,$ui,$owner)['panel_html'],'Отменить действие'), 'Rejected action must retain cancel');
    $state->getCard(1)->markers['spider_web'] = true;
    cancelActionAssert(!str_contains(cancelActionRender($state,$ui,$owner)['panel_html'],'Отменить действие'), 'Disabled source must not offer target-mode cancel');
    unset($state->getCard(1)->markers['spider_web']);
    $result = $engine->apply($state,$owner,new Command('action',['card_id'=>1,'target_id'=>2,'action_key'=>'shot']));
    cancelActionAssert($result->success && $state->getCard(1)->closed, 'Actual action must close source');
    foreach ([$owner,$opponent] as $actor) {
        $confirmed = $engine->apply($state,$actor,new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($state)]));
        cancelActionAssert($confirmed->success, 'Both viewers must confirm actual result');
    }
    cancelActionAssert(empty($state->battle['strike']), 'Result must finish before testing stale UI mode');
    foreach (['strike','action:shot'] as $mode) {
        $data = cancelActionRender($state,['sel'=>1,'mode'=>$mode],$owner);
        cancelActionAssert(!str_contains($data['panel_html'],'Отменить действие') && !str_contains($data['info_panel_html'],'Отменить действие'), 'Completed action must not leave cancel in selected card or InfoPanel: '.$owner.'/'.$mode);
        cancelActionAssert(str_contains($data['panel_html'],'Карта закрыта'), 'Closed-card status must remain visible');
    }
    // Test the exact fragments used by AJAX, retaining the old explicit mode.
    $snapshot = (new \Berserk\View\Screen\BattleSnapshotRenderer(new Template(__DIR__.'/../templates')))->render(
        $state,$owner,$owner,null,['attacker'=>['name'=>'Атакующий'],'enemy'=>['name'=>'Цель']],$ui
    );
    cancelActionAssert($snapshot['ui']['mode']==='action:shot' && !str_contains($snapshot['fragments']['panel_html'],'Отменить действие'), 'Fresh AJAX fragment must reject stale cancel despite retained explicit mode');
}
echo "Battle cancel action UI tests passed.\n";
