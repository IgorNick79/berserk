<?php
// tools/test_whip_cancel.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
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

function whipCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'] ?? 1,
        ukid: $overrides['ukid'] ?? 's1_7',
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 8,
        hpMax: $overrides['hpMax'] ?? 8,
        move: $overrides['move'] ?? 2,
        moveMax: $overrides['moveMax'] ?? 2,
        prop: $overrides['prop'] ?? [
            'turn_start' => [[
                'type' => 'whip',
                'label' => 'Щелчок хлыста',
                'value' => 1,
            ]],
        ],
    );
}

function whipState(CardInstance ...$cards): GameState
{
    $state = new GameState(7, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 2,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
        'hidden_row_revealed' => true,
        'turn_phase' => [
            'phase' => 'start',
            'active_key' => GameState::PLAYER_HOST,
            'passive_key' => GameState::PLAYER_PLAYER,
            'side' => 'active',
            'passive_queue' => [],
            'active_queue' => [[
                'id' => 'turn_start_1_0',
                'type' => 'turn_start:whip',
                'label' => 'Щелчок хлыста',
                'card_id' => 1,
                'ukid' => 's1_7',
                'row' => 3,
                'col' => 3,
                'stacked' => false,
                'payload' => [
                    'type' => 'whip',
                    'label' => 'Щелчок хлыста',
                    'value' => 1,
                ],
            ]],
            'sub' => null,
            'pending_ack' => null,
        ],
    ];

    foreach ($cards as $card) {
        $state->addCard($card);
    }

    return $state;
}

function startWhip(GameState $state): void
{
    $result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('turn_task', [
        'task_id' => 'turn_start_1_0',
    ]));

    assertTrue($result->success, $result->error ?? 'Whip turn task should start.');
    assertTrue(!empty($state->battle['pending_whip']), 'Whip pending should be created.');
}

$keeper = whipCard();
$ally = whipCard([
    'instanceId' => 2,
    'ukid' => 'ally',
    'row' => 3,
    'col' => 4,
    'hp' => 6,
    'hpMax' => 6,
    'move' => 1,
    'moveMax' => 1,
    'prop' => [],
]);
$state = whipState($keeper, $ally);
startWhip($state);

$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('cancel_pending'));
assertTrue($result->success, $result->error ?? 'Whip cancel_pending should be accepted.');
assertTrue(empty($state->battle['pending_whip']), 'Whip pending should be cleared after cancel.');
assertTrue(empty($state->battle['turn_phase']), 'Turn phase should continue after Whip cancel.');
assertTrue($keeper->hp === 8 && $keeper->move === 2 && empty($keeper->modifiers), 'Whip cancel should not change source.');
assertTrue($ally->hp === 6 && $ally->move === 1 && empty($ally->modifiers), 'Whip cancel should not change candidate target.');

$keeper = whipCard();
$ally = whipCard([
    'instanceId' => 2,
    'ukid' => 'ally',
    'row' => 3,
    'col' => 4,
    'hp' => 6,
    'hpMax' => 6,
    'move' => 1,
    'moveMax' => 1,
    'prop' => [],
]);
$state = whipState($keeper, $ally);
startWhip($state);

$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_whip_target', [
    'target_id' => 2,
]));
assertTrue($result->success, $result->error ?? 'Whip target choice should still work.');
assertTrue(empty($state->battle['pending_whip']), 'Whip pending should be cleared after target choice.');
assertTrue(empty($state->battle['turn_phase']), 'Turn phase should continue after Whip target choice.');
assertTrue($ally->hp === 5, 'Whip target should receive one wound.');
assertTrue($ally->move === 2, 'Whip target should receive immediate move bonus.');
assertTrue(
    ($ally->modifiers[0]['stat'] ?? null) === 'move'
        && (int) ($ally->modifiers[0]['value'] ?? 0) === 1
        && ($ally->modifiers[0]['expire'] ?? null) === 'end_of_turn',
    'Whip target should receive end-of-turn move modifier.'
);

echo "Whip cancel regression tests passed.\n";
