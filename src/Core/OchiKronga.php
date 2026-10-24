<?php
declare(strict_types=1);
namespace Berserk\Core;

/** One card ability. Consumes resolution boundaries synchronously, never diagnostic history. */
final class OchiKronga
{
    public const PROP = 'on_ally_action_sequence_open';
    public const STATE = 'ochi_kronga';

    public static function leftPlay(CardInstance $source): void
    {
        unset($source->flags[self::STATE]);
    }

    private static function live(CardInstance $source): bool
    {
        return in_array($source->zone, [CardInstance::ZONE_FIELD, CardInstance::ZONE_FLYING], true)
            && !$source->dying && $source->hp > 0;
    }

    private static function enabled(CardInstance $source): bool
    {
        $config = $source->prop[self::PROP] ?? null;
        return is_array($config) && ($config['filter'] ?? null) === 'forests';
    }

    private static function period(GameState $state, string $owner): ?string
    {
        if ($state->status !== 'battle' || $state->winner !== null || ($state->battle['active'] ?? null) !== $owner) return null;
        $timer = $state->battle['turn_timer'] ?? null;
        if ($timer !== null) {
            // active switches before the old end phase has resolved. Its clock still owns that phase.
            if (($timer['owner'] ?? null) !== $owner) return null;
            return $owner . ':timer:' . (int)$timer['period_id'];
        }
        // Legacy/isolated states without a timer still distinguish both players' turns.
        return $owner . ':turn:' . (int)($state->battle['turn'] ?? 0);
    }

    private static function initialize(CardInstance $source, string $period, int $lastSequence = 0): void
    {
        if (($source->flags[self::STATE]['period'] ?? null) === $period) return;
        $source->flags[self::STATE] = ['period'=>$period, 'sequence'=>[], 'uses'=>0, 'last_sequence'=>$lastSequence];
    }

    /** Called only after the native turn lifecycle has allocated its persisted period. */
    public static function startOwnTurn(GameState $state, string $owner): void
    {
        $period = self::period($state, $owner);
        if ($period === null) return;
        foreach ($state->cards as $source) {
            if (!self::live($source)) { self::leftPlay($source); continue; }
            if ($source->owner === $owner && self::enabled($source)) {
                self::initialize($source, $period, (int)($state->battle[ActionResolution::KEY]['sequence'] ?? 0));
            }
        }
    }

    /** null = ignore, one of the three names = candidate, other = interrupting independent action. */
    private static function classify(array $event): ?string
    {
        $type = (string)($event['type'] ?? '');
        if (in_array($event['outcome'] ?? '', ['miss','blocked','cancelled','no_effect'], true)) return null;
        if (in_array($type, ['move','jump','teleport','teleport_target','forced_move'], true)) return null;
        if ($type === 'answer' || ($event['origin'] ?? '') === 'response') return null;
        if (in_array($event['origin'] ?? '', ['technical','system'], true)) return null;
        if ($type === 'heal') return (int)($event['hp_restored'] ?? 0) > 0 ? 'heal' : null;
        if (in_array($type, ['strike','shot'], true)) return ($event['hit'] ?? false) === true ? $type : null;
        // bomb_shot retains its own native type. It is not silently promoted to shot.
        if ($type === 'bomb_shot') return ($event['hit'] ?? false) === true ? 'other' : null;
        // Other passive consequences are not independent actions. Positive passive healing was handled above.
        if (($event['origin'] ?? '') === 'passive') return null;
        return ($event['outcome'] ?? '') === 'performed' || ($event['outcome'] ?? '') === 'hit' ? 'other' : null;
    }

    public static function onResolution(GameState $state, array $event): void
    {
        $kind = self::classify($event);
        foreach ($state->cards as $source) {
            if (!self::live($source) || !self::enabled($source)) { self::leftPlay($source); continue; }
            $period = self::period($state, $source->owner);
            if ($period === null) continue;
            self::initialize($source, $period);
            $memory = &$source->flags[self::STATE];
            $sequence = (int)($event['sequence'] ?? 0);
            if ($sequence <= $memory['last_sequence']) { unset($memory); continue; }
            $memory['last_sequence'] = $sequence;
            if ($kind === null) { unset($memory); continue; }
            $eligible = $kind !== 'other' && (int)($event['actor_id'] ?? 0) !== $source->instanceId
                && ($event['owner'] ?? null) === $source->owner
                && in_array($event['actor_type'] ?? '', ['creature','fly'], true)
                && ($event['element'] ?? null) === 'forests';
            if (!$eligible) { $memory['sequence'] = []; unset($memory); continue; }
            if (in_array($kind, $memory['sequence'], true)) $memory['sequence'] = [];
            $memory['sequence'][] = $kind;
            if (count($memory['sequence']) === 3) {
                $memory['sequence'] = [];
                if ($memory['uses'] < 2 && $source->closed) {
                    ++$memory['uses'];
                    // Automatic consequence, not a new independent action. No recursive notification.
                    (new Engine())->openCard($source);
                    $state->historyRecorder?->liveEvent($state, 'card.status',
                        ['target_id'=>$source->instanceId, 'closed'=>false, 'effect'=>'open', '_private_owner'=>$source->owner],
                        [$source->instanceId]);
                }
            }
            unset($memory);
        }
    }
}
