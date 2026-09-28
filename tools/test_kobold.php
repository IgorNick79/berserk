<?php
// tools/test_kobold.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;
use Berserk\View\Screen\Battle\InfoPanel;
use Berserk\View\Template;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function koboldAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function koboldProp(): array
{
    return [
        'zov' => true,
        'actions' => [['type' => 'uchr', 'value' => 1]],
        'on_successful_hit' => [
            'type' => 'optional_heal',
            'value_from' => 'opposite_creature_strike_medium',
        ],
    ];
}

function koboldState(CardInstance ...$cards): GameState
{
    $state = new GameState(92, 101, 202);
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

function koboldCard(array $overrides = []): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'] ?? 1,
        ukid: $overrides['ukid'] ?? 's1_92',
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 2,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 8,
        hpMax: $overrides['hpMax'] ?? 11,
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
        move: $overrides['move'] ?? 1,
        moveMax: $overrides['moveMax'] ?? 1,
        strikeWeak: $overrides['strikeWeak'] ?? 1,
        strikeMedium: $overrides['strikeMedium'] ?? 2,
        strikeStrong: $overrides['strikeStrong'] ?? 3,
        prop: $overrides['prop'] ?? koboldProp(),
        modifiers: $overrides['modifiers'] ?? [],
    );
}

function koboldCreature(int $id, string $owner, int $row, int $col, array $overrides = []): CardInstance
{
    return koboldCard(array_replace([
        'instanceId' => $id,
        'ukid' => 'card_' . $id,
        'owner' => $owner,
        'row' => $row,
        'col' => $col,
        'hp' => 12,
        'hpMax' => 12,
        'strikeWeak' => 2,
        'strikeMedium' => 5,
        'strikeStrong' => 7,
        'prop' => [],
    ], $overrides));
}

function koboldPending(GameState $state): ?array
{
    return $state->battle['pending_kobold_heal'] ?? null;
}

function koboldApply(GameState $state, string $playerKey, Command $cmd): void
{
    $result = (new Engine())->apply($state, $playerKey, $cmd);
    koboldAssert($result->success, $result->error ?? 'Command failed.');
}

function koboldStrike(GameState $state, int $cardId = 1, int $targetId = 3, string $playerKey = GameState::PLAYER_HOST): void
{
    $resolver = new StrikeResolver($state, new Engine());
    $result = $resolver->declare($playerKey, new Command('strike', [
        'card_id' => $cardId,
        'target_id' => $targetId,
    ]));
    koboldAssert($result->success, $result->error ?? 'Kobold strike failed.');

    if (($state->battle['strike']['state'] ?? null) === 'waiting_defender') {
        $defenderKey = $state->getOpponentKey($playerKey);
        $result = $resolver->chooseDefender($defenderKey, new Command('choose_defender', ['defender_id' => 0]));
        koboldAssert($result->success, $result->error ?? 'Defender skip failed.');
    }
}

function koboldCardsInfo(): array
{
    return [
        's1_92' => ['name' => 'Кобольд'],
        'card_2' => ['name' => 'Существо напротив'],
        'card_3' => ['name' => 'Цель'],
    ];
}

$kobold = koboldCard();
koboldAssert(!empty($kobold->prop['zov']), 'Kobold should have zov.');
koboldAssert(CardStats::hasDefense(koboldState($kobold), $kobold, 'shot', koboldCreature(99, GameState::PLAYER_PLAYER, 4, 4)), 'Kobold zov should defend against shot.');
koboldAssert(($kobold->prop['actions'][0]['type'] ?? null) === 'uchr', 'Kobold should have uchr action.');
koboldAssert(($kobold->prop['actions'][0]['value'] ?? null) === 1, 'Kobold uchr should wound on 1.');

$state = koboldState(
    koboldCard(['hp' => 5]),
    koboldCreature(2, GameState::PLAYER_HOST, 3, 3, ['strikeWeak' => 3, 'strikeMedium' => 8, 'strikeStrong' => 9]),
    koboldCreature(3, GameState::PLAYER_PLAYER, 2, 4)
);
koboldStrike($state);
koboldAssert(($state->battle['strike']['damage_total'] ?? null) === 3, 'Kobold normal strike should resolve.');
$pending = koboldPending($state);
koboldAssert(is_array($pending), 'Successful normal strike should open optional heal pending.');
koboldAssert(($pending['value'] ?? null) === 8, 'Heal value should equal opposite medium strike, not weak or strong.');
$html = (new InfoPanel(new Template(__DIR__ . '/../templates/')))->render(
    $state,
    GameState::PLAYER_HOST,
    'host',
    koboldCardsInfo(),
    '/battle?game=92&first='
);
koboldAssert(str_contains($html, 'Кобольд может излечиться на 8'), 'InfoPanel should explain Kobold heal value.');
koboldApply($state, GameState::PLAYER_HOST, new Command('choose_kobold_heal'));
koboldAssert($state->getCard(1)->hp === 11, 'Accepting heal should heal by X.');
koboldAssert(empty(koboldPending($state)), 'Accepted heal should clear pending.');

$state = koboldState(
    koboldCard(['hp' => 10]),
    koboldCreature(2, GameState::PLAYER_HOST, 3, 3, ['strikeWeak' => 5, 'strikeMedium' => 5]),
    koboldCreature(3, GameState::PLAYER_PLAYER, 2, 4)
);
koboldStrike($state);
koboldApply($state, GameState::PLAYER_HOST, new Command('choose_kobold_heal'));
koboldAssert($state->getCard(1)->hp === 11, 'Kobold heal should be capped by max HP.');

