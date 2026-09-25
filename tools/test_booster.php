<?php
// tools/test_booster.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Db;
use Berserk\Core\BoosterGenerator;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

$config = require __DIR__ . '/../config/db.php';
$db  = new Db($config);
$gen = new BoosterGenerator($db);

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