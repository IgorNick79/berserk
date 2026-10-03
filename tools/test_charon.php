<?php
// tools/test_charon.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Choice\ChoiceRegistry;
use Berserk\View\Screen\BattleScreen;
use Berserk\View\Template;
use Berserk\View\Ui\Panel;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function chAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function chCharonProp(): array
{
    return [
        'actions' => [
            [
                'key' => 'last_journey',
                'name' => 'Последний путь',
                'type' => 'destroy_self_and_target',
                'coins' => 3,
                'target' => 'any_creature',
            ],
            [
                'type' => 'discharge',
                'value' => 1,
            ],
        ],
        'save_coins' => true,
    ];
}

function chCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'],
        ukid: $overrides['ukid'] ?? ('card_' . $overrides['instanceId']),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        slot: $overrides['slot'] ?? 0,
        hp: $overrides['hp'] ?? 5,
        hpMax: $overrides['hpMax'] ?? ($overrides['hp'] ?? 5),
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        coins: $overrides['coins'] ?? 0,
        prop: $overrides['prop'] ?? [],
    );
}

function chCharon(array $overrides = []): CardInstance
{
    return chCard(array_merge([
        'instanceId' => 1,
        'ukid' => 's1_150',
        'row' => 3,
        'col' => 1,
        'hp' => 5,
        'hpMax' => 5,
        'coins' => 3,
        'prop' => chCharonProp(),
    ], $overrides));
}

function chState(CardInstance ...$cards): GameState
{
    $state = new GameState(150, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
        'hidden_row_revealed' => true,
    ];

    foreach ($cards as $card) {
        $state->addCard($card);
    }

    return $state;
}

function chStart(GameState $state): \Berserk\Core\Result
{
    return (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
        'card_id' => 1,
        'action_key' => 'last_journey',
    ]));
}

function chCardsInfo(): array
{
    $raw = [
        's1_150' => ['name' => 'Харон'],
        'imp' => ['name' => 'Огненный имп'],
        'demon' => ['name' => 'Рогатый демон'],
        'ally' => ['name' => 'Союзник'],
        'enemy_fly' => ['name' => 'Вражеский летун'],
        'own_fly' => ['name' => 'Свой летун'],
        'victim' => ['name' => 'Жертва'],
        'card_2' => ['name' => 'Цель'],
        'card_3' => ['name' => 'Свидетель'],
        'card_4' => ['name' => 'Склеп'],
    ];

    foreach ($raw as &$info) {
        $info += [
            'health' => 5,
            'move' => 1,
            'strike_weak' => 1,
            'strike_medium' => 1,
            'strike_strong' => 1,
        ];
    }
    unset($info);

    return $raw;
}

function chSpec(GameState $state, string $side = 'enemy'): \Berserk\View\Ui\PanelSpec
{
    $_GET = ['target_side' => $side];
    $handler = ChoiceRegistry::current($state);
    chAssert($handler !== null, 'ChoiceRegistry should expose current Charon pending.');
    $spec = $handler->spec($state, GameState::PLAYER_HOST, chCardsInfo(), '?first&game=150', 'host');
    chAssert($spec !== null, 'Charon pending should render a PanelSpec.');
    return $spec;
}

function chRenderBattle(GameState $state): array
{
    $_GET = ['sel' => 1];
    $_SESSION = [];
    $screen = new BattleScreen(new Template(__DIR__ . '/../templates'));
    return $screen->prepare($state, GameState::PLAYER_HOST, 'host', null, chCardsInfo())['data'];
}

foreach ([0, 1, 2] as $coins) {
    $charon = chCharon(['coins' => $coins]);
    $target = chCard([
        'instanceId' => 2,
        'owner' => GameState::PLAYER_PLAYER,
        'row' => 6,
        'col' => 5,
    ]);
    $state = chState($charon, $target);
    $result = chStart($state);
    chAssert(!$result->success, "Last Journey should be unavailable with {$coins} coins.");
    chAssert(empty($state->battle['pending_destroy_self_and_target']), 'Failed start should not create pending.');
}

