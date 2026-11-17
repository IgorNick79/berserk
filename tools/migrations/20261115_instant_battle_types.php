<?php
declare(strict_types=1);
/** Stage B prop transform. No connection or writes on include; preserves unrelated fields. */
function battleTypesProp(string $ukid, string $json): string
{
    $spec = [
        's1_5' => ['dar_sily', 'strike_level', ['strike']],
        's1_20' => ['flash', 'damage_cap', ['strike', 'tap', 'magic']],
        's1_157' => ['krik', 'strike_value', ['strike','tap','magic','uchr']],
        's1_184' => ['raneniya', 'redistribute_wounds', ['strike','tap','magic','uchr']],
        's1_38' => ['magic_trick', 'redirect_strike', ['strike']],
    ];
    if (!isset($spec[$ukid])) throw new RuntimeException('Unexpected card: '.$ukid);
    [$key, $effect, $types] = $spec[$ukid];
    $prop = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $matches = 0;
    foreach ($prop['instants'] ?? [] as $i => $instant) {
        if (($instant['key'] ?? '') !== $key) continue;
        if (($instant['trigger'] ?? '') !== 'combat' || ($instant['effect']['type'] ?? '') !== $effect)
            throw new RuntimeException('Unexpected instant definition: '.$ukid);
        $prop['instants'][$i]['battle_types'] = $types;
        ++$matches;
    }
    if ($matches !== 1) throw new RuntimeException('Expected exactly one instant: '.$ukid);
    return json_encode($prop, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
// Explicit opt-in only. Default prints a preview and rolls back.
$apply = in_array('--apply', $argv, true);
$root = is_file(getcwd().'/config/db.php') ? getcwd() : dirname(__DIR__, 2);
$config = require $root.'/config/db.php';
$db = new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);
$db->set_charset('utf8mb4');
try {
    $engine = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cards'")->fetch_assoc();
    if (($engine['ENGINE'] ?? '') !== 'InnoDB') throw new RuntimeException('cards must use InnoDB');
    $db->query($apply ? 'START TRANSACTION' : 'START TRANSACTION READ ONLY');
    $rows = $db->query("SELECT ukid,name,prop FROM cards WHERE ukid IN ('s1_5','s1_20','s1_38','s1_157','s1_184')".($apply ? ' FOR UPDATE' : ''))->fetch_all(MYSQLI_ASSOC);
    if (count($rows) !== 5 || count(array_unique(array_column($rows,'ukid'))) !== 5) throw new RuntimeException('Expected five unique cards');
    $updates = [];
    foreach ($rows as $row) {
        $next = battleTypesProp($row['ukid'], $row['prop']);
        $updates[] = [$next, $row['ukid']];
        echo $row['ukid'].' '.$row['name'].' '.$next.PHP_EOL;
    }
    if ($apply) {
        $stmt = $db->prepare('UPDATE cards SET prop=? WHERE ukid=?');
        foreach ($updates as [$prop,$ukid]) { $stmt->bind_param('ss',$prop,$ukid); $stmt->execute(); }
        $db->commit();
    } else $db->rollback();
    echo $apply ? "APPLIED\n" : "PREVIEW ONLY; no changes\n";
} catch (Throwable $e) { $db->rollback(); throw $e; }
