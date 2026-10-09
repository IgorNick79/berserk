<?php
// src/Core/ActionResolver.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Логика действий карт (кроме простого удара — он в StrikeResolver).
 * Обрабатывает: shot, throw, discharge, magic, cast, tap, heal, impact, execute, uchr.
 */
final class ActionResolver
{
    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {}

    // ─── Команда action ───────────────────────────────────────

    public function handle(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'battle') {
            return Result::error('Сейчас не бой');
        }
        if ($this->state->battle['active'] !== $playerKey) {
            return Result::error('Сейчас не ваш ход');
        }
        if (!empty($this->state->battle['strike'])) {
            return Result::error('Идёт сражение');
        }

        if (!empty($this->state->battle['pending_coin_spend'])) {
            return Result::error('Сначала выберите количество монет');
        }

        // Обязательная атака
        foreach ($this->state->cards as $c) {
            if ($c->owner !== $playerKey) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if (CardStats::getForcedStrikeTarget($this->state, $c) !== null) {
                return Result::error('Сначала обязаны атаковать закрытое существо');
            }
        }

        $cardId    = (int) $cmd->get('card_id', 0);
        $targetId  = (int) $cmd->get('target_id', 0);
        $actionKey = (string) $cmd->get('action_key', '');

        $attacker = $this->state->getCard($cardId);
        $err = $this->validateAttacker($attacker, $playerKey);
        if ($err) return $err;

        $action = $this->findAction($attacker, $actionKey);
        if (!$action) {
            return Result::error('Действие не найдено');
        }

        $type = $action['type'] ?? '';
        if (CardStats::hasCannotAttack($attacker) && CardStats::isOffensiveAction($type)) {
            return Result::error('Карта не может атаковать до конца хода');
        }

        if ($type === 'place_cell_marker') {
            return $this->startPlaceCellMarker($attacker, $action, $cardId, $playerKey);
        }

        if ($type === 'become_fly') {
            return $this->resolveBecomeFly($attacker, $action, $cardId, $playerKey);
        }

        if ($type === 'grant_prop') {
            return $this->resolveGrantProp($attacker, $action, $cardId, $playerKey);
        }

        if ($type === 'bomb_shot') {
            return $this->resolveBombShot($attacker, $action, $cardId, $targetId, $playerKey);
        }
        
        if ($type === 'steal_coin') {
            return $this->resolveStealCoin($attacker, $action, $cardId, $targetId, $playerKey);
        }

        if ($type === 'give_coin') {
            return $this->resolveGiveCoin($attacker, $action, $cardId, $targetId, $playerKey);
        }

        if ($type === 'poison_target') {
            return $this->resolvePoisonTarget($attacker, $action, $cardId, $targetId, $playerKey);
        }

        if ($type === 'poison_boost') {
            return $this->resolvePoisonBoost($attacker, $action, $cardId, $targetId, $playerKey);
        }

        if ($type === 'damage_poisoned') {
            return $this->resolveDamagePoisoned($attacker, $action, $cardId, $playerKey);
        }

        if ($type === 'dive') {
            return $this->startDive($attacker, $action, $cardId, $targetId, $playerKey);
        }

        if ($type === 'row_spell') {
            return $this->startRowSpell($attacker, $action, $cardId, $playerKey);
        }

        if ($type === 'particle') {
            return $this->startParticlePick($attacker, $action, $cardId, $playerKey);
        }

        if ($type === 'life_gift') {
            return $this->startLifeGift($attacker, $action, $cardId, $playerKey);
        }

        if ($type === 'freeze_moves') {
            return $this->resolveFreezeMoves($attacker, $action, $cardId, $playerKey);
        }

        if ($type === 'mark_opponent_row') {
            return $this->startOpponentRowMarker($attacker, $action, $cardId, $playerKey, $actionKey);
        }

        if ($type === 'apply_delayed_marker') {
            return $this->resolveApplyDelayedMarker($attacker, $action, $cardId, $targetId, $playerKey);
        }

        if ($type === 'destroy_self_and_target') {
            return $this->startDestroySelfAndTarget($attacker, $action, $cardId, $playerKey);
        }

        if ($type === 'damage_ranged') {
            return $this->resolveDamageRanged($attacker, $action, $cardId, $playerKey);
        }

        if ($type === 'teleport_target') {
            return $this->startTeleportTarget($attacker, $action, $cardId, $targetId, $playerKey);
        }


        // ── Перераспределение ран (Волхв) ──────────────────────
        if ($type === 'wound_transfer') {
            $proc = new WoundTransferProcessor($this->state, $this->engine);
            $options = [
                'kind'          => (string) ($action['kind']          ?? 'volkhv'),
                'donor_filter'  => (string) ($action['donor_filter']  ?? 'wounded'),
                'target_filter' => (string) ($action['target_filter'] ?? 'enemy'),
                'max_transfer'  => (int)    ($action['max_transfer']  ?? 2),
                'coins_cost'    => (int)    ($action['coins']         ?? 0),
                'on_finish'     => (string) ($action['on_finish']     ?? 'main_phase'),
            ];
            if (isset($action['donor_element'])) $options['donor_element'] = (string) $action['donor_element'];
            if (!empty($action['donor_near']))   $options['donor_near']    = true;

            return $proc->start($playerKey, $attacker, $options);
        }

        // Грезы Архааля
        if ($type === 'grezy_prophecy') {
            return $this->startGrezyProphecy($attacker, $action, $cardId, $playerKey);
        }

        if ($type === 'dissonance') {
            return $this->resolveDissonance($attacker, $action, $cardId, $targetId, $playerKey);
        }

        // Множественное излечение (Фея леса)
        if ($type === 'heal' && !empty($action['max_targets'])) {
            return $this->startMultiHeal($attacker, $action, $cardId, $playerKey);
        }

        // Множественный разряд (Аколит Дзара)
        if ($type === 'multi_discharge') {
            return $this->startMultiDischarge($attacker, $action, $cardId, $playerKey);
        }

        // Украсть оружие (Лесной разбойник)
        if ($type === 'steal_strike') {
            return $this->resolveStealStrike($attacker, $action, $cardId, $targetId, $playerKey);
        }

        // Песчаные когти (Хозяйка прайда)
        if ($type === 'sand_claws') {
            return $this->resolveSandClaws($attacker, $action, $cardId, $targetId, $playerKey);
        }

        // Кровавый разряд (Ведьма слуа)
        if ($type === 'blood_tap') {
            return $this->startBloodTap($attacker, $action, $cardId, $targetId, $playerKey);
        }

        // Возрождение (Знахарь племени)
        if ($type === 'revive') {
            return $this->startRevive($attacker, $action, $cardId, $playerKey);
        }

        // Таран (Центурион): выбор количества ран, урон X-1 напротив
        if ($type === 'self_wound_strike') {
            if (empty($action['target']) || $action['target'] !== 'opposite') {
                // нечего проверять — на всякий
            }
            $cost = (int) ($action['coins'] ?? 0);
            if ($attacker->coins < $cost) {
                return Result::error('Не хватает монет');
            }

            $target = $this->state->getCard($targetId);
            if (!$target) {
                return Result::error('Цель не найдена');
            }
            if ($target->owner === $playerKey) {
                return Result::error('Нельзя бить своих');
            }
            if (!CardStats::isOpposite($attacker, $target)) {
                return Result::error('Только по карте напротив');
            }

            $attacker->coins -= $cost;

            $this->state->battle['pending_self_wound'] = [
                'attacker_id' => $cardId,
                'target_id'   => $targetId,
                'action'      => $action,
                'max_wounds'  => $attacker->hp,
            ];

            $this->state->bumpVersion();
            return Result::ok(['self_wound_started']);
        }

        $target = $this->state->getCard($targetId);
        if (!$target
            || ($target->zone !== CardInstance::ZONE_FIELD
                && $target->zone !== CardInstance::ZONE_FLYING)) {
            return Result::error('Цель не на поле');
        }

        if ($target->owner === $playerKey && $target->instanceId !== $attacker->instanceId) {
            $allowedFriendlyTargets = BattleHelper::getAttackTargets(
                $this->state,
                $attacker,
                'action:' . $actionKey,
                $playerKey,
                true
            );
            if (!isset($allowedFriendlyTargets[$target->instanceId])) {
                return Result::error('Нельзя атаковать эту свою карту');
            }
        }

        // Раскрытие атакованной цели
        $isAttack = in_array($type, ['shot', 'throw', 'discharge', 'magic', 'cast', 'tap'], true)
            && $target->owner !== $playerKey;
        if ($isAttack) {
            $this->engine->revealCard($this->state, $target);
        }

        // Проверка цели по типу
        $err = $this->validateTarget($attacker, $target, $action, $type, $playerKey);
        if ($err) return $err;

        // Пророчество-блок (Дочь перламутра)
        $blockTypes = ['shot', 'throw', 'tap', 'magic', 'cast', 'discharge'];
        if (in_array($type, $blockTypes, true)) {
            if ($this->engine->tryProphecyBlock($this->state, $attacker, $target, $type)) {
                $attacker->closed = true;
                $this->state->bumpVersion();
                return Result::ok(["action_blocked:{$type}:{$cardId}->{$targetId}"]);
            }
        }

        // Условие ally_price_near (Оури)
        if (!empty($action['condition'])
            && ($action['condition']['type'] ?? '') === 'ally_price_near') {

            if (empty($attacker->flags['moved_this_turn'])
                || !empty($attacker->flags['shot_used_this_turn'])
                || !CardStats::hasAllyPriceNear($this->state, $attacker, (int) $action['condition']['min'])) {
                return Result::error('Условие не выполнено: нужно подойти к существу 7+');
            }
        }

         // Общее строковое условие (Демон зависти и др.)
        if (!empty($action['condition'])
            && is_string($action['condition'])
            && !CardStats::checkCondition($action['condition'], $this->state, $attacker)) {
            return Result::error('Условие действия не выполнено');
        }

        // Выбор количества монет
        $coinConfig = $attacker->prop['coins'][$type] ?? null;
        if (is_array($coinConfig)
            && ($coinConfig['spend'] ?? '') === 'choice'
            && $attacker->coins > 0
            && $targetId > 0
        ) {
            return $this->startCoinSpendChoice(
                $attacker, $action, $type, $cardId, $targetId, $playerKey
            );
        }

        // Монеты
        $cost = (int) ($action['coins'] ?? 0);
        if ($cost > 0) {
            if ($attacker->coins < $cost) {
                return Result::error('Не хватает монет');
            }
            $attacker->coins -= $cost;
        }

        // Особые пути
        if ($type === 'heal')   return $this->resolveHeal($attacker, $target, $action, $cardId, $targetId, $playerKey);
        if ($type === 'impact') return $this->resolveImpact($attacker, $action, $cardId, $targetId, $playerKey);
        if ($type === 'execute') return $this->resolveExecute($attacker, $target, $action, $cardId, $targetId, $playerKey);
        if (!empty($action['grant_modifier'])) {
            return $this->resolveGrantModifier($attacker, $action, $cardId, $targetId, $playerKey);
        }

