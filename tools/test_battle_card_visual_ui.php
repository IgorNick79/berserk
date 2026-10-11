<?php
// tools/test_battle_card_visual_ui.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\GameState;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function battleCardVisualAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function battleCardVisualCard(array $o): CardInstance
{
    return new CardInstance(
        instanceId: $o['instanceId'],
        ukid: $o['ukid'],
        owner: $o['owner'],
        zone: $o['zone'] ?? CardInstance::ZONE_FIELD,
        row: $o['row'] ?? 1,
        col: $o['col'] ?? 1,
        slot: $o['slot'] ?? 0,
        hp: $o['hp'] ?? 3,
        hpMax: $o['hpMax'] ?? 3,
        closed: $o['closed'] ?? false,
        revealed: $o['revealed'] ?? true,
        element: $o['element'] ?? 'neutral',
        move: 1,
        moveMax: 1,
        strikeWeak: 1,
        strikeMedium: 2,
        strikeStrong: 3,
        armor: $o['armor'] ?? 0,
        coins: $o['coins'] ?? 0,
        markers: $o['markers'] ?? [],
    );
}

$_GET = [];
$_SESSION = [];

$state = new GameState(180, 101, 202);
$state->status = 'battle';
$state->battle = [
    'turn' => 1,
    'active' => GameState::PLAYER_HOST,
    'strike' => null,
];

$state->addCard(battleCardVisualCard([
    'instanceId' => 1,
    'ukid' => 'own_plains',
    'owner' => GameState::PLAYER_HOST,
    'row' => 1,
    'col' => 1,
    'element' => 'plains',
    'armor' => 1,
    'coins' => 2,
    'markers' => [
        'poison' => ['value' => 1],
        'fire' => ['value' => 1],
    ],
]));
$state->addCard(battleCardVisualCard([
    'instanceId' => 2,
    'ukid' => 'enemy_forest',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 1,
    'col' => 2,
    'element' => 'forests',
]));
$state->addCard(battleCardVisualCard([
    'instanceId' => 3,
    'ukid' => 'hidden_dark',
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 1,
    'col' => 3,
    'element' => 'dark',
    'revealed' => false,
    'armor' => 5,
    'coins' => 7,
    'markers' => [
        'poison' => ['value' => 1],
        'fire' => ['value' => 1],
    ],
]));
$state->addCard(battleCardVisualCard([
    'instanceId' => 4,
    'ukid' => 'fly_mountain',
    'owner' => GameState::PLAYER_HOST,
    'zone' => CardInstance::ZONE_FLYING,
    'slot' => 1,
    'element' => 'mountains',
]));
$state->addCard(battleCardVisualCard([
    'instanceId' => 5,
    'ukid' => 'unknown_element',
    'owner' => GameState::PLAYER_HOST,
    'row' => 2,
    'col' => 1,
    'element' => 'unknown',
]));
$state->addCard(battleCardVisualCard([
    'instanceId' => 6,
    'ukid' => 'hidden_fly_dark',
    'owner' => GameState::PLAYER_PLAYER,
    'zone' => CardInstance::ZONE_FLYING,
    'slot' => 2,
    'element' => 'dark',
    'revealed' => false,
    'armor' => 6,
    'coins' => 8,
    'markers' => [
        'poison' => ['value' => 1],
        'fire' => ['value' => 1],
    ],
]));

$cardsInfo = [
    'own_plains' => ['name' => 'Своя степная', 'health' => 3, 'element' => 'Степи'],
    'enemy_forest' => ['name' => 'Чужая лесная', 'health' => 3, 'element' => 'Леса'],
    'hidden_dark' => ['name' => 'Скрытая тьма', 'health' => 3, 'element' => 'Тьма'],
    'fly_mountain' => ['name' => 'Летун горный', 'health' => 3, 'element' => 'Горы'],
    'unknown_element' => ['name' => 'Неизвестная стихия', 'health' => 3, 'element' => 'Неизвестно'],
    'hidden_fly_dark' => ['name' => 'Скрытый летун тьмы', 'health' => 3, 'element' => 'Тьма'],
];

$screen = new BattleScreen(new Template(__DIR__ . '/../templates'));
$html = $screen->prepare($state, GameState::PLAYER_HOST, 'host', null, $cardsInfo)['data'];

battleCardVisualAssert(
    str_contains($html['field_html'], 'class="battle-card__icon"')
        && str_contains($html['field_html'], '/assets/images/element-plains.png')
        && str_contains($html['field_html'], '/assets/images/element-forests.png'),
    'Field cards should render element icons for visible own and enemy cards.'
);
battleCardVisualAssert(
    str_contains($html['fly_zones_html'], '/assets/images/element-mountains.png'),
    'Flying cards should render element icons.'
);
battleCardVisualAssert(
    !str_contains($html['field_html'], '/assets/cards/s1/'),
    'Battle mini-cards should not render creature card images.'
);
battleCardVisualAssert(
    !str_contains($html['field_html'], 'alt="Степи"')
        && str_contains($html['field_html'], 'alt="plains"'),
    'Element icons should use CardInstance element codes, not localized card info labels.'
);
battleCardVisualAssert(
    !str_contains($html['field_html'], '/assets/images/element-dark.png'),
    'Hidden enemy cards should not reveal their element icon.'
);
battleCardVisualAssert(
    !str_contains($html['field_html'], '<div class="barmor">5</div>')
        && !str_contains($html['field_html'], '<div class="bcoins">7</div>'),
    'Hidden enemy field cards should not reveal armor or coins.'
);
battleCardVisualAssert(
    str_contains($html['field_html'], '<div class="battle-card__bottom"><div class="bmarkers">'),
    'Card badges should render inside the bottom area.'
);
battleCardVisualAssert(
    str_contains($html['field_html'], 'marker-poison')
        && str_contains($html['field_html'], 'marker-fire')
        && str_contains($html['field_html'], '<div class="barmor">1</div>')
        && str_contains($html['field_html'], '<div class="bcoins">2</div>'),
    'Multiple card badges, armor, and coins should remain rendered.'
);
battleCardVisualAssert(
    !str_contains($html['field_html'], '/assets/images/element-unknown.png'),
    'Unknown element codes should not render an icon.'
);
battleCardVisualAssert(
    str_contains($html['fly_zones_html'], '???')
        && !str_contains($html['fly_zones_html'], 'Скрытый летун тьмы')
        && !str_contains($html['fly_zones_html'], '/assets/images/element-dark.png')
        && !str_contains($html['fly_zones_html'], '<div class="barmor">6</div>')
        && !str_contains($html['fly_zones_html'], '<div class="bcoins">8</div>'),
    'Hidden flying enemy cards should not reveal name, element icon, armor, or coins.'
);
battleCardVisualAssert(
    substr_count($html['field_html'] . $html['fly_zones_html'], 'marker-poison') === 1
        && substr_count($html['field_html'] . $html['fly_zones_html'], 'marker-fire') === 1,
    'Hidden cards should not reveal card badges.'
);

echo "Battle card visual UI tests passed.\n";
