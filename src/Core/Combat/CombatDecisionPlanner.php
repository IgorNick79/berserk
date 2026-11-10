<?php
declare(strict_types=1);

namespace Berserk\Core\Combat;

final class CombatDecisionPlanner
{
    /** The executor owns the per-turn history and passes only public visited cells. */
    public static function decide(array $observation, array $legalActions, array $visited = []): array
    {
        $stop = ['action'=>null, 'reason'=>'invalid_snapshot', 'score'=>null, 'factors'=>[]];
        if (!in_array($observation['viewer'] ?? null, ['host','player'], true)
            || !isset($observation['version'], $legalActions['version'])
            || $observation['version'] !== $legalActions['version']
            || ($observation['viewer'] ?? null) !== ($legalActions['viewer'] ?? null)
            || ($observation['stage'] ?? null) !== 'battle' || ($legalActions['stage'] ?? null) !== 'battle') return $stop;
        $control = $observation['control']['status'] ?? null;
        $decision = $legalActions['decision'] ?? null;
        if($decision==='root_auto_shot'&&$control==='choice_required')return self::rootAutoShot($observation,$legalActions['actions']);
        if ($decision === 'prophecy_transform_decline') {
            $window = $observation['prophecy_choice'] ?? [];
            $actions = $legalActions['actions'] ?? [];
            if ($control === 'choice_required' && ($window['kind'] ?? null) === $decision
                && ($window['owner'] ?? null) === $observation['viewer'] && ($window['can_decline'] ?? null) === true
                && $actions === [['type'=>'close_prophecy', 'payload'=>[]]]) {
                return ['action'=>$actions[0], 'reason'=>$decision, 'score'=>0, 'factors'=>[]];
            }
            return array_replace($stop, ['reason'=>'unsupported_pending']);
        }
        if (in_array($decision, ['prophecy_source', 'prophecy_close'], true) && $control === 'choice_required') {
            $window = $observation['prophecy_choice'] ?? [];
            $action = $legalActions['actions'][0] ?? null;
            $expected = $decision === 'prophecy_close'
                ? (($window['can_close'] ?? false) === true ? ['type'=>'close_prophecy', 'payload'=>[]] : null)
                : (isset($window['options'][0]['sub_id'])
                    ? ['type'=>'turn_sub', 'payload'=>['sub_id'=>$window['options'][0]['sub_id']]] : null);
            if (($window['kind'] ?? null) === $decision && ($window['owner'] ?? null) === $observation['viewer']
                && $expected !== null && $action === $expected
                && ($decision !== 'prophecy_close' || count($legalActions['actions']) === 1)) {
                return ['action'=>$action, 'reason'=>$decision, 'score'=>0, 'factors'=>[]];
            }
            return array_replace($stop, ['reason'=>'unsupported_pending']);
        }
        if(str_starts_with($legalActions['reason']??'','unsupported_turn_instant_context:')||($legalActions['reason']??null)==='turn_instant_result_legacy_contract')return array_replace($stop,['reason'=>$legalActions['reason']]);
        if (in_array($decision, ['turn_instant_terminal_pass', 'turn_instant_after_terminal_pass'], true)) {
            $kind = $decision === 'turn_instant_after_terminal_pass' ? 'after_terminal_pass' : 'terminal_pass';
            $window = $observation['turn_instant_choice'] ?? [];
            $actions = $legalActions['actions'] ?? [];
            if ($control === 'reaction_window' && ($window['kind'] ?? null) === $kind
                && ($window['owner'] ?? null) === $observation['viewer'] && ($window['can_pass'] ?? null) === true
                && $actions === [['type'=>'pass_turn_instant', 'payload'=>[]]]) {
                return ['action'=>$actions[0], 'reason'=>'pass_turn_instant_terminal', 'score'=>0, 'factors'=>[]];
            }
            return array_replace($stop, ['reason'=>'unsupported_pending']);
        }
        if(str_starts_with((string)$decision,'turn_instant_'))return self::turnInstant($observation,$legalActions['actions']);
        if($decision==='combat_power_instant' && $control==='reaction_window')return self::combatPower($observation,$legalActions['actions']);
        if ($decision === 'combat_main_pass' && $control === 'reaction_window') {
            $window = $observation['combat_main_pass_window'] ?? [];
            $actions = $legalActions['actions'] ?? [];
            if (($window['kind'] ?? null) === 'combat_main_pass'
                && ($window['owner'] ?? null) === $observation['viewer']
                && ($window['can_pass'] ?? null) === true
                && $actions === [['type'=>'combat_instant_pass', 'payload'=>[]]]) {
                return ['action'=>$actions[0], 'reason'=>'pass_combat_main_terminal', 'score'=>0, 'factors'=>[]];
            }
            return array_replace($stop, ['reason'=>'unsupported_pending']);
        }
        if($decision==='strike_redirect' && $control==='choice_required')return self::strikeRedirect($observation,$legalActions['actions']);
        if($decision==='forced_strike_choice' && $control==='choice_required'){
            $choice=$observation['forced_strike_choice'] ?? [];
            if(($choice['owner'] ?? null)!==$observation['viewer'])return array_replace($stop,['reason'=>'unsupported_pending']);
            return self::requiredStrike($observation,$legalActions['actions'],[(int)$choice['source_id']],'choose_forced_strike');
        }
        if (($legalActions['reason'] ?? null)==='turn_ack_legacy_contract') {
            return array_replace($stop,['reason'=>'turn_ack_legacy_contract']);
        }
        if ($decision === 'turn_phase_ack' && $control === 'confirm_information') {
            $ack = $observation['turn_phase_ack'] ?? [];
            foreach ($legalActions['actions'] as $action) {
                if (($ack['type'] ?? null)==='turn_phase_ack' && ($ack['owner'] ?? null)===$observation['viewer']
                    && is_string($ack['ack_id'] ?? null) && $action['type']==='turn_ack'
                    && $action['payload']===['ack_id'=>$ack['ack_id']]) {
                    return ['action'=>$action,'reason'=>'acknowledge_turn_phase_result','score'=>0,'factors'=>[]];
                }
            }
            return array_replace($stop,['reason'=>'unsupported_pending']);
        }
        if ($decision === 'greed_teleport' && $control === 'choice_required') {
            return self::greedTeleport($observation,$legalActions['actions']);
        }
        if (in_array($decision, ['first_strike_movement', 'card_option'], true) && $control === 'choice_required') {
            $command = $decision === 'card_option' ? 'choose_card_option' : 'choose_first_strike_movement';
            foreach ($legalActions['actions'] as $action) {
                if ($action['type'] === $command) {
                    return ['action'=>$action, 'reason'=>$decision, 'score'=>0, 'factors'=>[]];
                }
            }
            return array_replace($stop, ['reason'=>'unsupported_pending']);
        }
        if ($decision === 'gate_cell' && $control === 'choice_required') {
            $best = $stop;
            $best['reason'] = 'unsupported_pending';
            foreach ($legalActions['actions'] as $action) {
                if ($action['type'] !== 'choose_gate') continue;
                $cell = $action['payload'];
                $near = 6;
                foreach ($observation['cards'] as $card) {
                    if ($card['zone']!=='field' || $card['owner']!==$observation['viewer']) continue;
                    $near = min($near,abs($cell['row']-$card['row'])+abs($cell['col']-$card['col']));
                }
                $spread = 6;
                foreach ($observation['gate_choice']['chosen'] as $chosen) {
                    [$r,$c] = array_map('intval',explode('_',$chosen));
                    $spread = min($spread,abs($cell['row']-$r)+abs($cell['col']-$c));
                }
                $score = min(4,$spread)-$near;
                if ($best['score']!==null && $score<=$best['score']) continue;
                $best = ['action'=>$action,'reason'=>'spread_gate_destinations_near_allies','score'=>$score,
                    'factors'=>['spread'=>min(4,$spread),'ally_distance'=>-$near]];
            }
            return $best;
        }
        if ($decision === 'passive_task' && $control === 'choice_required') {
            return self::passiveTask($observation['passive_tasks'] ?? [],$legalActions['actions']);
        }
        if ($decision === 'defender' && $control === 'choice_required') {
            return self::defend($observation,$legalActions['actions']);
        }
        if ($decision === 'combat_dice_instant' && $control === 'reaction_window') {
            return self::combatInstant($observation['combat_dice_window'] ?? [],$legalActions['actions']);
        }
        if ($decision === 'additional_dice' && $control === 'choice_required') {
            return self::chooseDie($observation['additional_dice_choice'] ?? [],$legalActions['actions']);
        }
        if ($decision === 'strike_mode' && $control === 'choice_required') {
            return self::strikeMode($observation['strike_mode_choice'] ?? [],$legalActions['actions']);
        }
        if ($decision === 'strike_ack' && $control === 'confirm_information') {
            foreach ($legalActions['actions'] as $action) {
                if ($action['type'] === 'confirm_strike') {
                    return ['action'=>$action,'reason'=>'acknowledge_strike_result','score'=>0,'factors'=>[]];
                }
            }
        }
        if ($decision !== 'own_turn' || $control !== 'own_turn') {
            $stop['reason'] = ($decision === 'waiting' && $control === 'waiting')
                ? 'waiting' : 'unsupported_pending';
            return $stop;
        }

        $cards = array_column($observation['cards'], null, 'id');
        $requiredIds=array_column(array_filter($observation['forced_strikes'] ?? [],static fn($r)=>$r['status']==='required'),'source_id');
        if($requiredIds!==[])return self::requiredStrike($observation,$legalActions['actions'],$requiredIds,'strike');
        $baseline = self::voluntary($observation, $legalActions, $visited, $cards, $stop);
        $action = $baseline['action'] ?? null;
        if (!in_array($action['type'] ?? null, ['move', 'end_turn'], true)) return $baseline;
        if ($action['type'] === 'move'
            && ($cards[$action['payload']['card_id']]['contact_inventory'] ?? null) !== 'absent') return $baseline;

        $geometry = ContactOnlyDetour::evaluate($observation, $legalActions, $visited);
        if ($geometry['restricted'] === []) return $baseline;
        // Preserve an existing decreasing choice. Exposure constrains new alternatives.
        if ($action['type'] === 'move'
            && (!isset($geometry['restricted'][$action['payload']['card_id']])
                || isset($geometry['decreasing'][serialize($action)]))) return $baseline;

        $legalActions['actions'] = array_values(array_filter(
            $legalActions['actions'],
            static fn(array $candidate): bool => $candidate['type'] !== 'move'
                || !isset($geometry['restricted'][$candidate['payload']['card_id']])
                || isset($geometry['allowed'][serialize($candidate)])
        ));
        $plan = self::voluntary($observation, $legalActions, $visited, $cards, $stop);
        return ($plan['action']['type'] ?? null) === 'end_turn'
            ? ($geometry['fallback'] ?? $plan) : $plan;
    }

