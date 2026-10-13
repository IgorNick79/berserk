<?php
// tools/test_degasca.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\ActionResolver;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\DegascaProcessor;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\StrikeResolver;
use Berserk\Core\TurnProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function dgAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dgCard(array $overrides): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'],
        ukid: $overrides['ukid'] ?? ('card_' . $overrides['instanceId']),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        order: $overrides['order'] ?? null,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 10,
        hpMax: $overrides['hpMax'] ?? ($overrides['hp'] ?? 10),
        type: $overrides['type'] ?? 'creature',
        closed: $overrides['closed'] ?? false,
        move: 1,
        moveMax: 1,
        strikeWeak: $overrides['strikeWeak'] ?? 1,
        strikeMedium: $overrides['strikeMedium'] ?? 2,
        strikeStrong: $overrides['strikeStrong'] ?? 3,
        armor: $overrides['armor'] ?? 0,
        armorMax: $overrides['armorMax'] ?? ($overrides['armor'] ?? 0),
        prop: $overrides['prop'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function dgState(CardInstance ...$cards): GameState
{
    $state = new GameState(9401, 101, 202);
    $state->status = 'battle';
    $state->battle = ['turn' => 1, 'active' => GameState::PLAYER_HOST];
    $state->getPlayer(GameState::PLAYER_HOST)->side = 1;
    $state->getPlayer(GameState::PLAYER_PLAYER)->side = 2;
    foreach ($cards as $card) {
        $state->addCard($card);
    }
    return $state;
}

function dgInstant(string $key): array
{
    return [
        'key' => $key,
        'name' => 'Before',
        'trigger' => 'turn',
        'target' => 'self',
        'effect' => ['type' => 'damage', 'value' => 1],
    ];
}

function dgChooseLearn(GameState $state, int $cardId, string $playerKey = GameState::PLAYER_HOST): void
{
    $result = (new Engine())->apply($state, $playerKey, new Command('choose_degasca_card', ['card_id' => $cardId]));
    dgAssert($result->success, $result->error ?? 'Degasca learn choice should succeed.');
}

function dgAristocratProp(): array
{
    return [
        'start_pre_battle' => [
            ['side' => 1, 'count' => 2, 'effect' => ['type' => 'poison', 'value' => 1]],
            ['side' => 2, 'count' => 2, 'effect' => ['type' => 'close']],
        ],
    ];
}

// Pre-battle: Degasca opens before the first turn and leaves the learned card in the deck.
$degasca = dgCard([
    'instanceId' => 1,
    'ukid' => 's1_32',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
]);
$anubisarDeck = dgCard(['instanceId' => 3, 'ukid' => 's1_26', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1]);
$state = dgState($degasca, $anubisarDeck);
(new TurnProcessor($state, new Engine()))->startBattle();
dgAssert(!empty($state->battle['pending_degasca_sequence']), 'Degasca pre-battle pending should open.');
dgAssert(empty($state->battle['strike']), 'Degasca pre-battle pending must not be tied to a strike.');
dgChooseLearn($state, 3);
dgAssert(($degasca->flags['degasca_learned'] ?? []) === ['s1_26'], 'Degasca should learn Anubisar once.');
dgAssert($anubisarDeck->zone === CardInstance::ZONE_DECK, 'Learning must not remove card from deck.');
dgAssert($anubisarDeck->order !== null, 'Deck should be shuffled/reordered after learning.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca pre-battle pending should clear after choice.');
dgAssert(!empty($state->battle['pre_battle_completed']), 'Pre-battle lifecycle should be marked complete after Degasca choice.');
$state->battle['turn_phase']['sentinel'] = 'keep';
(new TurnProcessor($state, new Engine()))->continuePreBattleEffects();
dgAssert(($state->battle['turn_phase']['sentinel'] ?? null) === 'keep', 'Repeated pre-battle continuation must not restart the start turn phase.');

// Pre-battle with no available new ability finishes synchronously and does not open pending.
$degasca = dgCard([
    'instanceId' => 60,
    'ukid' => 's1_32',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'flags' => ['degasca_learned' => ['s1_131']],
]);
$state = dgState($degasca);
(new TurnProcessor($state, new Engine()))->startBattle();
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca should not leave pending when no new ability exists.');
dgAssert(empty($state->battle['pending_intrigue_start']), 'Pre-battle queue should finish when Degasca has no deck candidates.');

// Unavailable Degasca during a resumed pending clears the pending and does not leave combat stuck.
$degasca = dgCard([
    'instanceId' => 62,
    'ukid' => 's1_32',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'hp' => 0,
]);
$state = dgState($degasca);
$state->battle['pending_degasca_sequence'] = [
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 62,
    'activation' => 'pre_battle',
    'effects' => [],
    'index' => 0,
    'stage' => 'deck_pick',
    'context' => [],
];
(new DegascaProcessor($state, new Engine()))->resume(true);
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Unavailable Degasca should clear pending on resume.');
dgAssert(empty($state->battle['pending_intrigue_start']), 'Unavailable Degasca should continue pre-battle lifecycle.');

// Degasca does not open from strike_before / strike declaration.
$degasca = dgCard(['instanceId' => 10, 'ukid' => 's1_32', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$attacker = dgCard([
    'instanceId' => 12,
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 2,
]);
$target = dgCard(['instanceId' => 11, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 2]);
$medusaDeck = dgCard(['instanceId' => 13, 'ukid' => 's1_131', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1]);
$state = dgState($degasca, $attacker, $target, $medusaDeck);
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 12, 'target_id' => 11]));
dgAssert($result->success, $result->error ?? 'Non-Degasca strike should declare.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca pre-battle chooser must not open on strike declaration.');
dgAssert(($state->battle['strike']['attack_dice'] ?? null) !== null, 'Strike should continue normally without Degasca pre-battle hook.');

