<?php
declare(strict_types=1);

namespace Berserk\Core;

use Berserk\Core\Choice\ChoiceRegistry;

/** Read-only ownership projection. It never advances queues or chooses an action. */
final class BattleWaitResolver
{
    private const PLAYERS = ['host', 'player'];
    private const OWNER_PENDING = [
        'pending_temple_dice',
        'pending_tremor',
        'pending_deal_linked_recruit', 'pending_deal_scout_recruit', 'pending_deal_variable_recruit',
        'pending_swamp_queen_summon', 'pending_card_heal', 'pending_matriarch_order', 'pending_coin_ability', 'pending_first_strike_jump', 'pending_dice_choice', 'pending_after_strike_execute',
        'pending_cell_marker_pick','pending_cell_effect_direction', 'pending_instant_pick', 'pending_combat_pick',
        'pending_forced_strike_adjacent', 'pending_ranged_attack_redirect', 'pending_incarnation',
        'pending_valhalla_pick', 'pending_whip', 'pending_kobold_heal', 'pending_talion_incarnation',
        'pending_holvert_open', 'pending_anubisar_chain', 'pending_wound_transfer', 'pending_segger_shield',
        'pending_dverg_gift', 'pending_shaksi_roll', 'pending_heal_instead_wound',
        'pending_attack_series_choice', 'pending_wild_healer_move', 'pending_forced_directional_move',
        'pending_forced_strike', 'pending_row_pick', 'pending_opponent_row_marker', 'pending_row_spell_pick',
        'pending_gate_pick', 'pending_greed_teleport', 'pending_destroy_self_and_target', 'pending_strangulation',
        'pending_particle_pick', 'pending_blood_storm', 'pending_life_gift', 'pending_teleport_target', 'pending_ranged_neighbor_teleport',
    ];
    private const CARD_PENDING = [
        'pending_coin_spend' => 'attacker_id', 'pending_multi_heal' => 'attacker_id',
        'pending_multi_discharge' => 'attacker_id', 'pending_self_wound' => 'attacker_id',
        'pending_blood_tap' => 'attacker_id', 'pending_grezy' => 'attacker_id',
        'pending_card_choice' => 'card_id', 'pending_revive' => 'healer_id',
    ];
    // These are continuation storage, not independent requests for player input.
    private const CONTINUATIONS = [
        'pending_any_death_degasca_sequence', 'pending_nokami_wound_degasca_sequence',
        'pending_degasca_deferred_sequences',
    ];

