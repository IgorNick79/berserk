<?php
// tools/test_battle_sync_endpoint.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\CardInstance;
use Berserk\Core\Db;
use Berserk\Core\GameRepository;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function battleSyncAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function battleSyncRequest(array $query): array
{
    $runner = tempnam(sys_get_temp_dir(), 'berserk_battle_sync_');
    if ($runner === false) {
        throw new RuntimeException('Unable to create temporary endpoint runner');
    }

    $index = str_replace('\\', '/', realpath(__DIR__ . '/../www/index.php'));
    $payload = var_export($query, true);
    file_put_contents($runner, <<<PHP
<?php
\$_GET = {$payload};
\$_REQUEST = \$_GET;
\$_SERVER['REQUEST_URI'] = '/index.php?' . http_build_query(\$_GET);
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

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(
            "Endpoint did not return JSON. exit={$exitCode}; stdout={$stdout}; stderr={$stderr}"
        );
    }

    return $decoded;
}

$configPath = __DIR__ . '/../config/db.php';
if (!is_file($configPath)) {
    echo "Skipping battle_sync endpoint tests: config/db.php not found\n";
    exit(0);
}

$config = require $configPath;
try {
    $db = new Db($config);
} catch (Throwable $e) {
    echo "Skipping battle_sync endpoint tests: DB unavailable\n";
    exit(0);
}
$repo = new GameRepository($db);

$cardRows = $db->fetchAll(
    "SELECT ukid, health, move, strike_weak, strike_medium, strike_strong, prop
     FROM cards ORDER BY ind LIMIT 2"
);
if (count($cardRows) < 2) {
    echo "Skipping battle_sync endpoint tests: at least two cards are required\n";
    exit(0);
}

$state = $repo->create(1, 2);
$state->status = 'battle';
$state->battle = [
    'turn' => 1,
    'active' => 'host',
    'strike' => null,
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
    prop: $cardRows[0]['prop'] ? (json_decode((string) $cardRows[0]['prop'], true) ?: []) : [],
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
battleSyncAssert($loaded !== null, 'Saved battle should reload');
$syncVersion = $loaded->persistenceVersion;
battleSyncAssert(is_int($syncVersion), 'Loaded battle should expose persistence version');

$base = [
    'first' => '',
    'game' => (string) $state->gameId,
    'ajax' => 'battle_sync',
];

$snapshot = battleSyncRequest($base + ['since_version' => (string) ($syncVersion - 1)]);
battleSyncAssert($snapshot['ok'] === true, 'Older version request should succeed');
battleSyncAssert($snapshot['type'] === 'snapshot', 'Older version should return snapshot');
battleSyncAssert($snapshot['sync_version'] === $syncVersion, 'Snapshot should return persistence version');
battleSyncAssert($snapshot['status'] === 'battle', 'Snapshot should report battle status');
battleSyncAssert(isset($snapshot['fragments']['field_html']), 'Snapshot should include field_html');

$unchanged = battleSyncRequest($base + ['since_version' => (string) $syncVersion]);
battleSyncAssert($unchanged['ok'] === true, 'Equal version request should succeed');
battleSyncAssert($unchanged['type'] === 'no_change', 'Equal version should return no_change');
battleSyncAssert($unchanged['sync_version'] === $syncVersion, 'No_change should return persistence version');

$forced = battleSyncRequest($base + [
    'since_version' => (string) $syncVersion,
    'force_snapshot' => '1',
    'sel' => '1',
]);
battleSyncAssert($forced['type'] === 'snapshot', 'force_snapshot should return snapshot for equal version');
battleSyncAssert($forced['ui']['sel'] === 1, 'Valid UI selection should be preserved');

$future = battleSyncRequest($base + ['since_version' => (string) ($syncVersion + 1000)]);
battleSyncAssert($future['type'] === 'snapshot', 'Future version must not be treated as current');

$invalidUi = battleSyncRequest($base + [
    'force_snapshot' => '1',
    'sel' => '999999',
    'mode' => 'invalid-mode',
    'pile' => 'broken',
]);
battleSyncAssert(
    $invalidUi['ui'] === ['sel' => 0, 'mode' => 'strike', 'pile' => ''],
    'Invalid UI hints should normalize to safe defaults'
);

$second = battleSyncRequest([
    'second' => '',
    'game' => (string) $state->gameId,
    'ajax' => 'battle_sync',
    'force_snapshot' => '1',
]);
battleSyncAssert($second['ok'] === true && $second['type'] === 'snapshot', 'Second player perspective should work');

$beforeReadOnly = $repo->findById($state->gameId);
battleSyncAssert($beforeReadOnly !== null, 'Battle should reload before read-only check');
$beforeVersion = $beforeReadOnly->persistenceVersion;
for ($i = 0; $i < 3; $i++) {
    battleSyncRequest($base + ['since_version' => (string) ($syncVersion - 1)]);
}
$afterReadOnly = $repo->findById($state->gameId);
battleSyncAssert($afterReadOnly !== null, 'Battle should reload after read-only check');
battleSyncAssert(
    $afterReadOnly->persistenceVersion === $beforeVersion,
    'Repeated battle_sync requests must not advance persistence version'
);

$finished = $repo->findById($state->gameId);
battleSyncAssert($finished !== null, 'Battle should reload before finished status check');
$finished->status = 'game_over';
$finished->winner = 'host';
$finished->bumpVersion();
$repo->save($finished);
$finished = $repo->findById($state->gameId);
battleSyncAssert($finished !== null, 'Finished game should reload');

$finishedResponse = battleSyncRequest($base + [
    'since_version' => (string) ($finished->persistenceVersion - 1),
]);
battleSyncAssert($finishedResponse['ok'] === true, 'Finished status response should succeed');
battleSyncAssert($finishedResponse['type'] === 'status', 'Finished game should not return battle snapshot');
battleSyncAssert($finishedResponse['status'] === 'game_over', 'Finished game should report game_over status');
battleSyncAssert(!isset($finishedResponse['fragments']), 'Finished status response should not include battle fragments');

$missing = battleSyncRequest([
    'first' => '',
    'game' => '999999999',
    'ajax' => 'battle_sync',
]);
battleSyncAssert($missing['ok'] === false && $missing['error'] === 'game_not_found', 'Missing game should return JSON error');

$invalidGame = battleSyncRequest([
    'first' => '',
    'game' => 'not-a-number',
    'ajax' => 'battle_sync',
]);
battleSyncAssert($invalidGame['ok'] === false && $invalidGame['error'] === 'invalid_game', 'Invalid game id should return JSON error');

echo "Battle sync endpoint tests passed.\n";
