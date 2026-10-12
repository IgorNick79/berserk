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
ciuAssert(str_contains($html, 'Пока нет объявленных инстантов.'), 'Empty combat instant stack should be shown cleanly.');

$state->addCard(ciuCard(['instanceId' => 4, 'ukid' => 'ost', 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 3]));
$state->addCard(ciuCard(['instanceId' => 5, 'ukid' => 'glorm', 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 5]));
$cardsInfo['ost'] = ['name' => 'Ост'];
$cardsInfo['glorm'] = ['name' => 'Глорм'];
$state->battle['strike']['instant_stack'] = [
    [
        'card_id' => 4,
        'effect' => ['type' => 'strike_level', 'mode' => 'increase_one'],
        'target_id' => 1,
        'player' => GameState::PLAYER_HOST,
        'label' => 'Дар силы',
        'phase' => 'power',
        'sequence' => 0,
    ],
    [
        'card_id' => 5,
        'effect' => ['type' => 'damage_cap', 'value' => 1, 'internal_debug' => 'hidden-debug-value'],
        'target_id' => 2,
        'player' => GameState::PLAYER_PLAYER,
        'label' => 'Отвлекающая вспышка',
        'phase' => 'setter',
        'sequence' => 1,
    ],
    [
        'card_id' => 3,
        'effect' => ['type' => 'damage_on_dice', 'value' => 1, 'damage' => 2],
        'target_id' => 2,
        'player' => GameState::PLAYER_PLAYER,
        'label' => 'Черная метка',
        'phase' => 'dice',
        'sequence' => 2,
    ],
];
$state->battle['strike']['instant_participants'] = [GameState::PLAYER_PLAYER, GameState::PLAYER_HOST];
$state->battle['strike']['instant_priority'] = GameState::PLAYER_PLAYER;
$state->battle['strike']['instant_passed'] = [];

$htmlPlayer = $screen->prepare($state, GameState::PLAYER_PLAYER, 'player', null, $cardsInfo)['data']['info_panel_html'];
$htmlHost = $screen->prepare($state, GameState::PLAYER_HOST, 'host', null, $cardsInfo)['data']['info_panel_html'];

ciuAssert(str_contains($htmlPlayer, 'Объявленные боевые инстанты:'), 'Battle instant window should show declared stack title.');
ciuAssert(str_contains($htmlPlayer, 'instant-summary__list--plain'), 'Declared battle instant stack should use plain battle-style list without numbering.');
ciuAssert(!str_contains($htmlPlayer, '<ol class="instant-summary__list instant-summary__list--plain">'), 'Declared battle instant stack should not render as an ordered list.');
ciuAssert(str_contains($htmlPlayer, 'Ты') && str_contains($htmlPlayer, 'Соперник'), 'Player viewer should see self/opponent labels.');
ciuAssert(str_contains($htmlHost, 'Ты') && str_contains($htmlHost, 'Соперник'), 'Host viewer should see self/opponent labels.');
ciuAssert(
    strpos($htmlPlayer, 'Ост') < strpos($htmlPlayer, 'Глорм')
        && strpos($htmlPlayer, 'Глорм') < strpos($htmlPlayer, 'Взрывная Мэри'),
    'Combat instant stack should be displayed in declaration order, not phase resolution order.'
);
ciuAssert(str_contains($htmlPlayer, 'Соперник</span> <b>Ост</b>'), 'Player viewer should mark Host instant as opponent.');
ciuAssert(str_contains($htmlHost, 'Ты</span> <b>Ост</b>'), 'Host viewer should mark own instant as self.');
ciuAssert(str_contains($htmlPlayer, '→ Цель'), 'Declared target should be shown when it is public stack information.');
ciuAssert(!str_contains($htmlPlayer, 'hidden-debug-value'), 'Combat stack UI must not leak effect internals.');

$pass = (new InstantProcessor($state, new Engine()))->passCombat(GameState::PLAYER_PLAYER);
ciuAssert($pass->success, $pass->error ?? 'Combat pass should transfer priority.');
ciuAssert(($state->battle['strike']['instant_priority'] ?? null) === GameState::PLAYER_HOST, 'Pass should transfer priority to Host.');
$htmlAfterPass = $screen->prepare($state, GameState::PLAYER_HOST, 'host', null, $cardsInfo)['data']['info_panel_html'];
ciuAssert(
    str_contains($htmlAfterPass, 'Ост') && str_contains($htmlAfterPass, 'Глорм') && str_contains($htmlAfterPass, 'Взрывная Мэри'),
    'Declared combat instant stack should remain visible after pass/priority transfer.'
);

