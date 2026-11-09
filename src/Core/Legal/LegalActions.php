<?php
declare(strict_types=1);

namespace Berserk\Core\Legal;

use Berserk\Core\{BattleHelper, BattleWaitResolver, CardInstance, CardStats, CoinAbilities, Command,
    ExpiredCommandGuard, GameState, InformationalWaitManager, InstantReservation, MatchPause, PathfinderProcessor,
    Strangulation, ZoneManager};
use Berserk\Core\Choice\{ChoiceRegistry, StrangulationChoice, TempleDiceChoice, TurnPhaseChoice, GatePickChoice, GreedTeleportChoice, TurnAckChoice};
use Berserk\Core\Observation\{PlayerObservation, VisibilityPolicy};
use Berserk\Core\Observation\OrdinaryShots;
use Berserk\Core\Observation\BasicMagic;
use Berserk\Core\Observation\ForcedStrikeKnowledge;
use Berserk\Core\Observation\StrikeRedirectKnowledge;
use Berserk\Core\Observation\{CombatMainPassKnowledge, CombatPowerKnowledge};
use Berserk\Core\Choice\ForcedStrikeChoice;
use Berserk\Core\Prepare\PrepareProcessor;

final class LegalActions
{
    /** The caller establishes the viewer's side and supplies a consistent snapshot. */
    public static function build(GameState $state, string $viewer, ?int $now = null): array
    {
        if (!VisibilityPolicy::isSide($viewer)) {
            throw new \InvalidArgumentException('Invalid legal actions side');
        }
        $now ??= time();
        $out = [
            'viewer' => $viewer, 'version' => $state->persistenceVersion,
            'stage' => in_array($state->status, ['place', 'battle', 'game_over'], true) ? $state->status : 'other',
            'decision' => 'not_applicable', 'coverage' => 'unsupported', 'actions' => [], 'candidates' => [],
        ];
        if (MatchPause::blocking($state)) {
            $out['decision'] = 'paused';
            return $out;
        }

        if ($state->status === 'place') {
            $out['decision'] = 'placement';
            if (PathfinderProcessor::active($state) !== null || ChoiceRegistry::current($state) !== null) return $out;
            if ($state->getPlayer($viewer)->isConfirmed('place')) {
                return array_replace($out, ['decision' => 'waiting', 'coverage' => 'complete']);
            }
            $out['coverage'] = 'partial';
            $out['actions'] = self::placement($state, $viewer);
            return $out;
        }

        if ($state->status !== 'battle' || $state->winner !== null) return $out;
        $wait = BattleWaitResolver::current($state);
        if (($wait['kind'] ?? null) === 'unknown') {
            if (in_array($wait['reason']??null,['turn_ack_legacy_contract','turn_instant_result_legacy_contract'],true)) $out['reason']=$wait['reason'];
            return array_replace($out, ['decision' => 'unknown', 'coverage' => 'unknown']);
        }
        $handler = ChoiceRegistry::current($state);
        if ($wait !== null || $handler !== null) {
            $kind = $wait['kind'] ?? 'unknown';
            if ($kind === 'unknown') {
                return array_replace($out, ['decision' => 'unknown', 'coverage' => 'unknown']);
            }
            if (!in_array($viewer, $wait['required_players'], true)) {
                $out['decision'] = 'waiting';
                return $out;
            }
            $out['decision'] = $kind === 'informational' ? 'information' : 'choice';
            $autoShot=\Berserk\Core\Observation\RootAutoShotKnowledge::choice($state,$viewer);
            if($autoShot!==null){
                $out['decision']='root_auto_shot';$out['coverage']='partial';
                foreach(array_merge([0],\Berserk\Core\Observation\RootAutoShotKnowledge::alternatives($state,$viewer))as$id){
                    $option=self::option('choose_auto_target',['target_id'=>$id]);
                    if(self::passesClock($state,$viewer,$option,$now))$out['actions'][]=$option;
                }
                return $out;
            }
            $prophecy = \Berserk\Core\Observation\ProphecyKnowledge::choice($state, $viewer, $wait);
            if ($prophecy !== null) {
                $out['decision'] = $prophecy['kind'];
                $out['coverage'] = 'partial';
                $options = match ($prophecy['kind']) {
                    'prophecy_source' => array_map(
                        static fn(array $item): array => self::option('turn_sub', ['sub_id'=>$item['sub_id']]),
                        $prophecy['options'],
                    ),
                    'prophecy_close', 'prophecy_transform_decline' => [self::option('close_prophecy')],
                    default => [],
                };
                foreach ($options as $option) {
                    if (self::passesClock($state, $viewer, $option, $now)) $out['actions'][] = $option;
                }
                return $out;
            }
            $turnInstant=\Berserk\Core\Observation\TurnInstantKnowledge::build($state,$viewer);
            if($turnInstant===null&&in_array($wait['source'],['turn_phase.sub','pending_instant_pick','turn_instant_stack','turn_instant_result'],true))$out['reason']='unsupported_turn_instant_context:'.$wait['source'];
            if($turnInstant!==null){
                $out['decision']='turn_instant_'.$turnInstant['kind'];$out['coverage']='partial';$options=[];
                switch($turnInstant['kind']){
                    case 'sub': foreach($turnInstant['options'] as $option)$options[]=self::option('turn_sub',['sub_id'=>$option['sub_id']]);
                        if($turnInstant['can_close'])$options[]=self::option('turn_sub_close',[]);break;
                    case 'target':foreach($turnInstant['targets'] as $target)$options[]=self::option('choose_instant_pick',['target_id'=>$target['target_id']]);if($turnInstant['can_cancel'])$options[]=self::option('cancel_pending',[]);break;
                    case 'after_terminal_pass':
                    case 'terminal_pass':
                    case 'stack':$options[]=self::option('pass_turn_instant',[]);break;
                    case 'result':$options[]=self::option('turn_instant_result_ok',['ack_id'=>$turnInstant['ack_id']]);break;
                }
                foreach($options as$option)if(self::passesClock($state,$viewer,$option,$now))$out['actions'][]=$option;
            } else
            if(($redirect=StrikeRedirectKnowledge::choice($state,$viewer))!==null){
                $out['decision']='strike_redirect';$out['coverage']='partial';
                foreach(array_merge([0],StrikeRedirectKnowledge::alternatives($state,$viewer)) as $id){
                    $option=self::option('choose_redirect',['target_id'=>$id]);
                    if(self::passesClock($state,$viewer,$option,$now))$out['actions'][]=$option;
                }
            } else            if($handler instanceof ForcedStrikeChoice && $wait['source']==='pending_forced_strike'){
                $out['decision']='forced_strike_choice';$out['coverage']='partial';
                $out['actions']=self::forcedChoice($state,$viewer,$now);
            } elseif ($handler instanceof TurnAckChoice && ($ack = PlayerObservation::turnPhaseAck($state,$viewer,$wait)) !== null) {
                $out['decision'] = 'turn_phase_ack';
                $out['coverage'] = 'complete';
                $option = self::option('turn_ack',['ack_id'=>$ack['ack_id']]);
                if (self::passesClock($state,$viewer,$option,$now)) $out['actions'][] = $option;
            } elseif ($handler instanceof StrangulationChoice && $kind === 'decision'
                && $wait['source'] === Strangulation::PENDING) {
                $out['decision'] = 'strangulation';
                $out['coverage'] = 'partial';
                $out['actions'] = self::strangulation($state, $viewer, $now);
            } elseif ($handler === null && $wait['source'] === 'strike.waiting_defender') {
                $out['decision'] = 'defender';
                $out['coverage'] = 'partial';
                $strike = $state->battle['strike'];
                // Zero declines replacement and resolves the attack against the original target.
                $options = [self::option('choose_defender', ['defender_id'=>0])];
                if (empty($strike['matriarch_order'])) {
                    foreach ($strike['defenders'] ?? [] as $id) {
                        $card = is_int($id) && $id > 0 ? $state->getCard($id) : null;
                        if ($card !== null && $card->owner === $viewer
                            && VisibilityPolicy::card($state, $card, $viewer) === 'known') {
                            $options[] = self::option('choose_defender', ['defender_id'=>$id]);
                        }
                    }
                }
                foreach ($options as $option) {
                    if (self::passesClock($state, $viewer, $option, $now)) $out['actions'][] = $option;
                }
            } elseif ($handler instanceof GreedTeleportChoice
                && ($teleport = PlayerObservation::greedTeleportChoice($state,$viewer,$wait)) !== null) {
                $out['decision'] = 'greed_teleport';
                $out['coverage'] = 'partial';
                foreach ($teleport['destinations'] as $destination) {
                    $option = self::option('choose_greed_teleport',$destination);
                    if (self::passesClock($state,$viewer,$option,$now)) $out['actions'][] = $option;
                }
            } elseif ($handler instanceof GatePickChoice && $wait['source']==='pending_gate_pick') {
                $pending = $state->battle['pending_gate_pick'];
                $out['decision'] = 'gate_cell';
                $out['coverage'] = 'partial';
                if (count($pending['chosen']) < (int)$pending['count']) {
                    foreach ($pending['candidates'] as $cell) {
                        if (!preg_match('/^[1-6]_[1-5]$/D',$cell)) continue;
                        [$row,$col] = array_map('intval',explode('_',$cell));
                        $option = self::option('choose_gate',['row'=>$row,'col'=>$col]);
                        if (self::passesClock($state,$viewer,$option,$now)) $out['actions'][] = $option;
                    }
                }
            } elseif ($handler instanceof TurnPhaseChoice && ($tasks = PlayerObservation::passiveTasks($state,$viewer,$wait)) !== []) {
                $out['decision'] = 'passive_task';
                $out['coverage'] = 'partial';
                foreach ($tasks as $task) {
                    $option = self::option('turn_task',['task_id'=>$task['task_id']]);
                    if (self::passesClock($state,$viewer,$option,$now)) $out['actions'][] = $option;
                }
            } elseif ($handler instanceof TempleDiceChoice && $wait['source'] === $handler->pendingKey()
                && ($state->battle['strike']['state'] ?? null) === 'waiting_temple_dice') {
                $pending = $state->battle[$handler->pendingKey()];
                if (is_string($pending['id'] ?? null) && $pending['id'] !== ''
                    && in_array($pending['side'] ?? null,['attack','defend'],true)) {
                    $out['decision'] = 'additional_dice';
                    $out['coverage'] = 'complete';
                    foreach ($pending['values'] ?? [] as $value) {
                        if (!is_int($value) || $value < 1 || $value > 6) continue;
                        $option = self::option('choose_temple_dice',['id'=>$pending['id'],'value'=>$value]);
                        if (self::passesClock($state,$viewer,$option,$now)) $out['actions'][] = $option;
                    }
                }
            } elseif ($handler === null && $wait['source'] === 'strike.waiting_choice') {
                $out['decision'] = 'strike_mode';
                $out['coverage'] = 'partial';
                foreach (['normal','decrease'] as $mode) {
                    $option = self::option('choose_strike_mode', ['mode'=>$mode]);
                    if (self::passesClock($state,$viewer,$option,$now)) $out['actions'][] = $option;
                }
            } elseif (($power=CombatPowerKnowledge::window($state,$viewer))!==null) {
                $out['decision']='combat_power_instant';$out['coverage']='partial';
                $options=[self::option('combat_instant_pass')];
                foreach($power['options'] as $instant)$options[]=self::option('combat_instant_play',['card_id'=>$instant['card_id'],'instant_key'=>$instant['instant_key']]);
                foreach($options as $option)if(self::passesClock($state,$viewer,$option,$now))$out['actions'][]=$option;
            } elseif ($handler === null && ($window = PlayerObservation::combatDiceWindow($state,$viewer,$wait)) !== null) {
                $out['decision'] = 'combat_dice_instant';
                $out['coverage'] = 'partial';
                $options = [self::option('combat_instant_pass')];
                foreach ($window['options'] as $instant) {
                    $options[] = self::option('combat_instant_play', ['card_id'=>$instant['card_id'],'instant_key'=>$instant['instant_key']]);
                }
                foreach ($options as $option) {
                    if (self::passesClock($state,$viewer,$option,$now)) $out['actions'][] = $option;
                }
            } elseif (CombatMainPassKnowledge::window($state, $viewer, $now) !== null) {
                $out['decision'] = 'combat_main_pass';
                $out['coverage'] = 'partial';
                $out['actions'] = [self::option('combat_instant_pass')];
            } elseif ($wait['source'] === 'pending_coin_ability'
                && ($coin = $state->battle['pending_coin_ability'] ?? [])['kind'] === 'gain'
                && $coin['owner'] === $viewer) {
                $option = self::option('choose_coin_ability', ['choice_id' => $coin['id'], 'choice' => 'yes']);
                if (self::passesClock($state, $viewer, $option, $now)) {
                    $out['decision'] = 'coin_gain';
                    $out['coverage'] = 'complete';
                    $out['actions'][] = $option;
                }
            } elseif ($handler === null && $wait['source'] === 'strike.results'
                && (in_array($state->battle['strike']['kind'] ?? '', ['', 'shot', 'tap', 'discharge', 'uchr', 'heal', 'multi_heal', 'poison_target', 'damage_poisoned'], true)
                    || BasicMagic::resultAck($state,$viewer))
                && in_array($state->battle['strike']['instant_result_stage'] ?? null,[null,'dice'],true)
                && empty($state->battle['strike']['pending_wounds_resolution'])) {
                $token = InformationalWaitManager::token($state);
                $option = self::option('confirm_strike', ['wait_id'=>$token]);
                if ($token !== null && self::passesClock($state, $viewer, $option, $now)) {
                    $out['decision'] = 'strike_ack';
                    $out['coverage'] = 'partial';
                    $out['actions'][] = $option;
                }
            }
            return $out;
        }

        $active = $state->battle['active'] ?? null;
        if (!VisibilityPolicy::isSide($active)) {
            return array_replace($out, ['decision' => 'unknown', 'coverage' => 'unknown']);
        }
        if ($active !== $viewer) {
            $out['decision'] = 'waiting';
            return $out;
        }
        if (!empty($state->battle['turn_phase']) || !empty($state->battle['strike']) || !empty($state->battle['matriarch_order'])
            || InstantReservation::blocks($state, 'strike')) {
            $out['decision'] = 'blocked';
            return $out;
        }

        $out['decision'] = 'own_turn';
        $out['coverage'] = 'partial';
        [$out['actions'], $out['candidates']] = self::strikes($state, $viewer, $now);
        $out['actions']=array_merge($out['actions'],self::airStrikes($state,$viewer,$now));
        $out['actions']=array_merge($out['actions'],self::shots($state,$viewer,$now));
        $out['actions']=array_merge($out['actions'],self::magic($state,$viewer,$now));
        foreach ($state->getCardsInZone($viewer, CardInstance::ZONE_FIELD) as $card) {
            if ($card->dying || $card->hp <= 0) continue;
            // The forced movement helper probes reachability; certify it only on a fully known battlefield.
            if(!empty($card->effectiveProp()['forced_strike'])
                && (self::hiddenBattlefield($state,$viewer) || !empty($card->effectiveProp()['row_extreme'])))continue;
            foreach (BattleHelper::getMoveCells($state, $card) as $cell => $_) {
                [$row, $col] = array_map('intval', explode('_', $cell));
                $option = self::option('move', ['card_id'=>$card->instanceId, 'row'=>$row, 'col'=>$col]);
                if (self::passesClock($state, $viewer, $option, $now)) $out['actions'][] = $option;
            }
        }
        $end = self::option('end_turn');
        if (self::canEndTurn($state, $viewer) && self::passesClock($state, $viewer, $end, $now)) {
            $out['actions'][] = $end;
        }
        return $out;
    }

