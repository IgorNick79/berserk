<?php
// tools/test_battle_command_endpoint.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Db;
use Berserk\Core\GameRepository;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function battleCommandAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function battleCommandRequest(array $query, array $post = [], string $method = 'POST'): array
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
    file_put_contents('{$statusPath}', (string) http_response_code());
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
    $status = is_file($statusFile) ? (int) file_get_contents($statusFile) : 0;
    @unlink($statusFile);

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(
            "Endpoint did not return JSON. exit={$exitCode}; stdout={$stdout}; stderr={$stderr}"
        );
    }
    $decoded['__http_status'] = $status;

    return $decoded;
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

$cardRows = $db->fetchAll(
    "SELECT ukid, health, move, strike_weak, strike_medium, strike_strong, prop
     FROM cards ORDER BY ind LIMIT 2"
);
if (count($cardRows) < 2) {
    echo "Skipping battle_command endpoint tests: at least two cards are required\n";
    exit(0);
}

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

echo "Battle command endpoint tests passed.\n";
