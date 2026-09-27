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
     * @return array{gold_left:int, silver_left:int, gold_total:int, silver_total:int, elite_gold_bonus:int, gold_spent:int, silver_spent:int, silver_extra_spent:int, silver_extra_left:int, silver_extra_affordable:bool, elements_count:int, elements:array<string,int>, penalty:int}
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
        $silverExtraSpent = 0;
        $eliteGoldBonus = 0;
        $elements    = [];

        foreach ($state->cards as $card) {
            if ($card->owner !== $playerKey) continue;
            if ($card->zone !== CardInstance::ZONE_SQUAD) continue;
            if ($removing && $card->instanceId === $removing->instanceId) continue;

            if ($card->element !== 'neutral' && $card->element !== '') {
                $elements[$card->element] = ($elements[$card->element] ?? 0) + 1;
            }

            $eliteGoldBonus += self::dealEliteGoldBonus($card);
        }

        foreach ($state->cards as $card) {
            if ($card->owner !== $playerKey) continue;
            if ($card->zone !== CardInstance::ZONE_SQUAD) continue;
            if ($removing && $card->instanceId === $removing->instanceId) continue;

            self::addRecruitCost($state, $playerKey, $card, $goldSpent, $silverSpent, $silverExtraSpent);
        }

        if ($adding) {
            self::addRecruitCost($state, $playerKey, $adding, $goldSpent, $silverSpent, $silverExtraSpent);
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
        $silverExtraLeft = $silverTotal - $silverSpent;
        $silverExtraAffordable = $silverExtraSpent === 0 || $silverExtraLeft >= 0;

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
            'silver_extra_spent' => $silverExtraSpent,
            'silver_extra_left' => $silverExtraLeft,
            'silver_extra_affordable' => $silverExtraAffordable,
            'elements_count' => $elementsCount,
            'elements'       => $elements,
            'penalty'        => $penalty,
        ];
    }

    public static function effectiveRecruitCost(GameState $state, string $playerKey, CardInstance $candidate): int
    {
        return self::effectiveBaseRecruitCostAfterDealDiscount($state, $playerKey, $candidate)
            + self::dealExtraRecruitCost($candidate);
    }

    private static function effectiveBaseRecruitCost(GameState $state, string $playerKey, CardInstance $candidate): int
    {
        $baseCost = max(0, $candidate->price);
        $requiredCosts = self::freeIfSquadHasCosts($candidate);
        if (empty($requiredCosts)) {
            return $baseCost;
        }

        $presentCosts = [];
        foreach ($state->cards as $card) {
            if ($card->owner !== $playerKey) continue;
            if ($card->zone !== CardInstance::ZONE_SQUAD) continue;
            if ($card->instanceId === $candidate->instanceId) continue;

            $presentCosts[(int) $card->price] = true;
        }

        foreach ($requiredCosts as $cost) {
            if (!isset($presentCosts[$cost])) {
                return $baseCost;
            }
        }

        return 0;
    }

    private static function dealEliteGoldBonus(CardInstance $card): int
    {
        return max(0, (int) ($card->prop['deal']['resource_modifier']['elite_gold'] ?? 0));
    }

    private static function dealExtraRecruitCost(CardInstance $card): int
    {
        return max(0, (int) ($card->flags['deal_variable_recruit']['extra_cost'] ?? 0));
    }

    private static function dealExtraRecruitResource(CardInstance $card): string
    {
        return (string) ($card->flags['deal_variable_recruit']['resource'] ?? 'elite_gold');
    }

    private static function dealScoutSilverDiscount(CardInstance $card): int
    {
        if (($card->flags['deal_scout_recruit']['discount_resource'] ?? null) !== 'silver') {
            return 0;
        }

        return max(0, (int) ($card->flags['deal_scout_recruit']['silver_discount'] ?? 0));
    }

    private static function effectiveBaseRecruitCostAfterDealDiscount(
        GameState $state,
        string $playerKey,
        CardInstance $card,
    ): int {
        $baseCost = self::effectiveBaseRecruitCost($state, $playerKey, $card);
        if ($card->elite) {
            return $baseCost;
        }

        return max(0, $baseCost - self::dealScoutSilverDiscount($card));
    }

    private static function addRecruitCost(
        GameState $state,
        string $playerKey,
        CardInstance $card,
        int &$goldSpent,
        int &$silverSpent,
        int &$silverExtraSpent,
    ): void {
        $baseCost = self::effectiveBaseRecruitCostAfterDealDiscount($state, $playerKey, $card);
        if ($card->elite) {
            $goldSpent += $baseCost;
        } else {
            $silverSpent += $baseCost;
        }

        $extraCost = self::dealExtraRecruitCost($card);
        if ($extraCost <= 0) {
            return;
        }

        if (self::dealExtraRecruitResource($card) === 'silver') {
            $silverSpent += $extraCost;
            $silverExtraSpent += $extraCost;
            return;
        }

        $goldSpent += $extraCost;
    }

    /**
     * @return int[]
     */
    private static function freeIfSquadHasCosts(CardInstance $card): array
    {
        $required = $card->prop['deal']['cost_modifier']['free_if_squad_has_costs'] ?? [];
        if (!is_array($required)) {
            return [];
        }

        $costs = [];
        foreach ($required as $cost) {
            if (is_numeric($cost)) {
                $costs[] = max(0, (int) $cost);
            }
        }

        return array_values(array_unique($costs));
    }
}