$state = koboldState(
    koboldCard(['hp' => 5]),
    koboldCreature(2, GameState::PLAYER_PLAYER, 3, 3, ['strikeWeak' => 1, 'strikeMedium' => 4, 'strikeStrong' => 9]),
    koboldCreature(3, GameState::PLAYER_PLAYER, 2, 4)
);
koboldStrike($state);
koboldAssert((koboldPending($state)['value'] ?? null) === 4, 'Enemy opposite creature should set heal value.');
koboldApply($state, GameState::PLAYER_HOST, new Command('cancel_pending'));
koboldAssert(empty(koboldPending($state)), 'cancel_pending should clear Kobold heal pending.');
koboldAssert($state->getCard(1)->hp === 5, 'Declining heal should not change HP.');
koboldAssert(!empty($state->battle['strike']) && ($state->battle['strike']['state'] ?? null) === 'results', 'Decline should leave turn state usable.');

$state = koboldState(
    koboldCard(['hp' => 5]),
    koboldCreature(2, GameState::PLAYER_HOST, 3, 4, ['strikeWeak' => 1, 'strikeMedium' => 6]),
    koboldCreature(3, GameState::PLAYER_PLAYER, 2, 4)
);
koboldStrike($state);
koboldAssert(empty(koboldPending($state)), 'Adjacent column should not count as opposite.');

$state = koboldState(
    koboldCard(['hp' => 5]),
    koboldCreature(3, GameState::PLAYER_PLAYER, 2, 4)
);
koboldStrike($state);
koboldAssert(empty(koboldPending($state)), 'Empty opposite cell should not open heal pending.');

$state = koboldState(
    koboldCard(['hp' => 11]),
    koboldCreature(2, GameState::PLAYER_HOST, 3, 3, ['strikeMedium' => 3]),
    koboldCreature(3, GameState::PLAYER_PLAYER, 2, 4)
);
koboldStrike($state);
koboldAssert(empty(koboldPending($state)), 'Full HP Kobold should not open meaningless heal pending.');

$state = koboldState(
    koboldCard(['hp' => 5]),
    koboldCreature(2, GameState::PLAYER_HOST, 3, 3, ['strikeMedium' => 3]),
    koboldCreature(3, GameState::PLAYER_PLAYER, 2, 4, ['prop' => ['zoa' => true]])
);
$result = (new StrikeResolver($state, new Engine()))->declare(GameState::PLAYER_HOST, new Command('strike', [
    'card_id' => 1,
    'target_id' => 3,
]));
koboldAssert(!$result->success, 'Pre-result blocked strike should be rejected.');
koboldAssert(empty(koboldPending($state)), 'Pre-result blocked strike should not open heal pending.');

$state = koboldState(
    koboldCard(['hp' => 5, 'prop' => koboldProp() + ['ability' => ['value' => 9]]]),
    koboldCreature(2, GameState::PLAYER_HOST, 3, 3, ['strikeWeak' => 1, 'strikeMedium' => 3, 'strikeStrong' => 8]),
    koboldCreature(3, GameState::PLAYER_PLAYER, 2, 4, ['hp' => 1, 'hpMax' => 12])
);
koboldStrike($state);
koboldAssert((koboldPending($state)['value'] ?? null) === 3, 'Heal value should not depend on wounds dealt to target.');

$state = koboldState(
    koboldCard(['hp' => 5]),
    koboldCreature(2, GameState::PLAYER_HOST, 3, 3, ['strikeWeak' => 1, 'strikeMedium' => 2, 'modifiers' => [['stat' => 'ability_strike', 'value' => 2]]]),
    koboldCreature(3, GameState::PLAYER_PLAYER, 2, 4)
);
koboldStrike($state);
koboldAssert((koboldPending($state)['value'] ?? null) === 4, 'Modified effective medium strike should be used for heal value.');

$state = koboldState(
    koboldCard(['hp' => 5]),
    koboldCreature(2, GameState::PLAYER_HOST, 3, 3, ['strikeWeak' => 1, 'strikeMedium' => 3]),
    koboldCreature(3, GameState::PLAYER_PLAYER, 4, 3)
);
koboldApply($state, GameState::PLAYER_HOST, new Command('uchr', ['card_id' => 1, 'target_id' => 3]));
koboldAssert((koboldPending($state)['value'] ?? null) === 3, 'Successful uchr should open same optional heal pending.');
koboldAssert($state->getCard(1)->closed, 'Kobold uchr should close the Kobold.');
koboldApply($state, GameState::PLAYER_HOST, new Command('cancel_pending'));

$state = koboldState(
    koboldCard(['hp' => 5, 'row' => 4, 'col' => 3, 'owner' => GameState::PLAYER_PLAYER]),
    koboldCreature(2, GameState::PLAYER_HOST, 3, 3, ['strikeWeak' => 1, 'strikeMedium' => 4]),
    koboldCreature(3, GameState::PLAYER_HOST, 4, 4)
);
$state->battle['active'] = GameState::PLAYER_PLAYER;
koboldStrike($state, 1, 3, GameState::PLAYER_PLAYER);
koboldAssert((koboldPending($state)['value'] ?? null) === 4, 'Player-side Kobold should use row - 1 as opposite.');

echo "Kobold regression tests passed.\n";
