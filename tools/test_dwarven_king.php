<?php
// tools/test_dwarven_king.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\TurnProcessor;
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function dkAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dkProp(): array
{
    return [
        'has_line' => true,
        'damage_reduction' => [[
            'line' => true,
            'types' => ['strike', 'tap', 'uchr', 'shot', 'throw'],
            'value' => 1,
        ]],
        'line_strike_aura' => [
            'value' => 1,
            'exclude_self' => true,
        ],
        'attack_redirect' => [
            'optional' => true,
            'attack_types' => 'ranged',
            'protected_target' => [
                'owner' => 'self',
                'relative_rows' => [2, 3],
            ],
            'redirect_to' => [
                'relation' => 'same_line',
                'anchor' => 'source',
                'exclude_source' => true,
            ],
            'uses_per_turn' => 1,
            'usage_scope' => 'original_target',
        ],
    ];
}

function dkState(CardInstance ...$cards): GameState
{
    $state = new GameState(67, 101, 202);
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

function dkCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'] ?? 1,
        ukid: $overrides['ukid'] ?? 's1_67',
        owner: $overrides['owner'] ?? GameState::PLAYER_PLAYER,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 5,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 10,
        hpMax: $overrides['hpMax'] ?? 10,
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        strikeWeak: $overrides['strikeWeak'] ?? 1,
        strikeMedium: $overrides['strikeMedium'] ?? 2,
        strikeStrong: $overrides['strikeStrong'] ?? 3,
        prop: $overrides['prop'] ?? dkProp(),
        flags: $overrides['flags'] ?? [],
    );
}

function dkCreature(int $id, string $owner, int $row, int $col, array $overrides = []): CardInstance
{
    return dkCard(array_replace([
        'instanceId' => $id,
        'ukid' => 'card_' . $id,
        'owner' => $owner,
        'row' => $row,
        'col' => $col,
        'prop' => ['has_line' => true],
        'strikeWeak' => 2,
        'strikeMedium' => 3,
        'strikeStrong' => 4,
    ], $overrides));
}

function dkShooter(int $id, string $owner, int $row, int $col, array $action = []): CardInstance
{
    return dkCard([
        'instanceId' => $id,
        'ukid' => 'shooter_' . $id,
        'owner' => $owner,
        'row' => $row,
        'col' => $col,
        'prop' => [
            'actions' => [array_replace([
                'type' => 'shot',
                'key' => 'shot',
                'name' => 'Выстрел',
                'range' => 9,
                'value' => 1,
                'no_close' => true,
            ], $action)],
        ],
    ]);
}

function dkApply(GameState $state, string $playerKey, Command $cmd): \Berserk\Core\Result
{
    return (new Engine())->apply($state, $playerKey, $cmd);
}

function dkCardsInfo(): array
{
    return [
        's1_67' => ['name' => 'Гномий король'],
        'card_2' => ['name' => 'Прикрываемый'],
        'card_3' => ['name' => 'Щитоносец'],
        'card_4' => ['name' => 'Дальний союзник'],
        'card_5' => ['name' => 'Второй король'],
        'card_6' => ['name' => 'Королевская цель'],
        'shooter_9' => ['name' => 'Стрелок'],
    ];
}

$king = dkCard(['instanceId' => 1, 'row' => 5, 'col' => 3]);
$ally = dkCreature(2, GameState::PLAYER_PLAYER, 5, 4);
$enemy = dkCreature(9, GameState::PLAYER_HOST, 1, 3, ['prop' => []]);
$state = dkState($king, $ally, $enemy);
dkAssert(CardStats::getDamageReduction($state, $enemy, $king, 'shot') === 1, 'King in line should reduce shot damage by 1.');
dkAssert(CardStats::getDamageReduction($state, $enemy, $king, 'throw') === 1, 'King in line should reduce throw damage by 1.');
dkAssert(CardStats::getDamageReduction($state, $enemy, $king, 'strike') === 1, 'King in line should reduce strike damage by 1.');
dkAssert(CardStats::getDamageReduction($state, $enemy, $king, 'discharge') === 0, 'King should not reduce discharge.');
dkAssert(CardStats::getDamageReduction($state, $enemy, $king, 'magic') === 0, 'King should not reduce magic.');
$ally->row = 4;
$ally->col = 4;
dkAssert(CardStats::getDamageReduction($state, $enemy, $king, 'shot') === 0, 'Isolated King should not reduce ranged damage.');