    private static function magic(GameState $state, string $viewer, int $now): array
    {
        foreach ($state->battle as $key=>$value)
            if (str_starts_with((string)$key,'pending_') && !empty($value)) return [];
        if (!BasicMagic::knownContext($state,$viewer)) return [];
        foreach ($state->getCardsInZone($viewer,CardInstance::ZONE_FIELD) as $card)
            if (CardStats::getForcedStrikeTarget($state,$card)!==null) return [];
        $out=[];
        foreach ($state->getCardsInZone($viewer,CardInstance::ZONE_FIELD) as $source) {
            if ($source->dying || $source->hp<=0 || CardStats::isDisabled($source)
                || CardStats::hasCannotAttack($source) || Strangulation::blocksAttack($source,'magic')) continue;
            if (!is_int($source->row) || !is_int($source->col)
                || $source->row<1 || $source->row>6 || $source->col<1 || $source->col>5) continue;
            foreach (BasicMagic::profiles($state,$source) as $profile) {
                $action=['type'=>'magic','value'=>$profile['value']];
                if (CardStats::getEffectiveRange($state,$source,$action)!==0) continue;
                $targets=BattleHelper::getAttackTargets($state,$source,'action:'.$profile['action_key'],$viewer);
                foreach ($state->getCardsInZone($state->getOpponentKey($viewer),CardInstance::ZONE_FIELD) as $target) {
                    if (!$target->revealed || $target->dying || $target->hp<=0 || !isset($targets[$target->instanceId])) continue;
                    if (!is_int($target->row) || !is_int($target->col)
                        || $target->row<1 || $target->row>6 || $target->col<1 || $target->col>5) continue;
                    $dr=abs($source->row-$target->row);$dc=abs($source->col-$target->col);
                    if ($dr>1 || $dc>1 || $dr+$dc===0) continue;
                    // Protection affects usefulness; native magic may legally resolve to zero damage.
                    $option=self::option('action',['card_id'=>$source->instanceId,'target_id'=>$target->instanceId,'action_key'=>$profile['action_key']]);
                    if (self::passesClock($state,$viewer,$option,$now)) $out[]=$option;
                }
            }
        }
        return $out;
    }