$state->battle['strike']['state'] = 'results';
$state->battle['strike']['attack_dice'] = 2;
$state->battle['strike']['defend_dice'] = 1;
$state->battle['strike']['result'] = ['attack' => 'strong', 'defend' => '', 'winner' => 'attack'];
$state->battle['strike']['final'] = ['attack' => 'strong', 'defend' => '', 'winner' => 'attack'];
$state->battle['strike']['instant_summary'] = [
    [
        'label' => 'Магический трюк',
        'instant_name' => 'Магический трюк',
        'card_ukid' => 'ost',
        'card_id' => 4,
        'player' => GameState::PLAYER_HOST,
        'phase' => 'redirect',
        'effect_type' => 'redirect_strike',
        'applied' => true,
        'reason' => '',
        'result' => ['before_target_id' => 2, 'after_target_id' => 2],
    ],
    [
        'label' => 'Черная метка',
        'instant_name' => 'Черная метка',
        'card_ukid' => 'instant_card',
        'card_id' => 3,
        'player' => GameState::PLAYER_PLAYER,
        'phase' => 'dice',
        'effect_type' => 'damage_on_dice',
        'applied' => true,
        'reason' => '',
        'result' => ['dice_value' => 1, 'damage_delta' => 2, 'damaged' => [['card_id' => 2, 'damage' => 2]]],
    ],
    [
        'label' => 'Дар силы',
        'instant_name' => 'Дар силы',
        'card_ukid' => 'ost',
        'card_id' => 4,
        'player' => GameState::PLAYER_HOST,
        'phase' => 'power',
        'effect_type' => 'strike_level',
        'applied' => true,
        'reason' => '',
        'result' => ['side' => 'attack', 'before' => 'weak', 'after' => 'strong'],
    ],
    [
        'label' => 'Ослабление',
        'instant_name' => 'Ослабление',
        'card_ukid' => 'ost',
        'card_id' => 4,
        'player' => GameState::PLAYER_HOST,
        'phase' => 'value',
        'effect_type' => 'strike_level',
        'applied' => true,
        'reason' => '',
        'result' => ['side' => 'attack', 'before' => 'strong', 'after' => 'medium'],
    ],
    [
        'label' => 'Отвлекающая вспышка',
        'instant_name' => 'Отвлекающая вспышка',
        'card_ukid' => 'ost',
        'card_id' => 4,
        'player' => GameState::PLAYER_HOST,
        'phase' => 'setter',
        'effect_type' => 'damage_cap',
        'applied' => false,
        'reason' => 'цель удара изменилась',
        'result' => ['before_cap' => null, 'after_cap' => null, 'cap' => 1],
    ],
    [
        'label' => 'Перераспределение ран',
        'instant_name' => 'Перераспределение ран',
        'card_ukid' => 'ost',
        'card_id' => 4,
        'player' => GameState::PLAYER_HOST,
        'phase' => 'wounds',
        'effect_type' => 'redistribute_wounds',
        'applied' => true,
        'reason' => '',
        'result' => ['transferred' => 1],
    ],
];
$state->battle['strike']['combat_damage_summary'] = [
    'target_id' => 2,
    'attacker_id' => 1,
    'primary_damage' => 3,
    'target_total_this_strike' => 5,
    'target_extra_this_strike' => 2,
    'answer_damage' => 0,
    'attacker_total_this_strike' => 0,
    'attacker_extra_this_strike' => 0,
];

$html = $screen->prepare($state, GameState::PLAYER_PLAYER, 'player', null, $cardsInfo)['data']['info_panel_html'];
ciuAssert(str_contains($html, 'Разрешение инстантов по фазам'), 'Result screen should show phased instant summary.');
ciuAssert(
    str_contains($html, 'ПЕРЕНАПРАВЛЕНИЕ')
    && str_contains($html, 'КУБИК')
    && str_contains($html, 'СИЛА УДАРА')
    && str_contains($html, 'ИЗМЕНЕНИЕ УДАРА')
    && str_contains($html, 'ФИНАЛЬНЫЙ ЭФФЕКТ')
    && str_contains($html, 'РАНЫ'),
    'Result screen should group instant summary by localized canonical phase.'
);
ciuAssert(str_contains($html, 'Черная метка: кубик 1 -&gt; +2 урона'), 'Result screen should show dice damage effect result.');
ciuAssert(str_contains($html, 'Отвлекающая вспышка: без эффекта'), 'Result screen should show no-op instants.');
ciuAssert(str_contains($html, 'Итого по цели: <b>5</b> урона'), 'Result screen should show final target damage including instant damage.');

echo "Combat instant UI tests passed.\n";