        // Остальные — бросок кубика
        return $this->resolveDamage($attacker, $target, $action, $type, $cardId, $targetId, $playerKey);
    }

    // ─── УЧР ──────────────────────────────────────────────────

    public function uchr(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'battle') {
            return Result::error('Сейчас не бой');
        }
        if ($this->state->battle['active'] !== $playerKey) {
            return Result::error('Сейчас не ваш ход');
        }
        if (!empty($this->state->battle['strike'])) {
            return Result::error('Идёт сражение');
        }

        if (!empty($this->state->battle['pending_coin_spend'])) {
            return Result::error('Сначала выберите количество монет');
        }

        // Обязательная атака
        foreach ($this->state->cards as $c) {
            if ($c->owner !== $playerKey) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if (CardStats::getForcedStrikeTarget($this->state, $c) !== null) {
                return Result::error('Сначала обязаны атаковать закрытое существо');
            }
        }

        $cardId   = (int) $cmd->get('card_id', 0);
        $targetId = (int) $cmd->get('target_id', 0);

        $attacker = $this->state->getCard($cardId);
        $target   = $this->state->getCard($targetId);

        if (!$attacker || $attacker->owner !== $playerKey || $attacker->zone !== CardInstance::ZONE_FIELD) {
            return Result::error('Атакующий не на поле');
        }
        if (CardStats::isDisabled($attacker)) {
            $reason = CardStats::disabledReason($attacker);
            return Result::error($reason !== '' ? $reason : 'Атакующий не может действовать');
        }
        if (CardStats::hasCannotAttack($attacker)) {
            return Result::error('Карта не может атаковать до конца хода');
        }
        if (!$target || $target->zone !== CardInstance::ZONE_FIELD) {
            return Result::error('Цель не на поле');
        }
        if ($target->owner === $playerKey) {
            $allowedFriendlyTargets = BattleHelper::getAttackTargets(
                $this->state,
                $attacker,
                'uchr',
                $playerKey,
                true
            );
            if (!isset($allowedFriendlyTargets[$target->instanceId])) {
                return Result::error('Нельзя атаковать эту свою карту');
            }
        }

        if ($target->owner !== $playerKey) {
            $this->engine->revealCard($this->state, $target);
        }

        $uchrAction = null;
        foreach ($attacker->prop['actions'] ?? [] as $a) {
            if (($a['type'] ?? '') === 'uchr') {
                $uchrAction = $a;
                break;
            }
        }
        if (!$uchrAction) {
            return Result::error('У карты нет УЧР');
        }

        $drow = abs($target->row - $attacker->row);
        $dcol = abs($target->col - $attacker->col);
        if (($drow + $dcol) !== 2) {
            return Result::error('УЧР только на расстоянии 2 по прямой');
        }
        if ($drow !== 0 && $dcol !== 0) {
            return Result::error('УЧР не по диагонали');
        }

        // Пророчество-блок (Дочь перламутра)
        if ($this->engine->tryProphecyBlock($this->state, $attacker, $target, 'uchr')) {
            $attacker->closed = true;
            $this->state->bumpVersion();
            return Result::ok(["uchr_blocked:{$cardId}->{$targetId}"]);
        }

        $midRow = $attacker->row;
        $midCol = $attacker->col;
        if ($drow === 0) {
            $midCol = (int) (($attacker->col + $target->col) / 2);
        } else {
            $midRow = (int) (($attacker->row + $target->row) / 2);
        }

        $midCard = null;
        foreach ($this->state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->row === $midRow && $c->col === $midCol) {
                $midCard = $c;
                break;
            }
        }

        foreach ($this->state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->row === $midRow && $c->col === $midCol) {
                if ($c->owner !== $playerKey) {
                    return Result::error('Между вами и целью чужое существо');
                }
                break;
            }
        }

        $dice   = Dice::roll();
        $level  = BattleHelper::diceToLevel($dice);

        $val = 0;
        if (isset($uchrAction['strike'])) {
            $val = (int) ($uchrAction['strike'][$level] ?? 0);
        } elseif (isset($uchrAction['value'])) {
            $val = (int) $uchrAction['value'];
        }

        $this->state->battle['strike'] = [
            'kind'        => 'uchr',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => $dice,
            'defend_dice' => 0,
            'result'      => ['attack' => $level, 'defend' => '', 'winner' => 'attack'],
            'final'       => ['attack' => $level, 'defend' => '', 'decreased' => false],
            'damage'      => $val,
            'confirmed'   => [],
        ];

        $val += CardStats::getAbilityBonus($this->state, $attacker, $target, 'uchr');
        $reduction = CardStats::getDamageReduction($this->state, $attacker, $target, 'uchr');
        $val -= $reduction;
        if ($val < 0) $val = 0;

        $attackReduction = $this->engine->reduceAttackValueByCellMarkers($this->state, $attacker, $val);
        $val = (int) $attackReduction['value'];
        foreach ($attackReduction['events'] as $event) {
            $this->state->battle['strike']['attack_value_reduction'][] = $event;
        }

        $defended = CardStats::hasDefense($this->state, $target, 'uchr', $attacker);
        if ($defended) {
            $val = 0;
            $this->state->battle['strike']['defended'] = true;
        }
        if ($this->engine->tryBlockDamageAttackWithMarker($this->state, $target, 'uchr', $val)) {
            $val = 0;
        }
        $successfulHit = $val > 0;

        $this->engine->applyDamage($this->state, $target, $val, 'uchr', $attacker);
        $this->state->battle['strike']['damage_reduction'] = $reduction;
        $this->state->battle['strike']['damage_total'] = $val;

        // Эффекты на промежуточную карту (через кого бьют)
        if ($midCard && !empty($uchrAction['apply_to_mid'])) {
            foreach ($uchrAction['apply_to_mid'] as $eff) {
                if (($eff['type'] ?? '') === 'modifier') {
                    $midCard->modifiers[] = [
                        'stat'   => $eff['stat'],
                        'value'  => (int) ($eff['value'] ?? 1),
                        'expire' => $eff['expire'] ?? 'end_of_turn',
                        'source' => $playerKey,
                    ];

                    $this->state->battle['strike']['uchr_mid_effects'][] = [
                        'target_id'   => $midCard->instanceId,
                        'target_ukid' => $midCard->ukid,
                        'stat'        => $eff['stat'],
                        'value'       => (int) ($eff['value'] ?? 1),
                    ];
                }
            }
        }

        $this->engine->flushDeadeatQueue($this->state);
        if ($successfulHit && !$defended) {
            $this->openSuccessfulHitOptionalHeal($attacker);
        }
        $attacker->closed = true;

        $this->state->bumpVersion();
        return Result::ok(["uchr:{$playerKey}:{$cardId}->{$targetId}:dice={$dice}:dmg={$val}"]);
    }

    // ─── Проверки ─────────────────────────────────────────────

    private function validateAttacker(?CardInstance $attacker, string $playerKey): ?Result
    {
        if (!$attacker
            || $attacker->owner !== $playerKey
            || ($attacker->zone !== CardInstance::ZONE_FIELD
                && $attacker->zone !== CardInstance::ZONE_FLYING)) {
            return Result::error('Карта не на поле');
        }
        if (CardStats::isDisabled($attacker)) {
            $reason = CardStats::disabledReason($attacker);
            return Result::error($reason !== '' ? $reason : 'Карта не может действовать');
        }
        return null;
    }

    private function findAction(CardInstance $attacker, string $actionKey): ?array
    {
        foreach ($attacker->prop['actions'] ?? [] as $a) {
            $key = $a['key'] ?? $a['type'] ?? '';
            if ($key === $actionKey) {
                return $a;
            }
        }
        return null;
    }

    private function validateTarget(
        CardInstance $attacker,
        CardInstance $target,
        array $action,
        string $type,
        string $playerKey
    ): ?Result {
        $isFriendlyFire = $target->owner === $playerKey
            && $target->instanceId !== $attacker->instanceId
            && CardStats::canFriendlyFireAction($action);

        if ($type === 'tap') {
            if (!empty($action['self']) && $target->instanceId !== $attacker->instanceId) {
                return Result::error('Только на себя');
            }
            if (!empty($action['own']) && $target->owner !== $playerKey) {
                return Result::error('Только на своих');
            }
            if (empty($action['own']) && empty($action['self']) && $target->owner === $playerKey && !$isFriendlyFire) {
                return Result::error('Нельзя бить своих');
            }
            if (!empty($action['near'])) {
                $dr = abs($target->row - $attacker->row);
                $dc = abs($target->col - $attacker->col);
                if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) {
                    return Result::error('Цель не соседняя');
                }
            }
        } elseif ($type === 'heal') {
            if ($target->type !== 'creature' && $target->type !== 'fly') {
                return Result::error('Можно лечить только существ');
            }
            if (!empty($action['own']) && $target->owner !== $playerKey) {
                return Result::error('Только на своих');
            }
            if (!empty($action['self']) && $target->instanceId !== $attacker->instanceId) {
                return Result::error('Только на себя');
            }
            if (!empty($action['near'])) {
                $dr = abs($target->row - $attacker->row);
                $dc = abs($target->col - $attacker->col);
                if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) {
                    return Result::error('Цель не соседняя');
                }
            }
        } elseif ($type === 'execute') {
            if ($target->owner === $playerKey) {
                return Result::error('Нельзя добить своих');
            }
            if ($target->type === 'fly') {
                return Result::error('Нельзя добить летающего');
            }
            if (!empty($target->prop['incorporeal'])) {
                return Result::error('Нельзя добить бестелесного');
            }
            if ($target->hp > (int) ($action['value'] ?? 0)) {
                return Result::error('У цели слишком много HP');
            }
            if (!empty($action['near'])) {
                $dr = abs($target->row - $attacker->row);
                $dc = abs($target->col - $attacker->col);
                if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) {
                    return Result::error('Цель не соседняя');
                }
            }
        } elseif ($type === 'impact') {
            if (!empty($action['self_destroy']) && $target->instanceId !== $attacker->instanceId) {
                return Result::error('Цель — сам кастующий');
            }

            if (!empty($action['transfer_wounds'])) {
                $tw = $action['transfer_wounds'];
                $from = $tw['from'] ?? [];

                if (!empty($from['owner']) && $from['owner'] === 'own') {
                    if ($target->owner !== $playerKey) {
                        return Result::error('Только на своих');
                    }
                }
                if (!empty($from['near'])) {
                    $dr = abs($target->row - $attacker->row);
                    $dc = abs($target->col - $attacker->col);
                    if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) {
                        return Result::error('Цель не соседняя');
                    }
                }
                if (!empty($from['element']) && $target->element !== $from['element']) {
                    return Result::error('Неверный элемент цели');
                }
                if ($target->instanceId === $attacker->instanceId) {
                    return Result::error('Нельзя перераспределить на себя');
                }
            }
        } elseif ($type === 'magic') {
            if ($target->owner === $playerKey && !$isFriendlyFire) {
                return Result::error('Нельзя бить своих');
            }

            // Ближний удар (как strike), либо в пределах range если указан
            $drow = abs($target->row - $attacker->row);
            $dcol = abs($target->col - $attacker->col);
            $dist = $drow + $dcol;

            $range = CardStats::getEffectiveRange($this->state, $attacker, $action);
            if ($range > 0) {
                if ($dist > $range) {
                    return Result::error('Превышена дальность');
                }
                if ($dist === 0) {
                    return Result::error('Цель не соседняя');
                }
            } else {
                if ($drow > 1 || $dcol > 1 || $dist === 0) {
                    return Result::error('Цель не соседняя');
                }
            }
        } elseif ($type === 'poison_target') {
            if ($target->zone !== CardInstance::ZONE_FIELD
                && $target->zone !== CardInstance::ZONE_FLYING) {
                return Result::error('Цель не на поле');
            }
            return null;
        } elseif ($type === 'poison_boost') {
            if ($target->zone !== CardInstance::ZONE_FIELD
                && $target->zone !== CardInstance::ZONE_FLYING) {
                return Result::error('Цель не на поле');
            }
            if (empty($target->markers['poison'])) {
                return Result::error('Цель должна быть отравлена');
            }
            return null;
        } elseif ($type === 'apply_delayed_marker') {
            if (($action['target'] ?? '') === 'enemy_non_flying') {
                if ($target->owner === $playerKey) {
                    return Result::error('Только на врага');
                }
                if ($target->zone !== CardInstance::ZONE_FIELD) {
                    return Result::error('Цель должна быть нелетающей');
                }
                if ($target->type === 'fly') {
                    return Result::error('Цель должна быть нелетающей');
                }
            }

            $range = CardStats::getEffectiveRange($this->state, $attacker, $action);
            if ($range > 0) {
                $dist = abs($target->row - $attacker->row) + abs($target->col - $attacker->col);
                if ($dist === 0) {
                    return Result::error('Неверная цель');
                }
                if ($dist > $range) {
                    return Result::error('Превышена дальность');
                }
            }

            return null;
        } elseif (!empty($action['grant_modifier'])) {
            if (!empty($action['self']) && $target->instanceId !== $attacker->instanceId) {
                return Result::error('Только на себя');
            }
            if (!empty($action['own']) && $target->owner !== $playerKey) {
                return Result::error('Только на своих');
            }
            return null;
        } elseif ($type === 'dissonance') {
            if ($target->owner === $playerKey) {
                return Result::error('Только на врага');
            }
            if ($target->zone !== CardInstance::ZONE_FIELD) {
                return Result::error('Цель не на поле');
            }
            return null;
        } else {
            // Support-действие: cast с target = ally_other (Щит света)
            if (($action['target'] ?? '') === 'ally_other') {
                if ($target->owner !== $playerKey) {
                    return Result::error('Только на своих');
                }
                if ($target->instanceId === $attacker->instanceId) {
                    return Result::error('Только на другое существо');
                }
                if ($target->zone !== CardInstance::ZONE_FIELD
                    && $target->zone !== CardInstance::ZONE_FLYING) {
                    return Result::error('Цель не на поле');
                }
                return null;
            }

            // shot / throw / discharge / cast
            if ($target->owner === $playerKey && !$isFriendlyFire) {
                return Result::error('Нельзя бить своих');
            }

            $attackerIsFlying = ($attacker->zone === CardInstance::ZONE_FLYING);

            // Перехват Паука
            if ($attackerIsFlying
                && in_array($type, ['shot', 'throw', 'discharge', 'magic', 'cast', 'tap'], true)) {

                $interceptors = CardStats::getAirInterceptors($this->state, $playerKey);
                if (!empty($interceptors)) {
                    $allowedIds = array_map(fn($p) => $p->instanceId, $interceptors);
                    if (!in_array($target->instanceId, $allowedIds, true)) {
                        return Result::error('Летун может атаковать только Паука-пересмешника');
                    }
                }
            }


            // Перехват (только shot / throw / disacharge)
            if (in_array($type, ['shot', 'throw', 'discharge'], true)) {
                $interceptors = CardStats::getRangedInterceptors($this->state, $playerKey, $type);
                if (!empty($interceptors)) {
                    $allowedIds = array_map(fn($p) => $p->instanceId, $interceptors);
                    if (!in_array($target->instanceId, $allowedIds, true)) {
                        return Result::error('Стрелок может бить только Резчика идолов');
                    }
                    // цель — Резчик, дальность игнорируется, дальше не проверяем
                    return null;
                }
            }

            $targetIsFlying   = ($target->zone === CardInstance::ZONE_FLYING);

            if (!$targetIsFlying && !$attackerIsFlying) {
                $drow = abs($target->row - $attacker->row);
                $dcol = abs($target->col - $attacker->col);
                $maxd = max($drow, $dcol);
                $dist = $drow + $dcol;

                if ($maxd <= 1 && empty($action['near_shot'])) {
                    return Result::error('Дальняя атака невозможна по соседней клетке');
                }
                $range = CardStats::getEffectiveRange($this->state, $attacker, $action);
                if ($range > 0 && $dist > $range) {
                    return Result::error('Превышена дальность');
                }
            }
        }
        return null;
    }

    public function executeForced(
        string $playerKey,
        int $cardId,
        int $targetId,
        int $value,
        string $name = 'Добивание'
    ): Result {
        $attacker = $this->state->getCard($cardId);
        if (!$attacker || $attacker->owner !== $playerKey) {
            return Result::error('Атакующий не найден');
        }

        $target = $this->state->getCard($targetId);
        if (!$target) {
            return Result::error('Цель не найдена');
        }

        if (!CardStats::canExecuteTarget($this->state, $attacker, $target, $value)) {
            return Result::error('Нельзя добить эту цель');
        }

        return $this->resolveExecute(
            $attacker,
            $target,
            ['type' => 'execute', 'value' => $value, 'name' => $name],
            $cardId,
            $targetId,
            $playerKey
        );
    }

    // ─── Особые пути ──────────────────────────────────────────

    private function resolveApplyDelayedMarker(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        $target = $this->state->getCard($targetId);
        if (!$target
            || ($target->zone !== CardInstance::ZONE_FIELD
                && $target->zone !== CardInstance::ZONE_FLYING)) {
            return Result::error('Цель не на поле');
        }

        $err = $this->validateTarget($attacker, $target, $action, 'apply_delayed_marker', $playerKey);
        if ($err) return $err;

        $marker = (array) ($action['marker'] ?? []);
        $markerType = (string) ($marker['type'] ?? '');
        if ($markerType === '') {
            return Result::error('Маркер не задан');
        }

        $scheduled = (array) ($this->state->battle['scheduled_card_markers'] ?? []);
        $kept = [];
        foreach ($scheduled as $entry) {
            if ((int) ($entry['target_id'] ?? 0) === $target->instanceId
                && (string) ($entry['marker']['type'] ?? '') === $markerType) {
                continue;
            }
            $kept[] = $entry;
        }

        $kept[] = [
            'source_owner' => $playerKey,
            'source_id'    => $attacker->instanceId,
            'source_ukid'  => $attacker->ukid,
            'target_id'    => $target->instanceId,
            'target_ukid'  => $target->ukid,
            'activate'     => (string) ($marker['activate'] ?? 'end_of_current_turn'),
            'marker'       => [
                'type'   => $markerType,
                'source' => $playerKey,
                'timing' => (string) ($marker['timing'] ?? 'source_next_turn_start'),
            ],
        ];
        $this->state->battle['scheduled_card_markers'] = array_values($kept);

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'apply_delayed_marker',
            'action_name' => $action['name'] ?? 'Способность',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'delayed_marker' => [
                'type' => $markerType,
                'target_id' => $targetId,
                'activate' => 'end_of_current_turn',
            ],
            'confirmed'   => [],
        ];

        $this->state->bumpVersion();
        return Result::ok(["delayed_marker:{$markerType}:{$cardId}->{$targetId}"]);
    }

    private function resolveBecomeFly(
        CardInstance $attacker, array $action,
        int $cardId, string $playerKey
    ): Result {
        if ($attacker->type === 'fly') {
            return Result::error('Уже летающий');
        }
        if ($attacker->zone !== CardInstance::ZONE_FIELD) {
            return Result::error('Не на поле');
        }

        $attacker->type = 'fly';
        $attacker->closed = true;

        (new ZoneManager($this->state))->toFlying($attacker);

        // Для отображения результата
        $this->state->battle['strike'] = [
            'kind'        => 'become_fly',
            'action_name' => $action['name'] ?? 'Получить полёт',
            'attacker_id' => $cardId,
            'target_id'   => $cardId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'confirmed'   => [],
        ];

        $this->state->bumpVersion();
        return Result::ok(["become_fly:{$playerKey}:{$cardId}"]);
    }

    private function resolveHeal(
        CardInstance $attacker, CardInstance $target, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        // Серк и подобные: при полном hp вместо излечения — открыться
        $insteadOpen  = $target->prop['on_heal_instead_open'] ?? null;
        $openedInstead = false;

        if (is_array($insteadOpen)) {
            $oncePerTurn = !empty($insteadOpen['once_per_turn']);
            $alreadyUsed = !empty($target->flags['on_heal_open_used_this_turn']);
            $fullHp      = ($target->hp >= $target->hpMax);

            if ($fullHp && (!$oncePerTurn || !$alreadyUsed)) {
                $this->engine->openCard($target);
                $target->flags['on_heal_open_used_this_turn'] = true;
                $openedInstead = true;
            }
        }

        $healValue     = 0;
        $poisonRemoved = false;

        if (!$openedInstead) {
            $healRaw = $action['value'] ?? 0;
            if ($healRaw === 'full') {
                $healValue = $target->hpMax - $target->hp;
            } else {
                $healValue = (int) $healRaw;
            }
            $target->hp += $healValue;
            if ($target->hp > $target->hpMax) $target->hp = $target->hpMax;

            if (!empty($action['heal_poison']) && isset($target->markers['poison'])) {
                unset($target->markers['poison']);
                $poisonRemoved = true;
            }
        }

        $attacker->closed = true;

        $strike = [
            'kind'           => 'heal',
            'action_name'    => $action['name'] ?? 'Излечение',
            'attacker_id'    => $cardId,
            'target_id'      => $targetId,
            'defender_id'    => null,
            'state'          => 'results',
            'attack_dice'    => 0,
            'defend_dice'    => 0,
            'result'         => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'          => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'         => 0,
            'heal'           => $healValue,
            'poison_removed' => $poisonRemoved,
            'confirmed'      => [],
        ];

        if ($openedInstead) {
            $strike['heal_instead_open'] = ['target_id' => $target->instanceId];
        }

        $this->state->battle['strike'] = $strike;

        $this->state->bumpVersion();
        return Result::ok(["heal:{$playerKey}:{$cardId}->{$targetId}:value={$healValue}"]);
    }

    private function applyShieldLight(
        CardInstance $attacker, CardInstance $target, array $action,
        int $amount, string $playerKey
    ): Result {
        $target->modifiers[] = [
            'stat'   => 'shield_light',
            'value'  => 1,
            'expire' => $amount,
            'timing' => 'not_source_turn',
            'source' => $playerKey,
        ];

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'shield_light',
            'action_name' => $action['name'] ?? 'Щит света',
            'attacker_id' => $attacker->instanceId,
            'target_id'   => $target->instanceId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'shield_data' => [
                'turns'     => $amount,
                'target_id' => $target->instanceId,
            ],
            'confirmed'   => [],
        ];

        $this->state->bumpVersion();
        return Result::ok(["shield:{$playerKey}:{$attacker->instanceId}->{$target->instanceId}:{$amount}"]);
    }

    private function resolveImpact(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        $this->state->battle['strike'] = [
            'kind'        => 'impact',
            'action_name' => $action['name'] ?? 'Воздействие',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'confirmed'   => [],
        ];

        $value  = (int) ($action['value'] ?? 0);
        $poison = (int) ($action['poison'] ?? 0);
        $applied = [];

        if (($action['target'] ?? '') === 'all_near') {
            foreach ($this->state->cards as $c) {
                if ($c->zone !== CardInstance::ZONE_FIELD) continue;
                if ($c->instanceId === $attacker->instanceId) continue;

                $filter = $action['filter'] ?? null;
                if ($filter === 'enemy' && $c->owner === $playerKey) continue;
                if ($filter === 'own'   && $c->owner !== $playerKey) continue;

                $dr = abs($c->row - $attacker->row);
                $dc = abs($c->col - $attacker->col);
                if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;

                $hpBefore = $c->hp;
                if ($value > 0) {
                    $this->engine->applyDamage($this->state, $c, $value, 'impact', $attacker);
                }
                if ($poison > 0) {
                    $this->engine->applyPoison($c, $poison, $playerKey);
                }
                $applied[] = [
                    'target_id' => $c->instanceId,
                    'damage'    => max(0, $hpBefore - $c->hp),
                    'poison'    => $poison,
                ];
            }
        }

        if (!empty($action['self_destroy'])) {
            $this->engine->forceDeath($this->state, $attacker, 'self_destroy', $attacker);
        }

        $attacker->closed = true;

        $this->state->battle['strike']['impact_targets'] = $applied;
        $this->state->battle['strike']['self_destroyed'] = !empty($action['self_destroy']);

        $this->engine->checkGameOver($this->state);
        $this->engine->flushDeadeatQueue($this->state);

        $this->state->bumpVersion();
        return Result::ok(["impact:{$playerKey}:{$cardId}"]);
    }

    private function resolveExecute(
        CardInstance $attacker, CardInstance $target, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        // Структура до смерти цели
        $this->state->battle['strike'] = [
            'kind'        => 'execute',
            'action_name' => $action['name'] ?? 'Добивание',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'execute'     => true,
            'confirmed'   => [],
        ];

        $this->engine->forceDeath($this->state, $target, 'execute', $attacker);

        $attacker->closed = true;

        $this->engine->flushDeadeatQueue($this->state);

        $this->state->bumpVersion();
        return Result::ok(["execute:{$playerKey}:{$cardId}->{$targetId}"]);
    }

    private function resolveGrantModifier(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        $raw = $action['grant_modifier'];

        // Принимаем и одиночный объект, и массив
        $modifiers = isset($raw['stat']) ? [$raw] : $raw;

        foreach ($modifiers as $modifier) {
            $attacker->modifiers[] = [
                'stat'   => $modifier['stat'],
                'value'  => $modifier['value'] ?? 1,
                'expire' => $modifier['expire'] ?? 'permanent',
                'source' => $playerKey,
            ];
        }

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'             => 'modifier',
            'action_name'      => $action['name'] ?? 'Способность',
            'attacker_id'      => $cardId,
            'target_id'        => $targetId,
            'defender_id'      => null,
            'state'            => 'results',
            'attack_dice'      => 0,
            'defend_dice'      => 0,
            'result'           => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'            => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'           => 0,
            'modifier_granted' => $modifiers,
            'confirmed'        => [],
        ];

        $this->state->bumpVersion();
        return Result::ok(["modifier:{$playerKey}:{$cardId}:{$modifier['stat']}"]);
    }

    private function resolveDamage(
        CardInstance $attacker, CardInstance $target, array $action,
        string $type, int $cardId, int $targetId, string $playerKey,
        ?int $forcedCoinSpend = null
    ): Result {
        $dice   = Dice::roll();
        $level  = BattleHelper::diceToLevel($dice);

        $val = 0;
        if (isset($action['strike']) && is_array($action['strike'])) {
            $val = (int) ($action['strike'][$level] ?? 0);
        } elseif (($action['value'] ?? null) === 'self_wounds') {
            // Рэккен: метание на X, где X — число ран на самом метателе (максимум max_value)
            $wounds = max(0, $attacker->hpMax - $attacker->hp);
            $cap    = (int) ($action['max_value'] ?? 0);
            if ($cap > 0) {
                $wounds = min($wounds, $cap);
            }
            $val = $wounds;
        } elseif (($action['value'] ?? null) === 'adjacent_ally_weak_sum') {
            $val = 0;
            foreach ($this->state->cards as $c) {
                if ($c->owner !== $attacker->owner) continue;
                if ($c->zone !== CardInstance::ZONE_FIELD) continue;
                if ($c->dying || $c->hp <= 0) continue;
                if ($c->row !== $attacker->row) continue;
                if (abs($c->col - $attacker->col) !== 1) continue;
                $val += $c->strikeWeak;
            }
        } elseif (isset($action['value'])) {
            $val = (int) $action['value'];
        }

        // Монеты за действие (Арбалетчик, Повелитель)
        $coinBonus = 0;
        $coinsSpent = 0;
        $coinConfig = $attacker->prop['coins'][$type] ?? null;
        // Аргвальд: coin_bonus + reset
        if (!empty($action['coin_bonus']) && $attacker->coins > 0) {
            $perCoin = (int) $action['coin_bonus'];
            $coinsHeld = $attacker->coins;
            $coinBonus = $perCoin * $coinsHeld;
            $coinsSpent = $coinsHeld;
            $attacker->coins = 0;
            $val += $coinBonus;

            if (!empty($action['reset_coins_after'])) {
                // уже сбросили
            }
        } elseif (is_array($coinConfig) && $attacker->coins > 0) {
            $perCoin = (int) ($coinConfig['value'] ?? 0);
            $spend   = $coinConfig['spend'] ?? 'all';
            if ($perCoin > 0) {
                $coinsHeld = $attacker->coins;

                if ($forcedCoinSpend !== null) {
                    $coinsSpent = min($coinsHeld, $forcedCoinSpend);
                    $coinBonus  = $perCoin * $coinsSpent;
                } else {
                    // как было
                    $coinBonus = $perCoin * $coinsHeld;
                    $coinsSpent = ($spend === 'all')
                        ? $coinsHeld
                        : min($coinsHeld, (int) $spend);
                }

                $attacker->coins -= $coinsSpent;
                $val += $coinBonus;

                $this->engine->syncCoinBonus($attacker);
            }
        }

        // Защита цели
        $defended = false;
        if (in_array($type, ['shot', 'throw', 'discharge', 'magic', 'cast'], true)) {
            $defended = CardStats::hasDefense($this->state, $target, $type, $attacker);
            if ($defended) $val = 0;
        }

        // Аргвальд: получает монету при разряде по нему
        if ($type === 'discharge' && !empty($target->prop['on_discharge_self'])) {
            $bonus = (int) ($target->prop['on_discharge_self']['coins'] ?? 1);
            if ($bonus > 0) {
                $target->coins += $bonus;
                $this->engine->syncCoinBonus($target);
            }
        }

        $this->state->battle['strike'] = [
            'kind'        => $type,
            'action_name' => $action['name'] ?? '',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => $dice,
            'defend_dice' => 0,
            'result'      => ['attack' => $level, 'defend' => '', 'winner' => 'attack'],
            'final'       => ['attack' => $level, 'defend' => '', 'decreased' => false],
            'damage'      => $val,
            'defended'    => $defended,
            'confirmed'   => [],
        ];

        // Эффекты действия (strike_effects) — например, яд при среднем/сильном разряде
        if (!empty($action['strike_effects'])) {
            $this->engine->applyStrikeEffects($attacker, $target, $level, $action['strike_effects']);
        }

        $reduction = 0;
        $shotBonus = 0;
        $abilityBonus = 0;

        $abilityBonus = CardStats::getAbilityBonus($this->state, $attacker, $target, $type, $level);
        $val += $abilityBonus;

        // flying_bonus (Крондак)
        $flyingBonus = 0;
        if (!empty($action['flying_bonus']) && $target->type === 'fly') {
            $flyingBonus = (int) $action['flying_bonus'];
            $val += $flyingBonus;
        }

        // all_rows_bonus (Суккуб-истязатель)
        $rowsBonus = 0;
        if (!empty($action['all_rows_bonus'])
            && CardStats::hasOwnCreatureInAllRows($this->state, $playerKey)) {
            $rowsBonus = (int) $action['all_rows_bonus'];
            $val += $rowsBonus;
        }

        $targetModifierBonus = $this->targetModifierBonus($action, $target);
        $val += $targetModifierBonus;

        if ($type === 'shot') {
            $shotBonus = CardStats::getShotBonus($attacker);
            $val += $shotBonus;
        }

        $nextActionBonus = CardStats::getNextActionBonus($attacker, $type);
        $val += $nextActionBonus;

        $reduction = CardStats::getDamageReduction($this->state, $attacker, $target, $type);
        $val -= $reduction;
        if ($val < 0) $val = 0;

        $attackReduction = $this->engine->reduceAttackValueByCellMarkers($this->state, $attacker, $val);
        $val = (int) $attackReduction['value'];
        foreach ($attackReduction['events'] as $event) {
            $this->state->battle['strike']['attack_value_reduction'][] = $event;
        }

        // Мира: закрыть цель, если она уже получала раны от 2+ других выстрелов/метаний
        $closeAfter = false;
        if (!empty($action['close_on_ranged_hits'])) {
            $hitsBefore = (int) ($target->flags['ranged_hits_this_turn'] ?? 0);
            if ($hitsBefore >= (int) $action['close_on_ranged_hits']) {
                $closeAfter = true;
            }
        }

        if ($this->engine->tryBlockDamageAttackWithMarker($this->state, $target, $type, $val)) {
            $val = 0;
        }

        $this->engine->applyDamage($this->state, $target, $val, $type, $attacker);

        if ($closeAfter) {
            $target->closed = true;
        }

        if ($reduction > 0) $this->state->battle['strike']['damage_reduction'] = $reduction;
        if ($abilityBonus > 0) $this->state->battle['strike']['ability_bonus'] = $abilityBonus;
        if ($shotBonus > 0) $this->state->battle['strike']['shot_bonus'] = $shotBonus;
        if ($nextActionBonus > 0) $this->state->battle['strike']['next_action_bonus'] = $nextActionBonus;
        if ($coinBonus > 0) {
            $this->state->battle['strike']['coin_bonus'] = $coinBonus;
            $this->state->battle['strike']['coins_spent'] = $coinsSpent;
        }
        if ($rowsBonus > 0) {
            $this->state->battle['strike']['rows_bonus'] = $rowsBonus;
        }
        if ($targetModifierBonus > 0) {
            $this->state->battle['strike']['target_modifier_bonus'] = $targetModifierBonus;
        }
        if ($flyingBonus > 0) $this->state->battle['strike']['flying_bonus'] = $flyingBonus;

        $this->engine->applyAnswer($this->state, $target, $attacker, $type);
        $this->engine->flushDeadeatQueue($this->state);

        // Итоговый урон после всех бонусов (для отображения)
        $this->state->battle['strike']['damage_total'] = $val;


        if (empty($action['no_close'])) {
            $attacker->closed = true;
        }

        $this->consumeNextActionBonuses($attacker, $type);

        // Условие-действие: помечаем как использованное
        if (!empty($action['condition'])
            && ($action['condition']['type'] ?? '') === 'ally_price_near') {
            $attacker->flags['shot_used_this_turn'] = true;
        }

        if (!empty($action['marker']['type'])) {
            $this->engine->applyMarker($target, $action['marker'], $playerKey);
        }
        if (is_numeric($action['poison'] ?? null) && (int) $action['poison'] > 0) {
            $this->engine->applyPoison($target, (int) $action['poison'], $playerKey);
        }

        $this->state->bumpVersion();
        return Result::ok(["action:{$playerKey}:{$type}:{$cardId}->{$targetId}:dmg={$val}"]);
    }

    private function targetModifierBonus(array $action, CardInstance $target): int
    {
        $mods = $action['target_modifier'] ?? null;
        if ($mods === null) {
            return 0;
        }
        if (is_array($mods) && !array_is_list($mods)) {
            $mods = [$mods];
        }
        if (!is_array($mods)) {
            return 0;
        }

        $bonus = 0;
        foreach ($mods as $mod) {
            if (!is_array($mod)) continue;
            $condition = (string) ($mod['condition'] ?? '');
            $ok = match ($condition) {
                'target_flying', 'target_is_flying' => CardStats::isFlyingCreature($target),
                default => $condition === '',
            };
            if (!$ok) continue;
            $bonus += (int) ($mod['value'] ?? 0);
        }
        return $bonus;
    }

    private function consumeNextActionBonuses(CardInstance $card, string $actionType): void
    {
        $kept = [];
        foreach ($card->modifiers as $m) {
            if (($m['stat'] ?? '') !== 'next_action_bonus' || empty($m['consume'])) {
                $kept[] = $m;
                continue;
            }

            $types = $m['types'] ?? null;
            if (is_array($types) && !in_array($actionType, $types, true)) {
                $kept[] = $m;
            }
        }

        $card->modifiers = $kept;
    }

    private function startCoinSpendChoice(
        CardInstance $attacker, array $action, string $type,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        $coinConfig = $attacker->prop['coins'][$type];

        $maxByProp = (int) ($coinConfig['max_value'] ?? 0);
        $max = $maxByProp > 0 ? min($attacker->coins, $maxByProp) : $attacker->coins;
        $min = (int) ($coinConfig['min_value'] ?? 0);

        $this->state->battle['pending_coin_spend'] = [
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'action'      => $action,
            'type'        => $type,
            'max_coins'   => $max,
            'min_coins'   => $min,
            'per_coin'    => (int) ($coinConfig['value'] ?? 0),
            'mode'        => (string) ($coinConfig['mode'] ?? 'damage'),
        ];

        $this->state->bumpVersion();
        return Result::ok(['coin_spend_started']);
    }

    public function chooseCoinSpend(string $playerKey, Command $cmd): Result
    {
        $pcs = $this->state->battle['pending_coin_spend'] ?? null;
        if (!$pcs) {
            return Result::error('Нет ожидающего выбора');
        }

        $attacker = $this->state->getCard($pcs['attacker_id']);
        $target   = $this->state->getCard($pcs['target_id']);
        if (!$attacker || $attacker->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }
        if (!$target) {
            return Result::error('Цель не найдена');
        }

        $amount    = (int) $cmd->get('amount', -1);
        $maxAmount = (int) $pcs['max_coins'];
        $minAmount = (int) ($pcs['min_coins'] ?? 0);
        if ($amount < $minAmount || $amount > $maxAmount) {
            return Result::error('Неверное количество монет');
        }

        $action = $pcs['action'];
        $type   = $pcs['type'];
        $mode   = $pcs['mode'] ?? 'damage';        // ← КЛЮЧЕВАЯ СТРОКА

        unset($this->state->battle['pending_coin_spend']);

        if ($mode === 'shield') {
            $attacker->coins -= $amount;
            $this->engine->syncCoinBonus($attacker);
            return $this->applyShieldLight($attacker, $target, $action, $amount, $playerKey);
        }

        return $this->resolveDamage(
            $attacker, $target, $action, $type,
            $attacker->instanceId, $target->instanceId,
            $playerKey,
            $amount
        );
    }

    public function chooseSelfWound(string $playerKey, Command $cmd): Result
    {
        $psw = $this->state->battle['pending_self_wound'] ?? null;
        if (!$psw) {
            return Result::error('Нет ожидающего выбора');
        }

        $attacker = $this->state->getCard($psw['attacker_id']);
        $target   = $this->state->getCard($psw['target_id']);
        if (!$attacker || $attacker->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }
        if (!$target) {
            return Result::error('Цель не найдена');
        }

        $amount    = (int) $cmd->get('amount', -1);
        $maxWounds = (int) $psw['max_wounds'];
        if ($amount < 1 || $amount > $maxWounds) {
            return Result::error('Неверное количество ран');
        }

        unset($this->state->battle['pending_self_wound']);

        $action = $psw['action'];
        $attacker->closed = true;

        $damage   = 0;
        $diedSelf = false;

        $this->state->battle['strike'] = [
            'kind'        => 'self_wound',
            'action_name' => $action['name'] ?? 'Таран',
            'attacker_id' => $attacker->instanceId,
            'target_id'   => $target->instanceId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => $damage,
            'self_wound'  => [
                'amount'    => $amount,
                'damage'    => $damage,
                'died_self' => $diedSelf,
            ],
            'confirmed'   => [],
        ];

        $this->engine->applyDamage($this->state, $attacker, $amount, 'self_wound', $attacker);
        $diedSelf = $attacker->dying || $attacker->hp <= 0;

        if (!$diedSelf) {
            $damage = max(0, $amount - 1);
            if ($damage > 0) {
                $this->engine->applyDamage($this->state, $target, $damage, 'tap', $attacker);
            }
        }

        $this->state->battle['strike']['damage'] = $damage;
        $this->state->battle['strike']['self_wound']['damage'] = $damage;
        $this->state->battle['strike']['self_wound']['died_self'] = $diedSelf;

        $this->engine->checkGameOver($this->state);

        $this->state->bumpVersion();
        return Result::ok(["self_wound:{$amount}:dmg={$damage}"]);
    }

    private function startMultiHeal(
        CardInstance $attacker, array $action, int $cardId, string $playerKey
    ): Result {
        $filter     = $action['filter'] ?? '';
        $candidates = [];

        foreach ($this->state->cards as $c) {
            if ($c->owner !== $playerKey) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;

            if ($filter === 'own_forest' && $c->element !== 'forests') continue;

            $candidates[] = $c->instanceId;
        }

        if (empty($candidates)) {
            return Result::error('Нет подходящих целей');
        }

        $this->state->battle['pending_multi_heal'] = [
            'attacker_id' => $cardId,
            'action'      => $action,
            'candidates'  => $candidates,
            'max_targets' => (int) ($action['max_targets'] ?? 3),
            'value'       => (int) ($action['value'] ?? 1),
        ];

        $this->state->bumpVersion();
        return Result::ok(['multi_heal_started']);
    }

    /**
     * Общий разбор pending_multi_* и списка целей.
     *
     * @return array{attacker: CardInstance, targetIds: int[], config: array}|Result
     */
    private function parseMultiPick(string $playerKey, Command $cmd, string $pendingKey): array|Result
    {
        $pending = $this->state->battle[$pendingKey] ?? null;
        if (!$pending) return Result::error('Нет ожидающего выбора');

        $attacker = $this->state->getCard($pending['attacker_id']);
        if (!$attacker || $attacker->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $raw = $cmd->get('target_ids', []);
        if (!is_array($raw)) $raw = [$raw];
        $targetIds  = array_values(array_unique(array_map('intval', $raw)));
        $maxTargets = (int) $pending['max_targets'];

        if (empty($targetIds)) {
            return Result::error('Выберите хотя бы одну цель');
        }
        if (count($targetIds) > $maxTargets) {
            return Result::error('Слишком много целей (макс. ' . $maxTargets . ')');
        }

        foreach ($targetIds as $tid) {
            if (!in_array($tid, $pending['candidates'], true)) {
                return Result::error('Неверная цель');
            }
        }

        unset($this->state->battle[$pendingKey]);

        return [
            'attacker'  => $attacker,
            'targetIds' => $targetIds,
            'config'    => $pending,
        ];
    }

    public function chooseMultiHeal(string $playerKey, Command $cmd): Result
    {
        $parsed = $this->parseMultiPick($playerKey, $cmd, 'pending_multi_heal');
        if ($parsed instanceof Result) return $parsed;

        $attacker  = $parsed['attacker'];
        $targetIds = $parsed['targetIds'];
        $pmh       = $parsed['config'];

        $value  = (int) $pmh['value'];
        $healed = [];

        foreach ($targetIds as $tid) {
            $card = $this->state->getCard($tid);
            if (!$card) continue;

            $hpBefore = $card->hp;
            $card->hp += $value;
            if ($card->hp > $card->hpMax) $card->hp = $card->hpMax;

            $healed[] = [
                'target_id' => $tid,
                'heal'      => $card->hp - $hpBefore,
            ];
        }

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'multi_heal',
            'action_name' => $pmh['action']['name'] ?? 'Излечение',
            'attacker_id' => $attacker->instanceId,
            'target_id'   => $targetIds[0] ?? 0,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'healed'      => $healed,
            'confirmed'   => [],
        ];

        $this->state->bumpVersion();
        return Result::ok(['multi_heal_done']);
    }

    private function resolveStealStrike(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        if (!empty($attacker->flags['steal_weapon_used']) && !empty($action['once_per_battle'])) {
            return Result::error('Уже использовано в этом бою');
        }

        $cost = (int) ($action['coins'] ?? 0);
        if ($cost > 0) {
            if ($attacker->coins < $cost) {
                return Result::error('Не хватает монет');
            }
        }

        $target = $this->state->getCard($targetId);
        if (!$target) {
            return Result::error('Цель не найдена');
        }
        if ($target->owner === $playerKey) {
            return Result::error('Только на врага');
        }
        if ($target->zone !== CardInstance::ZONE_FIELD) {
            return Result::error('Цель не на поле');
        }

        $dr = abs($target->row - $attacker->row);
        $dc = abs($target->col - $attacker->col);
        if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) {
            return Result::error('Цель не соседняя');
        }

        if ($cost > 0) $attacker->coins -= $cost;

        $target->modifiers[] = [
            'stat'   => 'ability_strike',
            'value'  => -1,
            'expire' => 'permanent',
            'source' => $playerKey,
        ];
        $attacker->modifiers[] = [
            'stat'   => 'ability_strike',
            'value'  => 1,
            'expire' => 'permanent',
            'source' => $playerKey,
        ];

        $attacker->closed = true;
        $attacker->flags['steal_weapon_used'] = true;

        $this->state->battle['strike'] = [
            'kind'        => 'steal',
            'action_name' => $action['name'] ?? 'Украсть оружие',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'steal'       => [
                'target_id' => $targetId,
                'from'      => -1,
                'to'        => 1,
            ],
            'confirmed'   => [],
        ];

        $this->state->bumpVersion();
        return Result::ok(["steal_weapon:{$playerKey}:{$cardId}->{$targetId}"]);
    }

    private function resolveSandClaws(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');
        if ($target->owner === $playerKey) return Result::error('Только на врага');
        if ($target->zone !== CardInstance::ZONE_FIELD) {
            return Result::error('Цель не на поле');
        }

        $value = (int) ($action['value'] ?? 1);

        // Урон
        if ($value > 0) {
            $this->engine->applyDamage($this->state, $target, $value, 'tap', $attacker);
        }

        // Маркер: цели — песчаные когти, источника — owner атакующего
        if (!isset($target->markers['sand_claws'])) {
            $target->markers['sand_claws'] = [
                'value'  => 1,
                'source' => $playerKey,
                'expire' => 1,
                'timing' => 'source_turn',
            ];
        } else {
            $target->markers['sand_claws']['value']++;
            $target->markers['sand_claws']['expire'] = 1;
        }

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'sand_claws',
            'action_name' => $action['name'] ?? 'Песчаные когти',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => $value,
            'sand_claws'  => ['target_id' => $targetId, 'value' => 1],
            'confirmed'   => [],
        ];

        $this->engine->flushDeadeatQueue($this->state);
        $this->state->bumpVersion();
        return Result::ok(["sand_claws:{$playerKey}:{$cardId}->{$targetId}"]);
    }

    private function startMultiDischarge(
        CardInstance $attacker, array $action, int $cardId, string $playerKey
    ): Result {
        $candidates = [];

        foreach ($this->state->cards as $c) {
            if ($c->owner === $playerKey) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;

            $candidates[] = $c->instanceId;
        }

        if (empty($candidates)) {
            return Result::error('Нет подходящих целей');
        }

        $this->state->battle['pending_multi_discharge'] = [
            'attacker_id' => $cardId,
            'action'      => $action,
            'candidates'  => $candidates,
            'max_targets' => (int) ($action['max_targets'] ?? 3),
            'value'       => (int) ($action['value'] ?? 1),
        ];

        $this->state->bumpVersion();
        return Result::ok(['multi_discharge_started']);
    }

    public function chooseMultiDischarge(string $playerKey, Command $cmd): Result
    {
        $parsed = $this->parseMultiPick($playerKey, $cmd, 'pending_multi_discharge');
        if ($parsed instanceof Result) return $parsed;

        $attacker  = $parsed['attacker'];
        $targetIds = $parsed['targetIds'];
        $pmd       = $parsed['config'];

        $value   = (int) $pmd['value'];
        $results = [];

        foreach ($targetIds as $tid) {
            $target = $this->state->getCard($tid);
            if (!$target) continue;

            $hpBefore = $target->hp;

            if (CardStats::hasDefense($this->state, $target, 'discharge', $attacker)) {
                $results[] = ['target_id' => $tid, 'damage' => 0, 'defended' => true];
                continue;
            }

            $this->engine->applyDamage($this->state, $target, $value, 'discharge', $attacker);

            $results[] = [
                'target_id' => $tid,
                'damage'    => max(0, $hpBefore - $target->hp),
                'defended'  => false,
            ];
        }

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'multi_discharge',
            'action_name' => $pmd['action']['name'] ?? 'Тройной разряд',
            'attacker_id' => $attacker->instanceId,
            'target_id'   => $targetIds[0] ?? 0,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'discharged'  => $results,
            'confirmed'   => [],
        ];

        $this->engine->flushDeadeatQueue($this->state);
        $this->state->bumpVersion();
        return Result::ok(['multi_discharge_done']);
    }

    public function cancelPending(string $playerKey, Command $cmd): Result
    {
        $state = $this->state;

        if (!empty($state->battle['pending_forced_strike'])) {
            return Result::error('Обязательная атака — отмена невозможна');
        }

        $cancelled = [];

        // Простые: unset если owner === playerKey
        // [pendingKey, ownerField]
        $simple = [
            ['pending_whip',                    'owner'],
            ['pending_kobold_heal',             'owner'],
            ['pending_valhalla_pick',           'owner'],
            ['pending_instant_pick',            'owner'],
            ['pending_forced_strike_adjacent',  'owner'],
            ['pending_cell_marker_pick',        'owner'],
            ['pending_dice_choice',             'owner'],
            ['pending_combat_pick',             'owner'],
            ['pending_forced_directional_move', 'owner'],
            ['pending_row_pick',                'owner'],
            ['pending_opponent_row_marker',     'owner'],
            ['pending_gate_pick',               'owner'],
            ['pending_destroy_self_and_target',  'owner'],
            ['pending_particle_pick',           'owner'],
            ['pending_life_gift',               'owner'],
            ['pending_nokami_wound',            'owner'],
            ['pending_teleport_target',         'owner'], 
        ];
        foreach ($simple as [$key, $field]) {
            $p = $state->battle[$key] ?? null;
            if (!$p) continue;
            if (($p[$field] ?? null) !== $playerKey) continue;
            unset($state->battle[$key]);
            $cancelled[] = substr($key, strlen('pending_'));
        }

        // Card-owned: unset если карта игрока
        // [pendingKey, cardIdField]
        $cardOwned = [
            ['pending_multi_heal',      'attacker_id'],
            ['pending_multi_discharge', 'attacker_id'],
            ['pending_coin_spend',      'attacker_id'],
            ['pending_blood_tap',       'attacker_id'],
        ];
        foreach ($cardOwned as [$key, $field]) {
            $p = $state->battle[$key] ?? null;
            if (!$p) continue;
            $card = $state->getCard((int) ($p[$field] ?? 0));
            if (!$card || $card->owner !== $playerKey) continue;
            unset($state->battle[$key]);
            $cancelled[] = substr($key, strlen('pending_'));
        }

        // Спец: возврат монет
        if ($this->cancelWithRefund($state, $playerKey, 'pending_self_wound', 'attacker_id',
                fn($p) => (int) ($p['action']['coins'] ?? 0))) {
            $cancelled[] = 'self_wound';
        }
        if ($this->cancelWithRefund($state, $playerKey, 'pending_revive', 'healer_id',
                fn($p) => (int) ($p['cost'] ?? 0))) {
            $cancelled[] = 'revive';
        }
        if ($this->cancelWithRefund($state, $playerKey, 'pending_dive', 'attacker_id',
                fn($p) => (int) ($p['action']['coins'] ?? 0))) {
            $cancelled[] = 'dive';
        }

        // Спец: wound_transfer — возврат монет + reopen источника
        if (!empty($state->battle['pending_wound_transfer'])) {
            $p = $state->battle['pending_wound_transfer'];
            if (($p['owner'] ?? null) === $playerKey) {
                $cost = (int) ($p['options']['coins_cost'] ?? 0);
                $src = $state->getCard((int) ($p['source_id'] ?? 0));
                if ($src) {
                    if ($cost > 0) {
                        $src->coins += $cost;
                        $this->engine->syncCoinBonus($src);
                    }
                    $src->closed = false;
                    unset($src->flags['in_stack']);
                }
                unset($state->battle['pending_wound_transfer']);
                $cancelled[] = 'wound_transfer';
            }
        }

        if (empty($cancelled)) {
            return Result::error('Нечего отменять');
        }

        if (!empty($state->battle['turn_phase'])) {
            (new TurnPhaseProcessor($state, $this->engine))->resume();
        }

        $state->bumpVersion();
        return Result::ok(['cancelled:' . implode(',', $cancelled)]);
    }

    private function cancelWithRefund(
        GameState $state,
        string $playerKey,
        string $key,
        string $cardField,
        callable $costFn
    ): bool {
        $p = $state->battle[$key] ?? null;
        if (!$p) return false;

        $card = $state->getCard((int) ($p[$cardField] ?? 0));
        if (!$card || $card->owner !== $playerKey) return false;

        $cost = $costFn($p);
        if ($cost > 0) {
            $card->coins += $cost;
            $this->engine->syncCoinBonus($card);
        }
        unset($state->battle[$key]);
        return true;
    }

    public function openSuccessfulHitOptionalHeal(CardInstance $card): bool
    {
        if (empty($card->prop['on_successful_hit'])) return false;
        if ($card->zone !== CardInstance::ZONE_FIELD) return false;
        if ($card->dying || $card->hp <= 0) return false;
        if ($card->hp >= $card->hpMax) return false;

        $config = $card->prop['on_successful_hit'];
        if (!is_array($config) || ($config['type'] ?? '') !== 'optional_heal') return false;
        if (($config['value_from'] ?? '') !== 'opposite_creature_strike_medium') return false;

        $opposite = CardStats::getOppositeFieldCard($this->state, $card);
        if (!$opposite || $opposite->dying || $opposite->hp <= 0) return false;

        $value = CardStats::getStrikeValue($this->state, $opposite, $card, 'medium');
        if ($value <= 0) return false;

        $this->state->battle['pending_kobold_heal'] = [
            'owner' => $card->owner,
            'card_id' => $card->instanceId,
            'opposite_id' => $opposite->instanceId,
            'value' => $value,
        ];

        return true;
    }

    public function chooseKoboldHeal(string $playerKey, Command $cmd): Result
    {
        $pending = $this->state->battle['pending_kobold_heal'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) {
            return Result::error('Нет выбора излечения');
        }

        $card = $this->state->getCard((int) ($pending['card_id'] ?? 0));
        if (!$card || $card->owner !== $playerKey || $card->hp <= 0 || $card->dying) {
            unset($this->state->battle['pending_kobold_heal']);
            return Result::error('Карта недоступна');
        }

        $value = (int) ($pending['value'] ?? 0);
        if ($value <= 0 || $card->hp >= $card->hpMax) {
            unset($this->state->battle['pending_kobold_heal']);
            return Result::error('Излечение недоступно');
        }

        $hpBefore = $card->hp;
        $card->hp = min($card->hpMax, $card->hp + $value);
        $healed = max(0, $card->hp - $hpBefore);

        unset($this->state->battle['pending_kobold_heal']);

        if (!empty($this->state->battle['strike'])) {
            $this->state->battle['strike']['kobold_heal'][] = [
                'card_id' => $card->instanceId,
                'value' => $value,
                'heal' => $healed,
            ];
        }

        $this->state->bumpVersion();
        return Result::ok(["kobold_heal:{$card->instanceId}:{$healed}"]);
    }

    public function triggerTalionIncarnationToken(CardInstance $source): bool
    {
        $config = $source->prop['on_strong_strike'] ?? null;
        if (!is_array($config) || ($config['type'] ?? '') !== 'incarnation_token_graveyard') {
            return false;
        }

        $candidateIds = $this->talionIncarnationCandidates($source->owner);
        if (empty($candidateIds)) return false;

        if (count($candidateIds) === 1) {
            $target = $this->state->getCard($candidateIds[0]);
            if (!$target) return false;
            $this->addIncarnationToken($target);
            $this->recordTalionIncarnationToken($source, $target);
            return true;
        }

        $this->state->battle['pending_talion_incarnation'] = [
            'owner' => $source->owner,
            'source_id' => $source->instanceId,
            'candidate_ids' => $candidateIds,
        ];

        return true;
    }

    public function chooseTalionIncarnation(string $playerKey, Command $cmd): Result
    {
        $pending = $this->state->battle['pending_talion_incarnation'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) {
            return Result::error('Нет выбора жетона инкарнации');
        }

        $source = $this->state->getCard((int) ($pending['source_id'] ?? 0));
        if (!$source || $source->owner !== $playerKey) {
            unset($this->state->battle['pending_talion_incarnation']);
            return Result::error('Источник жетона недоступен');
        }

        $targetId = (int) $cmd->get('target_id', 0);
        $candidateIds = array_map('intval', (array) ($pending['candidate_ids'] ?? []));
        if (!in_array($targetId, $candidateIds, true)) {
            return Result::error('Нельзя выбрать эту карту');
        }

        $target = $this->state->getCard($targetId);
        if (!$target || !$this->isTalionIncarnationCandidate($target, $playerKey)) {
            unset($this->state->battle['pending_talion_incarnation']);
            return Result::error('Цель больше недоступна');
        }

        unset($this->state->battle['pending_talion_incarnation']);
        $this->addIncarnationToken($target);
        $this->recordTalionIncarnationToken($source, $target);

        $this->state->bumpVersion();
        return Result::ok(["talion_incarnation:{$source->instanceId}:{$target->instanceId}"]);
    }

    private function talionIncarnationCandidates(string $owner): array
    {
        $ids = [];
        foreach ($this->state->cards as $card) {
            if ($this->isTalionIncarnationCandidate($card, $owner)) {
                $ids[] = $card->instanceId;
            }
        }

        return $ids;
    }

    private function isTalionIncarnationCandidate(CardInstance $card, string $owner): bool
    {
        if ($card->owner !== $owner) return false;
        if ($card->zone !== CardInstance::ZONE_GRAVEYARD) return false;
        return $card->type === 'creature' || $card->type === 'fly';
    }

    private function addIncarnationToken(CardInstance $card): void
    {
        if (!isset($card->markers['incarnation'])) {
            $inc = $card->prop['incarnation'] ?? null;
            $threshold = 0;
            $open = false;

            if (is_array($inc)) {
                $threshold = (int) ($inc['turns'] ?? 0);
                $open = !empty($inc['open']);
            } elseif ($inc !== null) {
                $threshold = (int) $inc;
            }

            $card->markers['incarnation'] = [
                'value' => 0,
                'threshold' => $threshold,
                'open' => $open,
            ];
        }

        $card->markers['incarnation']['value'] =
            (int) ($card->markers['incarnation']['value'] ?? 0) + 1;
    }

    private function recordTalionIncarnationToken(CardInstance $source, CardInstance $target): void
    {
        if (empty($this->state->battle['strike'])) return;

        $this->state->battle['strike']['talion_incarnation_token'][] = [
            'source_id' => $source->instanceId,
            'target_id' => $target->instanceId,
            'value' => (int) ($target->markers['incarnation']['value'] ?? 0),
            'threshold' => (int) ($target->markers['incarnation']['threshold'] ?? 0),
            'has_incarnation' => array_key_exists('incarnation', $target->prop),
        ];
    }

    public function triggerLineOpenOnStrike(CardInstance $source): bool
    {
        $config = $source->prop['on_successful_strike'] ?? null;
        if (!is_array($config) || ($config['type'] ?? '') !== 'open_line_ally_cannot_attack') {
            return false;
        }
        if (!CardStats::isInLine($this->state, $source)) return false;

        $key = (string) ($config['key'] ?? 'open_line_ally_cannot_attack');
        $limit = (int) ($config['uses_per_turn'] ?? 1);
        $flag = 'trigger_used_this_turn:' . $key;
        if ($limit > 0 && (int) ($source->flags[$flag] ?? 0) >= $limit) {
            return false;
        }

        $candidateIds = [];
        foreach (CardStats::getLineGroup($this->state, $source) as $card) {
            if ($card->instanceId === $source->instanceId) continue;
            if ($card->dying || $card->hp <= 0) continue;
            if (!$card->closed) continue;
            $candidateIds[] = $card->instanceId;
        }

        if (empty($candidateIds)) return false;

        if (count($candidateIds) === 1) {
            $target = $this->state->getCard($candidateIds[0]);
            if (!$target) return false;
            $this->resolveLineOpenOnStrike($source, $target, $flag);
            return true;
        }

        $this->state->battle['pending_holvert_open'] = [
            'owner' => $source->owner,
            'source_id' => $source->instanceId,
            'candidate_ids' => $candidateIds,
            'flag' => $flag,
        ];

        return true;
    }

    public function chooseHolvertOpen(string $playerKey, Command $cmd): Result
    {
        $pending = $this->state->battle['pending_holvert_open'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) {
            return Result::error('Нет выбора открытия');
        }

        $source = $this->state->getCard((int) ($pending['source_id'] ?? 0));
        if (!$source || $source->owner !== $playerKey) {
            unset($this->state->battle['pending_holvert_open']);
            return Result::error('Источник недоступен');
        }

        $targetId = (int) $cmd->get('target_id', 0);
        $candidateIds = array_map('intval', (array) ($pending['candidate_ids'] ?? []));
        if (!in_array($targetId, $candidateIds, true)) {
            return Result::error('Нельзя выбрать эту карту');
        }

        $target = $this->state->getCard($targetId);
        if (!$target
            || $target->instanceId === $source->instanceId
            || !$target->closed
            || !in_array($target, CardStats::getLineGroup($this->state, $source), true)) {
            unset($this->state->battle['pending_holvert_open']);
            return Result::error('Цель больше недоступна');
        }

        unset($this->state->battle['pending_holvert_open']);
        $this->resolveLineOpenOnStrike($source, $target, (string) ($pending['flag'] ?? 'trigger_used_this_turn:open_line_ally_cannot_attack'));

        $this->state->bumpVersion();
        return Result::ok(["holvert_open:{$source->instanceId}:{$target->instanceId}"]);
    }

    private function resolveLineOpenOnStrike(CardInstance $source, CardInstance $target, string $flag): void
    {
        $this->engine->openCard($target);
        $target->modifiers[] = [
            'stat' => 'cannot_attack',
            'value' => 1,
            'expire' => 'end_of_turn',
            'source' => $source->owner,
        ];
        $source->flags[$flag] = ((int) ($source->flags[$flag] ?? 0)) + 1;

        if (!empty($this->state->battle['strike'])) {
            $this->state->battle['strike']['holvert_open'][] = [
                'source_id' => $source->instanceId,
                'target_id' => $target->instanceId,
            ];
        }
    }

    private function startRevive(
        CardInstance $attacker, array $action, int $cardId, string $playerKey
    ): Result {
        if (!empty($attacker->flags['revive_used']) && !empty($action['once_per_battle'])) {
            return Result::error('Уже использовано в этом бою');
        }

        $cost = (int) ($action['coins'] ?? 0);
        if ($cost > 0) {
            if ($attacker->coins < $cost) {
                return Result::error('Не хватает монет');
            }
            $attacker->coins -= $cost;
        }

        // Кандидаты — свои существа с кладбища
        $candidates = [];
        foreach ($this->state->cards as $c) {
            if ($c->owner !== $playerKey) continue;
            if ($c->zone !== CardInstance::ZONE_GRAVEYARD) continue;
            $candidates[] = $c->instanceId;
        }

        if (empty($candidates)) {
            // Возврат монет
            $attacker->coins += $cost;
            return Result::error('На кладбище нет существ');
        }

        $this->state->battle['pending_revive'] = [
            'healer_id'  => $cardId,
            'action'     => $action,
            'cost'       => $cost,
            'candidates' => $candidates,
            'chosen_id'  => null,
            'step'       => 'card',
        ];

        $this->state->bumpVersion();
        return Result::ok(['revive_started']);
    }

    public function chooseReviveTarget(string $playerKey, Command $cmd): Result
    {
        $pr = $this->state->battle['pending_revive'] ?? null;
        if (!$pr || ($pr['step'] ?? '') !== 'card') {
            return Result::error('Нет ожидающего выбора');
        }

        $healer = $this->state->getCard($pr['healer_id']);
        if (!$healer || $healer->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $targetId = (int) $cmd->get('target_id', 0);
        if (!in_array($targetId, $pr['candidates'], true)) {
            return Result::error('Неверная цель');
        }

        $card = $this->state->getCard($targetId);
        if (!$card) return Result::error('Карта не найдена');

        $pr['chosen_id'] = $targetId;

        // Флай — сразу в FLYING-зону, без выбора клетки
        if ($card->type === 'fly') {
            $this->reviveFlyer($healer, $card, $pr);
            unset($this->state->battle['pending_revive']);
            $this->state->bumpVersion();
            return Result::ok(["revived:{$card->instanceId}:flyer"]);
        }

        // Обычное — переходим к выбору клетки
        $pr['step'] = 'cell';
        $this->state->battle['pending_revive'] = $pr;
        $this->state->bumpVersion();
        return Result::ok(['revive_choose_cell']);
    }

    public function chooseReviveCell(string $playerKey, Command $cmd): Result
    {
        $pr = $this->state->battle['pending_revive'] ?? null;
        if (!$pr || ($pr['step'] ?? '') !== 'cell') {
            return Result::error('Нет ожидающего выбора');
        }

        $healer = $this->state->getCard($pr['healer_id']);
        if (!$healer || $healer->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $card = $this->state->getCard($pr['chosen_id']);
        if (!$card) return Result::error('Карта не найдена');

        $row = (int) $cmd->get('row', 0);
        $col = (int) $cmd->get('col', 0);

        $dr = abs($row - $healer->row);
        $dc = abs($col - $healer->col);
        if ($dr > 1 || $dc > 1 || ($dr === 0 && $dc === 0)) {
            return Result::error('Только соседняя клетка');
        }

        $zone = new ZoneManager($this->state);
        if ($zone->isFieldOccupied($row, $col)) {
            return Result::error('Клетка занята');
        }

        $zone->toField($card, $row, $col);

        // Базовые значения
        $card->hp        = $card->hpMax;
        $card->move      = $card->moveMax;
        $card->armor     = 0;
        $card->armorMax  = 0;
        $card->coins     = 0;
        $card->closed    = true;    // в закрытом виде
        $card->dying     = false;
        $card->revealed  = true;
        $card->markers   = [];      // сбрасываем incarnation и всё остальное
        $card->modifiers = [];
        $card->flags     = [];      // moved_this_turn и т.д. — с нуля
                                    // flags.incarnated НЕ ставим — это не инкарнация

        $healer->closed = true;
        $healer->flags['revive_used'] = true;

        $this->engine->refreshArmor($this->state);
        $this->engine->checkGameOver($this->state);

        unset($this->state->battle['pending_revive']);

        $this->state->bumpVersion();
        return Result::ok(["revived:{$card->instanceId}:{$row}_{$col}"]);
    }

    private function reviveFlyer(CardInstance $healer, CardInstance $card, array $pr): void
    {
        $zone = new ZoneManager($this->state);
        $zone->toFlying($card);

        $card->hp        = $card->hpMax;
        $card->move      = $card->moveMax;
        $card->armor     = 0;
        $card->armorMax  = 0;
        $card->coins     = 0;
        $card->closed    = true;
        $card->dying     = false;
        $card->revealed  = true;
        $card->markers   = [];
        $card->modifiers = [];
        $card->flags     = [];

        $healer->closed = true;
        $healer->flags['revive_used'] = true;

        $this->engine->refreshArmor($this->state);
    }

    private function startGrezyProphecy(
        CardInstance $attacker, array $action, int $cardId, string $playerKey
    ): Result {
        $count = (int) ($action['count'] ?? 1);

        $pp     = new ProphecyProcessor($this->state, $this->engine);
        $peeked = $pp->peek($playerKey, $count);
        if ($peeked === null) {
            return Result::error('Колода пуста');
        }

        $price = (int) $peeked['meta']['first_price'];

        $attacker->closed = true;

        $title   = 'Пророчество 1 — стоимость ' . $price;
        $actions = [['label' => 'Продолжить', 'cmd' => 'grezy_continue']];

        $pp->commit($playerKey, $attacker, $peeked, 'grezy', $title, $actions);

        $this->state->bumpVersion();
        return Result::ok(['grezy_prophecy_started']);
    }

    public function grezyContinue(string $playerKey, Command $cmd): Result
    {
        $pp = $this->state->battle['pending_prophecy'] ?? null;
        if (!$pp || ($pp['context'] ?? '') !== 'grezy') {
            return Result::error('Нет ожидающего пророчества');
        }
        if ($pp['owner'] !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $price    = (int) ($pp['meta']['price'] ?? 0);
        $attacker = $this->state->getCard($pp['source_id']);
        if (!$attacker) return Result::error('Карта не найдена');

        // Враги ценой X
        $enemies = [];
        foreach ($this->state->cards as $c) {
            if ($c->owner === $attacker->owner) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;
            if ((int) $c->price !== $price) continue;
            $enemies[] = $c->instanceId;
        }

        // Свои ценой X
        $allies = [];
        foreach ($this->state->cards as $c) {
            if ($c->owner !== $attacker->owner) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;
            if ((int) $c->price !== $price) continue;
            $allies[] = $c->instanceId;
        }

        unset($this->state->battle['pending_prophecy']);

        if (empty($enemies) && empty($allies)) {
            $this->state->bumpVersion();
            return Result::ok(['grezy_no_targets']);
        }

        $this->state->battle['pending_grezy'] = [
            'attacker_id'  => $attacker->instanceId,
            'shown_ukid'   => $pp['shown_ukids'][0],
            'price'        => $price,
            'enemies'      => $enemies,
            'allies'       => $allies,
            'chosen_enemy' => null,
            'chosen_own'   => null,
        ];

        $this->state->bumpVersion();
        return Result::ok(['grezy_choice_started']);
    }

    public function grezyPick(string $playerKey, Command $cmd): Result
    {
        $pg = $this->state->battle['pending_grezy'] ?? null;
        if (!$pg) return Result::error('Нет ожидающего выбора');

        $attacker = $this->state->getCard($pg['attacker_id']);
        if (!$attacker || $attacker->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $enemyId = (int) $cmd->get('enemy_id', 0);
        $ownId   = (int) $cmd->get('own_id', 0);

        // Валидация врага
        if (!empty($pg['enemies'])) {
            if ($enemyId <= 0 || !in_array($enemyId, $pg['enemies'], true)) {
                return Result::error('Выбери врага');
            }
        } else {
            $enemyId = 0;
        }

        // Валидация союзника
        if (!empty($pg['allies'])) {
            if ($ownId <= 0 || !in_array($ownId, $pg['allies'], true)) {
                return Result::error('Выбери своё существо');
            }
        } else {
            $ownId = 0;
        }

        $pg['chosen_enemy'] = $enemyId ?: null;
        $pg['chosen_own']   = $ownId ?: null;

        return $this->applyGrezy($pg);
    }

    private function applyGrezy(array $pg): Result
    {
        $attacker = $this->state->getCard($pg['attacker_id']);
        unset($this->state->battle['pending_grezy']);

        $log = [];

        if (!empty($pg['chosen_enemy'])) {
            $enemy = $this->state->getCard($pg['chosen_enemy']);
            if ($enemy) {
                $this->engine->applyPoison($enemy, 1, $attacker->owner);
                $log[] = 'poison:' . $enemy->instanceId;
            }
        }

        if (!empty($pg['chosen_own'])) {
            $ally = $this->state->getCard($pg['chosen_own']);
            if ($ally) {
                $ally->modifiers[] = [
                    'stat'   => 'damage_reduction',
                    'value'  => 1,
                    'types'  => ['strike', 'shot', 'throw', 'tap', 'uchr', 'execute', 'impact'],
                    'expire' => 'end_of_opponent_turn',
                    'source' => $attacker->owner,
                ];
                $log[] = 'shield:' . $ally->instanceId;
            }
        }

        $this->state->bumpVersion();
        return Result::ok(array_merge(['grezy_applied'], $log));
    }

    public function chooseWhipTarget(string $playerKey, Command $cmd): Result
    {
        $pw = $this->state->battle['pending_whip'] ?? null;
        if (!$pw) return Result::error('Нет ожидающего выбора');
        if ($pw['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $source = $this->state->getCard($pw['source_id']);
        if (!$source) return Result::error('Источник не найден');

        $targetId = (int) $cmd->get('target_id', 0);
        if (!in_array($targetId, $pw['targets'], true)) {
            return Result::error('Неверная цель');
        }

        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');

        $value = (int) $pw['value'];

        $this->engine->applyDamage($this->state, $target, $value, 'whip', $source);
        $died = $target->dying || $target->hp <= 0;
        if (!$died) {
            // Не погиб — +1 move до конца хода
            $target->modifiers[] = [
                'stat'   => 'move',
                'value'  => 1,
                'expire' => 'end_of_turn',
                'source' => $playerKey,
            ];
            // Применяем немедленно — текущий move ещё не пересчитан
            $target->move += 1;
        }

        $sourceName = $target->ukid;
        $targetName = $target->ukid;

        unset($this->state->battle['pending_whip']);

        // Продолжаем фазу
        if (!empty($this->state->battle['turn_phase'])) {
            (new TurnPhaseProcessor($this->state, $this->engine))->resume();
        }

        $this->state->bumpVersion();
        return Result::ok([
            "whip:{$target->instanceId}:" . ($died ? 'died' : 'move+1'),
        ]);
    }

    private function startDestroySelfAndTarget(
        CardInstance $attacker, array $action, int $cardId, string $playerKey
    ): Result {
        $cost = (int) ($action['coins'] ?? 0);
        if ($cost > 0 && $attacker->coins < $cost) {
            return Result::error('Не хватает монет');
        }

        $targets = $this->getDestroySelfAndTargetCandidates($attacker);
        if (empty($targets)) {
            return Result::error('Нет доступных целей');
        }

        $this->state->battle['pending_destroy_self_and_target'] = [
            'owner'       => $playerKey,
            'source_id'   => $cardId,
            'action'      => $action,
            'target_ids'  => $targets,
            'default_side' => 'enemy',
        ];

        $this->state->bumpVersion();
        return Result::ok(["destroy_self_and_target_started:{$cardId}"]);
    }

    public function chooseDestroySelfAndTarget(string $playerKey, Command $cmd): Result
    {
        $pending = $this->state->battle['pending_destroy_self_and_target'] ?? null;
        if (!$pending) return Result::error('Нет ожидающего выбора');
        if (($pending['owner'] ?? null) !== $playerKey) return Result::error('Не ваш выбор');

        $sourceId = (int) ($pending['source_id'] ?? 0);
        $source = $this->state->getCard($sourceId);
        if (!$source || $source->owner !== $playerKey) {
            unset($this->state->battle['pending_destroy_self_and_target']);
            return Result::error('Источник недоступен');
        }
        if ($source->closed) {
            unset($this->state->battle['pending_destroy_self_and_target']);
            return Result::error('Карта закрыта');
        }
        if ($source->zone !== CardInstance::ZONE_FIELD
            && $source->zone !== CardInstance::ZONE_FLYING) {
            unset($this->state->battle['pending_destroy_self_and_target']);
            return Result::error('Источник не на поле');
        }

        $action = is_array($pending['action'] ?? null) ? $pending['action'] : [];
        $cost = (int) ($action['coins'] ?? 0);
        if ($cost > 0 && $source->coins < $cost) {
            unset($this->state->battle['pending_destroy_self_and_target']);
            return Result::error('Не хватает монет');
        }

        $targetId = (int) $cmd->get('target_id', 0);
        if ($targetId === $sourceId) {
            return Result::error('Нельзя выбрать источник');
        }

        $target = $this->state->getCard($targetId);
        if (!$this->isDestroySelfAndTargetCandidate($source, $target)) {
            return Result::error('Неверная цель');
        }

        $sourceName = $source->ukid;
        $targetName = $target->ukid;

        unset($this->state->battle['pending_destroy_self_and_target']);

        if ($cost > 0) {
            $source->coins -= $cost;
            if ($source->coins < 0) $source->coins = 0;
            $this->engine->syncCoinBonus($source);
        }
        $source->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'destroy_self_and_target',
            'action_name' => $action['name'] ?? 'Последний путь',
            'attacker_id' => $sourceId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'destroyed'   => [
                'source_id'   => $sourceId,
                'source_ukid' => $source->ukid,
                'source_name' => $sourceName,
                'target_id'   => $targetId,
                'target_ukid' => $target->ukid,
                'target_name' => $targetName,
            ],
            'confirmed'   => [],
        ];

        $this->engine->forceDeath($this->state, $source, 'destroy', $source);
        $targetAfterSourceDeath = $this->state->getCard($targetId);
        if ($targetAfterSourceDeath && !$targetAfterSourceDeath->dying) {
            $this->engine->forceDeath($this->state, $targetAfterSourceDeath, 'destroy', $source);
        }

        $this->engine->flushDeadeatQueue($this->state);
        $this->engine->finalizeDying($this->state);

        $this->state->bumpVersion();
        return Result::ok(["destroy_self_and_target:{$sourceId}:{$targetId}"]);
    }

    /** @return int[] */
    private function getDestroySelfAndTargetCandidates(CardInstance $source): array
    {
        $ids = [];
        foreach ($this->state->cards as $card) {
            if ($this->isDestroySelfAndTargetCandidate($source, $card)) {
                $ids[] = $card->instanceId;
            }
        }
        return $ids;
    }

    private function isDestroySelfAndTargetCandidate(CardInstance $source, ?CardInstance $target): bool
    {
        if (!$target) return false;
        if ($target->instanceId === $source->instanceId) return false;
        if ($target->zone !== CardInstance::ZONE_FIELD
            && $target->zone !== CardInstance::ZONE_FLYING) {
            return false;
        }
        if ($target->dying || $target->hp <= 0) return false;
        return $target->type === 'creature' || $target->type === 'fly';
    }

    private function startBloodTap(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');
        if ($target->owner === $playerKey) return Result::error('Только на врага');
        if ($target->zone !== CardInstance::ZONE_FIELD
            && $target->zone !== CardInstance::ZONE_FLYING) {
            return Result::error('Цель не на поле');
        }

        $base      = (int) ($action['base_hp'] ?? 8);
        $maxExtra  = (int) ($action['max_extra'] ?? 4);
        $extras    = $attacker->hp - $base;

        if ($extras <= 0) {
            return Result::error('Нет дополнительных жизней');
        }

        $maxX = min($maxExtra, $extras);

        $this->state->battle['pending_blood_tap'] = [
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'max_x'       => $maxX,
        ];

        $this->state->bumpVersion();
        return Result::ok(['blood_tap_started']);
    }

    public function chooseBloodTap(string $playerKey, Command $cmd): Result
    {
        $pb = $this->state->battle['pending_blood_tap'] ?? null;
        if (!$pb) return Result::error('Нет ожидающего выбора');

        $attacker = $this->state->getCard($pb['attacker_id']);
        $target   = $this->state->getCard($pb['target_id']);
        if (!$attacker || $attacker->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }
        if (!$target) return Result::error('Цель не найдена');

        $x = (int) $cmd->get('amount', -1);
        if ($x < 1 || $x > (int) $pb['max_x']) {
            return Result::error('Неверное количество');
        }

        unset($this->state->battle['pending_blood_tap']);

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'blood_tap',
            'action_name' => 'Кровавый разряд',
            'attacker_id' => $attacker->instanceId,
            'target_id'   => $target->instanceId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => $x,
            'blood_tap'   => ['x' => $x, 'target_id' => $target->instanceId],
            'confirmed'   => [],
        ];

        $this->engine->applyDamage($this->state, $attacker, $x, 'blood_tap', $attacker);

        if (!$attacker->dying) {
            $this->engine->applyDamage($this->state, $target, $x, 'discharge', $attacker);
        }

        $this->engine->checkGameOver($this->state);
        $this->engine->flushDeadeatQueue($this->state);

        $this->state->bumpVersion();
        return Result::ok(["blood_tap:{$x}"]);
    }

    private function resolvePoisonBoost(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');
        if (empty($target->markers['poison'])) {
            return Result::error('Цель должна быть отравлена');
        }

        $value = (int) ($action['value'] ?? 1);
        $cap   = (int) ($action['cap'] ?? 2);
        $current = (int) $target->markers['poison']['value'];

        if ($current >= $cap) {
            return Result::error('Отравление уже максимально (' . $cap . ')');
        }

        $new = min($cap, $current + $value);
        $delta = $new - $current;
        $target->markers['poison']['value'] = $new;

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'poison_boost',
            'action_name' => $action['name'] ?? 'Вскипающий яд',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'poison_boost' => [
                'target_id' => $targetId,
                'from'      => $current,
                'to'        => $new,
                'delta'     => $delta,
            ],
            'confirmed'   => [],
        ];

        $this->state->bumpVersion();
        return Result::ok(["poison_boost:{$targetId}:{$current}->{$new}"]);
    }

    private function resolvePoisonTarget(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');
        if ($target->zone !== CardInstance::ZONE_FIELD
            && $target->zone !== CardInstance::ZONE_FLYING) {
            return Result::error('Цель не на поле');
        }

        $value = (int) ($action['value'] ?? 1);
        $this->engine->applyPoison($target, $value, $playerKey);

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'poison_target',
            'action_name' => $action['name'] ?? 'Отравление',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'poison_applied' => ['target_id' => $targetId, 'value' => $value],
            'confirmed'   => [],
        ];

        $this->state->bumpVersion();
        return Result::ok(["poison_target:{$targetId}:{$value}"]);
    }

    private function resolveDamagePoisoned(
        CardInstance $attacker, array $action,
        int $cardId, string $playerKey
    ): Result {
        $value  = (int) ($action['value'] ?? 1);
        $filter = (string) ($action['filter'] ?? 'enemy');

        $affected = [];
        foreach ($this->state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;
            if (empty($c->markers['poison'])) continue;

            if ($filter === 'enemy' && $c->owner === $playerKey) continue;
            if ($filter === 'own'   && $c->owner !== $playerKey) continue;

            $hpBefore = $c->hp;
            $this->engine->applyDamage($this->state, $c, $value, 'discharge', $attacker);
            $affected[] = [
                'target_id' => $c->instanceId,
                'damage'    => max(0, $hpBefore - $c->hp),
            ];
        }

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'damage_poisoned',
            'action_name' => $action['name'] ?? 'Власть Ундины',
            'attacker_id' => $cardId,
            'target_id'   => $affected[0]['target_id'] ?? 0,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'poisoned_damage' => $affected,
            'confirmed'   => [],
        ];

        $this->engine->flushDeadeatQueue($this->state);
        $this->engine->checkGameOver($this->state);
        $this->state->bumpVersion();
        return Result::ok(['damage_poisoned:' . count($affected)]);
    }

    private function startPlaceCellMarker(
        CardInstance $attacker, array $action, int $cardId, string $playerKey
    ): Result {
        if ($attacker->closed) {
            return Result::error('Карта закрыта');
        }

        $zone = new ZoneManager($this->state);
        $candidates = [];

        for ($row = 1; $row <= 6; $row++) {
            for ($col = 1; $col <= 5; $col++) {
                if ($zone->isFieldOccupied($row, $col)) continue;
                if ($zone->isCellMarked($row, $col)) continue;
                $candidates[] = "{$row}_{$col}";
            }
        }

        if (empty($candidates)) {
            return Result::error('Нет свободных клеток');
        }

        $this->state->battle['pending_cell_marker_pick'] = [
            'owner'    => $playerKey,
            'card_id'  => $cardId,
            'marker'   => (string) ($action['marker'] ?? 'marker'),
            'duration' => (string) ($action['duration'] ?? 'end_of_opponent_turn'),
            'label'    => (string) ($action['name'] ?? 'Маркер'),
            'cells'    => $candidates,
        ];

        $this->state->bumpVersion();
        return Result::ok(['cell_marker_started']);
    }

    public function chooseGate(string $playerKey, Command $cmd): Result
    {
        $p = $this->state->battle['pending_gate_pick'] ?? null;
        if (!$p || $p['owner'] !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $row = (int) $cmd->get('row', 0);
        $col = (int) $cmd->get('col', 0);
        $key = "{$row}_{$col}";

        if (!in_array($key, $p['candidates'], true)) {
            return Result::error('Неверная клетка');
        }

        $this->state->battle['pending_gate_pick']['chosen'][] = $key;
        $this->state->battle['pending_gate_pick']['candidates'] = array_values(
            array_diff($p['candidates'], [$key])
        );

        $chosenCount = count($this->state->battle['pending_gate_pick']['chosen']);
        $need        = (int) $p['count'];

        if ($chosenCount >= $need) {
            foreach ($this->state->battle['pending_gate_pick']['chosen'] as $k) {
                ZoneManager::addMarker($this->state, $k, [
                    'type'   => 'gate',
                    'source' => $playerKey,
                    'timing' => 'end_of_opponent_turn',
                ]);
            }
            unset($this->state->battle['pending_gate_pick']);

            if (!empty($this->state->battle['turn_phase'])) {
                (new TurnPhaseProcessor($this->state, $this->engine))->resume();
            }
        }

        $this->state->bumpVersion();
        return Result::ok(['gate_chosen']);
    }

    public function chooseCellMarker(string $playerKey, Command $cmd): Result
    {
        $pm = $this->state->battle['pending_cell_marker_pick'] ?? null;
        if (!$pm) return Result::error('Нет ожидающего выбора');
        if ($pm['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $row = (int) $cmd->get('row', 0);
        $col = (int) $cmd->get('col', 0);
        $key = "{$row}_{$col}";

        if (!in_array($key, $pm['cells'], true)) {
            return Result::error('Неверная клетка');
        }

        $attacker = $this->state->getCard($pm['card_id']);
        if (!$attacker) return Result::error('Карта не найдена');

        (new ZoneManager($this->state))->setCellMarker(
            $row, $col,
            $pm['marker'],
            1,                       // expire
            $pm['duration'],
            $playerKey
        );

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'cell_marker',
            'action_name' => $pm['label'],
            'attacker_id' => $attacker->instanceId,
            'target_id'   => 0,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'cell_marker' => ['row' => $row, 'col' => $col, 'type' => $pm['marker']],
            'confirmed'   => [],
        ];

        unset($this->state->battle['pending_cell_marker_pick']);

        $this->state->bumpVersion();
        return Result::ok(["cell_marker:{$row}_{$col}"]);
    }

    public function playCombatInstant(string $playerKey, Command $cmd): Result
    {
        return (new InstantProcessor($this->state, $this->engine))
            ->playCombat($playerKey, (int) $cmd->get('card_id', 0), (string) $cmd->get('instant_key', ''));
    }

    public function chooseCombatPick(string $playerKey, Command $cmd): Result
    {
        return (new InstantProcessor($this->state, $this->engine))->chooseTarget($playerKey, $cmd);
    }

    public function openTurnInstants(string $playerKey, Command $cmd): Result
    {
        return (new InstantProcessor($this->state, $this->engine))->openTurnInstants($playerKey, $cmd);
    }

    public function playTurnInstant(string $playerKey, Command $cmd): Result
    {
        return (new InstantProcessor($this->state, $this->engine))->playTurnInstant($playerKey, $cmd);
    }

    public function passTurnInstant(string $playerKey): Result
    {
        return (new InstantProcessor($this->state, $this->engine))->passTurnInstant($playerKey);
    }

    private function resolveDissonance(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');
        if ($target->owner === $playerKey) return Result::error('Только на врага');
        if ($target->zone !== CardInstance::ZONE_FIELD) {
            return Result::error('Цель не на поле');
        }

        $value    = (int) ($action['value'] ?? 1);
        $baseWeak = (int) $target->strikeWeak;

        $affected = [];

        // Основная цель
        $hpBefore = $target->hp;
        $this->engine->applyDamage($this->state, $target, $value, 'impact', $attacker);
        $affected[] = [
            'target_id' => $target->instanceId,
            'damage'    => max(0, $hpBefore - $target->hp),
        ];

        // Соседи с таким же strikeWeak
        foreach ($this->state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->owner === $playerKey) continue;
            if ($c->instanceId === $target->instanceId) continue;
            if ($c->dying || $c->hp <= 0) continue;

            $d2r = abs($c->row - $target->row);
            $d2c = abs($c->col - $target->col);
            if ($d2r > 1 || $d2c > 1 || ($d2r + $d2c) === 0) continue;

            if ((int) $c->strikeWeak !== $baseWeak) continue;

            $hpBefore2 = $c->hp;
            $this->engine->applyDamage($this->state, $c, $value, 'impact', $attacker);
            $affected[] = [
                'target_id' => $c->instanceId,
                'damage'    => max(0, $hpBefore2 - $c->hp),
            ];
        }

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'dissonance',
            'action_name' => $action['name'] ?? 'Диссонанс',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'dissonance'  => [
                'base_weak' => $baseWeak,
                'targets'   => $affected,
            ],
            'confirmed'   => [],
        ];

        $this->engine->flushDeadeatQueue($this->state);
        $this->engine->checkGameOver($this->state);
        $this->state->bumpVersion();
        return Result::ok(['dissonance:' . count($affected)]);
    }

    public function chooseDiceChoice(string $playerKey, Command $cmd): Result
    {
        $choice = (string) $cmd->get('choice', '');
        return (new InstantProcessor($this->state, $this->engine))->applyDiceChoice($playerKey, $choice);
    }

    private function resolveGiveCoin(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        $cost = (int) ($action['coins'] ?? 1);

        if ($attacker->coins < $cost) {
            return Result::error('Не хватает монет');
        }

        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');
        if ($target->owner !== $playerKey) return Result::error('Только на союзника');
        if ($target->instanceId === $attacker->instanceId) return Result::error('Нельзя на себя');
        if ($target->zone !== CardInstance::ZONE_FIELD
            && $target->zone !== CardInstance::ZONE_FLYING) {
            return Result::error('Цель не на поле');
        }
        if ($target->dying || $target->hp <= 0) {
            return Result::error('Цель мертва');
        }
        if (!CardStats::canSpendCoins($target)) {
            return Result::error('Цель не может потратить монеты');
        }

        $max = (int) ($target->prop['coins']['max_value'] ?? 0);
        if ($max > 0 && $target->coins >= $max) {
            return Result::error('У цели максимум монет');
        }

        $attacker->coins -= $cost;
        $this->engine->syncCoinBonus($attacker);

        $target->coins += $cost;
        $this->engine->syncCoinBonus($target);

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'give_coin',
            'action_name' => $action['name'] ?? 'Передать монету',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'give_coin'   => [
                'target_id' => $targetId,
                'value'     => $cost,
            ],
            'confirmed'   => [],
        ];

        $this->state->bumpVersion();
        return Result::ok(["give_coin:{$playerKey}:{$cardId}->{$targetId}:{$cost}"]);
    }

    private function resolveGrantProp(
        CardInstance $attacker, array $action, int $cardId, string $playerKey
    ): Result {
        $propKey = (string) ($action['prop'] ?? '');
        if ($propKey === '') {
            return Result::error('Свойство не задано');
        }

        $attacker->prop[$propKey] = true;
        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'modifier',
            'action_name' => $action['name'] ?? 'Свойство',
            'attacker_id' => $cardId,
            'target_id'   => $cardId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'granted_prop' => [
                'target_id' => $cardId,
                'prop' => $propKey,
            ],
            'confirmed'   => [],
        ];

        $this->state->bumpVersion();
        return Result::ok(["grant_prop:{$playerKey}:{$cardId}:{$propKey}"]);
    }

    private function resolveStealCoin(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        if (!empty($attacker->flags['steal_coin_used']) && !empty($action['once_per_battle'])) {
            return Result::error('Уже использовано в этом бою');
        }

        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');
        if ($target->owner === $playerKey) return Result::error('Только на врага');
        if ($target->zone !== CardInstance::ZONE_FIELD
            && $target->zone !== CardInstance::ZONE_FLYING) {
            return Result::error('Цель не на поле');
        }
        if ($target->dying || $target->hp <= 0) {
            return Result::error('Цель мертва');
        }
        if ((int) $target->coins <= 0) {
            return Result::error('У цели нет монет');
        }

        $value = (int) ($action['value'] ?? 1);
        $stolen = min($value, (int) $target->coins);

        $target->coins -= $stolen;
        $this->engine->syncCoinBonus($target);

        $attacker->closed = true;
        $attacker->flags['steal_coin_used'] = true;

        $this->state->battle['strike'] = [
            'kind'        => 'steal_coin',
            'action_name' => $action['name'] ?? 'Уловка',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'steal_coin'  => [
                'target_id' => $targetId,
                'value'     => $stolen,
            ],
            'confirmed'   => [],
        ];

        $this->state->bumpVersion();
        return Result::ok(["steal_coin:{$playerKey}:{$cardId}->{$targetId}:{$stolen}"]);
    }

    private function resolveFreezeMoves(
        CardInstance $attacker, array $action,
        int $cardId, string $playerKey
    ): Result {
        $cost = (int) ($action['coins'] ?? 0);
        if ($cost > 0 && $attacker->coins < $cost) {
            return Result::error('Не хватает монет');
        }

        $dice  = Dice::roll();
        $level = BattleHelper::diceToLevel($dice);
        $limit = (int) ($action['strike'][$level] ?? 0);

        if ($cost > 0) {
            $attacker->coins -= $cost;
            $this->engine->syncCoinBonus($attacker);
        }

        $oppKey = $this->state->getOpponentKey($playerKey);

        // Перезапись (см. договорённость). Прошлый лимит снимается.
        $this->state->battle['moves_limit'] = [
            'owner'      => $oppKey,
            'limit'      => $limit,
            'moved_ids'  => [],
            'source_id'  => $cardId,
        ];

        $this->state->battle['strike'] = [
            'kind'        => 'freeze_moves',
            'action_name' => $action['name'] ?? 'Ледяной дождь',
            'attacker_id' => $cardId,
            'target_id'   => $cardId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => $dice,
            'defend_dice' => 0,
            'result'      => ['attack' => $level, 'defend' => '', 'winner' => 'attack'],
            'final'       => ['attack' => $level, 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'freeze'      => [
                'dice'  => $dice,
                'level' => $level,
                'limit' => $limit,
                'owner' => $oppKey,
            ],
            'confirmed'   => [],
        ];

        $attacker->closed = true;
        $this->state->bumpVersion();
        return Result::ok(["freeze_moves:{$playerKey}:{$limit}"]);
    }

    private function startRowSpell(
        CardInstance $attacker, array $action,
        int $cardId, string $playerKey
    ): Result {
        if ($attacker->closed) {
            return Result::error('Карта закрыта');
        }

        $cost = (int) ($action['coins'] ?? 0);
        if ($cost > 0 && $attacker->coins < $cost) {
            return Result::error('Не хватает монет');
        }
        if ($cost > 0) {
            $attacker->coins -= $cost;
            $this->engine->syncCoinBonus($attacker);
        }

        $this->state->battle['pending_row_pick'] = [
            'owner'   => $playerKey,
            'card_id' => $cardId,
            'action'  => $action,
        ];

        $this->state->bumpVersion();
        return Result::ok(['row_pick_started']);
    }

    private function startOpponentRowMarker(
        CardInstance $attacker, array $action,
        int $cardId, string $playerKey, string $actionKey
    ): Result {
        if ($attacker->closed) {
            return Result::error('Карта закрыта');
        }

        $cost = (int) ($action['coins'] ?? 0);
        if ($cost > 0 && $attacker->coins < $cost) {
            return Result::error('Не хватает монет');
        }

        $rows = $this->availableOpponentMarkerRows($attacker, $action, $actionKey);
        if (empty($rows)) {
            return Result::error('Все ряды уже выбраны');
        }

        $this->state->battle['pending_opponent_row_marker'] = [
            'owner'      => $playerKey,
            'card_id'    => $cardId,
            'action_key' => $actionKey,
            'action'     => $action,
            'rows'       => $rows,
        ];

        $this->state->bumpVersion();
        return Result::ok(['opponent_row_marker_started']);
    }

    public function chooseOpponentRowMarker(string $playerKey, Command $cmd): Result
    {
        $pending = $this->state->battle['pending_opponent_row_marker'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $logicalRow = (int) $cmd->get('row', 0);
        $rows = array_map('intval', (array) ($pending['rows'] ?? []));
        if (!in_array($logicalRow, $rows, true)) {
            return Result::error('Неверный ряд');
        }

        $card = $this->state->getCard((int) ($pending['card_id'] ?? 0));
        if (!$card || $card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_FIELD || $card->closed) {
            unset($this->state->battle['pending_opponent_row_marker']);
            return Result::error('Карта недоступна');
        }

        $action = (array) ($pending['action'] ?? []);
        $cost = (int) ($action['coins'] ?? 0);
        if ($cost > 0) {
            if ($card->coins < $cost) return Result::error('Не хватает монет');
            $card->coins -= $cost;
            $this->engine->syncCoinBonus($card);
        }

        $actionKey = (string) ($pending['action_key'] ?? ($action['key'] ?? $action['type'] ?? 'mark_opponent_row'));
        $physicalRow = $this->opponentPhysicalRow($playerKey, $logicalRow);
        $markerConfig = (array) ($action['marker'] ?? []);
        $label = (string) ($action['name'] ?? $markerConfig['label'] ?? 'Способность');

        for ($col = 1; $col <= 5; $col++) {
            ZoneManager::addMarker($this->state, "{$physicalRow}_{$col}", [
                'type'       => (string) ($markerConfig['type'] ?? 'reduce_attack'),
                'source'     => $playerKey,
                'owner'      => $playerKey,
                'source_id'  => $card->instanceId,
                'source_ukid'=> $card->ukid,
                'action_key' => $actionKey,
                'label'      => $label,
                'value'      => (int) ($markerConfig['value'] ?? 1),
                'filter'     => (array) ($markerConfig['filter'] ?? ['enemy' => true, 'elite' => false]),
                'timing'     => (string) ($markerConfig['timing'] ?? 'end_of_opponent_turn'),
                'expire'     => (int) ($markerConfig['expire'] ?? 1),
                'row'        => $physicalRow,
                'logical_row'=> $logicalRow,
            ]);
        }

        $card->flags['opponent_row_markers_used'][$actionKey][$logicalRow] = true;
        $card->closed = true;

        unset($this->state->battle['pending_opponent_row_marker']);

        $this->state->bumpVersion();
        return Result::ok(["opponent_row_marker:{$logicalRow}->{$physicalRow}"]);
    }

    /** @return int[] */
    private function availableOpponentMarkerRows(CardInstance $card, array $action, string $actionKey): array
    {
        $rowCount = max(1, (int) ($action['row_count'] ?? 3));
        $used = (array) ($card->flags['opponent_row_markers_used'][$actionKey] ?? []);
        $rows = [];
        for ($row = 1; $row <= $rowCount; $row++) {
            if (!empty($used[$row])) continue;
            $rows[] = $row;
        }
        return $rows;
    }

    private function opponentPhysicalRow(string $playerKey, int $logicalRow): int
    {
        return $playerKey === GameState::PLAYER_HOST
            ? 3 + $logicalRow
            : 4 - $logicalRow;
    }

    private function startDive(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        if ($attacker->zone !== CardInstance::ZONE_FLYING) {
            return Result::error('Пикирование только из полёта');
        }
        if (!empty($attacker->flags['dive_used']) && !empty($action['once_per_battle'])) {
            return Result::error('Пикирование уже использовано');
        }

        $cost = (int) ($action['coins'] ?? 0);
        if ($cost > 0 && $attacker->coins < $cost) {
            return Result::error('Не хватает монет');
        }

        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');
        if ($target->owner === $playerKey) return Result::error('Только на врага');
        if ($target->zone !== CardInstance::ZONE_FIELD) return Result::error('Цель не на земле');
        if ($target->type === 'fly') return Result::error('Цель летающая');
        if ($target->dying || $target->hp <= 0) return Result::error('Цель мертва');

        $cells = [];
        for ($dr = -1; $dr <= 1; $dr++) {
            for ($dc = -1; $dc <= 1; $dc++) {
                if ($dr === 0 && $dc === 0) continue;
                $r = $target->row + $dr;
                $c = $target->col + $dc;
                if ($r < 1 || $r > 6 || $c < 1 || $c > 5) continue;

                $occupied = false;
                foreach ($this->state->cards as $o) {
                    if ($o->zone === CardInstance::ZONE_FIELD
                        && $o->row === $r && $o->col === $c) {
                        $occupied = true;
                        break;
                    }
                }
                if ($occupied) continue;
                if (ZoneManager::hasBlockingMarker($this->state, "{$r}_{$c}")) continue;

                $cells[] = ['row' => $r, 'col' => $c];
            }
        }

        if (empty($cells)) {
            return Result::error('Нет свободных клеток рядом с целью');
        }

        if ($cost > 0) {
            $attacker->coins -= $cost;
            $this->engine->syncCoinBonus($attacker);
        }

        $this->state->battle['pending_dive'] = [
            'owner'       => $playerKey,
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'action'      => $action,
            'cells'       => $cells,
        ];

        $this->state->bumpVersion();
        return Result::ok(['dive_started']);
    }

        private function startParticlePick(
        CardInstance $attacker, array $action,
        int $cardId, string $playerKey
    ): Result {
        if ($attacker->closed) {
            return Result::error('Карта закрыта');
        }

        $candidates = [];
        foreach ($this->state->cards as $c) {
            if ($c->instanceId === $attacker->instanceId) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;
            if ($c->hp < $c->hpMax) continue;   // только без ран
            $candidates[] = $c->instanceId;
        }

        if (empty($candidates)) {
            return Result::error('Нет существ без ран');
        }

        $this->state->battle['pending_particle_pick'] = [
            'owner'       => $playerKey,
            'card_id'     => $cardId,
            'value'       => (int) ($action['value'] ?? 1),
            'max_targets' => (int) ($action['max_targets'] ?? 2),
            'candidates'  => $candidates,
        ];

        $this->state->bumpVersion();
        return Result::ok(['particle_started']);
    }

    public function chooseParticlePick(string $playerKey, Command $cmd): Result
    {
        $p = $this->state->battle['pending_particle_pick'] ?? null;
        if (!$p || $p['owner'] !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $raw = $cmd->get('target_ids', []);
        if (!is_array($raw)) $raw = [$raw];
        $targetIds = array_values(array_unique(array_map('intval', $raw)));

        if (empty($targetIds)) {
            return Result::error('Выберите хотя бы одну цель');
        }
        if (count($targetIds) > (int) $p['max_targets']) {
            return Result::error('Слишком много целей');
        }
        foreach ($targetIds as $tid) {
            if (!in_array($tid, $p['candidates'], true)) {
                return Result::error('Неверная цель');
            }
        }

        $attacker = $this->state->getCard((int) $p['card_id']);
        if (!$attacker) {
            unset($this->state->battle['pending_particle_pick']);
            return Result::error('Источник недоступен');
        }

        unset($this->state->battle['pending_particle_pick']);

        $value = (int) $p['value'];
        $applied = [];
        foreach ($targetIds as $tid) {
            $target = $this->state->getCard($tid);
            if (!$target || $target->dying || $target->hp <= 0) continue;
            $hpBefore = $target->hp;
            $this->engine->applyDamage($this->state, $target, $value, 'impact', $attacker);
            $applied[] = [
                'target_id' => $tid,
                'damage'    => max(0, $hpBefore - $target->hp),
            ];
        }

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'particle',
            'action_name' => 'Частица души',
            'attacker_id' => $attacker->instanceId,
            'target_id'   => $targetIds[0] ?? 0,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'particle'    => ['targets' => $applied],
            'confirmed'   => [],
        ];

        $this->engine->flushDeadeatQueue($this->state);
        $this->state->bumpVersion();
        return Result::ok(['particle_done']);
    }

    private function startLifeGift(
        CardInstance $attacker, array $action,
        int $cardId, string $playerKey
    ): Result {
        if ($attacker->closed) {
            return Result::error('Карта закрыта');
        }

        $min = (int) ($action['min_x'] ?? 1);
        if ($attacker->coins < $min) {
            return Result::error('Не хватает монет');
        }

        $enemies = [];
        $allies  = [];
        foreach ($this->state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;
            if ($c->owner === $playerKey) {
                $allies[] = $c->instanceId;
            } else {
                $enemies[] = $c->instanceId;
            }
        }

        if (empty($enemies)) {
            return Result::error('Нет целей-врагов');
        }
        if (empty($allies)) {
            return Result::error('Нет союзников');
        }

        $this->state->battle['pending_life_gift'] = [
            'owner'     => $playerKey,
            'card_id'   => $cardId,
            'min_x'     => $min,
            'max_x'     => $attacker->coins,
            'enemies'   => $enemies,
            'allies'    => $allies,
        ];

        $this->state->bumpVersion();
        return Result::ok(['life_gift_started']);
    }

    public function chooseLifeGift(string $playerKey, Command $cmd): Result
    {
        $p = $this->state->battle['pending_life_gift'] ?? null;
        if (!$p || $p['owner'] !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $x = (int) $cmd->get('amount', 0);
        $enemyId = (int) $cmd->get('enemy_id', 0);
        $allyId  = (int) $cmd->get('ally_id', 0);

        if ($x < (int) $p['min_x'] || $x > (int) $p['max_x']) {
            return Result::error('Неверное количество монет');
        }
        if (!in_array($enemyId, $p['enemies'], true)) {
            return Result::error('Неверная цель-враг');
        }
        if (!in_array($allyId, $p['allies'], true)) {
            return Result::error('Неверная цель-союзник');
        }

        $attacker = $this->state->getCard((int) $p['card_id']);
        if (!$attacker) {
            unset($this->state->battle['pending_life_gift']);
            return Result::error('Источник недоступен');
        }

        $enemy = $this->state->getCard($enemyId);
        $ally  = $this->state->getCard($allyId);
        if (!$enemy || !$ally) {
            return Result::error('Цель не найдена');
        }

        unset($this->state->battle['pending_life_gift']);

        $attacker->coins -= $x;
        $this->engine->syncCoinBonus($attacker);
        $attacker->closed = true;

        // Урон врагу — cast (защита zom)
        $defended = CardStats::hasDefense($this->state, $enemy, 'cast', $attacker);
        $hpBefore = $enemy->hp;
        if (!$defended) {
            $this->engine->applyDamage($this->state, $enemy, $x, 'cast', $attacker);
        }
        $dealt = max(0, $hpBefore - $enemy->hp);

        // Heal союзнику на X (с капом)
        $allyHpBefore = $ally->hp;
        $ally->hp = min($ally->hpMax, $ally->hp + $x);
        $healed = $ally->hp - $allyHpBefore;

        $this->state->battle['strike'] = [
            'kind'        => 'life_gift',
            'action_name' => 'Предсмертный дар',
            'attacker_id' => $attacker->instanceId,
            'target_id'   => $enemy->instanceId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => $dealt,
            'life_gift'   => [
                'x'         => $x,
                'enemy_id'  => $enemy->instanceId,
                'ally_id'   => $ally->instanceId,
                'damage'    => $dealt,
                'heal'      => $healed,
                'defended'  => $defended,
            ],
            'confirmed'   => [],
        ];

        $this->engine->flushDeadeatQueue($this->state);
        $this->engine->checkGameOver($this->state);
        $this->state->bumpVersion();
        return Result::ok(["life_gift:{$x}"]);
    }

    private function resolveBombShot(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');
        if ($target->owner === $playerKey) return Result::error('Только на врага');
        if ($target->zone !== CardInstance::ZONE_FIELD) {
            return Result::error('Цель не на земле');
        }
        if ($target->dying || $target->hp <= 0) return Result::error('Цель мертва');

        $dr = abs($target->row - $attacker->row);
        $dc = abs($target->col - $attacker->col);
        $dist = $dr + $dc;
        if ($dist === 0) return Result::error('Цель не соседняя');

        $range = CardStats::getEffectiveRange($this->state, $attacker, $action);
        if ($range > 0 && $dist > $range) {
            return Result::error('Превышена дальность');
        }

        $cellKey = "{$target->row}_{$target->col}";
        if (ZoneManager::hasMarker($this->state, $cellKey)) {
            return Result::error('Клетка уже помечена');
        }

        $cost = (int) ($action['coins'] ?? 0);
        if ($cost > 0) {
            if ($attacker->coins < $cost) return Result::error('Не хватает монет');
            $attacker->coins -= $cost;
        }

        $value      = (int) ($action['value'] ?? 1);
        $bombDamage = (int) ($action['bomb_damage'] ?? 2);
        $attackReduction = $this->engine->reduceAttackValueByCellMarkers($this->state, $attacker, $value);
        $value = (int) $attackReduction['value'];
        $webBlocked = $this->engine->tryBlockDamageAttackWithMarker($this->state, $target, 'shot', $value);
        if ($webBlocked) {
            $value = 0;
        }

        $hpBefore = $target->hp;
        $this->engine->applyDamage($this->state, $target, $value, 'shot', $attacker);
        $realDamage = max(0, $hpBefore - $target->hp);

        ZoneManager::addMarker($this->state, $cellKey, [
            'type'   => 'bomb',
            'source' => $playerKey,
            'damage' => $bombDamage,
            'label'  => $action['name'] ?? 'Бомба',
        ]);

        $this->state->battle['strike'] = [
            'kind'        => 'bomb_shot',
            'action_name' => $action['name'] ?? 'Бомба',
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => $realDamage,
            'bomb'        => [
                'row'    => $target->row,
                'col'    => $target->col,
                'damage' => $bombDamage,
            ],
            'confirmed'   => [],
        ];
        foreach ($attackReduction['events'] as $event) {
            $this->state->battle['strike']['attack_value_reduction'][] = $event;
        }

        $attacker->closed = true;
        $this->state->bumpVersion();
        return Result::ok(["bomb_shot:{$playerKey}:{$cardId}->{$targetId}"]);
    }

    public function chooseDiveCell(string $playerKey, Command $cmd): Result
    {
        $pd = $this->state->battle['pending_dive'] ?? null;
        if (!$pd) return Result::error('Нет ожидающего выбора');
        if ($pd['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $row = (int) $cmd->get('row', 0);
        $col = (int) $cmd->get('col', 0);

        $valid = false;
        foreach ($pd['cells'] as $c) {
            if ($c['row'] === $row && $c['col'] === $col) { $valid = true; break; }
        }
        if (!$valid) return Result::error('Неверная клетка');

        $attacker = $this->state->getCard($pd['attacker_id']);
        $target   = $this->state->getCard($pd['target_id']);
        if (!$attacker || !$target) return Result::error('Карта не найдена');

        $action = $pd['action'];

        // 1. Запоминаем где была цель
        $oldRow = $target->row;
        $oldCol = $target->col;

        // 2. Перемещаем цель
        $target->row = $row;
        $target->col = $col;
        $target->flags['moved_this_turn'] = true;

        // 3. Кондор становится существом на освободившейся клетке
        $zone = new ZoneManager($this->state);
        $zone->toField($attacker, $oldRow, $oldCol);
        $attacker->type     = 'creature';
        $attacker->closed   = true;
        $attacker->move     = 1;
        $attacker->moveMax  = 1;
        $attacker->flags['dive_used'] = true;

        // 4. Кубик и урон
        $dice  = Dice::roll();
        $level = BattleHelper::diceToLevel($dice);
        $val   = (int) ($action['strike'][$level] ?? 0);

        $this->engine->applyDamage($this->state, $target, $val, 'tap', $attacker);

        unset($this->state->battle['pending_dive']);

        $this->state->battle['strike'] = [
            'kind'        => 'dive',
            'action_name' => $action['name'] ?? 'Пикирование',
            'attacker_id' => $attacker->instanceId,
            'target_id'   => $target->instanceId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => $dice,
            'defend_dice' => 0,
            'result'      => ['attack' => $level, 'defend' => '', 'winner' => 'attack'],
            'final'       => ['attack' => $level, 'defend' => '', 'decreased' => false],
            'damage'      => $val,
            'damage_total' => $val,
            'confirmed'   => [],
        ];

        $this->engine->flushDeadeatQueue($this->state);
        $this->state->bumpVersion();
        return Result::ok(['dive_done']);
    }

    public function chooseForcedStrike(string $playerKey, Command $cmd): Result
    {
        $pf = $this->state->battle['pending_forced_strike'] ?? null;
        if (!$pf) return Result::error('Нет ожидающего выбора');
        if ($pf['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $targetId = (int) $cmd->get('target_id', 0);
        if (!in_array($targetId, $pf['candidates'], true)) {
            return Result::error('Неверная цель');
        }

        $attacker = $this->state->getCard($pf['attacker_id']);
        if (!$attacker) return Result::error('Атакующий не найден');

        unset($this->state->battle['pending_forced_strike']);

        // Запускаем обычный strike
        $strike = new StrikeResolver($this->state, $this->engine);
        $strikeCmd = new Command('strike', [
            'card_id'   => $attacker->instanceId,
            'target_id' => $targetId,
        ]);
        return $strike->declare($playerKey, $strikeCmd);
    }

    public function chooseRow(string $playerKey, Command $cmd): Result
    {
        $p = $this->state->battle['pending_row_pick'] ?? null;
        if (!$p || $p['owner'] !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $uiRow = (int) $cmd->get('row', 0);
        if ($uiRow < 1 || $uiRow > 6) {
            return Result::error('Неверный ряд');
        }

        $card = $this->state->getCard($p['card_id']);
        if (!$card || $card->owner !== $playerKey) {
            unset($this->state->battle['pending_row_pick']);
            return Result::error('Карта недоступна');
        }

        // UI-номер → физический row (зеркалирование для player)
        $isHost = $playerKey === 'host';
        $physicalRow = $isHost ? $uiRow : (7 - $uiRow);

        // 5 маркеров на клетки ряда
        for ($col = 1; $col <= 5; $col++) {
            $key = "{$physicalRow}_{$col}";
            ZoneManager::addMarker($this->state, $key, [
                'type'   => 'row_spell',
                'source' => $playerKey,
            ]);
        }

        $card->flags['row_spell_pending'] = [
            'row' => $physicalRow,
            'action' => $p['action'] ?? [],
        ];
        $card->closed = true;

        unset($this->state->battle['pending_row_pick']);

        $this->state->bumpVersion();
        return Result::ok(["row_chosen:{$uiRow}->{$physicalRow}"]);
    }

    public function chooseRowSpellTargets(string $playerKey, Command $cmd): Result
    {
        $p = $this->state->battle['pending_row_spell_pick'] ?? null;
        if (!$p || $p['owner'] !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $raw = $cmd->get('target_ids', []);
        if (!is_array($raw)) $raw = [$raw];
        $targetIds = array_values(array_unique(array_map('intval', $raw)));

        $x = (int) $p['x'];

        if (count($targetIds) !== $x) {
            return Result::error('Нужно выбрать ровно ' . $x . ' цел' . ($x === 1 ? 'ь' : 'и'));
        }

        foreach ($targetIds as $tid) {
            if (!in_array($tid, $p['candidates'], true)) {
                return Result::error('Неверная цель');
            }
        }

        $source = $this->state->getCard((int) $p['source_id']);
        unset($this->state->battle['pending_row_spell_pick']);

        // Один бросок кубика
        $action = $p['action'] ?? [];

        $dice  = Dice::roll();
        $level = BattleHelper::diceToLevel($dice);
        $val   = (int) ($action['strike'][$level] ?? 0);

        $results = [];
        foreach ($targetIds as $tid) {
            $target = $this->state->getCard($tid);
            if (!$target) continue;
            $hpBefore = $target->hp;
            $this->engine->applyDamage($this->state, $target, $val, 'discharge', $source);
            $results[] = [
                'target_id' => $tid,
                'damage'    => max(0, $hpBefore - $target->hp),
            ];
        }

        $this->state->battle['strike'] = [
            'kind'        => 'row_spell',
            'action_name' => 'Цветущие руны',
            'attacker_id' => $source?->instanceId ?? 0,
            'target_id'   => $targetIds[0] ?? 0,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => $dice,
            'defend_dice' => 0,
            'result'      => ['attack' => $level, 'defend' => '', 'winner' => 'attack'],
            'final'       => ['attack' => $level, 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'row_spell'   => ['dice' => $dice, 'level' => $level, 'damage_per_target' => $val, 'targets' => $results],
            'confirmed'   => [],
        ];

         if (!empty($this->state->battle['turn_phase'])) {
            (new TurnPhaseProcessor($this->state, $this->engine))->resume();
        }

        $this->engine->flushDeadeatQueue($this->state);
        $this->state->bumpVersion();
        return Result::ok(['row_spell_done']);
    }

    public function chooseGreedTeleport(string $playerKey, Command $cmd): Result
    {
        $p = $this->state->battle['pending_greed_teleport'] ?? null;
        if (!$p || $p['owner'] !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $row = (int) $cmd->get('row', 0);
        $col = (int) $cmd->get('col', 0);
        $key = "{$row}_{$col}";

        if (!in_array($key, $p['free_gates'], true)) {
            return Result::error('Неверная клетка');
        }

        $card = $this->state->getCard((int) $p['source_id']);
        if (!$card || $card->owner !== $playerKey) {
            unset($this->state->battle['pending_greed_teleport']);
            return Result::error('Карта недоступна');
        }

        $damage     = (int) $p['damage'];
        $gates      = $p['gates'];
        $ownerKey   = $playerKey;

        unset($this->state->battle['pending_greed_teleport']);

        // Телепорт
        $card->row = $row;
        $card->col = $col;

        // AoE 8 клеток
        $hits = [];
        foreach ($this->state->cards as $other) {
            if ($other->owner === $ownerKey) continue;
            if ($other->zone !== CardInstance::ZONE_FIELD) continue;
            if ($other->dying || $other->hp <= 0) continue;
            if ($other->instanceId === $card->instanceId) continue;

            $dr = abs($other->row - $row);
            $dc = abs($other->col - $col);
            if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;

            $hpBefore = $other->hp;
            $this->engine->applyDamage($this->state, $other, $damage, 'impact', $card);
            $hits[] = [
                'instance_id' => $other->instanceId,
                'delta'       => -(max(0, $hpBefore - $other->hp)),
            ];
        }

        // Снять все gate владельца
        foreach ($gates as $k) {
            ZoneManager::removeMarkersByType($this->state, $k, 'gate', $ownerKey);
        }

        // Результат — записать в текущий turn_phase pending_ack
        if (!empty($this->state->battle['turn_phase'])) {
            $this->state->battle['turn_phase']['pending_ack'] = [
                'label' => 'Демон жадности',
                'items' => array_merge(
                    [['standalone_text' => "телепортировался на ({$row};{$col})"]],
                    $hits
                ),
            ];
            // НЕ resume — ждём подтверждения игрока через turn_ack.
            // Кнопка «Продолжить» → ackPending() → advance → finish.
        }

        $this->engine->flushDeadeatQueue($this->state);
        $this->state->bumpVersion();
        return Result::ok(["greed_teleport:{$row}_{$col}"]);
    }

    public function chooseNokamiWound(string $playerKey, Command $cmd): Result
    {
        $queue = $this->state->battle['pending_nokami_wound'] ?? [];
        if (empty($queue)) {
            return Result::error('Нет ожидающего выбора');
        }

        $item = $queue[0];
        $nokami = $this->state->getCard((int) $item['source_id']);
        if (!$nokami || $nokami->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $targetId = (int) $cmd->get('target_id', 0);

        if ($targetId === 0) {
            array_shift($this->state->battle['pending_nokami_wound']);
            if (empty($this->state->battle['pending_nokami_wound'])
                && !empty($this->state->battle['turn_phase'])) {
                (new TurnPhaseProcessor($this->state, $this->engine))->resume();
            }
            $this->state->bumpVersion();
            return Result::ok(['nokami_skipped']);
        }

        if (!in_array($targetId, $item['candidates'], true)) {
            return Result::error('Неверная цель');
        }

        $target = $this->state->getCard($targetId);
        if (!$target || $target->dying || $target->hp <= 0) {
            return Result::error('Цель недоступна');
        }

        $value = (int) ($item['value'] ?? 1);
        $this->engine->applyDamage($this->state, $target, $value, 'impact', $nokami);

        $nokami->flags['nokami_used_this_turn'] = true;

        if (!empty($this->state->battle['strike'])) {
            $this->state->battle['strike']['nokami_wound'][] = [
                'source_id' => $nokami->instanceId,
                'target_id' => $target->instanceId,
                'value'     => $value,
            ];
        }

        array_shift($this->state->battle['pending_nokami_wound']);

        if (empty($this->state->battle['pending_nokami_wound'])
            && !empty($this->state->battle['turn_phase'])) {
            (new TurnPhaseProcessor($this->state, $this->engine))->resume();
        }

        $this->engine->flushDeadeatQueue($this->state);
        $this->state->bumpVersion();
        return Result::ok(["nokami_wound:{$targetId}"]);
    }

    private function resolveDamageRanged(
        CardInstance $attacker, array $action,
        int $cardId, string $playerKey
    ): Result {
        $value  = (int) ($action['value'] ?? 2);
        $filter = (string) ($action['filter'] ?? 'enemy');

        $affected = [];
        foreach ($this->state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;
            if ($c->instanceId === $attacker->instanceId) continue;

            if ($filter === 'enemy' && $c->owner === $playerKey) continue;
            if ($filter === 'own'   && $c->owner !== $playerKey) continue;

            if (!CardStats::hasRangedAction($c)) continue;

            $hpBefore = $c->hp;
            $this->engine->applyDamage($this->state, $c, $value, 'cast', $attacker);
            $affected[] = [
                'target_id' => $c->instanceId,
                'damage'    => max(0, $hpBefore - $c->hp),
            ];
        }

        $attacker->closed = true;

        $this->state->battle['strike'] = [
            'kind'        => 'damage_ranged',
            'action_name' => $action['name'] ?? 'Пламя бездны',
            'attacker_id' => $cardId,
            'target_id'   => $affected[0]['target_id'] ?? 0,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'ranged_damage' => $affected,
            'confirmed'   => [],
        ];

        $this->engine->flushDeadeatQueue($this->state);
        $this->engine->checkGameOver($this->state);
        $this->state->bumpVersion();
        return Result::ok(['damage_ranged:' . count($affected)]);
    }

    private function startTeleportTarget(
        CardInstance $attacker, array $action,
        int $cardId, int $targetId, string $playerKey
    ): Result {
        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');
        if ($target->zone !== CardInstance::ZONE_FIELD) {
            return Result::error('Только нелетающее существо на земле');
        }
        if ($target->dying || $target->hp <= 0) {
            return Result::error('Цель мертва');
        }

        $cost = (int) ($action['coins'] ?? 0);
        if ($cost > 0 && $attacker->coins < $cost) {
            return Result::error('Не хватает монет');
        }

        $zone  = new ZoneManager($this->state);
        $cells = [];
        for ($r = 1; $r <= 6; $r++) {
            for ($c = 1; $c <= 5; $c++) {
                if ($r === $target->row && $c === $target->col) continue;
                if ($zone->isFieldOccupied($r, $c)) continue;
                if (ZoneManager::hasBlockingMarker($this->state, "{$r}_{$c}")) continue;
                $cells[] = ['row' => $r, 'col' => $c];
            }
        }
        if (empty($cells)) return Result::error('Нет свободных клеток');

        if ($cost > 0) {
            $attacker->coins -= $cost;
            $this->engine->syncCoinBonus($attacker);
        }

        $this->state->battle['pending_teleport_target'] = [
            'owner'       => $playerKey,
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'action'      => $action,
            'cells'       => $cells,
        ];

        $this->state->bumpVersion();
        return Result::ok(['teleport_target_started']);
    }

    public function chooseTeleportTargetCell(string $playerKey, Command $cmd): Result
    {
        $pt = $this->state->battle['pending_teleport_target'] ?? null;
        if (!$pt) return Result::error('Нет ожидающего выбора');
        if ($pt['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $row = (int) $cmd->get('row', 0);
        $col = (int) $cmd->get('col', 0);

        $valid = false;
        foreach ($pt['cells'] as $c) {
            if ($c['row'] === $row && $c['col'] === $col) { $valid = true; break; }
        }
        if (!$valid) return Result::error('Неверная клетка');

        $attacker = $this->state->getCard($pt['attacker_id']);
        $target   = $this->state->getCard($pt['target_id']);
        if (!$attacker || !$target) {
            unset($this->state->battle['pending_teleport_target']);
            return Result::error('Карта не найдена');
        }

        $action = $pt['action'];

        $target->row = $row;
        $target->col = $col;
        $target->flags['moved_this_turn'] = true;

        $attacker->closed = true;

        unset($this->state->battle['pending_teleport_target']);

        $this->state->battle['strike'] = [
            'kind'        => 'teleport_target',
            'action_name' => $action['name'] ?? 'Дверь измерений',
            'attacker_id' => $attacker->instanceId,
            'target_id'   => $target->instanceId,
            'defender_id' => null,
            'state'       => 'results',
            'attack_dice' => 0,
            'defend_dice' => 0,
            'result'      => ['attack' => '', 'defend' => '', 'winner' => ''],
            'final'       => ['attack' => '', 'defend' => '', 'decreased' => false],
            'damage'      => 0,
            'teleport'    => [
                'target_id' => $target->instanceId,
                'new_row'   => $row,
                'new_col'   => $col,
            ],
            'confirmed'   => [],
        ];

        $this->state->bumpVersion();
        return Result::ok(['teleport_target_done']);
    }
}
