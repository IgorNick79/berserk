<?php
declare(strict_types=1);

namespace Berserk\Core;

/** Read-only gameplay projection. Raw prop/modifiers remain the persistence source. */
final class EffectiveFeatures
{
    public const MEMORY_LOSS = 'memory_loss_sources';

    public static function onBattlefield(CardInstance $card): bool
    {
        // Dying cards still occupy the battlefield until ZoneManager moves them.
        return in_array($card->zone, [CardInstance::ZONE_FIELD, CardInstance::ZONE_FLYING], true);
    }

    /** Stitched loss belongs to the recipient; only memory loss follows a source link. */
    public static function suppressed(CardInstance $card): bool
    {
        return self::onBattlefield($card) && (!empty($card->flags[self::MEMORY_LOSS]) || !empty($card->flags['stitched_unique_loss']));
    }

    public static function prop(CardInstance $card): array
    {
        if (!self::suppressed($card)) return $card->prop;
        $prop = array_intersect_key($card->prop, array_fill_keys(PermanentFeatures::KEYS, true));
        $armor = $card->prop['armor'] ?? null;
        if (is_array($armor) && !empty($armor['permanent'])
            && !array_diff(array_keys($armor), ['value', 'permanent'])) $prop['armor'] = $armor;
        return $prop;
    }

    public static function modifiers(CardInstance $card): array
    {
        if (!self::suppressed($card)) return $card->modifiers;
        // Modifier stat is the feature being granted. Internal aliases (e.g.
        // ability_strike) are unique as well. Preserve indices for indexed readers.
        return array_filter($card->modifiers, static fn(array $m): bool =>
            PermanentFeatures::contains((string) ($m['stat'] ?? '')));
    }

    /** Infrastructure API for effects; Stage 2B supplies payment and targeting. */
    public static function applyMemoryLoss(GameState $state, CardInstance $source, CardInstance $target): bool
    {
        if ($state->getCard($source->instanceId) !== $source
            || $state->getCard($target->instanceId) !== $target
            || !self::onBattlefield($source) || !self::onBattlefield($target)) return false;
        $wasSuppressed = self::suppressed($target);
        $target->flags[self::MEMORY_LOSS][$source->instanceId] = true;
        if (!$wasSuppressed) {
            (new DamageResolver($state))->refreshArmor();
            Strangulation::refresh($state);
        }
        return true;
    }

    /** Called immediately after the actual zone assignment, never at hp=0/dying. */
    public static function leftBattlefield(GameState $state, CardInstance $leaving): void
    {
        if (self::onBattlefield($leaving)) return;
        $changed = false;
        unset($leaving->flags[self::MEMORY_LOSS]);
        foreach ($state->cards as $target) {
            if (!isset($target->flags[self::MEMORY_LOSS][$leaving->instanceId])) continue;
            $changed = true;
            unset($target->flags[self::MEMORY_LOSS][$leaving->instanceId]);
            if (!$target->flags[self::MEMORY_LOSS]) unset($target->flags[self::MEMORY_LOSS]);
        }
        if ($changed) (new DamageResolver($state))->refreshArmor();
    }
}