    private static function shots(GameState $state, string $viewer, int $now): array
    {
        // Hidden interceptors/range auras can change helper membership. Never consult their traits.
        foreach($state->cards as $card) if(VisibilityPolicy::card($state,$card,$viewer)==='unknown') return [];
        foreach($state->getCardsInZone($viewer,CardInstance::ZONE_FIELD) as $card) {
            if(CardStats::getForcedStrikeTarget($state,$card)!==null) return [];
        }
        $out=[];
        foreach($state->cards as $source) {
            if($source->owner!==$viewer || !in_array($source->zone,[CardInstance::ZONE_FIELD,CardInstance::ZONE_FLYING],true))continue;
            if($source->dying || $source->hp<=0 || CardStats::isDisabled($source)
                || CardStats::hasCannotAttack($source) || Strangulation::blocksAttack($source,'shot')) continue;
            foreach(OrdinaryShots::profiles($state,$source) as $profile) {
                $targets=BattleHelper::getAttackTargets($state,$source,'action:'.$profile['action_key'],$viewer);
                foreach($state->cards as $target) {
                    if($target->owner===$viewer || !in_array($target->zone,[CardInstance::ZONE_FIELD,CardInstance::ZONE_FLYING],true))continue;
                    if(!$target->revealed || $target->dying || $target->hp<=0 || !isset($targets[$target->instanceId])) continue;
                    $option=self::option('action',['card_id'=>$source->instanceId,'target_id'=>$target->instanceId,'action_key'=>$profile['action_key']]);
                    if(self::passesClock($state,$viewer,$option,$now)) $out[]=$option;
                }
            }
        }
        return $out;
    }

