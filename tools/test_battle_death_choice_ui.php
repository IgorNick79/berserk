<?php
// tools/test_battle_death_choice_ui.php

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

function deathChoiceUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function deathChoiceUiCard(
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

function deathChoiceUiState(string $chooserKey, array $candidates, array $extraCandidateIds = []): GameState
{
    $state = new GameState(92, 101, 202);
    $state->status = 'battle';

    $candidateIds = array_map(static fn(CardInstance $c): int => $c->instanceId, $candidates);
    foreach ($extraCandidateIds as $candidateId) {
        $candidateIds[] = (int) $candidateId;
    }

    $oppKey = $state->getOpponentKey($chooserKey);
    $state->battle = [
        'turn' => 1,
        'active' => $chooserKey,
        'strike' => [
            'kind' => 'strike',
            'state' => 'results',
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
            'pending_choice' => [
                'source' => 'on_death',
                'died_ukid' => 'dead',
                'died_id' => 9,
                'effect' => ['type' => 'damage', 'value' => 1],
                'candidates' => $candidateIds,
            ],
        ],
    ];

    $state->addCard(deathChoiceUiCard(1, 'attacker', $chooserKey, 2, 2));
    $state->addCard(deathChoiceUiCard(2, 'target', $oppKey, 3, 3));

    $dead = deathChoiceUiCard(9, 'dead', $chooserKey, 1, 1, CardInstance::ZONE_GRAVEYARD);
    $dead->hp = 0;
    $state->addCard($dead);

    foreach ($candidates as $card) {
        $state->addCard($card);
    }

    return $state;
}

function deathChoiceUiRender(GameState $state, string $viewerKey, string $role, array $cardsInfo): array
{
    $_GET = [];
    $_SESSION = [];
    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));

    return $screen->prepare($state, $viewerKey, $role, null, $cardsInfo)['data'];
}

$cardsInfo = [
    'attacker' => ['name' => 'Атакующий'],
    'target' => ['name' => 'Цель'],
    'dead' => ['name' => 'Погибший'],
    'choice_a' => ['name' => 'Близнец'],
    'choice_b' => ['name' => 'Близнец'],
    'choice_c' => ['name' => 'Дальний'],
    'fly_choice' => ['name' => 'Летун'],
];

$playerState = deathChoiceUiState(GameState::PLAYER_PLAYER, [
    deathChoiceUiCard(3, 'choice_a', GameState::PLAYER_HOST, 3, 2),
    deathChoiceUiCard(4, 'choice_b', GameState::PLAYER_HOST, 4, 4),
    deathChoiceUiCard(5, 'choice_c', GameState::PLAYER_HOST, 6, 5),
], [999]);
$playerHtml = deathChoiceUiRender($playerState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);

deathChoiceUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Погибший'),
    'Death choice text should keep the dead card name.'
);
deathChoiceUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Близнец [4:4]'),
    'Player-relative death target label should mirror first candidate row and column.'
);
deathChoiceUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Близнец [3:2]'),
    'Identical death target cards should be distinguishable by mirrored coordinates.'
);
deathChoiceUiAssert(
    str_contains($playerHtml['info_panel_html'], 'Дальний [1:1]'),
    'Edge death target coordinate should mirror for player viewer.'
);
deathChoiceUiAssert(
    substr_count($playerHtml['info_panel_html'], 'cmd=choose_death_target&amp;target_id=') === 3,
    'Three death target buttons should be rendered through CardButton.'
);
deathChoiceUiAssert(
    substr_count($playerHtml['field_html'], 'pending-death-target') === 3,
    'All existing field death target candidates should be highlighted.'
);
deathChoiceUiAssert(
    str_contains($playerHtml['field_html'], 'cmd=choose_death_target&target_id=4'),
    'Battlefield death target click should use choose_death_target command.'
);

$hostState = deathChoiceUiState(GameState::PLAYER_HOST, [
    deathChoiceUiCard(3, 'choice_a', GameState::PLAYER_PLAYER, 2, 5),
]);
$hostHtml = deathChoiceUiRender($hostState, GameState::PLAYER_HOST, 'host', $cardsInfo);

deathChoiceUiAssert(
    str_contains($hostHtml['info_panel_html'], 'Близнец [2:5]'),
    'Host-relative death target label should keep absolute row and column.'
);
deathChoiceUiAssert(
    substr_count($hostHtml['field_html'], 'pending-death-target') === 1,
    'Single host death target candidate should be highlighted.'
);

$waitingHtml = deathChoiceUiRender($hostState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);
deathChoiceUiAssert(
    str_contains($waitingHtml['info_panel_html'], 'Ожидание выбора цели оппонентом'),
    'Non-chooser should keep the waiting state.'
);
deathChoiceUiAssert(
    !str_contains($waitingHtml['field_html'], 'pending-death-target'),
    'Non-chooser viewer should not get death target battlefield clicks.'
);

$flyState = deathChoiceUiState(GameState::PLAYER_PLAYER, [
    deathChoiceUiCard(6, 'fly_choice', GameState::PLAYER_HOST, 1, 5, CardInstance::ZONE_FLYING),
]);
$flyHtml = deathChoiceUiRender($flyState, GameState::PLAYER_PLAYER, 'player', $cardsInfo);
deathChoiceUiAssert(
    str_contains($flyHtml['info_panel_html'], 'Летун [6:1]'),
    'Flying death target candidate should use viewer-relative coordinates.'
);
deathChoiceUiAssert(
    str_contains($flyHtml['fly_zones_html'], 'pending-death-target')
        && str_contains($flyHtml['fly_zones_html'], 'cmd=choose_death_target&target_id=6'),
    'Flying death target candidate should be highlighted and clickable.'
);

$serverState = deathChoiceUiState(GameState::PLAYER_HOST, [
    deathChoiceUiCard(3, 'choice_a', GameState::PLAYER_PLAYER, 2, 5),
]);
$invalid = (new Engine())->apply($serverState, GameState::PLAYER_HOST, new Command('choose_death_target', [
    'target_id' => 999,
]));
deathChoiceUiAssert(!$invalid->success, 'Invalid death target id should still be rejected server-side.');

$wrongPlayer = (new Engine())->apply($serverState, GameState::PLAYER_PLAYER, new Command('choose_death_target', [
    'target_id' => 3,
]));
deathChoiceUiAssert(!$wrongPlayer->success, 'Non-chooser should still be rejected server-side.');

$valid = (new Engine())->apply($serverState, GameState::PLAYER_HOST, new Command('choose_death_target', [
    'target_id' => 3,
]));
deathChoiceUiAssert($valid->success, $valid->error ?? 'Valid death target should be accepted server-side.');
deathChoiceUiAssert(($serverState->getCard(3)?->hp ?? 0) === 2, 'Valid death target should apply existing death effect.');
deathChoiceUiAssert(
    empty($serverState->battle['strike']['pending_choice']),
    'Valid death target should clear pending choice.'
);

echo "Battle death choice UI tests passed.\n";