    /** @return array{kind:string,required_players:list<string>,source:string,reason:?string}|null */
    public static function current(GameState $state): ?array
    {
        if ($state->status !== 'battle' || $state->winner !== null) return null;
        $battle = $state->battle;
        $known = array_merge(array_map(static fn($h) => $h->pendingKey(), ChoiceRegistry::all()),
            ['pending_prophecy', 'pending_dive'], self::CONTINUATIONS);
        foreach ($battle as $key => $value) {
            if (str_starts_with((string) $key, 'pending_') && !empty($value) && !in_array($key, $known, true)) {
                return self::unknown((string) $key, 'unregistered_pending');
            }
        }
        // Avoid warnings from legacy isActive() methods when a saved phase is malformed.
        $phase = $battle['turn_phase'] ?? null;
        if (!empty($phase) && (!is_array($phase) || (empty($phase['pending_ack']) && empty($phase['sub'])
            && (!in_array($phase['side'] ?? null, ['active', 'passive'], true)
                || !is_array($phase[$phase['side'] . '_queue'] ?? []))))) {
            return self::unknown('turn_phase', 'invalid_phase');
        }
        $handler = ChoiceRegistry::current($state);
        if ($handler !== null) {
            $key = $handler->pendingKey();
            $pending = $battle[$key];
            if (in_array($key, ['pending_incarnation_ack', 'turn_instant_result'], true)) {
                return self::unknown($key, 'ack_owner_not_defined');
            }
            if ($key === 'turn_phase') {
                if (!empty($pending['pending_ack'])) return self::unknown('turn_phase.pending_ack', 'ack_owner_not_defined');
                $side = $pending['side'] ?? null;
                if (!in_array($side, ['active', 'passive'], true)) return self::unknown($key, 'invalid_phase_side');
                return self::decision($key . (!empty($pending['sub']) ? '.sub' : '.' . $side . '_queue'), $pending[$side . '_key'] ?? null);
            }
            if (!is_array($pending)) return self::unknown($key, 'invalid_pending');
            if ($key === 'turn_instant_stack') {
                return self::priority($key, $pending['priority'] ?? null, ($pending['participants'] ?? []) === []
                    ? self::PLAYERS : $pending['participants']);
            }
            if (in_array($key, ['pending_incarnation_wound', 'pending_nokami_wound', 'pending_intrigue_start'], true)) {
                return self::decision($key . '[0]', is_array($pending[0] ?? null) ? ($pending[0]['owner'] ?? null) : null);
            }
            if ($key === 'pending_any_death') {
                return self::cardDecision($state, $key . '[0]', is_array($pending[0] ?? null) ? ($pending[0]['source_id'] ?? null) : null);
            }
            if ($key === 'pending_degasca_sequence') {
                if (($pending['stage'] ?? null) !== 'choose_card') return self::unknown($key, 'continuation_without_active_choice');
                return self::decision($key, $pending['owner'] ?? null);
            }
            if (isset(self::CARD_PENDING[$key])) return self::cardDecision($state, $key, $pending[self::CARD_PENDING[$key]] ?? null);
            if ($key==='pending_paired_ranged') {if(!empty($battle[$key]))return self::decision($key,$pending['owner']);return self::cardDecision($state,'strike.pending_choice',$battle['strike']['pending_choice']['died_id']);}
            if ($key==='pending_grave_ritual') return self::decision($key,$pending['owner']);
            if ($key==='pending_deck_reorder') return self::decision($key,$pending['owner']);
            if ($key==='pending_alvalind_end') {
                if (!empty($battle['pending_alvalind_end'])) return self::decision($key,$battle[$key]['owner']);
                return self::cardDecision($state,'strike.pending_choice',$battle['strike']['pending_choice']['died_id']);
            }
            if ($key === 'abyss_movement') return self::cardDecision($state,'abyss_movement', $battle['abyss_movement']['result']['source_id'] ?? $battle['strike']['pending_choice']['died_id']);
            if ($key === 'pending_incarnation' && WormIncarnation::operation($state) && !empty($battle['strike']['pending_choice'])) return self::cardDecision($state, 'strike.pending_choice', $battle['strike']['pending_choice']['died_id']);
            if (in_array($key, self::OWNER_PENDING, true)) return self::decision($key, $pending['owner'] ?? null);
            return self::unknown($key, 'unclassified_handler');
        }

        // Legacy panels follow the same priority as InfoPanel: prophecy, strike, dive.
        if (!empty($battle['pending_prophecy'])) return self::prophecy($battle['pending_prophecy']);
        if (!empty($battle['strike'])) return self::strike($state, $battle['strike']);
        if (!empty($battle['pending_dive'])) {
            $pending = $battle['pending_dive'];
            return self::decision('pending_dive', is_array($pending) ? ($pending['owner'] ?? null) : null);
        }
        if (!empty($battle['turn_instant_stack'])) return self::unknown('turn_instant_stack', 'continuation_without_active_choice');
        if (!empty($phase)) return self::unknown('turn_phase', 'continuation_without_active_choice');
        foreach (self::CONTINUATIONS as $key) {
            if (!empty($battle[$key])) return self::unknown($key, 'continuation_without_active_choice');
        }
        if (!empty($battle['coin_post_combat_queue']) || !empty($battle['coin_post_combat_continuation'])) {
            return self::unknown('coin_post_combat', 'continuation_without_active_choice');
        }
        return null;
    }

    private static function strike(GameState $state, mixed $strike): array
    {
        if (!is_array($strike)) return self::unknown('strike', 'invalid_strike');
        $source = 'strike.' . (is_string($strike['state'] ?? null) ? $strike['state'] : 'unknown');
        // A death choice may live inside results; it must not become an informational ACK.
        if (!empty($strike['pending_choice'])) {
            $pending = $strike['pending_choice'];
            return self::cardDecision($state, 'strike.pending_choice', is_array($pending) ? ($pending['died_id'] ?? null) : null);
        }
        $nestedStages = ['pending_auto'=>'waiting_auto_target', 'pending_push'=>'waiting_push_choice',
            'pending_close_or_damage'=>'waiting_close_or_damage', 'pending_ally_modifier'=>'waiting_ally_modifier'];
        foreach ($strike as $key => $value) {
            if (str_starts_with((string) $key, 'pending_') && !empty($value) && $key !== 'pending_wounds_resolution'
                && (!isset($nestedStages[$key]) || $nestedStages[$key] !== ($strike['state'] ?? null))) {
                return self::unknown('strike.' . $key, 'unclassified_or_mismatched_nested_choice');
            }
        }
        switch ($strike['state'] ?? null) {
            case 'waiting_defender':
            case 'waiting_redirect':
                $owner = self::cardOwner($state, $strike['attacker_id'] ?? null);
                return self::decision($source, $owner === null ? null : $state->getOpponentKey($owner));
            case 'waiting_instant':
                $participants = $strike['instant_participants'] ?? null;
                if ($participants === null || $participants === []) {
                    $owner = self::cardOwner($state, $strike['attacker_id'] ?? null);
                    if ($owner === null) return self::unknown($source, 'missing_attacker');
                    $participants = !empty($strike['friendly_fire']) ? [$owner] : self::PLAYERS;
                }
                return self::priority($source, $strike['instant_priority'] ?? null, $participants);
            case 'waiting_choice':
                $owner = self::cardOwner($state, $strike['attacker_id'] ?? null);
                $winner = $strike['choice_winner'] ?? null;
                if ($owner === null || !in_array($winner, ['attack', 'defend'], true)) return self::unknown($source, 'invalid_choice_winner');
                return self::decision($source, $winner === 'attack' ? $owner : $state->getOpponentKey($owner));
            case 'waiting_auto_target':
                return self::nestedCardDecision($state, $strike, 'pending_auto', 'source_id');
            case 'waiting_push_choice':
                return self::nestedCardDecision($state, $strike, 'pending_push', 'defender_id');
            case 'waiting_close_or_damage':
                return self::nestedCardDecision($state, $strike, 'pending_close_or_damage', 'defender_id');
            case 'waiting_ally_modifier':
                return self::nestedCardDecision($state, $strike, 'pending_ally_modifier', 'source_id');
            case 'results':
            case 'waiting_push_ack':
                $confirmed = $strike['confirmed'] ?? [];
                if (!self::playerList($confirmed)) return self::unknown($source, 'invalid_confirmations');
                return self::projection('informational', array_values(array_diff(self::PLAYERS, $confirmed)), $source);
            default:
                return self::unknown($source, 'unclassified_strike_state');
        }
    }

