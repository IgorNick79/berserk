<?php
// tools/test_action_defense.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\ActionResolver;
use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function adAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function adCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'] ?? 1,
        ukid: $overrides['ukid'] ?? ('test_' . ($overrides['instanceId'] ?? 1)),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 5,
        hpMax: $overrides['hpMax'] ?? 5,
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        strikeWeak: $overrides['strikeWeak'] ?? 0,
        strikeMedium: $overrides['strikeMedium'] ?? 0,
        strikeStrong: $overrides['strikeStrong'] ?? 0,
        prop: $overrides['prop'] ?? [],
        modifiers: $overrides['modifiers'] ?? [],
    );
}

function adState(CardInstance ...$cards): GameState
{
    $state = new GameState(801, 1, 2);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 2,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
        'hidden_row_revealed' => true,
    ];
    foreach ($cards as $card) {
        $state->addCard($card);
    }
    return $state;
}

// action_type=cast respects magic/spell defenses, but stamina alone does not block cast.
$caster = adCard(['instanceId' => 1]);
$zozTarget = adCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'prop' => ['zoz' => true]]);
$staminaTarget = adCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'prop' => ['stamina' => true]]);
$state = adState($caster, $zozTarget, $staminaTarget);
$resolver = new ActionResolver($state, new Engine());
$castAction = ['type' => 'row_spell', 'action_type' => 'cast'];
adAssert($resolver->isTargetDefendedByAction($castAction, 2, $caster), 'Cast action_type should be blocked by zoz.');
adAssert(!$resolver->isTargetDefendedByAction($castAction, 3, $caster), 'Cast action_type should not be blocked by stamina.');
adAssert(!$resolver->isTargetDefendedByAction(['type' => 'row_spell', 'action_type' => 'unknown'], 2, $caster), 'Unknown action_type should not crash or invent a defense category.');

// Tergala row_spell checks each selected target independently.
$source = adCard(['instanceId' => 10, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 1]);
$blocked = adCard(['instanceId' => 11, 'owner' => GameState::PLAYER_PLAYER, 'row' => 2, 'col' => 1, 'hp' => 5, 'hpMax' => 5, 'prop' => ['zom' => true]]);
$open = adCard(['instanceId' => 12, 'owner' => GameState::PLAYER_PLAYER, 'row' => 2, 'col' => 2, 'hp' => 5, 'hpMax' => 5]);
$state = adState($source, $blocked, $open);
$state->battle['pending_row_spell_pick'] = [
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 10,
    'action' => ['type' => 'row_spell', 'action_type' => 'cast'],
    'x' => 2,
    'candidates' => [11, 12],
    'dice' => 6,
    'level' => 'strong',
    'val' => 2,
];
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_row_spell_targets', [
    'target_ids' => [11, 12],
]));
adAssert($result->success, $result->error ?? 'Row spell selection should resolve.');
adAssert($blocked->hp === 5, 'Protected row spell target should not take damage.');
adAssert($open->hp === 3, 'Unprotected row spell target should take damage.');
$rowTargets = $state->battle['strike']['row_spell']['targets'] ?? [];
adAssert(!empty($rowTargets[0]['defended']) && empty($rowTargets[1]['defended']), 'Row spell result should mark only the protected target as defended.');

// Wound transfer actions must carry a known action_type instead of silently bypassing defenses.
$badVolkhv = adCard([
    'instanceId' => 18,
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['actions' => [[
        'key' => 'volkhvovanie',
        'type' => 'wound_transfer',
        'donor_filter' => 'wounded',
        'target_filter' => 'enemy',
    ]]],
]);
$badDonor = adCard(['instanceId' => 19, 'owner' => GameState::PLAYER_HOST, 'hp' => 3, 'hpMax' => 5]);
$state = adState($badVolkhv, $badDonor);
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 18,
    'action_key' => 'volkhvovanie',
]));
adAssert(!$result->success && $result->error === 'Для перераспределения ран не задана категория защиты', 'Wound transfer without action_type should fail with diagnostics.');
adAssert(empty($state->battle['pending_wound_transfer']), 'Wound transfer without action_type should not open pending.');

