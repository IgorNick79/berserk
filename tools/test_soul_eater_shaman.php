<?php
// tools/test_soul_eater_shaman.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\InstantProcessor;
use Berserk\Core\StrikeResolver;
use Berserk\Core\TurnProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function shAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function shamanProp(): array
{
    return [
        'save_coins' => true,
        'start' => [
            'side' => 2,
            'type' => 'get_coins',
            'coins' => 1,
        ],
        'actions' => [[
            'key' => 'gain_zoal',
            'name' => 'Получить ЗОАЛ',
            'type' => 'grant_prop',
            'prop' => 'zoal',
            'self' => true,
        ]],
        'instants' => [[
            'key' => 'hypnosis',
            'name' => 'Гипноз',
            'trigger' => 'turn',
            'coins' => 1,
            'target' => 'enemy_open_creature',
            'effect' => [
                'type' => 'forced_strike_adjacent',
                'close_attacker_after' => true,
            ],
        ]],
    ];
}

function shCard(array $overrides): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'],
        ukid: $overrides['ukid'] ?? ('card_' . $overrides['instanceId']),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 8,
        hpMax: $overrides['hpMax'] ?? ($overrides['hp'] ?? 8),
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
        move: 1,
        moveMax: 1,
        strikeWeak: $overrides['strikeWeak'] ?? 1,
        strikeMedium: $overrides['strikeMedium'] ?? 2,
        strikeStrong: $overrides['strikeStrong'] ?? 3,
        coins: $overrides['coins'] ?? 0,
        prop: $overrides['prop'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function shState(CardInstance ...$cards): GameState
{
    $state = new GameState(118, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => null,
    ];
    foreach ($cards as $card) {
        $state->addCard($card);
    }
    return $state;
}

function shHasHypnosis(GameState $state, string $owner = GameState::PLAYER_HOST): bool
{
    $instants = (new InstantProcessor($state, new Engine()))->getInstants($owner, 'turn', 'turn');
    foreach ($instants as $instant) {
        if (($instant['payload']['key'] ?? '') === 'hypnosis') {
            return true;
        }
    }
    return false;
}

function shOpenHypnosis(GameState $state, string $owner = GameState::PLAYER_HOST): InstantProcessor
{
    $ip = new InstantProcessor($state, new Engine());
    shAssert($ip->openTurnStackWindow($owner, ['type' => 'manual'], 'turn'), 'Turn stack should open.');
    return $ip;
}

function shResolveTurnStack(GameState $state, string $owner = GameState::PLAYER_HOST): void
{
    $ip = new InstantProcessor($state, new Engine());
    $result = $ip->passTurnInstant($owner);
    shAssert($result->success, $result->error ?? 'Owner pass should resolve/pass stack.');
    if (!empty($state->battle['turn_instant_result'])) {
        $ack = $ip->ackTurnInstantResult($owner);
        shAssert($ack->success, $ack->error ?? 'Turn instant result ack should succeed.');
    }
}

function shFinishStrike(GameState $state): void
{
    $engine = new Engine();
    $resolver = new StrikeResolver($state, $engine);

    for ($i = 0; $i < 8 && !empty($state->battle['strike']); $i++) {
        $strike = $state->battle['strike'];
        $attacker = $state->getCard((int) ($strike['attacker_id'] ?? 0));
        $attackerKey = $attacker?->owner ?? GameState::PLAYER_HOST;
        $defenderKey = $state->getOpponentKey($attackerKey);
        $stateName = (string) ($strike['state'] ?? '');

        if ($stateName === 'waiting_defender') {
            $result = $resolver->chooseDefender($defenderKey, new Command('choose_defender', ['defender_id' => 0]));
            shAssert($result->success, $result->error ?? 'Defender skip should resolve forced strike.');
            continue;
        }
        if ($stateName === 'waiting_choice') {
            $winnerKey = ($strike['choice_winner'] ?? '') === 'attack' ? $attackerKey : $defenderKey;
            $result = $resolver->chooseStrikeMode($winnerKey, new Command('choose_strike_mode', ['mode' => 'normal']));
            shAssert($result->success, $result->error ?? 'Strike choice should resolve.');
            continue;
        }
        if ($stateName === 'results') {
            $resolver->confirmStrike(GameState::PLAYER_HOST, new Command('confirm_strike'));
            $resolver->confirmStrike(GameState::PLAYER_PLAYER, new Command('confirm_strike'));
            continue;
        }
    }

    shAssert(empty($state->battle['strike']), 'Forced strike should finish.');
}

// Start / coins.
$first = shState(shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp()]));
$first->players[GameState::PLAYER_HOST]->side = 1;
(new TurnProcessor($first, new Engine()))->applyStartEffects();
shAssert($first->getCard(1)->coins === 0, 'Shaman should not gain a start coin when owner is first.');

$second = shState(shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp()]));
$second->players[GameState::PLAYER_HOST]->side = 2;
(new TurnProcessor($second, new Engine()))->applyStartEffects();
shAssert($second->getCard(1)->coins === 1, 'Shaman should gain one start coin when owner is second.');
shAssert(!empty($second->getCard(1)->prop['save_coins']), 'Shaman should save coins.');