    private static function voluntary(array $observation, array $legalActions, array $visited, array $cards, array $stop): array
    {
        $field = array_filter($cards, static fn(array $card): bool => $card['zone'] === 'field');
        $enemies = array_filter($cards, static fn(array $card): bool => in_array($card['zone'],['field','flying'],true) &&
            $card['owner'] !== $observation['viewer'] && $card['identity'] === 'known');
        $groundEnemies=array_filter($enemies,static fn(array $card):bool=>$card['zone']==='field');
        $best = $stop;
        $best['reason'] = 'no_supported_action';
        foreach ($legalActions['actions'] as $action) {
            $p = $action['payload'];
            $source = $cards[$p['card_id'] ?? 0] ?? null;
            $target = $enemies[$p['target_id'] ?? 0] ?? null;
            $factors = match ($action['type']) {
                'end_turn' => [],
                'strike' => $source && $target ? [
                    'strike_opportunity'=>30,
                    'printed_strike'=>min(9, array_sum($source['strike'])),
                    'wounded_target'=>min(6, max(0, $target['hp_max'] - $target['hp'])),
                    'own_fragility'=>$source['hp'] <= 3 ? -6 : 0,
                ] : null,
                'action' => $source && $target ? (self::magicFactors($source,$target,$p)
                    ?? self::shotFactors($source,$target,$p,$enemies)) : null,
                'move' => $source && $source['zone']==='field' ? (self::moveFactors($source, $p, $groundEnemies, $field, $visited)
                    ?? self::shotMoveFactors($source,$p,$groundEnemies,$visited)) : null,
                default => null,
            };
            if ($factors === null) continue;
            $score = array_sum($factors);
            if (in_array($action['type'],['move','action'],true) && $score <= 0) continue;
            if ($best['score'] !== null && ($score < $best['score']
                || ($score === $best['score'] && serialize($action) >= serialize($best['action'])))) continue;
            $best = ['action'=>$action, 'reason'=>match ($action['type']) {
                'strike'=>'known_strike_opportunity', 'action'=>isset($factors['magic_opportunity']) ? 'known_basic_magic_opportunity' : 'known_shot_opportunity',
                'move'=>isset($factors['approach_known_shot_range']) ? 'improved_shot_position' : 'improved_contact_position', default=>'no_useful_supported_action',
            }, 'score'=>$score, 'factors'=>$factors];
        }
        if($best['action']===null && array_filter($observation['forced_strikes'] ?? [],static fn($r)=>$r['status']==='unknown'))
            $best['reason']='uncertain_forced_strike';
        if (($best['action']['type'] ?? null)==='end_turn')
            return PositionalProgressFallback::consider($observation,$legalActions,$visited,$best);
        return $best;
    }

