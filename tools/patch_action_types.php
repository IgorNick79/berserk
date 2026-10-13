<?php
// tools/patch_action_types.php — одноразово проставляет action_type в prop.actions

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Db;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

$config = require __DIR__ . '/../config/db.php';
$db = new Db($config);

$map = [
    'poison_target'        => 'impact',
    'poison_boost'         => 'impact',
    'sand_claws'           => 'impact',
    'dissonance'           => 'impact',
    'particle'             => 'impact',
    'apply_delayed_marker' => 'impact',
    'dive'                 => 'impact',
    'freeze_moves'         => 'cast',
    'row_spell'            => 'cast',
    'damage_poisoned'      => 'cast',
    'life_gift'            => 'cast',
    'wound_transfer'       => 'cast',
    'damage_ranged'        => 'cast',
    'blood_tap'            => 'discharge',
    'bomb_shot'            => 'throw',
    'self_wound_strike'    => 'strike',
];

$rows = $db->fetchAll("SELECT ukid, name, prop FROM cards WHERE prop IS NOT NULL");
$patched = 0;

foreach ($rows as $r) {
    $prop = json_decode($r['prop'], true);
    if (!is_array($prop) || empty($prop['actions'])) continue;

    $changed = false;
    foreach ($prop['actions'] as &$a) {
        $t = (string) ($a['type'] ?? '');
        if ($t === '' || !isset($map[$t])) continue;
        if (!empty($a['action_type'])) continue;

        $a['action_type'] = $map[$t];
        $changed = true;
    }
    unset($a);

    if (!$changed) continue;

    $json = json_encode($prop, JSON_UNESCAPED_UNICODE);
    $escapedJson = $db->escape($json);
    $escapedUkid = $db->escape($r['ukid']);

    $db->query("UPDATE cards SET prop = '{$escapedJson}' WHERE ukid = '{$escapedUkid}'");

    $patched++;
    echo "  patched {$r['ukid']} {$r['name']}\n";
}

echo "Done: {$patched} cards updated\n";