// ZOAL action uses generic grant_prop.
$zoal = shState(shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp()]));
$result = (new Engine())->apply($zoal, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'gain_zoal',
]));
shAssert($result->success, $result->error ?? 'Gain ZOAL action should resolve.');
shAssert(!empty($zoal->getCard(1)->prop['zoal']), 'Gain ZOAL should grant zoal prop.');
shAssert($zoal->getCard(1)->closed, 'Gain ZOAL should close Shaman.');

// Hypnosis availability and first target filtering.
$noCoin = shState(
    shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp(), 'coins' => 0]),
    shCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]),
    shCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5])
);
shAssert(empty((new InstantProcessor($noCoin, new Engine()))->getInstants(GameState::PLAYER_HOST, 'turn', 'turn')), 'Hypnosis should be unavailable without a coin.');

$targeting = shState(
    shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp(), 'coins' => 1]),
    shCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]),
    shCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5]),
    shCard(['instanceId' => 4, 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 5, 'closed' => true]),
    shCard(['instanceId' => 5, 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 2])
);
$instants = (new InstantProcessor($targeting, new Engine()))->getInstants(GameState::PLAYER_HOST, 'turn', 'turn');
shAssert(count($instants) === 1 && ($instants[0]['payload']['key'] ?? '') === 'hypnosis', 'Hypnosis should be available with a coin and an open valid enemy.');
shAssert(shHasHypnosis($targeting), 'Open Shaman with a coin should offer Hypnosis.');

$closedAfterZoal = shState(
    shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp(), 'coins' => 1]),
    shCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]),
    shCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5])
);
$result = (new Engine())->apply($closedAfterZoal, GameState::PLAYER_HOST, new Command('action', [
    'card_id' => 1,
    'action_key' => 'gain_zoal',
]));
shAssert($result->success, $result->error ?? 'Gain ZOAL should resolve before availability check.');
shAssert(!shHasHypnosis($closedAfterZoal), 'Closed Shaman after Gain ZOAL should not offer Hypnosis.');

$closedOnOpponentTurn = shState(
    shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp(), 'coins' => 1, 'closed' => true]),
    shCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]),
    shCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5])
);
$closedOnOpponentTurn->battle['active'] = GameState::PLAYER_PLAYER;
shAssert(!shHasHypnosis($closedOnOpponentTurn), 'Closed Shaman should not offer Hypnosis on opponent turn.');

$closedManyCoins = shState(
    shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp(), 'coins' => 3, 'closed' => true]),
    shCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]),
    shCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5])
);
shAssert(!shHasHypnosis($closedManyCoins), 'Closed Shaman with multiple coins should still not offer Hypnosis.');
(new Engine())->openCard($closedManyCoins->getCard(1));
shAssert(shHasHypnosis($closedManyCoins), 'Reopened Shaman with enough coins should offer Hypnosis again.');

$directClosedAttempt = shState(
    shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp(), 'coins' => 1, 'closed' => true]),
    shCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]),
    shCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5])
);
$directClosedAttempt->battle['turn_instant_stack'] = [
    'state' => 'ordering',
    'phase' => 'turn',
    'participants' => [GameState::PLAYER_HOST],
    'priority' => GameState::PLAYER_HOST,
    'passed' => [],
    'stack' => [],
    'next_sequence' => 0,
    'continuation' => ['type' => 'manual'],
    'summary' => [],
];
$result = (new InstantProcessor($directClosedAttempt, new Engine()))->playTurnInstant(
    GameState::PLAYER_HOST,
    new Command('play_turn_instant', ['card_id' => 1, 'instant_key' => 'hypnosis'])
);
shAssert(!$result->success, 'Server should reject direct Hypnosis declaration by closed Shaman.');

$noTapInstant = shState(shCard([
    'instanceId' => 10,
    'owner' => GameState::PLAYER_HOST,
    'closed' => true,
    'prop' => ['instants' => [[
        'key' => 'no_tap',
        'name' => 'Без закрытия',
        'trigger' => 'turn',
        'tap_source' => false,
        'effect' => ['type' => 'damage', 'value' => 1],
    ]]],
]));
$instants = (new InstantProcessor($noTapInstant, new Engine()))->getInstants(GameState::PLAYER_HOST, 'turn', 'turn');
shAssert(count($instants) === 1 && ($instants[0]['payload']['key'] ?? '') === 'no_tap', 'Closed source should still offer turn instants that do not require tapping.');

$ip = shOpenHypnosis($targeting);
shAssert($ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 1, 'instant_key' => 'hypnosis']))->success, 'Hypnosis declaration should open first pick.');
shAssert(!empty($targeting->battle['pending_instant_pick']), 'First target pending should be created.');
shAssert(!$ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 4]))->success, 'Closed enemy should not be a hypnosis target.');
shAssert(!$ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 5]))->success, 'Own card should not be a hypnosis target.');
shAssert($ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 2]))->success, 'Open enemy with adjacent card should be a hypnosis target.');
shAssert(!empty($targeting->battle['pending_forced_strike_adjacent']), 'Second adjacent strike pending should be created.');
shAssert(!$ip->chooseForcedStrikeAdjacent(GameState::PLAYER_HOST, new Command('choose_forced_strike_adjacent', ['target_id' => 5]))->success, 'Non-adjacent card should be rejected.');