$badVolkhv->prop['actions'][0]['action_type'] = 'mystery';
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 18,
    'action_key' => 'volkhvovanie',
]));
adAssert(!$result->success && $result->error === 'Неизвестная категория защиты: mystery', 'Wound transfer with unknown action_type should fail with diagnostics.');
adAssert(empty($state->battle['pending_wound_transfer']), 'Wound transfer with unknown action_type should not open pending.');

// Wound transfer blocks before any transfer is committed.
$volkhv = adCard([
    'instanceId' => 20,
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['actions' => [[
        'key' => 'volkhvovanie',
        'type' => 'wound_transfer',
        'action_type' => 'cast',
        'donor_filter' => 'wounded',
        'target_filter' => 'enemy',
        'max_transfer' => 2,
    ]]],
]);
$donor = adCard(['instanceId' => 21, 'owner' => GameState::PLAYER_HOST, 'hp' => 3, 'hpMax' => 5]);
$protectedEnemy = adCard(['instanceId' => 22, 'owner' => GameState::PLAYER_PLAYER, 'hp' => 5, 'hpMax' => 5, 'prop' => ['zoz' => true]]);
$state = adState($volkhv, $donor, $protectedEnemy);
$engine = new Engine();
$result = $engine->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 20,
    'action_key' => 'volkhvovanie',
]));
adAssert($result->success, $result->error ?? 'Wound transfer should start.');
$engine->apply($state, GameState::PLAYER_HOST, new Command('wt_source', ['donor_id' => 21]));
$engine->apply($state, GameState::PLAYER_HOST, new Command('wt_amount', ['amount' => 2]));
$result = $engine->apply($state, GameState::PLAYER_HOST, new Command('wt_target', ['target_id' => 22]));
adAssert(!$result->success && $result->error === 'Цель защищена от заклинания', 'Protected wound transfer target should be rejected with a clear message.');
adAssert($donor->hp === 3 && $protectedEnemy->hp === 5, 'Blocked wound transfer should not heal donor or wound target.');
adAssert(($state->battle['pending_wound_transfer']['step'] ?? null) === 'target', 'Blocked wound transfer should keep target choice pending.');

// Whip is impact; stamina blocks it and does not close the source.
$keeper = adCard(['instanceId' => 30, 'owner' => GameState::PLAYER_HOST, 'hp' => 8, 'hpMax' => 8]);
$ally = adCard(['instanceId' => 31, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 4, 'hp' => 5, 'hpMax' => 5, 'prop' => ['stamina' => true]]);
$state = adState($keeper, $ally);
$state->battle['pending_whip'] = [
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 30,
    'targets' => [31],
    'value' => 1,
    'action' => ['type' => 'whip', 'action_type' => 'impact'],
];
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_whip_target', ['target_id' => 31]));
adAssert($result->success && in_array('Цель защищена от воздействия', $result->events, true), 'Stamina should block whip as a resolved impact defense.');
adAssert(!$keeper->closed && $ally->hp === 5 && $ally->move === 1 && empty($ally->modifiers), 'Blocked whip should not close source, wound target, or apply move bonus.');
adAssert(empty($state->battle['pending_whip']), 'Blocked whip should close pending choice to avoid a stuck UI.');

// Explicit whip action_type is carried through pending and has priority over impact fallback.
$keeper = adCard([
    'instanceId' => 32,
    'owner' => GameState::PLAYER_HOST,
    'hp' => 8,
    'hpMax' => 8,
    'prop' => ['turn_start' => [[
        'type' => 'whip',
        'action_type' => 'cast',
        'label' => 'Щелчок хлыста',
        'value' => 1,
    ]]],
]);
$ally = adCard(['instanceId' => 33, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 4, 'hp' => 5, 'hpMax' => 5, 'prop' => ['stamina' => true]]);
$state = adState($keeper, $ally);
$state->battle['turn_phase'] = [
    'phase' => 'start',
    'active_key' => GameState::PLAYER_HOST,
    'passive_key' => GameState::PLAYER_PLAYER,
    'side' => 'active',
    'passive_queue' => [],
    'active_queue' => [[
        'id' => 'turn_start_32_0',
        'type' => 'turn_start:whip',
        'label' => 'Щелчок хлыста',
        'card_id' => 32,
        'ukid' => $keeper->ukid,
        'row' => 3,
        'col' => 3,
        'stacked' => false,
        'payload' => $keeper->prop['turn_start'][0],
    ]],
    'sub' => null,
    'pending_ack' => null,
];
$engine = new Engine();
$result = $engine->apply($state, GameState::PLAYER_HOST, new Command('turn_task', [
    'task_id' => 'turn_start_32_0',
]));
adAssert($result->success, $result->error ?? 'Whip turn task with explicit action_type should open pending.');
adAssert(($state->battle['pending_whip']['action']['action_type'] ?? null) === 'cast', 'Whip pending should preserve explicit action_type.');
$result = $engine->apply($state, GameState::PLAYER_HOST, new Command('choose_whip_target', ['target_id' => 33]));
adAssert($result->success, $result->error ?? 'Explicit cast action_type should not be blocked by stamina fallback.');
adAssert($ally->hp === 4 && $ally->move === 2, 'Whip with explicit cast action_type should apply when only stamina is present.');
adAssert(empty($state->battle['pending_whip']), 'Resolved whip with explicit action_type should close pending.');

