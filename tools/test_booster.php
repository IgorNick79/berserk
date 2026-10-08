<?php
// tools/test_booster.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Db;
use Berserk\Core\Prepare\BoosterGenerator;
use Berserk\Core\Prepare\DraftCopyRules;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

$config = require __DIR__ . '/../config/db.php';
$db  = new Db($config);
$gen = new BoosterGenerator($db);

function boosterAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pool = $gen->generatePool(8);
boosterAssert(count($pool) === 96, 'Eight draft boosters should produce 96 cards');
$in = "'" . implode("','", array_map(fn($u) => $db->escape($u), array_values(array_unique($pool)))) . "'";
$rows = $db->fetchAll("SELECT ukid, prop FROM cards WHERE ukid IN ($in)");
$byUkid = [];
foreach ($rows as $row) {
    $byUkid[(string) $row['ukid']] = $row;
}
$poolCounts = array_count_values($pool);
foreach ($poolCounts as $ukid => $count) {
    $limit = DraftCopyRules::poolLimit($byUkid[$ukid] ?? ['ukid' => $ukid, 'prop' => []]);
    boosterAssert($count <= $limit, "Draft pool copy limit exceeded for {$ukid}: {$count} > {$limit}");
}

$boosters = [];
for ($i = 1; $i <= 5; $i++) {
    $boosters[] = $gen->generate();
}

foreach ($boosters as $i => $booster) {
    $num = $i + 1;
    echo "=== Booster #{$num} ===\n";

    $in = "'" . implode("','", array_map(fn($u) => $db->escape($u), $booster)) . "'";
    $rows = $db->fetchAll("SELECT ukid, name, rarity FROM cards WHERE ukid IN ($in)");
    $byUkid = [];
    foreach ($rows as $r) {
        $byUkid[$r['ukid']] = $r;
    }

    $counts = ['common' => 0, 'uncommon' => 0, 'rare' => 0, 'ultrarare' => 0];

    foreach ($booster as $ukid) {
        $r = $byUkid[$ukid] ?? null;
        $name   = $r['name']   ?? '?';
        $rarity = $r['rarity'] ?? '?';
        $counts[$rarity] = ($counts[$rarity] ?? 0) + 1;

        echo "  [{$rarity}] {$ukid} — {$name}\n";
    }

    $dups = count($booster) !== count(array_unique($booster));
    echo "  Counts: common={$counts['common']}, uncommon={$counts['uncommon']}"
        . ", rare={$counts['rare']}, ultra={$counts['ultrarare']}\n";
    echo "  Duplicates: " . ($dups ? 'YES (BUG)' : 'no') . "\n\n";
}

echo "Done. Total boosters: 5, expected 60 cards.\n";
