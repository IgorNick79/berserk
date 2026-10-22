<?php
declare(strict_types=1);

// Runs the existing isolated SQL suite first; reuses its guarded connection and fresh synthetic schema.
require __DIR__ . '/test_lucktest_quick_sql.php';

use Berserk\Core\{Command, Engine};

$recorderSqlStart = $checks;
$state = seed($db);
$repo = recording($db, $state->gameId);
$entry = register($j, $state, 'recorder-same-source-retry');
$broken = $db->escape(json_encode(['start' => ['type' => 'get_hp', 'hp' => PHP_INT_MAX]], JSON_THROW_ON_ERROR));
$db->query("UPDATE cards SET prop='$broken'");
try {
    $repo->executeLockedCommand($state, $j, (int) $entry['id'], function ($source) use ($db) {
        $original = $source->historyRecorder;
        $recorderBefore = serialize($original);
        $stateBefore = $source->toArray();
        $engine = new Engine($db, fn() => 1800000000, fn($min, $max) => $min);
        $cmd = new Command('confirm_settings');
        rolls();
        $failed = $engine->apply($source, 'host', $cmd);
        verify(!$failed->success && $source->toArray() === $stateBefore, 'failed candidate did not publish GameState');
        verify($source->historyRecorder === $original && serialize($original) === $recorderBefore, 'failed candidate did not publish Recorder');

        $fixed = $db->escape(json_encode(['start' => ['type' => 'get_hp', 'hp' => 2]], JSON_THROW_ON_ERROR));
        $db->query("UPDATE cards SET prop='$fixed'");
        rolls();
        $retried = $engine->apply($source, 'host', $cmd);
        verify($retried->success && $source->status === 'battle', 'same locked source retries successfully');
        verify($source->historyRecorder !== $original && serialize($original) === $recorderBefore, 'only successful observer is published');
        return $retried;
    });
} finally {
    $db->query("UPDATE cards SET prop='{}'");
}
$id = $state->gameId;
verify($j->findById((int) $entry['id'])['status'] === 'committed', 'retried command journal committed');
verify(initialCount($db, $id) === 1, 'one durable initial after in-memory retry');
verify((int) $db->fetchOne("SELECT COUNT(*) n FROM battle_history_steps WHERE game_id=$id")['n'] === 2, 'one initial plus one battle_ready');
foreach (['initial.started', 'initial.settled', 'battle_ready.started', 'battle_ready.settled', 'turn.started'] as $type) {
    verify((int) $db->fetchOne("SELECT COUNT(*) n FROM battle_history_events WHERE game_id=$id AND event_type='$type'")['n'] === 1, 'one durable event: ' . $type);
}
$loaded = $repo->findById($id);
verify($loaded->status === 'battle' && count($loaded->cards) === 60, 'retried Battle reloads');
$followup = $j->registerProcessing($id, $loaded->battle['active'], 'recorder-next-turn', 'end_turn', [], $loaded->persistenceVersion);
$repo->executeLockedCommand($loaded, $j, (int) $followup['id'], fn($locked) => (new Engine($db))->apply($locked, $locked->battle['active'], new Command('end_turn')));
verify($j->findById((int) $followup['id'])['status'] === 'committed' && initialCount($db, $id) === 1, 'subsequent native action commits without another initial');
verify((int) $db->fetchOne("SELECT COUNT(*) n FROM battle_history_actions WHERE game_id=$id AND action_type='recording_gap'")['n'] === 0, 'retry and followup leave no recording gap');
echo 'LuckTest Recorder SQL retry: ' . ($checks - $recorderSqlStart) . " checks passed on the isolated server.\n";
