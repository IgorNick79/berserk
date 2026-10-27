<?php
declare(strict_types=1);
namespace Berserk\Core;

/** Explicit admission policy only. Never changes a pending, advances a choice or opens a stack. */
final class FreeInstantWindows
{
    public const EXTERNAL = [
        'pending_coin_ability', 'pending_first_strike_jump', 'pending_coin_spend',
        'pending_cell_marker_pick', 'pending_cell_effect_direction', 'pending_swamp_queen_summon',
        'pending_ranged_attack_redirect', 'pending_incarnation_wound', 'pending_incarnation',
        'pending_valhalla_pick', 'pending_any_death', 'pending_whip', 'pending_kobold_heal',
        'pending_talion_incarnation', 'pending_holvert_open', 'pending_wound_transfer',
        'pending_multi_heal', 'pending_multi_discharge', 'pending_self_wound', 'pending_card_choice',
        'pending_intrigue_start', 'pending_dverg_gift', 'pending_shaksi_roll', 'pending_blood_tap',
        'pending_heal_instead_wound', 'pending_wild_healer_move', 'pending_revive', 'pending_grezy',
        'pending_forced_directional_move', 'pending_row_pick',
        'pending_opponent_row_marker', 'pending_row_spell_pick', 'pending_gate_pick', 'pending_greed_teleport',
        'pending_destroy_self_and_target', 'pending_strangulation', 'pending_particle_pick',
        'pending_blood_storm', 'pending_life_gift', 'pending_nokami_wound', 'pending_teleport_target',
        'pending_ranged_neighbor_teleport',
    ];

    private static function internal(GameState $s): bool
    {
        if (CombatContinuation::active($s)) return true;
        foreach (['turn_instant_stack', 'turn_instant_result', 'pending_instant_pick', 'pending_combat_pick',
            'pending_forced_strike_adjacent', 'pending_dice_choice', 'matriarch_order'] as $key) {
            if (!empty($s->battle[$key])) return true;
        }
        $strike = $s->battle['strike'] ?? [];
        return ($strike['state'] ?? null) === 'waiting_instant' || !empty($strike['instant_resolution'])
            || !empty($strike['pending_wounds_resolution']) || ($strike['instant_result_stage'] ?? null) === 'dice';
    }

    /** Null includes ambiguous/legacy states. required_players alone is never an authorization. */
    public static function current(GameState $s, string $actor, int $now): ?array
    {
        if ($s->status !== 'battle' || $s->winner !== null || MatchPause::blocking($s) || self::internal($s)) return null;
        $wait = BattleWaitResolver::current($s);
        if (!in_array($wait['kind'] ?? null, ['decision', 'informational'], true)
            || !in_array($actor, $wait['required_players'], true)) return null;
        $source = $wait['source'];
        $strike = $s->battle['strike'] ?? [];
        if (in_array($source, ['strike.waiting_defender', 'strike.waiting_redirect', 'strike.results', 'strike.waiting_push_ack'], true)) {
            if (!empty($strike['pending_choice'])) return null;
            $info = $wait['kind'] === 'informational';
            $record = $info ? InformationalWaitManager::record($s) : null;
            if ($info && ($record === null || $now >= $record['deadline_at'])) return null;
            if (!$info && (int) ($strike[ActionResolution::ID] ?? 0) < 1) return null;
            $anchor = (int) ($strike['attacker_id'] ?? 0);
            $card = $s->getCard($anchor);
            if (!$card) return null;
            $binding = ['key' => 'strike', 'anchor_field' => 'attacker_id', 'anchor_id' => $anchor,
                'anchor_owner' => $card->owner, 'resolution_id' => $strike[ActionResolution::ID] ?? null,
                'wait_id' => $info ? (string) $record['wait_id'] : null,
                'states' => $info ? ['results', 'waiting_push_ack'] : ['waiting_defender', 'waiting_redirect']];
            return self::origin($s, $source, $binding, $strike, 'after');
        }

        $handler = Choice\ChoiceRegistry::current($s);
        if (!$handler) return null;
        $key = $handler->pendingKey();
        $pending = $s->battle[$key] ?? null;
        if (!is_array($pending)) return null;
        if ($key === 'turn_phase') {
            if (!empty($strike) || ($pending['phase'] ?? null) !== 'start' || ($pending['side'] ?? null) !== 'passive'
                || ($pending['active_key'] ?? null) !== ($s->battle['active'] ?? null)
                || ($pending['passive_key'] ?? null) !== $actor || !empty($pending['pending_ack'])
                || ($pending['sub']['parent_type'] ?? null) === 'instants') return null;
            return self::origin($s, $source, ['key' => $key, 'phase' => 'start', 'side' => 'passive',
                'active_key' => $pending['active_key']], $pending, 'turn');
        }
        if (!in_array($key, self::EXTERNAL, true)) return null;
        if (!empty($strike) && !($key === 'pending_heal_instead_wound'
            && !empty($strike['waiting_resolve_after_heal']) && ($strike['state'] ?? null) === 'waiting_defender')) return null;
        if ($key === 'pending_wound_transfer' && (($pending['kind'] ?? 'hermit') === 'hermit'
            || ($pending['options']['on_finish'] ?? null) !== 'main_phase')) return null;
        $head = array_is_list($pending) ? ($pending[0] ?? null) : $pending;
        if (!is_array($head)) return null;
        foreach (['healer_id', 'source_id', 'attacker_id', 'card_id', 'current'] as $field) {
            $id = (int) ($head[$field] ?? 0);
            if ($id < 1) continue;
            $card = $s->getCard($id);
            if (!$card) return null;
            return self::origin($s, $source, ['key' => $key, 'queue' => array_is_list($pending),
                'anchor_field' => $field, 'anchor_id' => $id, 'anchor_owner' => $card->owner], $pending,
                empty($strike) ? 'turn' : 'after');
        }
        return null; // Owner-only legacy payloads have no proven choice identity.
    }

