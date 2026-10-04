<?php
// tools/test_combat_instant_ui.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\InstantProcessor;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function ciuAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ciuCard(array $overrides): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'],
        ukid: $overrides['ukid'] ?? ('card_' . $overrides['instanceId']),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: 5,
        hpMax: 5,
        type: 'creature',
        move: 1,
        moveMax: 1,
        strikeWeak: 1,
        strikeMedium: 2,
        strikeStrong: 3,
        prop: $overrides['prop'] ?? [],
    );
}

$state = new GameState(9201, 101, 202);
$state->status = 'battle';
$state->battle = [
    'turn' => 1,
    'active' => GameState::PLAYER_HOST,
    'hidden_row_revealed' => true,
    'strike' => [
        'attacker_id' => 1,
        'target_id' => 2,
        'defender_id' => null,
        'state' => 'waiting_instant',
        'attack_dice' => 2,
        'defend_dice' => 0,
        'attack_mod' => 0,
        'defend_mod' => 0,
        'result' => ['attack' => 'weak', 'defend' => '', 'winner' => 'attack'],
        'confirmed' => [],
        'defenders' => [],
        'redirect_candidates' => [],
    ],
];

$state->addCard(ciuCard(['instanceId' => 1, 'ukid' => 'attacker', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]));
$state->addCard(ciuCard(['instanceId' => 2, 'ukid' => 'target', 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]));
$state->addCard(ciuCard([
    'instanceId' => 3,
    'ukid' => 'instant_card',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 4,
    'prop' => ['instants' => [[
        'key' => 'black_mark',
        'name' => 'Черная метка',
        'effect' => ['type' => 'damage_on_dice', 'value' => 1, 'damage' => 2],
        'trigger' => 'combat',
        'phase' => 'dice',
    ]]],
]));

(new InstantProcessor($state, new Engine()))->openWindow('combat', GameState::PLAYER_HOST);

ciuAssert(($state->battle['strike']['instant_priority'] ?? null) === GameState::PLAYER_PLAYER, 'Only player with instants should get priority immediately.');
ciuAssert(in_array(GameState::PLAYER_HOST, $state->battle['strike']['instant_passed'] ?? [], true), 'Player without instants should be auto-passed.');

$cardsInfo = [
    'attacker' => ['name' => 'Атакующий'],
    'target' => ['name' => 'Цель'],
    'instant_card' => ['name' => 'Взрывная Мэри'],
];

$_GET = [];
$_SESSION = [];
$screen = new BattleScreen(new Template(__DIR__ . '/../templates'));
$html = $screen->prepare($state, GameState::PLAYER_PLAYER, 'player', null, $cardsInfo)['data']['info_panel_html'];

ciuAssert(str_contains($html, 'task-card--instant'), 'Instant owner should immediately see instant cards.');
ciuAssert(str_contains($html, 'cmd=combat_instant_play'), 'Instant owner should see play command.');
ciuAssert(str_contains($html, 'cmd=combat_instant_pass'), 'Instant owner should see pass/close command.');
ciuAssert(!str_contains($html, 'Ожидание — оппонент решает'), 'Instant owner should not see an intermediate waiting step.');

echo "Combat instant UI tests passed.\n";