// Pending survives serialization.
$state = dgState($degasca, $medusaDeck);
(new TurnProcessor($state, new Engine()))->startBattle();
$restored = GameState::fromArray($state->toArray());
dgAssert(($restored->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Degasca pending should survive serialization.');

// Degasca and Aristocrat share the same pre-battle lifecycle.
$degasca = dgCard(['instanceId' => 70, 'ukid' => 's1_32', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$aristocrat = dgCard(['instanceId' => 71, 'ukid' => 's1_194', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 2, 'prop' => dgAristocratProp()]);
$enemyA = dgCard(['instanceId' => 72, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 2]);
$enemyB = dgCard(['instanceId' => 73, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3]);
$anubisarDeck = dgCard(['instanceId' => 74, 'ukid' => 's1_26', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1]);
$state = dgState($degasca, $aristocrat, $enemyA, $enemyB, $anubisarDeck);
(new TurnProcessor($state, new Engine()))->startBattle();
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Degasca should be first pre-battle pending in this setup.');
dgChooseLearn($state, 74);
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca pending should finish before Aristocrat.');
dgAssert(($state->battle['pending_intrigue_start'][0]['type'] ?? '') === 'intrigue', 'Aristocrat should remain queued after Degasca.');
$result = (new ActionResolver($state, new Engine()))->chooseIntrigueStart(GameState::PLAYER_HOST, new Command('choose_intrigue_start', ['target_ids' => [72, 73]]));
dgAssert($result->success, $result->error ?? 'Aristocrat should resolve after Degasca.');
dgAssert(($enemyA->markers['poison']['value'] ?? null) === 1 && ($enemyB->markers['poison']['value'] ?? null) === 1, 'Aristocrat effect should still apply after Degasca.');

// Aristocrat can resolve before Degasca when it appears earlier in the same-side pre-battle queue.
$aristocrat = dgCard(['instanceId' => 75, 'ukid' => 's1_194', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 2, 'prop' => dgAristocratProp()]);
$degasca = dgCard(['instanceId' => 76, 'ukid' => 's1_32', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$enemyA = dgCard(['instanceId' => 77, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 2]);
$enemyB = dgCard(['instanceId' => 78, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3]);
$medusaDeck = dgCard(['instanceId' => 79, 'ukid' => 's1_131', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1]);
$state = dgState($aristocrat, $degasca, $enemyA, $enemyB, $medusaDeck);
(new TurnProcessor($state, new Engine()))->startBattle();
dgAssert(($state->battle['pending_intrigue_start'][0]['type'] ?? '') === 'intrigue', 'Aristocrat should be first when queued before Degasca.');
$result = (new ActionResolver($state, new Engine()))->chooseIntrigueStart(GameState::PLAYER_HOST, new Command('choose_intrigue_start', ['target_ids' => [77, 78]]));
dgAssert($result->success, $result->error ?? 'Aristocrat should resolve before Degasca.');
dgAssert(($state->battle['pending_degasca_sequence']['source_id'] ?? 0) === 76, 'Degasca should open after Aristocrat resumes pre-battle.');
dgChooseLearn($state, 79);
dgAssert(($degasca->flags['degasca_learned'] ?? []) === ['s1_131'], 'Degasca should learn after Aristocrat-first sequence.');

// If Degasca is the final queue item and has no candidates, pre-battle still completes once.
$aristocrat = dgCard(['instanceId' => 84, 'ukid' => 's1_194', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 2, 'prop' => dgAristocratProp()]);
$degasca = dgCard([
    'instanceId' => 85,
    'ukid' => 's1_32',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'flags' => ['degasca_learned' => ['s1_26', 's1_131', 's1_149', 's1_199']],
]);
$enemyA = dgCard(['instanceId' => 86, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 2]);
$enemyB = dgCard(['instanceId' => 87, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3]);
$state = dgState($aristocrat, $degasca, $enemyA, $enemyB);
(new TurnProcessor($state, new Engine()))->startBattle();
$result = (new ActionResolver($state, new Engine()))->chooseIntrigueStart(GameState::PLAYER_HOST, new Command('choose_intrigue_start', ['target_ids' => [86, 87]]));
dgAssert($result->success, $result->error ?? 'Aristocrat should resolve before no-candidate Degasca.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'No-candidate Degasca as final pre-battle item should not leave pending.');
dgAssert(empty($state->battle['pending_intrigue_start']), 'Pre-battle queue should clear after final no-candidate Degasca.');
dgAssert(!empty($state->battle['pre_battle_completed']), 'Pre-battle should complete after final no-candidate Degasca.');

// Both players can resolve their own Degasca pre-battle trigger in side order.
$hostDegasca = dgCard(['instanceId' => 80, 'ukid' => 's1_32', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$playerDegasca = dgCard(['instanceId' => 81, 'ukid' => 's1_32', 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3]);
$hostDeck = dgCard(['instanceId' => 82, 'ukid' => 's1_26', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1]);
$playerDeck = dgCard(['instanceId' => 83, 'ukid' => 's1_131', 'owner' => GameState::PLAYER_PLAYER, 'zone' => CardInstance::ZONE_DECK, 'order' => 1]);
$state = dgState($hostDegasca, $playerDegasca, $hostDeck, $playerDeck);
(new TurnProcessor($state, new Engine()))->startBattle();
dgAssert(($state->battle['pending_degasca_sequence']['owner'] ?? '') === GameState::PLAYER_HOST, 'First-player Degasca should resolve first.');
dgChooseLearn($state, 82, GameState::PLAYER_HOST);
dgAssert(($state->battle['pending_degasca_sequence']['owner'] ?? '') === GameState::PLAYER_PLAYER, 'Second-player Degasca should resolve after host.');
$state = GameState::fromArray($state->toArray());
dgChooseLearn($state, 83, GameState::PLAYER_PLAYER);
dgAssert(($state->getCard(80)->flags['degasca_learned'] ?? []) === ['s1_26'], 'Host Degasca should learn own deck card.');
dgAssert(($state->getCard(81)->flags['degasca_learned'] ?? []) === ['s1_131'], 'Player Degasca should learn own deck card after restore.');

// Duplicate deck copies are offered once per ukid and fully learned Degasca opens no pending.
$degasca = dgCard(['instanceId' => 90, 'ukid' => 's1_32', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$copyA = dgCard(['instanceId' => 91, 'ukid' => 's1_26', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1]);
$copyB = dgCard(['instanceId' => 92, 'ukid' => 's1_26', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 2]);
$medusa = dgCard(['instanceId' => 93, 'ukid' => 's1_131', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 3]);
$state = dgState($degasca, $copyA, $copyB, $medusa);
(new TurnProcessor($state, new Engine()))->startBattle();
$candidateIds = $state->battle['pending_degasca_sequence']['candidate_ids'] ?? [];
dgAssert(count($candidateIds) === 2, 'Degasca should offer one candidate per source ukid.');
dgAssert(count(array_intersect($candidateIds, [91, 92])) === 1, 'Duplicate Anubisar copies should be collapsed.');

$degasca = dgCard([
    'instanceId' => 94,
    'ukid' => 's1_32',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'flags' => ['degasca_learned' => ['s1_26', 's1_131', 's1_149', 's1_199']],
]);
$state = dgState(
    $degasca,
    dgCard(['instanceId' => 95, 'ukid' => 's1_26', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK]),
    dgCard(['instanceId' => 96, 'ukid' => 's1_131', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK]),
    dgCard(['instanceId' => 97, 'ukid' => 's1_149', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK]),
    dgCard(['instanceId' => 98, 'ukid' => 's1_199', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK])
);
(new TurnProcessor($state, new Engine()))->startBattle();
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Fully learned Degasca should not open pre-battle chooser.');
(new TurnProcessor($state, new Engine()))->continuePreBattleEffects();
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Repeated pre-battle continuation should not reopen Degasca.');

// Strong strike: learned Medusa runs, new Anubisar is learned but not run in the same sequence.
$degasca = dgCard([
    'instanceId' => 20,
    'ukid' => 's1_32',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'flags' => ['degasca_learned' => ['s1_131']],
]);
$target = dgCard(['instanceId' => 21, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$adjacent = dgCard(['instanceId' => 22, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4, 'hp' => 10]);
$anubisarDeck = dgCard(['instanceId' => 23, 'ukid' => 's1_26', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1]);
$state = dgState($degasca, $target, $adjacent, $anubisarDeck);
$state->battle['strike'] = [
    'attacker_id' => 20,
    'target_id' => 21,
    'original_target_id' => 21,
    'defender_id' => null,
    'state' => 'waiting_choice',
    'confirmed' => [],
    'defenders' => [],
    'redirect_candidates' => [],
    'friendly_fire' => false,
];
(new StrikeResolver($state, new Engine()))->apply(['attack' => 'strong', 'defend' => '', 'winner' => 'attack'], false);
dgAssert(isset($target->markers['stun']), 'Learned Medusa effect should stun target.');
dgAssert(empty($state->battle['chain_damage'] ?? []), 'Newly learned Anubisar must not run in same sequence.');
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Strong strike should proceed to Degasca deck pick.');
dgChooseLearn($state, 23);
dgAssert(($degasca->flags['degasca_learned'] ?? []) === ['s1_131', 's1_26'], 'Degasca should preserve learned order.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca pending should clear after strong pick.');

// Strong strike sequence blocks confirm until the choice is resolved and does not reapply damage afterward.
$degasca = dgCard([
    'instanceId' => 24,
    'ukid' => 's1_32',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'flags' => ['degasca_learned' => ['s1_131']],
]);
$target = dgCard(['instanceId' => 25, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$anubisarDeck = dgCard(['instanceId' => 26, 'ukid' => 's1_26', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1]);
$state = dgState($degasca, $target, $anubisarDeck);
$state->battle['strike'] = [
    'attacker_id' => 24,
    'target_id' => 25,
    'original_target_id' => 25,
    'defender_id' => null,
    'state' => 'waiting_choice',
    'confirmed' => [],
    'defenders' => [],
    'redirect_candidates' => [],
    'friendly_fire' => false,
    'choice_winner' => 'attack',
    'result' => ['attack' => 'strong', 'defend' => '', 'winner' => 'attack'],
];
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_strike_mode', ['mode' => 'normal']));
dgAssert($result->success, $result->error ?? 'Strong strike mode should resolve.');
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Strong strike should pause on Degasca deck pick.');
dgAssert($target->hp === 7, 'Primary strong strike should apply once before Degasca choice.');
$blocked = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('confirm_strike'));
dgAssert(!$blocked->success, 'Confirm strike must be blocked while Degasca pending is active.');
dgChooseLearn($state, 26);
dgAssert($target->hp === 7, 'Resolving Degasca choice must not repeat strike damage.');
dgAssert((new Engine())->apply($state, GameState::PLAYER_HOST, new Command('confirm_strike'))->success, 'Host confirm after Degasca choice should succeed.');
dgAssert($target->hp === 7, 'Host confirm must not repeat damage.');
dgAssert((new Engine())->apply($state, GameState::PLAYER_PLAYER, new Command('confirm_strike'))->success, 'Player confirm after Degasca choice should succeed.');
dgAssert($target->hp === 7, 'Player confirm must not repeat damage.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca strong sequence should not restart after confirms.');

// Talion condition: no token at zero wounds, token when strong strike dealt wounds.
$degasca = dgCard([
    'instanceId' => 30,
    'ukid' => 's1_32',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'strikeStrong' => 0,
    'flags' => ['degasca_learned' => ['s1_149']],
]);
$target = dgCard(['instanceId' => 31, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$grave = dgCard(['instanceId' => 32, 'ukid' => 'grave', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_GRAVEYARD, 'hp' => 0, 'prop' => ['incarnation' => 4]]);
$state = dgState($degasca, $target, $grave);
$state->battle['strike'] = ['attacker_id' => 30, 'target_id' => 31, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
(new StrikeResolver($state, new Engine()))->apply(['attack' => 'strong', 'defend' => '', 'winner' => 'attack'], false);
dgAssert(!isset($grave->markers['incarnation']), 'Learned Talion must not trigger at zero wounds.');

$degasca->strikeStrong = 3;
$grave->markers = [];
$target->hp = 10;
$state->battle['strike'] = ['attacker_id' => 30, 'target_id' => 31, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
(new StrikeResolver($state, new Engine()))->apply(['attack' => 'strong', 'defend' => '', 'winner' => 'attack'], false);
dgAssert((int) ($grave->markers['incarnation']['value'] ?? 0) === 1, 'Learned Talion should add token after strong wound.');

// Anubisar chain choice resumes and clears when no new deck candidates remain.
$degasca = dgCard([
    'instanceId' => 40,
    'ukid' => 's1_32',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'flags' => ['degasca_learned' => ['s1_26']],
]);
$target = dgCard(['instanceId' => 41, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$c1 = dgCard(['instanceId' => 42, 'owner' => GameState::PLAYER_PLAYER, 'row' => 2, 'col' => 4, 'hp' => 10]);
$c2 = dgCard(['instanceId' => 43, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4, 'hp' => 10]);
$c3 = dgCard(['instanceId' => 44, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5, 'hp' => 10]);
$state = dgState($degasca, $target, $c1, $c2, $c3);
$state->battle['strike'] = ['attacker_id' => 40, 'target_id' => 41, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
(new StrikeResolver($state, new Engine()))->apply(['attack' => 'strong', 'defend' => '', 'winner' => 'attack'], false);
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_chain', 'Learned Anubisar should open Degasca chain choice.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_degasca_chain', ['target_ids' => [42, 43]]));
dgAssert($result->success, $result->error ?? 'Degasca chain choice should succeed.');
dgAssert($c1->hp === 9 && $c2->hp === 9 && $c3->hp === 10, 'Degasca chain should damage chosen targets only.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca chain sequence should finish without deck candidates.');

// Tich effect uses the weak strike of the card behind the target.
$degasca = dgCard([
    'instanceId' => 50,
    'ukid' => 's1_32',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'flags' => ['degasca_learned' => ['s1_199']],
]);
$target = dgCard(['instanceId' => 51, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$behind = dgCard(['instanceId' => 52, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5, 'hp' => 10, 'strikeWeak' => 2]);
$state = dgState($degasca, $target, $behind);
$state->battle['strike'] = ['attacker_id' => 50, 'target_id' => 51, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
(new StrikeResolver($state, new Engine()))->apply(['attack' => 'strong', 'defend' => '', 'winner' => 'attack'], false);
dgAssert($target->hp === 5, 'Degasca learned Tich should add behind weak strike damage after primary strong damage.');

echo "Degasca tests passed.\n";
