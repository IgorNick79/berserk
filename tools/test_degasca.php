<?php
// tools/test_degasca.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\ActionResolver;
use Berserk\Core\CardInstance;
use Berserk\Core\Choice\ChoiceRegistry;
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

function dgDegascaProp(): array
{
    return [
        'start_pre_battle' => [
            ['effect' => ['type' => 'degasca_study']],
        ],
        'strike_effects' => [
            ['levels' => ['strong'], 'type' => 'degasca_sequence'],
        ],
    ];
}

function dgMarkerProp(string $type = 'stun'): array
{
    return [
        'strike_effects' => [[
            'levels' => ['strong'],
            'marker' => ['type' => $type, 'expire' => 1, 'timing' => 'owner_turn'],
        ]],
    ];
}

function dgMixedLevelProp(): array
{
    return [
        'strike_effects' => [[
            'levels' => ['strong', 'medium'],
            'marker' => ['type' => 'stun', 'expire' => 1, 'timing' => 'owner_turn'],
        ]],
    ];
}

function dgChainProp(): array
{
    return [
        'strike_effects' => [[
            'levels' => ['strong'],
            'chain_damage' => ['value' => 1, 'max_targets' => 2, 'filter' => 'enemy'],
        ]],
    ];
}

function dgChainOneProp(): array
{
    return [
        'strike_effects' => [[
            'levels' => ['strong'],
            'chain_damage' => ['value' => 1, 'max_targets' => 1, 'filter' => 'enemy'],
        ]],
    ];
}

function dgChainThenMarkerProp(): array
{
    return [
        'strike_effects' => [
            [
                'levels' => ['strong'],
                'chain_damage' => ['value' => 1, 'max_targets' => 2, 'filter' => 'enemy'],
            ],
            [
                'levels' => ['strong'],
                'marker' => ['type' => 'stun', 'expire' => 1, 'timing' => 'owner_turn'],
            ],
        ],
    ];
}

function dgDoubleChainProp(): array
{
    return [
        'strike_effects' => [
            [
                'levels' => ['strong'],
                'chain_damage' => ['value' => 1, 'max_targets' => 2, 'filter' => 'enemy'],
            ],
            [
                'levels' => ['strong'],
                'chain_damage' => ['value' => 1, 'max_targets' => 2, 'filter' => 'enemy'],
            ],
        ],
    ];
}

function dgTalionProp(): array
{
    return [
        'strike_effects' => [[
            'levels' => ['strong'],
            'type' => 'incarnation_token_graveyard',
        ]],
    ];
}

function dgTichProp(): array
{
    return [
        'strike_effects' => [[
            'levels' => ['strong'],
            'wound_target_by_behind_weak_strike' => true,
        ]],
    ];
}

function dgLearnedFlags(array $entries): array
{
    $ukids = [];
    $effects = [];
    foreach ($entries as $ukid => $effectList) {
        $ukids[] = (string) $ukid;
        $effects[] = ['ukid' => (string) $ukid, 'effects' => array_values((array) $effectList)];
    }
    return ['degasca_learned' => $ukids, 'degasca_learned_effects' => $effects];
}

function dgAnyDeathWatcherProp(): array
{
    return ['on_any_death' => [['side' => 'enemy']]];
}

function dgNokamiProp(): array
{
    return ['on_poison_damage' => ['value' => 1]];
}

function dgSkipAnyDeath(GameState $state, string $playerKey = GameState::PLAYER_HOST): void
{
    $result = (new Engine())->apply($state, $playerKey, new Command('choose_any_death_target', ['target_id' => 0]));
    dgAssert($result->success, $result->error ?? 'Any-death skip should succeed.');
}

function dgChooseNokami(GameState $state, int $targetId, string $playerKey = GameState::PLAYER_PLAYER): void
{
    $result = (new Engine())->apply($state, $playerKey, new Command('choose_nokami_wound', ['target_id' => $targetId]));
    dgAssert($result->success, $result->error ?? 'Nokami wound choice should succeed.');
}

function dgApplyStrikeResult(GameState $state, array $result): void
{
    (new StrikeResolver($state, new Engine()))->apply($result, false);
    if (($state->battle['strike']['state'] ?? '') !== 'waiting_auto_target') {
        $state->battle['strike']['state'] = 'results';
    }
}

function dgConfirmStrikeBoth(GameState $state): void
{
    $result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($state)]));
    dgAssert($result->success, $result->error ?? 'Host strike confirm should succeed.');
    $result = (new Engine())->apply($state, GameState::PLAYER_PLAYER, new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($state)]));
    dgAssert($result->success, $result->error ?? 'Player strike confirm should succeed.');
}

// Degasca opens a pre-battle chooser and learns a starter ability before battle continues.
$degasca = dgCard([
    'instanceId' => 1,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
]);
$anubisarDeck = dgCard(['instanceId' => 3, 'ukid' => 'learn_chain', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgChainProp()]);
$state = dgState($degasca, $anubisarDeck);
(new TurnProcessor($state, new Engine()))->startBattle();
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Degasca must open a pre-battle chooser.');
dgChooseLearn($state, 3);
dgAssert(($degasca->flags['degasca_learned'] ?? []) === ['learn_chain'], 'Degasca should learn the chosen pre-battle ability.');
dgAssert($anubisarDeck->zone === CardInstance::ZONE_DECK, 'Pre-battle learning must not remove a learnable card from deck.');
dgAssert(!empty($state->battle['pre_battle_completed']), 'Pre-battle lifecycle should continue after Degasca choice.');

