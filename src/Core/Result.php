<?php
// src/Core/Result.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Результат применения команды.
 * Либо успех (с событиями), либо ошибка (с причиной).
 */
final class Result
{
    /**
     * @param string[] $events
     */
    private function __construct(
        public bool $success,
        public array $events = [],
        public ?string $error = null,
    ) {}

    public static function ok(array $events = []): self
    {
        return new self(true, $events, null);
    }

    public static function error(string $message): self
    {
        return new self(false, [], $message);
    }
}