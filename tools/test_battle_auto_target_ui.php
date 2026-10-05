<?php
// tools/test_battle_auto_target_ui.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\GameState;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function autoTargetUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function autoTargetUiCard(
    int $id,
    string $ukid,
    string $owner,
    int $row,
    int $col,
    string $zone = CardInstance::ZONE_FIELD
): CardInstance {
    return new CardInstance(
        instanceId: $id,
        ukid: $ukid,
        owner: $owner,
        zone: $zone,
        row: $row,
        col: $col,
        slot: $id,
        hp: 3,
        hpMax: 3,
        move: 1,
        moveMax: 1,
        strikeWeak: 1,
        strikeMedium: 2,
        strikeStrong: 3,
    );
}

function autoTargetUiState(string $sourceOwner, array $candidates, array $extraCandidateIds = []): GameState
{
    $state = new GameState(79, 101, 202);
    $state->status = 'battle';

    $candidateIds = array_map(static fn(CardInstance $c): int => $c->instanceId, $candidates);
    foreach ($extraCandidateIds as $candidateId) {
        $candidateIds[] = (int) $candidateId;
    }

    $opponent = $state->getOpponentKey($sourceOwner);
    $state->battle = [
        'turn' => 1,
        'active' => $sourceOwner,
        'strike' => [
            'kind' => 'strike',
            'state' => 'waiting_auto_target',
            'attacker_id' => 1,
            'target_id' => 2,
            'defender_id' => null,
            'attack_dice' => 1,
            'defend_dice' => 1,
            'attack_mod' => 0,
            'defend_mod' => 0,
            'result' => ['attack' => 'weak', 'defend' => 'weak', 'winner' => ''],
            'final' => ['attack' => 'weak', 'defend' => 'weak', 'winner' => '', 'decreased' => false],
            'damage' => 0,
            'confirmed' => [],
            'defenders' => [],
            'redirect_candidates' => [],
            'pending_auto' => [
                'source_id' => 3,
                'effect' => ['name' => 'Автовыстрел', 'type' => 'shot', 'value' => 1],
                'candidates' => $candidateIds,
            ],
        ],
    ];

    $state->addCard(autoTargetUiCard(1, 'attacker', $sourceOwner, 2, 2));
    $state->addCard(autoTargetUiCard(2, 'target', $opponent, 3, 3));
    $state->addCard(autoTargetUiCard(3, 'source', $sourceOwner, 5, 1));
    foreach ($candidates as $card) {
        $state->addCard($card);
    }

    return $state;
}

function autoTargetUiRender(GameState $state, string $viewerKey, string $role, array $cardsInfo): array
{
    $_GET = [];
    $_SESSION = [];
    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));

    return $screen->prepare($state, $viewerKey, $role, null, $cardsInfo)['data'];
}

$cardsInfo = [
    'attacker' => ['name' => 'Атакующий'],
    'target' => ['name' => 'Цель'],
    'source' => ['name' => 'Стрелок'],
    'auto_a' => ['name' => 'Близнец'],
    'auto_b' => ['name' => 'Близнец'],
    'auto_c' => ['name' => 'Дальний'],
    'fly_auto' => ['name' => 'Летун'],
];

$playerState = autoTargetUiState(GameState::PLAYER_PLAYER, [
    autoTargetUiCard(4, 'auto_a', GameState::PLAYER_HOST, 3, 2),
    autoTargetUiCard(5, 'auto_b', GameState::PLAYER_HOST, 4, 4),
    autoTargetUiCard(6, 'auto_c', GameState::PLAYER_HOST, 6, 5),
], [999]);
$playerHtml = autoTargetUiRender($playerState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);

autoTargetUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Близнец [4:4]'),
    'Player-relative auto target label should mirror first candidate row and column.'
);
autoTargetUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Близнец [3:2]'),
    'Identical auto target cards should be distinguishable by mirrored coordinates.'
);
autoTargetUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Дальний [1:1]'),
    'Edge auto target coordinate should mirror for player viewer.'
);
autoTargetUiAssert(
    substr_count($playerHtml['field_html'], 'pending-auto-target') === 3,
    'All existing player-view auto target candidates should be highlighted on battlefield.'
);
autoTargetUiAssert(
    str_contains($playerHtml['field_html'], 'cmd=choose_auto_target&target_id=5'),
    'Battlefield auto target click should use choose_auto_target command.'
);
autoTargetUiAssert(
    substr_count($playerHtml['info_panel_html'], 'cmd=choose_auto_target&amp;target_id=') === 3,
    'Three auto target buttons should be rendered through CardButton.'
);
autoTargetUiAssert(
    str_contains($playerHtml['info_panel_html'], 'cmd=choose_auto_target&target_id=0')
        && str_contains($playerHtml['info_panel_html'], 'Пропустить'),
    'Skip auto target button should remain available.'
);

$hostState = autoTargetUiState(GameState::PLAYER_HOST, [
    autoTargetUiCard(4, 'auto_a', GameState::PLAYER_PLAYER, 2, 5),
]);
$hostHtml = autoTargetUiRender($hostState, GameState::PLAYER_HOST, 'host', $cardsInfo);

autoTargetUiAssert(
    str_contains($hostHtml['info_panel_html'], 'Близнец [2:5]'),
    'Host-relative auto target label should keep absolute row and column.'
);
autoTargetUiAssert(
    substr_count($hostHtml['field_html'], 'pending-auto-target') === 1,
    'Single host auto target candidate should be highlighted.'
);

$waitingForOpponentHtml = autoTargetUiRender($hostState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);
autoTargetUiAssert(
    !str_contains($waitingForOpponentHtml['field_html'], 'pending-auto-target'),
    'Non-chooser viewer should not get auto target battlefield clicks.'
);

$flyState = autoTargetUiState(GameState::PLAYER_PLAYER, [
    autoTargetUiCard(7, 'fly_auto', GameState::PLAYER_HOST, 1, 5, CardInstance::ZONE_FLYING),
]);
$flyHtml = autoTargetUiRender($flyState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);
autoTargetUiAssert(
    str_contains($flyHtml['info_panel_html'], 'Летун [6:1]'),
    'Flying auto target candidate should use viewer-relative coordinates when present.'
);
autoTargetUiAssert(
    str_contains($flyHtml['fly_zones_html'], 'pending-auto-target')
        && str_contains($flyHtml['fly_zones_html'], 'cmd=choose_auto_target&target_id=7'),
    'Flying auto target candidate should be highlighted and clickable.'
);

$resolvedState = autoTargetUiState(GameState::PLAYER_PLAYER, [
    autoTargetUiCard(4, 'auto_a', GameState::PLAYER_HOST, 3, 2),
]);
$resolvedState->battle['strike']['state'] = 'results';
$resolvedHtml = autoTargetUiRender($resolvedState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);
autoTargetUiAssert(
    !str_contains($resolvedHtml['field_html'], 'pending-auto-target')
        && !str_contains($resolvedHtml['fly_zones_html'], 'pending-auto-target'),
    'Resolved strike should not keep auto targets highlighted.'
);

echo "Battle auto target UI tests passed.\n";
