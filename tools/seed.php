<?php
// tools/seed.php
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

$name = $argv[1] ?? '';
if ($name === '') {
    echo "Usage: php seed.php <scenario>\n";
    echo "Files in debug/scenarios/*.json\n";
    exit(1);
}

$file = __DIR__ . '/../debug/scenarios/' . $name . '.json';
if (!is_file($file)) {
    echo "Not found: $file\n";
    exit(1);
}

$scenario = json_decode(file_get_contents($file), true);
if (!is_array($scenario)) {
    echo "Invalid JSON\n";
    exit(1);
}

$db   = new Db($config);
$repo = new GameRepository($db);

$state = $repo->create(1, 2);
$state->status      = $scenario['stage'] ?? 'battle';
$state->firstPlayer = $scenario['first_player'] ?? 'host';
$state->getPlayer('host')->side   = (int) ($scenario['host_side'] ?? 1);
$state->getPlayer('player')->side = (int) ($scenario['player_side'] ?? 2);

$ukids = array_unique(array_map(fn ($c) => $c['ukid'], $scenario['cards'] ?? []));

$elements = [];
foreach ($db->fetchAll("SELECT ind, code FROM elements") as $e) {
    $elements[(int) $e['ind']] = $e['code'];
}

$info = [];
if (!empty($ukids)) {
    $in = "'" . implode("','", array_map(fn ($u) => $db->escape($u), $ukids)) . "'";
    $rows = $db->fetchAll(
        "SELECT ukid, name, price, health, move, elite, type, class,
                strike_weak, strike_medium, strike_strong,
                element_id, prop
         FROM cards WHERE ukid IN ($in)"
    );
    foreach ($rows as $r) {
        $info[$r['ukid']] = [
            'price'   => (int) $r['price'],
            'health'  => (int) $r['health'],
            'move'    => (int) $r['move'],
            'elite'   => (bool) $r['elite'],
            'type'    => $r['type'] ?? 'creature',
            'class'   => $r['class'] ?? '',
            'element' => $elements[(int) $r['element_id']] ?? 'neutral',
            'sw'      => (int) $r['strike_weak'],
            'sm'      => (int) $r['strike_medium'],
            'ss'      => (int) $r['strike_strong'],
            'prop'    => $r['prop'] ? json_decode($r['prop'], true) : [],
        ];
    }
}

foreach ($scenario['cards'] ?? [] as $c) {
    $u = $c['ukid'];
    if (!isset($info[$u])) {
        echo "Unknown ukid: $u\n";
        continue;
    }
    $i = $info[$u];

    $zone = isset($c['row']) ? CardInstance::ZONE_FIELD : CardInstance::ZONE_SQUAD;
    $hpMax = (int) ($i['prop']['hp_max_override'] ?? $i['health']);
    $hp    = isset($c['hp']) ? (int) $c['hp'] : (int) $i['health'];

    $state->addCard(new CardInstance(
        instanceId:   $state->nextInstanceId(),
        ukid:         $u,
        owner:        $c['owner'],
        zone:         $zone,
        row:          $c['row'] ?? null,
        col:          $c['col'] ?? null,
        hp:           $hp,
        hpMax:        $hpMax,
        price:        $i['price'],
        elite:        $i['elite'],
        element:      $i['element'],
        move:         $i['move'],
        moveMax:      $i['move'],
        strikeWeak:   $i['sw'],
        strikeMedium: $i['sm'],
        strikeStrong: $i['ss'],
        prop:         $i['prop'],
        type:         $i['type'],
        class:        $i['class']
    ));
}

if (($scenario['stage'] ?? 'battle') === 'battle') {
    $deckSize = (int) ($scenario['deck_size'] ?? 15);

    foreach (['host', 'player'] as $owner) {
        // 1. Принудительные карты (в порядке: top first)
        $forced = $scenario['deck_' . $owner] ?? [];
        foreach ($forced as $f) {
            $u = $f['ukid'] ?? null;
            if (!$u) continue;

            // Если карты нет в $info — добираем из БД
            if (!isset($info[$u])) {
                $in = "'" . $db->escape($u) . "'";
                $rows = $db->fetchAll(
                    "SELECT ukid, price, health, move, elite, type, class
                            strike_weak, strike_medium, strike_strong,
                            element_id, prop
                     FROM cards WHERE ukid IN ($in)"
                );
                if (empty($rows)) continue;
                $r = $rows[0];
                $info[$u] = [
                    'price'   => (int) $r['price'],
                    'health'  => (int) $r['health'],
                    'move'    => (int) $r['move'],
                    'elite'   => (bool) $r['elite'],
                    'type'    => $r['type'] ?? 'creature',
                    'class'   => (string) ($r['class'] ?? ''),
                    'element' => $elements[(int) $r['element_id']] ?? 'neutral',
                    'sw'      => (int) $r['strike_weak'],
                    'sm'      => (int) $r['strike_medium'],
                    'ss'      => (int) $r['strike_strong'],
                    'prop'    => $r['prop'] ? json_decode($r['prop'], true) : [],
                ];
            }

            $i = $info[$u];
            $state->addCard(new CardInstance(
                instanceId:   $state->nextInstanceId(),
                ukid:         $u,
                owner:        $owner,
                zone:         CardInstance::ZONE_DECK,
                hp:           $i['health'],
                hpMax:        $i['health'],
                price:        $i['price'],
                elite:        $i['elite'],
                element:      $i['element'],
                move:         $i['move'],
                moveMax:      $i['move'],
                strikeWeak:   $i['sw'],
                strikeMedium: $i['sm'],
                strikeStrong: $i['ss'],
                prop:         $i['prop'],
                type:         $i['type'],
                class:        $i['class'],
            ));
        }

        // 2. Случайные для добора до deck_size
        $restSize = $deckSize - count($forced);
        if ($restSize > 0) {
            $rows = $db->fetchAll(
                "SELECT ukid, price, health, move, elite, type,
                        strike_weak, strike_medium, strike_strong,
                        element_id, prop
                 FROM cards
                 WHERE type IN ('creature','fly')
                 ORDER BY RAND()
                 LIMIT $restSize"
            );
            foreach ($rows as $r) {
                $state->addCard(new CardInstance(
                    instanceId:   $state->nextInstanceId(),
                    ukid:         $r['ukid'],
                    owner:        $owner,
                    zone:         CardInstance::ZONE_DECK,
                    hp:           (int) $r['health'],
                    hpMax:        (int) $r['health'],
                    price:        (int) $r['price'],
                    elite:        (bool) $r['elite'],
                    element:      $elements[(int) $r['element_id']] ?? 'neutral',
                    move:         (int) $r['move'],
                    moveMax:      (int) $r['move'],
                    strikeWeak:   (int) $r['strike_weak'],
                    strikeMedium: (int) $r['strike_medium'],
                    strikeStrong: (int) $r['strike_strong'],
                    prop:         $r['prop'] ? json_decode($r['prop'], true) : [],
                    type:         $r['type'] ?? 'creature',
                ));
            }
        }
    }

    $engine = new Engine();
    $turn   = new TurnProcessor($state, $engine);
    $turn->startBattle();
}

$state->bumpVersion();
$repo->save($state);

echo "Created game #{$state->gameId}\n";
echo "  host:   ?first&game={$state->gameId}\n";
echo "  player: ?second&game={$state->gameId}\n";