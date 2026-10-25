<?php
declare(strict_types=1);
require __DIR__ . '/test_effective_features.php';

use Berserk\Core\{ActionResolver, CardInstance, Command, DamageResolver, DegascaProcessor, Dice,
    EffectiveFeatures, Engine, GameState, IncarnationTokenProcessor, InstantProcessor,
    StrikeResolver, TurnPhaseProcessor, WoundTransferProcessor, ZoneManager};

$continuationChecksBefore = $checks;
function fcMode(GameState $s, string $mode, int $target = 2): GameState {
    if ($mode !== 'normal') {
        efCheck(EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $s->getCard($target)), 'apply ' . $mode);
        if ($mode === 'released') (new ZoneManager($s))->toHand($s->getCard(1));
    }
    return efRoundtrip($s);
}
function fcCommand(GameState $s, string $owner, string $type, array $payload = []): \Berserk\Core\Result {
    return (new Engine())->apply($s, $owner, new Command($type, $payload));
}
function fcStrike(): array {
    return ['attacker_id' => 2, 'target_id' => 3, 'defender_id' => null, 'state' => 'results',
        'attack_dice' => 6, 'defend_dice' => 0, 'attack_mod' => 0, 'defend_mod' => 0,
        'result' => ['attack' => 'strong', 'defend' => '', 'winner' => 'attack'],
        'final' => ['attack' => 'strong', 'defend' => '', 'decreased' => false],
        'damage_applied' => true, 'confirmed' => []];
}
function fcSettled(GameState $s, string $mode, string $owner, string $type, array $payload = []): void {
    $before = [$s->getCard(3)->hp, $s->getCard(3)->markers, $s->getCard(2)->coins];
    if ($mode === 'normal') {
        EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $s->getCard(2));
        efCheck([$s->getCard(3)->hp, $s->getCard(3)->markers, $s->getCard(2)->coins] === $before, 'completed effect is not undone');
    } elseif ($mode === 'suppressed') {
        (new ZoneManager($s))->toHand($s->getCard(1)); $s = efRoundtrip($s);
        efCheck(!fcCommand($s, $owner, $type, $payload)->success, 'release does not revive a consumed choice');
        efCheck([$s->getCard(3)->hp, $s->getCard(3)->markers, $s->getCard(2)->coins] === $before, 'post-release retry has no effect or cost');
    }
}