    private static function combatPower(array $observation,array $actions):array
    {
        $best=['action'=>null,'reason'=>'unsupported_pending','score'=>null,'factors'=>[]];
        $window=$observation['combat_power_window']??[];
        if(($window['kind']??null)!=='self_strike_level_set_strong' || ($window['priority']??null)!==$observation['viewer'])return $best;
        foreach($actions as $action)if($action===['type'=>'combat_instant_pass','payload'=>[]] && !empty($window['can_pass']))
            $best=['action'=>$action,'reason'=>'pass_combat_power_priority','score'=>0,'factors'=>[]];
        if(($window['stack_count']??null)!==0 || !in_array($window['current_own_level']??null,['weak','medium'],true))return $best;
        $cards=array_column($observation['cards'],null,'id');
        foreach($window['options'] as $option){
            $action=['type'=>'combat_instant_play','payload'=>['card_id'=>$option['card_id'],'instant_key'=>$option['instant_key']]];
            if(!in_array($action,$actions,true))continue;$card=$cards[$option['card_id']]??null;
            if(!$card || $card['owner']!==$observation['viewer'] || $card['identity']!=='known')continue;
            $factors=['published_level_gain'=>$window['current_own_level']==='weak'?8:5,
                'coin_cost'=>-3*$option['cost'],'use_and_close'=>-1,'support_value'=>-min(4,max($card['strike']))];
            $score=array_sum($factors);
            if($score<=0 || ($best['score']!==null && ($score<$best['score'] || ($score===$best['score'] && serialize($action)>=serialize($best['action'])))))continue;
            $best=['action'=>$action,'reason'=>'improve_published_strike_level','score'=>$score,'factors'=>$factors];
        }
        return $best;
    }