    private static function airStrikes(GameState $state,string $viewer,int $now):array
    {
        foreach($state->cards as $card)if(VisibilityPolicy::card($state,$card,$viewer)==='unknown')return [];
        $out=[];
        foreach($state->getCardsInZone($viewer,CardInstance::ZONE_FLYING) as $source) {
            if($source->dying || $source->hp<=0 || CardStats::isDisabled($source)
                || CardStats::hasCannotAttack($source) || !CardStats::hasAnyStrike($source))continue;
            $prop=$source->effectiveProp();
            if(!empty($prop['strike']['opposite']) || !empty($prop['strike']['redirect']) || !empty($prop['forced_strike']) || !empty($prop['attack_series'])
                || !empty($source->flags['attack_series_active']))continue;
            $used=(int)($source->flags['attacks_used_this_turn'] ?? 0);
            $limit=(int)($prop['attacks_per_turn'] ?? 1);
            if(!empty($prop['strike_consecutive']) && !empty($source->flags['strike_chain_broken']))$limit=1;
            if($used>=$limit)continue;
            $targets=BattleHelper::getAttackTargets($state,$source,'strike',$viewer);
            foreach($state->cards as $target) {
                if($target->owner===$viewer || !in_array($target->zone,[CardInstance::ZONE_FIELD,CardInstance::ZONE_FLYING],true)
                    || !$target->revealed || $target->dying || $target->hp<=0 || !isset($targets[$target->instanceId]))continue;
                // The interceptor helper can return early before its defense filter; use the same server predicate.
                if(CardStats::hasDefense($state,$target,'strike',$source))continue;
                $option=self::option('strike',['card_id'=>$source->instanceId,'target_id'=>$target->instanceId]);
                if(self::passesClock($state,$viewer,$option,$now))$out[]=$option;
            }
        }
        return $out;
    }

