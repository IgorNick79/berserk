<?php
// tools/test_command_journal_repository.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CommandJournalException;
use Berserk\Core\CommandJournalRepository;
use Berserk\Core\DbQueryException;
use Berserk\Core\Db;
use Berserk\Core\GameRepository;
use Berserk\Core\GameState;
use Berserk\Core\StaleStateException;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function journalAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function journalPayload(int $target = 1): array
{
    return [
        'cmd' => 'strike',
        'target' => $target,
        'nested' => [
            'b' => 2,
            'a' => 1,
        ],
    ];
}

$configPath = __DIR__ . '/../config/db.php';
if (!is_file($configPath)) {
    echo "Skipping command journal repository tests: config/db.php not found\n";
    exit(0);
}

try {
    $db = new Db(require $configPath);
} catch (Throwable) {
    echo "Skipping command journal repository tests: DB unavailable\n";
    exit(0);
}

$repo = new GameRepository($db);
$journal = new CommandJournalRepository($db);

$state = $repo->create(880001, 880002);
$gameId = $state->gameId;
$version = (int) $state->persistenceVersion;

$commandId = 'journal-test-' . $gameId . '-duplicate';
$first = $journal->registerProcessing(
    $gameId,
    GameState::PLAYER_HOST,
    $commandId,
    'strike',
    journalPayload(),
    $version
);
$duplicate = $journal->registerProcessing(
    $gameId,
    GameState::PLAYER_HOST,
    $commandId,
    'strike',
    ['nested' => ['a' => 1, 'b' => 2], 'target' => 1, 'cmd' => 'strike'],
    $version
);
journalAssert((int) $duplicate['id'] === (int) $first['id'], 'Duplicate command id should return the original journal row');
journalAssert($duplicate['status'] === CommandJournalRepository::STATUS_PROCESSING, 'Duplicate matching command should remain processing');

$mismatchDetected = false;
try {
    $journal->registerProcessing(
        $gameId,
        GameState::PLAYER_HOST,
        $commandId,
        'strike',
        journalPayload(2),
        $version
    );
} catch (CommandJournalException) {
    $mismatchDetected = true;
}
journalAssert($mismatchDetected, 'Duplicate command id with different payload should be rejected');

$expectedVersionMismatchDetected = false;
try {
    $journal->registerProcessing(
        $gameId,
        GameState::PLAYER_HOST,
        $commandId,
        'strike',
        journalPayload(),
        $version + 1
    );
} catch (CommandJournalException) {
    $expectedVersionMismatchDetected = true;
}
journalAssert($expectedVersionMismatchDetected, 'Duplicate command id with different expected_version should be rejected');

$invalidInsertPropagated = false;
try {
    $journal->registerProcessing(
        2147483648,
        GameState::PLAYER_HOST,
        'journal-test-' . $gameId . '-bad-insert',
        'strike',
        journalPayload(),
        $version
    );
} catch (DbQueryException $e) {
    $invalidInsertPropagated = $e->getCode() !== 1062;
}
journalAssert($invalidInsertPropagated, 'Non-duplicate insert errors should not be treated as duplicate commands');

$rejected = $journal->markRejected((int) $first['id'], $version, 'invalid_action', 'Cannot perform command');
journalAssert($rejected['status'] === CommandJournalRepository::STATUS_REJECTED, 'Rejected command should become terminal rejected');
journalAssert((int) $rejected['after_version'] === $version, 'Rejected command should not advance after_version');

$terminalProtected = false;
try {
    $journal->markFailed((int) $first['id'], $version, 'late_failure');
} catch (CommandJournalException) {
    $terminalProtected = true;
}
journalAssert($terminalProtected, 'Terminal command status should not be changed');

$commitState = $repo->findById($gameId);
journalAssert($commitState !== null, 'Game should reload before atomic commit');
$commitVersion = (int) $commitState->persistenceVersion;
$commitCommand = $journal->registerProcessing(
    $gameId,
    GameState::PLAYER_HOST,
    'journal-test-' . $gameId . '-commit',
    'resign',
    ['cmd' => 'resign'],
    $commitVersion
);
$commitState->status = 'battle';
$commitState->bumpVersion();

$separateConnectionRejected = false;
try {
    $otherJournal = new CommandJournalRepository(new Db(require $configPath));
    $repo->saveCommittedCommand($commitState, $otherJournal, (int) $commitCommand['id']);
} catch (RuntimeException) {
    $separateConnectionRejected = true;
}
journalAssert($separateConnectionRejected, 'Atomic command save should require one shared DB connection');

$wrongExpectedCommand = $journal->registerProcessing(
    $gameId,
    GameState::PLAYER_HOST,
    'journal-test-' . $gameId . '-wrong-version',
    'resign',
    ['cmd' => 'resign'],
    $commitVersion + 99
);
$wrongExpectedRejected = false;
try {
    $repo->saveCommittedCommand($commitState, $journal, (int) $wrongExpectedCommand['id']);
} catch (CommandJournalException) {
    $wrongExpectedRejected = true;
}
journalAssert($wrongExpectedRejected, 'Atomic command save should reject journal rows with mismatched expected_version');

$otherGame = $repo->create(880003, 880004);
$wrongGameCommand = $journal->registerProcessing(
    $otherGame->gameId,
    GameState::PLAYER_HOST,
    'journal-test-' . $gameId . '-wrong-game',
    'resign',
    ['cmd' => 'resign'],
    (int) $otherGame->persistenceVersion
);
$wrongGameRejected = false;
try {
    $repo->saveCommittedCommand($commitState, $journal, (int) $wrongGameCommand['id']);
} catch (CommandJournalException) {
    $wrongGameRejected = true;
}
journalAssert($wrongGameRejected, 'Atomic command save should reject journal rows from another game');

