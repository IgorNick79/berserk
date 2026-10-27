<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Materialized HP/max-HP delta: changing health preserves wounds, never heals. */
final class TemporaryHealth
{
    public static function isAction(array $action): bool
    {
        return ($action['type'] ?? '') === 'cast' && ($action['effect'] ?? '') === 'temporary_health';
    }

    public static function legal(GameState $state, CardInstance $source, CardInstance $target, array $action): bool
    {
        return EffectiveFeatures::onBattlefield($target) && !$target->dying && $target->hp > 0
            && in_array($target->type, ['creature','fly'], true) && $target->owner !== $source->owner
            && (empty($action['no_attack_wounds_this_turn']) || empty($target->flags['attack_wounds_this_turn']))
            && !CardStats::hasDefense($state, $target, 'cast', $source);
    }

    public static function apply(CardInstance $target, int $value, string $owner): void
    {
        $target->hp += $value;
        $target->hpMax += $value;
        $target->modifiers[] = ['stat'=>'health', 'value'=>$value, 'expire'=>'end_of_turn',
            'source'=>$owner, 'materialized_health'=>true];
    }

    public static function remove(CardInstance $target, array $modifier): void
    {
        if (empty($modifier['materialized_health'])) return;
        $value = (int) $modifier['value'];
        $target->hpMax -= $value;
        if (EffectiveFeatures::onBattlefield($target) && !$target->dying && $target->hp > 0) $target->hp -= $value;
    }

    /** Zone reset discards modifiers, so restore max HP before they disappear. */
    public static function clear(CardInstance $target): void
    {
        foreach ($target->modifiers as $modifier) self::remove($target, $modifier);
        $target->modifiers = array_values(array_filter($target->modifiers,
            static fn(array $m): bool => empty($m['materialized_health'])));
    }
}
