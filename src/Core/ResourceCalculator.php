<?php
// src/Core/ResourceCalculator.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Расчёт ресурсов игрока.
 * Серебро может уходить в минус и занимать у золота.
 * Золото в минус не уходит — это ошибка.
 */
final class ResourceCalculator
{
    /**
     * Считает остатки с учётом карт в отряде.
     *
     * @param CardInstance|null $adding    Карта, которую хотим добавить (для проверки)
     * @param CardInstance|null $removing  Карта, которую хотим убрать
     * @return array{gold_left:int, silver_left:int, gold_total:int, silver_total:int, elite_gold_bonus:int, gold_spent:int, silver_spent:int, elements_count:int, elements:array<string,int>, penalty:int}
     */
    public static function compute(
        GameState $state,
        string $playerKey,
        ?CardInstance $adding = null,
        ?CardInstance $removing = null,
    ): array {
        $player = $state->getPlayer($playerKey);
        $baseGoldTotal = (int) ($player->resources['gold'] ?? 0);
        $silverTotal = (int) ($player->resources['silver'] ?? 0);

        $goldSpent   = 0;
        $silverSpent = 0;
        $eliteGoldBonus = 0;
        $elements    = [];

        foreach ($state->cards as $card) {
            if ($card->owner !== $playerKey) continue;
            if ($card->zone !== CardInstance::ZONE_SQUAD) continue;
            if ($removing && $card->instanceId === $removing->instanceId) continue;

            if ($card->elite) {
                $goldSpent += $card->price;
            } else {
                $silverSpent += $card->price;
            }

            if ($card->element !== 'neutral' && $card->element !== '') {
                $elements[$card->element] = ($elements[$card->element] ?? 0) + 1;
            }

            $eliteGoldBonus += self::dealEliteGoldBonus($card);
        }

        if ($adding) {
            if ($adding->elite) {
                $goldSpent += $adding->price;
            } else {
                $silverSpent += $adding->price;
            }
            if ($adding->element !== 'neutral' && $adding->element !== '') {
                $elements[$adding->element] = ($elements[$adding->element] ?? 0) + 1;
            }
        }

        // Штраф: 1 стихия — 0, 2 стихии — 1, 3 — 2, и т.д.
        $elementsCount = count($elements);
        $penalty = $elementsCount > 1 ? $elementsCount - 1 : 0;
        $goldSpent += $penalty;

        $goldTotal = $baseGoldTotal + $eliteGoldBonus;
        $goldLeft   = $goldTotal - $goldSpent;
        $silverLeft = $silverTotal - $silverSpent;

        if ($silverLeft < 0) {
            $goldLeft   += $silverLeft;
            $silverLeft = 0;
        }

        return [
            'gold_left'      => $goldLeft,
            'silver_left'    => $silverLeft,
            'gold_total'     => $goldTotal,
            'silver_total'   => $silverTotal,
            'elite_gold_bonus' => $eliteGoldBonus,
            'gold_spent'     => $goldSpent,
            'silver_spent'   => $silverSpent,
            'elements_count' => $elementsCount,
            'elements'       => $elements,
            'penalty'        => $penalty,
        ];
    }

    private static function dealEliteGoldBonus(CardInstance $card): int
    {
        return max(0, (int) ($card->prop['deal']['resource_modifier']['elite_gold'] ?? 0));
    }
}
