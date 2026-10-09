<?php
// tools/test_battle_ally_modifier_ui.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function allyModifierUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function allyModifierUiCard(
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

function allyModifierUiState(
    string $sourceOwner,
    array $candidates,
    array $extraCandidateIds = [],
    string $strikeState = 'waiting_ally_modifier',
    bool $includeSource = true
): GameState {
    $state = new GameState(93, 101, 202);
    $state->status = 'battle';

    $candidateIds = array_map(static fn(CardInstance $c): int => $c->instanceId, $candidates);
    foreach ($extraCandidateIds as $candidateId) {
        $candidateIds[] = (int) $candidateId;
    }

    $oppKey = $state->getOpponentKey($sourceOwner);
    $state->battle = [
        'turn' => 1,
        'active' => $sourceOwner,
        'strike' => [
            'kind' => 'strike',
            'state' => $strikeState,
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
            'pending_ally_modifier' => [
                'source_id' => 1,
                'candidates' => $candidateIds,
                'modifier' => [
                    'stat' => 'damage_reduction',
                    'value' => 1,
                    'types' => ['shot'],
                    'expire' => 'end_of_opponent_turn',
                ],
            ],
        ],
    ];

    if ($includeSource) {
        $state->addCard(allyModifierUiCard(1, 'source', $sourceOwner, 2, 2));
    }
    $state->addCard(allyModifierUiCard(2, 'target', $oppKey, 3, 3));

    foreach ($candidates as $card) {
        $state->addCard($card);
    }

    return $state;
}

function allyModifierUiRender(GameState $state, string $viewerKey, string $role, array $cardsInfo): array
{
    $_GET = [];
    $_SESSION = [];
    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));

    return $screen->prepare($state, $viewerKey, $role, null, $cardsInfo)['data'];
}

$cardsInfo = [
    'source' => ['name' => 'Защитник'],
    'target' => ['name' => 'Цель'],
    'ally_a' => ['name' => 'Близнец'],
    'ally_b' => ['name' => 'Близнец'],
    'ally_c' => ['name' => 'Дальний'],
    'fly_ally' => ['name' => 'Летун'],
];

$playerState = allyModifierUiState(GameState::PLAYER_PLAYER, [
    allyModifierUiCard(3, 'ally_a', GameState::PLAYER_PLAYER, 3, 2),
    allyModifierUiCard(4, 'ally_b', GameState::PLAYER_PLAYER, 4, 4),
    allyModifierUiCard(5, 'ally_c', GameState::PLAYER_PLAYER, 6, 5),
], [999]);
$playerHtml = allyModifierUiRender($playerState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);

allyModifierUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Защитник'),
    'Ally modifier text should keep the source card name.'
);
allyModifierUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Близнец [4:4]'),
    'Player-relative ally modifier label should mirror first candidate row and column.'
);
allyModifierUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Близнец [3:2]'),
    'Identical ally modifier cards should be distinguishable by mirrored coordinates.'
);
allyModifierUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Дальний [1:1]'),
    'Edge ally modifier coordinate should mirror for player viewer.'
);
allyModifierUiAssert(
    substr_count($playerHtml['info_panel_html'], 'cmd=choose_ally_modifier&amp;target_id=') === 3,
    'Three ally modifier buttons should be rendered through CardButton.'
);
allyModifierUiAssert(
    substr_count($playerHtml['field_html'], 'pending-ally-modifier-target') === 3,
    'All existing field ally modifier candidates should be highlighted.'
);
allyModifierUiAssert(
    str_contains($playerHtml['field_html'], 'cmd=choose_ally_modifier&target_id=4'),
    'Battlefield ally modifier click should use choose_ally_modifier command.'
);

$hostState = allyModifierUiState(GameState::PLAYER_HOST, [
    allyModifierUiCard(3, 'ally_a', GameState::PLAYER_HOST, 2, 5),
]);
$hostHtml = allyModifierUiRender($hostState, GameState::PLAYER_HOST, 'host', $cardsInfo);

allyModifierUiAssert(
    str_contains($hostHtml['info_panel_html'], 'Близнец [2:5]'),
    'Host-relative ally modifier label should keep absolute row and column.'
);
allyModifierUiAssert(
    substr_count($hostHtml['field_html'], 'pending-ally-modifier-target') === 1,
    'Single host ally modifier candidate should be highlighted.'
);

