<?php
// tools/test_battle_redirect_ui.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\GameState;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function redirectUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function redirectUiCard(int $id, string $ukid, string $owner, int $row, int $col, string $zone = CardInstance::ZONE_FIELD): CardInstance
{
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

function redirectUiState(string $attackerOwner, array $candidates, array $extraCandidateIds = []): GameState
{
    $state = new GameState(78, 101, 202);
    $state->status = 'battle';

    $candidateIds = array_map(static fn(CardInstance $c): int => $c->instanceId, $candidates);
    foreach ($extraCandidateIds as $candidateId) {
        $candidateIds[] = (int) $candidateId;
    }

    $state->battle = [
        'turn' => 1,
        'active' => $attackerOwner,
        'strike' => [
            'kind' => 'strike',
            'state' => 'waiting_redirect',
            'attacker_id' => 1,
            'target_id' => 2,
            'defender_id' => null,
            'attack_dice' => 1,
            'defend_dice' => 1,
            'result' => ['attack' => 'weak', 'defend' => 'weak', 'winner' => ''],
            'final' => ['attack' => 'weak', 'defend' => 'weak', 'winner' => '', 'decreased' => false],
            'damage' => 0,
            'confirmed' => [],
            'defenders' => [],
            'redirect_candidates' => $candidateIds,
        ],
    ];

    $opponent = $state->getOpponentKey($attackerOwner);
    $state->addCard(redirectUiCard(1, 'attacker', $attackerOwner, 2, 2));
    $state->addCard(redirectUiCard(2, 'target', $opponent, 3, 3));
    foreach ($candidates as $card) {
        $state->addCard($card);
    }

    return $state;
}

function redirectUiRender(GameState $state, string $viewerKey, string $role, array $cardsInfo): array
{
    $_GET = [];
    $_SESSION = [];
    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));

    return $screen->prepare($state, $viewerKey, $role, null, $cardsInfo)['data'];
}

$cardsInfo = [
    'attacker' => ['name' => 'Атакующий'],
    'target' => ['name' => 'Цель'],
    'redir_a' => ['name' => 'Близнец'],
    'redir_b' => ['name' => 'Близнец'],
    'redir_c' => ['name' => 'Дальний'],
    'fly_redir' => ['name' => 'Летун'],
];

$playerState = redirectUiState(GameState::PLAYER_HOST, [
    redirectUiCard(3, 'redir_a', GameState::PLAYER_PLAYER, 3, 2),
    redirectUiCard(4, 'redir_b', GameState::PLAYER_PLAYER, 4, 4),
    redirectUiCard(5, 'redir_c', GameState::PLAYER_PLAYER, 6, 5),
], [999]);
$playerHtml = redirectUiRender($playerState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);

redirectUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Близнец [4:4]'),
    'Player-relative redirect label should mirror first candidate row and column.'
);
redirectUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Близнец [3:2]'),
    'Identical redirect cards should be distinguishable by mirrored coordinates.'
);
redirectUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Дальний [1:1]'),
    'Edge redirect coordinate should mirror for player viewer.'
);
redirectUiAssert(
    substr_count($playerHtml['field_html'], 'pending-redirect-target') === 3,
    'All existing player redirect candidates should be highlighted on battlefield.'
);
redirectUiAssert(
    str_contains($playerHtml['field_html'], 'cmd=choose_redirect&target_id=4'),
    'Battlefield redirect click should use choose_redirect command.'
);
redirectUiAssert(
    substr_count($playerHtml['info_panel_html'], 'cmd=choose_redirect&amp;target_id=') === 3,
    'Three redirect target buttons should be rendered through CardButton.'
);
redirectUiAssert(
    str_contains($playerHtml['info_panel_html'], 'cmd=choose_redirect&target_id=0')
        && str_contains($playerHtml['info_panel_html'], 'Не перенаправлять (Цель)'),
    'Skip redirect button should remain available.'
);

$hostState = redirectUiState(GameState::PLAYER_PLAYER, [
    redirectUiCard(3, 'redir_a', GameState::PLAYER_HOST, 2, 5),
]);
$hostHtml = redirectUiRender($hostState, GameState::PLAYER_HOST, 'host', $cardsInfo);

redirectUiAssert(
    str_contains($hostHtml['info_panel_html'], 'Близнец [2:5]'),
    'Host-relative redirect label should keep absolute row and column.'
);
redirectUiAssert(
    substr_count($hostHtml['field_html'], 'pending-redirect-target') === 1,
    'Single host redirect candidate should be highlighted.'
);

$waitingForOpponentHtml = redirectUiRender($hostState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);
redirectUiAssert(
    !str_contains($waitingForOpponentHtml['field_html'], 'pending-redirect-target'),
    'Non-chooser viewer should not get redirect battlefield clicks.'
);

$flyState = redirectUiState(GameState::PLAYER_HOST, [
    redirectUiCard(6, 'fly_redir', GameState::PLAYER_PLAYER, 1, 5, CardInstance::ZONE_FLYING),
]);
$flyHtml = redirectUiRender($flyState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);
redirectUiAssert(
    str_contains($flyHtml['info_panel_html'], 'Летун [6:1]'),
    'Flying redirect candidate should still use viewer-relative coordinates when present.'
);
redirectUiAssert(
    str_contains($flyHtml['fly_zones_html'], 'pending-redirect-target')
        && str_contains($flyHtml['fly_zones_html'], 'cmd=choose_redirect&target_id=6'),
    'Flying redirect candidate should be highlighted and clickable if present in pending state.'
);

$resolvedState = redirectUiState(GameState::PLAYER_HOST, [
    redirectUiCard(3, 'redir_a', GameState::PLAYER_PLAYER, 3, 2),
]);
$resolvedState->battle['strike']['state'] = 'results';
$resolvedHtml = redirectUiRender($resolvedState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);
redirectUiAssert(
    !str_contains($resolvedHtml['field_html'], 'pending-redirect-target')
        && !str_contains($resolvedHtml['fly_zones_html'], 'pending-redirect-target'),
    'Resolved strike should not keep redirect targets highlighted.'
);

echo "Battle redirect UI tests passed.\n";
