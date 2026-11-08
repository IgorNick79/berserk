<?php
declare(strict_types=1);
namespace Berserk\Core\Legal;

use Berserk\Core\{CardInstance,CardStats};

/** Shared read-only source predicate. Target/turn/command certification remains in LegalActions. */
final class BasicGroundStrikeSource
{
    public static function supports(CardInstance $source):bool
    {
        if(!self::enabled($source) || !CardStats::hasAnyStrike($source))return false;
        $prop=$source->effectiveProp();
        if(!empty($prop['strike']['opposite']) || !empty($prop['attack_series']) || !empty($source->flags['attack_series_active']))return false;
        $limit=(int)($prop['attacks_per_turn'] ?? 1);
        if(!empty($prop['strike_consecutive']) && !empty($source->flags['strike_chain_broken']))$limit=1;
        return (int)($source->flags['attacks_used_this_turn'] ?? 0)<$limit;
    }
    public static function enabled(CardInstance $source):bool
    {
        return $source->zone===CardInstance::ZONE_FIELD && !$source->dying && $source->hp>0
            && !CardStats::isDisabled($source) && !CardStats::hasCannotAttack($source);
    }
}
