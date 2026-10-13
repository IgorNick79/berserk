<?php
// tools/test_battle_command_endpoint.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\CommandJournalRepository;
use Berserk\Core\Db;
use Berserk\Core\GameLog;
use Berserk\Core\GameRepository;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function battleCommandAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function battleCommandRawRequest(array $query, array $post = [], string $method = 'POST'): array
{
    $runner = tempnam(sys_get_temp_dir(), 'berserk_battle_command_');
    if ($runner === false) {
        throw new RuntimeException('Unable to create temporary endpoint runner');
    }
    $statusFile = tempnam(sys_get_temp_dir(), 'berserk_battle_command_status_');
    if ($statusFile === false) {
        @unlink($runner);
        throw new RuntimeException('Unable to create temporary status file');
    }

    $index = str_replace('\\', '/', realpath(__DIR__ . '/../www/index.php'));
    $statusPath = str_replace('\\', '/', $statusFile);
    $queryPayload = var_export($query, true);
    $postPayload = var_export($post, true);
    $methodPayload = var_export($method, true);
    file_put_contents($runner, <<<PHP
<?php
\$_GET = {$queryPayload};
\$_POST = {$postPayload};
\$_REQUEST = array_merge(\$_GET, \$_POST);
\$_SERVER['REQUEST_METHOD'] = {$methodPayload};
\$_SERVER['REQUEST_URI'] = '/index.php?' . http_build_query(\$_GET);
register_shutdown_function(static function (): void {
    file_put_contents('{$statusPath}', json_encode([
        'status' => http_response_code() ?: 200,
        'headers' => function_exists('xdebug_get_headers') ? xdebug_get_headers() : headers_list(),
        'session_debug' => \$_SESSION['debug'] ?? null,
        'session_debug_roll' => \$_SESSION['debug_roll'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
});
require '{$index}';
PHP);

    $cmd = [PHP_BINARY, $runner];
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        @unlink($runner);
        throw new RuntimeException('Unable to start endpoint runner');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($runner);
    $statusPayload = is_file($statusFile) ? json_decode((string) file_get_contents($statusFile), true) : null;
    @unlink($statusFile);

    return [
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
        'exit_code' => $exitCode,
        'status' => is_array($statusPayload) ? (int) ($statusPayload['status'] ?? 0) : 0,
        'headers' => is_array($statusPayload) ? (array) ($statusPayload['headers'] ?? []) : [],
        'session_debug' => is_array($statusPayload) ? ($statusPayload['session_debug'] ?? null) : null,
        'session_debug_roll' => is_array($statusPayload) ? ($statusPayload['session_debug_roll'] ?? null) : null,
    ];
}

function battleCommandRequest(array $query, array $post = [], string $method = 'POST'): array
{
    $raw = battleCommandRawRequest($query, $post, $method);
    $decoded = json_decode((string) $raw['stdout'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException(
            "Endpoint did not return JSON. exit={$raw['exit_code']}; stdout={$raw['stdout']}; stderr={$raw['stderr']}"
        );
    }
    $decoded['__http_status'] = $raw['status'];

    return $decoded;
}

function battleCommandHeader(array $raw, string $prefix): ?string
{
    foreach ($raw['headers'] as $header) {
        if (stripos((string) $header, $prefix) === 0) {
            return (string) $header;
        }
    }
    return null;
}

function battleCommandCreateBattle(GameRepository $repo, array $cardRows): array
{
    $state = $repo->create(1, 2);
    $state->status = 'battle';
    $state->battle = [
        'turn' => 1,
        'active' => 'host',
        'strike' => null,
        'instant_result' => ['sentinel' => true],
    ];
    $state->addCard(new CardInstance(
        instanceId: 1,
        ukid: (string) $cardRows[0]['ukid'],
        owner: 'host',
        zone: CardInstance::ZONE_FIELD,
        row: 1,
        col: 1,
        slot: 0,
        hp: (int) $cardRows[0]['health'],
        hpMax: (int) $cardRows[0]['health'],
        move: (int) $cardRows[0]['move'],
        moveMax: (int) $cardRows[0]['move'],
        strikeWeak: (int) $cardRows[0]['strike_weak'],
        strikeMedium: (int) $cardRows[0]['strike_medium'],
        strikeStrong: (int) $cardRows[0]['strike_strong'],
        revealed: true,
        prop: array_replace_recursive(
            $cardRows[0]['prop'] ? (json_decode((string) $cardRows[0]['prop'], true) ?: []) : [],
            [
                'save_coins' => true,
                'coins' => ['max_value' => 3],
            ]
        ),
    ));
    $state->addCard(new CardInstance(
        instanceId: 2,
        ukid: (string) $cardRows[1]['ukid'],
        owner: 'player',
        zone: CardInstance::ZONE_FIELD,
        row: 1,
        col: 2,
        slot: 0,
        hp: (int) $cardRows[1]['health'],
        hpMax: (int) $cardRows[1]['health'],
        move: (int) $cardRows[1]['move'],
        moveMax: (int) $cardRows[1]['move'],
        strikeWeak: (int) $cardRows[1]['strike_weak'],
        strikeMedium: (int) $cardRows[1]['strike_medium'],
        strikeStrong: (int) $cardRows[1]['strike_strong'],
        revealed: false,
        prop: $cardRows[1]['prop'] ? (json_decode((string) $cardRows[1]['prop'], true) ?: []) : [],
    ));
    $state->bumpVersion();
    $repo->save($state);

    $loaded = $repo->findById($state->gameId);
    battleCommandAssert($loaded !== null, 'Created battle should reload');
    battleCommandAssert(is_int($loaded->persistenceVersion), 'Created battle should have persistence version');

    return [$loaded, (int) $loaded->persistenceVersion];
}

$configPath = __DIR__ . '/../config/db.php';
if (!is_file($configPath)) {
    echo "Skipping battle_command endpoint tests: config/db.php not found\n";
    exit(0);
}

$config = require $configPath;
try {
    $db = new Db($config);
} catch (Throwable) {
    echo "Skipping battle_command endpoint tests: DB unavailable\n";
    exit(0);
}
$repo = new GameRepository($db);
$journal = new CommandJournalRepository($db);

$cardRows = $db->fetchAll(
    "SELECT ukid, health, move, strike_weak, strike_medium, strike_strong, prop
     FROM cards ORDER BY ind LIMIT 2"
);
if (count($cardRows) < 2) {
    echo "Skipping battle_command endpoint tests: at least two cards are required\n";
    exit(0);
}

[$legacyState, $legacyVersion] = battleCommandCreateBattle($repo, $cardRows);
$legacyJournalBefore = $db->fetchOne(
    "SELECT COUNT(*) AS cnt FROM battle_command_journal WHERE game_id = " . $legacyState->gameId
);
$legacyBlocked = battleCommandRawRequest([
    'first' => '',
    'game' => (string) $legacyState->gameId,
    'cmd' => 'end_turn',
    'target_id' => '2',
    'sel' => '1',
    'mode' => 'strike',
    'pile' => 'grave',
], [], 'GET');
$legacyLocation = battleCommandHeader($legacyBlocked, 'Location:');
battleCommandAssert($legacyBlocked['status'] === 302, 'Legacy battle GET should redirect');
if ($legacyLocation !== null) {
    battleCommandAssert(str_contains($legacyLocation, '?first&game=' . $legacyState->gameId), 'Legacy redirect should keep role and game id');
    battleCommandAssert(!str_contains($legacyLocation, 'cmd='), 'Legacy redirect must drop cmd');
    battleCommandAssert(!str_contains($legacyLocation, 'target_id='), 'Legacy redirect must drop command payload');
}

$afterLegacyBlocked = $repo->findById($legacyState->gameId);
battleCommandAssert($afterLegacyBlocked !== null, 'Blocked legacy battle should reload');
battleCommandAssert($afterLegacyBlocked->status === 'battle', 'Blocked legacy GET must keep battle active');
battleCommandAssert($afterLegacyBlocked->persistenceVersion === $legacyVersion, 'Blocked legacy GET must not advance persistence version');
battleCommandAssert($afterLegacyBlocked->getCard(1)?->coins === 0, 'Blocked legacy GET must not execute Engine');
$legacyJournalAfter = $db->fetchOne(
    "SELECT COUNT(*) AS cnt FROM battle_command_journal WHERE game_id = " . $legacyState->gameId
);
battleCommandAssert(
    (int) ($legacyJournalAfter['cnt'] ?? -1) === (int) ($legacyJournalBefore['cnt'] ?? -2),
    'Blocked legacy GET must not create journal rows'
);
battleCommandAssert(GameLog::read($legacyState->gameId) === [], 'Blocked legacy GET must not append GameLog events');

$legacyResign = battleCommandRawRequest([
    'first' => '',
    'game' => (string) $legacyState->gameId,
    'cmd' => 'resign',
], [], 'GET');
battleCommandAssert($legacyResign['status'] === 302, 'Legacy battle resign should redirect');
$afterLegacyResign = $repo->findById($legacyState->gameId);
battleCommandAssert($afterLegacyResign !== null, 'Blocked legacy resign should reload');
battleCommandAssert($afterLegacyResign->status === 'battle', 'Blocked legacy resign must not finish the game');
battleCommandAssert($afterLegacyResign->winner === null, 'Blocked legacy resign must not set winner');
battleCommandAssert($afterLegacyResign->persistenceVersion === $legacyVersion, 'Blocked legacy resign must not advance version');

$debugRoll = battleCommandRawRequest([
    'first' => '',
    'game' => (string) $legacyState->gameId,
    'debug_roll' => '6*',
], [], 'GET');
battleCommandAssert($debugRoll['status'] === 200, 'debug_roll without cmd should render normally');
battleCommandAssert($debugRoll['session_debug_roll'] === '6*', 'debug_roll without cmd should update session state');

$debugOn = battleCommandRawRequest([
    'first' => '',
    'game' => (string) $legacyState->gameId,
    'debug' => '1',
], [], 'GET');
battleCommandAssert($debugOn['status'] === 200, 'debug without cmd should render normally');
battleCommandAssert((int) $debugOn['session_debug'] === 1, 'debug=1 without cmd should update session state');

$uiOnly = battleCommandRawRequest([
    'first' => '',
    'game' => (string) $legacyState->gameId,
    'sel' => '1',
    'mode' => 'strike',
    'pile' => 'grave',
], [], 'GET');
battleCommandAssert($uiOnly['status'] === 200, 'Safe UI params without cmd should render normally');
$afterUiOnly = $repo->findById($legacyState->gameId);
battleCommandAssert($afterUiOnly !== null, 'UI-only battle should reload');
battleCommandAssert($afterUiOnly->persistenceVersion === $legacyVersion, 'Safe UI params without cmd must not mutate battle');

$preBattle = $repo->create(1, 2);
$preBattleVersion = (int) $preBattle->persistenceVersion;
$preBattleLegacy = battleCommandRawRequest([
    'first' => '',
    'game' => (string) $preBattle->gameId,
    'cmd' => 'choose_mode',
    'mode' => 'system',
], [], 'GET');
battleCommandAssert($preBattleLegacy['status'] === 302, 'Pre-battle legacy command should keep PRG redirect');
$afterPreBattle = $repo->findById($preBattle->gameId);
battleCommandAssert($afterPreBattle !== null, 'Pre-battle game should reload');
battleCommandAssert($afterPreBattle->persistenceVersion > $preBattleVersion, 'Pre-battle legacy command should still persist');
battleCommandAssert($afterPreBattle->status !== 'mode', 'Pre-battle legacy command should still execute');

[$state, $version] = battleCommandCreateBattle($repo, $cardRows);
$baseQuery = [
    'first' => '',
    'game' => (string) $state->gameId,
    'ajax' => 'battle_command',
];

$getRejected = battleCommandRequest($baseQuery, [], 'GET');
battleCommandAssert($getRejected['ok'] === false, 'GET battle_command should be rejected');
battleCommandAssert($getRejected['error'] === 'method_not_allowed', 'GET rejection should use method_not_allowed');
battleCommandAssert($getRejected['__http_status'] === 405, 'GET rejection should use HTTP 405');

$missingVersion = battleCommandRequest($baseQuery, ['cmd' => 'resign']);
battleCommandAssert($missingVersion['ok'] === false, 'Missing expected_version should be rejected');
battleCommandAssert($missingVersion['error'] === 'missing_expected_version', 'Missing version should be explicit');

$stale = battleCommandRequest($baseQuery, [
    'cmd' => 'resign',
    'expected_version' => (string) ($version - 1),
]);
battleCommandAssert($stale['ok'] === false, 'Stale command should be rejected');
battleCommandAssert($stale['type'] === 'conflict', 'Stale command should return conflict');
battleCommandAssert($stale['sync_version'] === $version, 'Conflict should return current persistence version');
battleCommandAssert($stale['__http_status'] === 409, 'Stale command should use HTTP 409');

$invalid = battleCommandRequest($baseQuery, [
    'cmd' => 'unknown_ajax_test_command',
    'expected_version' => (string) $version,
    'sel' => '1',
]);
battleCommandAssert($invalid['ok'] === false, 'Rejected gameplay command should return ok=false');
battleCommandAssert($invalid['type'] === 'snapshot', 'Rejected gameplay command in battle should return snapshot');
battleCommandAssert($invalid['sync_version'] === $version, 'Rejected command should not advance persistence version');
battleCommandAssert(isset($invalid['fragments']['field_html']), 'Rejected command should include fresh battle fragments');
battleCommandAssert($invalid['__http_status'] === 200, 'Rejected gameplay command should use HTTP 200');

$afterInvalid = $repo->findById($state->gameId);
battleCommandAssert($afterInvalid !== null, 'Battle should reload after rejected command');
battleCommandAssert($afterInvalid->persistenceVersion === $version, 'Rejected command must not persist changes');
battleCommandAssert($afterInvalid->status === 'battle', 'Rejected command must leave persisted status unchanged');
battleCommandAssert(
    ($afterInvalid->battle['instant_result']['sentinel'] ?? false) === true,
    'Rejected command must not persist in-memory mutations made before Result::error'
);

$nonFinishing = battleCommandRequest($baseQuery, [
    'cmd' => 'gain_coin',
    'expected_version' => (string) $version,
    'card_id' => '1',
]);
battleCommandAssert($nonFinishing['ok'] === true, 'Successful non-finishing command should return ok=true');
battleCommandAssert($nonFinishing['type'] === 'snapshot', 'Successful non-finishing command should return battle snapshot');
battleCommandAssert($nonFinishing['status'] === 'battle', 'Successful non-finishing command should keep battle active');
battleCommandAssert($nonFinishing['sync_version'] > $version, 'Successful non-finishing command should advance persistence version');
battleCommandAssert($nonFinishing['__http_status'] === 200, 'Successful non-finishing command should use HTTP 200');
battleCommandAssert(!array_key_exists('command_status', $nonFinishing), 'Legacy command without command_id should not include journal metadata');
foreach (['field_html', 'fly_zones_html', 'panel_html', 'piles_html', 'pile_reveal_html', 'info_panel_html'] as $fragment) {
    battleCommandAssert(array_key_exists($fragment, $nonFinishing['fragments']), "Snapshot should contain {$fragment}");
}

$afterNonFinishing = $repo->findById($state->gameId);
battleCommandAssert($afterNonFinishing !== null, 'Battle should reload after non-finishing command');
battleCommandAssert($afterNonFinishing->status === 'battle', 'Non-finishing command should persist battle status');
battleCommandAssert($afterNonFinishing->getCard(1)?->coins === 1, 'Non-finishing command should persist coin gain');
battleCommandAssert(
    $afterNonFinishing->persistenceVersion === $nonFinishing['sync_version'],
    'Non-finishing response version should match persisted version'
);

$opponentSync = battleCommandRequest([
    'second' => '',
    'game' => (string) $state->gameId,
    'ajax' => 'battle_sync',
    'since_version' => (string) $version,
], [], 'GET');
battleCommandAssert($opponentSync['ok'] === true, 'Opponent battle_sync should succeed after command');
battleCommandAssert($opponentSync['type'] === 'snapshot', 'Opponent battle_sync should detect newer version');
battleCommandAssert($opponentSync['sync_version'] === $nonFinishing['sync_version'], 'Opponent sync should return committed command version');

$version = (int) $nonFinishing['sync_version'];
$success = battleCommandRequest($baseQuery, [
    'cmd' => 'resign',
    'expected_version' => (string) $version,
]);
battleCommandAssert($success['ok'] === true, 'Successful command should return ok=true');
battleCommandAssert($success['type'] === 'status', 'Resign finishes the game and should return status response');
battleCommandAssert($success['status'] === 'game_over', 'Successful resign should return game_over status');
battleCommandAssert($success['sync_version'] > $version, 'Successful command should return committed persistence version');
battleCommandAssert(!isset($success['fragments']), 'Finished game response should not fabricate battle fragments');

$committed = $repo->findById($state->gameId);
battleCommandAssert($committed !== null, 'Committed game should reload');
battleCommandAssert($committed->status === 'game_over', 'Successful command should persist game_over status');
battleCommandAssert($committed->winner === 'player', 'Host resign should make player the winner');
battleCommandAssert($committed->persistenceVersion === $success['sync_version'], 'Response version should match committed persistence version');

$repeatOld = battleCommandRequest($baseQuery, [
    'cmd' => 'resign',
    'expected_version' => (string) $version,
]);
battleCommandAssert($repeatOld['ok'] === false, 'Repeated old command should be rejected');
battleCommandAssert($repeatOld['type'] === 'conflict', 'Repeated old command should return conflict');
battleCommandAssert($repeatOld['status'] === 'game_over', 'Conflict after finish should report current status');

[$secondState, $secondVersion] = battleCommandCreateBattle($repo, $cardRows);
$secondSuccess = battleCommandRequest([
    'second' => '',
    'game' => (string) $secondState->gameId,
    'ajax' => 'battle_command',
], [
    'cmd' => 'resign',
    'expected_version' => (string) $secondVersion,
]);
battleCommandAssert($secondSuccess['ok'] === true, 'Second player command should work');
battleCommandAssert($secondSuccess['status'] === 'game_over', 'Second player resign should finish game');

$secondCommitted = $repo->findById($secondState->gameId);
battleCommandAssert($secondCommitted !== null, 'Second player game should reload');
battleCommandAssert($secondCommitted->winner === 'host', 'Second player resign should make host the winner');

[$arrayState, $arrayVersion] = battleCommandCreateBattle($repo, $cardRows);
$arrayParam = battleCommandRequest([
    'first' => '',
    'game' => (string) $arrayState->gameId,
    'ajax' => 'battle_command',
], [
    'cmd' => ['resign'],
    'expected_version' => (string) $arrayVersion,
]);
battleCommandAssert($arrayParam['ok'] === false, 'Array cmd should be rejected safely');

[$arrayPayloadState, $arrayPayloadVersion] = battleCommandCreateBattle($repo, $cardRows);
$arrayPayload = battleCommandRequest([
    'first' => '',
    'game' => (string) $arrayPayloadState->gameId,
    'ajax' => 'battle_command',
], [
    'cmd' => 'resign',
    'expected_version' => (string) $arrayPayloadVersion,
    'target_id' => ['2'],
]);
battleCommandAssert($arrayPayload['ok'] === false, 'Array command payload values should be rejected safely');
battleCommandAssert($arrayPayload['error'] === 'Invalid command request', 'Array payload should use invalid request error');

[$processingState, $processingVersion] = battleCommandCreateBattle($repo, $cardRows);
$processingCommandId = 'journal-endpoint-' . $processingState->gameId . '-processing';
$processingRecord = $journal->registerProcessing(
    $processingState->gameId,
    'host',
    $processingCommandId,
    'gain_coin',
    ['card_id' => '1'],
    $processingVersion
);
$processingDuplicate = battleCommandRequest([
    'first' => '',
    'game' => (string) $processingState->gameId,
    'ajax' => 'battle_command',
], [
    'cmd' => 'gain_coin',
    'expected_version' => (string) $processingVersion,
    'command_id' => $processingCommandId,
    'card_id' => '1',
]);
battleCommandAssert(($processingRecord['is_new'] ?? null) === true, 'Test setup should create a fresh processing journal row');
battleCommandAssert($processingDuplicate['ok'] === true, 'Duplicate processing command should return ok=true status response');
battleCommandAssert($processingDuplicate['type'] === 'command_status', 'Duplicate processing command should return command_status type');
battleCommandAssert($processingDuplicate['command_status'] === CommandJournalRepository::STATUS_PROCESSING, 'Duplicate processing command should remain processing');
battleCommandAssert($processingDuplicate['terminal'] === false, 'Processing command should not be terminal');
$afterProcessingDuplicate = $repo->findById($processingState->gameId);
battleCommandAssert($afterProcessingDuplicate !== null, 'Processing duplicate game should reload');
battleCommandAssert($afterProcessingDuplicate->getCard(1)?->coins === 0, 'Duplicate processing command must not execute Engine');

[$failedState, $failedVersion] = battleCommandCreateBattle($repo, $cardRows);
$failedCommandId = 'journal-endpoint-' . $failedState->gameId . '-failed';
$failedRecord = $journal->registerProcessing(
    $failedState->gameId,
    'host',
    $failedCommandId,
    'gain_coin',
    ['card_id' => '1'],
    $failedVersion
);
$journal->markFailed((int) $failedRecord['id'], $failedVersion, 'unknown_outcome');
$failedDuplicate = battleCommandRequest([
    'first' => '',
    'game' => (string) $failedState->gameId,
    'ajax' => 'battle_command',
], [
    'cmd' => 'gain_coin',
    'expected_version' => (string) $failedVersion,
    'command_id' => $failedCommandId,
    'card_id' => '1',
]);
battleCommandAssert($failedDuplicate['ok'] === false, 'Duplicate failed command should return ok=false');
battleCommandAssert($failedDuplicate['type'] === 'command_status', 'Duplicate failed command should return command_status type');
battleCommandAssert($failedDuplicate['command_status'] === CommandJournalRepository::STATUS_FAILED, 'Duplicate failed command should preserve failed status');
battleCommandAssert($failedDuplicate['terminal'] === true, 'Failed command should be terminal');
battleCommandAssert(($failedDuplicate['error'] ?? '') !== 'Command outcome is unknown', 'Failed terminal command should not be reported as unknown');
$afterFailedDuplicate = $repo->findById($failedState->gameId);
battleCommandAssert($afterFailedDuplicate !== null, 'Failed duplicate game should reload');
battleCommandAssert($afterFailedDuplicate->getCard(1)?->coins === 0, 'Duplicate failed command must not execute Engine');

[$journalState, $journalVersion] = battleCommandCreateBattle($repo, $cardRows);
$journalQuery = [
    'first' => '',
    'game' => (string) $journalState->gameId,
    'ajax' => 'battle_command',
];
$journalCommandId = 'journal-endpoint-' . $journalState->gameId . '-gain';
$journalSuccess = battleCommandRequest($journalQuery, [
    'cmd' => 'gain_coin',
    'expected_version' => (string) $journalVersion,
    'command_id' => $journalCommandId,
    'card_id' => '1',
]);
battleCommandAssert($journalSuccess['ok'] === true, 'Journal-aware successful command should return ok=true');
battleCommandAssert($journalSuccess['command_id'] === $journalCommandId, 'Journal-aware response should echo command_id');
battleCommandAssert($journalSuccess['command_status'] === CommandJournalRepository::STATUS_COMMITTED, 'Journal-aware successful command should be committed');
battleCommandAssert($journalSuccess['terminal'] === true, 'Committed command should be terminal');
battleCommandAssert($journalSuccess['before_version'] === $journalVersion, 'Committed command should record before_version');
battleCommandAssert($journalSuccess['after_version'] === $journalSuccess['sync_version'], 'Committed after_version should match response version');

$afterJournalSuccess = $repo->findById($journalState->gameId);
battleCommandAssert($afterJournalSuccess !== null, 'Journal-aware game should reload after success');
battleCommandAssert($afterJournalSuccess->getCard(1)?->coins === 1, 'Journal-aware success should persist exactly one coin');
$committedVersion = (int) $afterJournalSuccess->persistenceVersion;

$journalDuplicate = battleCommandRequest($journalQuery, [
    'cmd' => 'gain_coin',
    'expected_version' => (string) $journalVersion,
    'command_id' => $journalCommandId,
    'card_id' => '1',
]);
battleCommandAssert($journalDuplicate['ok'] === true, 'Duplicate committed command should return current state');
battleCommandAssert($journalDuplicate['command_status'] === CommandJournalRepository::STATUS_COMMITTED, 'Duplicate committed command should remain committed');
$afterJournalDuplicate = $repo->findById($journalState->gameId);
battleCommandAssert($afterJournalDuplicate !== null, 'Journal-aware game should reload after duplicate');
battleCommandAssert($afterJournalDuplicate->getCard(1)?->coins === 1, 'Duplicate command id must not execute Engine again');
battleCommandAssert($afterJournalDuplicate->persistenceVersion === $committedVersion, 'Duplicate command id must not advance version again');

$journalMismatch = battleCommandRequest($journalQuery, [
    'cmd' => 'gain_coin',
    'expected_version' => (string) $journalVersion,
    'command_id' => $journalCommandId,
    'card_id' => '2',
]);
battleCommandAssert($journalMismatch['ok'] === false, 'Duplicate command id with different payload should be rejected');
battleCommandAssert($journalMismatch['error'] === 'command_id_conflict', 'Payload mismatch should use command_id_conflict');
battleCommandAssert($journalMismatch['__http_status'] === 409, 'Payload mismatch should use HTTP 409');

$journalRejectedId = 'journal-endpoint-' . $journalState->gameId . '-rejected';
$journalRejected = battleCommandRequest([
    'first' => '',
    'game' => (string) $journalState->gameId,
    'ajax' => 'battle_command',
], [
    'cmd' => 'unknown_ajax_test_command',
    'expected_version' => (string) $committedVersion,
    'command_id' => $journalRejectedId,
    'sel' => '1',
]);
battleCommandAssert($journalRejected['ok'] === false, 'Rejected journal-aware command should return ok=false');
battleCommandAssert($journalRejected['command_status'] === CommandJournalRepository::STATUS_REJECTED, 'Rejected command should be journaled as rejected');
battleCommandAssert(($journalRejected['error'] ?? '') !== 'Command outcome is unknown', 'Rejected terminal command should not be reported as unknown');
battleCommandAssert($journalRejected['sync_version'] === $committedVersion, 'Rejected journal-aware command should not advance version');

$journalStatus = battleCommandRequest([
    'first' => '',
    'game' => (string) $journalState->gameId,
    'ajax' => 'battle_command_status',
    'command_id' => $journalCommandId,
], [], 'GET');
battleCommandAssert($journalStatus['ok'] === true, 'Command status endpoint should return ok=true for owner');
battleCommandAssert($journalStatus['type'] === 'command_status', 'Command status endpoint should use command_status type');
battleCommandAssert($journalStatus['command_status'] === CommandJournalRepository::STATUS_COMMITTED, 'Command status endpoint should report committed status');
battleCommandAssert($journalStatus['terminal'] === true, 'Committed status endpoint response should be terminal');

$opponentStatus = battleCommandRequest([
    'second' => '',
    'game' => (string) $journalState->gameId,
    'ajax' => 'battle_command_status',
    'command_id' => $journalCommandId,
], [], 'GET');
battleCommandAssert($opponentStatus['ok'] === true, 'Opponent command status lookup should not fail');
battleCommandAssert($opponentStatus['command_id'] === $journalCommandId, 'Unknown command status should preserve command_id');
battleCommandAssert($opponentStatus['command_status'] === 'unknown', 'Opponent must not see another player command status');
battleCommandAssert($opponentStatus['terminal'] === false, 'Unknown opponent status must not be terminal');

[$conflictState, $conflictVersion] = battleCommandCreateBattle($repo, $cardRows);
$journalConflictId = 'journal-endpoint-' . $conflictState->gameId . '-conflict';
$journalConflict = battleCommandRequest([
    'first' => '',
    'game' => (string) $conflictState->gameId,
    'ajax' => 'battle_command',
], [
    'cmd' => 'resign',
    'expected_version' => (string) ($conflictVersion - 1),
    'command_id' => $journalConflictId,
]);
battleCommandAssert($journalConflict['ok'] === false, 'Journal-aware stale command should be rejected');
battleCommandAssert($journalConflict['type'] === 'conflict', 'Journal-aware stale command should return conflict');
battleCommandAssert($journalConflict['command_status'] === CommandJournalRepository::STATUS_CONFLICT, 'Journal-aware stale command should be journaled as conflict');
battleCommandAssert(($journalConflict['error'] ?? '') !== 'Command outcome is unknown', 'Conflict terminal command should not be reported as unknown');
battleCommandAssert($journalConflict['__http_status'] === 409, 'Journal-aware stale command should use HTTP 409');

$missingStatusId = battleCommandRequest([
    'first' => '',
    'game' => (string) $journalState->gameId,
    'ajax' => 'battle_command_status',
], [], 'GET');
battleCommandAssert($missingStatusId['ok'] === false, 'Missing status command_id should be rejected');
battleCommandAssert($missingStatusId['error'] === 'missing_command_id', 'Missing status command_id should be explicit');

$invalidStatusId = battleCommandRequest([
    'first' => '',
    'game' => (string) $journalState->gameId,
    'ajax' => 'battle_command_status',
    'command_id' => 'bad id',
], [], 'GET');
battleCommandAssert($invalidStatusId['ok'] === false, 'Invalid status command_id should be rejected');
battleCommandAssert($invalidStatusId['error'] === 'invalid_command_id', 'Invalid status command_id should be explicit');
battleCommandAssert($invalidStatusId['__http_status'] === 400, 'Invalid status command_id should use HTTP 400');

$invalidCommandId = battleCommandRequest($journalQuery, [
    'cmd' => 'gain_coin',
    'expected_version' => (string) $committedVersion,
    'command_id' => 'bad id',
    'card_id' => '1',
]);
battleCommandAssert($invalidCommandId['ok'] === false, 'Invalid command_id should be rejected');
battleCommandAssert($invalidCommandId['error'] === 'invalid_command_id', 'Invalid command_id should be explicit');
battleCommandAssert($invalidCommandId['__http_status'] === 400, 'Invalid command_id should use HTTP 400');

echo "Battle command endpoint tests passed.\n";
