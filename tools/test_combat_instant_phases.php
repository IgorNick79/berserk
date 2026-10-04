<?php
// tools/test_combat_instant_phases.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\InstantProcessor;
use Berserk\Core\WoundTransferProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function cipAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cipCard(array $overrides): CardInstance
{
    return new CardInstance(
        instanceId: $overrides['instanceId'],
        ukid: $overrides['ukid'] ?? ('card_' . $overrides['instanceId']),
        owner: $overrides['owner'] ?? GameState::PLAYER_HOST,
        zone: $overrides['zone'] ?? CardInstance::ZONE_FIELD,
        row: $overrides['row'] ?? 3,
        col: $overrides['col'] ?? 3,
        hp: $overrides['hp'] ?? 5,
        hpMax: $overrides['hpMax'] ?? ($overrides['hp'] ?? 5),
        type: $overrides['type'] ?? 'creature',
        move: 1,
        moveMax: 1,
        strikeWeak: $overrides['strikeWeak'] ?? 1,
        strikeMedium: $overrides['strikeMedium'] ?? 2,
        strikeStrong: $overrides['strikeStrong'] ?? 3,
        element: $overrides['element'] ?? '',
        prop: $overrides['prop'] ?? [],
        flags: $overrides['flags'] ?? [],
    );
}

function cipState(CardInstance ...$cards): GameState
{
    $state = new GameState(9101, 101, 202);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => GameState::PLAYER_HOST,
        'strike' => [
            'attacker_id' => 1,
            'target_id' => 2,
            'defender_id' => null,
            'state' => 'waiting_instant',
            'attack_dice' => 2,
            'defend_dice' => 0,
            'attack_mod' => 0,
            'defend_mod' => 0,
            'result' => ['attack' => 'weak', 'defend' => '', 'winner' => 'attack'],
            'confirmed' => [],
            'instant_phase' => 'combat',
            'instant_priority' => GameState::PLAYER_HOST,
            'instant_passed' => [],
            'instant_next_sequence' => 0,
            'instant_stack' => [],
        ],
    ];

    foreach ($cards as $card) {
        $state->addCard($card);
    }

    return $state;
}