$charon = chCharon(['coins' => 3]);
$target = chCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5]);
$state = chState($charon, $target);
$result = chStart($state);
chAssert($result->success, $result->error ?? 'Last Journey should start with 3 coins.');
chAssert(!empty($state->battle['pending_destroy_self_and_target']), 'Last Journey should create pending.');
chAssert(!empty($charon->prop['save_coins']), 'Charon prop should preserve coins between turns.');
chAssert(($state->battle['pending_destroy_self_and_target']['default_side'] ?? null) === 'enemy', 'Last Journey should default UI side to enemy.');

$battleHtml = chRenderBattle(chState(
    chCharon(['coins' => 3]),
    chCard(['instanceId' => 2, 'ukid' => 'imp', 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5])
));
chAssert(
    str_contains($battleHtml['panel_html'], 'cmd=action&action_key=last_journey')
        && str_contains($battleHtml['panel_html'], 'target_id=1'),
    'Last Journey action button should start pending immediately without choosing a board target.'
);

$closed = chCharon(['coins' => 3, 'closed' => true]);
$state = chState($closed, $target);
$result = chStart($state);
chAssert(!$result->success, 'Closed Charon should not start Last Journey.');

$charon = chCharon(['coins' => 3]);
$farEnemy = chCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5]);
$own = chCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 2]);
$enemyFly = chCard([
    'instanceId' => 4,
    'owner' => GameState::PLAYER_PLAYER,
    'zone' => CardInstance::ZONE_FLYING,
    'type' => 'fly',
    'slot' => 1,
]);
$ownFly = chCard([
    'instanceId' => 5,
    'owner' => GameState::PLAYER_HOST,
    'zone' => CardInstance::ZONE_FLYING,
    'type' => 'fly',
    'slot' => 1,
]);
$state = chState($charon, $farEnemy, $own, $enemyFly, $ownFly);
$result = chStart($state);
chAssert($result->success, $result->error ?? 'Last Journey should start for target tests.');
$ids = $state->battle['pending_destroy_self_and_target']['target_ids'] ?? [];
foreach ([2, 3, 4, 5] as $id) {
    chAssert(in_array($id, $ids, true), "Target {$id} should be a legal Last Journey target.");
}
chAssert(!in_array(1, $ids, true), 'Charon should not target himself.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_destroy_self_and_target', [
    'target_id' => 1,
]));
chAssert(!$result->success, 'Server should reject Charon as his own target.');

$uiCharon = chCharon(['coins' => 3, 'row' => 3, 'col' => 1]);
$enemyA = chCard(['instanceId' => 2, 'ukid' => 'imp', 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5]);
$enemyB = chCard(['instanceId' => 6, 'ukid' => 'demon', 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 4]);
$enemyC = chCard(['instanceId' => 7, 'ukid' => 'demon', 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 3]);
$ownA = chCard(['instanceId' => 3, 'ukid' => 'ally', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 2]);
$enemyFlyUi = chCard([
    'instanceId' => 4,
    'ukid' => 'enemy_fly',
    'owner' => GameState::PLAYER_PLAYER,
    'zone' => CardInstance::ZONE_FLYING,
    'type' => 'fly',
    'slot' => 2,
]);
$ownFlyUi = chCard([
    'instanceId' => 5,
    'ukid' => 'own_fly',
    'owner' => GameState::PLAYER_HOST,
    'zone' => CardInstance::ZONE_FLYING,
    'type' => 'fly',
    'slot' => 1,
]);
$state = chState($uiCharon, $enemyA, $enemyB, $enemyC, $ownA, $enemyFlyUi, $ownFlyUi);
$result = chStart($state);
chAssert($result->success, $result->error ?? 'Last Journey should start for UI tests.');

