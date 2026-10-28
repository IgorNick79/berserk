<?php
declare(strict_types=1);

namespace Berserk\Core;

/** Active impact availability and its per-instance battle limit, not suppression. */
final class MemoryLoss
{
    public const USED = 'memory_loss_used';

    public static function isAction(array $action): bool
    {
        return ($action['type'] ?? '') === 'impact' && ($action['effect'] ?? '') === 'memory_loss';
    }

    public static function available(CardInstance $source): bool
    {
        return EffectiveFeatures::onBattlefield($source) && !$source->dying && $source->hp > 0
            && !CardStats::isDisabled($source) && !EffectiveFeatures::suppressed($source)
            && $source->coins >= 1 && empty($source->flags[self::USED]);
    }

    /** Keep this battle usage when existing zone/return paths clear transient flags. */
    public static function persistentFlags(CardInstance $card): array
    {
        return array_intersect_key($card->flags, [Tremor::USED => true, self::USED => true, 'stitch_revive_used' => true, 'stitched_unique_loss' => true]);
    }
}