// Oyuun-style open+damage is blocked before opening or damage.
$oyuun = adCard(['instanceId' => 40, 'owner' => GameState::PLAYER_HOST, 'closed' => false]);
$closedAlly = adCard(['instanceId' => 41, 'owner' => GameState::PLAYER_HOST, 'closed' => true, 'hp' => 5, 'hpMax' => 5, 'prop' => ['stamina' => true]]);
$state = adState($oyuun, $closedAlly);
$state->battle['turn_instant_stack'] = [
    'state' => 'ordering',
    'priority' => GameState::PLAYER_HOST,
    'participants' => [GameState::PLAYER_HOST],
    'passed' => [],
    'phase' => 'turn',
    'continuation' => ['type' => 'manual'],
    'next_sequence' => 1,
    'stack' => [[
        'card_id' => 40,
        'player' => GameState::PLAYER_HOST,
        'instant_key' => 'battle_frenzy',
        'label' => 'Боевое исступление',
        'effect' => ['type' => 'open', 'damage' => 1],
        'target_id' => 41,
        'sequence' => 0,
        'cost' => 0,
        'close_source' => true,
        'action_type' => '',
    ]],
];
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('pass_turn_instant'));
adAssert($result->success, $result->error ?? 'Turn instant stack should resolve.');
$summary = $state->battle['turn_instant_result']['summary'][0] ?? [];
adAssert(($summary['applied'] ?? true) === false && ($summary['reason'] ?? '') === 'Цель защищена', 'Blocked instant should report defense.');
adAssert($closedAlly->closed && $closedAlly->hp === 5, 'Blocked open instant should not open or wound target.');

// Open without damage is still an impact-like state change and should be defended.
$oyuun = adCard(['instanceId' => 42, 'owner' => GameState::PLAYER_HOST, 'closed' => false]);
$closedAlly = adCard(['instanceId' => 43, 'owner' => GameState::PLAYER_HOST, 'closed' => true, 'hp' => 5, 'hpMax' => 5, 'prop' => ['stamina' => true]]);
$state = adState($oyuun, $closedAlly);
$state->battle['turn_instant_stack'] = [
    'state' => 'ordering',
    'priority' => GameState::PLAYER_HOST,
    'participants' => [GameState::PLAYER_HOST],
    'passed' => [],
    'phase' => 'turn',
    'continuation' => ['type' => 'manual'],
    'next_sequence' => 1,
    'stack' => [[
        'card_id' => 42,
        'player' => GameState::PLAYER_HOST,
        'instant_key' => 'open_only',
        'label' => 'Открыть',
        'effect' => ['type' => 'open'],
        'target_id' => 43,
        'sequence' => 0,
        'cost' => 0,
        'close_source' => true,
        'action_type' => '',
    ]],
];
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('pass_turn_instant'));
adAssert($result->success, $result->error ?? 'Open-only instant stack should resolve.');
$summary = $state->battle['turn_instant_result']['summary'][0] ?? [];
adAssert(($summary['applied'] ?? true) === false && ($summary['reason'] ?? '') === 'Цель защищена', 'Open-only instant should report defense.');
adAssert($closedAlly->closed && $closedAlly->hp === 5, 'Blocked open-only instant should not open target.');

