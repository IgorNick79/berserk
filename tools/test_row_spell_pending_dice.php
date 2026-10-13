<?php
// tools/test_row_spell_pending_dice.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Dice;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\TurnPhaseProcessor;
use Berserk\Core\Choice\RowSpellPickChoice;
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;
use Berserk\View\Ui\Panel;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function rowSpellAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rowSpellApplyDebugRoll(string $value): void
{
    $_SESSION['debug_roll'] = $value;
    unset($_SESSION['debug_roll_state']);
    Dice::init();
}

function rowSpellCard(array $o): CardInstance
{
    return new CardInstance(
        instanceId: $o['instanceId'],
        ukid: $o['ukid'] ?? ('card_' . $o['instanceId']),
        owner: $o['owner'],
        zone: $o['zone'] ?? CardInstance::ZONE_FIELD,
        row: $o['row'],
        col: $o['col'],
        hp: $o['hp'] ?? 5,
        hpMax: $o['hpMax'] ?? ($o['hp'] ?? 5),
        type: $o['type'] ?? 'creature',
        move: 1,
        moveMax: 1,
        strikeWeak: 1,
        strikeMedium: 2,
        strikeStrong: 3,
        prop: $o['prop'] ?? [],
        flags: $o['flags'] ?? [],
    );
}

$_SESSION = [];
rowSpellApplyDebugRoll('6,1');

$rowSpellAction = [
    'key' => 'flower_runes',
    'name' => 'Цветущие руны',
    'type' => 'row_spell',
    'strike' => ['weak' => 1, 'medium' => 2, 'strong' => 2],
];

$state = new GameState(196, 101, 202);
$state->status = 'battle';
$state->battle = [
    'turn' => 2,
    'active' => GameState::PLAYER_HOST,
    'strike' => null,
];
$state->addCard(rowSpellCard([
    'instanceId' => 1,
    'ukid' => 's1_96',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => ['actions' => [$rowSpellAction]],
    'flags' => [
        'row_spell_pending' => [
            'row' => 3,
            'action' => $rowSpellAction,
        ],
    ],
]));
$state->addCard(rowSpellCard([
    'instanceId' => 2,
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 4,
]));
$state->addCard(rowSpellCard([
    'instanceId' => 3,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 3,
]));
$state->addCard(rowSpellCard([
    'instanceId' => 4,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 4,
    'col' => 4,
]));

$engine = new Engine();
(new TurnPhaseProcessor($state, $engine))->beginStartPhase(GameState::PLAYER_HOST);

$pending = $state->battle['pending_row_spell_pick'] ?? null;
rowSpellAssert(is_array($pending), 'Row spell pending should open during start phase');
rowSpellAssert(($pending['dice'] ?? null) === 6, 'Row spell should roll dice when pending is created');
rowSpellAssert(($pending['level'] ?? null) === 'strong', 'Row spell should save dice level in pending');
rowSpellAssert(($pending['val'] ?? null) === 2, 'Row spell should save damage value in pending');
rowSpellAssert(Dice::remaining() === [1], 'Row spell pending creation should consume exactly one debug roll');

$choice = new RowSpellPickChoice();
$specA = $choice->spec($state, GameState::PLAYER_HOST, [
    's1_96' => ['name' => 'Тергала'],
    'card_2' => ['name' => 'Союзник'],
    'card_3' => ['name' => 'Враг 1'],
    'card_4' => ['name' => 'Враг 2'],
], '?first&game=196', 'host');
$specB = $choice->spec($state, GameState::PLAYER_HOST, [
    's1_96' => ['name' => 'Тергала'],
    'card_2' => ['name' => 'Союзник'],
    'card_3' => ['name' => 'Враг 1'],
    'card_4' => ['name' => 'Враг 2'],
], '?first&game=196', 'host');

rowSpellAssert($specA !== null && str_contains(implode('', $specA->text), '<div class="dice">6</div>'), 'Row spell choice should show saved dice');
rowSpellAssert($specB !== null && $specB->title === $specA->title, 'Repeated row spell render should keep the same result');
$choiceHtml = Panel::render($specA);
rowSpellAssert(str_contains($choiceHtml, '<div class="label">Кубик</div>'), 'Rendered row spell choice should show dice label');
rowSpellAssert(str_contains($choiceHtml, '<div class="dice">6</div>'), 'Rendered row spell choice should show saved dice');
rowSpellAssert(str_contains($choiceHtml, 'Урон: 2 на цель'), 'Rendered row spell choice should show saved damage');
rowSpellAssert(Dice::remaining() === [1], 'Repeated row spell render should not consume another roll');

$result = $engine->apply($state, GameState::PLAYER_HOST, new Command('choose_row_spell_targets', [
    'target_ids' => [3, 4],
]));

rowSpellAssert($result->success, 'Choosing row spell targets should succeed: ' . ($result->error ?? ''));
rowSpellAssert(empty($state->battle['pending_row_spell_pick']), 'Row spell pending should be removed after resolution');
rowSpellAssert(Dice::remaining() === [1], 'Choosing row spell targets should not roll again');
rowSpellAssert($state->getCard(3)?->hp === 3, 'First target should take saved row spell damage');
rowSpellAssert($state->getCard(4)?->hp === 3, 'Second target should take saved row spell damage');
rowSpellAssert(($state->battle['strike']['row_spell']['dice'] ?? null) === 6, 'Result panel should use saved dice');
rowSpellAssert(($state->battle['strike']['row_spell']['damage_per_target'] ?? null) === 2, 'Result panel should use saved damage');

$resultHtml = (new InfoPanel(new Template(__DIR__ . '/../templates')))->render($state, GameState::PLAYER_HOST, 'host', [
    's1_96' => ['name' => 'Тергала'],
    'card_2' => ['name' => 'Союзник'],
    'card_3' => ['name' => 'Враг 1'],
    'card_4' => ['name' => 'Враг 2'],
], '?first&game=196');
rowSpellAssert(str_contains($resultHtml, '2 урона на цель'), 'Row spell result should show applied damage');
rowSpellAssert(!str_contains($resultHtml, 'кубик 6'), 'Row spell result should not repeat dice after target choice');
rowSpellAssert(!str_contains($resultHtml, '<div class="dice">6</div>'), 'Row spell result should not render a dice block after target choice');

echo "Row spell pending dice tests passed.\n";