foreach (['normal', 'suppressed', 'released'] as $mode) {
    $suppressed = $mode === 'suppressed';
    // 1. Death reaction was prepared before suppression. Dying is still field.
    $s = efState(); $c = $s->getCard(2); $c->coins = 3; $c->closed = true;
    $c->prop = ['on_death' => [['target' => 'choice', 'type' => 'damage', 'value' => 2]]];
    $s->battle['strike'] = fcStrike();
    (new DamageResolver($s))->forceDeath($c, 'strike', $s->getCard(3));
    efCheck(isset($s->battle['strike']['pending_choice']), 'prepared real death choice');
    $s = fcMode($s, $mode);
    efCheck(fcCommand($s, 'host', 'choose_death_target', ['target_id' => 3])->success, 'death choice ' . $mode);
    efCheck($s->getCard(3)->hp === ($suppressed ? 20 : 18), 'death effect ' . $mode);
    efCheck($s->getCard(2)->coins === 3 && $s->getCard(2)->closed && $s->getCard(2)->dying, 'death costs and boundary retained');
    efCheck(!isset($s->battle['strike']['pending_choice']) && $s->battle['strike']['state'] === 'results', 'death ACK frame retained');
    $hp = $s->getCard(3)->hp;
    efCheck(!fcCommand($s, 'host', 'choose_death_target', ['target_id' => 3])->success && $s->getCard(3)->hp === $hp, 'death retry no effect');
    fcSettled($s, $mode, 'host', 'choose_death_target', ['target_id' => 3]);

    // 2a. Incarnation-token reaction owns its own source, not the parent effect.
    $s = efState(); $s->getCard(2)->prop = ['on_incarnation_token' => ['value' => 2]];
    $s->getCard(2)->coins = 3; $s->getCard(2)->closed = true;
    (new IncarnationTokenProcessor($s, new Engine()))->onTokenGranted($s->getCard(2), 1);
    efCheck(isset($s->battle['pending_incarnation_wound']), 'prepared real token reaction');
    $s = fcMode($s, $mode);
    efCheck(fcCommand($s, 'host', 'choose_incarnation_wound', ['target_ids' => [3]])->success, 'incarnation choice ' . $mode);
    efCheck($s->getCard(3)->hp === ($suppressed ? 20 : 18), 'token reaction ' . $mode);
    efCheck($s->getCard(2)->coins === 3 && $s->getCard(2)->closed, 'token reaction resources retained');
    efCheck(empty($s->battle['pending_incarnation_wound']), 'token queue consumed once');
    $hp = $s->getCard(3)->hp;
    efCheck(!fcCommand($s, 'host', 'choose_incarnation_wound', ['target_ids' => [3]])->success && $hp === $s->getCard(3)->hp, 'token retry no effect');
    fcSettled($s, $mode, 'host', 'choose_incarnation_wound', ['target_ids' => [3]]);

    // 2b. Fixed attack cost precedes the defender's coin choice. Do not refund
    // it, pay a new defensive cost, or spend variable attack coins on suppression.
    $s = efState(); $s->getCard(2)->coins = 3;
    $s->getCard(2)->prop = ['actions' => [['key' => 'shot', 'type' => 'shot', 'value' => 3,
        'range' => 6, 'near_shot' => true, 'coins' => 1]]];
    $s->getCard(3)->coins = 1;
    $s->getCard(3)->prop = ['coin_block' => ['types' => ['shot'], 'cost' => 1, 'defense' => 'zoa']];
    efCheck(fcCommand($s, 'host', 'action', ['card_id' => 2, 'target_id' => 3, 'action_key' => 'shot'])->success, 'attack declaration');
    efCheck(isset($s->battle['pending_coin_ability']) && $s->getCard(2)->coins === 2, 'attack fixed cost before pending');
    $s = fcMode($s, $mode); $id = $s->battle['pending_coin_ability']['id'];
    $choice = $suppressed ? 'yes' : 'no';
    efCheck(fcCommand($s, 'player', 'choose_coin_ability', ['choice_id' => $id, 'choice' => $choice])->success, 'coin continuation ' . $mode);
    efCheck($s->getCard(3)->hp === ($suppressed ? 20 : 17), 'attack effect ' . $mode);
    efCheck($s->getCard(2)->coins === 2 && $s->getCard(3)->coins === 1, 'no refund or second cost');
    efCheck($s->getCard(2)->closed === !$suppressed && empty($s->battle['pending_coin_ability']), 'attack tap and continuation consumed');
    $hp = $s->getCard(3)->hp;
    efCheck(!fcCommand($s, 'player', 'choose_coin_ability', ['choice_id' => $id, 'choice' => 'no'])->success && $hp === $s->getCard(3)->hp, 'coin retry no effect');
    fcSettled($s, $mode, 'player', 'choose_coin_ability', ['choice_id' => $id, 'choice' => 'no']);

    // 3. Delayed row spell: real activation and row selection, then serialized task.
    $s = efState(); $s->getCard(2)->coins = 3;
    $s->getCard(2)->prop = ['actions' => [['key' => 'runes', 'type' => 'row_spell', 'coins' => 1,
        'strike' => ['weak' => 1, 'medium' => 2, 'strong' => 3]]]];
    efCheck(fcCommand($s, 'host', 'action', ['card_id' => 2, 'action_key' => 'runes'])->success, 'schedule row spell');
    efCheck(fcCommand($s, 'host', 'choose_row', ['row' => 3])->success, 'row selected');
    $s->battle['turn_phase'] = ['phase' => 'start', 'side' => 'active', 'active_key' => 'host', 'passive_key' => 'player',
        'active_queue' => [['id' => 'runes', 'type' => 'row_spell', 'card_id' => 2]], 'passive_queue' => [], 'sub' => null, 'pending_ack' => null];
    $s = fcMode($s, $mode);
    efCheck(fcCommand($s, 'host', 'turn_task', ['task_id' => 'runes'])->success, 'execute scheduled task ' . $mode);
    efCheck(!isset($s->getCard(2)->flags['row_spell_pending']), 'schedule consumed');
    if ($suppressed) {
        efCheck(empty($s->battle['pending_row_spell_pick']) && !empty($s->battle['turn_phase']['pending_ack']), 'suppressed schedule returns to technical ACK');
        efCheck(fcCommand($s, 'host', 'turn_ack')->success, 'suppressed task ACK');
    } else {
        $val = $s->battle['pending_row_spell_pick']['val'];
        $s = efRoundtrip($s);
        efCheck(fcCommand($s, 'host', 'choose_row_spell_targets', ['target_ids' => [3]])->success, 'row target after reload');
        efCheck($s->getCard(3)->hp === 20 - $val, 'row spell damage once');
    }
    efCheck($s->getCard(2)->coins === 2 && $s->getCard(2)->closed, 'scheduled spell costs retained');
    $hp = $s->getCard(3)->hp;
    efCheck(!fcCommand($s, 'host', 'choose_row_spell_targets', ['target_ids' => [3]])->success && $hp === $s->getCard(3)->hp, 'row retry no effect');
    fcSettled($s, $mode, 'host', 'choose_row_spell_targets', ['target_ids' => [3]]);

    // 4. Degasca learns only prop-driven strike_effects. Both handoff styles
    // must resume their saved sequence even if the source is suppressed.
    foreach (['chain', 'talion'] as $kind) {
        $s = efState(); $c = $s->getCard(2); $c->coins = 3; $c->closed = true;
        $c->prop = ['strike_effects' => [['levels' => ['strong'], 'type' => 'degasca_sequence']]];
        $effect = $kind === 'chain' ? ['levels' => ['strong'], 'chain_damage' => ['value' => 1, 'max_targets' => 1, 'filter' => 'enemy']]
            : ['levels' => ['strong'], 'type' => 'incarnation_token_graveyard'];
        $c->flags['degasca_learned_effects'] = [['ukid' => 'arbitrary-learned-card', 'effects' => [$effect,
            ['levels' => ['strong'], 'marker' => ['type' => 'stun', 'expire' => 1]]]]];
        foreach ([5, 6] as $id) $s->addCard(new CardInstance(instanceId: $id, ukid: 'candidate_' . $id,
            owner: $kind === 'chain' ? 'player' : 'host', zone: $kind === 'chain' ? 'field' : 'graveyard', row: 4, col: $id === 5 ? 4 : 2,
            hp: $kind === 'chain' ? 20 : 0, hpMax: 20, prop: $kind === 'talion' ? ['incarnation' => 3] : []));
        $s->battle['strike'] = fcStrike();
        $s->getCard(3)->hp = 17; // The completed base strike must never be undone.
        (new DegascaProcessor($s, new Engine()))->openStrongStrikeSequence($c, $s->getCard(3), 'strong', 3);
        $key = $kind === 'chain' ? 'pending_anubisar_chain' : 'pending_talion_incarnation';
        efCheck(isset($s->battle[$key]['degasca_sequence']), 'nested sequence handed off ' . $kind);
        $s = fcMode($s, $mode);
        $cmd = $kind === 'chain' ? 'choose_anubisar_chain' : 'choose_talion_incarnation';
        $payload = $kind === 'chain' ? ['target_ids' => [5]] : ['target_id' => 5];
        efCheck(fcCommand($s, 'host', $cmd, $payload)->success, 'nested choice ' . $kind . '/' . $mode);
        efCheck(empty($s->battle[$key]) && empty($s->battle['pending_degasca_sequence']), 'nested sequence drains ' . $kind);
        efCheck($s->getCard(3)->hp === 17 && isset($s->getCard(3)->markers['stun']) === !$suppressed, 'completed hit retained, next learned effect controlled');
        efCheck($kind === 'chain' ? $s->getCard(5)->hp === ($suppressed ? 20 : 19)
            : (int) ($s->getCard(5)->markers['incarnation']['value'] ?? 0) === ($suppressed ? 0 : 1), 'nested effect ' . $kind);
        efCheck($s->getCard(2)->coins === 3 && $s->getCard(2)->closed, 'nested resources retained');
        efCheck(!fcCommand($s, 'host', $cmd, $payload)->success, 'nested retry unavailable');
        fcSettled($s, $mode, 'host', $cmd, $payload);
    }

    // 5. Turn instant pays once at declaration. A fizzle follows existing tap
    // completion, retains the paid coin, and still produces its technical ACK.
    $s = efState(); $c = $s->getCard(2); $c->hp = 10; $c->coins = 3;
    $c->prop = ['instants' => [['key' => 'heal', 'name' => 'Fixture heal', 'trigger' => 'turn',
        'target' => 'self', 'coins' => 1, 'effect' => ['type' => 'heal', 'value' => 2]]]];
    $ip = new InstantProcessor($s, new Engine());
    efCheck($ip->openTurnStackWindow('host', ['type' => 'manual']), 'turn instant window');
    efCheck(fcCommand($s, 'host', 'play_turn_instant', ['card_id' => 2, 'instant_key' => 'heal'])->success, 'declare paid instant');
    efCheck($c->coins === 2 && !$c->closed, 'coin paid before effect; tap still scheduled');
    $s = fcMode($s, $mode);
    efCheck(fcCommand($s, 'host', 'pass_turn_instant')->success, 'resolve instant ' . $mode);
    efCheck($s->getCard(2)->hp === ($suppressed ? 10 : 12) && $s->getCard(2)->coins === 2 && $s->getCard(2)->closed, 'instant effect and scheduled tap');
    efCheck($s->battle['turn_instant_result']['summary'][0]['applied'] === !$suppressed, 'honest instant summary');
    $s = efRoundtrip($s);
    efCheck(fcCommand($s, 'host', 'turn_instant_result_ok')->success, 'instant result ACK');
    efCheck(!fcCommand($s, 'host', 'turn_instant_result_ok')->success && $s->getCard(2)->coins === 2, 'instant ACK retry no spend');
    fcSettled($s, $mode, 'host', 'turn_instant_result_ok');
}