    private static function canEndTurn(GameState $state, string $viewer): bool
    {
        foreach(ForcedStrikeKnowledge::build($state,$viewer) as $requirement)
            if($requirement['status']!=='not_required')return false;
        return true;
    }

    private static function hiddenBattlefield(GameState $state,string $viewer):bool
    {
        foreach($state->cards as $card)if(VisibilityPolicy::card($state,$card,$viewer)==='unknown')return true;
        return false;
    }

    private static function basicGroundStrikeSource(CardInstance $source):bool
    {
        return BasicGroundStrikeSource::supports($source);
    }

    private static function forcedChoice(GameState $state,string $viewer,int $now):array
    {
        if(($state->battle['active'] ?? null)!==$viewer || self::hiddenBattlefield($state,$viewer))return [];
        $pending=$state->battle['pending_forced_strike'];
        $source=$state->getCard((int)($pending['attacker_id'] ?? 0));
        if($source===null || $source->owner!==$viewer || !self::basicGroundStrikeSource($source)
            || \Berserk\Core\EffectiveFeatures::suppressed($source))return [];
        $targets=BattleHelper::getAttackTargets($state,$source,'strike',$viewer);$out=[];
        foreach($pending['candidates'] ?? [] as $id){
            $target=is_int($id)?$state->getCard($id):null;
            if($target===null || $target->zone!==CardInstance::ZONE_FIELD || $target->owner===$viewer
                || !$target->revealed || $target->dying || $target->hp<=0 || !isset($targets[$id])
                || !ZoneManager::areAdjacentFieldCards($source,$target))continue;
            $option=self::option('choose_forced_strike',['target_id'=>$id]);
            if(self::passesClock($state,$viewer,$option,$now))$out[]=$option;
        }
        return $out;
    }

