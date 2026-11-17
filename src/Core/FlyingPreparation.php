<?php
declare(strict_types=1);
namespace Berserk\Core;
final class FlyingPreparation
{
    public const FLAG = 'flying_preparation';
    public static function onlyFlying(GameState $s, CardInstance $source): bool
    {
        $found = false;
        foreach ($s->cards as $c) {
            if ($c->owner === $source->owner || $c->dying || $c->hp <= 0) continue;
            if ($c->zone === CardInstance::ZONE_FIELD) return false;
            if ($c->zone === CardInstance::ZONE_FLYING) $found = true;
        }
        return $found;
    }
    public static function ready(GameState $s, CardInstance $c): bool
    {
        return $c->zone === CardInstance::ZONE_FIELD && !$c->dying && $c->hp > 0
            && ($c->flags[self::FLAG] ?? null) === 'ready'
            && ($s->battle['active'] ?? null) === $c->owner && self::onlyFlying($s, $c);
    }
    public static function canReach(GameState $s, CardInstance $c): bool
    {
        return CardStats::canAttackFlying($s, $c) || self::ready($s, $c);
    }
    public static function canPrepare(GameState $s, CardInstance $c): bool
    {
        return $s->status === 'battle' && ($s->battle['active'] ?? null) === $c->owner
            && $c->zone === CardInstance::ZONE_FIELD && !$c->dying && $c->hp > 0
            && !CardStats::isDisabled($c) && !CardStats::hasCannotAttack($c)
            && CardStats::hasAnyStrike($c) && !CardStats::canAttackFlying($s, $c)
            && !isset($c->flags[self::FLAG]) && self::onlyFlying($s, $c)
            && CardStats::getForcedStrikeTarget($s, $c) === null;
    }
    public static function changeTurn(GameState $s, string $ending, string $next): void
    {
        foreach ($s->cards as $c) {
            if ($c->owner === $ending && ($c->flags[self::FLAG] ?? null) === 'ready') unset($c->flags[self::FLAG]);
            if ($c->owner === $next && ($c->flags[self::FLAG] ?? null) === 'waiting') $c->flags[self::FLAG] = 'ready';
        }
    }
}
