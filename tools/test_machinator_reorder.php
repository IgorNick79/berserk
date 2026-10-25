<?php
declare(strict_types=1);
// Includes the Stage 2A infrastructure checks and their JSON roundtrip fixture.
require __DIR__ . '/test_effective_features.php';

use Berserk\Core\{CardInstance, Command, EffectiveFeatures, Engine, GameState, ProphecyProcessor};
use Berserk\Core\BattleHistory\Recorder;

$reorderChecksBefore = $checks;
function mrState(int $count = 3): GameState {
    $s = efState();
    $s->getCard(2)->prop = ['prophecy_reorder' => true];
    $s->getCard(3)->owner = 'host';
    $s->getCard(3)->prop = ['prophecy_reorder' => true];
    for ($id = 5; $id < 5 + $count; $id++) {
        $s->addCard(new CardInstance(instanceId: $id, ukid: 'deck_' . $id, owner: 'host', zone: 'deck', order: $id));
    }
    $p = new ProphecyProcessor($s, new Engine());
    $peek = $p->peek('host', $count);
    $p->commit('host', $s->getCard(1), $peek, 'block', 'Fixture prophecy', [
        ['cmd' => 'close_prophecy', 'label' => 'Continue'],
    ]);
    foreach ($peek['cards'] as $card) $card->order = null;
    return $s;
}
function mrStart(GameState $s): void {
    efCheck((new Engine())->apply($s, 'host', new Command('reorder_start'))->success, 'start reorder via Engine');
}
function mrPick(GameState $s, int $id, string $direction = 'up', string $owner = 'host'): \Berserk\Core\Result {
    return (new Engine())->apply($s, $owner, new Command('reorder_card_' . $direction, ['card_id' => $id]));
}

$s = mrState(); mrStart($s);
efCheck($s->battle['pending_prophecy']['source_id'] === 1, 'seeker id preserved');
efCheck($s->battle['pending_prophecy']['reorder_source_id'] === 2, 'actual machinator persisted');
$s = efRoundtrip($s);
efCheck($s->battle['pending_prophecy']['reorder_source_id'] === 2, 'origin survives JSON reload');
EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $s->getCard(3));
efCheck(mrPick($s, 5)->success && $s->getCard(5)->order === 0, 'suppression of another machinator does not block origin');
efCheck($s->battle['pending_prophecy']['reorder_remaining'] === [6, 7], 'only selected card consumed');
$top = $s->getCard(5)->order;
// Even with an otherwise available second machinator, use the recorded origin.
(new \Berserk\Core\ZoneManager($s))->toHand($s->getCard(3));
(new \Berserk\Core\ZoneManager($s))->toField($s->getCard(3), 4, 4);
EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $s->getCard(2));
$s = efRoundtrip($s);
$beforeWrongOwner = $s->toArray();
efCheck(!mrPick($s, 6, 'up', 'player')->success && $s->toArray() === $beforeWrongOwner, 'foreign player cannot finalize owner pending');
efCheck(mrPick($s, 6)->success, 'suppressed origin finalizes technically');
efCheck(!isset($s->battle['pending_prophecy']), 'prophecy pending finalized');
efCheck($s->getCard(5)->order === $top, 'previous top placement preserved');
foreach ($s->cards as $card) {
    if ($card->zone === 'deck' && $card->ukid !== 'deck_5') efCheck($card->order === null, 'no new top placement');
    efCheck(empty($card->flags['in_prophecy_pending']), 'technical pending flags cleaned');
}
$beforeReplay = $s->toArray();
efCheck(!mrPick($s, 6)->success && $s->toArray() === $beforeReplay, 'replay cannot rearrange after finalization');

// Legacy pending follows the real close_prophecy route exactly; do not infer origin.
$s = mrState(); mrStart($s); efCheck(mrPick($s, 5)->success, 'legacy fixture has an applied rearrangement');
unset($s->battle['pending_prophecy']['reorder_source_id']);
$s = efRoundtrip($s); $reference = efRoundtrip($s);
$s->battle['unrelated_sentinel'] = $reference->battle['unrelated_sentinel'] = ['value' => 17];
efCheck((new Engine())->apply($reference, 'host', new Command('close_prophecy'))->success, 'reference technical finalization');
efCheck(mrPick($s, 6)->success, 'legacy finalization succeeds');
efCheck($s->toArray() === $reference->toArray(), 'legacy output equals existing close route including control state');
efCheck($s->getCard(5)->order === 0, 'legacy keeps previously applied placement');
$snapshot = $s->toArray();
efCheck(!mrPick($s, 6)->success && $snapshot === $s->toArray(), 'legacy replay makes no changes');
efCheck(!(new Engine())->apply($s, 'host', new Command('reorder_start'))->success
    && $snapshot === $s->toArray(), 'legacy finalized prophecy cannot restart');