// Suppression after row target selection was already opened, not just scheduling.
$s = efState(); $s->getCard(2)->coins = 2; $s->getCard(2)->closed = true;
$s->battle['pending_row_spell_pick'] = ['owner' => 'host', 'source_id' => 2, 'x' => 1, 'candidates' => [3, 4],
    'action' => ['type' => 'row_spell'], 'dice' => 6, 'level' => 'strong', 'val' => 3];
$s = fcMode($s, 'suppressed');
efCheck(fcCommand($s, 'host', 'choose_row_spell_targets', ['target_ids' => [3]])->success
    && $s->getCard(3)->hp === 20 && empty($s->battle['pending_row_spell_pick']), 'cached row pick suppressed');
efCheck($s->getCard(2)->coins === 2 && $s->getCard(2)->closed, 'cached row pick keeps prior costs');

// A suppressed wound-transfer does not run the refundable player-cancel path.
foreach (['source', 'amount', 'target', 'target_amount'] as $step) {
    $s = efState(); $s->getCard(2)->coins = 3; $s->getCard(3)->hp = 10;
    efCheck((new WoundTransferProcessor($s, new Engine()))->start('host', $s->getCard(2),
        ['kind' => 'fixture', 'coins_cost' => 1, 'on_finish' => 'main_phase', 'donor_filter' => 'wounded'])
        ->success === false, 'enemy is not an eligible donor');
    $s->getCard(1)->hp = 10;
    efCheck((new WoundTransferProcessor($s, new Engine()))->start('host', $s->getCard(2),
        ['kind' => 'fixture', 'coins_cost' => 1, 'on_finish' => 'main_phase', 'donor_filter' => 'wounded'])->success, 'paid wound transfer');
    $s->battle['pending_wound_transfer']['step'] = $step;
    $s = fcMode($s, 'suppressed');
    $method = ['source' => 'chooseSource', 'amount' => 'chooseAmount', 'target' => 'chooseTarget', 'target_amount' => 'chooseTargetAmount'][$step];
    efCheck((new WoundTransferProcessor($s, new Engine()))->$method('host', new Command('fixture'))->success, 'suppressed transfer ' . $step);
    efCheck($s->getCard(1)->hp === 10 && $s->getCard(2)->coins === 2 && $s->getCard(2)->closed
        && empty($s->battle['pending_wound_transfer']), 'no refund/open/transfer; pending drained');
}