    /** Relative known-card exposure, not a prediction of the server's damage formula. */
    private static function strikeRedirect(array $observation,array $actions):array
    {
        $best=['action'=>null,'reason'=>'unsupported_pending','score'=>null,'factors'=>[]];
        $choice=$observation['strike_redirect_choice']??[];
        if(($choice['owner']??null)!==$observation['viewer'] || ($choice['kind']??null)!=='attacker_strike_redirect_before_dice')return $best;
        $cards=array_column($observation['cards'],null,'id');$attacker=$cards[$choice['attacker_id']??0]??null;
        if(!$attacker || $attacker['identity']!=='known')return $best;
        $pressure=max($attacker['strike']);
        foreach($actions as $action){
            $id=$action['payload']['target_id']??null;
            if($action['type']!=='choose_redirect' || !is_int($id) || $id<0 || $action['payload']!==['target_id'=>$id])continue;
            $target=$cards[$id===0?($choice['original_target_id']??0):$id]??null;
            if(!$target || $target['owner']!==$observation['viewer'] || $target['identity']!=='known')continue;
            $factors=['health_buffer'=>min(10,$target['hp']),'known_armor'=>min(6,$target['armor']*2),
                'fragility'=>$target['hp']<=$pressure?-8:0,
                'card_value_at_risk'=>-min(12,array_sum($target['strike'])+intdiv($target['hp_max'],6)),
                'possible_open_answer'=>$target['closed']?-3:min(6,max($target['strike'])),
                'known_strike_pressure'=>-min(10,$pressure)];
            $score=array_sum($factors);
            if($best['score']!==null && ($score<$best['score'] || ($score===$best['score'] && $id>=$best['action']['payload']['target_id'])))continue;
            $best=['action'=>$action,'reason'=>'choose_strike_redirect','score'=>$score,'factors'=>$factors];
        }
        return $best;
    }