$waitingHtml = allyModifierUiRender($hostState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);
allyModifierUiAssert(
    str_contains($waitingHtml['info_panel_html'], 'Ожидание выбора оппонента'),
    'Non-chooser should keep the waiting state.'
);
allyModifierUiAssert(
    !str_contains($waitingHtml['field_html'], 'pending-ally-modifier-target'),
    'Non-chooser viewer should not get ally modifier battlefield clicks.'
);

$flyState = allyModifierUiState(GameState::PLAYER_PLAYER, [
    allyModifierUiCard(6, 'fly_ally', GameState::PLAYER_PLAYER, 1, 5, CardInstance::ZONE_FLYING),
]);
$flyHtml = allyModifierUiRender($flyState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);
allyModifierUiAssert(
    str_contains($flyHtml['info_panel_html'], 'Летун [6:1]'),
    'Flying ally modifier candidate should use viewer-relative coordinates.'
);
allyModifierUiAssert(
    str_contains($flyHtml['fly_zones_html'], 'pending-ally-modifier-target')
        && str_contains($flyHtml['fly_zones_html'], 'cmd=choose_ally_modifier&target_id=6'),
    'Flying ally modifier candidate should be highlighted and clickable.'
);

$wrongStateHtml = allyModifierUiRender(
    allyModifierUiState(GameState::PLAYER_HOST, [
        allyModifierUiCard(3, 'ally_a', GameState::PLAYER_HOST, 2, 5),
    ], [], 'results'),
    GameState::PLAYER_HOST,
    'host',
    $cardsInfo
);
allyModifierUiAssert(
    !str_contains($wrongStateHtml['field_html'], 'pending-ally-modifier-target'),
    'Incorrect strike state should not produce ally modifier battlefield targets.'
);

$missingSourceHtml = allyModifierUiRender(
    allyModifierUiState(GameState::PLAYER_HOST, [
        allyModifierUiCard(3, 'ally_a', GameState::PLAYER_HOST, 2, 5),
    ], [], 'waiting_ally_modifier', false),
    GameState::PLAYER_HOST,
    'host',
    $cardsInfo
);
allyModifierUiAssert(
    !str_contains($missingSourceHtml['field_html'], 'pending-ally-modifier-target'),
    'Missing source should not produce ally modifier battlefield targets.'
);

$serverState = allyModifierUiState(GameState::PLAYER_HOST, [
    allyModifierUiCard(3, 'ally_a', GameState::PLAYER_HOST, 2, 5),
]);
$invalid = (new Engine())->apply($serverState, GameState::PLAYER_HOST, new Command('choose_ally_modifier', [
    'target_id' => 999,
]));
allyModifierUiAssert(!$invalid->success, 'Invalid ally modifier target id should still be rejected server-side.');

$wrongPlayer = (new Engine())->apply($serverState, GameState::PLAYER_PLAYER, new Command('choose_ally_modifier', [
    'target_id' => 3,
]));
allyModifierUiAssert(!$wrongPlayer->success, 'Non-chooser should still be rejected server-side.');

$valid = (new Engine())->apply($serverState, GameState::PLAYER_HOST, new Command('choose_ally_modifier', [
    'target_id' => 3,
]));
allyModifierUiAssert($valid->success, $valid->error ?? 'Valid ally modifier target should be accepted server-side.');
$target = $serverState->getCard(3);
allyModifierUiAssert(count($target?->modifiers ?? []) === 1, 'Valid ally modifier choice should apply one modifier.');
allyModifierUiAssert(($target->modifiers[0]['stat'] ?? '') === 'damage_reduction', 'Applied modifier should preserve stat.');
allyModifierUiAssert(
    empty($serverState->battle['strike']['pending_ally_modifier']),
    'Valid ally modifier choice should clear pending state.'
);
allyModifierUiAssert(
    ($serverState->battle['strike']['state'] ?? '') === 'results',
    'Valid ally modifier choice should transition strike back to results.'
);

echo "Battle ally modifier UI tests passed.\n";