$repo->saveCommittedCommand($commitState, $journal, (int) $commitCommand['id']);

$committed = $journal->findById((int) $commitCommand['id']);
$afterCommit = $repo->findById($gameId);
journalAssert($committed !== null, 'Committed journal row should reload');
journalAssert($afterCommit !== null, 'Committed game should reload');
journalAssert($committed['status'] === CommandJournalRepository::STATUS_COMMITTED, 'Atomic path should mark command committed');
journalAssert((int) $committed['before_version'] === $commitVersion, 'Committed command should record before_version');
journalAssert((int) $committed['after_version'] === $commitVersion + 1, 'Committed command should record after_version');
journalAssert($afterCommit->status === 'battle', 'Atomic commit should persist GameState');
journalAssert($afterCommit->persistenceVersion === $commitVersion + 1, 'Atomic commit should advance persistence version');

$rollbackState = $repo->findById($gameId);
journalAssert($rollbackState !== null, 'Game should reload before rollback check');
$rollbackVersion = (int) $rollbackState->persistenceVersion;
$rollbackOriginalStatus = $rollbackState->status;
$rollbackCommand = $journal->registerProcessing(
    $gameId,
    GameState::PLAYER_HOST,
    'journal-test-' . $gameId . '-rollback',
    'resign',
    ['cmd' => 'resign'],
    $rollbackVersion
);

$rollbackState->status = 'turn';
$rollbackState->bumpVersion();
$rollbackJsonBefore = $rollbackState->persistenceJson;
$rolledBackAfterGamesUpdate = false;
$rollbackTriggerName = 'berserk_journal_rollback_test';
try {
    $db->query("DROP TRIGGER IF EXISTS {$rollbackTriggerName}");
    $db->query(
        "CREATE TRIGGER {$rollbackTriggerName}
         BEFORE UPDATE ON battle_command_journal
         FOR EACH ROW
         BEGIN
             IF @berserk_journal_rollback_test_id IS NOT NULL
                AND OLD.id = @berserk_journal_rollback_test_id
                AND NEW.status = 'committed' THEN
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal rollback test';
             END IF;
         END"
    );
    $db->query("SET @berserk_journal_rollback_test_id = " . (int) $rollbackCommand['id']);
    try {
        $repo->saveCommittedCommand($rollbackState, $journal, (int) $rollbackCommand['id']);
    } catch (DbQueryException) {
        $rolledBackAfterGamesUpdate = true;
    }
} finally {
    try {
        $db->query("SET @berserk_journal_rollback_test_id = NULL");
    } catch (Throwable) {
        // Best effort cleanup; dropping the trigger is the important part.
    }
    try {
        $db->query("DROP TRIGGER IF EXISTS {$rollbackTriggerName}");
    } catch (Throwable) {
        throw new RuntimeException('Unable to clean up rollback test trigger');
    }
}
journalAssert($rolledBackAfterGamesUpdate, 'Atomic commit should fail after games UPDATE and before journal completion');
journalAssert($rollbackState->persistenceVersion === $rollbackVersion, 'Failed commit should restore in-memory persistence version');
journalAssert($rollbackState->persistenceJson === $rollbackJsonBefore, 'Failed commit should restore in-memory persistence JSON');

$afterRollback = $repo->findById($gameId);
journalAssert($afterRollback !== null, 'Game should reload after rollback');
journalAssert($afterRollback->status === $rollbackOriginalStatus, 'Failed journal update should roll back GameState update');
journalAssert($afterRollback->persistenceVersion === $rollbackVersion, 'Failed journal update should roll back version increment');
$afterRollbackJournal = $journal->findById((int) $rollbackCommand['id']);
journalAssert($afterRollbackJournal !== null, 'Rollback journal row should reload');
journalAssert($afterRollbackJournal['status'] === CommandJournalRepository::STATUS_PROCESSING, 'Failed journal update must not create a false committed status');

$staleA = $repo->findById($gameId);
$staleB = $repo->findById($gameId);
journalAssert($staleA !== null && $staleB !== null, 'Concurrent states should load');
$staleCommand = $journal->registerProcessing(
    $gameId,
    GameState::PLAYER_HOST,
    'journal-test-' . $gameId . '-stale',
    'resign',
    ['cmd' => 'resign'],
    (int) $staleB->persistenceVersion
);
$staleA->status = 'side';
$staleA->bumpVersion();
$repo->save($staleA);

$staleB->status = 'deal';
$staleB->bumpVersion();
$staleRejected = false;
try {
    $repo->saveCommittedCommand($staleB, $journal, (int) $staleCommand['id']);
} catch (StaleStateException) {
    $staleRejected = true;
}
journalAssert($staleRejected, 'Atomic command save should preserve optimistic locking conflict');

$afterStale = $repo->findById($gameId);
journalAssert($afterStale !== null, 'Game should reload after stale check');
journalAssert($afterStale->status === 'side', 'Stale atomic save must not overwrite newer state');

$staleJournal = $journal->findById((int) $staleCommand['id']);
journalAssert($staleJournal !== null, 'Stale command journal row should still exist');
journalAssert($staleJournal['status'] === CommandJournalRepository::STATUS_PROCESSING, 'Stale save should not create a false committed status');
$conflict = $journal->markConflict((int) $staleCommand['id'], (int) $afterStale->persistenceVersion, 'stale_state');
journalAssert($conflict['status'] === CommandJournalRepository::STATUS_CONFLICT, 'Conflict terminal status should be available without GameState save');

echo "Command journal repository tests passed.\n";