    /** Obligation selects among certified commands even when every tactical score is negative. */
    private static function requiredStrike(array $observation,array $actions,array $sources,string $type):array
    {
        $best=['action'=>null,'reason'=>$type==='strike'?'unsupported_forced_strike':'unsupported_pending','score'=>null,'factors'=>[]];
        $cards=array_column($observation['cards'],null,'id');
        foreach($actions as $action){
            if($action['type']!==$type)continue;
            $sourceId=$type==='strike'?($action['payload']['card_id'] ?? null):($observation['forced_strike_choice']['source_id'] ?? null);
            if(!in_array($sourceId,$sources,true))continue;
            $source=$cards[$sourceId] ?? null;$target=$cards[$action['payload']['target_id'] ?? 0] ?? null;
            if($source===null || $source['owner']!==$observation['viewer'] || $target===null || $target['identity']!=='known')continue;
            $factors=['known_strike'=>min(9,array_sum($source['strike'])),
                'wounded_target'=>min(6,max(0,$target['hp_max']-$target['hp'])),
                'known_armor'=>-min(6,$target['armor']*2),'own_fragility'=>$source['hp']<=3?-10:0,'uncertain_effects'=>-2];
            $score=array_sum($factors);
            if($best['score']!==null && ($score<$best['score'] || ($score===$best['score'] && serialize($action)>=serialize($best['action']))))continue;
            $best=['action'=>$action,'reason'=>$type==='strike'?'required_strike':'choose_required_strike_target','score'=>$score,'factors'=>$factors];
        }
        return $best;
    }

    private static function magicFactors(array $source, array $target, array $payload): ?array
    {
        $profile=null;
        foreach ($source['basic_magic_profiles'] ?? [] as $magic)
            if ($magic['action_key']===($payload['action_key'] ?? null)) {$profile=$magic;break;}
        if ($profile===null) return null;
        $penalty=0;
        $keys=['zom','zoa'];
        if ($source['type']==='creature') $keys[]='zoan';
        if ($source['type']==='fly') $keys[]='zoal';
        foreach ($keys as $key) {
            $direct=$target['properties']['defenses'][$key]['direct'] ?? 'unknown';
            if ($direct==='present') $penalty=100;
            elseif ($direct==='conditional') $penalty=max($penalty,12);
        }
        // A printed value is an opportunity, never a prediction of DamageResolver's final result.
        return ['magic_opportunity'=>$profile['value']>0 ? 20 : -100,
            'known_magic_value'=>min(12,3*$profile['value']),
            'wounded_target'=>min(6,max(0,$target['hp_max']-$target['hp'])),
            'possible_finish'=>$profile['value']>=$target['hp'] ? 6 : 0,
            'known_magic_defense'=>-$penalty,'spend_source_action'=>-2,
            'unresolved_magic_context'=>-2,'own_fragility'=>$source['hp']<=3 ? -4 : 0];
    }

    private static function shotFactors(array $source, array $target, array $payload, array $enemies): ?array
    {
        $profile=null;
        foreach($source['shot_profiles'] ?? [] as $shot) if($shot['action_key']===($payload['action_key'] ?? null)) {$profile=$shot;break;}
        if($profile===null || $profile['type']!=='shot') return null;
        $strength=max(0,array_sum($profile['damage_profile'])/3+$profile['own_damage_bonus']);
        $maximum=max($profile['damage_profile'])+$profile['own_damage_bonus'];
        if($maximum<=0) return null;
        $defenses=$target['properties']['defenses'] ?? [];
        $keys=['zoa','zov','zoda'];
        if($source['type']==='creature')$keys[]='zoan';
        if($source['type']==='fly')$keys[]='zoal';
        $defensePenalty=0;
        foreach($keys as $key) {
            $direct=$defenses[$key]['direct'] ?? 'unknown';
            if($direct==='present')$defensePenalty=max($defensePenalty,100);
            elseif($direct==='conditional')$defensePenalty=max($defensePenalty,12);
        }
        $exposed=false;
        foreach($enemies as $enemy) {
            if($enemy['zone']==='flying' || ($source['zone']==='field' && $enemy['zone']==='field'
                && max(abs($source['row']-$enemy['row']),abs($source['col']-$enemy['col']))<=1)) {$exposed=true;break;}
        }
        return ['shot_opportunity'=>20,'known_profile'=>min(12,(int)floor(3*$strength)),
            'wounded_target'=>min(6,max(0,$target['hp_max']-$target['hp'])),
            'possible_finish'=>$maximum>=$target['hp'] ? 6 : 0,
            'known_defense'=>-$defensePenalty,'known_armor'=>-min(6,$target['armor']*2),
            'unresolved_context'=>-2,
            'safe_position'=>$exposed || $source['zone']==='flying' ? 0 : 3,'own_fragility'=>$source['hp']<=3 ? -4 : 0];
    }

