<?php
// src/Core/BoosterSettings.php

declare(strict_types=1);

namespace Berserk\Core;

final class BoosterSettings
{
    public const BOOSTER_SIZE = 12;
    public const DEFAULT_COMMON = 8;
    public const DEFAULT_UNCOMMON = 3;
    public const DEFAULT_RARE_SLOTS = 1;
    public const DEFAULT_ULTRA_RARE_CHANCE = 10;

    /**
     * @return array{common:int, uncommon:int, rare_slots:int, ultra_rare_chance:int}
     */
    public static function defaults(): array
    {
        return [
            'common' => self::DEFAULT_COMMON,
            'uncommon' => self::DEFAULT_UNCOMMON,
            'rare_slots' => self::DEFAULT_RARE_SLOTS,
            'ultra_rare_chance' => self::DEFAULT_ULTRA_RARE_CHANCE,
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @return array{common:int, uncommon:int, rare_slots:int, ultra_rare_chance:int}
     */
    public static function normalize(array $config): array
    {
        $merged = array_merge(self::defaults(), $config);
        return [
            'common' => (int) $merged['common'],
            'uncommon' => (int) $merged['uncommon'],
            'rare_slots' => (int) $merged['rare_slots'],
            'ultra_rare_chance' => (int) $merged['ultra_rare_chance'],
        ];
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function validate(array $config): ?string
    {
        $merged = array_merge(self::defaults(), $config);

        $slots = [];
        foreach (['common', 'uncommon', 'rare_slots'] as $key) {
            $value = self::parseIntegerInRange($merged[$key] ?? null, 0, self::BOOSTER_SIZE);
            if ($value === null) {
                return 'Неверное распределение карт в бустере';
            }
            $slots[$key] = $value;
        }

        if ($slots['common'] + $slots['uncommon'] + $slots['rare_slots'] !== self::BOOSTER_SIZE) {
            return 'Сумма карт в бустере должна быть 12';
        }

        $chance = self::parseIntegerInRange($merged['ultra_rare_chance'] ?? null, 0, 100);
        if ($chance === null || $chance % 10 !== 0) {
            return 'Неверная вероятность редкости бустера';
        }

        return null;
    }

    public static function rareChanceToUltraChance(mixed $rareChance): ?int
    {
        $parsed = self::parseIntegerInRange($rareChance, 0, 100);
        if ($parsed === null || $parsed % 10 !== 0) {
            return null;
        }

        return 100 - $parsed;
    }

    private static function parseIntegerInRange(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            return $value >= $min && $value <= $max ? $value : null;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');
        $digits = $digits === '' ? '0' : $digits;
        if ($negative) {
            if ($min >= 0) {
                return null;
            }
            $limit = (string) abs($min);
        } else {
            $limit = (string) $max;
        }
        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            return null;
        }

        $parsed = (int) $value;
        return $parsed >= $min && $parsed <= $max ? $parsed : null;
    }
}
