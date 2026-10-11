<?php
// tools/test_prepare_dice.php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Autoloader.php';

use Berserk\Core\Autoloader;
use Berserk\Core\Dice;
use Berserk\Core\GameState;
use Berserk\Core\Prepare\PrepareProcessor;

Autoloader::register();
Autoloader::addNamespace('Berserk\\', __DIR__ . '/../src/');

function prepareDiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$_SESSION = [
    'debug_roll' => '6,1',
];
unset($_SESSION['debug_roll_state']);
Dice::init();

$state = new GameState(916, 101, 202);
$processor = new PrepareProcessor($state);
$method = new ReflectionMethod(PrepareProcessor::class, 'rollDice');
$method->setAccessible(true);
$method->invoke($processor);

prepareDiceAssert($state->getPlayer(GameState::PLAYER_HOST)->dice === 6, 'Host prepare dice should use first debug roll.');
prepareDiceAssert($state->getPlayer(GameState::PLAYER_PLAYER)->dice === 1, 'Player prepare dice should use second debug roll.');
prepareDiceAssert($state->firstPlayer === GameState::PLAYER_HOST, 'First player should be resolved from forced dice.');
prepareDiceAssert(Dice::remaining() === [], 'Prepare dice should consume the forced debug queue.');

echo "Prepare dice tests passed.\n";
