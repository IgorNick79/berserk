<?php
// tools/test_than_haneranga.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\Command;
use Berserk\Core\Dice;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;
use Berserk\Core\TurnProcessor;
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function thanAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function thanProp(): array
{
    return [
        'damage_reduction' => [[
            'line' => true,
            'types' => ['magic', 'discharge'],
            'value' => 1,
        ]],
        'line_death_next_strike_bonus' => ['value' => 2],
    ];
}

function thanState(CardInstance ...$cards): GameState
{
    $state = new GameState(52, 101, 202);
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

function thanCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'] ?? 1,
        ukid: $overrides['ukid'] ?? 's1_52',
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 10,
        hpMax: $overrides['hpMax'] ?? 10,
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 2,
        moveMax: $overrides['moveMax'] ?? 2,
        strikeWeak: $overrides['strikeWeak'] ?? 1,
        strikeMedium: $overrides['strikeMedium'] ?? 2,
        strikeStrong: $overrides['strikeStrong'] ?? 3,
        prop: $overrides['prop'] ?? thanProp(),
        modifiers: $overrides['modifiers'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function thanLineCard(int $id, int $row, int $col, array $overrides = []): CardInstance
{
    return thanCard(array_replace([
        'instanceId' => $id,
        'ukid' => 'line_' . $id,
        'row' => $row,
        'col' => $col,
        'hp' => 4,
        'hpMax' => 4,
        'prop' => ['has_line' => true],
    ], $overrides));
}

function thanEnemy(int $id, int $row, int $col, array $overrides = []): CardInstance
{
    return thanCard(array_replace([
        'instanceId' => $id,
        'ukid' => 'enemy_' . $id,
        'owner' => GameState::PLAYER_PLAYER,
        'row' => $row,
        'col' => $col,
        'hp' => 10,
        'hpMax' => 10,
        'prop' => [],
        'strikeWeak' => 0,
        'strikeMedium' => 0,
        'strikeStrong' => 0,
    ], $overrides));
}

function thanModifierValue(CardInstance $card, string $stat): int
{
    $value = 0;
    foreach ($card->modifiers as $modifier) {
        if (($modifier['stat'] ?? '') === $stat) {
            $value += (int) ($modifier['value'] ?? 0);
        }
    }
    return $value;
}

function thanApply(GameState $state, string $playerKey, Command $command): void
{
    $result = (new Engine())->apply($state, $playerKey, $command);
    thanAssert($result->success, $result->error ?? 'Command failed.');
}

$tan = thanCard(['instanceId' => 1, 'row' => 3, 'col' => 3]);
$ally = thanLineCard(2, 3, 4);
$enemy = thanEnemy(3, 5, 5);
$state = thanState($tan, $ally, $enemy);
thanAssert(CardStats::getDamageReduction($state, $enemy, $tan, 'magic') === 1, 'Than should reduce magic damage by 1 while in line.');
thanAssert(CardStats::getDamageReduction($state, $enemy, $tan, 'discharge') === 1, 'Than should reduce discharge damage by 1 while in line.');
thanAssert(CardStats::getDamageReduction($state, $enemy, $tan, 'strike') === 0, 'Than should not reduce normal strike damage.');

$caster = thanEnemy(5, 4, 3, [
    'prop' => ['actions' => [['key' => 'magic', 'type' => 'magic', 'value' => 2]]],
]);
$magicState = thanState(
    thanCard(['instanceId' => 6, 'row' => 3, 'col' => 3]),
    thanLineCard(7, 3, 4),
    $caster
);
$magicState->battle['active'] = GameState::PLAYER_PLAYER;
thanApply($magicState, GameState::PLAYER_PLAYER, new Command('action', [
    'card_id' => 5,
    'target_id' => 6,
    'action_key' => 'magic',
]));
thanAssert(($magicState->battle['strike']['damage_total'] ?? null) === 1, 'Magic action should apply Than damage reduction.');
$magicHtml = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render(
    $magicState,
    GameState::PLAYER_HOST,
    'host',
    [
        's1_52' => ['name' => 'Тан Ханеранга'],
        'line_7' => ['name' => 'Сосед'],
        'enemy_5' => ['name' => 'Циклоп'],
    ],
    '/battle?game=52&first='
);
thanAssert(str_contains($magicHtml, 'Урон снижен на 1'), 'InfoPanel should show damage reduction for magic action.');

$isolated = thanCard(['instanceId' => 4, 'row' => 1, 'col' => 1]);
$isolatedState = thanState($isolated, $enemy);
thanAssert(CardStats::getDamageReduction($isolatedState, $enemy, $isolated, 'magic') === 0, 'Than should not reduce magic damage outside line.');

$tan = thanCard(['instanceId' => 10, 'row' => 1, 'col' => 5]);
$survivorA = thanLineCard(11, 3, 1);
$diedB = thanLineCard(12, 3, 2);
$survivorC = thanLineCard(13, 3, 3);
$otherLineD = thanLineCard(14, 5, 1);
$otherLineE = thanLineCard(15, 5, 2);
$state = thanState($tan, $survivorA, $diedB, $survivorC, $otherLineD, $otherLineE, thanEnemy(16, 6, 6));
$state->battle['active'] = GameState::PLAYER_PLAYER;
(new Engine())->applyDamage($state, $diedB, 99, 'magic', $state->getCard(16));
thanAssert($diedB->zone === CardInstance::ZONE_GRAVEYARD, 'Dead line creature should be finalized outside strike.');
thanAssert(thanModifierValue($survivorA, 'next_strike_bonus') === 2, 'Creature from the same line should receive +2 next strike.');
thanAssert(thanModifierValue($survivorC, 'next_strike_bonus') === 2, 'Second creature from the same line should receive +2 next strike.');
thanAssert(thanModifierValue($otherLineD, 'next_strike_bonus') === 0, 'Separate line should not receive the bonus.');
thanAssert(thanModifierValue($otherLineE, 'next_strike_bonus') === 0, 'Separate line pair should not receive the bonus.');
$state->battle['active'] = GameState::PLAYER_HOST;
$state->getCard(16)->row = 4;
$state->getCard(16)->col = 2;
$_SESSION['debug_roll'] = '6,1';
Dice::init();
$result = (new StrikeResolver($state, new Engine()))->declare(GameState::PLAYER_HOST, new Command('strike', [
    'card_id' => 11,
    'target_id' => 16,
]));
thanAssert($result->success, $result->error ?? 'Recipient strike should be declared.');
thanAssert(thanModifierValue($survivorA, 'next_strike_bonus') === 0, 'Striking recipient should consume only its own next strike bonus.');
thanAssert(thanModifierValue($survivorC, 'next_strike_bonus') === 2, 'Other recipient should keep its independent next strike bonus.');

$source = thanCard(['instanceId' => 20, 'row' => 1, 'col' => 5]);
$nonLine = thanCard(['instanceId' => 21, 'row' => 3, 'col' => 2, 'prop' => []]);
$lineAlly = thanLineCard(22, 3, 3);
$state = thanState($source, $nonLine, $lineAlly, thanEnemy(23, 6, 6));
$state->battle['active'] = GameState::PLAYER_PLAYER;
(new Engine())->applyDamage($state, $nonLine, 99, 'magic', $state->getCard(23));
thanAssert(thanModifierValue($lineAlly, 'next_strike_bonus') === 0, 'Non-line death should not trigger Than bonus.');

$source = thanCard(['instanceId' => 30, 'row' => 1, 'col' => 5]);
$ownTurnDied = thanLineCard(31, 3, 2);
$ownTurnAlly = thanLineCard(32, 3, 3);
$state = thanState($source, $ownTurnDied, $ownTurnAlly, thanEnemy(33, 6, 6));
$state->battle['active'] = GameState::PLAYER_HOST;
(new Engine())->applyDamage($state, $ownTurnDied, 99, 'magic', $state->getCard(33));
thanAssert(thanModifierValue($ownTurnAlly, 'next_strike_bonus') === 0, 'Line death on own turn should not trigger Than bonus.');

$tan = thanCard(['instanceId' => 40, 'row' => 3, 'col' => 3]);
$selfDeathAllyA = thanLineCard(41, 3, 2);
$selfDeathAllyB = thanLineCard(42, 2, 3);
$selfDeathDiagonal = thanLineCard(43, 2, 2);
$selfDeathOther = thanLineCard(45, 5, 5);
$state = thanState($tan, $selfDeathAllyA, $selfDeathAllyB, $selfDeathDiagonal, $selfDeathOther, thanEnemy(44, 6, 6));
$state->battle['active'] = GameState::PLAYER_PLAYER;
thanAssert(CardStats::isInLine($state, $selfDeathDiagonal), 'Diagonal card should still be in line through other cards.');
(new Engine())->applyDamage($state, $tan, 99, 'magic', $state->getCard(44));
thanAssert($tan->zone === CardInstance::ZONE_GRAVEYARD, 'Than self death should be finalized outside strike.');
thanAssert(thanModifierValue($selfDeathAllyA, 'next_strike_bonus') === 2, 'Than self death should buff his former line ally.');
thanAssert(thanModifierValue($selfDeathAllyB, 'next_strike_bonus') === 2, 'Than self death should buff every former line ally.');
thanAssert(thanModifierValue($selfDeathDiagonal, 'next_strike_bonus') === 0, 'Than self death should not buff diagonal line card that was not directly in line with him.');
thanAssert(thanModifierValue($selfDeathOther, 'next_strike_bonus') === 0, 'Than self death should not buff another line.');

$tan = thanCard(['instanceId' => 50, 'row' => 1, 'col' => 5]);
$stackReceiver = thanLineCard(51, 3, 2);
$diedA = thanLineCard(52, 3, 1);
$diedB = thanLineCard(53, 3, 3);
$diedC = thanLineCard(54, 2, 2);
$state = thanState($tan, $stackReceiver, $diedA, $diedB, $diedC, thanEnemy(55, 6, 6));
$state->battle['active'] = GameState::PLAYER_PLAYER;
(new Engine())->applyDamage($state, $diedA, 99, 'magic', $state->getCard(55));
(new Engine())->applyDamage($state, $diedB, 99, 'magic', $state->getCard(55));
thanAssert(thanModifierValue($stackReceiver, 'next_strike_bonus') === 4, 'Two Than death triggers should stack to +4.');
(new Engine())->applyDamage($state, $diedC, 99, 'magic', $state->getCard(55));
thanAssert(thanModifierValue($stackReceiver, 'next_strike_bonus') === 6, 'Than next strike bonus should stack without a cap.');
(new TurnProcessor($state, new Engine()))->afterEndPhase(GameState::PLAYER_PLAYER, GameState::PLAYER_HOST);
thanAssert(thanModifierValue($stackReceiver, 'next_strike_bonus') === 6, 'Unused next strike bonus should persist across turns.');

$thrower = thanCard([
    'instanceId' => 60,
    'row' => 3,
    'col' => 3,
    'prop' => [
        'actions' => [[
            'type' => 'throw',
            'range' => 9,
            'strike' => ['weak' => 1, 'medium' => 1, 'strong' => 1],
            'no_close' => true,
        ]],
    ],
    'modifiers' => [['stat' => 'next_strike_bonus', 'value' => 2]],
]);
$target = thanEnemy(61, 6, 6);
$state = thanState($thrower, $target);
thanApply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 60,
    'target_id' => 61,
    'action_key' => 'throw',
]));
thanAssert(thanModifierValue($thrower, 'next_strike_bonus') === 2, 'Non-strike action should not consume Than next strike bonus.');