    private static function origin(GameState $s, string $source, array $binding, array $payload, string $phase): array
    {
        $period = InstantReservation::snapshot($s)['period_id'];
        return ['source' => $source, 'choice_id' => 'fw:' . hash('sha256', serialize([
            $s->gameId, $period, $s->persistenceVersion ?? $s->version, $source, $binding, $payload,
        ])), 'binding' => $binding, 'instant_phase' => $phase, 'released' => false];
    }

    public static function validAnchor(GameState $s, array $origin): bool
    {
        $b = $origin['binding'] ?? [];
        if (empty($b['anchor_id'])) return true; // Explicit start-phase context has no source card.
        $card = $s->getCard((int) $b['anchor_id']);
        return $card !== null && $card->owner === ($b['anchor_owner'] ?? null);
    }

    /** Multi-step/owner-switching choices keep their root until it leaves the active context. */
    public static function waiting(GameState $s, array $origin): bool
    {
        $b = $origin['binding'] ?? [];
        if (!isset($b['key'])) return false;
        $pending = $s->battle[$b['key']] ?? null;
        if (!is_array($pending) || !$pending) return false;
        if ($b['key'] === 'turn_phase') {
            if (self::nativeWindow($s, $s->getOpponentKey($b['active_key']), $origin)) return false;
            return ($pending['phase'] ?? null) === $b['phase'] && ($pending['side'] ?? null) === $b['side']
                && ($pending['active_key'] ?? null) === $b['active_key'];
        }
        if ($b['key'] === 'strike') {
            if (($pending['attacker_id'] ?? null) !== $b['anchor_id'] || !in_array($pending['state'] ?? null, $b['states'], true)) return false;
            if ($b['wait_id'] !== null) return InformationalWaitManager::token($s) === $b['wait_id'];
            return ($pending[ActionResolution::ID] ?? null) === $b['resolution_id'];
        }
        $head = !empty($b['queue']) ? ($pending[0] ?? []) : $pending;
        return (int) ($head[$b['anchor_field']] ?? 0) === $b['anchor_id'];
    }

    /** Reuse a native turn window, never a combat window or another pending's hidden stack. */
    public static function nativeWindow(GameState $s, string $actor, array $origin): bool
    {
        // A child's native window must not consume a request still owed after its parent sequence.
        if (CombatContinuation::active($s) || !empty($s->battle['coin_post_combat_queue'])
            || !empty($s->battle['coin_post_combat_continuation'])) return false;
        $stack = $s->battle['turn_instant_stack'] ?? [];
        $wait = BattleWaitResolver::current($s);
        if (($wait['source'] ?? null) !== 'turn_instant_stack' || !in_array($wait['kind'] ?? null, ['decision', 'priority'], true)
            || ($stack['state'] ?? null) !== 'ordering' || ($stack['priority'] ?? null) !== $actor
            || !in_array($actor, $stack['participants'] ?? [], true)) return false;
        if (($origin['binding']['key'] ?? null) === 'strike' || ($origin['instant_phase'] ?? null) === 'after') {
            return ($stack['phase'] ?? null) === 'after';
        }
        return in_array($stack['phase'] ?? null, ['turn', 'before', 'after'], true);
    }
}
