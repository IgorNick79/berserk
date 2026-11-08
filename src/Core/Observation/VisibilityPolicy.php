<?php
declare(strict_types=1);

namespace Berserk\Core\Observation;

use Berserk\Core\{CardInstance, GameState, PathfinderProcessor};

final class VisibilityPolicy
{
    public static function card(GameState $state, CardInstance $card, string $viewer): string
    {
        if (!self::isSide($viewer) || !self::isSide($card->owner)) {
            return 'not_applicable';
        }

        $own = $card->owner === $viewer;
        if ($state->status === 'place' && $own && $card->zone === CardInstance::ZONE_SQUAD) {
            return 'known';
        }
        if ($state->status === 'place' && $card->zone === CardInstance::ZONE_FIELD) {
            if ($own) return 'known';
            $pathfinder = PathfinderProcessor::active($state);
            $frontRow = $viewer === GameState::PLAYER_HOST ? 4 : 3;
            if ($pathfinder !== null && (!empty($card->flags['pathfinder_revealed'])
                || (!empty($pathfinder['revealed_to'][$viewer]) && $card->row === $frontRow))) {
                return 'known';
            }
        }

        if ($state->status === 'battle') {
            if (in_array($card->zone, [CardInstance::ZONE_FIELD, CardInstance::ZONE_FLYING], true)) {
                return $own || $card->revealed ? 'known' : 'unknown';
            }
            if (in_array($card->zone, [CardInstance::ZONE_GRAVEYARD, CardInstance::ZONE_EXILE], true)) {
                return 'known';
            }
        }

        return 'not_applicable';
    }

    public static function isSide(mixed $side): bool
    {
        return in_array($side, [GameState::PLAYER_HOST, GameState::PLAYER_PLAYER], true);
    }
}
