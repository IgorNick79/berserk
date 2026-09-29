<?php
// src/Core/Prepare/SquadRules.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

use Berserk\Core\CardInstance;
use Berserk\Core\GameState;

final class SquadRules
{
    public const FLYING_COST_LIMIT = 15;

    /**
     * @param CardInstance[] $additions
     */
    public static function validate(GameState $state, string $playerKey, array $additions = []): ?string
    {
        $squad = self::resultingSquad($state, $playerKey, $additions);

        if (self::flyingCostForCards($squad) > self::FLYING_COST_LIMIT) {
            return 'Стоимость летающих в отряде превысит ' . self::FLYING_COST_LIMIT . ' кристаллов';
        }

        $seenUnique = [];
        foreach ($squad as $card) {
            if (!$card->single) {
                continue;
            }
            if (isset($seenUnique[$card->ukid])) {
                return 'Эта уникальная карта уже есть в отряде';
            }
            $seenUnique[$card->ukid] = true;
        }

        // TODO: terrain cards — enforce max 1 terrain card per squad
        // when terrain card type is introduced/supported.

        return null;
    }

    public static function flyingCost(GameState $state, string $playerKey): int
    {
        return self::flyingCostForCards(self::resultingSquad($state, $playerKey));
    }

    /**
     * @param CardInstance[] $cards
     */
    private static function flyingCostForCards(array $cards): int
    {
        $total = 0;
        foreach ($cards as $card) {
            if ($card->type === 'fly') {
                $total += max(0, $card->price);
            }
        }
        return $total;
    }

    /**
     * @param CardInstance[] $additions
     * @return CardInstance[]
     */
    private static function resultingSquad(GameState $state, string $playerKey, array $additions = []): array
    {
        $squad = [];
        foreach ($state->cards as $card) {
            if ($card->owner === $playerKey && $card->zone === CardInstance::ZONE_SQUAD) {
                $squad[$card->instanceId] = $card;
            }
        }

        foreach ($additions as $card) {
            $squad[$card->instanceId] = $card;
        }

        return array_values($squad);
    }
}