// Open without protection still works.
$oyuun = adCard(['instanceId' => 44, 'owner' => GameState::PLAYER_HOST, 'closed' => false]);
$closedAlly = adCard(['instanceId' => 48, 'owner' => GameState::PLAYER_HOST, 'closed' => true, 'hp' => 5, 'hpMax' => 5]);
$state = adState($oyuun, $closedAlly);
$state->battle['turn_instant_stack'] = [
    'state' => 'ordering',
    'priority' => GameState::PLAYER_HOST,
    'participants' => [GameState::PLAYER_HOST],
    'passed' => [],
    'phase' => 'turn',
    'continuation' => ['type' => 'manual'],
    'next_sequence' => 1,
    'stack' => [[
        'card_id' => 44,
        'player' => GameState::PLAYER_HOST,
        'instant_key' => 'open_only',
        'label' => 'Открыть',
        'effect' => ['type' => 'open'],
        'target_id' => 48,
        'sequence' => 0,
        'cost' => 0,
        'close_source' => true,
        'action_type' => '',
    ]],
];
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('pass_turn_instant'));
adAssert($result->success, $result->error ?? 'Unprotected open-only instant stack should resolve.');
$summary = $state->battle['turn_instant_result']['summary'][0] ?? [];
adAssert(($summary['applied'] ?? false) === true, 'Unprotected open-only instant should apply.');
adAssert(!$closedAlly->closed && $closedAlly->hp === 5, 'Unprotected open-only instant should open target without damage.');

// Effects with their own combat resolution should not get a premature action_type defense check.
$instSource = adCard(['instanceId' => 45, 'owner' => GameState::PLAYER_HOST, 'closed' => false]);
$forcedAttacker = adCard([
    'instanceId' => 46,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 3,
    'closed' => false,
    'prop' => ['stamina' => true],
    'strikeWeak' => 1,
    'strikeMedium' => 2,
    'strikeStrong' => 3,
]);
$forcedTarget = adCard(['instanceId' => 47, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 4]);
$state = adState($instSource, $forcedAttacker, $forcedTarget);
$state->battle['turn_instant_stack'] = [
    'state' => 'ordering',
    'priority' => GameState::PLAYER_HOST,
    'participants' => [GameState::PLAYER_HOST],
    'passed' => [],
    'phase' => 'turn',
    'continuation' => ['type' => 'manual'],
    'next_sequence' => 1,
    'stack' => [[
        'card_id' => 45,
        'player' => GameState::PLAYER_HOST,
        'instant_key' => 'hypnosis',
        'label' => 'Гипноз',
        'effect' => ['type' => 'forced_strike_adjacent', 'forced_target_id' => 47],
        'target_id' => 46,
        'sequence' => 0,
        'cost' => 0,
        'close_source' => true,
        'action_type' => 'impact',
    ]],
];
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('pass_turn_instant'));
adAssert($result->success, $result->error ?? 'Forced strike instant stack should resolve.');
$summary = $state->battle['turn_instant_result']['summary'][0] ?? [];
adAssert(($summary['applied'] ?? false) === true && ($summary['effect_type'] ?? '') === 'forced_strike_adjacent', 'Forced strike instant should not be pre-blocked by stamina as impact.');

// Execute is still execute mechanically, but uses impact for defenses.
$executioner = adCard([
    'instanceId' => 50,
    'owner' => GameState::PLAYER_HOST,
    'prop' => ['actions' => [[
        'key' => 'finish',
        'type' => 'execute',
        'value' => 5,
    ]]],
]);
$staminaVictim = adCard(['instanceId' => 51, 'owner' => GameState::PLAYER_PLAYER, 'hp' => 4, 'hpMax' => 5, 'prop' => ['stamina' => true]]);
$state = adState($executioner, $staminaVictim);
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 50,
    'target_id' => 51,
    'action_key' => 'finish',
]));
adAssert(!$result->success && $result->error === 'Цель защищена от добивания', 'Stamina should block execute through impact defense.');
adAssert(!$staminaVictim->dying && $staminaVictim->hp === 4 && empty($state->battle['strike']), 'Blocked execute should not kill target or create a result strike.');

echo "Action defense regression tests passed.\n";
