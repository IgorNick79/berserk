<?php
// src/Core/Prepare/DraftCopyRules.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

use Berserk\Core\CardInstance;

final class DraftCopyRules
{
    public const NORMAL_DECK_LIMIT = 3;
    public const HORDE_DECK_LIMIT = 5;
    public const NORMAL_POOL_LIMIT = 6;
    public const HORDE_POOL_LIMIT = 10;

    public static function isHordeProp(mixed $prop): bool
    {
        if (is_string($prop)) {
            $decoded = json_decode($prop, true);
            $prop = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($prop)) return false;

        $value = $prop['horde'] ?? false;
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value === 1;
        if (is_string($value)) return in_array(strtolower($value), ['1', 'true', 'yes'], true);

        return false;
    }

    public static function deckLimit(array|CardInstance|null $card): int
    {
        return self::isHordeCard($card) ? self::HORDE_DECK_LIMIT : self::NORMAL_DECK_LIMIT;
    }

    public static function poolLimit(array|CardInstance|null $card): int
    {
        return self::isHordeCard($card) ? self::HORDE_POOL_LIMIT : self::NORMAL_POOL_LIMIT;
    }

    /**
     * @param string[] $existing
     * @param string[] $adding
     * @param array<string,array<string,mixed>|CardInstance> $cardsByUkid
     */
    public static function firstDeckLimitViolation(array $existing, array $adding, array $cardsByUkid): ?array
    {
        $counts = [];
        foreach ($existing as $ukid) {
            $counts[(string) $ukid] = ($counts[(string) $ukid] ?? 0) + 1;
        }
        foreach ($adding as $ukid) {
            $ukid = (string) $ukid;
            $counts[$ukid] = ($counts[$ukid] ?? 0) + 1;
            $card = $cardsByUkid[$ukid] ?? null;
            $limit = self::deckLimit($card);
            if ($counts[$ukid] > $limit) {
                return [
                    'ukid' => $ukid,
                    'name' => self::cardName($card, $ukid),
                    'count' => $counts[$ukid],
                    'limit' => $limit,
                ];
            }
        }

        return null;
    }

    /**
     * @param string[] $ukids
     * @param array<string,array<string,mixed>|CardInstance> $cardsByUkid
     */
    public static function firstDeckCountViolation(array $ukids, array $cardsByUkid): ?array
    {
        return self::firstDeckLimitViolation([], $ukids, $cardsByUkid);
    }

    public static function violationMessage(array $violation): string
    {
        if (isset($violation['error'])) {
            return (string) $violation['error'];
        }

        return sprintf(
            'Слишком много копий карты %s: %d из %d',
            (string) ($violation['name'] ?? $violation['ukid'] ?? '?'),
            (int) ($violation['count'] ?? 0),
            (int) ($violation['limit'] ?? 0)
        );
    }

    private static function isHordeCard(array|CardInstance|null $card): bool
    {
        if ($card instanceof CardInstance) {
            return self::isHordeProp($card->prop);
        }
        if (is_array($card)) {
            return self::isHordeProp($card['prop'] ?? []);
        }

        return false;
    }

    private static function cardName(array|CardInstance|null $card, string $ukid): string
    {
        if (is_array($card) && !empty($card['name'])) {
            return (string) $card['name'];
        }

        return $ukid;
    }
}