$enemySpec = chSpec($state, 'enemy');
$enemyLabels = array_column($enemySpec->form['items'] ?? [], 'label');
$enemyValues = array_column($enemySpec->form['items'] ?? [], 'value');
chAssert(in_array(2, $enemyValues, true) && in_array(6, $enemyValues, true), 'Enemy UI should include enemy creatures.');
chAssert(!in_array(3, $enemyValues, true), 'Enemy UI should not display own creatures.');
chAssert(in_array(4, $enemyValues, true), 'Enemy UI should include enemy flyers.');
chAssert(in_array('Огненный имп [6:5]', $enemyLabels, true), 'Enemy label should use numeric row:col coordinates.');
chAssert(in_array('Рогатый демон [5:4]', $enemyLabels, true), 'Duplicate enemy label should include coordinates.');
chAssert(in_array('Рогатый демон [5:3]', $enemyLabels, true), 'Second duplicate enemy label should include different coordinates.');
chAssert(in_array('Вражеский летун [летун 2]', $enemyLabels, true), 'Flying enemy should use a clear flying-zone position.');
foreach ($enemyLabels as $label) {
    chAssert(!str_contains($label, '#'), 'Target label should not expose instance ids.');
}
chAssert(($enemySpec->form['type'] ?? null) === 'radio', 'Target selection should render as radio.');
chAssert(($enemySpec->form['name'] ?? null) === 'target_id', 'Radio should submit target_id.');
chAssert(($enemySpec->form['submit'] ?? null) === 'Выбрать', 'Radio form should have choose submit.');
chAssert(str_contains($enemySpec->form['cancel'] ?? '', 'cmd=cancel_pending'), 'Radio form should have cancel link.');
chAssert(in_array(6, $enemyValues, true), 'Radio value should be the real instance_id.');

$ownSpec = chSpec($state, 'own');
$ownLabels = array_column($ownSpec->form['items'] ?? [], 'label');
$ownValues = array_column($ownSpec->form['items'] ?? [], 'value');
chAssert(in_array(3, $ownValues, true), 'Own UI should include own creatures.');
chAssert(in_array(5, $ownValues, true), 'Own UI should include own flyers.');
chAssert(!in_array(1, $ownValues, true), 'Own UI should exclude Charon himself.');
chAssert(!in_array(2, $ownValues, true), 'Own UI should not display enemy creatures.');
chAssert(in_array('Союзник [3:2]', $ownLabels, true), 'Own field creature should use numeric coordinates.');
chAssert(in_array('Свой летун [летун 1]', $ownLabels, true), 'Own flyer should use flying-zone position.');

$enemySpecAgain = chSpec($state, 'enemy');
chAssert(array_column($enemySpecAgain->form['items'] ?? [], 'value') === $enemyValues, 'Switching back to enemy should keep the same pending and targets.');
chAssert(!empty($state->battle['pending_destroy_self_and_target']), 'Switching side should not clear pending.');
chAssert(!$uiCharon->closed, 'Switching side should not close Charon.');
chAssert($uiCharon->coins === 3, 'Switching side should not spend coins.');
foreach ([$uiCharon, $enemyA, $enemyB, $enemyC, $ownA, $enemyFlyUi, $ownFlyUi] as $card) {
    chAssert($card->zone === CardInstance::ZONE_FIELD || $card->zone === CardInstance::ZONE_FLYING, 'Switching side should not destroy cards.');
}
chAssert(str_contains(Panel::render($enemySpec), 'type="radio"'), 'Rendered pending should contain radio inputs.');
chAssert(str_contains(Panel::render($enemySpec), 'value="6"'), 'Rendered radio should submit instance_id.');

$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_destroy_self_and_target'));
chAssert(!$result->success, 'Submitting without a selected radio should not resolve Last Journey.');
chAssert(!empty($state->battle['pending_destroy_self_and_target']), 'Rejected empty submit should leave pending intact.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_destroy_self_and_target', [
    'target_id' => 999,
]));
chAssert(!$result->success, 'Server should reject fake target_id.');
chAssert(!empty($state->battle['pending_destroy_self_and_target']), 'Rejected fake target should leave pending intact.');

