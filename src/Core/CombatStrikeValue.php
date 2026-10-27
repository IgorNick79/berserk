<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Ordered numeric changes, separate from table/strike levels and later setters. */
final class CombatStrikeValue
{
    public static function side(GameState $state, int $hitterId): ?string
    {
        $s = $state->battle['strike'] ?? [];
        if ((int) ($s['attacker_id'] ?? 0) === $hitterId) return 'attack';
        if ((int) (($s['defender_id'] ?? 0) ?: ($s['target_id'] ?? 0)) === $hitterId) return 'defend';
        return null;
    }

    public static function apply(array $strike, string $side, int $value): int
    {
        foreach ($strike['strike_value_effects'] ?? [] as $effect) {
            if (($effect['side'] ?? '') !== $side) continue;
            $value = ($effect['mode'] ?? 'add') === 'set' ? (int) $effect['value'] : $value + (int) $effect['value'];
            $value = max(0, $value);
        }
        return $value;
    }

    public static function current(GameState $state, string $side): ?int
    {
        $s = $state->battle['strike'] ?? [];
        $a = $state->getCard((int) ($s['attacker_id'] ?? 0));
        $d = $state->getCard((int) (($s['defender_id'] ?? 0) ?: ($s['target_id'] ?? 0)));
        $level = $s['result'][$side] ?? '';
        if (!$a || !$d || $level === '' || !EffectiveFeatures::onBattlefield($a) || !EffectiveFeatures::onBattlefield($d)
            || $a->dying || $d->dying || $a->hp <= 0 || $d->hp <= 0) return null;
        [$source,$target] = $side === 'attack' ? [$a,$d] : [$d,$a];
        if ($side === 'attack') $level = CardStats::reduceStrikeLevel($state, $d, $level);
        elseif (CardStats::hasHorizontalStrikeReduction($state, $a)) $level = 'weak';
        if ($level === '') return null;
        $stats = $side === 'attack' && !empty($source->effectiveProp()['copy_target_strike']) ? $target : $source;
        $value = match ($level) {'weak'=>$stats->strikeWeak, 'medium'=>$stats->strikeMedium, 'strong'=>$stats->strikeStrong, default=>0};
        $value += CardStats::getAbilityBonus($state,$source,$target,'strike',$level);
        if ($side === 'attack') $value += CardStats::getNextStrikeBonus($source);
        $value -= CardStats::getDamageReduction($state,$source,$target,'strike',$level);
        return self::apply($s,$side,max(0,$value));
    }
}