// Pre-battle with no available new ability still finishes synchronously and does not open pending.
$degasca = dgCard([
    'instanceId' => 60,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_marker' => dgMarkerProp()['strike_effects']]),
]);
$state = dgState($degasca);
(new TurnProcessor($state, new Engine()))->startBattle();
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca should not leave pending when no new ability exists.');
dgAssert(empty($state->battle['pending_intrigue_start']), 'Pre-battle queue should finish when Degasca has no deck candidates.');
dgAssert(!empty($state->battle['pre_battle_completed']), 'Pre-battle should complete when Degasca has no deck candidates.');

// A matching ukid alone is not enough: Degasca behavior is driven only by prop.
$plainS132 = dgCard([
    'instanceId' => 61,
    'ukid' => 's1_32',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
]);
$candidate = dgCard(['instanceId' => 63, 'ukid' => 'learn_chain', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgChainProp()]);
$state = dgState($plainS132, $candidate);
(new TurnProcessor($state, new Engine()))->startBattle();
dgAssert(empty($state->battle['pending_degasca_sequence']), 's1_32 without Degasca prop should not open a chooser.');

// Unavailable Degasca during a resumed pending clears the pending and does not leave combat stuck.
$degasca = dgCard([
    'instanceId' => 62,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'hp' => 0,
    'prop' => dgDegascaProp(),
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
$degasca = dgCard(['instanceId' => 10, 'ukid' => 'any_degasca', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'prop' => dgDegascaProp()]);
$attacker = dgCard([
    'instanceId' => 12,
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 2,
]);
$target = dgCard(['instanceId' => 11, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 2]);
$medusaDeck = dgCard(['instanceId' => 13, 'ukid' => 'learn_marker', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgMarkerProp()]);
$state = dgState($degasca, $attacker, $target, $medusaDeck);
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('strike', ['card_id' => 12, 'target_id' => 11]));
dgAssert($result->success, $result->error ?? 'Non-Degasca strike should declare.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca pre-battle chooser must not open on strike declaration.');
dgAssert(($state->battle['strike']['attack_dice'] ?? null) !== null, 'Strike should continue normally without Degasca pre-battle hook.');

// Aristocrat pre-battle lifecycle is unaffected by Degasca being present.
$degasca = dgCard(['instanceId' => 70, 'ukid' => 'any_degasca', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'prop' => dgDegascaProp()]);
$aristocrat = dgCard(['instanceId' => 71, 'ukid' => 's1_194', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 2, 'prop' => dgAristocratProp()]);
$enemyA = dgCard(['instanceId' => 72, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 2]);
$enemyB = dgCard(['instanceId' => 73, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3]);
$anubisarDeck = dgCard(['instanceId' => 74, 'ukid' => 'learn_chain', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgChainProp()]);
$state = dgState($degasca, $aristocrat, $enemyA, $enemyB, $anubisarDeck);
(new TurnProcessor($state, new Engine()))->startBattle();
dgAssert(($state->battle['pending_intrigue_start'][0]['type'] ?? '') === 'intrigue', 'Aristocrat should remain queued after Degasca.');
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Degasca should open before Aristocrat when it is earlier in the pre-battle queue.');
dgChooseLearn($state, 74);
$result = (new ActionResolver($state, new Engine()))->chooseIntrigueStart(GameState::PLAYER_HOST, new Command('choose_intrigue_start', ['target_ids' => [72, 73]]));
dgAssert($result->success, $result->error ?? 'Aristocrat should resolve while Degasca waits for a strong strike.');
dgAssert(($enemyA->markers['poison']['value'] ?? null) === 1 && ($enemyB->markers['poison']['value'] ?? null) === 1, 'Aristocrat effect should still apply after Degasca.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca should not open after Aristocrat pre-battle resolves.');

// Both players' Degascas wait for their own strong strikes; pre-battle does not choose for either side.
$hostDegasca = dgCard(['instanceId' => 80, 'ukid' => 'host_degasca', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'prop' => dgDegascaProp()]);
$playerDegasca = dgCard(['instanceId' => 81, 'ukid' => 'player_degasca', 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 3, 'prop' => dgDegascaProp()]);
$hostDeck = dgCard(['instanceId' => 82, 'ukid' => 'host_chain', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgChainProp()]);
$playerDeck = dgCard(['instanceId' => 83, 'ukid' => 'player_marker', 'owner' => GameState::PLAYER_PLAYER, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgMarkerProp()]);
$state = dgState($hostDegasca, $playerDegasca, $hostDeck, $playerDeck);
(new TurnProcessor($state, new Engine()))->startBattle();
dgAssert(($state->battle['pending_degasca_sequence']['source_id'] ?? null) === 80, 'First player Degasca should open first during pre-battle.');
dgChooseLearn($state, 82, GameState::PLAYER_HOST);
dgAssert(($state->battle['pending_degasca_sequence']['source_id'] ?? null) === 81, 'Second player Degasca should open after the first one resolves.');
dgChooseLearn($state, 83, GameState::PLAYER_PLAYER);
dgAssert(($state->getCard(80)->flags['degasca_learned'] ?? []) === ['host_chain'], 'Host Degasca should learn during pre-battle.');
dgAssert(($state->getCard(81)->flags['degasca_learned'] ?? []) === ['player_marker'], 'Player Degasca should learn during pre-battle.');
dgAssert(!empty($state->battle['pre_battle_completed']), 'Pre-battle should complete after both Degasca choices.');

// Duplicate deck copies are offered once per ukid after a final strong strike, not before battle.
$degasca = dgCard(['instanceId' => 90, 'ukid' => 'any_degasca', 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'prop' => dgDegascaProp()]);
$target = dgCard(['instanceId' => 89, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$copyA = dgCard(['instanceId' => 91, 'ukid' => 'learn_chain', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgChainProp()]);
$copyB = dgCard(['instanceId' => 92, 'ukid' => 'learn_chain', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 2, 'prop' => dgChainProp()]);
$medusa = dgCard(['instanceId' => 93, 'ukid' => 'learn_marker', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 3, 'prop' => dgMarkerProp()]);
$mixed = dgCard(['instanceId' => 99, 'ukid' => 'mixed_levels', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 4, 'prop' => dgMixedLevelProp()]);
$state = dgState($degasca, $target, $copyA, $copyB, $medusa, $mixed);
$state->battle['strike'] = ['attacker_id' => 90, 'target_id' => 89, 'defender_id' => null, 'state' => 'results', 'confirmed' => [GameState::PLAYER_HOST, GameState::PLAYER_PLAYER], 'result' => ['attack' => 'strong', 'defend' => '', 'winner' => 'attack'], 'final' => ['attack' => 'strong', 'defend' => '', 'decreased' => false], 'damage_total' => 3, 'strike_hit' => true];
(new StrikeResolver($state, new Engine()))->continueAfterStrikeResultAck($state->battle['strike']);
$candidateIds = $state->battle['pending_degasca_sequence']['candidate_ids'] ?? [];
dgAssert(count($candidateIds) === 2, 'Degasca should offer one candidate per source ukid.');
dgAssert(count(array_intersect($candidateIds, [91, 92])) === 1, 'Duplicate Anubisar copies should be collapsed.');
dgAssert(!in_array(99, $candidateIds, true), 'Mixed-level strike_effects should not be offered as Degasca candidates.');

$degasca = dgCard([
    'instanceId' => 94,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags([
        'learn_chain' => dgChainProp()['strike_effects'],
        'learn_marker' => dgMarkerProp()['strike_effects'],
        'learn_talion' => dgTalionProp()['strike_effects'],
        'learn_tich' => dgTichProp()['strike_effects'],
    ]),
]);
$state = dgState(
    $degasca,
    dgCard(['instanceId' => 95, 'ukid' => 'learn_chain', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'prop' => dgChainProp()]),
    dgCard(['instanceId' => 96, 'ukid' => 'learn_marker', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'prop' => dgMarkerProp()]),
    dgCard(['instanceId' => 97, 'ukid' => 'learn_talion', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'prop' => dgTalionProp()]),
    dgCard(['instanceId' => 98, 'ukid' => 'learn_tich', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'prop' => dgTichProp()])
);
(new TurnProcessor($state, new Engine()))->startBattle();
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Fully learned Degasca should not open pre-battle chooser.');
(new TurnProcessor($state, new Engine()))->continuePreBattleEffects();
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Repeated pre-battle continuation should not reopen Degasca.');

// Strong strike: learned Medusa runs, new Anubisar is learned but not run in the same sequence.
$degasca = dgCard([
    'instanceId' => 20,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_marker' => dgMarkerProp()['strike_effects']]),
]);
$target = dgCard(['instanceId' => 21, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$adjacent = dgCard(['instanceId' => 22, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4, 'hp' => 10]);
$anubisarDeck = dgCard(['instanceId' => 23, 'ukid' => 'learn_chain', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgChainProp()]);
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
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca should wait until the strong-strike result is fully acknowledged.');
dgAssert((new Engine())->apply($state, GameState::PLAYER_HOST, new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($state)]))->success, 'Host confirm before Degasca should succeed.');
dgAssert((new Engine())->apply($state, GameState::PLAYER_PLAYER, new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($state)]))->success, 'Player confirm should open Degasca after the fight lifecycle.');
dgAssert(isset($target->markers['stun']), 'Learned Medusa effect should stun target.');
dgAssert(empty($state->battle['chain_damage'] ?? []), 'Newly learned Anubisar must not run in same sequence.');
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Final strong strike should proceed to Degasca deck pick.');
dgChooseLearn($state, 23);
dgAssert(($degasca->flags['degasca_learned'] ?? []) === ['learn_marker', 'learn_chain'], 'Degasca should preserve learned order.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca pending should clear after strong pick.');
dgAssert(empty($state->battle['strike']), 'Strike should close after Degasca finishes its post-fight sequence.');

// Ost can upgrade a weak final strike to strong; Degasca must use the overridden result level.
$degasca = dgCard([
    'instanceId' => 110,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
]);
$target = dgCard(['instanceId' => 111, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 9]);
$deck = dgCard(['instanceId' => 112, 'ukid' => 'learn_marker', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgMarkerProp()]);
$state = dgState($degasca, $target, $deck);
$state->battle['strike'] = [
    'attacker_id' => 110,
    'target_id' => 111,
    'defender_id' => null,
    'state' => 'results',
    'confirmed' => [GameState::PLAYER_HOST, GameState::PLAYER_PLAYER],
    'result' => ['attack' => 'strong', 'defend' => '', 'winner' => 'attack'],
    'final' => ['attack' => 'weak', 'defend' => '', 'decreased' => false],
    'level_overridden' => true,
    'damage_total' => 1,
    'strike_hit' => true,
];
(new StrikeResolver($state, new Engine()))->continueAfterStrikeResultAck($state->battle['strike']);
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Degasca should trigger when Ost changes a weak strike result to strong.');

// Strong strike sequence opens after both result confirmations and does not reapply damage afterward.
$degasca = dgCard([
    'instanceId' => 24,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_marker' => dgMarkerProp()['strike_effects']]),
]);
$target = dgCard(['instanceId' => 25, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$anubisarDeck = dgCard(['instanceId' => 26, 'ukid' => 'learn_chain', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgChainProp()]);
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
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca should not pause before result confirmation.');
dgAssert($target->hp === 7, 'Primary strong strike should apply once before Degasca choice.');
dgAssert((new Engine())->apply($state, GameState::PLAYER_HOST, new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($state)]))->success, 'Host confirm should succeed before Degasca opens.');
dgAssert((new Engine())->apply($state, GameState::PLAYER_PLAYER, new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($state)]))->success, 'Player confirm should open Degasca.');
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Final strong strike should pause on Degasca deck pick.');
$blocked = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('confirm_strike', ['wait_id'=>\Berserk\Core\InformationalWaitManager::token($state)]));
dgAssert(!$blocked->success, 'Extra confirm must be blocked while Degasca pending is active.');
dgChooseLearn($state, 26);
dgAssert($target->hp === 7, 'Resolving Degasca choice must not repeat strike damage.');
dgAssert(empty($state->battle['strike']), 'Strike should close after Degasca choice because both players already confirmed.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca strong sequence should not restart after confirms.');

