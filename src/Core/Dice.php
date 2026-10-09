<?php
// src/Core/Dice.php

declare(strict_types=1);

namespace Berserk\Core;

final class Dice
{
    private const SESSION_STATE_KEY = 'debug_roll_state';

    private static array $queue = [];
    private static bool  $active = false;
    private static bool  $infinite = false;

    /** Вызывается один раз при загрузке запроса (www/index.php). */
    public static function init(): void
    {
        self::resetRuntime();

        if (empty($_SESSION['debug_roll'])) {
            unset($_SESSION[self::SESSION_STATE_KEY]);
            return;
        }

        $raw = trim((string) $_SESSION['debug_roll']);
        if ($raw === '' || $raw === 'off') {
            unset($_SESSION[self::SESSION_STATE_KEY]);
            return;
        }

        $state = $_SESSION[self::SESSION_STATE_KEY] ?? null;
        if (self::isValidState($state, $raw)) {
            self::restoreState($state);
            return;
        }

        // "6,1,4,4" → [6,1,4,4]
        // "6*"        → бесконечный 6
        if (preg_match('/^([1-6])\*$/', $raw, $m)) {
            self::$queue  = [(int) $m[1]];
            self::$active = true;
            self::$infinite = true;
            self::saveState($raw);
            return;
        }

        $parts = array_map('intval', explode(',', $raw));
        $parts = array_values(array_filter($parts, fn($v) => $v >= 1 && $v <= 6));
        if (empty($parts)) {
            unset($_SESSION[self::SESSION_STATE_KEY]);
            return;
        }

        self::$queue  = $parts;
        self::$active = true;
        self::saveState($raw);
    }

    public static function roll(): int
    {
        if (self::$active && !empty(self::$queue)) {
            if (self::$infinite) {
                return self::$queue[0];
            }

            $v = array_shift(self::$queue);
            self::$active = !empty(self::$queue);
            self::saveState(trim((string) ($_SESSION['debug_roll'] ?? '')));
            return $v;
        }
        return random_int(1, 6);
    }

    public static function remaining(): array
    {
        return self::$queue;
    }

    private static function isInfiniteMode(): bool
    {
        return self::$infinite;
    }

    private static function resetRuntime(): void
    {
        self::$queue  = [];
        self::$active = false;
        self::$infinite = false;
    }

    private static function isValidState(mixed $state, string $raw): bool
    {
        return is_array($state)
            && ($state['config'] ?? null) === $raw
            && in_array(($state['mode'] ?? null), ['once', 'infinite'], true)
            && isset($state['queue'])
            && is_array($state['queue']);
    }

    private static function restoreState(array $state): void
    {
        self::$queue = array_values(array_filter(
            array_map('intval', $state['queue']),
            fn($v) => $v >= 1 && $v <= 6
        ));
        self::$infinite = ($state['mode'] ?? null) === 'infinite';
        self::$active = self::$infinite || !empty(self::$queue);
    }

    private static function saveState(string $raw): void
    {
        if ($raw === '') return;

        $_SESSION[self::SESSION_STATE_KEY] = [
            'config' => $raw,
            'mode'   => self::isInfiniteMode() ? 'infinite' : 'once',
            'queue'  => self::$queue,
        ];
    }
}
