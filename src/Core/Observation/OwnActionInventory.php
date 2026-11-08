<?php
declare(strict_types=1);

namespace Berserk\Core\Observation;

use Berserk\Core\CardInstance;

/** Conservative proof over the inspected native and runtime action providers. */
final class OwnActionInventory
{
    public static function classify(CardInstance $source, array $view): string
    {
        $unknown = false;
        $actions = [];
        foreach ([$source->prop, $source->effectiveProp()] as $properties) {
            if (array_key_exists('actions', $properties) && (!is_array($properties['actions']) || !array_is_list($properties['actions']))) {
                $unknown = true;
            } else {
                foreach ($properties['actions'] ?? [] as $action) {
                    $actions[] = $action;
                }
            }
        }
        foreach ($source->flags as $key => $value) {
            if (str_starts_with((string)$key, 'granted_action:')) {
                $actions[] = $value;
                $unknown = true;
            }
        }
        foreach ($actions as $action) {
            if (!is_array($action)) {
                $unknown = true;
                continue;
            }
            $type = $action['type'] ?? null;
            $range = array_key_exists('range', $action) ? $action['range'] : 0;
            if (in_array($type, ['shot','throw','discharge','cast','multi_discharge','bomb_shot','paired_ranged','damage_ranged','uchr'], true)
                || ($type === 'magic' && is_int($range) && $range > 0)) return 'present';
            if ($type !== 'magic' || !is_int($range) || $range !== 0
                || array_diff(array_keys($action), ['type','key','name','coins','value','range'])
                || !(is_int($action['value'] ?? null) || ($action['value'] ?? null) === 'adjacent_ally_weak_sum')) {
                $unknown = true;
            }
        }

        // Prove only the small inspected envelope. Every other provider is unresolved.
        $properties = $source->prop;
        if (array_diff(array_keys($properties), ['actions','save_coins','zom','zoo','direct','strike','regeneration','armor','zoa','zoan','zoal'])) $unknown = true;
        if (array_key_exists('strike', $properties) && (!is_array($properties['strike']) || array_diff(array_keys($properties['strike']), ['opposite']))) $unknown = true;
        foreach ($source->modifiers as $modifier) {
            // CardStats returns before consulting range bonuses for base range zero.
            if (!is_array($modifier) || ($modifier['stat'] ?? null) !== 'action_range'
                || !is_int($modifier['value'] ?? null)
                || array_diff(array_keys($modifier), ['stat','value','types','duration'])) $unknown = true;
        }
        $bookkeeping = ['lucktest_squad','moved_this_turn','moved_last_turn','damage_taken_this_turn','ranged_hits_this_turn',
            'choice_done','any_death_used_this_turn','prophecy_done_this_turn','attack_block_used_this_turn',
            'prophecy_reorder_used_this_turn','no_close_this_turn','shot_used_this_turn','attacks_used_this_turn',
            'after_strike_execute_used_this_turn','instant_uses_this_turn'];
        if (array_diff(array_keys($source->flags),$bookkeeping) || $source->markers) $unknown = true;
        if (empty($view['basic_ground_strike']['supported_source']) && empty($view['basic_magic_profiles'])) $unknown = true;
        return $unknown ? 'unknown' : 'absent';
    }
}