// Cancel before commit should not spend the coin or close Shaman.
$cancel = shState(
    shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp(), 'coins' => 1]),
    shCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]),
    shCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5])
);
$ip = shOpenHypnosis($cancel);
$ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 1, 'instant_key' => 'hypnosis']));
$ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 2]));
$result = (new Engine())->apply($cancel, GameState::PLAYER_HOST, new Command('cancel_pending'));
shAssert($result->success, $result->error ?? 'Second hypnosis pending should be cancellable.');
shAssert(empty($cancel->battle['pending_forced_strike_adjacent']), 'Cancel should clear second hypnosis pending.');
shAssert($cancel->getCard(1)->coins === 1 && !$cancel->getCard(1)->closed, 'Cancel before commit should not spend or close Shaman.');

// Friendly forced strike: enemy creature hits its own adjacent card through the regular strike state.
$friendly = shState(
    shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp(), 'coins' => 1]),
    shCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]),
    shCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5])
);
$ip = shOpenHypnosis($friendly);
$ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 1, 'instant_key' => 'hypnosis']));
$ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 2]));
shAssert($ip->chooseForcedStrikeAdjacent(GameState::PLAYER_HOST, new Command('choose_forced_strike_adjacent', ['target_id' => 3]))->success, 'Adjacent friendly target should stack hypnosis.');
shAssert($friendly->getCard(1)->coins === 0, 'Hypnosis commit should spend exactly one coin.');
shResolveTurnStack($friendly);
shAssert(!empty($friendly->battle['strike']), 'Hypnosis should create a forced strike.');
shAssert(!empty($friendly->battle['strike']['friendly_fire']), 'Hypnosis should use friendly-fire strike when attacker hits its own card.');
shAssert($friendly->getCard(1)->closed, 'Shaman should close as instant cost on resolution.');
shFinishStrike($friendly);
shAssert($friendly->getCard(2)->closed, 'Hypnotized creature should close after the forced strike.');

// Enemy forced strike can hit the Shaman owner's adjacent card.
$enemy = shState(
    shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp(), 'coins' => 1, 'row' => 2, 'col' => 4]),
    shCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]),
    shCard(['instanceId' => 3, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 5])
);
$ip = shOpenHypnosis($enemy);
$ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 1, 'instant_key' => 'hypnosis']));
$ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 2]));
shAssert($ip->chooseForcedStrikeAdjacent(GameState::PLAYER_HOST, new Command('choose_forced_strike_adjacent', ['target_id' => 3]))->success, 'Adjacent enemy-side target should stack hypnosis.');
shResolveTurnStack($enemy);
shAssert(empty($enemy->battle['strike']['friendly_fire']), 'Hypnosis should use ordinary strike when sides differ.');
shFinishStrike($enemy);
shAssert($enemy->getCard(2)->closed, 'Enemy hypnotized creature should close after the forced strike.');

// Minotaur-style two-attack creature still makes exactly one forced strike and closes.
$minotaur = shState(
    shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp(), 'coins' => 1]),
    shCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'prop' => ['attacks_per_turn' => 2]]),
    shCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5])
);
$ip = shOpenHypnosis($minotaur);
$ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 1, 'instant_key' => 'hypnosis']));
$ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 2]));
$ip->chooseForcedStrikeAdjacent(GameState::PLAYER_HOST, new Command('choose_forced_strike_adjacent', ['target_id' => 3]));
shResolveTurnStack($minotaur);
shFinishStrike($minotaur);
shAssert((int) ($minotaur->getCard(2)->flags['attacks_used_this_turn'] ?? 0) === 1, 'Hypnosis should create exactly one forced strike.');
shAssert($minotaur->getCard(2)->closed, 'Two-attack creature should still close after Hypnosis.');

// Stack invalidation: if the chosen attacker closes before resolution, no invalid strike is created.
$invalid = shState(
    shCard(['instanceId' => 1, 'ukid' => 's1_118', 'prop' => shamanProp(), 'coins' => 1]),
    shCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]),
    shCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5])
);
$ip = shOpenHypnosis($invalid);
$ip->playTurnInstant(GameState::PLAYER_HOST, new Command('play_turn_instant', ['card_id' => 1, 'instant_key' => 'hypnosis']));
$ip->chooseTurnTarget(GameState::PLAYER_HOST, new Command('choose_instant_pick', ['target_id' => 2]));
$ip->chooseForcedStrikeAdjacent(GameState::PLAYER_HOST, new Command('choose_forced_strike_adjacent', ['target_id' => 3]));
$invalid->getCard(2)->closed = true;
shResolveTurnStack($invalid);
shAssert(empty($invalid->battle['strike']), 'Closed hypnotized creature should not create an invalid forced strike.');

echo "OK soul_eater_shaman\n";