$cancelOwnCharon = chCharon(['coins' => 3]);
$cancelOwnTarget = chCard(['instanceId' => 3, 'ukid' => 'ally', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 2]);
$state = chState(
    $cancelOwnCharon,
    chCard(['instanceId' => 2, 'ukid' => 'imp', 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5]),
    $cancelOwnTarget,
);
$result = chStart($state);
chAssert($result->success, $result->error ?? 'Last Journey should start before own-tab cancel.');
chSpec($state, 'own');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('cancel_pending'));
chAssert($result->success, $result->error ?? 'Cancel after switching to own should work.');
chAssert(empty($state->battle['pending_destroy_self_and_target']), 'Cancel after own tab should clear pending.');
chAssert(!$cancelOwnCharon->closed, 'Cancel after own tab should leave Charon open.');
chAssert($cancelOwnCharon->coins === 3, 'Cancel after own tab should not spend coins.');
chAssert($cancelOwnTarget->zone === CardInstance::ZONE_FIELD, 'Cancel after own tab should not destroy own target.');

$charon = chCharon(['coins' => 3]);
$target = chCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5]);
$state = chState($charon, $target);
$result = chStart($state);
chAssert($result->success, $result->error ?? 'Last Journey should start before cancel.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('cancel_pending'));
chAssert($result->success, $result->error ?? 'Last Journey cancel should be accepted.');
chAssert(empty($state->battle['pending_destroy_self_and_target']), 'Cancel should clear Last Journey pending.');
chAssert(!$charon->closed, 'Cancel should leave Charon open.');
chAssert($charon->coins === 3, 'Cancel should not spend coins.');
chAssert($charon->zone === CardInstance::ZONE_FIELD && $target->zone === CardInstance::ZONE_FIELD, 'Cancel should not destroy cards.');
$result = chStart($state);
chAssert($result->success, $result->error ?? 'Last Journey should be redeclarable after cancel.');

$charon = chCharon(['coins' => 3]);
$victim = chCard([
    'instanceId' => 2,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 4,
    'prop' => [
        'on_death' => [[
            'type' => 'damage',
            'target' => 'near',
            'value' => 1,
        ]],
    ],
]);
$onDeathWitness = chCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 5, 'hp' => 5]);
$anyDeathWitness = chCard([
    'instanceId' => 4,
    'owner' => GameState::PLAYER_HOST,
    'row' => 1,
    'col' => 1,
    'coins' => 0,
    'prop' => [
        'coins' => ['max_value' => 10],
        'on_any_death' => [
            'side' => 'any',
            'cause' => 'any',
            'value' => 1,
            'effect' => 'get_coin',
        ],
    ],
]);
$state = chState($charon, $victim, $onDeathWitness, $anyDeathWitness);
$result = chStart($state);
chAssert($result->success, $result->error ?? 'Last Journey should start before resolution.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_destroy_self_and_target', [
    'target_id' => 2,
]));
chAssert($result->success, $result->error ?? 'Last Journey target choice should resolve.');
chAssert($charon->zone === CardInstance::ZONE_GRAVEYARD, 'Charon should go to graveyard.');
chAssert($victim->zone === CardInstance::ZONE_GRAVEYARD, 'Target should go to graveyard.');
chAssert($charon->coins === 0, 'Charon coins should be cleared in graveyard.');
chAssert(empty($state->battle['pending_destroy_self_and_target']), 'Resolution should clear pending.');
chAssert(($state->battle['strike']['kind'] ?? null) === 'destroy_self_and_target', 'Resolution should expose InfoPanel result.');
chAssert($onDeathWitness->hp === 4, 'Target on_death should trigger through destroy pipeline.');
chAssert($anyDeathWitness->coins === 2, 'on_any_death should see both Charon and target deaths.');

$charon = chCharon(['coins' => 3]);
$dischargeTarget = chCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5, 'hp' => 5]);
$state = chState($charon, $dischargeTarget);
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'discharge',
    'target_id' => 2,
]));
chAssert($result->success, $result->error ?? 'Charon discharge should use standard action resolver.');
chAssert($charon->closed, 'Standard discharge should close Charon.');
chAssert(($state->battle['strike']['kind'] ?? null) === 'discharge', 'Discharge should expose standard strike kind.');

echo "Charon regression tests passed.\n";