$striker = thanCard([
    'instanceId' => 70,
    'row' => 3,
    'col' => 3,
    'strikeWeak' => 1,
    'strikeMedium' => 2,
    'strikeStrong' => 4,
    'modifiers' => [['stat' => 'next_strike_bonus', 'value' => 2]],
]);
$defender = thanEnemy(71, 4, 3);
$state = thanState($striker, $defender);
$resolver = new StrikeResolver($state, new Engine());
$_SESSION['debug_roll'] = '6,1';
Dice::init();
$result = $resolver->declare(GameState::PLAYER_HOST, new Command('strike', [
    'card_id' => 70,
    'target_id' => 71,
]));
thanAssert($result->success, $result->error ?? 'Than bonus strike should be declared.');
thanAssert(($state->battle['strike']['damage_total'] ?? 0) === 6, 'Next strike bonus should add to strike damage.');
thanAssert(($state->battle['strike']['next_strike_bonus'] ?? 0) === 2, 'Strike result should expose applied next strike bonus.');
thanAssert(thanModifierValue($striker, 'next_strike_bonus') === 0, 'Next strike bonus should be consumed by the strike.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    [
        's1_52' => ['name' => 'Тан Ханеранга'],
        'enemy_71' => ['name' => 'Цель'],
    ],
    '/battle?game=52&first='
);
thanAssert(str_contains($html, '+2 к урону (следующий удар)'), 'InfoPanel should show applied next strike bonus.');