    private static function placement(GameState $state, string $viewer): array
    {
        $actions = [];
        foreach ($state->getCardsInZone($viewer, CardInstance::ZONE_SQUAD) as $card) {
            $actions = array_merge($actions, self::placementsFor($state, $viewer, $card));
        }
        foreach ($state->getCardsInZone($viewer, CardInstance::ZONE_FIELD) as $card) {
            $actions[] = self::option('unplace_card', ['card_id' => $card->instanceId]);
            // PrepareProcessor checks a reposition with this occupant removed.
            $copy = clone $state;
            $copy->cards[$card->instanceId] = clone $card;
            $copy->cards[$card->instanceId]->zone = CardInstance::ZONE_SQUAD;
            $actions = array_merge($actions, self::placementsFor($copy, $viewer, $card));
        }
        if ((new PrepareProcessor($state))->placementConfirmationError($viewer) === null) {
            $actions[] = self::option('confirm_place');
        }
        return $actions;
    }

    private static function placementsFor(GameState $state, string $viewer, CardInstance $card): array
    {
        $zone = new ZoneManager($state);
        $actions = [];
        foreach (ZoneManager::cellLevels($viewer) as $level) {
            foreach ($level as $cell) {
                [$row, $col] = array_map('intval', explode('_', $cell));
                if ($card->row === $row && $card->col === $col && $card->zone === CardInstance::ZONE_FIELD) continue;
                if ($zone->isCellAllowed($viewer, $row, $col) && !$zone->isFieldOccupied($row, $col)) {
                    $actions[] = self::option('place_card', ['card_id' => $card->instanceId, 'row' => $row, 'col' => $col]);
                }
            }
        }
        return $actions;
    }

