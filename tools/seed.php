<?php
// tools/seed.php
declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Db;
use Berserk\Core\GameRepository;
use Berserk\Core\CardInstance;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
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
$stage = $scenario['stage'] ?? 'battle';
$state->status      = $stage;
$state->firstPlayer = $scenario['first_player'] ?? 'host';
$state->getPlayer('host')->side   = (int) ($scenario['host_side'] ?? 1);
$state->getPlayer('player')->side = (int) ($scenario['player_side'] ?? 2);

$ukids = array_map(fn ($c) => $c['ukid'], $scenario['cards'] ?? []);
foreach (['host', 'player'] as $owner) {
    foreach ($scenario['deck_' . $owner] ?? [] as $forced) {
        if (!empty($forced['ukid'])) $ukids[] = (string) $forced['ukid'];
    }
}
$ukids = array_unique($ukids);

$elements = [];
foreach ($db->fetchAll("SELECT ind, code FROM elements") as $e) {
    $elements[(int) $e['ind']] = $e['code'];
}

$info = [];
$info = seedLoadCardInfo($db, $ukids, $elements);

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

if ($stage === 'deal') {
    $dealSize = (int) ($scenario['deal_size'] ?? 15);

    foreach ([GameState::PLAYER_HOST, GameState::PLAYER_PLAYER] as $owner) {
        $player = $state->getPlayer($owner);
        if ($player->side === 2) {
            $player->resources = ['gold' => 25, 'silver' => 23];
        } else {
            $player->resources = ['gold' => 24, 'silver' => 22];
        }

        $cards = seedBuildDealCards($db, $scenario['deck_' . $owner] ?? [], $dealSize, $elements);
        $player->deckId = 0;
        $player->deckCards = seedDeckCardsFromList($cards);

        foreach ($cards as $cardInfo) {
            seedAddCardInstance($state, $owner, CardInstance::ZONE_HAND, $cardInfo);
        }
    }
}

if ($stage === 'battle') {
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
                    "SELECT ukid, price, health, move, elite, single, type, class,
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
                    'single'  => (bool) $r['single'],
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
                single:       $i['single'],
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
                "SELECT ukid, price, health, move, elite, single, type,
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
                    single:       (bool) $r['single'],
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

function seedLoadCardInfo(Db $db, array $ukids, array $elements): array
{
    $ukids = array_values(array_unique(array_filter(array_map('strval', $ukids))));
    if (empty($ukids)) return [];

    $in = "'" . implode("','", array_map(fn ($u) => $db->escape($u), $ukids)) . "'";
    $rows = $db->fetchAll(
        "SELECT ukid, name, price, health, move, elite, single, type, class,
                strike_weak, strike_medium, strike_strong,
                element_id, prop
         FROM cards WHERE ukid IN ($in)"
    );

    $info = [];
    foreach ($rows as $r) {
        $info[$r['ukid']] = seedCardInfoFromRow($r, $elements);
    }
    return $info;
}

function seedCardInfoFromRow(array $r, array $elements): array
{
    return [
        'ukid'    => (string) $r['ukid'],
        'price'   => (int) $r['price'],
        'health'  => (int) $r['health'],
        'move'    => (int) $r['move'],
        'elite'   => (bool) $r['elite'],
        'single'  => (bool) ($r['single'] ?? false),
        'type'    => $r['type'] ?? 'creature',
        'class'   => $r['class'] ?? '',
        'element' => $elements[(int) $r['element_id']] ?? 'neutral',
        'sw'      => (int) $r['strike_weak'],
        'sm'      => (int) $r['strike_medium'],
        'ss'      => (int) $r['strike_strong'],
        'prop'    => $r['prop'] ? json_decode($r['prop'], true) : [],
    ];
}

function seedBuildDealCards(Db $db, array $forced, int $targetSize, array $elements): array
{
    $forcedUkids = [];
    foreach ($forced as $f) {
        if (!empty($f['ukid'])) $forcedUkids[] = (string) $f['ukid'];
    }

    $forcedInfo = seedLoadCardInfo($db, $forcedUkids, $elements);
    $cards = [];
    foreach ($forcedUkids as $ukid) {
        if (isset($forcedInfo[$ukid])) {
            $cards[] = $forcedInfo[$ukid];
        }
    }
    $restSize = $targetSize - count($cards);
    if ($restSize <= 0) {
        return array_slice($cards, 0, $targetSize);
    }

    $rows = $db->fetchAll(
        "SELECT ukid, price, health, move, elite, single, type, class,
                strike_weak, strike_medium, strike_strong,
                element_id, prop
         FROM cards
         WHERE type IN ('creature','fly')
         ORDER BY RAND()
         LIMIT $restSize"
    );

    foreach ($rows as $r) {
        $cards[] = seedCardInfoFromRow($r, $elements);
    }

    return $cards;
}

function seedDeckCardsFromList(array $cards): array
{
    $deckCards = [];
    foreach ($cards as $card) {
        $ukid = $card['ukid'];
        if (!isset($deckCards[$ukid])) {
            $deckCards[$ukid] = [
                'ukid'          => $ukid,
                'count'         => 0,
                'price'         => $card['price'],
                'elite'         => $card['elite'],
                'single'        => $card['single'],
                'element'       => $card['element'],
                'health'        => $card['health'],
                'move'          => $card['move'],
                'strike_weak'   => $card['sw'],
                'strike_medium' => $card['sm'],
                'strike_strong' => $card['ss'],
                'prop'          => $card['prop'],
                'type'          => $card['type'],
                'class'         => $card['class'],
            ];
        }
        $deckCards[$ukid]['count']++;
    }
    return array_values($deckCards);
}

function seedAddCardInstance(GameState $state, string $owner, string $zone, array $i): void
{
    $state->addCard(new CardInstance(
        instanceId:   $state->nextInstanceId(),
        ukid:         $i['ukid'],
        owner:        $owner,
        zone:         $zone,
        hp:           $i['health'],
        hpMax:        $i['health'],
        price:        $i['price'],
        elite:        $i['elite'],
        single:       $i['single'],
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
