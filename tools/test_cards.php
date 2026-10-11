<?php
// tools/test_cards.php
declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Db;
use Berserk\Core\GameRepository;
use Berserk\Core\CardInstance;
use Berserk\Core\Engine;
use Berserk\Core\TurnProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

$config = require __DIR__ . '/../config/db.php';

$hasCount = array_key_exists(1, $argv);
$count = (int) ($argv[1] ?? 0);
if (!$hasCount || $count < 1 || $count > 9) {
    echo "Usage: php tools/test_cards.php <count>\n";
    echo "  count: 1-9 (сколько готовых карт на поле у каждого игрока)\n";
    echo "  5-6 — оптимально: две линии по колонкам 2-3-4\n";
    exit($hasCount ? 1 : 0);
}

$db   = new Db($config);
$repo = new GameRepository($db);

$state = $repo->create(1, 2);
$state->status      = 'battle';
$state->firstPlayer = 'host';
$state->getPlayer('host')->side   = 1;
$state->getPlayer('player')->side = 2;

$elements = [];
foreach ($db->fetchAll("SELECT ind, code FROM elements") as $e) {
    $elements[(int) $e['ind']] = $e['code'];
}

// 2*N случайных карт с prop
$total = $count * 2;
$rows = $db->fetchAll(
    "SELECT ukid, name, price, health, move, elite, single, type, class,
            strike_weak, strike_medium, strike_strong,
            element_id, prop
     FROM cards
     WHERE prop IS NOT NULL
       AND prop <> ''
       AND type IN ('creature','fly')
     ORDER BY RAND()
     LIMIT $total"
);

if (count($rows) < $total) {
    echo "В БД меньше $total карт с prop\n";
    exit(1);
}

// Колонки 2-3-4. Ряды: host — 3, 2, 1 (от центра), player — 4, 5, 6
$hostCells = [
    [3, 2], [3, 3], [3, 4],
    [2, 2], [2, 3], [2, 4],
    [1, 2], [1, 3], [1, 4],
];
$playerCells = [
    [4, 2], [4, 3], [4, 4],
    [5, 2], [5, 3], [5, 4],
    [6, 2], [6, 3], [6, 4],
];

$hostRows   = array_slice($rows, 0, $count);
$playerRows = array_slice($rows, $count, $count);

$addCard = function (array $r, string $owner, int $row, int $col) use ($state, $elements) {
    $prop  = $r['prop'] ? json_decode($r['prop'], true) : [];
    $hpMax = (int) ($prop['hp_max_override'] ?? $r['health']);

    $state->addCard(new CardInstance(
        instanceId:   $state->nextInstanceId(),
        ukid:         $r['ukid'],
        owner:        $owner,
        zone:         CardInstance::ZONE_FIELD,
        row:          $row,
        col:          $col,
        hp:           $hpMax,
        hpMax:        $hpMax,
        price:        (int) $r['price'],
        elite:        (bool) $r['elite'],
        single:       (bool) ($r['single'] ?? false),
        element:      $elements[(int) $r['element_id']] ?? 'neutral',
        move:         (int) $r['move'],
        moveMax:      (int) $r['move'],
        strikeWeak:   (int) $r['strike_weak'],
        strikeMedium: (int) $r['strike_medium'],
        strikeStrong: (int) $r['strike_strong'],
        prop:         $prop,
        type:         $r['type'] ?? 'creature',
        classes:      CardInstance::parseClasses((string) ($r['class'] ?? '')),
    ));
};

foreach ($hostRows as $i => $r) {
    [$row, $col] = $hostCells[$i];
    $addCard($r, 'host', $row, $col);
}

foreach ($playerRows as $i => $r) {
    [$row, $col] = $playerCells[$i];
    $addCard($r, 'player', $row, $col);
}

$engine = new Engine();
$turn   = new TurnProcessor($state, $engine);
$turn->startBattle();

$state->bumpVersion();
$repo->save($state);

echo "Created test game #{$state->gameId}\n\n";
echo "Host field:\n";
foreach ($hostRows as $i => $r) {
    [$row, $col] = $hostCells[$i];
    printf("  (%d,%d)  %-8s  %s\n", $row, $col, $r['ukid'], $r['name']);
}
echo "\nPlayer field:\n";
foreach ($playerRows as $i => $r) {
    [$row, $col] = $playerCells[$i];
    printf("  (%d,%d)  %-8s  %s\n", $row, $col, $r['ukid'], $r['name']);
}
echo "\n";
echo "  host:   ?first&game={$state->gameId}\n";
echo "  player: ?second&game={$state->gameId}\n";