    /** A positioning hint over existing moves, never a claim that a future shot is legal. */
    private static function shotMoveFactors(array $source, array $dest, array $enemies, array $visited): ?array
    {
        if(isset($visited[$source['id']][$dest['row'].':'.$dest['col']]))return null;
        foreach($source['shot_profiles'] ?? [] as $shot) {
            if(!is_int($shot['range']) || $shot['range']<=0)continue;
            foreach($enemies as $enemy) {
                $old=abs($source['row']-$enemy['row'])+abs($source['col']-$enemy['col']);
                $new=abs($dest['row']-$enemy['row'])+abs($dest['col']-$enemy['col']);
                $near=max(abs($dest['row']-$enemy['row']),abs($dest['col']-$enemy['col']))<=1;
                if($old>$shot['range'] && $new<$old && (!$near || $shot['near_shot'])) {
                    return ['approach_known_shot_range'=>$new<=$shot['range'] ? 5 : 2,'own_fragility'=>$source['hp']<=3 ? -4 : 0];
                }
            }
        }
        return null;
    }

    private static function greedTeleport(array $observation, array $actions): array
    {
        $best = ['action'=>null,'reason'=>'unsupported_pending','score'=>null,'factors'=>[]];
        $choice = $observation['greed_teleport_choice'] ?? [];
        $cards = array_column($observation['cards'],null,'id');
        $source = $cards[$choice['source_id'] ?? 0] ?? null;
        if ($source===null) return $best;
        foreach ($actions as $action) {
            if ($action['type']!=='choose_greed_teleport') continue;
            $destination = $action['payload'];
            $allies = $enemies = $unknown = 0;
            foreach ($cards as $card) {
                if ($card['zone']!=='field' || $card['id']===$source['id']) continue;
                $distance = max(abs($card['row']-$destination['row']),abs($card['col']-$destination['col']));
                if ($distance>1) continue;
                if ($card['owner']===$observation['viewer']) $allies++;
                elseif ($card['identity']==='known') $enemies++;
                else $unknown++;
            }
            $factors = ['potential_targets'=>$choice['potential_adjacent_enemy_damage']>0 ? min(3,$enemies)*2 : 0,
                'ally_support'=>min(2,$allies),'isolation'=>$allies===0 ? -2 : 0,
                'fragile_exposure'=>$source['hp']<=3 ? -3*($enemies+$unknown) : -$unknown];
            $score = array_sum($factors);
            if ($best['score']!==null && ($score<$best['score']
                || ($score===$best['score'] && serialize($destination)>=serialize($best['action']['payload'])))) continue;
            $best = ['action'=>$action,'reason'=>'compare_known_gate_destinations','score'=>$score,'factors'=>$factors];
        }
        return $best;
    }

    private static function passiveTask(array $window, array $actions): array
    {
        $best = ['action'=>null,'reason'=>'unsupported_pending','score'=>null,'factors'=>[]];
        $options = array_column($window['options'] ?? [],null,'task_id');
        foreach ($actions as $action) {
            if ($action['type'] !== 'turn_task') continue;
            $id = $action['payload']['task_id'];
            $task = $options[$id] ?? null;
            if ($task === null) continue;
            $factors = match ($task['type']) {
                'place_gates'=>['required_cells'=>-$task['gate_count']],
                // Shared gates may be consumed by the first effect. Prefer the more vulnerable source.
                'greed_teleport'=>['self_damage_risk'=>$task['potential_self_damage']-$task['source_hp']],
                default=>null,
            };
            if ($factors === null) continue;
            $score = array_sum($factors);
            if ($best['score'] !== null && ($score < $best['score']
                || ($score === $best['score'] && strcmp($id,$best['action']['payload']['task_id']) >= 0))) continue;
            $best = ['action'=>$action,'reason'=>$task['type']==='place_gates'
                ? 'prefer_fewer_required_gate_cells' : 'prioritize_vulnerable_passive_source',
                'score'=>$score,'factors'=>$factors + ['resource_cost'=>0]];
        }
        return $best;
    }

    private static function combatInstant(array $window, array $actions): array
    {
        $best = ['action'=>null,'reason'=>'unsupported_pending','score'=>null,'factors'=>[]];
        $die = $window['opposing_die'] ?? null;
        foreach ($actions as $action) {
            if ($action['type'] === 'combat_instant_pass') {
                if ($best['action'] === null) $best = ['action'=>$action,'reason'=>'pass_combat_priority','score'=>0,'factors'=>[]];
                continue;
            }
            if ($action['type'] !== 'combat_instant_play' || empty($window['stack_empty'])
                || !isset($window['target_id'],$die['value'],$die['modifier']) || $die['value'] <= 0) continue;
            foreach ($window['options'] as $option) {
                if ($action['payload']['card_id'] !== $option['card_id']
                    || $action['payload']['instant_key'] !== $option['instant_key']
                    || $die['value']+$die['modifier'] !== $option['required_total']) continue;
                $score = $option['damage'] - 2*$option['cost'];
                if ($score <= 0 || ($best['score'] !== null && $score <= $best['score'])) continue;
                $best = ['action'=>$action,'reason'=>'published_die_matches_conditional_damage','score'=>$score,
                    'factors'=>['potential_damage'=>$option['damage'],'coin_penalty'=>-2*$option['cost']]];
            }
        }
        return $best;
    }

