<?php
// src/Core/Filter/TargetFilter.php

declare(strict_types=1);

namespace Berserk\Core\Filter;

use Berserk\Core\GameState;
use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;

/**
 * Фильтры кандидатов для choice-окон.
 * Все методы возвращают замыкание CardInstance → bool.
 */
final class TargetFilter
{
    /** На поле (field или flying), не dying, hp > 0 */
    public static function alive(): \Closure
    {
        return fn(CardInstance $c) =>
            ($c->zone === CardInstance::ZONE_FIELD
                || $c->zone === CardInstance::ZONE_FLYING)
            && !$c->dying
            && $c->hp > 0;
    }

    /** Только на field */
    public static function onField(): \Closure
    {
        return fn(CardInstance $c) => $c->zone === CardInstance::ZONE_FIELD;
    }

    /** Враги владельца */
    public static function enemies(string $ownerKey): \Closure
    {
        return fn(CardInstance $c) => $c->owner !== $ownerKey;
    }

    /** Союзники владельца */
    public static function allies(string $ownerKey): \Closure
    {
        return fn(CardInstance $c) => $c->owner === $ownerKey;
    }

    /** Кроме указанных instance_id */
    public static function exclude(array $ids): \Closure
    {
        return fn(CardInstance $c) => !in_array($c->instanceId, $ids, true);
    }

    /** Условие из prop: target_not_moved, own_yordling, ... */
    public static function hasCondition(string $cond): \Closure
    {
        return function (CardInstance $c) use ($cond) {
            return match ($cond) {
                'target_not_moved' => empty($c->flags['moved_this_turn']),
                'own_yordling'     => CardStats::hasClass($c, 'Йордлинг'),
                default            => true,
            };
        };
    }

    /** Соседи карты (8 клеток) */
    public static function near(CardInstance $card): \Closure
    {
        return function (CardInstance $c) use ($card) {
            if ($c->zone !== CardInstance::ZONE_FIELD) return false;
            if ($card->zone !== CardInstance::ZONE_FIELD) return false;
            $dr = abs($c->row - $card->row);
            $dc = abs($c->col - $card->col);
            return $dr <= 1 && $dc <= 1 && ($dr + $dc) > 0;
        };
    }

    /** Класс */
    public static function class(string $class): \Closure
    {
        return fn(CardInstance $c) => CardStats::hasClass($c, $class);
    }

    /**
     * Применить набор фильтров к массиву карт.
     *
     * @param CardInstance[] $cards
     * @param \Closure[]     $filters
     * @return CardInstance[]
     */
    public static function apply(array $cards, array $filters): array
    {
        $result = [];
        foreach ($cards as $c) {
            foreach ($filters as $f) {
                if (!$f($c)) continue 2;
            }
            $result[] = $c;
        }
        return $result;
    }

    /**
     * Хелпер: собрать кандидатов из GameState.
     *
     * @param \Closure[] $filters
     * @return int[]  instance_id
     */
    public static function collect(GameState $state, array $filters): array
    {
        $cards = self::apply(array_values($state->cards), $filters);
        return array_map(fn(CardInstance $c) => $c->instanceId, $cards);
    }
}