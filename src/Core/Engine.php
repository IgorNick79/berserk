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
    public function __construct(private ?Db $db = null) {}

    public function apply(GameState $state, string $playerKey, Command $cmd): Result
    {
        $result = $this->doApply($state, $playerKey, $cmd);
        GameLog::append($state, $playerKey, $cmd, $result);
        return $result;
    }

    private function doApply(GameState $state, string $playerKey, Command $cmd): Result
    {
        unset($state->battle['instant_result']);

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
            'choose_mode'  => $prepare->chooseMode($playerKey, $cmd),
            'confirm_settings' => $prepare->confirmSettings($playerKey, $cmd),
            'draft_row'    => $prepare->draftRow($playerKey, $cmd),
            'draft_col'    => $prepare->draftCol($playerKey, $cmd),
            'draft_pass'   => $prepare->draftPass($playerKey),
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
            'confirm_strike'      => $strike->confirmStrike($playerKey, $cmd),
            'choose_strike_mode'  => $strike->chooseStrikeMode($playerKey, $cmd),
            'choose_after_strike_execute' => $strike->chooseAfterStrikeExecute($playerKey, $cmd),
            'choose_forced_strike' => $action->chooseForcedStrike($playerKey, $cmd),
            'uchr'                => $action->uchr($playerKey, $cmd),
            'action'              => $action->handle($playerKey, $cmd),
            'end_turn'            => $turn->endTurn($playerKey, $cmd),
            'resign'              => $this->resign($state, $playerKey, $cmd),
            'gain_coin'               => $this->gainCoin($state, $playerKey, $cmd),
            'choose_death_target'     => $this->damageResolver($state)->chooseDeathTarget($playerKey, $cmd),
            'choose_auto_target'      => $this->chooseAutoTarget($state, $playerKey, $cmd),
            'choose_card_option'      => $turn->chooseCardOption($playerKey, $cmd),
            'choose_push_choice'      => $strike->choosePushChoice($playerKey, $cmd),
            'choose_any_death_target' => $this->damageResolver($state)->chooseAnyDeathTarget($playerKey, $cmd),
            'choose_transfer_donor'   => $action->chooseTransferDonor($playerKey, $cmd),
            'choose_transfer_amount'  => $action->chooseTransferAmount($playerKey, $cmd),
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
            'choose_close_or_damage'  => $strike->chooseCloseOrDamage($playerKey, $cmd),
            'choose_ally_modifier'    => $strike->chooseAllyModifier($playerKey, $cmd),
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
        ?string $choice = null
    ): ?string {
        $type = $effect['type'] ?? '';
        $strike = &$state->battle['strike'];

        switch ($type) {

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

                if ($mode === 'set') {
                    if ($current === $value) return 'уже ' . $value;
                    $strike['result'][$side] = $value;
                    if ($isAttackerSide) $strike['level_overridden'] = true;
                    return null;
                }

                if ($mode === 'reduce_one') {
                    $levels = ['strong' => 'medium', 'medium' => 'weak', 'weak' => ''];
                    $new = $levels[$current] ?? '';
                    if ($new === $current) return 'уже минимум';
                    $strike['result'][$side] = $new;
                    return null;
                }
                return 'неверный mode';

            case 'damage_cap':
                $cap = (int) ($effect['value'] ?? 0);
                $strike['damage_cap'] = $cap;

                $selfWound = (int) ($effect['self_wound'] ?? 0);
                if ($selfWound > 0 && $source) {
                    $targetId = $strike['defender_id'] ?: $strike['target_id'];
                    $target   = $state->getCard($targetId);

                    $skipWound = false;
                    $except    = $effect['except_element'] ?? null;
                    if ($except && $target && $target->element === $except) {
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
                    $strike['attack_dice'] = random_int(1, 6);
                    $strike['defend_dice'] = random_int(1, 6);
                    $sr->recalcTable();
                    return null;
                }

                if ($op === 'plus') {
                    if ($isAttackDice) $strike['attack_dice']++;
                    else               $strike['defend_dice']++;
                    $sr->recalcTable();
                    return null;
                }

                if ($op === 'minus') {
                    if ($isAttackDice) {
                        $strike['attack_dice'] = max(1, (int) $strike['attack_dice'] - 1);
                    } else {
                        $strike['defend_dice'] = max(1, (int) $strike['defend_dice'] - 1);
                    }
                    $sr->recalcTable();
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
                if (!$isAttackerMine && (int) $strike['attack_dice'] === $diceVal) {
                    $hits[] = $attacker;
                }

                // Кубик защитника — противник владельца Мэри?
                if ($isAttackerMine && $defender && (int) $strike['defend_dice'] === $diceVal) {
                    $hits[] = $defender;
                }

                if (empty($hits)) {
                    return 'кубик противника ≠ ' . $diceVal;
                }

                foreach ($hits as $t) {
                    $this->applyDamage($state, $t, $damage, 'damage_on_dice', $source);
                }
                return null;
        }
        return 'неизвестный эффект: ' . $type;
    }

    public function applyInstantEffect(GameState $state, array $effect, ?CardInstance $source, CardInstance $target, string $ownerKey): void
    {
        $type = $effect['type'] ?? '';

        switch ($type) {
            case 'open':
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
                $wounds = (int) ($target->flags['damage_taken_this_turn'] ?? 0);
                if ($wounds > 0) {
                    $target->hp += $wounds;
                    if ($target->hp > $target->hpMax) $target->hp = $target->hpMax;
                }
                break;
            case 'heal':
                $value = (int) ($effect['value'] ?? 1);
                $target->hp += $value;
                if ($target->hp > $target->hpMax) $target->hp = $target->hpMax;
                break;
            case 'close_target':
                $target->closed = true;
                break;
            case 'marker':
                $this->applyMarker($target, $effect['marker'] ?? [], $ownerKey);
                break;
        }
    }

    public function openCard(CardInstance $card): void
    {
        $card->closed = false;
        $card->flags['attacks_used_this_turn'] = 0;
        $card->flags['shot_used_this_turn'] = false;
        $card->flags['after_strike_execute_used_this_turn'] = 0;
        unset($card->flags['first_attack_target_id']);
    }

    public function applyDamage(
        GameState $state,
        CardInstance $target,
        int $val,
        string $actionType = 'strike',
        ?CardInstance $attacker = null,
        bool $skipHunt = false
    ): void {
        $this->damageResolver($state)->applyDamage($target, $val, $actionType, $attacker, $skipHunt);
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
        if ($state->status !== 'battle') {
            return Result::error('Сдаться можно только в бою');
        }
        if ($state->winner !== null) {
            return Result::error('Игра уже завершена');
        }

        $state->winner = $state->getOpponentKey($playerKey);
        $state->status = 'game_over';
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
        if ($card->closed) {
            return Result::error('Карта закрыта');
        }
        if (empty($card->prop['save_coins'])) {
            return Result::error('Карта не умеет копить монеты');
        }

        $max = (int) ($card->prop['coins']['max_value'] ?? 0);
        if ($max > 0 && $card->coins >= $max) {
            return Result::error('Максимум монет');
        }

        $card->coins++;
        $card->closed = true;
        $this->syncCoinBonus($card);
        $state->bumpVersion();

        return Result::ok(["coin_gained:{$playerKey}:{$cardId}:total={$card->coins}"]);
    }

    public function applyMarker(CardInstance $target, array $marker, string $sourceKey): void
    {
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

        // zoo — защита от отравлений
        if (!empty($target->prop['zoo'])) {
            return;
        }

        if (!isset($target->markers['poison'])) {
            $target->markers['poison'] = [
                'value'  => $value,
                'source' => $sourceKey,
                'timing' => 'permanent',
            ];
        } else {
            // Замещение: ставим большее
            if ($value > $target->markers['poison']['value']) {
                $target->markers['poison']['value'] = $value;
            }
        }
    }

    public function applyRegeneration(CardInstance $card): void
    {
        $regen = $card->prop['regeneration'] ?? 0;
        if (is_array($regen)) $regen = (int) ($regen['value'] ?? 0);
        else $regen = (int) $regen;

        // Модификаторы (на будущее)
        foreach ($card->modifiers as $m) {
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
        $effects = $effects ?? ($attacker->prop['strike_effects'] ?? []);
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
        $auto = $attacker->prop['auto'] ?? null;
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

        // Закрываем сражение
        $state->battle['strike'] = null;
        $this->finalizeDying($state);

        $state->bumpVersion();
        return Result::ok(["auto_target:{$targetId}"]);
    }

    public function clearRootedBySource(GameState $state, int $sourceId): void
    {
        $this->damageResolver($state)->clearRootedBySource($sourceId);
    }

    public function getRegenerationAmount(CardInstance $card): int
    {
        $regen = $card->prop['regeneration'] ?? 0;
        if (is_array($regen)) $regen = (int) ($regen['value'] ?? 0);
        else $regen = (int) $regen;

        foreach ($card->modifiers as $m) {
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
        $config = $target->prop['prophecy_block'] ?? null;
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
