<?php
// tools/test_dice.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Dice;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function assertSameValue(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . '. Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function applyDebugRoll(string $value): void
{
    $_SESSION['debug_roll'] = $value;
    unset($_SESSION['debug_roll_state']);
    Dice::init();
}

$_SESSION = [];

applyDebugRoll('6,1');
assertSameValue(Dice::roll(), 6, 'First queued roll was not consumed');
assertSameValue(Dice::remaining(), [1], 'Remaining queue after first roll is wrong');
assertSameValue(Dice::roll(), 1, 'Second queued roll was not consumed');
assertSameValue(Dice::remaining(), [], 'Queue should be empty after consuming 6,1');
Dice::init();
assertSameValue(Dice::remaining(), [], 'Exhausted queue restarted after a new request');
assertSameValue($_SESSION['debug_roll_state']['queue'] ?? null, [], 'Exhausted queue state was not saved');

applyDebugRoll('6');
assertSameValue(Dice::roll(), 6, 'Single queued roll was not consumed');
assertSameValue(Dice::remaining(), [], 'Single queued roll should leave an empty queue');
Dice::init();
assertSameValue(Dice::remaining(), [], 'Single queued roll restarted after a new request');

applyDebugRoll('6,1');
assertSameValue(Dice::roll(), 6, 'Cross-request first roll was not consumed');
Dice::init();
assertSameValue(Dice::remaining(), [1], 'Queue remainder was not restored across requests');
assertSameValue(Dice::roll(), 1, 'Cross-request second roll was not consumed');
Dice::init();
assertSameValue(Dice::remaining(), [], 'Queue restarted after cross-request exhaustion');

applyDebugRoll('6,1');
assertSameValue(Dice::remaining(), [6, 1], 'Manual reapply of identical setting did not create a fresh queue');

$_SESSION['debug_roll'] = '2,5';
Dice::init();
assertSameValue(Dice::remaining(), [2, 5], 'Changing setting did not create a new queue');

applyDebugRoll('6*');
for ($i = 0; $i < 4; $i++) {
    assertSameValue(Dice::roll(), 6, 'Infinite 6* mode did not return 6');
}
Dice::init();
assertSameValue(Dice::remaining(), [6], 'Infinite 6* mode did not persist across requests');

applyDebugRoll('1*');
for ($i = 0; $i < 4; $i++) {
    assertSameValue(Dice::roll(), 1, 'Infinite 1* mode did not return 1');
}

applyDebugRoll('6,1');
assertSameValue(Dice::roll(), 6, 'Off setup failed to consume first roll');
$_SESSION['debug_roll'] = 'off';
Dice::init();
assertSameValue(Dice::remaining(), [], 'Off did not clear runtime queue');
assertSameValue(isset($_SESSION['debug_roll_state']), false, 'Off did not clear saved queue state');

applyDebugRoll('bad,9');
assertSameValue(Dice::remaining(), [], 'Invalid values should not create a queue');
assertSameValue(isset($_SESSION['debug_roll_state']), false, 'Invalid values should not save queue state');

echo "Dice tests passed\n";
