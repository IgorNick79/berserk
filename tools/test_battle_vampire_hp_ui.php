<?php
// tools/test_battle_vampire_hp_ui.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\GameState;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function vampireHpUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function vampireHpUiCard(array $overrides): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'],
        ukid: $overrides['ukid'],
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 1,
        col: $overrides['col'] ?? 1,
        slot: $overrides['slot'] ?? 0,
        hp: $overrides['hp'],
        hpMax: $overrides['hpMax'],
        move: 1,
        moveMax: 1,
        strikeWeak: 1,
        strikeMedium: 2,
        strikeStrong: 3,
        prop: $overrides['prop'] ?? [],
    );
}

function vampireHpUiRender(GameState $state, array $cardsInfo): array
{
    $_GET = ['sel' => 1];
    $_SESSION = [];

    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));
    return $screen->prepare($state, GameState::PLAYER_HOST, 'host', null, $cardsInfo)['data'];
}

$state = new GameState(816, 101, 202);
$state->status = 'battle';
$state->battle = [
    'turn' => 1,
    'active' => GameState::PLAYER_HOST,
    'strike' => null,
];

$state->addCard(vampireHpUiCard([
    'instanceId' => 1,
    'ukid' => 's1_139',
    'row' => 1,
    'col' => 1,
    'hp' => 9,
    'hpMax' => 999,
    'prop' => ['vampire' => true, 'hp_max_override' => 999],
]));
$state->addCard(vampireHpUiCard([
    'instanceId' => 2,
    'ukid' => 's1_139',
    'row' => 1,
    'col' => 2,
    'hp' => 10,
    'hpMax' => 999,
    'prop' => ['vampire' => true, 'hp_max_override' => 999],
]));
$state->addCard(vampireHpUiCard([
    'instanceId' => 3,
    'ukid' => 's1_139',
    'row' => 1,
    'col' => 3,
    'hp' => 7,
    'hpMax' => 999,
    'prop' => ['vampire' => true, 'hp_max_override' => 999],
]));
$state->addCard(vampireHpUiCard([
    'instanceId' => 4,
    'ukid' => 'normal_bonus',
    'row' => 1,
    'col' => 4,
    'hp' => 7,
    'hpMax' => 12,
]));
$state->addCard(vampireHpUiCard([
    'instanceId' => 5,
    'ukid' => 's1_139',
    'zone' => CardInstance::ZONE_FLYING,
    'slot' => 1,
    'hp' => 11,
    'hpMax' => 999,
    'prop' => ['vampire' => true, 'hp_max_override' => 999],
]));
$state->addCard(vampireHpUiCard([
    'instanceId' => 6,
    'ukid' => 'other_override',
    'row' => 1,
    'col' => 5,
    'hp' => 7,
    'hpMax' => 999,
    'prop' => ['hp_max_override' => 999],
]));
$state->addCard(vampireHpUiCard([
    'instanceId' => 7,
    'ukid' => 'plain_999',
    'row' => 2,
    'col' => 1,
    'hp' => 7,
    'hpMax' => 999,
]));

$cardsInfo = [
    's1_139' => ['name' => 'Вампир', 'health' => 9],
    'normal_bonus' => ['name' => 'Обычный бонус HP', 'health' => 9],
    'other_override' => ['name' => 'Другая карта с override', 'health' => 9],
    'plain_999' => ['name' => 'Обычная карта 999', 'health' => 9],
];

$html = vampireHpUiRender($state, $cardsInfo);
$combined = $html['field_html'] . $html['fly_zones_html'] . $html['panel_html'];

vampireHpUiAssert(str_contains($html['field_html'], '<div class="bhp">9/∞</div>'), 'Base Vampire HP should render as 9/infinity.');
vampireHpUiAssert(str_contains($html['field_html'], '<div class="bhp">10/∞</div>'), 'Healed Vampire HP should render as 10/infinity.');
vampireHpUiAssert(str_contains($html['field_html'], '<div class="bhp">7/∞</div>'), 'Wounded Vampire HP should render as 7/infinity.');
vampireHpUiAssert(str_contains($html['field_html'], '<div class="bhp">7/12</div>'), 'Normal HP bonuses should still render real hpMax.');
vampireHpUiAssert(substr_count($html['field_html'], '<div class="bhp">7/∞</div>') === 2, 'Any technical 999 HP override should render as infinity.');
vampireHpUiAssert(str_contains($html['field_html'], '<div class="bhp">7/999</div>'), 'Plain 999 hpMax without the technical override prop should keep existing display rules.');
vampireHpUiAssert(str_contains($html['fly_zones_html'], '<div class="bhp">11/∞</div>'), 'Flying Vampire HP should use the same infinity max.');
vampireHpUiAssert(str_contains($html['panel_html'], 'HP 9/∞'), 'Selected Vampire panel should render infinity max.');

echo "Battle vampire HP UI tests passed.\n";
