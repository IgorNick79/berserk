<?php
declare(strict_types=1);

namespace Berserk\Core\Observation;

use Berserk\Core\{BattleHelper, BattleWaitResolver, CardInstance, EffectiveFeatures, Engine, GameState, InstantProcessor, MatchPause, Strangulation, StrikeResolver, TurnPhaseAck, ZoneManager};
use Berserk\Core\Choice\{ChoiceRegistry, TurnAckChoice};
use Berserk\Core\Choice\ForcedStrikeChoice;

final class PlayerObservation
{
    /** Trusted server boundary. The caller must establish the viewer's side. */
    public static function build(GameState $state, string $viewer, array $catalog = []): array
    {
        if (!VisibilityPolicy::isSide($viewer)) {
            throw new \InvalidArgumentException('Invalid observation side');
        }

        $stage = in_array($state->status, [
            'mode', 'settings', 'draft', 'deck', 'view', 'turn', 'side', 'deal', 'place', 'battle', 'game_over',
        ], true) ? $state->status : 'unknown';
        $battle = $stage === 'battle';
        $active = $battle ? ($state->battle['active'] ?? null) : null;
        $active = VisibilityPolicy::isSide($active) ? $active : null;
        $turn = $battle ? ($state->battle['turn'] ?? null) : null;
        $phase = $battle ? ($state->battle['turn_phase']['phase'] ?? null) : null;
        $finished = $stage === 'game_over';
        $cards = [];
        foreach ($state->cards as $card) {
            $visibility = VisibilityPolicy::card($state, $card, $viewer);
            if ($visibility !== 'not_applicable') {
                $cards[] = self::card($card, $visibility, $battle || $card->owner === $viewer, $catalog);
            }
        }
        // Stable public order independent of catalog or internal zone ordering.
        usort($cards, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        if ($battle) foreach ($cards as &$visibleCard) {
            if ($visibleCard['owner']!==$viewer || $visibleCard['identity']!=='known') continue;
            $source = $state->getCard($visibleCard['id']);
            $profiles=OrdinaryShots::profiles($state,$source);
            if ($profiles!==[]) $visibleCard['shot_profiles']=$profiles;
            $magic=BasicMagic::profiles($state,$source);
            if ($magic!==[]) {
                $positionalContext=BasicMagic::knownContext($state,$viewer)
                    && empty($source->flags['attack_series_active'])
                    && \Berserk\Core\Legal\BasicGroundStrikeSource::enabled($source);
                foreach ($magic as &$profile) $profile['positional_context_supported']=$positionalContext;
                unset($profile);
            }
            if ($magic!==[]) $visibleCard['basic_magic_profiles']=$magic;
            if (\Berserk\Core\Legal\BasicGroundStrikeSource::supports($source))
                $visibleCard['basic_ground_strike']=['supported_source'=>true,'distance_rule'=>'adjacent_ground'];
            if ($visibleCard['zone']==='field') {
                $visibleCard['contact_inventory']=OwnActionInventory::classify($source, $visibleCard);
            }
        }
        unset($visibleCard);

        $wait = $battle && !MatchPause::blocking($state) ? BattleWaitResolver::current($state) : null;
        $observation = [
            'viewer' => $viewer,
            'version' => $state->persistenceVersion,
            'stage' => $stage,
            'active' => $active,
            'turn' => is_int($turn) && $turn > 0 ? $turn : null,
            'finished' => $finished,
            'winner' => $finished && VisibilityPolicy::isSide($state->winner) ? $state->winner : null,
            'phase' => in_array($phase, ['start', 'end'], true) ? $phase : null,
            'control' => self::control($state, $viewer, $active, $wait),
            'cards' => $cards,
        ];
        if(($redirect=StrikeRedirectKnowledge::choice($state,$viewer))!==null)$observation['strike_redirect_choice']=$redirect;
        if (($wait['source'] ?? null) === 'strike.waiting_defender' && ($wait['kind'] ?? null) === 'decision') {
            $observation['defender_choice'] = ['owner'=>$wait['required_players'][0]];
            if ($wait['required_players'][0] === $viewer) {
                $target = $state->getCard((int) ($state->battle['strike']['target_id'] ?? 0));
                if ($target !== null && VisibilityPolicy::card($state,$target,$viewer) === 'known') {
                    $observation['defender_choice']['target_id'] = $target->instanceId;
                }
            }
        }
        if (($wait['source'] ?? null) === 'strike.waiting_choice' && in_array($viewer,$wait['required_players'],true)) {
            $strike = $state->battle['strike'];
            $preview = ['role'=>$strike['choice_winner'],'levels'=>null,'final_effects'=>'unknown'];
            foreach (['attacker_id'=>$strike['attacker_id'], 'defender_id'=>($strike['defender_id'] ?? null) ?: $strike['target_id']] as $key=>$id) {
                $card = $state->getCard((int)$id);
                if ($card !== null && VisibilityPolicy::card($state,$card,$viewer) === 'known') $preview[$key] = $card->instanceId;
            }
            foreach (['attack','defend'] as $side) {
                $die = $strike[$side.'_dice'] ?? null;
                $modifier = $strike[$side.'_mod'] ?? null;
                if (is_int($die) && is_int($modifier)) $preview['dice'][$side] = ['die'=>$die,'modifier'=>$modifier];
            }
            $attack = $strike['result']['attack'] ?? null;
            $defend = $strike['result']['defend'] ?? null;
            $levels = ['', 'weak', 'medium', 'strong'];
            if (in_array($attack,$levels,true) && in_array($defend,$levels,true)) {
                $preview['levels'] = [
                    'normal'=>['attack'=>$attack,'defend'=>$defend],
                    'decrease'=>['attack'=>BattleHelper::decreaseStrike($attack),'defend'=>BattleHelper::decreaseStrike($defend)],
                ];
            }
            $observation['strike_mode_choice'] = $preview;
        }
        if (($wait['source'] ?? null) === 'pending_temple_dice'
            && in_array($viewer,$wait['required_players'],true)
            && ($state->battle['strike']['state'] ?? null) === 'waiting_temple_dice') {
            $observation['additional_dice_choice'] = self::additionalDiceChoice($state,$viewer);
        }
        if (($wait['source'] ?? null)==='pending_gate_pick' && $wait['required_players']===[$viewer]) {
            $pending = $state->battle['pending_gate_pick'];
            $observation['gate_choice'] = ['owner'=>$viewer,'count'=>(int)$pending['count'],
                'chosen'=>array_values($pending['chosen']),'candidates'=>array_values($pending['candidates']),
                'effect'=>'gate_marker_for_later_effects'];
        }
        $ack = self::turnPhaseAck($state,$viewer,$wait);
        $forced=ForcedStrikeKnowledge::build($state,$viewer);
        if($forced!==[])$observation['forced_strikes']=$forced;
        if(($wait['source'] ?? null)==='pending_forced_strike' && ($wait['required_players'] ?? [])===[$viewer]
            && ChoiceRegistry::current($state) instanceof ForcedStrikeChoice){
            $source=$state->getCard((int)($state->battle['pending_forced_strike']['attacker_id'] ?? 0));
            if($source!==null && $source->owner===$viewer && VisibilityPolicy::card($state,$source,$viewer)==='known')
                $observation['forced_strike_choice']=['owner'=>$viewer,'source_id'=>$source->instanceId];
        }
        if ($ack !== null) $observation['turn_phase_ack'] = $ack;
        if (($wait['reason'] ?? null)==='turn_ack_legacy_contract') $observation['control']['reason'] = 'turn_ack_legacy_contract';
        $teleport = self::greedTeleportChoice($state,$viewer,$wait);
        if ($teleport !== null) $observation['greed_teleport_choice'] = $teleport;
        $tasks = self::passiveTasks($state,$viewer,$wait);
        if ($tasks !== []) $observation['passive_tasks'] = ['owner'=>$viewer,'options'=>$tasks];
        $instant = self::combatDiceWindow($state,$viewer,$wait);
        if ($instant !== null) $observation['combat_dice_window'] = $instant;
        $power=CombatPowerKnowledge::window($state,$viewer);
        if($power!==null)$observation['combat_power_window']=$power;
        $mainPass = CombatMainPassKnowledge::window($state, $viewer);
        if ($mainPass !== null) $observation['combat_main_pass_window'] = $mainPass;

        $prophecy = ProphecyKnowledge::choice($state, $viewer, $wait);
        if ($prophecy !== null) $observation['prophecy_choice'] = $prophecy;
        $turnInstant=TurnInstantKnowledge::build($state,$viewer);
        if($turnInstant!==null)$observation['turn_instant_choice']=$turnInstant;
        $autoShot=RootAutoShotKnowledge::choice($state,$viewer);
        if($autoShot!==null)$observation['auto_shot_choice']=$autoShot;
        return $observation;
    }

    /** Only the current owner's server-issued identity; no result content. */
    public static function turnPhaseAck(GameState $state, string $viewer, ?array $wait): ?array
    {
        if (($wait['source'] ?? null)!=='turn_phase.pending_ack' || ($wait['kind'] ?? null)!=='informational'
            || ($wait['required_players'] ?? [])!==[$viewer] || !(ChoiceRegistry::current($state) instanceof TurnAckChoice)) return null;
        $ack = $state->battle['turn_phase']['pending_ack'] ?? null;
        if (!TurnPhaseAck::valid($ack) || $ack['owner']!==$viewer) return null;
        return ['type'=>'turn_phase_ack','owner'=>$viewer,'ack_id'=>$ack['ack_id']];
    }

    /** Current destinations follow chooseGreedTeleport, independently of ordinary move rules. */
    public static function greedTeleportChoice(GameState $state, string $viewer, ?array $wait): ?array
    {
        if (($wait['source'] ?? null)!=='pending_greed_teleport' || ($wait['kind'] ?? null)!=='decision'
            || $wait['required_players']!==[$viewer]) return null;
        $pending = $state->battle['pending_greed_teleport'];
        $card = $state->getCard((int)($pending['source_id'] ?? 0));
        if ($card===null || $card->owner!==$viewer || VisibilityPolicy::card($state,$card,$viewer)!=='known'
            || EffectiveFeatures::suppressed($card)) return null;
        $destinations = [];
        if (Strangulation::count($card)===0) {
            foreach ($pending['free_gates'] as $cell) {
                if (!preg_match('/^[1-6]_[1-5]$/D',$cell) || ZoneManager::hasBlockingMarker($state,$cell)) continue;
                [$row,$col] = array_map('intval',explode('_',$cell));
                $occupied = false;
                foreach ($state->cards as $other) {
                    if ($other->zone===CardInstance::ZONE_FIELD && $other->row===$row && $other->col===$col
                        && $other->instanceId!==$card->instanceId) { $occupied = true; break; }
                }
                if (!$occupied) $destinations[] = ['row'=>$row,'col'=>$col];
            }
        }
        return ['owner'=>$viewer,'source_id'=>$card->instanceId,
            'from'=>['row'=>$card->row,'col'=>$card->col],'destinations'=>$destinations,
            'potential_adjacent_enemy_damage'=>(int)$pending['damage'],
            'consumes_pending_gates'=>true,'resource_cost'=>0,'final_effects'=>'unknown'];
    }

    /** A confirmed subset of the current passive queue, with no task payloads or labels. */
    public static function passiveTasks(GameState $state, string $viewer, ?array $wait): array
    {
        if (($wait['source'] ?? null) !== 'turn_phase.passive_queue' || ($wait['kind'] ?? null) !== 'decision'
            || $wait['required_players'] !== [$viewer]) return [];
        $phase = $state->battle['turn_phase'];
        if (!in_array($phase['phase'],['start','end'],true) || $phase['side'] !== 'passive' || $phase['passive_key'] !== $viewer) return [];
        $options = [];
        foreach ($phase['passive_queue'] as $task) {
            if (!empty($task['sub']) || !is_string($task['id'] ?? null) || $task['id'] === '') continue;
            $source = $state->getCard((int) ($task['card_id'] ?? 0));
            if ($source === null || $source->owner !== $viewer || VisibilityPolicy::card($state,$source,$viewer) !== 'known') continue;
            $option = ['task_id'=>$task['id'],'source_id'=>$source->instanceId,'resource_cost'=>0];
            if ($phase['phase']==='start' && ($task['type'] ?? null)==='opponent_turn_start:place_gates') {
                $count = (int) ($task['payload']['count'] ?? 3);
                if ($count <= 0) continue;
                $options[] = $option + ['type'=>'place_gates','gate_count'=>$count,'effect'=>'may_open_gate_cell_choice'];
            } elseif ($phase['phase']==='end' && ($task['type'] ?? null)==='opponent_turn_end') {
                // The executor prefers turn_end when both properties exist. Match its actual source.
                $effects = $source->effectiveProp()['turn_end'] ?? $source->effectiveProp()['opponent_turn_end'] ?? [];
                if (!is_array($effects) || count($effects)!==1 || ($effects[0]['type'] ?? null)!=='greed_teleport') continue;
                $options[] = $option + ['type'=>'greed_teleport','source_hp'=>$source->hp,
                    'potential_damage'=>(int)($effects[0]['damage'] ?? 2),
                    'potential_self_damage'=>(int)($effects[0]['self_damage'] ?? 3),
                    'effect'=>'may_open_teleport_choice_or_apply_self_damage','outcome'=>'unknown'];
            }
        }
        return $options;
    }

    /** Shared safe facts for the narrow combat dice window. Command timing is checked separately. */
    public static function combatDiceWindow(GameState $state, string $viewer, ?array $wait): ?array
    {
        $strike = $state->battle['strike'] ?? [];
        if (($wait['source'] ?? null) !== 'strike.waiting_instant' || ($wait['kind'] ?? null) !== 'decision'
            || ($strike['instant_phase'] ?? null) !== 'combat' || ($strike['combat_instant_window'] ?? null) !== 'dice'
            || ($strike['instant_priority'] ?? null) !== $viewer || $wait['required_players'] !== [$viewer]) return null;
        $attacker = $state->getCard((int) ($strike['attacker_id'] ?? 0));
        if ($attacker === null) return null;
        $opposing = $attacker->owner === $viewer ? 'defend' : 'attack';
        $target = $opposing === 'attack' ? $attacker
            : $state->getCard((int) (($strike['defender_id'] ?? 0) ?: ($strike['target_id'] ?? 0)));
        $die = $strike[$opposing.'_dice'] ?? null;
        $mod = $strike[$opposing.'_mod'] ?? null;
        $options = [];
        foreach ((new InstantProcessor($state,new Engine()))->getInstants($viewer,'combat','combat') as $entry) {
            $ability = $entry['payload'];
            $effect = $ability['effect'] ?? [];
            $key = $ability['key'] ?? '';
            $card = $state->getCard($entry['card_id']);
            if (!is_string($key) || $key === '' || ($ability['target'] ?? 'self') !== 'self'
                || ($effect['type'] ?? null) !== 'damage_on_dice'
                || isset($ability['condition']) || isset($effect['condition'])
                || count(array_keys(array_column($card->effectiveProp()['instants'],'key'),$key,true)) !== 1) continue;
            $options[] = ['card_id'=>$entry['card_id'],'instant_key'=>$key,
                'required_total'=>(int) ($effect['value'] ?? 0),'damage'=>(int) ($effect['damage'] ?? 0),
                'cost'=>max(0,$entry['cost'])];
        }
        return ['priority'=>$viewer,'stack_empty'=>empty($strike['instant_stack']),
            'opposing_die'=>is_int($die) && is_int($mod) ? ['value'=>$die,'modifier'=>$mod] : null,
            'target_id'=>$target !== null && VisibilityPolicy::card($state,$target,$viewer)==='known' ? $target->instanceId : null,
            'options'=>$options,'final_effects'=>'unknown'];
    }

    private static function additionalDiceChoice(GameState $state, string $viewer): array
    {
        $strike = $state->battle['strike'];
        $role = $state->battle['pending_temple_dice']['side'] ?? null;
        $rolls = [];
        foreach (['attack','defend'] as $side) {
            // Same published rolls as the human panel. The pending queue and chosen values stay private.
            $values = $strike['temple_rolls'][$side]['values'] ?? [$strike[$side.'_dice'] ?? null];
            $rolls[$side] = array_values(array_filter($values,static fn($die): bool => is_int($die) && $die >= 0 && $die <= 6));
        }
        $preview = ['owner'=>$viewer,'role'=>$role,'published_dice'=>$rolls,'resource_cost'=>0,
            'unmodified_levels'=>null,'final_effects'=>'unknown'];
        if (!in_array($role,['attack','defend'],true)) return $preview;
        $opposing = array_values(array_unique($rolls[$role === 'attack' ? 'defend' : 'attack']));
        if (count($opposing) !== 1) return $preview;
        $resolver = new StrikeResolver($state,new Engine());
        foreach ($rolls[$role] as $die) {
            $table = $role === 'attack' ? $resolver->strikeTable($die,$opposing[0]) : $resolver->strikeTable($opposing[0],$die);
            $preview['unmodified_levels'][$die] = ['attack'=>$table['attack'],'defend'=>$table['defend']];
        }
        return $preview;
    }

    private static function card(CardInstance $card, string $visibility, bool $includeDetails, array $catalog): array
    {
        $out = ['id' => $card->instanceId, 'owner' => $card->owner, 'zone' => $card->zone,
            'identity' => $visibility];
        if ($card->zone === CardInstance::ZONE_FIELD) {
            $out['row'] = $card->row;
            $out['col'] = $card->col;
        } elseif ($card->zone === CardInstance::ZONE_FLYING) {
            $out['slot'] = $card->slot;
            if ($visibility==='known') $out['flight']=['position_kind'=>'air_slot','ordinary_movement'=>'not_applicable'];
        }
        if ($visibility !== 'known') return $out;

        $out['ukid'] = $card->ukid;
        if (!$includeDetails || !in_array($card->zone, [CardInstance::ZONE_FIELD, CardInstance::ZONE_FLYING, CardInstance::ZONE_SQUAD], true)) {
            return $out;
        }

        $poison = $card->markers['poison'] ?? null;
        $poison = is_array($poison) ? ($poison['value'] ?? null) : $poison;
        $markers = is_int($poison) && $poison > 0 ? ['poison' => $poison] : [];
        foreach (['stun', 'rooted', 'spider_web'] as $key) {
            if (isset($card->markers[$key])) $markers[$key] = true;
        }
        $strangulation = $card->markers['strangulation']['value'] ?? null;
        if (is_int($strangulation) && $strangulation > 0) $markers['strangulation'] = $strangulation;

        return $out + [
            'type' => $card->type,
            'strike' => ['weak' => $card->strikeWeak, 'medium' => $card->strikeMedium, 'strong' => $card->strikeStrong],
            'move' => $card->move,
            'move_max' => $card->moveMax,
            'properties' => KnownCardProperties::build($card),
            'closed' => $card->closed,
            'revealed' => $card->revealed,
            'hp' => $card->hp,
            'hp_max' => $card->hpMax,
            'display_hp_max' => self::displayHpMax($card, $catalog),
            'armor' => $card->armor,
            'coins' => $card->coins,
            'markers' => $markers,
        ];
    }

    private static function displayHpMax(CardInstance $card, array $catalog): int|string|null
    {
        if ((int) ($card->prop['hp_max_override'] ?? 0) === 999) return '∞';
        if (!empty($card->flags['stitched_unique_loss']) || !empty($card->prop['first_card_heal'])) {
            return $card->hpMax;
        }
        $health = $catalog[$card->ukid]['health'] ?? null;
        return is_int($health) && $health > 0 ? $health : null;
    }

    private static function control(GameState $state, string $viewer, ?string $active, ?array $wait): array
    {
        $ownsTurn = $active === null ? null : $active === $viewer;
        if ($state->status !== 'battle' || $state->winner !== null) {
            $status = 'not_applicable';
        } elseif (MatchPause::blocking($state)) {
            $status = 'paused';
        } else {
            $mine = in_array($viewer, $wait['required_players'] ?? [], true);
            $status = match ($wait['kind'] ?? null) {
                'informational' => $mine ? 'confirm_information' : 'waiting',
                'decision' => !$mine ? 'waiting' : (
                    in_array($wait['source'], ['strike.waiting_instant', 'turn_instant_stack'], true)
                        ? 'reaction_window' : 'choice_required'
                ),
                null => $ownsTurn === null ? 'unknown' : ($ownsTurn ? 'own_turn' : 'waiting'),
                default => 'unknown',
            };
        }

        return ['owns_turn' => $ownsTurn, 'status' => $status];
    }
}
