<?php
// src/Core/Engine.php

declare(strict_types=1);

namespace Berserk\Core;

use Berserk\Core\Movement\MovementResolver;
use Berserk\Core\Prepare\PrepareProcessor;

/**
 * Применяет команды к состоянию партии.
 * Единая точка входа для игровой логики.
 */
final class Engine
{
    public function __construct(private ?Db $db = null, private ?\Closure $clock = null, private ?\Closure $preparationRandom = null,
        private ?\Closure $cardNameLookup = null) {}

    /** Printed catalog names, not feature keys or UKID equivalence. No state mutation. */
    public function cardNames(array $ukids): array
    {
        $ukids = array_values(array_unique($ukids));
        if ($this->cardNameLookup !== null) return ($this->cardNameLookup)($ukids);
        if ($this->db === null) return [];
        $quoted = array_map(fn(string $id): string => "'" . $this->db->escape($id) . "'", $ukids);
        if (!$quoted) return [];
        return array_column($this->db->fetchAll('SELECT ukid,name FROM cards WHERE ukid IN (' . implode(',', $quoted) . ')'), 'name', 'ukid');
    }

    public function serverNow(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    public function apply(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($cmd->type === 'set_lucktest_url_roll') {
            $state->historyRecorder?->commandStarted($state, $playerKey, $cmd);
            $result = LuckTestDebug::setUrlRoll($state, $playerKey, $cmd);
            $state->historyRecorder?->commandFinished($state, $result);
            return $result;
        }
        if (($state->mode === GameSettings::MODE_LUCKTEST && $state->status === 'settings' && $cmd->type === 'confirm_settings')
            || LuckTestDebug::hasInput($cmd) || LuckTestDice::hasQueue($state, $playerKey)) {
            try {
                $working = GameState::fromArray($state->toArray());
                // Recorder owns mutable captures and open scopes; discard them with a failed candidate.
                $working->historyRecorder = $state->historyRecorder === null ? null : clone $state->historyRecorder;
                LuckTestDebug::configure($working, $playerKey, $cmd);
                $result = LuckTestDice::run($working, $playerKey, fn() => $this->applyObserved($working, $playerKey, $cmd));
                if ($result->success) {
                    foreach (get_object_vars($working) as $key => $value) {
                        if (!in_array($key, ['persistenceJson', 'persistenceVersion'], true)) $state->$key = $value;
                    }
                }
                return $result;
            } catch (\DomainException $e) {
                return Result::error($e->getMessage());
            } catch (\Throwable $e) {
                error_log((string) json_encode(['event'=>$state->status === 'settings' ? 'lucktest_quick_preparation_failed' : 'lucktest_debug_command_failed', 'game_id'=>$state->gameId,
                    'exception'=>get_class($e), 'code'=>$e->getCode(), 'message'=>$e->getMessage(),
                    'file'=>$e->getFile(), 'line'=>$e->getLine()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                return Result::error($state->status === 'settings' ? 'Не удалось завершить подготовку LuckTest. Обновите страницу и повторите попытку'
                    : 'Не удалось выполнить команду LuckTest. Обновите страницу и повторите попытку');
            }
        }
        return LuckTestDice::run($state, $playerKey, fn() => $this->applyObserved($state, $playerKey, $cmd));
    }

    private function applyObserved(GameState $state, string $playerKey, Command $cmd): Result
    {
        $observer = $state->historyRecorder;
        if ($observer === null) return $this->applyCommand($state,$playerKey,$cmd);
        $observer->commandStarted($state,$playerKey,$cmd);
        $result = $this->applyCommand($state,$playerKey,$cmd);
        $observer->commandFinished($state,$result);
        return $result;
    }

    private function applyCommand(GameState $state, string $playerKey, Command $cmd): Result
    {
        $now = $this->serverNow();
        if (MatchPause::isCommand($cmd->type)) return MatchPause::apply($state, $playerKey, $cmd, $now);
        if (MatchPause::blocking($state)) {
            if ($cmd->type !== 'resign') return Result::error('match_paused');
            $result = $this->resign($state, $playerKey, $cmd);
            if ($result->success) MatchPause::terminate($state, $now);
            GameLog::append($state, $playerKey, $cmd, $result);
            return $result; // Never advance combat continuations on pause or terminal commands.
        }
        $error = ExpiredCommandGuard::error($state, $playerKey, $cmd, $now);
        if ($error !== null) return Result::error($error);
        if (InstantReservation::blocks($state, $cmd->type)) return Result::error('instant_reservation_priority_held');
        if ($cmd->type === 'confirm_strike') {
            $error = InformationalWaitManager::validateAck($state, $playerKey, $cmd->get('wait_id'));
            if ($error !== null) return Result::error($error);
        }
        $resolutionBefore = $state->battle[ActionResolution::KEY] ?? null;
        ActionResolution::beginCommand($state);
        TurnTimer::settle($state, $this->serverNow());
        $result = $this->doApply($state, $playerKey, $cmd);
        if (!$result->success) {
            if ($resolutionBefore === null) unset($state->battle[ActionResolution::KEY]);
            else $state->battle[ActionResolution::KEY] = $resolutionBefore;
        }
        if ($result->success) (new AbyssMovement($state, $this))->advance();
        if ($result->success) (new WormIncarnation($state, $this))->advance();
        if ($result->success) (new CoinAbilities($state, $this))->advance();
        if ($result->success) CombatContinuation::settle($state, $this);
        if ($result->success) { (new AlvalindProcessor($state,$this))->advanceRecoil(); (new AlvalindProcessor($state,$this))->advanceEnd(); }
        if ($result->success) (new PairedRanged($state,$this))->advance();
        if ($result->success) (new GraveRitual($state,$this))->advance();
        if ($result->success) (new MatriarchOrderProcessor($state, $this))->advance();
        if ($result->success && ($state->battle['turn_timer']['pause_reason'] ?? null) === 'start_resolution') {
            (new TurnProcessor($state, $this))->afterStartPhase($state->battle['turn_timer']['owner']);
        }
        if ($result->success) {
            InformationalWaitManager::reconcile($state, $this->serverNow());
            InstantReservation::afterCommand($state, $this, $cmd);
            TurnTimer::reconcileWaiting($state, $this->serverNow());
        }
        TurnTimer::settle($state, $this->serverNow());
        GameLog::append($state, $playerKey, $cmd, $result);
        return $result;
    }

    private function doApply(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($cmd->type==='pathfinder_choice') {
            $p=PathfinderProcessor::active($state);
            if($state->status!=='place'||!$p||$p['owner']!==$playerKey||$p['stage']!=='choice'
                ||(int)$cmd->get('step',-1)!==$p['index'])return Result::error('Выбор Следопыта устарел');
            $choice=$cmd->get('choice');
            if(!in_array($choice,['start','rearrange'],true))return Result::error('Неверный выбор');
            $state->battle['pathfinder']['stage']='rearranging';$state->getPlayer($playerKey)->confirmed['place']=false;
            if($choice==='start')return $this->confirmPreparePlace($state,$playerKey,new Prepare\PrepareProcessor($state,$this->db));
            $state->bumpVersion();return Result::ok(['pathfinder_rearranging']);
        }
        // Сдача завершает матч независимо от активного обязательного выбора.
        if ($cmd->type === 'resign') {
            return $this->resign($state, $playerKey, $cmd);
        }

        if (in_array($cmd->type, InstantReservation::COMMANDS, true)) {
            return InstantReservation::apply($state, $this, $playerKey, $cmd);
        }

        if (!empty($state->battle['matriarch_order']) && in_array($cmd->type,
            ['strike','action','uchr','move','jump','end_turn','gain_coin','open_turn_instants'], true)) {
            return Result::error('Сначала завершите приказ матриарха');
        }
        unset($state->battle['instant_result']);
        unset($state->battle['any_death_messages']);
        unset($state->battle['degasca_messages']);

        // Реестр ChoiceHandler — новые команды
        $activeChoice = Choice\ChoiceRegistry::current($state);
        if ($activeChoice !== null) {
            if (in_array($cmd->type, $activeChoice->commandTypes(), true)) {
                return $activeChoice->apply($state, $this, $playerKey, $cmd);
            }

            return Result::error('Ожидается выбор');
        }

        $handler = Choice\ChoiceRegistry::byCommandType($cmd->type, $state);
        if ($handler !== null) {
            return $handler->apply($state, $this, $playerKey, $cmd);
        }

        $strike = new StrikeResolver($state, $this);
        $action = new ActionResolver($state, $this);
        $movement = new MovementResolver($state, $this);
        $turn   = new TurnProcessor($state, $this);
        $zone   = new ZoneManager($state);
        $turnPhase = new TurnPhaseProcessor($state, $this);
        $valhalla = new ValhallaProcessor($state, $this);
        $prepare = new PrepareProcessor($state, $this->db);

        return match ($cmd->type) {
            'set_lucktest_debug' => $this->debugConfigured($state),
            'choose_mode'  => $prepare->chooseMode($playerKey, $cmd),
            'confirm_settings' => $this->confirmPrepareSettings($state, $playerKey, $cmd, $prepare),
            'draft_row'    => $prepare->draftRow($playerKey, $cmd),
            'draft_col'    => $prepare->draftCol($playerKey, $cmd),
            'draft_pass'   => $prepare->draftPass($playerKey, $cmd),
            'draft_confirm' => $prepare->draftConfirm($playerKey, $cmd),
            'finish_draft' => $prepare->finishDraft($playerKey),
            'select_deck'  => $prepare->selectDeck($playerKey, $cmd),
            'confirm_view' => $prepare->confirmView($playerKey),
            'view_to_sideboard' => $prepare->moveViewCardToSideboard($playerKey, $cmd),
            'view_to_deck' => $prepare->moveViewCardToDeck($playerKey, $cmd),
            'confirm_turn' => $prepare->confirmTurn($playerKey),
            'choose_side'  => $prepare->chooseSide($playerKey, $cmd),
            'pick_card'    => $prepare->pickCard($playerKey, $cmd),
            'unpick_card'  => $prepare->unpickCard($playerKey, $cmd),
            'confirm_deal' => $prepare->confirmDeal($playerKey),
            'reshuffle'    => $prepare->reshuffle($playerKey),
            'place_card'   => $prepare->placeCard($playerKey, $cmd),
            'unplace_card' => $prepare->unplaceCard($playerKey, $cmd),
            'confirm_place'       => $this->confirmPreparePlace($state, $playerKey, $prepare),
            'move'                => $movement->move($playerKey, $cmd),
            'jump'                => $movement->jump($playerKey, $cmd),
            'strike'              => $strike->declare($playerKey, $cmd),
            'choose_defender'     => $strike->chooseDefender($playerKey, $cmd),
            'choose_redirect'       => $strike->chooseRedirect($playerKey, $cmd),
            'choose_mage_redirect'  => $strike->chooseMageRedirect($playerKey, $cmd),
            'choose_defender_option' => $strike->chooseDefenderOption($playerKey, $cmd),
            'confirm_strike'      => $strike->confirmStrike($playerKey, $cmd),
            'choose_strike_mode'  => $strike->chooseStrikeMode($playerKey, $cmd),
            'choose_after_strike_execute' => $strike->chooseAfterStrikeExecute($playerKey, $cmd),
            'choose_forced_strike' => $action->chooseForcedStrike($playerKey, $cmd),
            'uchr'                => $action->uchr($playerKey, $cmd),
            'action'              => $action->handle($playerKey, $cmd),
            'end_turn'            => $turn->endTurn($playerKey, $cmd),
            'gain_coin'               => $this->gainCoin($state, $playerKey, $cmd),
            'choose_teleport_cell'    => $action->chooseTeleportTargetCell($playerKey, $cmd),
            'choose_death_target'     => $this->damageResolver($state)->chooseDeathTarget($playerKey, $cmd),
            'choose_auto_target'      => $this->chooseAutoTarget($state, $playerKey, $cmd),
            'choose_card_option'      => $turn->chooseCardOption($playerKey, $cmd),
            'choose_push_choice'      => $strike->choosePushChoice($playerKey, $cmd),
            'choose_any_death_target' => $this->damageResolver($state)->chooseAnyDeathTarget($playerKey, $cmd),
            'choose_incarnation_cell' => $turn->chooseIncarnationCell($playerKey, $cmd),
            'choose_self_wound'       => $action->chooseSelfWound($playerKey, $cmd),
            'choose_multi_heal'       => $action->chooseMultiHeal($playerKey, $cmd),
            'choose_multi_discharge'  => $action->chooseMultiDischarge($playerKey, $cmd),
            'choose_revive_target'    => $action->chooseReviveTarget($playerKey, $cmd),
            'choose_revive_cell'      => $action->chooseReviveCell($playerKey, $cmd),
            'cancel_pending'          => $action->cancelPending($playerKey, $cmd),
            'close_prophecy'          => $turn->closeProphecy($playerKey, $cmd),
            'transform_seeker'        => $turn->transformSeeker($playerKey, $cmd),
            'grezy_continue'          => $action->grezyContinue($playerKey, $cmd),
            'grezy_pick'              => $action->grezyPick($playerKey, $cmd),
            'choose_whip_target'      => $action->chooseWhipTarget($playerKey, $cmd),
            'choose_kobold_heal'      => $action->chooseKoboldHeal($playerKey, $cmd),
            'choose_talion_incarnation' => $action->chooseTalionIncarnation($playerKey, $cmd),
            'choose_holvert_open'     => $action->chooseHolvertOpen($playerKey, $cmd),
            'choose_ranged_redirect'  => $action->chooseRangedRedirect($playerKey, $cmd),
            'choose_dive_cell'        => $action->chooseDiveCell($playerKey, $cmd),
            'turn_task'               => $turnPhase->runTask($playerKey, $cmd),
            'turn_sub'                => $turnPhase->runSub($playerKey, $cmd),
            'turn_ack'                => $turnPhase->ackPending($playerKey),
            'choose_blood_tap'        => $action->chooseBloodTap($playerKey, $cmd),
            'valhalla_pick'           => $valhalla->chooseTarget($playerKey, $cmd),
            'turn_sub_close'          => $turnPhase->closeSub($playerKey, $cmd),
            'choose_instant_pick'     => $turnPhase->chooseInstantPick($playerKey, $cmd),
            'combat_instant_play'     => $action->playCombatInstant($playerKey, $cmd),
            'combat_instant_pass'     => $strike->passCombatInstant($playerKey),
            'choose_combat_pick'      => $action->chooseCombatPick($playerKey, $cmd),
            'open_turn_instants'      => $action->openTurnInstants($playerKey, $cmd),
            'play_turn_instant'       => $action->playTurnInstant($playerKey, $cmd),
            'pass_turn_instant'       => (new InstantProcessor($state, $this))->passTurnInstant($playerKey),
            'choose_close_or_damage'  => $strike->chooseCloseOrDamage($playerKey, $cmd),
            'choose_ally_modifier'    => $strike->chooseAllyModifier($playerKey, $cmd),
            'choose_row'                => $action->chooseRow($playerKey, $cmd),
            'choose_opponent_row_marker'=> $action->chooseOpponentRowMarker($playerKey, $cmd),
            'choose_row_spell_targets'  => $action->chooseRowSpellTargets($playerKey, $cmd),
            'choose_gate'             => $action->chooseGate($playerKey, $cmd),
            'choose_greed_teleport'   => $action->chooseGreedTeleport($playerKey, $cmd),
            'choose_particle_pick'    => $action->chooseParticlePick($playerKey, $cmd),
            'choose_life_gift'        => $action->chooseLifeGift($playerKey, $cmd),
            'choose_nokami_wound'     => $action->chooseNokamiWound($playerKey, $cmd),
            'reorder_start'           => (new ProphecyProcessor($state, $this))->startReorder($playerKey),
            'reorder_card_up'         => (new ProphecyProcessor($state, $this))->reorderCard(
                $playerKey, (int) $cmd->get('card_id', 0), 'top'
            ),
            'reorder_card_down'       => (new ProphecyProcessor($state, $this))->reorderCard(
                $playerKey, (int) $cmd->get('card_id', 0), 'bottom'
            ),
            'summon_start'    => (new ProphecyProcessor($state, $this))->startSummon(
                $playerKey, (int) $cmd->get('card_id', 0)
            ),
            'summon_cell'     => (new ProphecyProcessor($state, $this))->summonChooseCell(
                $playerKey, (int) $cmd->get('row', 0), (int) $cmd->get('col', 0)
            ),
            'summon_creature' => (new ProphecyProcessor($state, $this))->summonChooseCreature(
                $playerKey, (int) $cmd->get('target_id', 0)
            ),
            'summon_cancel'   => (new ProphecyProcessor($state, $this))->summonCancel($playerKey),

            default                   => Result::error("Unknown command: {$cmd->type}"),
        };
    }

    private function debugConfigured(GameState $state): Result
    {
        $state->bumpVersion();
        return Result::ok(['lucktest_debug_configured']);
    }

    private function confirmPrepareSettings(GameState $state, string $actor, Command $cmd, PrepareProcessor $prepare): Result
    {
        $result = $prepare->confirmSettings($actor, $cmd);
        if (!$result->success || $state->mode !== GameSettings::MODE_LUCKTEST || !$state->settings->lucktest['quick_game']) return $result;
        $events = $result->events;
        foreach (['host', 'player'] as $owner) $events = array_merge($events, $this->preparationEvents($prepare->confirmTurn($owner)));
        $events = array_merge($events, $this->preparationEvents($prepare->chooseSide($state->firstPlayer,
            new Command('choose_side', ['side'=>$this->preparationRandomInt(1, 2)]))));
        foreach (['host', 'player'] as $owner) $events = array_merge($events, $this->randomPlaceSquad($state, $prepare, $owner));
        \Berserk\Core\Prepare\LuckTestScenario::verify($state);
        \Berserk\Core\Prepare\LuckTestScenario::applyInitialHealth($state);
        // This is the SAME final validation and startBattle boundary used by manual confirm_place.
        foreach (['host', 'player'] as $owner) $events = array_merge($events,
            $this->preparationEvents($this->confirmPreparePlace($state, $owner, $prepare)));
        $events[] = 'lucktest_quick_preparation_completed';
        return Result::ok($events);
    }

    private function randomPlaceSquad(GameState $state, PrepareProcessor $prepare, string $owner): array
    {
        if (($state->lucktestDebug['bindings'] ?? []) !== []) return $this->placeScenarioSquad($state, $prepare, $owner);
        $cards = $state->getCardsInZone($owner, CardInstance::ZONE_SQUAD);
        shuffle($cards);
        $zone = new ZoneManager($state);
        $events = [];
        foreach ($cards as $card) {
            $available = [];
            foreach (ZoneManager::cellLevels($owner) as $level) {
                foreach ($level as $cell) {
                    [$row, $col] = array_map('intval', explode('_', $cell));
                    if ($zone->isCellAllowed($owner, $row, $col) && !$zone->isFieldOccupied($row, $col)) $available[] = [$row, $col];
                }
            }
            if ($available === []) throw new \RuntimeException('No legal LuckTest placement for ' . $owner);
            [$row, $col] = $available[$this->preparationRandomInt(0, count($available) - 1)];
            $events = array_merge($events, $this->preparationEvents($prepare->placeCard($owner,
                new Command('place_card', ['card_id'=>$card->instanceId, 'row'=>$row, 'col'=>$col]))));
        }
        return $events;
    }

    private function placeScenarioSquad(GameState $state, PrepareProcessor $prepare, string $owner): array
    {
        $cards = $state->getCardsInZone($owner, CardInstance::ZONE_SQUAD);
        shuffle($cards); $cards = array_column($cards, null, 'instanceId');
        $reserved = []; $pins = [];
        foreach ($state->lucktestDebug['bindings'] as $entry) {
            if ($entry['owner'] === $owner && isset($entry['row'])) {
                $reserved[$entry['row'].'_'.$entry['col']] = true; $pins[$entry['instance_id']] = $entry;
            }
        }
        $events = []; $zone = new ZoneManager($state);
        while ($cards !== []) {
            $id = null; $position = null;
            foreach ($pins as $key => $entry) {
                if (isset($cards[$key]) && $zone->isCellAllowed($owner, $entry['row'], $entry['col'])
                    && !$zone->isFieldOccupied($entry['row'], $entry['col'])) {
                    $id = $key; $position = [$entry['row'], $entry['col']]; break;
                }
            }
            if ($id === null) {
                foreach ($cards as $key=>$card) if (!isset($pins[$key])) { $id = $key; break; }
                $available = [];
                foreach (ZoneManager::cellLevels($owner) as $level) foreach ($level as $cell) {
                    [$row,$col] = array_map('intval', explode('_',$cell));
                    if (!isset($reserved[$cell]) && $zone->isCellAllowed($owner,$row,$col) && !$zone->isFieldOccupied($row,$col)) $available[] = [$row,$col];
                }
                if ($id === null || $available === []) throw new \DomainException('Сценарий LuckTest: полная легальная расстановка невозможна');
                $position = $available[$this->preparationRandomInt(0,count($available)-1)];
            }
            $events = array_merge($events, $this->preparationEvents($prepare->placeCard($owner,
                new Command('place_card',['card_id'=>$id,'row'=>$position[0],'col'=>$position[1]]))));
            unset($cards[$id]);
        }
        return $events;
    }

    private function preparationEvents(Result $result): array
    {
        if (!$result->success) throw new \RuntimeException('LuckTest preparation rejected: ' . $result->error);
        return $result->events;
    }

    private function preparationRandomInt(int $min, int $max): int
    {
        $value = $this->preparationRandom !== null ? ($this->preparationRandom)($min, $max) : random_int($min, $max);
        if (!is_int($value) || $value < $min || $value > $max) throw new \UnexpectedValueException('Invalid preparation random value');
        return $value;
    }

    private function confirmPreparePlace(GameState $state, string $playerKey, PrepareProcessor $prepare): Result
    {
        $result = $prepare->confirmPlace($playerKey);
        if ($result->success && in_array('stage_changed:battle', $result->events, true)) {
            (new TurnProcessor($state, $this))->startBattle();
        }

        return $result;
    }
    public function applyCombatEffect(
        GameState $state,
        array $effect,
        ?CardInstance $source,
        string $ownerKey,
        ?string $choice = null,
        ?CardInstance $target = null
    ): ?string {
        if ($source && EffectiveFeatures::suppressed($source)) return 'Потеря памяти';
        $type = $effect['type'] ?? '';
        $strike = &$state->battle['strike'];

        switch ($type) {

            case 'redirect_strike':
                if (!empty($strike['matriarch_order'])) return 'Перенаправление запрещено';
                if (!$target) return 'цель не найдена';
                if ($target->owner !== $ownerKey) return 'цель не союзная';
                if ($target->zone !== CardInstance::ZONE_FIELD) return 'цель не на поле';
                if ($target->dying || $target->hp <= 0) return 'цель недоступна';

                $attacker = $state->getCard((int) ($strike['attacker_id'] ?? 0));
                if ($source && $attacker && CardStats::hasDefense($state, $attacker, 'magic', $source)) {
                    return 'атакующий защищён от магии';
                }
                if ($source && CardStats::hasDefense($state, $target, 'magic', $source)) {
                    return 'цель защищена от магии';
                }

                $currentTarget = $state->getCard((int) ($strike['target_id'] ?? 0));
                if (!$currentTarget) return 'исходная цель не найдена';
                if ($currentTarget->zone !== CardInstance::ZONE_FIELD) return 'исходная цель не на поле';
                if ($currentTarget->owner !== $ownerKey) return 'исходная цель не союзная';
                if ($target->owner !== $currentTarget->owner) return 'цель из другого отряда';

                if (!ZoneManager::areAdjacentFieldCards($currentTarget, $target)) {
                    return 'цель не рядом';
                }

                $strike['target_id'] = $target->instanceId;
                $strike['defender_id'] = null;
                $strike['defenders'] = [];
                $strike['redirect_used'] = true;

                if ($attacker) {
                    $noDefendDice = $target->closed
                        || CardStats::hasUnanswer($state, $attacker, $target);
                    if ($noDefendDice) {
                        $strike['defend_dice'] = 0;
                    }
                    $strike['attack_mod'] = CardStats::getOva($state, $attacker, $target)
                        - CardStats::getClumsyPenalty($attacker);
                    $strike['defend_mod'] = $target->closed
                        ? 0
                        : (CardStats::getOvz($state, $target)
                            - CardStats::getClumsyPenalty($target));
                    $strike['attack_clumsy'] = CardStats::getClumsyPenalty($attacker);
                    $strike['defend_clumsy'] = $target->closed
                        ? 0
                        : CardStats::getClumsyPenalty($target);
                    (new StrikeResolver($state, $this))->recalcTable();
                }
                return null;

            case 'strike_value':
                if ($source && (!EffectiveFeatures::onBattlefield($source) || $source->dying || $source->hp <= 0)) return 'источник недоступен';
                $side = $target ? CombatStrikeValue::side($state, $target->instanceId) : null;
                if ($side === null) return 'выбранный удар недоступен';
                $current = CombatStrikeValue::current($state, $side);
                if ($current === null) return 'выбранный удар не наносится';
                $value = (int) ($effect['value'] ?? 0);
                $next = ($effect['mode'] ?? 'add') === 'set' ? max(0, $value) : max(0, $current + $value);
                if ($next === $current) return 'величина удара не изменилась';
                $strike['strike_value_effects'][] = ['side'=>$side, 'mode'=>$effect['mode'] ?? 'add', 'value'=>$value];
                if ($source && !empty($effect['gain_coins'])) {
                    (new CoinAbilities($state, $this))->gain($source, (int) $effect['gain_coins'], $source, true);
                }
                return null;

            case 'strike_level':
                $mode  = $effect['mode'] ?? 'set';
                $value = $effect['value'] ?? null;

                // На чьей стороне владелец карты
                $attacker = $state->getCard($strike['attacker_id']);
                if (!$attacker) return 'атакующий не найден';
                $isAttackerSide = ($attacker->owner === $ownerKey);

                // Какой уровень меняем — attack или defend
                $side = $isAttackerSide ? 'attack' : 'defend';
                $current = $strike['result'][$side] ?? '';

                if ($current === '') {
                    return $isAttackerSide ? 'промах' : 'защитник промахнулся';
                }

                if (!isset($strike['strike_level_effects']) && !empty($strike['level_overridden'])) {
                    $strike['strike_level_effects'] = [[
                        'side' => 'attack', 'mode' => 'set', 'value' => $strike['result']['attack'],
                    ]];
                }

                if ($mode === 'set') {
                    if ($current === $value) return 'уже ' . $value;
                    $strike['base_result'] ??= $strike['result'];
                    $strike['strike_level_effects'][] = ['side' => $side, 'mode' => 'set', 'value' => $value];
                    $strike['result'][$side] = $value;
                    if ($isAttackerSide) $strike['level_overridden'] = true;
                    return null;
                }

                if ($mode === 'reduce_one') {
                    $levels = ['strong' => 'medium', 'medium' => 'weak', 'weak' => ''];
                    $new = $levels[$current] ?? '';
                    if ($new === $current) return 'уже минимум';
                    $strike['base_result'] ??= $strike['result'];
                    $strike['strike_level_effects'][] = ['side' => $side, 'mode' => 'reduce_one'];
                    $strike['result'][$side] = $new;
                    return null;
                }
                return 'неверный mode';

            case 'damage_cap':
                if ($target !== null) {
                    $currentTargetId = (int) ($strike['defender_id'] ?: ($strike['target_id'] ?? 0));
                    if ($currentTargetId !== $target->instanceId) {
                        return 'цель удара изменилась';
                    }
                }

                $cap = (int) ($effect['value'] ?? 0);
                if ($source) ActionResolution::effect($state, $source, (string)($effect['_resolution_type'] ?? 'instant'), 'instant', ['effect_type'=>$type]);
                $strike['damage_cap'] = $cap;

                $selfWound = (int) ($effect['self_wound'] ?? 0);
                if ($selfWound > 0 && $source) {
                    $targetId = $strike['defender_id'] ?: $strike['target_id'];
                    $target   = $state->getCard($targetId);

                    $skipWound = false;
                    $except    = $effect['except_element'] ?? null;
                    if ($except && $target && CardStats::hasElement($target, (string) $except)) {
                        $skipWound = true;
                    }

                    if (!$skipWound) {
                        $this->applyDamage($state, $source, $selfWound, 'impact');
                    }
                }
                return null;

            case 'dice_choice':
                if ($choice === null) return 'нет выбора';

                $parts = explode(':', $choice);
                if (count($parts) !== 2) return 'неверный выбор';
                [$op, $who] = $parts;

                $attacker = $state->getCard($strike['attacker_id']);
                if (!$attacker) return 'атакующий не найден';

                $attackerKey    = $attacker->owner;
                $isAttackerSide = ($ownerKey === $attackerKey);

                if ($who === 'own') {
                    $isAttackDice = $isAttackerSide;
                } else {
                    $isAttackDice = !$isAttackerSide;
                }

                $sr = new StrikeResolver($state, $this);

                if ($op === 'reroll') {
                    $beforeDice = ['attack'=>$strike['attack_dice'], 'defend'=>$strike['defend_dice']];
                    $debugQueueActive = Dice::isInfiniteMode() || !empty(Dice::remaining());
                    $strike['attack_dice'] = Dice::roll();
                    $strike['defend_dice'] = Dice::roll();
                    $strike['reroll_trace'][] = ['source_id'=>$source?->instanceId,'before'=>$beforeDice,
                        'after'=>['attack'=>$strike['attack_dice'],'defend'=>$strike['defend_dice']],
                        'attacker_six_ready'=>!empty($attacker->flags[CardRoll::READY]),
                        'debug_queue_active'=>$debugQueueActive,
                        'debug_infinite'=>Dice::isInfiniteMode()];
                    $sr->recalcTable();
                    $state->historyRecorder?->liveEvent($state,'combat.dice_changed',[
                        'attack'=>$strike['attack_dice'],'defend'=>$strike['defend_dice'],'operation'=>$op],
                        array_values(array_filter([$strike['attacker_id'],$strike['defender_id']?:$strike['target_id'],$source?->instanceId])));
                    return null;
                }

                if ($op === 'plus') {
                    if ($isAttackDice) $strike['attack_dice']++;
                    else               $strike['defend_dice']++;
                    $sr->recalcTable();
                    $state->historyRecorder?->liveEvent($state,'combat.dice_changed',[
                        'attack'=>$strike['attack_dice'],'defend'=>$strike['defend_dice'],'operation'=>$op],
                        array_values(array_filter([$strike['attacker_id'],$strike['defender_id']?:$strike['target_id'],$source?->instanceId])));
                    return null;
                }

                if ($op === 'minus') {
                    if ($isAttackDice) {
                        $strike['attack_dice'] = max(1, (int) $strike['attack_dice'] - 1);
                    } else {
                        $strike['defend_dice'] = max(1, (int) $strike['defend_dice'] - 1);
                    }
                    $sr->recalcTable();
                    $state->historyRecorder?->liveEvent($state,'combat.dice_changed',[
                        'attack'=>$strike['attack_dice'],'defend'=>$strike['defend_dice'],'operation'=>$op],
                        array_values(array_filter([$strike['attacker_id'],$strike['defender_id']?:$strike['target_id'],$source?->instanceId])));
                    return null;
                }
                return 'неверная операция';

            case 'damage_on_dice':
                $diceVal = (int) ($effect['value'] ?? 0);
                $damage  = (int) ($effect['damage'] ?? 0);

                $attacker = $state->getCard($strike['attacker_id']);
                if (!$attacker) return 'атакующий не найден';

                $attackerKey    = $attacker->owner;
                $isAttackerMine = ($attackerKey === $ownerKey);

                $defenderId = $strike['defender_id'] ?: $strike['target_id'];
                $defender   = $state->getCard($defenderId);

                $hits = [];

                // Кубик атакующего — противник владельца Мэри?
                if (!$isAttackerMine && (int) $strike['attack_dice'] > 0
                    && (int) $strike['attack_dice'] + (int) ($strike['attack_mod'] ?? 0) === $diceVal) {
                    $hits[] = $attacker;
                }

                // Кубик защитника — противник владельца Мэри?
                if ($isAttackerMine && $defender && (int) $strike['defend_dice'] > 0
                    && (int) $strike['defend_dice'] + (int) ($strike['defend_mod'] ?? 0) === $diceVal) {
                    $hits[] = $defender;
                }

                if (empty($hits)) {
                    return 'промах: итог кубика противника ≠ ' . $diceVal;
                }

                if ($source) ActionResolution::effect($state, $source, (string)($effect['_resolution_type'] ?? 'instant'), 'instant', ['effect_type'=>$type]);
                foreach ($hits as $t) {
                    $this->applyDamage($state, $t, $damage, 'damage_on_dice', $source);
                }
                return null;
        }
        return 'неизвестный эффект: ' . $type;
    }

    public function applyInstantEffect(GameState $state, array $effect, ?CardInstance $source, CardInstance $target, string $ownerKey): void
    {
        if ($source && EffectiveFeatures::suppressed($source)) return;
        $type = $effect['type'] ?? '';
        $journalHpBefore=$target->hp;
        $journalClosedBefore=$target->closed;

        switch ($type) {
            case 'open':
                ActionResolution::effect($state, $source ?? $target, 'open', 'passive', ['target_id'=>$target->instanceId]);
                $this->openCard($target);
                $damage = (int) ($effect['damage'] ?? 0);
                if ($damage > 0) {
                    $this->applyDamage($state, $target, $damage, 'impact', $source);
                    $state->battle['instant_result'][] = [
                        'type' => 'open_damage',
                        'source_id' => $source?->instanceId,
                        'target_id' => $target->instanceId,
                        'damage' => $damage,
                        'died' => $target->hp <= 0 || $target->dying,
                    ];
                }
                break;
            case 'damage':
                $value = (int) ($effect['value'] ?? 1);
                $this->applyDamage($state, $target, $value, 'impact', $source);
                break;
            case 'heal_turn_wounds':
            case 'heal':
                $value=$type==='heal_turn_wounds'?(int)($target->flags['damage_taken_this_turn']??0):(int)($effect['value']??1);
                (new CardHealing($state,$this))->begin($source,$target,$value,$type,$effect['_heal_return_to']??[],
                    ['parent'=>ActionResolution::parent($state)]);
                return;
            case 'close_target':
                $target->closed = true;
                break;
            case 'marker':
                $this->applyMarker($target, $effect['marker'] ?? [], $ownerKey);
                break;
            case 'forced_strike_adjacent':
                $forcedTarget = $state->getCard((int) ($effect['forced_target_id'] ?? 0));
                if (!$source || !$forcedTarget) {
                    break;
                }
                (new StrikeResolver($state, $this))->declareForcedAdjacent(
                    $target,
                    $forcedTarget,
                    !empty($effect['close_attacker_after'])
                );
                break;
        }
        if (in_array($type,['heal','heal_turn_wounds'],true) && $source) ActionResolution::healed($state, $source, $target, $journalHpBefore, $type);
        if (in_array($type,['heal','heal_turn_wounds'],true)) $state->historyRecorder?->healed($state,$target,$journalHpBefore,$source,$type);
        if ($target->closed!==$journalClosedBefore || $type==='marker') {
            $state->historyRecorder?->liveEvent($state,'card.status',[
                'target_id'=>$target->instanceId,'closed'=>$target->closed,'effect'=>$type,
                '_private_owner'=>$source?->owner??$ownerKey],array_values(array_filter([$target->instanceId,$source?->instanceId])));
        }
    }

    public function openCard(CardInstance $card): void
    {
        $card->closed = false;
        $card->flags['attacks_used_this_turn'] = 0;
        $card->flags['shot_used_this_turn'] = false;
        $card->flags['after_strike_execute_used_this_turn'] = 0;
        unset($card->flags['first_attack_target_id']);

        // Сброс использования выданных действий (Дверг / Ойуун)
        foreach (array_keys($card->flags) as $k) {
            if (!is_string($k) || !str_starts_with($k, 'granted_action:')) continue;
            if (!is_array($card->flags[$k])) continue;
            $card->flags[$k]['used'] = false;
        }
    }

    public function applyDamage(
        GameState $state,
        CardInstance $target,
        int $val,
        string $actionType = 'strike',
        ?CardInstance $attacker = null
    ): void {
        $this->damageResolver($state)->applyDamage($target, $val, $actionType, $attacker);
    }

    public function reduceAttackValueByCellMarkers(GameState $state, CardInstance $attacker, int $value): array
    {
        if ($value <= 0) {
            return ['value' => $value, 'events' => []];
        }
        if ($attacker->zone !== CardInstance::ZONE_FIELD || $attacker->row === null || $attacker->col === null) {
            return ['value' => $value, 'events' => []];
        }

        $cellKey = $attacker->row . '_' . $attacker->col;
        $current = $value;
        $events = [];

        foreach (ZoneManager::markersAt($state, $cellKey) as $marker) {
            if (($marker['type'] ?? '') !== 'reduce_attack') continue;
            if (!$this->attackReductionMarkerApplies($marker, $attacker)) continue;

            $targetValue = max(0, (int) ($marker['value'] ?? $current));
            if ($current <= $targetValue) continue;

            $from = $current;
            $current = $targetValue;
            $events[] = [
                'label' => (string) ($marker['label'] ?? 'Способность'),
                'from'  => $from,
                'to'    => $current,
            ];
        }

        return ['value' => $current, 'events' => $events];
    }

    private function attackReductionMarkerApplies(array $marker, CardInstance $attacker): bool
    {
        $filter = (array) ($marker['filter'] ?? []);
        $owner = (string) ($marker['owner'] ?? $marker['source'] ?? '');

        if (array_key_exists('enemy', $filter)) {
            $isEnemy = $owner !== '' && $attacker->owner !== $owner;
            if ($isEnemy !== (bool) $filter['enemy']) return false;
        }

        if (array_key_exists('elite', $filter)) {
            if ($attacker->elite !== (bool) $filter['elite']) return false;
        }

        return true;
    }

    public function tryBlockDamageAttackWithMarker(
        GameState $state,
        CardInstance $target,
        string $actionType,
        int $value
    ): bool {
        if ($value <= 0) return false;
        if (!isset($target->markers['spider_web'])) return false;

        $attackTypes = ['strike', 'uchr', 'shot', 'throw', 'discharge', 'magic', 'cast', 'tap'];
        if (!in_array($actionType, $attackTypes, true)) return false;

        $marker = (array) $target->markers['spider_web'];
        unset($target->markers['spider_web']);

        $state->battle['strike']['spider_web_block'] = [
            'target_id' => $target->instanceId,
            'target_ukid' => $target->ukid,
            'type' => 'spider_web',
            'source' => $marker['source'] ?? null,
        ];

        return true;
    }

    public function forceDeath(
        GameState $state,
        CardInstance $target,
        string $cause,
        ?CardInstance $source = null
    ): void {
        $this->damageResolver($state)->forceDeath($target, $cause, $source);
    }

    private function diceToLevel(int $dice): string
    {
        if ($dice <= 3) return 'weak';
        if ($dice <= 5) return 'medium';
        return 'strong';
    }

    public function hasDefense(GameState $state, CardInstance $target, string $actionType, ?CardInstance $attacker = null): bool
    {
        return CardStats::hasDefense($state, $target, $actionType, $attacker);
    }

    private function resign(GameState $state, string $playerKey, Command $cmd): Result
    {
        if (!in_array($playerKey, [GameState::PLAYER_HOST, GameState::PLAYER_PLAYER], true)) {
            return Result::error('Вы не участник партии');
        }
        if ($state->status !== 'battle') {
            return Result::error('Сдаться можно только в бою');
        }
        if ($state->winner !== null) {
            return Result::error('Игра уже завершена');
        }

        $state->winner = $state->getOpponentKey($playerKey);
        $state->status = 'game_over';
        $state->battle['finished_at'] = $this->serverNow();
        $state->battle['result_reason'] = 'resign';
        $state->bumpVersion();

        return Result::ok(["resigned:{$playerKey}", "winner:{$state->winner}"]);
    }

    public function checkGameOver(GameState $state): void
    {
        $this->damageResolver($state)->checkGameOver();
    }

    public function finalizeDying(GameState $state): void
    {
        (new ZoneManager($state))->flushDying();
        $this->refreshArmor($state);
        $this->checkGameOver($state);
    }

    private function gainCoin(GameState $state, string $playerKey, Command $cmd): Result
    {
        if ($state->status !== 'battle') {
            return Result::error('Сейчас не бой');
        }
        if ($state->battle['active'] !== $playerKey) {
            return Result::error('Сейчас не ваш ход');
        }
        if (!empty($state->battle['strike'])) {
            return Result::error('Идёт сражение');
        }

        $cardId = (int) $cmd->get('card_id', 0);
        $card   = $state->getCard($cardId);

        if (!$card
            || $card->owner !== $playerKey
            || ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING)) {
            return Result::error('Карта не на поле');
        }
        if (CardStats::isDisabled($card)) {
            $reason = CardStats::disabledReason($card);
            return Result::error($reason !== '' ? $reason : 'Карта не может действовать');
        }
        if (empty($card->effectiveProp()['save_coins'])) {
            return Result::error('Карта не умеет копить монеты');
        }

        $max = (int) ($card->effectiveProp()['coins']['max_value'] ?? 0);
        if ($max > 0 && $card->coins >= $max) {
            return Result::error('Максимум монет');
        }

        $card->coins++;
        $card->closed = true;
        $this->syncCoinBonus($card);
        $state->bumpVersion();

        ActionResolution::effect($state, $card, 'gain_coin', 'ordinary');
        return Result::ok(["coin_gained:{$playerKey}:{$cardId}:total={$card->coins}"]);
    }

    public function applyMarker(CardInstance $target, array $marker, string $sourceKey): void
    {
        if (($marker['if'] ?? '') === 'target_closed' && !$target->closed) {
            return;
        }

        $type   = $marker['type'];
        $timing = $marker['timing'] ?? 'source_turn';

        if (!isset($target->markers[$type])) {
            $target->markers[$type] = [
                'value'  => 1,
                'expire' => $marker['expire'] ?? null,
                'source' => $sourceKey,
                'timing' => $timing,
            ];

            if (!empty($marker['skip_first_tick'])) {
                $target->markers[$type]['skip_first_tick'] = true;
            }
        } else {
            $target->markers[$type]['value']++;
            if (isset($marker['expire'])) {
                $target->markers[$type]['expire'] = $marker['expire'];
            }
            if (!empty($marker['skip_first_tick'])) {
                $target->markers[$type]['skip_first_tick'] = true;
            }
        }
    }

    public function applyPoison(CardInstance $target, int $value, string $sourceKey): void
    {
        if ($value <= 0) return;

        if (!empty($target->effectiveProp()['zoo'])) {
            return;
        }

        if (!isset($target->markers['poison'])) {
            $target->markers['poison'] = [
                'value'  => $value,
                'source' => $sourceKey,
                'timing' => 'permanent',
            ];
        } else {
            if ($value > $target->markers['poison']['value']) {
                $target->markers['poison']['value'] = $value;
            }
        }
    }

    public function applyRegeneration(CardInstance $card): void
    {
        $regen = $card->effectiveProp()['regeneration'] ?? 0;
        if (is_array($regen)) $regen = (int) ($regen['value'] ?? 0);
        else $regen = (int) $regen;

        // Модификаторы (на будущее)
        foreach ($card->effectiveModifiers() as $m) {
            if (($m['stat'] ?? '') === 'regeneration') {
                $regen += (int) ($m['value'] ?? 0);
            }
        }

        if ($regen <= 0) return;
        if ($card->hp >= $card->hpMax) return; // уже целая

        $card->hp += $regen;
        if ($card->hp > $card->hpMax) {
            $card->hp = $card->hpMax;
        }
    }

    public function refreshArmor(GameState $state): void
    {
        $this->damageResolver($state)->refreshArmor();
    }

    public function triggerOnDeath(GameState $state, CardInstance $died): void
    {
        $this->damageResolver($state)->triggerOnDeath($died);
    }









    public function revealCard(GameState $state, CardInstance $card): void
    {
        if ($card->revealed) return;

        $card->revealed = true;

        if ($card->type === 'fly' && $card->zone === CardInstance::ZONE_FIELD) {
            $this->moveToFlyingZone($state, $card);
        }
    }

    public function moveToFlyingZone(GameState $state, CardInstance $card): void
    {
        (new ZoneManager($state))->toFlying($card);
    }

    public function applyStrikeEffects(
        CardInstance $attacker,
        CardInstance $target,
        string $level,
        ?array $effects = null
    ): void {
        if (EffectiveFeatures::suppressed($attacker)) return;
        $effects = $effects ?? ($attacker->effectiveProp()['strike_effects'] ?? []);
        if (!is_array($effects)) return;

        foreach ($effects as $effect) {
            $levels = $effect['levels'] ?? null;
            if ($levels !== null && !in_array($level, $levels, true)) continue;

            if (!empty($effect['poison'])) {
                $this->applyPoison($target, (int) $effect['poison'], $attacker->owner);
            }

            if (!empty($effect['close'])) {
                $target->closed = true;
            }

            if (!empty($effect['marker']['type'])) {
                $this->applyMarker($target, $effect['marker'], $attacker->owner);
                // Взгляд Медузы закрывает цель сразу, затем запрещает следующее открытие.
                if ($effect['marker']['type'] === 'stun'
                    && isset($target->markers['stun'])) {
                    $target->closed = true;
                }
            }

            if (!empty($effect['rooted'])) {
                if (!isset($target->markers['rooted'])) {
                    $target->markers['rooted'] = ['sources' => []];
                }
                if (!in_array($attacker->instanceId, $target->markers['rooted']['sources'], true)) {
                    $target->markers['rooted']['sources'][] = $attacker->instanceId;
                }
            }
        }
    }

    public function flushDeadeatQueue(GameState $state): void
    {
        $this->damageResolver($state)->flushDeadeatQueue();
    }

    public function applyAnswer(
        GameState $state,
        CardInstance $defender,
        CardInstance $attacker,
        string $actionType
    ): void {
        $this->damageResolver($state)->applyAnswer($defender, $attacker, $actionType);
    }

    public function openAutoChoice(GameState $state, CardInstance $attacker): bool
    {
        $auto = $attacker->effectiveProp()['auto'] ?? null;
        if (!$auto || !is_array($auto)) return false;

        foreach ($auto as $effect) {
            $trigger = $effect['trigger'] ?? 'always';
            if ($trigger === 'strike_hit') {
                if (empty($state->battle['strike']['strike_hit'])) continue;
            }

            // Перехват Резчика (только для shot)
            $interceptors = [];
            if (($effect['type'] ?? '') === 'shot') {
                $interceptors = CardStats::getRangedInterceptors(
                    $state, $attacker->owner, 'shot'
                );
            }
            $interceptorIds = array_map(fn($p) => $p->instanceId, $interceptors);

            $candidates = [];
            foreach ($state->cards as $target) {
                if ($target->zone !== CardInstance::ZONE_FIELD
                    && $target->zone !== CardInstance::ZONE_FLYING) continue;
                if ($target->owner === $attacker->owner) continue;

                // Резчик: если есть перехватчики — только они
                if (!empty($interceptorIds) && !in_array($target->instanceId, $interceptorIds, true)) {
                    continue;
                }

                // zov / defense
                if (!empty($effect['type']) && in_array($effect['type'], ['shot', 'throw', 'discharge'], true)) {
                    if (CardStats::hasDefense($state, $target, $effect['type'], $attacker)) {
                        continue;
                    }
                }

                $range = (int) ($effect['range'] ?? 0);

                $attackerIsFlying = ($attacker->zone === CardInstance::ZONE_FLYING);
                $targetIsFlying   = ($target->zone === CardInstance::ZONE_FLYING);

                if (!$attackerIsFlying && !$targetIsFlying) {
                    $dr = abs($target->row - $attacker->row);
                    $dc = abs($target->col - $attacker->col);
                    $maxd = max($dr, $dc);
                    $dist = $dr + $dc;

                    // Соседняя клетка запрещена
                    if ($maxd <= 1) continue;
                    // range = 0 → без ограничения дальности
                    if ($range > 0 && $dist > $range) continue;
                }

                $candidates[] = $target->instanceId;
            }

            if (empty($candidates)) continue;
            
            $state->battle['strike']['pending_auto'] = [
                'source_id'  => $attacker->instanceId,
                'effect'     => $effect,
                'candidates' => $candidates,
            ];
            $state->battle['strike']['state'] = 'waiting_auto_target';
            $state->battle['strike']['confirmed'] = [];  // сбрасываем
            return true;
        }
        return false;
    }

    private function chooseAutoTarget(GameState $state, string $playerKey, Command $cmd): Result
    {
        $strike = $state->battle['strike'] ?? null;
        if (!$strike || $strike['state'] !== 'waiting_auto_target') {
            return Result::error('Сейчас не выбор цели auto');
        }

        $pa = $strike['pending_auto'];
        $source = $state->getCard($pa['source_id']);
        if (!$source || $source->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $targetId = (int) $cmd->get('target_id', 0);

        // Пропуск (если игрок не хочет)
        if ($targetId === 0) {
            unset($state->battle['strike']['pending_auto']);
            if (!empty($state->battle['strike']['continuation_frame'])) {
                $this->publishContinuationAutoResult($state);
                return Result::ok(['auto_skipped']);
            }

            $this->closeForcedStrikeAttackerAfter($state);
            CombatContinuation::localDone($state, $state->battle['strike'] ?? []);
            $state->battle['strike'] = null;
            $this->finalizeDying($state);

            $state->bumpVersion();
            return Result::ok(['auto_skipped']);
        }

        if (!in_array($targetId, $pa['candidates'], true)) {
            return Result::error('Неверная цель');
        }

        $target = $state->getCard($targetId);
        if (!$target) {
            return Result::error('Цель не найдена');
        }

        $effect = $pa['effect'];
        $val = (int) ($effect['value'] ?? 0);

        ActionResolution::effect($state, $source, 'shot', 'passive', ['hit'=>$val > 0, 'target_id'=>$targetId, 'ability'=>'auto']);
        // Применяем урон
        $hpBefore = $target->hp;
        $this->applyDamage($state, $target, $val, 'shot', $source);

        // Записываем результат
        $state->battle['strike']['auto_effects'][] = [
            'name'      => $effect['name'] ?? 'Auto',
            'source_id' => $source->instanceId,
            'target_id' => $target->instanceId,
            'damage'    => max(0, $hpBefore - $target->hp),
        ];

        unset($state->battle['strike']['pending_auto']);

        if (!empty($state->battle['strike']['continuation_frame'])) {
            $this->publishContinuationAutoResult($state);
            return Result::ok(["auto_target:{$targetId}"]);
        }

        // Закрываем сражение
        $this->closeForcedStrikeAttackerAfter($state);
        CombatContinuation::localDone($state, $state->battle['strike'] ?? []);
        $state->battle['strike'] = null;
        $this->finalizeDying($state);

        $state->bumpVersion();
        return Result::ok(["auto_target:{$targetId}"]);
    }

    private function closeForcedStrikeAttackerAfter(GameState $state): void
    {
        $strike = $state->battle['strike'] ?? null;
        if (!$strike || empty($strike['forced_close_attacker_after'])) return;

        $attacker = $state->getCard((int) ($strike['attacker_id'] ?? 0));
        if (!$attacker
            || $attacker->dying
            || $attacker->hp <= 0
            || ($attacker->zone !== CardInstance::ZONE_FIELD
                && $attacker->zone !== CardInstance::ZONE_FLYING)) {
            return;
        }

        $attacker->closed = true;
    }

    private function publishContinuationAutoResult(GameState $state): void
    {
        // Keep death choices inside the combat, then return through its full after lifecycle.
        $state->battle['strike']['continuation_auto_done'] = true;
        $state->battle['strike']['state'] = 'results';
        $state->battle['strike']['confirmed'] = [];
        InformationalWaitManager::publish($state);
        $state->bumpVersion();
    }

    public function clearRootedBySource(GameState $state, int $sourceId): void
    {
        $this->damageResolver($state)->clearRootedBySource($sourceId);
    }

    public function getRegenerationAmount(CardInstance $card): int
    {
        $regen = $card->effectiveProp()['regeneration'] ?? 0;
        if (is_array($regen)) $regen = (int) ($regen['value'] ?? 0);
        else $regen = (int) $regen;

        foreach ($card->effectiveModifiers() as $m) {
            if (($m['stat'] ?? '') === 'regeneration') {
                $regen += (int) ($m['value'] ?? 0);
            }
        }
        return $regen;
    }

    public function triggerOnAnyDeath(GameState $state, CardInstance $died, string $cause = 'any'): void
    {
        $this->damageResolver($state)->triggerOnAnyDeath($died, $cause);
    }

    private function damageResolver(GameState $state): DamageResolver
    {
        return new DamageResolver($state, \Closure::fromCallable([$this, 'syncCoinBonus']));
    }

    public function tryProphecyBlock(
        GameState $state,
        CardInstance $attacker,
        CardInstance $target,
        string $actionType
    ): bool {
        $config = $target->effectiveProp()['prophecy_block'] ?? null;
        if (!$config) return false;

        $attackTypes = ['strike', 'tap', 'uchr', 'shot', 'throw'];
        if (!in_array($actionType, $attackTypes, true)) return false;

        $pp     = new ProphecyProcessor($state, $this);
        $peeked = $pp->peek($target->owner, 1);
        if ($peeked === null) return false;

        $isOdd = (bool) $peeked['meta']['is_odd'];

        $alreadyBlocked = !empty($target->flags['attack_block_used_this_turn']);
        $willBlock      = $isOdd && !$alreadyBlocked;

        if ($willBlock) {
            $target->flags['attack_block_used_this_turn'] = true;
        }

        $title = match (true) {
            $willBlock => 'Нечётная цена — атака заблокирована',
            $isOdd     => 'Нечётная цена, но блок уже использован',
            default    => 'Чётная цена — атака проходит',
        };

        $actions = [['label' => 'Закрыть', 'cmd' => 'close_prophecy', 'class' => 'skip']];

        $pp->commit($target->owner, $target, $peeked, 'block', $title, $actions);

        return $willBlock;
    }

    public function syncCoinBonus(CardInstance $card): void
    {
        // Maintain the raw derived cache even while its gameplay view is suppressed.
        if (empty($card->prop['coin_strike_bonus'])) return;

        // Убираем старый модификатор
        $card->modifiers = array_values(array_filter(
            $card->modifiers,
            fn($m) => ($m['stat'] ?? '') !== 'coin_strike_bonus'
        ));

        if ($card->coins <= 0) return;

        $card->modifiers[] = [
            'stat'   => 'coin_strike_bonus',
            'value'  => (int) $card->coins,
            'expire' => 'permanent',
        ];
    }
}