$s = mrState(); $s->getCard(2)->flags['prophecy_reorder_used_this_turn'] = true;
mrStart($s);
efCheck($s->battle['pending_prophecy']['reorder_source_id'] === 3, 'second eligible machinator is stored explicitly');
$s = mrState(); mrStart($s);
EffectiveFeatures::applyMemoryLoss($s, $s->getCard(3), $s->getCard(1));
efCheck(mrPick(efRoundtrip($s), 5)->success, 'suppressed seeker does not replace the actual reorder origin');
$s = mrState(); mrStart($s); efCheck(mrPick($s, 5, 'down')->success, 'applied bottom choice');
unset($s->battle['pending_prophecy']['reorder_source_id']);
efCheck(mrPick($s, 6)->success, 'legacy with previous bottom choice finalizes');
$bottomCards = array_values(array_filter($s->cards, static fn(CardInstance $c): bool => $c->ukid === 'deck_5'));
efCheck(count($bottomCards) === 1 && $bottomCards[0]->order === null, 'bottom placement preserved without duplicate instance');

// Normal completion and single-card completion retain the exact origin metadata.
$s = mrState(); mrStart($s);
foreach ([5, 6, 7] as $id) efCheck(mrPick($s, $id)->success, 'normal reorder card ' . $id);
efCheck($s->battle['pending_prophecy']['reorder_source_id'] === 2
    && !$s->battle['pending_prophecy']['reorder_mode'], 'normal reconstruction retains origin');
$s = mrState(1); mrStart($s);
efCheck($s->battle['pending_prophecy']['reorder_source_id'] === 2
    && !$s->battle['pending_prophecy']['reorder_mode'], 'single-card reconstruction retains origin');

// The legacy rule is restricted to active reorder mode, not ordinary prophecy.
$s = mrState(); $before = $s->toArray();
efCheck(!mrPick($s, 5)->success && $before === $s->toArray(), 'ordinary prophecy is not legacy reorder');

// Live journal observes the real incoming command and existing technical close;
// it must not invent an ability.applied event or an additional gameplay action.
$s = mrState(); mrStart($s);
unset($s->battle['pending_prophecy']['reorder_source_id']);
$s = efRoundtrip($s);
$catalog = [];
foreach ($s->cards as $card) $catalog[$card->ukid] = ['revision' => 'reorder-test', 'name' => $card->ukid,
    'image' => '', 'description' => '', 'type' => $card->type, 'element' => 'neutral', 'classes' => [], 'price' => 0];
$s->historyRecorder = new Recorder($s->gameId, 1, $catalog, [], true);
efCheck(mrPick($s, 6)->success, 'legacy finalization with live recorder succeeds');
$batch = $s->historyRecorder->batch($s)->data();
efCheck(!in_array('ability.applied', array_column($batch['events'], 'type'), true), 'no fictional ability application');
efCheck(array_diff(array_column($batch['actions'], 'type'), ['recording_gap']) === []
    && count($batch['captures']) === 0, 'no fictional gameplay action or replay capture');
// Existing recorder reports these commands as gaps. Preserve that honest result
// instead of fabricating coverage for the technical fallback.
efCheck(count($batch['actions']) === 1 && $batch['actions'][0]['type'] === 'recording_gap', 'existing journal gap classification preserved');
efCheck(!isset(efRoundtrip($s)->battle['pending_prophecy']), 'journal observer does not break serialized finalization');

$s = mrState(); mrStart($s);
EffectiveFeatures::applyMemoryLoss($s, $s->getCard(1), $s->getCard(2));
$s = efRoundtrip($s);
$s->historyRecorder = new Recorder($s->gameId, 2, $catalog, [], true);
efCheck(mrPick($s, 6)->success && !isset($s->battle['pending_prophecy']), 'suppressed origin finalizes with live journal');
$batch = $s->historyRecorder->batch($s)->data();
efCheck(!in_array('ability.applied', array_column($batch['events'], 'type'), true)
    && array_diff(array_column($batch['actions'], 'type'), ['recording_gap']) === [], 'suppressed source adds no invented gameplay event');

echo 'Machinator reorder: ' . ($checks - $reorderChecksBefore) . " checks passed\n";
