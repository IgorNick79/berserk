<?php
// tools/test_paladin_allamora.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\BattleHelper;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function paladinProp(): array
{
    return [
        'ability' => [
            'line' => true,
            'only' => ['strike'],
            'value' => 1,
            'element' => 'dark',
        ],
        'column_range_aura' => [
            'types' => ['shot', 'throw', 'discharge'],
            'value' => 1,
            'target' => 'ally',
        ],
    ];
}

function pState(CardInstance ...$cards): GameState
{
    $state = new GameState(188, 101, 202);
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

function pCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'] ?? 1,
        ukid: $overrides['ukid'] ?? 'card_' . ($overrides['instanceId'] ?? 1),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 10,
        hpMax: $overrides['hpMax'] ?? ($overrides['hp'] ?? 10),
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        strikeWeak: $overrides['strikeWeak'] ?? 2,
        strikeMedium: $overrides['strikeMedium'] ?? 3,
        strikeStrong: $overrides['strikeStrong'] ?? 4,
        prop: $overrides['prop'] ?? [],
        element: $overrides['element'] ?? 'neutral',
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
    );
}

function paladin(array $overrides = []): CardInstance
{
    return pCard($overrides + [
        'instanceId' => 1,
        'ukid' => 's1_188',
        'row' => 3,
        'col' => 3,
        'prop' => paladinProp(),
    ]);
}

function lineAlly(array $overrides = []): CardInstance
{
    return pCard($overrides + [
        'instanceId' => 2,
        'ukid' => 'line_ally',
        'row' => 3,
        'col' => 4,
        'prop' => ['has_line' => true],
    ]);
}

function enemy(int $id, int $row, int $col, string $element = 'neutral'): CardInstance
{
    return pCard([
        'instanceId' => $id,
        'ukid' => 'enemy_' . $id,
        'owner' => GameState::PLAYER_PLAYER,
        'row' => $row,
        'col' => $col,
        'hp' => 12,
        'hpMax' => 12,
        'element' => $element,
    ]);
}

function rangedAlly(array $actions, int $col = 3, array $overrides = []): CardInstance
{
    return pCard($overrides + [
        'instanceId' => 10,
        'ukid' => 'ranged_ally',
        'row' => 3,
        'col' => $col,
        'strikeWeak' => 0,
        'strikeMedium' => 0,
        'strikeStrong' => 0,
        'prop' => ['actions' => $actions],
    ]);
}

$darkTarget = enemy(3, 4, 3, 'dark');
$neutralTarget = enemy(4, 4, 4, 'neutral');
$state = pState(paladin(), lineAlly(), $darkTarget, $neutralTarget);
assertTrue(CardStats::getAbilityBonus($state, $state->getCard(1), $darkTarget, 'strike') === 1, 'Paladin in line should get +1 strike against dark cards.');
assertTrue(CardStats::getAbilityBonus($state, $state->getCard(1), $neutralTarget, 'strike') === 0, 'Paladin should not get line bonus against non-dark cards.');
assertTrue(CardStats::getAbilityBonus($state, $state->getCard(1), $darkTarget, 'shot') === 0, 'Paladin line bonus should not apply to non-strike actions.');

$state = pState(paladin(), $darkTarget);
assertTrue(CardStats::getAbilityBonus($state, $state->getCard(1), $darkTarget, 'strike') === 0, 'Paladin out of line should not get dark strike bonus.');

$shot = ['key' => 'shot', 'type' => 'shot', 'range' => 2, 'value' => 1];
$throw = ['key' => 'throw', 'type' => 'throw', 'range' => 2, 'value' => 1];
$discharge = ['key' => 'discharge', 'type' => 'discharge', 'range' => 2, 'value' => 1];
$magic = ['key' => 'magic', 'type' => 'magic', 'range' => 2, 'value' => 1];

