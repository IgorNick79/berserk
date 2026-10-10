<?php
// tools/test_battle_snapshot_renderer.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\GameState;
use Berserk\View\Screen\BattleSnapshotRenderer;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function battleSnapshotAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function battleSnapshotCard(
    int $id,
    string $ukid,
    string $owner,
    string $zone,
    ?int $row,
    ?int $col,
    bool $revealed = true,
    int $slot = 0,
    array $prop = []
): CardInstance {
    return new CardInstance(
        instanceId: $id,
        ukid: $ukid,
        owner: $owner,
        zone: $zone,
        row: $row,
        col: $col,
        slot: $slot,
        hp: 3,
        hpMax: 3,
        move: 1,
        moveMax: 1,
        strikeWeak: 1,
        strikeMedium: 2,
        strikeStrong: 3,
        revealed: $revealed,
        prop: $prop,
    );
}

function battleSnapshotState(): GameState
{
    $state = new GameState(981, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
    ];

    $state->addCard(battleSnapshotCard(
        1,
        'own',
        GameState::PLAYER_HOST,
        CardInstance::ZONE_FIELD,
        1,
        1,
        true,
        0,
        ['actions' => [['type' => 'shot', 'key' => 'aimed_shot', 'name' => 'Выстрел']]]
    ));
    $state->addCard(battleSnapshotCard(
        2,
        'hidden_enemy',
        GameState::PLAYER_PLAYER,
        CardInstance::ZONE_FIELD,
        1,
        2,
        false
    ));
    $state->addCard(battleSnapshotCard(
        3,
        'flying',
        GameState::PLAYER_HOST,
        CardInstance::ZONE_FLYING,
        null,
        null,
        true,
        1
    ));
    $state->addCard(battleSnapshotCard(
        4,
        'dead',
        GameState::PLAYER_HOST,
        CardInstance::ZONE_GRAVEYARD,
        null,
        null
    ));

    return $state;
}

$cardsInfo = [
    'own' => ['name' => 'Свой'],
    'hidden_enemy' => ['name' => 'Скрытый враг'],
    'flying' => ['name' => 'Летун'],
    'dead' => ['name' => 'Погибший'],
    'attacker' => ['name' => 'Атакующий'],
    'target' => ['name' => 'Цель'],
    'defender' => ['name' => 'Защитник'],
];

$_SESSION = [];
$_GET = ['sel' => 2, 'mode' => 'uchr', 'pile' => 'bad_value'];

$state = battleSnapshotState();
$before = json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$renderer = new BattleSnapshotRenderer(new Template(__DIR__ . '/../templates'));
$snapshot = $renderer->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    null,
    $cardsInfo,
    ['sel' => 1, 'mode' => 'action:aimed_shot', 'pile' => 'own_grave']
);

battleSnapshotAssert(
    $snapshot['ui'] === ['sel' => 1, 'mode' => 'action:aimed_shot', 'pile' => 'own_grave'],
    'Explicit UI state should override $_GET and be returned as normalized UI state.'
);

foreach (['field_html', 'fly_zones_html', 'panel_html', 'piles_html', 'pile_reveal_html', 'info_panel_html'] as $fragment) {
    battleSnapshotAssert(
        array_key_exists($fragment, $snapshot['fragments']),
        "Snapshot should include {$fragment}."
    );
}

battleSnapshotAssert(
    str_contains($snapshot['fragments']['panel_html'], 'Свой'),
    'Selected own card should render in the panel.'
);
battleSnapshotAssert(
    str_contains($snapshot['fragments']['pile_reveal_html'], 'Погибший'),
    'Valid pile UI state should render pile reveal content.'
);

$inactive = battleSnapshotState();
$inactive->battle['active'] = GameState::PLAYER_PLAYER;
$inactiveSnapshot = $renderer->render(
    $inactive,
    GameState::PLAYER_HOST,
    'host',
    null,
    $cardsInfo,
    ['sel' => 1, 'mode' => 'action:aimed_shot', 'pile' => '']
);
battleSnapshotAssert(
    $inactiveSnapshot['ui']['mode'] === 'action:aimed_shot',
    'Action mode should remain a local UI hint during the opponent turn.'
);

$strikeState = battleSnapshotState();
$strikeState->battle['strike'] = [
    'kind' => 'strike',
    'state' => 'waiting_defender',
    'attacker_id' => 1,
    'target_id' => 2,
    'defenders' => [],
];
$strikeSnapshot = $renderer->render(
    $strikeState,
    GameState::PLAYER_HOST,
    'host',
    null,
    $cardsInfo,
    ['sel' => 1, 'mode' => 'action:aimed_shot', 'pile' => '']
);
battleSnapshotAssert(
    $strikeSnapshot['ui']['mode'] === 'action:aimed_shot',
    'Action mode should remain a local UI hint while a strike or pending interaction is active.'
);

$invalid = $renderer->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    null,
    $cardsInfo,
    ['sel' => 999, 'mode' => 'not_a_mode', 'pile' => 'broken']
);
battleSnapshotAssert(
    $invalid['ui'] === ['sel' => 0, 'mode' => 'strike', 'pile' => ''],
    'Invalid selection, mode and pile should normalize to safe defaults.'
);

$hidden = $renderer->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    null,
    $cardsInfo,
    ['sel' => 2, 'mode' => 'strike', 'pile' => '']
);
battleSnapshotAssert(
    $hidden['ui']['sel'] === 0 && $hidden['fragments']['panel_html'] === '',
    'Hidden opponent cards should not remain selected or expose panel details.'
);

$flying = $renderer->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    null,
    $cardsInfo,
    ['sel' => 3, 'mode' => 'strike', 'pile' => '']
);
battleSnapshotAssert(
    $flying['ui']['sel'] === 3 && str_contains($flying['fragments']['panel_html'], 'Летун'),
    'Flying cards should remain selectable.'
);

$pending = new GameState(982, 101, 202);
$pending->status = 'battle';
$pending->battle = [
    'turn' => 1,
    'active' => GameState::PLAYER_HOST,
    'strike' => [
        'kind' => 'strike',
        'state' => 'waiting_defender',
        'attacker_id' => 10,
        'target_id' => 11,
        'defenders' => [12],
    ],
];
$pending->addCard(battleSnapshotCard(10, 'attacker', GameState::PLAYER_HOST, CardInstance::ZONE_FIELD, 2, 2));
$pending->addCard(battleSnapshotCard(11, 'target', GameState::PLAYER_PLAYER, CardInstance::ZONE_FIELD, 3, 3));
$pending->addCard(battleSnapshotCard(12, 'defender', GameState::PLAYER_PLAYER, CardInstance::ZONE_FIELD, 3, 2));

$pendingSnapshot = $renderer->render(
    $pending,
    GameState::PLAYER_PLAYER,
    'player',
    null,
    $cardsInfo,
    ['sel' => 12, 'mode' => 'not_a_mode', 'pile' => '']
);
battleSnapshotAssert(
    str_contains($pendingSnapshot['fragments']['info_panel_html'], 'Защитник'),
    'Pending defender InfoPanel rendering should be preserved.'
);
battleSnapshotAssert(
    $pendingSnapshot['ui']['mode'] === 'strike',
    'Invalid mode should normalize during pending interaction rendering.'
);

$after = json_encode($state->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
battleSnapshotAssert($before === $after, 'Snapshot rendering must not mutate GameState.');

echo "Battle snapshot renderer tests passed.\n";
