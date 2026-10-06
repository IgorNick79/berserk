<?php
// tools/test_repository_optimistic_locking.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Db;
use Berserk\Core\GameRepository;
use Berserk\Core\StaleStateException;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function rolAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$configPath = __DIR__ . '/../config/db.php';
if (!is_file($configPath)) {
    echo "Skipping repository optimistic locking checks: config/db.php not found\n";
    exit(0);
}

$db = new Db(require $configPath);
$repo = new GameRepository($db);

$state = $repo->create(990001, 990002);
$gameId = $state->gameId;

$noChange = $repo->findById($gameId);
rolAssert($noChange !== null, 'Created game should reload');
$repo->save($noChange);

$afterNoChange = $repo->findById($gameId);
rolAssert($afterNoChange !== null, 'Game should reload after no-op save');
rolAssert(
    $afterNoChange->persistenceVersion === $noChange->persistenceVersion,
    'No-op save should advance persistence version without a false stale conflict'
);

$a = $repo->findById($gameId);
$b = $repo->findById($gameId);
rolAssert($a !== null && $b !== null, 'Concurrent states should load');
rolAssert($a->persistenceVersion === $b->persistenceVersion, 'Concurrent states should start from same persistence version');

$a->status = 'settings';
$a->bumpVersion();
$repo->save($a);

$staleRejected = false;
$b->status = 'draft';
$b->bumpVersion();
try {
    $repo->save($b);
} catch (StaleStateException) {
    $staleRejected = true;
}
rolAssert($staleRejected, 'Second concurrent save should be rejected as stale');

$c = $repo->findById($gameId);
rolAssert($c !== null, 'Game should reload after stale rejection');
rolAssert($c->status === 'settings', 'Reloaded game should contain the first save');
rolAssert($c->status !== 'draft', 'Stale save must not overwrite newer state');

$d = $repo->findById($gameId);
rolAssert($d !== null, 'Sequential state D should load');
$d->status = 'view';
$d->bumpVersion();
$repo->save($d);

$e = $repo->findById($gameId);
rolAssert($e !== null, 'Sequential state E should load');
$e->status = 'turn';
$e->bumpVersion();
$repo->save($e);

$f = $repo->findById($gameId);
rolAssert($f !== null, 'Final state should load');
rolAssert($f->status === 'turn', 'Sequential save after reload should succeed');

echo "Repository optimistic locking tests passed.\n";