$state = pState(paladin(), rangedAlly([$shot]), enemy(20, 6, 3));
assertTrue(CardStats::getEffectiveRange($state, $state->getCard(10), $shot) === 3, 'Shot ally in Paladin column should receive +1 range.');

$state = pState(paladin(), rangedAlly([$throw]), enemy(20, 6, 3));
assertTrue(CardStats::getEffectiveRange($state, $state->getCard(10), $throw) === 3, 'Throw ally in Paladin column should receive +1 range.');

$state = pState(paladin(), rangedAlly([$discharge]), enemy(20, 6, 3));
assertTrue(CardStats::getEffectiveRange($state, $state->getCard(10), $discharge) === 3, 'Discharge ally in Paladin column should receive +1 range.');

$state = pState(paladin(), rangedAlly([$shot], 4), enemy(20, 6, 4));
assertTrue(CardStats::getEffectiveRange($state, $state->getCard(10), $shot) === 2, 'Ally in another column should not receive Paladin range aura.');

$enemyShooter = rangedAlly([$shot], 3, ['owner' => GameState::PLAYER_PLAYER]);
$state = pState(paladin(), $enemyShooter, enemy(20, 6, 3));
assertTrue(CardStats::getEffectiveRange($state, $enemyShooter, $shot) === 2, 'Enemy creature in Paladin column should not receive ally range aura.');

$moving = rangedAlly([$shot], 2);
$state = pState(paladin(), $moving, enemy(20, 6, 2));
assertTrue(CardStats::getEffectiveRange($state, $moving, $shot) === 2, 'Range aura should be absent before moving into Paladin column.');
$moving->col = 3;
assertTrue(CardStats::getEffectiveRange($state, $moving, $shot) === 3, 'Range aura should appear after moving into Paladin column.');
$moving->col = 4;
assertTrue(CardStats::getEffectiveRange($state, $moving, $shot) === 2, 'Range aura should disappear after moving out of Paladin column.');

$multi = rangedAlly([$shot, $throw, $discharge], 3);
$state = pState(paladin(), $multi, enemy(20, 6, 3));
assertTrue(CardStats::getColumnRangeAuraBonus($state, $multi, 'shot') === 1, 'A single Paladin should give +1, not +1 per matching type.');
assertTrue(CardStats::getEffectiveRange($state, $multi, $shot) === 3, 'Multi-type creature should receive +1 to shot range.');
assertTrue(CardStats::getEffectiveRange($state, $multi, $throw) === 3, 'Multi-type creature should receive +1 to throw range.');
assertTrue(CardStats::getEffectiveRange($state, $multi, $discharge) === 3, 'Multi-type creature should receive +1 to discharge range.');
assertTrue(CardStats::getEffectiveRange($state, rangedAlly([$magic], 3), $magic) === 2, 'Unlisted ranged action type should not receive Paladin aura.');

$target = enemy(20, 6, 3);
$state = pState(paladin(), rangedAlly([$shot], 3), $target);
$highlight = BattleHelper::getAttackTargets($state, $state->getCard(10), 'action:shot', GameState::PLAYER_HOST);
assertTrue(!empty($highlight[$target->instanceId]), 'Highlight should include target inside aura-extended range.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 10,
    'action_key' => 'shot',
    'target_id' => $target->instanceId,
]));
assertTrue($result->success, $result->error ?? 'Server validation should accept target inside aura-extended range.');

$target = enemy(20, 6, 4);
$state = pState(paladin(), rangedAlly([$shot], 4), $target);
$highlight = BattleHelper::getAttackTargets($state, $state->getCard(10), 'action:shot', GameState::PLAYER_HOST);
assertTrue(empty($highlight[$target->instanceId]), 'Highlight should exclude target outside non-aura range.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 10,
    'action_key' => 'shot',
    'target_id' => $target->instanceId,
]));
assertTrue(!$result->success && $result->error === 'Превышена дальность', 'Server validation should reject the same target without aura range.');

echo "Paladin Allamora regression tests passed.\n";