// Paused combat dice choices retain the original no_close contract even though
// the current effective instant list is empty while suppressed.
foreach ([false, true] as $noClose) foreach (['normal', 'suppressed', 'released'] as $mode) {
    $s = efState(); $s->getCard(2)->coins = 2;
    $s->getCard(2)->prop = ['instants' => [['key' => 'luck', 'trigger' => 'combat', 'phase' => 'dice',
        'target' => 'self', 'no_close' => $noClose, 'effect' => ['type' => 'dice_choice']]]];
    $s->battle['strike'] = array_replace(fcStrike(), ['attacker_id' => 3, 'target_id' => 2,
        'state' => 'waiting_instant', 'matriarch_order' => true, 'instant_phase' => 'combat',
        'combat_instant_window' => 'dice', 'instant_priority' => 'host', 'instant_passed' => []]);
    efCheck(fcCommand($s, 'host', 'combat_instant_play', ['card_id' => 2, 'instant_key' => 'luck'])->success, 'combat instant declared');
    for ($i = 0; $i < 10 && empty($s->battle['pending_dice_choice']); $i++) {
        efCheck(fcCommand($s, $s->battle['strike']['instant_priority'], 'combat_instant_pass')->success, 'combat priority pass');
    }
    efCheck(isset($s->battle['pending_dice_choice']), 'dice choice paused before execution');
    $s = fcMode($s, $mode); $dice = $s->battle['strike']['defend_dice'];
    efCheck(fcCommand($s, 'host', 'choose_dice_choice', ['choice' => 'plus:own'])->success, 'dice continuation ' . $mode);
    efCheck($s->battle['strike']['defend_dice'] === $dice + ($mode === 'suppressed' ? 0 : 1), 'dice effect availability');
    efCheck($s->getCard(2)->coins === 2 && $s->getCard(2)->closed === !$noClose, 'original tap contract retained');
    efCheck(empty($s->battle['pending_dice_choice']) && $s->battle['strike']['state'] === 'results', 'dice returns to result ACK');
    efCheck(!fcCommand($s, 'host', 'choose_dice_choice', ['choice' => 'plus:own'])->success, 'dice retry unavailable');
}

