<?php
declare(strict_types=1);

namespace Berserk\Core;

/** Rules classification, independent of a property's value or activation conditions. */
final class PermanentFeatures
{
    public const KEYS = [
        'direct', 'zom', 'zoz', 'zor', 'zot', 'zov', 'zoal', 'zoo',
        'regeneration', 'ova', 'ovz', 'stamina', 'ovs',
    ];

    public static function contains(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }
}
