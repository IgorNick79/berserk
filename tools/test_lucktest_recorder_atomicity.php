<?php
declare(strict_types=1);

require __DIR__ . '/test_lucktest.php';

use Berserk\Core\{CardInstance, Command, Dice, Engine};
use Berserk\Core\BattleHistory\Recorder;

$recorderStart = $checks;
$state = fresh();
$catalog = ['army' => ['revision' => 'test', 'name' => 'Army', 'image' => 'test/army.jpg',
    'description' => 'fixture', 'type' => 'creature', 'element' => 'forests', 'classes' => ['Воин'], 'price' => 7]];
$state->historyRecorder = new Recorder(123, 19, $catalog, [], true);
$originalRecorder = $state->historyRecorder;
$recorderBefore = serialize($originalRecorder);
$before = $state->toArray();
$db = fixture([card('army', 7, 'creature', ['start' => ['type' => 'get_hp', 'hp' => PHP_INT_MAX]])]);
$engine = new Engine($db, fn() => 1800000000, fn($min, $max) => $min);
$command = new Command('confirm_settings', ['lucktest_card_count' => '3']);

$_SESSION['debug_roll'] = '6,1';
unset($_SESSION['debug_roll_state']);
Dice::init();
$offset = strlen(file_get_contents($diagnosticFile));
$failed = $engine->apply($state, 'host', $command);
$afterFailure = serialize($originalRecorder);
$stateAfterFailure = $state->toArray();
$log = substr(file_get_contents($diagnosticFile), $offset);

// Retry on the exact same source GameState, Engine and original Recorder binding.
// Only repair the synthetic catalog; no reload or replacement observer in the test.
$db->cards['army']['prop'] = json_encode(['start' => ['type' => 'get_hp', 'hp' => 2]], JSON_THROW_ON_ERROR);
$_SESSION['debug_roll'] = '6,1';
unset($_SESSION['debug_roll_state']);
Dice::init();
$retried = $engine->apply($state, 'host', $command);

if (in_array('--diagnose', $argv, true)) {
    echo json_encode(['first_success' => $failed->success, 'recorder_changed_after_failure' => $afterFailure !== $recorderBefore,
        'retry_success' => $retried->success, 'status' => $state->status,
        'retry_error' => $retried->error], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    exit;
}

check(!$failed->success && str_contains($log, 'TypeError') && str_contains($log, 'TurnProcessor.php'), 'native start ability failed after initial capture');
check($stateAfterFailure === $before, 'failed attempt leaves original GameState unchanged');
check($afterFailure === $recorderBefore, 'failed attempt must not mutate source Recorder');
check($retried->success && $state->status === 'battle', 'same-source retry reaches Battle: ' . ($retried->error ?? ''));
check($state->historyRecorder !== $originalRecorder && serialize($originalRecorder) === $recorderBefore, 'publish successful observer; original binding stays clean');
$batch = $state->historyRecorder->batch($state)->data();
$initial = array_values(array_filter($batch['captures'], fn($capture) => $capture['initial']));
check(count($initial) === 1 && count($batch['captures']) === 2, 'exactly one initial and one battle_ready capture');
check(array_column($batch['actions'], 'type') === ['initial', 'battle_ready'], 'no leftover action, gap or open scope from failed attempt');
$types = array_column($batch['events'], 'type');
foreach (['initial.started', 'initial.settled', 'battle_ready.started', 'battle_ready.settled', 'turn.started'] as $type) {
    check(count(array_keys($types, $type, true)) === 1, 'event emitted exactly once: ' . $type);
}
foreach ($initial[0]['state']['cards'] as $card) {
    check($card['hp_max'] === 8, 'initial remains before native start effects');
}
foreach (['host', 'player'] as $owner) {
    foreach ($state->getCardsInZone($owner, CardInstance::ZONE_FIELD) as $card) {
        check($card->hpMax === 10 && $card->hp === 10, 'successful start effect once, both owners');
    }
}
$cardsBefore = $state->toArray()['cards'];
check(!$engine->apply($state, 'host', $command)->success, 'completed Battle cannot restart');
// commandStarted updates the current observer's bookkeeping on normal rejected commands,
// but must not append actions/events/captures or repeat start effects.
check($state->historyRecorder->batch($state)->data() === $batch && $state->toArray()['cards'] === $cardsBefore, 'rejected repeat has no new history or effects');

// A non-exception Result rejection must also discard changes to observer command metadata.
$rejectedState = fresh();
$rejectedState->historyRecorder = new Recorder(123, 20, $catalog, []);
$rejectedRecorder = $rejectedState->historyRecorder;
$rejectedBefore = serialize($rejectedRecorder);
$result = $engine->apply($rejectedState, 'player', $command);
check(!$result->success && $rejectedState->historyRecorder === $rejectedRecorder
    && serialize($rejectedRecorder) === $rejectedBefore, 'domain rejection leaves source observer untouched');

unset($_SESSION['debug_roll'], $_SESSION['debug_roll_state']);
Dice::init();
echo 'LuckTest Recorder atomicity: ' . ($checks - $recorderStart) . " checks passed (no live database).\n";