function cipBaseState(int $attackerHp = 5): array
{
    $attacker = cipCard(['instanceId' => 1, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3, 'hp' => $attackerHp]);
    $target = cipCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]);
    $mary = cipCard(['instanceId' => 3, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
    $catcher = cipCard(['instanceId' => 4, 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 4]);
    return [cipState($attacker, $target, $mary, $catcher), $attacker, $target, $mary, $catcher];
}

function cipMaryItem(CardInstance $mary, int $sequence): array
{
    return [
        'card_id' => $mary->instanceId,
        'effect' => ['type' => 'damage_on_dice', 'value' => 1, 'damage' => 2],
        'target_id' => $mary->instanceId,
        'player' => GameState::PLAYER_PLAYER,
        'label' => 'Черная метка',
        'phase' => 'dice',
        'sequence' => $sequence,
    ];
}

function cipCatcherItem(CardInstance $catcher, int $sequence): array
{
    return [
        'card_id' => $catcher->instanceId,
        'effect' => ['type' => 'dice_choice'],
        'target_id' => $catcher->instanceId,
        'player' => GameState::PLAYER_PLAYER,
        'label' => 'Удача',
        'phase' => 'dice',
        'sequence' => $sequence,
    ];
}

function cipResolveChoice(GameState $state, string $choice): void
{
    $result = (new InstantProcessor($state, new Engine()))->applyDiceChoice(GameState::PLAYER_PLAYER, $choice);
    cipAssert($result->success, $result->error ?? 'Dice choice should resolve.');
    cipAssert(empty($state->battle['pending_dice_choice']), 'Dice pending should close after choice.');
    cipAssert(empty($state->battle['strike']['instant_resolution'] ?? []), 'Resolution queue should be exhausted after choice.');
}

// Phase ordering: dice must resolve before power even when power was ordered later.
[$state, $attacker, $target, $mary, $catcher] = cipBaseState();
$ost = cipCard(['instanceId' => 5, 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 3]);
$state->addCard($ost);
$state->battle['strike']['instant_stack'] = [
    cipMaryItem($mary, 0),
    [
        'card_id' => $ost->instanceId,
        'effect' => ['type' => 'strike_level', 'mode' => 'set', 'value' => 'strong'],
        'target_id' => $ost->instanceId,
        'player' => GameState::PLAYER_HOST,
        'label' => 'Дар силы',
        'phase' => 'power',
        'sequence' => 1,
    ],
];
(new InstantProcessor($state, new Engine()))->resolveStack();
$summary = $state->battle['strike']['instant_summary'] ?? [];
cipAssert(($summary[0]['phase'] ?? null) === 'dice', 'Dice phase should resolve before power despite global LIFO.');
cipAssert(($summary[1]['phase'] ?? null) === 'power', 'Power phase should resolve after dice.');
cipAssert(($state->battle['strike']['result']['attack'] ?? null) === 'strong', 'Power instant should still apply.');

// Same-phase LIFO: later sequence in the same phase resolves first.
[$state, $attacker, $target, $mary, $catcher] = cipBaseState();
$powerA = cipCard(['instanceId' => 6, 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 2]);
$powerB = cipCard(['instanceId' => 7, 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 4]);
$state->addCard($powerA);
$state->addCard($powerB);
$state->battle['strike']['instant_stack'] = [
    [
        'card_id' => $powerA->instanceId,
        'effect' => ['type' => 'strike_level', 'mode' => 'set', 'value' => 'medium'],
        'target_id' => $powerA->instanceId,
        'player' => GameState::PLAYER_HOST,
        'label' => 'Power A',
        'phase' => 'power',
        'sequence' => 0,
    ],
    [
        'card_id' => $powerB->instanceId,
        'effect' => ['type' => 'strike_level', 'mode' => 'set', 'value' => 'strong'],
        'target_id' => $powerB->instanceId,
        'player' => GameState::PLAYER_HOST,
        'label' => 'Power B',
        'phase' => 'power',
        'sequence' => 1,
    ],
];
(new InstantProcessor($state, new Engine()))->resolveStack();
$summary = $state->battle['strike']['instant_summary'] ?? [];
cipAssert(($summary[0]['label'] ?? '') === 'Power B', 'Same-phase LIFO should resolve latest sequence first.');
cipAssert(($summary[1]['label'] ?? '') === 'Power A', 'Same-phase LIFO should then resolve older sequence.');

// Mary -> Catcher order means Catcher resolves first, then Mary sees the changed die.
[$state, $attacker, $target, $mary, $catcher] = cipBaseState();
$state->battle['strike']['instant_stack'] = [
    cipMaryItem($mary, 0),
    cipCatcherItem($catcher, 1),
];
(new InstantProcessor($state, new Engine()))->resolveStack();
cipAssert(!empty($state->battle['pending_dice_choice']), 'Catcher should pause Mary -> Catcher order.');
cipAssert(count($state->battle['strike']['instant_resolution']['queue'] ?? []) === 2, 'Paused queue should keep Catcher and Mary.');
cipResolveChoice($state, 'minus:enemy');
cipAssert($attacker->hp === 3, 'Mary should damage attacker after Catcher changes attack die to 1.');
cipAssert(count($state->battle['strike']['instant_summary'] ?? []) === 2, 'Both dice items should resolve exactly once.');

// Catcher -> Mary order means Mary resolves before Catcher; the later dice choice must not replay Mary.
[$state, $attacker, $target, $mary, $catcher] = cipBaseState();
$state->battle['strike']['instant_stack'] = [
    cipCatcherItem($catcher, 0),
    cipMaryItem($mary, 1),
];
(new InstantProcessor($state, new Engine()))->resolveStack();
cipAssert(!empty($state->battle['pending_dice_choice']), 'Catcher should pause after Mary in Catcher -> Mary order.');
cipAssert($attacker->hp === 5, 'Mary should not hit before Catcher changes the die.');
cipAssert(count($state->battle['strike']['instant_resolution']['queue'] ?? []) === 1, 'Only Catcher should remain pending.');
cipResolveChoice($state, 'minus:enemy');
cipAssert($attacker->hp === 5, 'Mary must not be replayed after Catcher resolves.');
cipAssert(count($state->battle['strike']['instant_summary'] ?? []) === 2, 'Mary and Catcher should each resolve once.');

// Catcher choices: plus, minus, reroll all pause safely and finish without losing the queue.
foreach ([
    'plus:enemy' => static fn(GameState $state): bool => (int) $state->battle['strike']['attack_dice'] === 3,
    'minus:enemy' => static fn(GameState $state): bool => (int) $state->battle['strike']['attack_dice'] === 1,
    'reroll:any' => static fn(GameState $state): bool => (int) $state->battle['strike']['attack_dice'] >= 1
        && (int) $state->battle['strike']['attack_dice'] <= 6,
] as $choice => $assertion) {
    [$state, $attacker, $target, $mary, $catcher] = cipBaseState();
    $state->battle['strike']['instant_stack'] = [cipCatcherItem($catcher, 0)];
    (new InstantProcessor($state, new Engine()))->resolveStack();
    cipAssert(!empty($state->battle['pending_dice_choice']), "{$choice}: choice should pause resolution.");
    cipAssert(count($state->battle['strike']['instant_resolution']['queue'] ?? []) === 1, "{$choice}: queue should keep current item.");
    cipResolveChoice($state, $choice);
    cipAssert($assertion($state), "{$choice}: dice result should be applied.");
    cipAssert(count($state->battle['strike']['instant_summary'] ?? []) === 1, "{$choice}: item should resolve once.");
}

// Valid redirect: defender-side Mage redirects from current defender card to adjacent ally.
$attacker = cipCard(['instanceId' => 1, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$target = cipCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]);
$ally = cipCard(['instanceId' => 8, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$mage = cipCard(['instanceId' => 9, 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 4]);
$state = cipState($attacker, $target, $ally, $mage);
$state->battle['strike']['instant_stack'] = [[
    'card_id' => $mage->instanceId,
    'effect' => ['type' => 'redirect_strike'],
    'target_id' => $ally->instanceId,
    'player' => GameState::PLAYER_PLAYER,
    'label' => 'Магический трюк',
    'phase' => 'redirect',
    'sequence' => 0,
]];
(new InstantProcessor($state, new Engine()))->resolveStack();
cipAssert((int) $state->battle['strike']['target_id'] === $ally->instanceId, 'Valid redirect should update strike target.');
cipAssert(($state->battle['strike']['instant_summary'][0]['applied'] ?? false) === true, 'Valid redirect should apply.');

// No adjacent target: Mage should not be listed as playable.
$mage->closed = false;
$mage->flags = [];
$mage->prop = ['instants' => [[
    'key' => 'magic_trick',
    'name' => 'Магический трюк',
    'effect' => ['type' => 'redirect_strike'],
    'target' => 'adjacent_ally',
    'trigger' => 'combat',
    'phase' => 'redirect',
]]];
$farAlly = cipCard(['instanceId' => 10, 'owner' => GameState::PLAYER_PLAYER, 'row' => 6, 'col' => 5]);
$state = cipState($attacker, $target, $mage, $farAlly);
$instants = (new InstantProcessor($state, new Engine()))->getInstants(GameState::PLAYER_PLAYER, 'combat', 'combat');
cipAssert(empty($instants), 'Mage redirect should not be available without adjacent ally target.');

// Attacker-side Mage must not be available against an opponent strike target, even with an attacker-owned adjacent card.
$hostMage = cipCard([
    'instanceId' => 17,
    'owner' => GameState::PLAYER_HOST,
    'row' => 5,
    'col' => 4,
    'prop' => ['instants' => [[
        'key' => 'magic_trick',
        'name' => 'Магический трюк',
        'effect' => ['type' => 'redirect_strike'],
        'target' => 'adjacent_ally',
        'trigger' => 'combat',
        'phase' => 'redirect',
    ]]],
]);
$hostAdjacent = cipCard(['instanceId' => 18, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 4]);
$state = cipState($attacker, $target, $hostMage, $hostAdjacent);
$state->battle['strike']['instant_priority'] = GameState::PLAYER_HOST;
$instants = (new InstantProcessor($state, new Engine()))->getInstants(GameState::PLAYER_HOST, 'combat', 'combat');
cipAssert(empty($instants), 'Attacker-side Mage must not be available when origin strike target belongs to opponent.');
$result = (new InstantProcessor($state, new Engine()))->playCombat(
    GameState::PLAYER_HOST,
    $hostMage->instanceId,
    'magic_trick'
);
cipAssert(!$result->success, 'Direct playCombat should reject illegal attacker-side Mage redirect.');
cipAssert(empty($state->battle['pending_combat_pick']), 'Illegal direct Mage play must not open pending target pick.');
cipAssert(empty($state->battle['strike']['instant_stack']), 'Illegal direct Mage play must not mutate instant stack.');

// Invalid redirect: attacker cannot redirect opponent target to attacker-owned card.
$ownCard = cipCard(['instanceId' => 11, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 4]);
$state = cipState($attacker, $target, $ownCard);
$reason = (new Engine())->applyCombatEffect(
    $state,
    ['type' => 'redirect_strike'],
    $attacker,
    GameState::PLAYER_HOST,
    null,
    $ownCard
);
cipAssert($reason !== null, 'Attacker-side redirect from opponent target to own card must be rejected.');
cipAssert((int) $state->battle['strike']['target_id'] === $target->instanceId, 'Invalid redirect must not change target.');

// Invalid redirect: new target must belong to same owner as current target.
$enemyOfDefender = cipCard(['instanceId' => 12, 'owner' => GameState::PLAYER_HOST, 'row' => 4, 'col' => 4]);
$state = cipState($attacker, $target, $enemyOfDefender, $mage);
$reason = (new Engine())->applyCombatEffect(
    $state,
    ['type' => 'redirect_strike'],
    $mage,
    GameState::PLAYER_PLAYER,
    null,
    $enemyOfDefender
);
cipAssert($reason !== null, 'Redirect to another owner should be rejected.');

// Redirect + setter: Glorm ordered before redirect becomes no-op after redirect changes the target.
$attacker = cipCard(['instanceId' => 1, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$target = cipCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]);
$ally = cipCard(['instanceId' => 8, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$mage = cipCard(['instanceId' => 9, 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 4]);
$glorm = cipCard(['instanceId' => 13, 'owner' => GameState::PLAYER_PLAYER, 'row' => 5, 'col' => 5]);
$state = cipState($attacker, $target, $ally, $mage, $glorm);
$state->battle['strike']['instant_stack'] = [
    [
        'card_id' => $glorm->instanceId,
        'effect' => ['type' => 'damage_cap', 'value' => 1, 'self_wound' => 1, 'except_element' => 'plains'],
        'target_id' => $target->instanceId,
        'player' => GameState::PLAYER_PLAYER,
        'label' => 'Отвлекающая вспышка',
        'phase' => 'setter',
        'sequence' => 0,
    ],
    [
        'card_id' => $mage->instanceId,
        'effect' => ['type' => 'redirect_strike'],
        'target_id' => $ally->instanceId,
        'player' => GameState::PLAYER_PLAYER,
        'label' => 'Магический трюк',
        'phase' => 'redirect',
        'sequence' => 1,
    ],
];
(new InstantProcessor($state, new Engine()))->resolveStack();
$summary = $state->battle['strike']['instant_summary'] ?? [];
cipAssert(($summary[1]['label'] ?? '') === 'Отвлекающая вспышка', 'Glorm should still reach setter after redirect.');
cipAssert(($summary[1]['applied'] ?? true) === false, 'Glorm should no-op when ordered target is no longer strike target.');
cipAssert((int) $state->battle['strike']['target_id'] === $ally->instanceId, 'Glorm no-op must not pick a new target.');

// Hermit without wounds resolves without effect and closes the source.
$attacker = cipCard(['instanceId' => 1, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$target = cipCard(['instanceId' => 2, 'owner' => GameState::PLAYER_PLAYER, 'row' => 3, 'col' => 4]);
$hermit = cipCard(['instanceId' => 14, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 5]);
$state = cipState($attacker, $target, $hermit);
$state->battle['strike']['damage_applied'] = true;
$state->battle['strike']['pending_wounds_resolution'] = true;
$state->battle['strike']['instant_stack'] = [[
    'card_id' => $hermit->instanceId,
    'effect' => ['type' => 'redistribute_wounds'],
    'target_id' => $hermit->instanceId,
    'player' => GameState::PLAYER_PLAYER,
    'label' => 'Перераспределение ран',
    'phase' => 'wounds',
    'sequence' => 0,
]];
(new InstantProcessor($state, new Engine()))->resumeCombatWounds();
cipAssert(($state->battle['strike']['instant_summary'][0]['applied'] ?? true) === false, 'Hermit should no-op when there are no wounds.');
cipAssert($hermit->closed, 'No-op Hermit should still close source.');
cipAssert(empty($state->battle['strike']['instant_resolution'] ?? []), 'No-op Hermit should finish resolution.');

// Hermit with wounds opens transfer, finish removes current item once and resumes the remaining queue.
$attacker = cipCard(['instanceId' => 1, 'owner' => GameState::PLAYER_HOST, 'row' => 3, 'col' => 3]);
$target = cipCard([
    'instanceId' => 2,
    'owner' => GameState::PLAYER_PLAYER,
    'row' => 3,
    'col' => 4,
    'hp' => 3,
    'hpMax' => 5,
    'flags' => ['damage_taken_this_strike' => 2],
]);
$recipient = cipCard(['instanceId' => 15, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 4]);
$hermit = cipCard(['instanceId' => 14, 'owner' => GameState::PLAYER_PLAYER, 'row' => 4, 'col' => 5]);
$latePower = cipCard(['instanceId' => 16, 'owner' => GameState::PLAYER_HOST, 'row' => 2, 'col' => 3]);
$state = cipState($attacker, $target, $recipient, $hermit, $latePower);
$state->battle['strike']['damage_applied'] = true;
$state->battle['strike']['pending_wounds_resolution'] = true;
$state->battle['strike']['instant_stack'] = [
    [
        'card_id' => $hermit->instanceId,
        'effect' => ['type' => 'redistribute_wounds'],
        'target_id' => $hermit->instanceId,
        'player' => GameState::PLAYER_PLAYER,
        'label' => 'Перераспределение ран',
        'phase' => 'wounds',
        'sequence' => 1,
    ],
    [
        'card_id' => $latePower->instanceId,
        'effect' => ['type' => 'strike_level', 'mode' => 'set', 'value' => 'strong'],
        'target_id' => $latePower->instanceId,
        'player' => GameState::PLAYER_HOST,
        'label' => 'Late power should be ignored in wounds-only resume',
        'phase' => 'power',
        'sequence' => 0,
    ],
];
(new InstantProcessor($state, new Engine()))->resumeCombatWounds();
cipAssert(!empty($state->battle['pending_wound_transfer']), 'Hermit with wounds should open transfer pending.');
cipAssert(count($state->battle['strike']['instant_resolution']['queue'] ?? []) === 1, 'Hermit item should stay current while transfer is pending.');
$result = (new WoundTransferProcessor($state, new Engine()))->chooseSource(
    GameState::PLAYER_PLAYER,
    new Command('wt_source', ['donor_id' => $target->instanceId])
);
cipAssert($result->success, $result->error ?? 'Hermit donor should be selectable.');
$result = (new WoundTransferProcessor($state, new Engine()))->chooseAmount(
    GameState::PLAYER_PLAYER,
    new Command('wt_amount', ['amount' => 1])
);
cipAssert($result->success, $result->error ?? 'Hermit amount should be selectable.');
$result = (new WoundTransferProcessor($state, new Engine()))->chooseTarget(
    GameState::PLAYER_PLAYER,
    new Command('wt_target', ['target_id' => $recipient->instanceId])
);
cipAssert($result->success, $result->error ?? 'Hermit recipient should be selectable.');
$result = (new WoundTransferProcessor($state, new Engine()))->chooseTargetAmount(
    GameState::PLAYER_PLAYER,
    new Command('wt_target_amount', ['amount' => 1])
);
cipAssert($result->success, $result->error ?? 'Hermit transfer should finish.');
cipAssert(empty($state->battle['pending_wound_transfer']), 'Hermit transfer pending should close.');
cipAssert($hermit->closed, 'Hermit source should close after transfer.');
cipAssert(count($state->battle['strike']['instant_summary'] ?? []) === 1, 'Hermit item should be removed exactly once.');
cipAssert(empty($state->battle['strike']['instant_resolution'] ?? []), 'Hermit transfer should finish wounds resolution.');

echo "Combat instant phase tests passed.\n";