    private static function chooseDie(array $choice, array $actions): array
    {
        $best = ['action'=>null,'reason'=>'unsupported_pending','score'=>null,'factors'=>[]];
        $strength = [''=>0,'weak'=>1,'medium'=>2,'strong'=>3];
        $role = $choice['role'] ?? null;
        foreach ($actions as $action) {
            if ($action['type'] !== 'choose_temple_dice') continue;
            $value = $action['payload']['value'];
            $levels = $choice['unmodified_levels'][$value] ?? null;
            $factors = ['published_die'=>$value];
            if ($levels !== null && in_array($role,['attack','defend'],true)) {
                $factors = ['outgoing_level'=>$strength[$levels[$role]],
                    'incoming_level'=>-$strength[$levels[$role === 'attack' ? 'defend' : 'attack']]];
            }
            $score = array_sum($factors);
            if ($best['score'] !== null && ($score < $best['score']
                || ($score === $best['score'] && $value <= $best['action']['payload']['value']))) continue;
            $best = ['action'=>$action,'reason'=>$levels === null ? 'higher_published_die_uncertain_outcome' : 'compare_unmodified_strike_levels',
                'score'=>$score,'factors'=>$factors];
        }
        return $best;
    }

    private static function strikeMode(array $choice, array $actions): array
    {
        $best = ['action'=>null,'reason'=>'unsupported_pending','score'=>null,'factors'=>[]];
        $strength = [''=>0,'weak'=>1,'medium'=>2,'strong'=>3];
        $role = $choice['role'] ?? null;
        foreach ($actions as $action) {
            if ($action['type'] !== 'choose_strike_mode') continue;
            $mode = $action['payload']['mode'];
            $levels = $choice['levels'][$mode] ?? null;
            $factors = [];
            if ($levels !== null && in_array($role,['attack','defend'],true)) {
                $own = $strength[$levels[$role]];
                $incoming = $strength[$levels[$role === 'attack' ? 'defend' : 'attack']];
                $factors = [
                    'outgoing_level'=>$own * ($role === 'attack' ? 2 : 1),
                    'incoming_level'=>-$incoming * ($role === 'defend' ? 2 : 1),
                ];
            }
            $score = array_sum($factors);
            if ($best['score'] !== null && ($score < $best['score'] || ($score === $best['score'] && $mode !== 'normal'))) continue;
            $best = ['action'=>$action,'reason'=>$factors === [] ? 'unknown_effects_keep_base_result' : 'compare_visible_strike_levels',
                'score'=>$score,'factors'=>$factors];
        }
        return $best;
    }

    private static function defend(array $observation, array $actions): array
    {
        $best = ['action'=>null,'reason'=>'unsupported_pending','score'=>null,'factors'=>[]];
        $choice = $observation['defender_choice'] ?? [];
        if (($choice['owner'] ?? null) !== $observation['viewer']) return $best;
        $cards = array_column($observation['cards'],null,'id');
        $target = $cards[$choice['target_id'] ?? 0] ?? null;
        foreach ($actions as $action) {
            if ($action['type'] !== 'choose_defender') continue;
            $id = $action['payload']['defender_id'];
            $card = $cards[$id] ?? null;
            $factors = [];
            if ($id !== 0) {
                if (!isset($card['hp'], $target['hp'])) continue;
                $factors = [
                    'known_durability_gain'=>$card['hp'] + $card['armor'] - $target['hp'] - $target['armor'],
                    'defender_commitment'=>-2,
                ];
            }
            $score = array_sum($factors);
            if ($id !== 0 && $score <= 0) continue;
            if ($best['score'] !== null && ($score < $best['score']
                || ($score === $best['score'] && $id >= $best['action']['payload']['defender_id']))) continue;
            $best = ['action'=>$action,'reason'=>$id === 0 ? 'keep_original_target' : 'prefer_more_durable_defender',
                'score'=>$score,'factors'=>$factors];
        }
        return $best;
    }