    private static function strikes(GameState $state, string $viewer, int $now): array
    {
        $actions = $candidates = [];
        $hasHiddenBattlefield = false;
        foreach ($state->cards as $card) {
            if (VisibilityPolicy::card($state, $card, $viewer) === 'unknown') {
                $hasHiddenBattlefield = true;
                break;
            }
        }
        foreach ($state->getCardsInZone($viewer, CardInstance::ZONE_FIELD) as $source) {
            if(!self::basicGroundStrikeSource($source))continue;
            $prop = $source->effectiveProp();
            // Restrict certification to basic ground strikes.
            $used = (int) ($source->flags['attacks_used_this_turn'] ?? 0);

            $targets = $hasHiddenBattlefield ? [] : BattleHelper::getAttackTargets($state, $source, 'strike', $viewer);
            foreach ($state->getCardsInZone($state->getOpponentKey($viewer), CardInstance::ZONE_FIELD) as $target) {
                if (!ZoneManager::areAdjacentFieldCards($source, $target)
                    || (!empty($prop['strike_targets_unique']) && $used > 0
                        && (int) ($source->flags['first_attack_target_id'] ?? 0) === $target->instanceId)) continue;
                $visibility = VisibilityPolicy::card($state, $target, $viewer);
                $option = self::option('strike', ['card_id' => $source->instanceId, 'target_id' => $target->instanceId]);
                if (!self::passesClock($state, $viewer, $option, $now)) continue;
                if ($hasHiddenBattlefield && $visibility !== 'not_applicable') {
                    // Membership depends on visible geometry, not the hidden target's defenses.
                    $candidates[] = $option;
                } elseif ($visibility === 'known' && $target->revealed && isset($targets[$target->instanceId])) {
                    $actions[] = $option;
                }
            }
        }
        return [$actions, $candidates];
    }

    private static function strangulation(GameState $state, string $viewer, int $now): array
    {
        $pending = $state->battle[Strangulation::PENDING] ?? null;
        if (!is_array($pending) || ($pending['owner'] ?? null) !== $viewer
            || !is_string($pending['id'] ?? null) || $pending['id'] === '') return [];
        $token = ['choice_id' => $pending['id']];
        $cancel = self::option('cancel_pending', $token);
        $actions = self::passesClock($state, $viewer, $cancel, $now) ? [$cancel] : [];
        $source = $state->getCard((int) ($pending['source_id'] ?? 0));
        if ($source === null || $source->owner !== $viewer || !Strangulation::available($state, $source)) {
            return $actions;
        }
        foreach ($source->effectiveProp()['actions'] ?? [] as $action) {
            if (($action['type'] ?? null) !== 'strangle'
                || ($action['key'] ?? $action['type']) !== ($pending['action_key'] ?? null)) continue;
            if (!CoinAbilities::actionAvailable($source, $action)) return $actions;
            foreach (Strangulation::targets($state, $source) as $id) {
                $target = $state->getCard($id);
                if (VisibilityPolicy::card($state, $target, $viewer) !== 'known'
                    || !in_array($id, $pending['target_ids'] ?? [], true)) continue;
                $option = self::option('choose_strangulation', ['target_id' => $id] + $token);
                if (self::passesClock($state, $viewer, $option, $now)) $actions[] = $option;
            }
            break;
        }
        return $actions;
    }

    private static function passesClock(GameState $state, string $viewer, array $option, int $now): bool
    {
        return !InstantReservation::blocks($state, $option['type'])
            && ExpiredCommandGuard::error($state, $viewer, new Command($option['type'], $option['payload']), $now) === null;
    }

    private static function option(string $type, array $payload = []): array
    {
        return ['type' => $type, 'payload' => $payload];
    }
}