$tan = thanCard(['instanceId' => 80, 'row' => 1, 'col' => 5]);
$lineVictim = thanLineCard(81, 4, 3, ['hp' => 1, 'hpMax' => 1]);
$lineAlly = thanLineCard(82, 4, 4);
$attacker = thanEnemy(83, 3, 3, ['strikeWeak' => 1, 'strikeMedium' => 1, 'strikeStrong' => 1]);
$state = thanState($tan, $lineVictim, $lineAlly, $attacker);
$state->battle['active'] = GameState::PLAYER_PLAYER;
$_SESSION['debug_roll'] = '6,1';
Dice::init();
$result = (new StrikeResolver($state, new Engine()))->declare(GameState::PLAYER_PLAYER, new Command('strike', [
    'card_id' => 83,
    'target_id' => 81,
]));
thanAssert($result->success, $result->error ?? 'Strike killing a line creature should be declared.');
thanApply($state, GameState::PLAYER_HOST, new Command('choose_defender', ['defender_id' => 0]));
thanAssert(thanModifierValue($lineAlly, 'next_strike_bonus') === 2, 'Strike death should trigger Than bonus for line ally.');
thanAssert(!empty($state->battle['strike']['line_death_next_strike_bonus']), 'Strike result should store Than trigger for InfoPanel.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    [
        's1_52' => ['name' => 'Тан Ханеранга'],
        'line_81' => ['name' => 'Павший'],
        'line_82' => ['name' => 'Соратник'],
        'enemy_83' => ['name' => 'Враг'],
    ],
    '/battle?game=52&first='
);
thanAssert(str_contains($html, 'Тан Ханеранга'), 'InfoPanel should name Than as the line death bonus source.');
thanAssert(str_contains($html, 'Соратник получает +2 к следующему удару'), 'InfoPanel should show Than line death bonus message.');

echo "Than Haneranga regression tests passed.\n";