    private static function moveFactors(array $source, array $dest, array $enemies, array $field, array $visited): ?array
    {
        $key = $dest['row'].':'.$dest['col'];
        if ($enemies === [] || isset($visited[$source['id']][$key]) || array_sum($source['strike']) <= 0) return null;
        $distance = static fn(array $a, array $b): int => max(abs($a['row']-$b['row']), abs($a['col']-$b['col']));
        $old = min(array_map(static fn($enemy) => $distance($source, $enemy), $enemies));
        $new = min(array_map(static fn($enemy) => $distance($dest, $enemy), $enemies));
        if ($new >= $old) return null;
        $allies = array_filter($field, static fn($card) => $card['owner'] === $source['owner'] && $card['id'] !== $source['id']);
        $supported = array_filter($allies, static fn($card) => $distance($dest, $card) <= 1) !== [];
        return [
            'approach'=>min(6, 3 * ($old-$new)),
            'contact'=>$new === 1 ? 2 : 0,
            'isolated'=>$supported ? 0 : -2,
            'own_fragility'=>$source['hp'] <= 3 ? -8 : 0,
        ];
    }
    private static function turnInstant(array $o,array $actions):array
    {
        $c=$o['turn_instant_choice']??[];$best=['action'=>null,'reason'=>'unsupported_turn_instant','score'=>null,'factors'=>[]];
        if(($c['owner']??null)!==$o['viewer'])return $best;
        foreach($actions as$a){$score=null;
            if($c['kind']==='result'&&$a===['type'=>'turn_instant_result_ok','payload'=>['ack_id'=>$c['ack_id']]])$score=0;
            if($c['kind']==='stack'&&$a===['type'=>'pass_turn_instant','payload'=>[]])$score=0;
            if($c['kind']==='sub'){
                if($a===['type'=>'turn_sub_close','payload'=>[]])$score=0;
                foreach($c['options']as$x)if($a===['type'=>'turn_sub','payload'=>['sub_id'=>$x['sub_id']]]){
                    foreach($x['targets']as$t){$v=self::turnTargetScore($x['effect'],$t)-3*$x['cost']-1;$score=$score===null?$v:max($score,$v);}
                }
            }
            if($c['kind']==='target'&&$a===['type'=>'cancel_pending','payload'=>[]]&&$c['can_cancel'])$score=0;
            if($c['kind']==='target'&&$a['type']==='choose_instant_pick')foreach($c['targets']as$t)if($a['payload']===['target_id'=>$t['target_id']])$score=self::turnTargetScore($c['effect'],$t);
            if($score===null)continue;
            if($best['score']!==null&&($score<$best['score']||($score===$best['score']&&strcmp(json_encode($a),json_encode($best['action']))>=0)))continue;
            $best=['action'=>$a,'reason'=>'supported_turn_instant_'.$c['kind'],'score'=>$score,'factors'=>[]];
        }
        return $best;
    }
    private static function turnTargetScore(string $effect,array $target):int
    {
        if($effect==='open')return $target['hp']<=$target['damage']?-100:8-2*$target['damage'];
        return $target['closed']?-1:6;
    }

    private static function rootAutoShot(array $o,array $actions):array
    {
        $c=$o['auto_shot_choice']??[];$best=['action'=>null,'reason'=>'unsupported_pending','score'=>null,'factors'=>[]];
        if(($c['kind']??null)!=='root_after_hit_shot'||($c['owner']??null)!==$o['viewer'])return $best;
        $cards=array_column($o['cards'],null,'id');
        foreach($actions as$a){
            if($a['type']!=='choose_auto_target'||!is_int($a['payload']['target_id']??null))continue;
            $id=$a['payload']['target_id'];$score=0;$factors=[];
            if($id!==0){$t=$cards[$id]??null;if(!$t||$t['identity']!=='known'||$t['owner']===$o['viewer'])continue;
                $useful=min($t['hp'],max(0,$c['damage']-$t['armor']));if($useful<=0)continue;
                $factors=['known_damage_estimate'=>10*$useful,'possible_finish'=>$useful>=$t['hp']?8:0,'known_printed_value'=>min(6,array_sum($t['strike']))];$score=array_sum($factors);
            }
            if($best['score']!==null&&($score<$best['score']||($score===$best['score']&&$id>=$best['action']['payload']['target_id'])))continue;
            $best=['action'=>$a,'reason'=>$id===0?'decline_root_auto_shot':'known_root_auto_shot_target','score'=>$score,'factors'=>$factors];
        }
        return $best;
    }

}
