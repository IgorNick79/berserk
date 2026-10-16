<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Internal server orchestration outcome; never replaces user command metadata. */
final class AutomaticCommandResult
{
    public function __construct(public GameState $state, public bool $certain, public string $reason) {}
}