// Primary strike death pending should resolve before learned effects continue.
$degasca = dgCard([
    'instanceId' => 27,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_marker' => dgMarkerProp()['strike_effects']]),
]);
$target = dgCard(['instanceId' => 28, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 3]);
$watcher = dgCard(['instanceId' => 29, 'ukid' => 'watcher', 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 4, 'hp' => 10, 'prop' => dgAnyDeathWatcherProp()]);
$survivor = dgCard(['instanceId' => 86, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 5, 'hp' => 10]);
$deck = dgCard(['instanceId' => 36, 'ukid' => 'learn_chain', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgChainProp()]);
$state = dgState($degasca, $target, $watcher, $survivor, $deck);
$state->battle['strike'] = ['attacker_id' => 27, 'target_id' => 28, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgAssert(!empty($state->battle['pending_any_death']), 'Primary strike death should create any-death pending before learned effects continue.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca should hand off to primary-death pending before learned effects.');
dgAssert(!isset($target->markers['stun']), 'Learned marker should not run before primary-death pending is resolved.');
$state = GameState::fromArray($state->toArray());
dgSkipAnyDeath($state);
dgAssert(!isset($state->getCard(28)->markers['stun']), 'Degasca should still wait for strike confirmation after primary-death pending.');
dgConfirmStrikeBoth($state);
dgAssert(isset($state->getCard(28)->markers['stun']), 'Degasca should resume learned effects after primary-death pending and result confirmation.');
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Degasca should offer a new learned ability after primary-death pending.');
dgAssert(($state->battle['pending_degasca_sequence']['index'] ?? -1) === 1, 'Primary-death resume should not repeat the learned marker effect.');

// A pre-existing any-death pending must pause Degasca before the next learned effect.
$degasca = dgCard([
    'instanceId' => 37,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_marker' => dgMarkerProp()['strike_effects']]),
]);
$target = dgCard(['instanceId' => 38, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$watcher = dgCard(['instanceId' => 39, 'ukid' => 'watcher', 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 4, 'hp' => 10, 'prop' => dgAnyDeathWatcherProp()]);
$deck = dgCard(['instanceId' => 40, 'ukid' => 'learn_chain', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgChainProp()]);
$state = dgState($degasca, $target, $watcher, $deck);
$state->battle['pending_any_death'] = [[
    'type' => 'poison_near',
    'source_id' => 39,
    'died_id' => 999,
    'died_ukid' => 'dead',
    'candidates' => [38],
    'poison_value' => 1,
]];
$state->battle['strike'] = ['attacker_id' => 37, 'target_id' => 38, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca should hand off to pre-existing any-death pending before running learned marker.');
dgAssert(!isset($target->markers['stun']), 'Learned marker should not run while pre-existing any-death pending is unresolved.');
dgSkipAnyDeath($state);
dgConfirmStrikeBoth($state);
dgAssert(isset($target->markers['stun']), 'Degasca should resume learned marker after pre-existing any-death pending.');

// Resolving a pre-existing any-death pending can resume Degasca into a learned effect that opens another pending.
$degasca = dgCard([
    'instanceId' => 78,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_chain' => dgChainProp()['strike_effects']]),
]);
$target = dgCard(['instanceId' => 79, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$watcher = dgCard(['instanceId' => 80, 'ukid' => 'watcher', 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 4, 'hp' => 10, 'prop' => dgAnyDeathWatcherProp()]);
$c1 = dgCard(['instanceId' => 81, 'owner' => GameState::PLAYER_PLAYER, 'row' => 2, 'col' => 4, 'hp' => 10]);
$c2 = dgCard(['instanceId' => 82, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4, 'hp' => 10]);
$c3 = dgCard(['instanceId' => 83, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5, 'hp' => 10]);
$deck = dgCard(['instanceId' => 84, 'ukid' => 'learn_marker', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgMarkerProp()]);
$state = dgState($degasca, $target, $watcher, $c1, $c2, $c3, $deck);
$state->battle['pending_any_death'] = [[
    'type' => 'poison_near',
    'source_id' => 80,
    'died_id' => 998,
    'died_ukid' => 'dead',
    'candidates' => [79],
    'poison_value' => 1,
]];
$state->battle['strike'] = ['attacker_id' => 78, 'target_id' => 79, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgAssert(empty($state->battle['pending_anubisar_chain']), 'Learned chain should not open while pre-existing any-death is active.');
dgSkipAnyDeath($state);
dgConfirmStrikeBoth($state);
dgAssert(!empty($state->battle['pending_anubisar_chain']), 'Degasca should resume after any-death and hand off to pending created by the learned effect.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca should wait while the learned chain choice is active.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_anubisar_chain', ['target_ids' => [81, 82]]));
dgAssert($result->success, $result->error ?? 'Chain choice after any-death resume should succeed.');
dgAssert($state->getCard(81)->hp === 9 && $state->getCard(82)->hp === 9 && $state->getCard(83)->hp === 10, 'Resumed chain choice should apply exactly once.');
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Degasca should continue to deck pick after the nested learned-effect pending.');

// Restoring an external continuation must not overwrite another active Degasca sequence.
$state = dgState(
    dgCard(['instanceId' => 85, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'prop' => dgDegascaProp()]),
    dgCard(['instanceId' => 86, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10])
);
$state->battle['pending_degasca_sequence'] = [
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 85,
    'stage' => 'choose_card',
    'index' => 9,
    'effects' => [],
    'candidates' => [],
];
(new DegascaProcessor($state, new Engine()))->restoreAndResume([
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 85,
    'stage' => 'effects',
    'index' => 0,
    'effects' => [[['levels' => ['strong'], 'marker' => ['type' => 'stun']]]],
    'context' => ['target_id' => 86, 'attack_strike' => 'strong', 'wounds_dealt' => 1],
]);
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'restoreAndResume must not overwrite an active Degasca pending.');
dgAssert(($state->battle['pending_degasca_sequence']['index'] ?? -1) === 9, 'restoreAndResume must preserve the active Degasca sequence index.');

// The external-pending probe follows the same priority order as ChoiceRegistry::current(), excluding Degasca itself.
$state = dgState(
    dgCard(['instanceId' => 91, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'prop' => dgDegascaProp()]),
    dgCard(['instanceId' => 92, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]),
    dgCard(['instanceId' => 93, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4, 'hp' => 10])
);
$state->battle['pending_degasca_sequence'] = [
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 91,
    'stage' => 'choose_card',
    'index' => 0,
    'effects' => [],
    'candidates' => [],
];
$state->battle['pending_any_death'] = [[
    'type' => 'poison_near',
    'source_id' => 91,
    'died_id' => 999,
    'died_ukid' => 'dead',
    'candidates' => [92],
    'poison_value' => 1,
]];
$state->battle['pending_nokami_wound'] = [[
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 91,
    'poisoned_ids' => [92],
    'candidates' => [93],
    'value' => 1,
]];
dgAssert((ChoiceRegistry::current($state)?->pendingKey() ?? '') === 'pending_degasca_sequence', 'ChoiceRegistry should still expose active Degasca first to the UI.');
$method = new ReflectionMethod(DegascaProcessor::class, 'currentExternalPendingKey');
$method->setAccessible(true);
$externalKey = $method->invoke(new DegascaProcessor($state, new Engine()));
dgAssert($externalKey === 'pending_any_death', 'Degasca external handoff should choose the first non-Degasca pending in registry priority order.');

// A continuation that collides with an active Degasca chooser is deferred and resumed after the chooser finishes.
$degasca = dgCard(['instanceId' => 94, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'prop' => dgDegascaProp()]);
$learn = dgCard(['instanceId' => 95, 'ukid' => 'learn_marker', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgMarkerProp('learned')]);
$target = dgCard(['instanceId' => 96, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$state = dgState($degasca, $learn, $target);
$state->battle['pending_degasca_sequence'] = [
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 94,
    'activation' => 'strong',
    'stage' => 'choose_card',
    'index' => 0,
    'effects' => [],
    'context' => ['target_id' => 96, 'attack_strike' => 'strong', 'wounds_dealt' => 1],
    'candidate_ids' => [95],
];
(new DegascaProcessor($state, new Engine()))->restoreAndResume([
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 94,
    'activation' => 'strong',
    'stage' => 'effects',
    'index' => 0,
    'effects' => [['ukid' => 'deferred_marker', 'effects' => dgMarkerProp('deferred')['strike_effects']]],
    'context' => ['target_id' => 96, 'attack_strike' => 'strong', 'wounds_dealt' => 1],
]);
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Conflicting restore should keep the active chooser visible.');
dgChooseLearn($state, 95);
dgAssert(isset($target->markers['deferred']), 'Deferred continuation should resume after the active Degasca chooser finishes.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Deferred continuation should not leave a dangling Degasca pending.');

// A completed pre-battle sequence must continue the pre-battle lifecycle before draining deferred continuations.
$degasca = dgCard(['instanceId' => 103, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'prop' => dgDegascaProp()]);
$learn = dgCard(['instanceId' => 104, 'ukid' => 'learn_marker', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgMarkerProp('learned')]);
$target = dgCard(['instanceId' => 105, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$secondTarget = dgCard(['instanceId' => 106, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4, 'hp' => 10]);
$state = dgState($degasca, $learn, $target, $secondTarget);
$state->battle['pending_degasca_sequence'] = [
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 103,
    'activation' => 'pre_battle',
    'stage' => 'choose_card',
    'index' => 0,
    'effects' => [],
    'context' => [],
    'candidate_ids' => [104],
];
(new DegascaProcessor($state, new Engine()))->restoreAndResume([
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 103,
    'activation' => 'strong',
    'stage' => 'effects',
    'index' => 0,
    'effects' => [['ukid' => 'deferred_one', 'effects' => dgMarkerProp('deferred_one')['strike_effects']]],
    'context' => ['target_id' => 105, 'attack_strike' => 'strong', 'wounds_dealt' => 1],
]);
(new DegascaProcessor($state, new Engine()))->restoreAndResume([
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 103,
    'activation' => 'strong',
    'stage' => 'effects',
    'index' => 0,
    'effects' => [['ukid' => 'deferred_two', 'effects' => dgMarkerProp('deferred_two')['strike_effects']]],
    'context' => ['target_id' => 106, 'attack_strike' => 'strong', 'wounds_dealt' => 1],
]);
$state = GameState::fromArray($state->toArray());
dgChooseLearn($state, 104);
dgAssert(!empty($state->battle['pre_battle_completed']), 'Pre-battle lifecycle should complete even when deferred Degasca continuations exist.');
dgAssert(isset($state->getCard(105)->markers['deferred_one']), 'First deferred continuation should resume after pre-battle lifecycle continuation.');
dgAssert(isset($state->getCard(106)->markers['deferred_two']), 'Second deferred continuation should resume after the first deferred sequence completes.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'No Degasca pending should remain after draining pre-battle deferred continuations.');

// Moving any-death continuations behind an already pending Nokami choice must append, not overwrite.
$degasca = dgCard(['instanceId' => 97, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'prop' => dgDegascaProp()]);
$watcher = dgCard(['instanceId' => 98, 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 4, 'hp' => 10, 'prop' => dgAnyDeathWatcherProp()]);
$nokami = dgCard(['instanceId' => 99, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4, 'hp' => 10, 'prop' => dgNokamiProp()]);
$nokamiTarget = dgCard(['instanceId' => 100, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 3, 'hp' => 10]);
$oldTarget = dgCard(['instanceId' => 101, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$newTarget = dgCard(['instanceId' => 102, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5, 'hp' => 10]);
$state = dgState($degasca, $watcher, $nokami, $nokamiTarget, $oldTarget, $newTarget);
$state->battle['pending_any_death'] = [[
    'type' => 'poison_near',
    'source_id' => 98,
    'died_id' => 990,
    'died_ukid' => 'dead',
    'candidates' => [101],
    'poison_value' => 1,
]];
$state->battle['pending_nokami_wound'] = [[
    'owner' => GameState::PLAYER_PLAYER,
    'source_id' => 99,
    'poisoned_ids' => [101],
    'candidates' => [100],
    'value' => 1,
]];
DegascaProcessor::appendSavedContinuation($state, DegascaProcessor::NOKAMI_WOUND_CONTINUATION_KEY, [
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 97,
    'activation' => 'strong',
    'stage' => 'effects',
    'index' => 0,
    'effects' => [['ukid' => 'old_marker', 'effects' => dgMarkerProp('old_pending')['strike_effects']]],
    'context' => ['target_id' => 101, 'attack_strike' => 'strong', 'wounds_dealt' => 1],
]);
DegascaProcessor::appendSavedContinuation($state, DegascaProcessor::ANY_DEATH_CONTINUATION_KEY, [
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 97,
    'activation' => 'strong',
    'stage' => 'effects',
    'index' => 0,
    'effects' => [['ukid' => 'new_marker', 'effects' => dgMarkerProp('new_pending')['strike_effects']]],
    'context' => ['target_id' => 102, 'attack_strike' => 'strong', 'wounds_dealt' => 1],
]);
dgSkipAnyDeath($state);
$stored = DegascaProcessor::drainSavedContinuations($state, DegascaProcessor::NOKAMI_WOUND_CONTINUATION_KEY);
dgAssert(count($stored) === 2, 'Any-death to Nokami transfer should append without overwriting an existing continuation.');
foreach ($stored as $pending) {
    DegascaProcessor::appendSavedContinuation($state, DegascaProcessor::NOKAMI_WOUND_CONTINUATION_KEY, $pending);
}
dgChooseNokami($state, 100, GameState::PLAYER_PLAYER);
dgAssert(isset($oldTarget->markers['old_pending']), 'Existing Nokami continuation should still resume.');
dgAssert(isset($newTarget->markers['new_pending']), 'Transferred any-death continuation should resume after Nokami.');
dgAssert(empty($state->battle[DegascaProcessor::NOKAMI_WOUND_CONTINUATION_KEY] ?? []), 'Nokami continuation storage should drain after resolution.');

// Missing target skips target-dependent effects, but target-independent effects still resolve.
$degasca = dgCard([
    'instanceId' => 87,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_marker' => dgMarkerProp()['strike_effects']]),
]);
$deck = dgCard(['instanceId' => 88, 'ukid' => 'learn_chain', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgChainProp()]);
$state = dgState($degasca, $deck);
(new DegascaProcessor($state, new Engine()))->restoreAndResume([
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 87,
    'stage' => 'effects',
    'index' => 0,
    'effects' => [['ukid' => 'learn_marker', 'effects' => dgMarkerProp()['strike_effects']]],
    'context' => ['target_id' => 9999, 'attack_strike' => 'strong', 'wounds_dealt' => 1],
]);
dgAssert(!isset($degasca->markers['stun']), 'Missing target should not redirect target-dependent marker effects onto Degasca.');
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Missing target-dependent effect should still advance the sequence.');

$degasca = dgCard([
    'instanceId' => 89,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_talion' => dgTalionProp()['strike_effects']]),
]);
$grave = dgCard(['instanceId' => 90, 'ukid' => 'grave', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_GRAVEYARD, 'hp' => 0, 'prop' => ['incarnation' => 4]]);
$state = dgState($degasca, $grave);
(new DegascaProcessor($state, new Engine()))->restoreAndResume([
    'owner' => GameState::PLAYER_HOST,
    'source_id' => 89,
    'stage' => 'effects',
    'index' => 0,
    'effects' => [['ukid' => 'learn_talion', 'effects' => dgTalionProp()['strike_effects']]],
    'context' => ['target_id' => 9999, 'attack_strike' => 'strong', 'wounds_dealt' => 1],
]);
dgAssert((int) ($grave->markers['incarnation']['value'] ?? 0) === 1, 'Missing target must not skip target-independent Talion effects.');

// Talion condition: no token at zero wounds, token when strong strike dealt wounds.
$degasca = dgCard([
    'instanceId' => 30,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'strikeStrong' => 0,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_talion' => dgTalionProp()['strike_effects']]),
]);
$target = dgCard(['instanceId' => 31, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$grave = dgCard(['instanceId' => 32, 'ukid' => 'grave', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_GRAVEYARD, 'hp' => 0, 'prop' => ['incarnation' => 4]]);
$state = dgState($degasca, $target, $grave);
$state->battle['strike'] = ['attacker_id' => 30, 'target_id' => 31, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgConfirmStrikeBoth($state);
dgAssert(!isset($grave->markers['incarnation']), 'Learned Talion must not trigger at zero wounds.');

$degasca->strikeStrong = 3;
$grave->markers = [];
$target->hp = 10;
$state->battle['strike'] = ['attacker_id' => 30, 'target_id' => 31, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgConfirmStrikeBoth($state);
dgAssert((int) ($grave->markers['incarnation']['value'] ?? 0) === 1, 'Learned Talion should add token after strong wound.');

// Talion migration: old and new configs together should not double-trigger, and zero wounds still block it.
$talion = dgCard([
    'instanceId' => 33,
    'ukid' => 'talion_migrating',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'strikeStrong' => 3,
    'prop' => [
        'on_strong_strike' => ['type' => 'incarnation_token_graveyard'],
        'strike_effects' => dgTalionProp()['strike_effects'],
    ],
]);
$target = dgCard(['instanceId' => 34, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$grave = dgCard(['instanceId' => 35, 'ukid' => 'grave', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_GRAVEYARD, 'hp' => 0, 'prop' => ['incarnation' => 4]]);
$state = dgState($talion, $target, $grave);
$state->battle['strike'] = ['attacker_id' => 33, 'target_id' => 34, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgConfirmStrikeBoth($state);
dgAssert((int) ($grave->markers['incarnation']['value'] ?? 0) === 1, 'Talion old+new migration config should add exactly one token after wounds.');

$talion->strikeStrong = 0;
$grave->markers = [];
$target->hp = 10;
$state->battle['strike'] = ['attacker_id' => 33, 'target_id' => 34, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgConfirmStrikeBoth($state);
dgAssert(!isset($grave->markers['incarnation']), 'Talion old+new migration config should not trigger at zero wounds.');

// Anubisar chain choice resumes and clears when no new deck candidates remain.
$degasca = dgCard([
    'instanceId' => 40,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_chain' => dgChainProp()['strike_effects']]),
]);
$target = dgCard(['instanceId' => 41, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$c1 = dgCard(['instanceId' => 42, 'owner' => GameState::PLAYER_PLAYER, 'row' => 2, 'col' => 4, 'hp' => 10]);
$c2 = dgCard(['instanceId' => 43, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4, 'hp' => 10]);
$c3 = dgCard(['instanceId' => 44, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5, 'hp' => 10]);
$state = dgState($degasca, $target, $c1, $c2, $c3);
$state->battle['strike'] = ['attacker_id' => 40, 'target_id' => 41, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgConfirmStrikeBoth($state);
dgAssert(!empty($state->battle['pending_anubisar_chain']), 'Learned Anubisar should open the shared Anubisar chain choice.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_anubisar_chain', ['target_ids' => [42, 43]]));
dgAssert($result->success, $result->error ?? 'Degasca chain choice should succeed.');
dgAssert($c1->hp === 9 && $c2->hp === 9 && $c3->hp === 10, 'Degasca chain should damage chosen targets only.');
dgAssert(count($state->battle['degasca_messages'][0]['items'] ?? []) === 2, 'Degasca chain damage should remain recorded for the result UI.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca chain sequence should finish without deck candidates.');
dgAssert(empty($state->battle['pending_anubisar_chain']), 'Shared Anubisar pending should be cleared after choice.');

// A learned card with two effects resumes at the second effect after the first opens a choice.
$degasca = dgCard([
    'instanceId' => 45,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_chain_marker' => dgChainThenMarkerProp()['strike_effects']]),
]);
$target = dgCard(['instanceId' => 46, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$c1 = dgCard(['instanceId' => 47, 'owner' => GameState::PLAYER_PLAYER, 'row' => 2, 'col' => 4, 'hp' => 10]);
$c2 = dgCard(['instanceId' => 48, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4, 'hp' => 10]);
$c3 = dgCard(['instanceId' => 49, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5, 'hp' => 10]);
$state = dgState($degasca, $target, $c1, $c2, $c3);
$state->battle['strike'] = ['attacker_id' => 45, 'target_id' => 46, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgConfirmStrikeBoth($state);
dgAssert(!empty($state->battle['pending_anubisar_chain']), 'First effect of a multi-effect learned card should open a shared choice.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_anubisar_chain', ['target_ids' => [47, 48]]));
dgAssert($result->success, $result->error ?? 'First multi-effect choice should resolve.');
dgAssert(isset($target->markers['stun']), 'Second effect of the same learned card should resume and apply after choice.');
dgAssert($c1->hp === 9 && $c2->hp === 9 && $c3->hp === 10, 'First multi-effect chain should damage chosen targets only.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Multi-effect learned card should finish without deck candidates.');

// Two sequential learned effects can both open choices, with serialization between them.
$degasca = dgCard([
    'instanceId' => 53,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_double_chain' => dgDoubleChainProp()['strike_effects']]),
]);
$target = dgCard(['instanceId' => 54, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$c1 = dgCard(['instanceId' => 55, 'owner' => GameState::PLAYER_PLAYER, 'row' => 2, 'col' => 4, 'hp' => 10]);
$c2 = dgCard(['instanceId' => 56, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4, 'hp' => 10]);
$c3 = dgCard(['instanceId' => 57, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5, 'hp' => 10]);
$state = dgState($degasca, $target, $c1, $c2, $c3);
$state->battle['strike'] = ['attacker_id' => 53, 'target_id' => 54, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgConfirmStrikeBoth($state);
dgAssert(!empty($state->battle['pending_anubisar_chain']), 'First sequential effect should open a choice.');
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_anubisar_chain', ['target_ids' => [55, 56]]));
dgAssert($result->success, $result->error ?? 'First sequential choice should resolve.');
dgAssert(!empty($state->battle['pending_anubisar_chain']), 'Second sequential effect should open another choice.');
$state = GameState::fromArray($state->toArray());
$result = (new Engine())->apply($state, GameState::PLAYER_HOST, new Command('choose_anubisar_chain', ['target_ids' => [55, 57]]));
dgAssert($result->success, $result->error ?? 'Second sequential choice should resolve after restore.');
dgAssert($state->getCard(55)->hp === 8 && $state->getCard(56)->hp === 9 && $state->getCard(57)->hp === 9, 'Sequential choices should continue from the correct effect without repeats.');
dgAssert(empty($state->battle['pending_anubisar_chain']) && empty($state->battle['pending_degasca_sequence']), 'Sequential choices should clear all pending after completion.');

// Learned chain damage can kill a creature, wait for any-death, then continue without repeating damage.
$degasca = dgCard([
    'instanceId' => 65,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_chain_one' => dgChainOneProp()['strike_effects']]),
]);
$target = dgCard(['instanceId' => 66, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$victim = dgCard(['instanceId' => 67, 'owner' => GameState::PLAYER_PLAYER, 'row' => 2, 'col' => 4, 'hp' => 1]);
$watcher = dgCard(['instanceId' => 68, 'ukid' => 'watcher', 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 5, 'hp' => 10, 'prop' => dgAnyDeathWatcherProp()]);
$deck = dgCard(['instanceId' => 69, 'ukid' => 'learn_marker', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgMarkerProp()]);
$state = dgState($degasca, $target, $victim, $watcher, $deck);
$state->battle['strike'] = ['attacker_id' => 65, 'target_id' => 66, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgConfirmStrikeBoth($state);
dgAssert(!empty($state->battle['pending_any_death']), 'Learned chain lethal damage should create any-death pending.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca should hand off to any-death created by learned chain.');
dgAssert($victim->hp === 0, 'Learned chain should apply lethal damage exactly once before pending.');
$state = GameState::fromArray($state->toArray());
dgSkipAnyDeath($state);
dgAssert($state->getCard(67)->hp === 0, 'Resolving any-death must not repeat learned chain damage.');
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Degasca should continue to deck pick after chain death pending.');
dgAssert(($state->battle['pending_degasca_sequence']['index'] ?? -1) === 1, 'Chain death resume should continue after the completed chain effect.');

// Learned behind weak strike can kill the target, wait for any-death, then continue.
$degasca = dgCard([
    'instanceId' => 70,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_tich' => dgTichProp()['strike_effects']]),
]);
$target = dgCard(['instanceId' => 71, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 4]);
$behind = dgCard(['instanceId' => 72, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5, 'hp' => 10, 'strikeWeak' => 2]);
$watcher = dgCard(['instanceId' => 73, 'ukid' => 'watcher', 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 4, 'hp' => 10, 'prop' => dgAnyDeathWatcherProp()]);
$deck = dgCard(['instanceId' => 74, 'ukid' => 'learn_marker', 'owner' => GameState::PLAYER_HOST, 'zone' => CardInstance::ZONE_DECK, 'order' => 1, 'prop' => dgMarkerProp()]);
$state = dgState($degasca, $target, $behind, $watcher, $deck);
$state->battle['strike'] = ['attacker_id' => 70, 'target_id' => 71, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgConfirmStrikeBoth($state);
dgAssert(!empty($state->battle['pending_any_death']), 'Learned behind weak strike lethal damage should create any-death pending.');
dgAssert(empty($state->battle['pending_degasca_sequence']), 'Degasca should hand off to any-death created by behind weak strike.');
dgSkipAnyDeath($state);
dgAssert(($state->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'Degasca should continue to deck pick after behind weak death pending.');
dgAssert(($state->battle['pending_degasca_sequence']['index'] ?? -1) === 1, 'Behind weak death resume should continue after the completed effect.');

// Tich effect uses the weak strike of the card behind the target.
$degasca = dgCard([
    'instanceId' => 75,
    'ukid' => 'any_degasca',
    'owner' => GameState::PLAYER_HOST,
    'row' => 3,
    'col' => 3,
    'prop' => dgDegascaProp(),
    'flags' => dgLearnedFlags(['learn_tich' => dgTichProp()['strike_effects']]),
]);
$target = dgCard(['instanceId' => 76, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4, 'hp' => 10]);
$behind = dgCard(['instanceId' => 77, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 5, 'hp' => 10, 'strikeWeak' => 2]);
$state = dgState($degasca, $target, $behind);
$state->battle['strike'] = ['attacker_id' => 75, 'target_id' => 76, 'defender_id' => null, 'state' => 'waiting_choice', 'confirmed' => [], 'defenders' => [], 'redirect_candidates' => [], 'friendly_fire' => false];
dgApplyStrikeResult($state, ['attack' => 'strong', 'defend' => '', 'winner' => 'attack']);
dgConfirmStrikeBoth($state);
dgAssert($target->hp === 5, 'Degasca learned Tich should add behind weak strike damage after primary strong damage.');
dgAssert((int) ($state->battle['degasca_messages'][0]['damage'] ?? 0) === 2, 'Degasca learned Tich should remain recorded for the result UI.');

echo "Degasca tests passed.\n";