// Paused wounds item: finish it once and process the next item through the
// existing queue; neither suppression nor reload may discard that neighbour.
foreach (['normal', 'suppressed', 'released'] as $mode) {
    $s = efState(); $s->getCard(1)->hp = 10; $s->getCard(2)->coins = 2;
    $s->getCard(2)->prop = ['instants' => [['key' => 'wt', 'trigger' => 'combat', 'phase' => 'wounds',
        'effect' => ['type' => 'redistribute_wounds']]]];
    $item = ['card_id' => 2, 'target_id' => 2, 'player' => 'host', 'instant_key' => 'wt',
        'phase' => 'wounds', 'sequence' => 0, 'label' => 'transfer', 'effect' => ['type' => 'redistribute_wounds']];
    $next = ['card_id' => 4, 'target_id' => 4, 'player' => 'player', 'phase' => 'wounds', 'sequence' => 1,
        'label' => 'neighbour', 'effect' => ['type' => 'damage_cap', 'cap' => 1]];
    $s->battle['strike'] = array_replace(fcStrike(), ['instant_phase' => 'combat', 'instant_stack' => [$item, $next],
        'instant_resolution' => ['mode' => 'wounds', 'queue' => [$item, $next]]]);
    $s->getCard(2)->flags['in_stack'] = true;
    efCheck((new WoundTransferProcessor($s, new Engine()))->start('host', $s->getCard(2),
        ['on_finish' => 'combat_resolution', 'donor_filter' => 'wounded', 'target_filter' => 'source', 'max_transfer' => 1])->success, 'wounds paused');
    $s = fcMode($s, $mode);
    // The released suppression source leaves the field and cannot be a donor;
    // use a separate wounded ally for that scenario.
    if ($mode === 'released') {
        (new ZoneManager($s))->toField($s->getCard(1), 2, 2);
    }
    efCheck((new WoundTransferProcessor($s, new Engine()))->chooseSource('host', new Command('wt_source', ['donor_id' => 1]))->success, 'wounds source after reload');
    if ($mode !== 'suppressed') efCheck((new WoundTransferProcessor($s, new Engine()))->chooseAmount('host', new Command('wt_amount', ['amount' => 1]))->success, 'wounds amount');
    efCheck($s->getCard(1)->hp === ($mode === 'suppressed' ? 10 : 11), 'suppressed wounds not transferred');
    efCheck($s->getCard(2)->coins === 2 && $s->getCard(2)->closed, 'wounds preserves paid coin and ordinary tap');
    efCheck(empty($s->battle['pending_wound_transfer']) && empty($s->battle['strike']['instant_resolution']), 'wounds queue drained');
    efCheck(count($s->battle['strike']['instant_summary']) === 2, 'neighbour item processed exactly once');
    efCheck(!((new WoundTransferProcessor($s, new Engine()))->chooseAmount('host', new Command('wt_amount', ['amount' => 1])))->success, 'wounds retry unavailable');
}

