<?php
// tools/test_battle_defender_ui.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\GameState;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function defenderUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function defenderUiCard(int $id, string $ukid, string $owner, int $row, int $col): CardInstance
{
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
        strikeMedium: 2,
        strikeStrong: 3,
    );
}

function defenderUiState(string $attackerOwner, array $defenders): GameState
{
    $state = new GameState(77, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => $attackerOwner,
        'strike' => [
            'kind' => 'strike',
            'state' => 'waiting_defender',
            'attacker_id' => 1,
            'target_id' => 2,
            'defenders' => array_map(static fn(CardInstance $c): int => $c->instanceId, $defenders),
        ],
    ];

    $opponent = $state->getOpponentKey($attackerOwner);
    $state->addCard(defenderUiCard(1, 'attacker', $attackerOwner, 2, 2));
    $state->addCard(defenderUiCard(2, 'target', $opponent, 3, 3));
    foreach ($defenders as $card) {
        $state->addCard($card);
    }

    return $state;
}

function defenderUiRender(GameState $state, string $viewerKey, string $role, array $cardsInfo): array
{
    $_GET = [];
    $_SESSION = [];
    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));

    return $screen->prepare($state, $viewerKey, $role, null, $cardsInfo)['data'];
}

$cardsInfo = [
    'attacker' => ['name' => 'Атакующий'],
    'target' => ['name' => 'Цель'],
    'def_a' => ['name' => 'Защитник A'],
    'def_b' => ['name' => 'Защитник B'],
    'def_c' => ['name' => 'Защитник C'],
];

$playerState = defenderUiState(GameState::PLAYER_HOST, [
    defenderUiCard(3, 'def_a', GameState::PLAYER_PLAYER, 3, 2),
    defenderUiCard(4, 'def_b', GameState::PLAYER_PLAYER, 4, 4),
    defenderUiCard(5, 'def_c', GameState::PLAYER_PLAYER, 6, 5),
]);
$playerHtml = defenderUiRender($playerState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);

defenderUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Защитник A [4:4]'),
    'Player-relative defender label should mirror row and column.'
);
defenderUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Защитник B [3:2]'),
    'Second defender should use player-relative coordinates.'
);
defenderUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Защитник C [1:1]'),
    'Third defender should use player-relative coordinates.'
);
defenderUiAssert(
    substr_count($playerHtml['field_html'], 'pending-defender-target') === 3,
    'All three player defender candidates should be highlighted on battlefield.'
);
defenderUiAssert(
    str_contains($playerHtml['field_html'], 'cmd=choose_defender&defender_id=4'),
    'Battlefield defender click should use the same choose_defender command.'
);

$hostState = defenderUiState(GameState::PLAYER_PLAYER, [
    defenderUiCard(3, 'def_a', GameState::PLAYER_HOST, 2, 5),
]);
$hostHtml = defenderUiRender($hostState, GameState::PLAYER_HOST, 'host', $cardsInfo);

defenderUiAssert(
    str_contains($hostHtml['info_panel_html'], 'Защитник A [2:5]'),
    'Host-relative defender label should keep absolute row and column.'
);
defenderUiAssert(
    substr_count($hostHtml['field_html'], 'pending-defender-target') === 1,
    'Single host defender candidate should be highlighted on battlefield.'
);

$twoDefenderState = defenderUiState(GameState::PLAYER_HOST, [
    defenderUiCard(3, 'def_a', GameState::PLAYER_PLAYER, 3, 2),
    defenderUiCard(4, 'def_b', GameState::PLAYER_PLAYER, 4, 4),
]);
$twoDefenderHtml = defenderUiRender($twoDefenderState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);

defenderUiAssert(
    substr_count($twoDefenderHtml['info_panel_html'], 'cmd=choose_defender&amp;defender_id=') === 2,
    'Two defender buttons should be rendered in pending panel.'
);
defenderUiAssert(
    str_contains($twoDefenderHtml['info_panel_html'], 'Без защитника'),
    'Skip defender button should remain available.'
);

echo "Battle defender UI tests passed.\n";