$state = dkState(
    dkCard(['instanceId' => 1, 'row' => 5, 'col' => 3]),
    dkCreature(2, GameState::PLAYER_PLAYER, 5, 4),
    dkCreature(9, GameState::PLAYER_HOST, 1, 3, ['prop' => []])
);
dkAssert(CardStats::getStrikeValue($state, $state->getCard(2), $state->getCard(9), 'weak') === 3, 'Ally in King line should get +1 strike.');
dkAssert(CardStats::getStrikeValue($state, $state->getCard(1), $state->getCard(9), 'weak') === 1, 'King should not buff himself.');
$state->addCard(dkCard(['instanceId' => 5, 'ukid' => 'card_5', 'row' => 5, 'col' => 2]));
dkAssert(CardStats::getStrikeValue($state, $state->getCard(2), $state->getCard(9), 'weak') === 4, 'Two Kings should stack their strike aura.');
dkAssert(CardStats::getStrikeValue($state, $state->getCard(1), $state->getCard(9), 'weak') === 2, 'Kings should buff each other.');
$state->getCard(2)->row = 4;
$state->getCard(2)->col = 4;
dkAssert(CardStats::getStrikeValue($state, $state->getCard(2), $state->getCard(9), 'weak') === 2, 'Ally outside connected line should not get King aura.');

$state = dkState(
    dkShooter(9, GameState::PLAYER_HOST, 1, 3),
    dkCard(['instanceId' => 1, 'row' => 5, 'col' => 3]),
    dkCreature(2, GameState::PLAYER_PLAYER, 5, 4),
    dkCreature(3, GameState::PLAYER_PLAYER, 5, 2)
);
$result = dkApply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 9,
    'target_id' => 2,
    'action_key' => 'shot',
]));
dkAssert($result->success, $result->error ?? 'Shot should start redirect choice.');
dkAssert(!empty($state->battle['pending_ranged_attack_redirect']), 'Protected back-row target should create redirect pending.');
$pending = $state->battle['pending_ranged_attack_redirect'];
dkAssert(($pending['owner'] ?? null) === GameState::PLAYER_PLAYER, 'Redirect pending should belong to original target owner.');
dkAssert(count($pending['options'] ?? []) === 1, 'Redirect should offer one eligible line target.');
dkAssert(($pending['options'][0]['target_id'] ?? null) === 3, 'Redirect option should target another creature in King line.');
dkAssert(($pending['options'][0]['source_id'] ?? null) === 1, 'Redirect option should preserve concrete King source.');
$beforeOriginalHp = $state->getCard(2)->hp;
$beforeRedirectHp = $state->getCard(3)->hp;
$result = dkApply($state, GameState::PLAYER_PLAYER, new Command('choose_ranged_redirect', [
    'redirect_option' => (string) $pending['options'][0]['option_id'],
]));
dkAssert($result->success, $result->error ?? 'Redirect choice should resolve.');
dkAssert(empty($state->battle['pending_ranged_attack_redirect']), 'Redirect pending should be cleared after choice.');
dkAssert(($state->battle['strike']['target_id'] ?? null) === 3, 'Resolved attack should hit redirected target.');
dkAssert(($state->battle['strike']['ranged_redirect']['original_target_id'] ?? null) === 2, 'Strike should remember original target.');
dkAssert($state->getCard(2)->hp === $beforeOriginalHp, 'Original target should not take redirected damage.');
dkAssert($state->getCard(3)->hp === $beforeRedirectHp - 1, 'Redirect target should take the ranged attack damage.');
dkAssert(!empty($state->getCard(2)->flags['ranged_redirect_used_this_turn']), 'Successful redirect should consume original target limit.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render(
    $state,
    GameState::PLAYER_PLAYER,
    'second',
    dkCardsInfo(),
    '/battle?game=67&second='
);
dkAssert(str_contains($html, 'Гномий король'), 'InfoPanel should mention King redirect source.');
dkAssert(str_contains($html, 'атака перенаправлена'), 'InfoPanel should explain ranged redirect result.');
dkAssert(str_contains($html, 'Прикрываемый') && str_contains($html, 'Щитоносец'), 'InfoPanel should show original and new redirect targets.');

$state->battle['strike'] = null;
$result = dkApply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 9,
    'target_id' => 2,
    'action_key' => 'shot',
]));
dkAssert($result->success, $result->error ?? 'Second shot should resolve without redirect pending.');
dkAssert(empty($state->battle['pending_ranged_attack_redirect']), 'Same original target should not be redirected twice in one turn.');
dkAssert(($state->battle['strike']['target_id'] ?? null) === 2, 'Second shot should stay on original target.');

(new TurnProcessor($state, new Engine()))->continueStartTurn(GameState::PLAYER_HOST);
dkAssert(empty($state->getCard(2)->flags['ranged_redirect_used_this_turn']), 'Redirect limit should reset on a new turn start.');