    private static function prophecy(mixed $pending): array
    {
        $source = 'pending_prophecy';
        if (!is_array($pending)) return self::unknown($source, 'invalid_pending');
        if (!empty($pending['reorder_mode']) && !empty($pending['summon_mode'])) return self::unknown($source, 'ambiguous_prophecy_stage');
        if (!empty($pending['reorder_mode'])) return self::decision($source . '.reorder', $pending['owner'] ?? null);
        if (!empty($pending['summon_mode'])) {
            if (!in_array($pending['summon_step'] ?? null, ['cell', 'creature'], true)) return self::unknown($source, 'ambiguous_prophecy_stage');
            return self::decision($source . '.summon.' . $pending['summon_step'], $pending['owner'] ?? null);
        }
        $actions = $pending['actions'] ?? null;
        if (!is_array($actions)) return self::unknown($source, 'ambiguous_prophecy_stage');
        $hasDecision = false;
        foreach ($actions as $action) {
            $type = is_array($action) ? ($action['cmd'] ?? null) : null;
            if (!in_array($type, ['reorder_start', 'summon_start', 'transform_seeker', 'close_prophecy', 'grezy_continue'], true)) {
                return self::unknown($source, 'ambiguous_prophecy_stage');
            }
            if (in_array($type, ['reorder_start', 'summon_start', 'transform_seeker'], true)) $hasDecision = true;
        }
        return $hasDecision ? self::decision($source, $pending['owner'] ?? null) : self::unknown($source, 'ambiguous_prophecy_stage');
    }

    private static function nestedCardDecision(GameState $state, array $strike, string $key, string $field): array
    {
        $pending = $strike[$key] ?? null;
        return self::cardDecision($state, 'strike.' . $key, is_array($pending) ? ($pending[$field] ?? null) : null);
    }

    private static function cardOwner(GameState $state, mixed $id): ?string
    {
        if (!is_int($id) || $id <= 0) return null;
        $owner = $state->getCard($id)?->owner;
        return in_array($owner, self::PLAYERS, true) ? $owner : null;
    }

    private static function cardDecision(GameState $state, string $source, mixed $id): array
    {
        return self::decision($source, self::cardOwner($state, $id));
    }

    private static function priority(string $source, mixed $owner, mixed $participants): array
    {
        if (!self::playerList($participants) || !in_array($owner, $participants, true)) return self::unknown($source, 'invalid_priority');
        return self::decision($source, $owner);
    }

    private static function playerList(mixed $players): bool
    {
        if (!is_array($players) || !array_is_list($players)) return false;
        foreach ($players as $player) {
            if (!in_array($player, self::PLAYERS, true)) return false;
        }
        return true;
    }

    private static function decision(string $source, mixed $owner): array
    {
        return in_array($owner, self::PLAYERS, true)
            ? self::projection('decision', [$owner], $source) : self::unknown($source, 'missing_or_invalid_owner');
    }

    private static function unknown(string $source, string $reason): array
    {
        return self::projection('unknown', [], $source, $reason);
    }

    private static function projection(string $kind, array $players, string $source, ?string $reason = null): array
    {
        return ['kind' => $kind, 'required_players' => $players, 'source' => $source, 'reason' => $reason];
    }
}
