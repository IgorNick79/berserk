<?php
declare(strict_types=1);
namespace Berserk\Core\Observation;

use Berserk\Core\{ActionResolution,CardInstance,EffectiveFeatures,GameState};

/** Free adjacent fixed magic only. Recognition never depends on a catalogue identity or key. */
final class BasicMagic
{
    public static function profiles(GameState $state, CardInstance $source): array
    {
        if ($source->zone !== CardInstance::ZONE_FIELD || EffectiveFeatures::suppressed($source)) return [];
        $prop = $source->effectiveProp();
        if (!empty($prop['coins']['magic'])) return [];
        foreach ($source->flags as $key => $value) {
            if (str_starts_with((string)$key, 'granted_action:') && ($value['type'] ?? null) === 'magic') return [];
        }
        // Results do not persist the action key. Keep their provenance unambiguous too.
        $magic = array_filter($prop['actions'] ?? [], static fn($a) => ($a['type'] ?? null) === 'magic');
        if (count($magic) !== 1) return [];
        $seen = [];
        foreach ($prop['actions'] ?? [] as $action) {
            $key = $action['key'] ?? $action['type'] ?? null;
            if (!is_string($key) || $key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            if (($action['type'] ?? null) !== 'magic'
                || array_diff(array_keys($action), ['key','name','type','value']) !== []
                || !is_int($action['value'] ?? null) || $action['value'] < 0) continue;
            return [['action_key'=>$key, 'type'=>'basic_magic', 'value'=>$action['value'],
                'distance_rule'=>'adjacent_ground', 'resource_cost'=>0, 'closes_source'=>true,
                'contextual_effects'=>'unknown']];
        }
        return [];
    }

    /** Conservative first coverage: no hidden providers, redirects or extra choice-producing traits. */
    public static function knownContext(GameState $state, string $viewer): bool
    {
        if ($state->cell_markers !== []) return false;
        foreach ($state->cards as $card) {
            if (VisibilityPolicy::card($state,$card,$viewer) === 'unknown') return false;
        }
        foreach ($state->cards as $card) {
            if (!in_array($card->zone, [CardInstance::ZONE_FIELD,CardInstance::ZONE_FLYING], true)) continue;
            $prop = $card->effectiveProp();
            if (array_diff(array_keys($prop), ['actions','zoa','zoan','zoal','zoz','zor','zom','zoda','zov','zot','zoo',
                'stamina','direct','strike','regeneration','save_coins','coins','armor']) !== []) return false;
            if (isset($prop['strike']) && !is_array($prop['strike'])) return false;
            if (array_diff(array_keys($prop['strike'] ?? []), ['opposite']) !== []) return false;
            // Discharge and storage do not create a magic coin-spend/block window.
            if (isset($prop['coins']) && !is_array($prop['coins'])) return false;
            if (array_diff(array_keys($prop['coins'] ?? []), ['max_value','discharge']) !== []) return false;
        }
        return true;
    }

    /** Only a resolved ordinary root result; BattleWaitResolver remains the authority for priority/owner. */
    public static function resultAck(GameState $state, string $viewer): bool
    {
        $strike = $state->battle['strike'] ?? [];
        if (($strike['kind'] ?? null) !== 'magic' || ($strike['state'] ?? null) !== 'results'
            || !empty($strike['pending_wounds_resolution']) || !empty($strike['continuation_frame'])
            || isset($strike['instant_result_stage']) || !self::knownContext($state,$viewer)) return false;
        $source = $state->getCard((int)($strike['attacker_id'] ?? 0));
        $target = $state->getCard((int)($strike['target_id'] ?? 0));
        if ($source === null || $target === null || self::profiles($state,$source) === []
            || !in_array($target->zone,[CardInstance::ZONE_FIELD,CardInstance::ZONE_GRAVEYARD],true)
            || $target->type==='fly' || $source->owner === $target->owner) return false;
        $token = $state->battle[ActionResolution::KEY]['tokens'][$strike[ActionResolution::ID] ?? 0] ?? [];
        return ($token['type'] ?? null) === 'magic' && ($token['origin'] ?? null) === 'ordinary'
            && ($token['actor_id'] ?? null) === $source->instanceId
            && ($token['parent_id'] ?? null) === null && ($token['delivered'] ?? false) === true;
    }
}
