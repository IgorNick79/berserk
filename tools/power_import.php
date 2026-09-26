<?php
// tools/power_import.php
//
// Использование: php tools/power_import.php <файл>
// Файл ищется в tmp/.
// Формат строки: Название карты — значение

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Db;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

$config = require __DIR__ . '/../config/db.php';

$name = $argv[1] ?? '';
if ($name === '') {
    echo "Usage: php power_import.php <file>\n";
    echo "Файл в tmp/\n";
    exit(1);
}

$file = __DIR__ . '/../tmp/' . $name;
if (!is_file($file)) {
    echo "Not found: $file\n";
    exit(1);
}

$db = new Db($config);

$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    echo "Не удалось прочитать файл\n";
    exit(1);
}

$updated = 0;
$skipped = 0;

foreach ($lines as $i => $line) {
    $line = trim($line);
    if ($line === '') continue;

    // Разделитель — em-dash «—»
    $pos = mb_strpos($line, '—');
    if ($pos === false) {
        echo "Строка " . ($i + 1) . ": не найден разделитель: $line\n";
        $skipped++;
        continue;
    }

    $cardName = trim(mb_substr($line, 0, $pos));
    $value    = trim(mb_substr($line, $pos + 1));

    if ($cardName === '' || $value === '') {
        echo "Строка " . ($i + 1) . ": пустое имя или значение\n";
        $skipped++;
        continue;
    }

    $escapedName  = $db->escape($cardName);
    $escapedValue = $db->escape($value);

    $rows = $db->fetchAll(
        "SELECT ind FROM cards WHERE name = '$escapedName' LIMIT 1"
    );
    if (empty($rows)) {
        echo "Строка " . ($i + 1) . ": карта не найдена: $cardName\n";
        $skipped++;
        continue;
    }

    $db->fetchAll(
        "UPDATE cards SET power = '$escapedValue' WHERE name = '$escapedName'"
    );
    $updated++;
    echo "OK: $cardName → $value\n";
}

echo "\nГотово. Обновлено: $updated, пропущено: $skipped\n";