// Saved any-death/Nokami handoffs own independent reactions. Suppressing the
// parent does not cancel the already-created child; it stops the parent's next effect.
foreach (['any', 'nokami'] as $kind) foreach (['normal', 'suppressed', 'released'] as $mode) {
    $s = efState();
    $s->getCard(2)->prop = ['strike_effects' => [['levels' => ['strong'], 'type' => 'degasca_sequence']]];
    $s->addCard(new CardInstance(instanceId: 7, ukid: 'independent-reaction', owner: 'host', zone: 'field',
        row: 3, col: 4, hp: 20, hpMax: 20, prop: $kind === 'any' ? ['on_any_death' => [['side' => 'enemy']]] : ['on_poison_damage' => ['value' => 1]]));
    $key = $kind === 'any' ? 'pending_any_death' : 'pending_nokami_wound';
    $savedKey = $kind === 'any' ? DegascaProcessor::ANY_DEATH_CONTINUATION_KEY : DegascaProcessor::NOKAMI_WOUND_CONTINUATION_KEY;
    $s->battle[$key] = $kind === 'any' ? [['type' => 'poison_near', 'source_id' => 7, 'died_id' => 99,
        'died_ukid' => 'dead-fixture', 'candidates' => [3], 'poison_value' => 1]]
        : [['owner' => 'host', 'source_id' => 7, 'poisoned_ids' => [3], 'candidates' => [3], 'value' => 1]];
    DegascaProcessor::appendSavedContinuation($s, $savedKey, ['owner' => 'host', 'source_id' => 2,
        'activation' => 'strong_strike', 'stage' => 'effects', 'index' => 0,
        'effects' => [['ukid' => 'prop-only', 'effects' => [['levels' => ['strong'], 'marker' => ['type' => 'stun', 'expire' => 1]]]]],
        'context' => ['target_id' => 3, 'attack_level' => 'strong', 'wounds_dealt' => 1]]);
    $s = fcMode($s, $mode);
    $cmd = $kind === 'any' ? 'choose_any_death_target' : 'choose_nokami_wound';
    efCheck(fcCommand($s, 'host', $cmd, ['target_id' => 3])->success, 'independent nested reaction ' . $kind);
    efCheck($kind === 'any' ? isset($s->getCard(3)->markers['poison']) : $s->getCard(3)->hp === 19, 'independent child already initiated');
    efCheck(isset($s->getCard(3)->markers['stun']) === ($mode !== 'suppressed'), 'parent next effect suppressed ' . $kind);
    efCheck(empty($s->battle[$savedKey]) && empty($s->battle['pending_degasca_sequence']), 'saved continuation drained');
    efCheck(!fcCommand($s, 'host', $cmd, ['target_id' => 3])->success, 'saved reaction retry unavailable');
}

foreach (['normal', 'suppressed', 'released'] as $mode) {
    $s = efState(); $s->getCard(2)->prop = ['strike_effects' => [['levels' => ['strong'], 'type' => 'degasca_sequence']]];
    $s->addCard(new CardInstance(instanceId: 8, ukid: 'any-study-candidate', owner: 'host', zone: 'deck',
        prop: ['strike_effects' => [['levels' => ['strong'], 'marker' => ['type' => 'stun', 'expire' => 1]]]]));
    $s->battle['strike'] = fcStrike();
    (new DegascaProcessor($s, new Engine()))->openStrongStrikeSequence($s->getCard(2), $s->getCard(3), 'strong', 3);
    efCheck(($s->battle['pending_degasca_sequence']['stage'] ?? '') === 'choose_card', 'real prop study choice opened');
    $s = fcMode($s, $mode);
    efCheck(fcCommand($s, 'host', 'choose_degasca_card', ['card_id' => 8])->success, 'study completes technically even when suppressed');
    efCheck(!empty($s->getCard(2)->flags['degasca_learned_effects']) === ($mode !== 'suppressed'), 'study availability ' . $mode);
    efCheck(empty($s->battle['pending_degasca_sequence']), 'study pending consumed');
    efCheck(!fcCommand($s, 'host', 'choose_degasca_card', ['card_id' => 8])->success, 'study retry unavailable');
}

echo 'Effective feature continuations: ' . ($checks - $continuationChecksBefore) . " checks passed\n";