$state = dkState(
    dkShooter(9, GameState::PLAYER_HOST, 1, 3),
    dkCard(['instanceId' => 1, 'row' => 5, 'col' => 3]),
    dkCreature(2, GameState::PLAYER_PLAYER, 5, 4),
    dkCreature(3, GameState::PLAYER_PLAYER, 5, 2)
);
$result = dkApply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 9,
    'target_id' => 2,
    'action_key' => 'shot',
]));
dkAssert($result->success, $result->error ?? 'Shot should start redirect choice for decline test.');
$result = dkApply($state, GameState::PLAYER_PLAYER, new Command('choose_ranged_redirect', [
    'redirect_option' => '0',
]));
dkAssert($result->success, $result->error ?? 'Decline should resolve original shot.');
dkAssert(empty($state->battle['pending_ranged_attack_redirect']), 'Decline should clear redirect pending.');
dkAssert(($state->battle['strike']['target_id'] ?? null) === 2, 'Decline should keep the original target.');
dkAssert(empty($state->getCard(2)->flags['ranged_redirect_used_this_turn']), 'Decline should not consume redirect limit.');
dkAssert($state->getCard(2)->hp === 9, 'Decline should damage original target.');
dkAssert($state->getCard(3)->hp === 10, 'Decline should not damage redirect candidate.');

$state = dkState(
    dkShooter(9, GameState::PLAYER_HOST, 1, 3),
    dkCard(['instanceId' => 1, 'row' => 5, 'col' => 3]),
    dkCreature(2, GameState::PLAYER_PLAYER, 4, 4),
    dkCreature(3, GameState::PLAYER_PLAYER, 5, 2)
);
$result = dkApply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 9,
    'target_id' => 2,
    'action_key' => 'shot',
]));
dkAssert($result->success, $result->error ?? 'Fourth-row player target should be attackable.');
dkAssert(empty($state->battle['pending_ranged_attack_redirect']), 'Player row 4 should not be protected by King redirect.');

$state = dkState(
    dkShooter(9, GameState::PLAYER_PLAYER, 6, 3),
    dkCard(['instanceId' => 1, 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 3]),
    dkCreature(2, GameState::PLAYER_HOST, 2, 4),
    dkCreature(3, GameState::PLAYER_HOST, 2, 2)
);
$state->battle['active'] = GameState::PLAYER_PLAYER;
$result = dkApply($state, GameState::PLAYER_PLAYER, new Command('action', [
    'card_id' => 9,
    'target_id' => 2,
    'action_key' => 'shot',
]));
dkAssert($result->success, $result->error ?? 'Host row 2 target should be attackable.');
dkAssert(!empty($state->battle['pending_ranged_attack_redirect']), 'Host row 2 should be protected by King redirect.');

$state = dkState(
    dkShooter(9, GameState::PLAYER_HOST, 1, 3, ['type' => 'magic', 'key' => 'magic', 'range' => 9]),
    dkCard(['instanceId' => 1, 'row' => 5, 'col' => 3]),
    dkCreature(2, GameState::PLAYER_PLAYER, 5, 4),
    dkCreature(3, GameState::PLAYER_PLAYER, 5, 2)
);
$result = dkApply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 9,
    'target_id' => 2,
    'action_key' => 'magic',
]));
dkAssert($result->success, $result->error ?? 'Ranged magic should start redirect choice.');
dkAssert(!empty($state->battle['pending_ranged_attack_redirect']), 'Ranged magic should be redirectable.');

$state = dkState(
    dkShooter(9, GameState::PLAYER_HOST, 4, 4, ['type' => 'magic', 'key' => 'magic', 'range' => 0]),
    dkCard(['instanceId' => 1, 'row' => 5, 'col' => 3]),
    dkCreature(2, GameState::PLAYER_PLAYER, 5, 4),
    dkCreature(3, GameState::PLAYER_PLAYER, 5, 2)
);
$result = dkApply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 9,
    'target_id' => 2,
    'action_key' => 'magic',
]));
dkAssert($result->success, $result->error ?? 'Adjacent magic strike should resolve.');
dkAssert(empty($state->battle['pending_ranged_attack_redirect']), 'Adjacent no-range magic should not be redirected.');

$state = dkState(
    dkShooter(9, GameState::PLAYER_HOST, 1, 3),
    dkCard(['instanceId' => 1, 'row' => 5, 'col' => 3]),
    dkCard(['instanceId' => 5, 'ukid' => 'card_5', 'row' => 5, 'col' => 2]),
    dkCreature(6, GameState::PLAYER_PLAYER, 5, 4)
);
$result = dkApply($state, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 9,
    'target_id' => 6,
    'action_key' => 'shot',
]));
dkAssert($result->success, $result->error ?? 'Two-King redirect setup should start pending.');
$targetIds = array_map(static fn(array $option): int => (int) $option['target_id'], $state->battle['pending_ranged_attack_redirect']['options'] ?? []);
sort($targetIds);
dkAssert($targetIds === [1, 5], 'Two Kings should be able to redirect to each other without self-source targets.');

echo "Dwarven King tests passed\n";
