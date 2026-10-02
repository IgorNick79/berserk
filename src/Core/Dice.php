<?php
// src/Core/Dice.php

declare(strict_types=1);

namespace Berserk\Core;

final class Dice
{
    private static array $queue = [];
    private static bool  $active = false;

    /** Вызывается один раз при загрузке запроса (www/index.php). */
    public static function init(): void
    {
        self::$queue  = [];
        self::$active = false;

        if (empty($_SESSION['debug_roll'])) return;

        $raw = trim((string) $_SESSION['debug_roll']);
        if ($raw === '' || $raw === 'off') return;

        // "6,1,4,4" → [6,1,4,4]
        // "6*"        → бесконечный 6
        if (preg_match('/^([1-6])\*$/', $raw, $m)) {
            self::$queue  = [(int) $m[1]];
            self::$active = true;
            return;
        }

        $parts = array_map('intval', explode(',', $raw));
        $parts = array_values(array_filter($parts, fn($v) => $v >= 1 && $v <= 6));
        if (empty($parts)) return;

        self::$queue  = $parts;
        self::$active = true;
    }

    public static function roll(): int
    {
        if (self::$active && !empty(self::$queue)) {
            $v = array_shift(self::$queue);
            // "6*" — оставляем значение в очереди навсегда
            if (empty(self::$queue) && self::$active && self::isInfiniteMode()) {
                self::$queue = [$v];
            }
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
        return !empty($_SESSION['debug_roll'])
            && str_ends_with(trim((string) $_SESSION['debug_roll']), '*');
    }
}