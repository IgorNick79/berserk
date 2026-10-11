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

$count = (int) ($argv[1] ?? 0);
if ($count < 1 || $count > 9) {
    echo "Usage: php tools/test_cards.php <count>\n";
    echo "  count: 1-9 (сколько готовых карт выставить на поле host)\n";
    echo "  5-6 — оптимально: две линии по колонкам 2-3-4\n";
    exit(1);
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

// Случайные карты с непустым prop
$rows = $db->fetchAll(
    "SELECT ukid, name, price, health, move, elite, single, type, class,
            strike_weak, strike_medium, strike_strong,
            element_id, prop
     FROM cards
     WHERE prop IS NOT NULL
       AND type IN ('creature','fly')
     ORDER BY RAND()
     LIMIT $count"
);

if (count($rows) < $count) {
    echo "В БД меньше $count карт с prop\n";
    exit(1);
}

// Колонки 2-3-4, ряды: сначала 3 (ближний), потом 2, потом 1
$cells = [
    [3, 2], [3, 3], [3, 4],
    [2, 2], [2, 3], [2, 4],
    [1, 2], [1, 3], [1, 4],
];

// Фиктивная карта игроку — иначе checkGameOver сразу завершит игру
$dummy = $db->fetchAll(
    "SELECT ukid, name, price, health, move, elite, single, type, class,
            strike_weak, strike_medium, strike_strong, element_id, prop
     FROM cards
     WHERE ukid = 's1_1' LIMIT 1"
);
if (!empty($dummy)) {
    $d = $dummy[0];
    $state->addCard(new CardInstance(
        instanceId:   $state->nextInstanceId(),
        ukid:         $d['ukid'],
        owner:        'player',
        zone:         CardInstance::ZONE_FIELD,
        row:          6,
        col:          3,
        hp:           (int) $d['health'],
        hpMax:        (int) $d['health'],
        price:        (int) $d['price'],
        elite:        (bool) $d['elite'],
        single:       (bool) ($d['single'] ?? false),
        element:      $elements[(int) $d['element_id']] ?? 'neutral',
        move:         (int) $d['move'],
        moveMax:      (int) $d['move'],
        strikeWeak:   (int) $d['strike_weak'],
        strikeMedium: (int) $d['strike_medium'],
        strikeStrong: (int) $d['strike_strong'],
        prop:         $d['prop'] ? json_decode($d['prop'], true) : [],
        type:         $d['type'] ?? 'creature',
        classes:      CardInstance::parseClasses((string) ($d['class'] ?? '')),
    ));
}

// Основные карты — host, полные HP
foreach ($rows as $i => $r) {
    [$row, $col] = $cells[$i];

    $prop  = $r['prop'] ? json_decode($r['prop'], true) : [];
    $hpMax = (int) ($prop['hp_max_override'] ?? $r['health']);

    $state->addCard(new CardInstance(
        instanceId:   $state->nextInstanceId(),
        ukid:         $r['ukid'],
        owner:        'host',
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
}

$engine = new Engine();
$turn   = new TurnProcessor($state, $engine);
$turn->startBattle();

$state->bumpVersion();
$repo->save($state);

echo "Created test game #{$state->gameId}\n";
echo "Host field:\n";
foreach ($rows as $i => $r) {
    [$row, $col] = $cells[$i];
    printf("  (%d,%d)  %-8s  %s\n", $row, $col, $r['ukid'], $r['name']);
}
echo "\n";
echo "  host:   ?first&game={$state->gameId}\n";
echo "  player: ?second&game={$state->gameId}\n";