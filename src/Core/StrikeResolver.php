<?php
// src/Core/StrikeResolver.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Логика сражения. Часть A — чистые методы (не мутируют state).
 * Часть B (сценарий сражения) будет добавлена позже.
 */
final class StrikeResolver
{
    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {}

    /**
     * Таблица ударов.
     */
    public function strikeTable(int $attack, int $defend): array
    {
        $strike = ['attack' => '', 'defend' => '', 'winner' => ''];

        if ($defend > 0) {
            if ((abs($attack - $defend) === 1) || (($attack === $defend) && ($attack <= 4))) {
                $strike['attack'] = 'weak';
            } elseif (($attack - $defend) === 2) {
                $strike['attack'] = 'medium';
                $strike['defend'] = 'weak';
                $strike['winner'] = 'attack';
            } elseif (($attack - $defend) === 3) {
                $strike['attack'] = 'medium';
            } elseif (($attack - $defend) === 4) {
                $strike['attack'] = 'strong';
                $strike['defend'] = 'weak';
                $strike['winner'] = 'attack';
            } elseif (($attack - $defend) >= 5) {
                $strike['attack'] = 'strong';
            } elseif ((($defend - $attack) === 3) || (($attack === $defend) && ($defend >= 5))) {
                $strike['defend'] = 'weak';
            } elseif (($defend - $attack) === 4) {
                $strike['attack'] = 'weak';
                $strike['defend'] = 'medium';
                $strike['winner'] = 'defend';
            } elseif (($defend - $attack) >= 5) {
                $strike['defend'] = 'medium';
            }
        } else {
            if ($attack <= 3) $strike['attack'] = 'weak';
            elseif ($attack <= 5) $strike['attack'] = 'medium';
            else $strike['attack'] = 'strong';
        }

        if ($strike['winner'] === '') {
            $strike['winner'] = ($strike['attack'] !== '') ? 'attack' : 'defend';
        }
        return $strike;
    }

    /**
     * Уменьшение удара на одну ступень.
     */
    public function decreaseStrike(string $s): string
    {
        return match ($s) {
            'weak'   => '',
            'medium' => 'weak',
            'strong' => 'medium',
            default  => '',
        };
    }

    /**
     * @return int[] instance_id возможных защитников
     */
    public function findDefenders(
        CardInstance $attacker,
        CardInstance $target,
        string $oppKey
    ): array {
        $result = [];

        $attackerIsFlying = ($attacker->zone === CardInstance::ZONE_FLYING);
        $targetIsFlying   = ($target->zone === CardInstance::ZONE_FLYING);

        foreach ($this->state->cards as $card) {
            if ($card->owner !== $oppKey) continue;
            if ($card->closed) continue;
            if ($card->instanceId === $target->instanceId) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;

            $canDefend = false;

            if ($card->type === 'fly' && $card->zone === CardInstance::ZONE_FLYING) {
                if (!empty($card->prop['fly_defend'])) {
                    $canDefend = true;
                } elseif ($targetIsFlying) {
                    $canDefend = true;
                }
            } elseif ($targetIsFlying) {
                $canDefend = false;
            } elseif ($attackerIsFlying) {
                $drT = abs($card->row - $target->row);
                $dcT = abs($card->col - $target->col);
                $canDefend = ($drT <= 1 && $dcT <= 1 && ($drT + $dcT) > 0);
            } else {
                $drA = abs($card->row - $attacker->row);
                $dcA = abs($card->col - $attacker->col);
                $drT = abs($card->row - $target->row);
                $dcT = abs($card->col - $target->col);
                $nearA = ($drA <= 1 && $dcA <= 1 && ($drA + $dcA) > 0);
                $nearT = ($drT <= 1 && $dcT <= 1 && ($drT + $dcT) > 0);
                $canDefend = $nearA && $nearT;
            }

            if ($canDefend) {
                $result[] = $card->instanceId;
            }
        }

        return $result;
    }

    public function declare(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'battle') {
            return Result::error('Сейчас не бой');
        }
        if ($this->state->battle['active'] !== $playerKey) {
            return Result::error('Сейчас не ваш ход');
        }
        if (!empty($this->state->battle['strike'])) {
            return Result::error('Уже идёт сражение');
        }

        $cardId   = (int) $cmd->get('card_id', 0);
        $targetId = (int) $cmd->get('target_id', 0);

        $attacker = $this->state->getCard($cardId);
        $target   = $this->state->getCard($targetId);

        if (!$attacker
            || $attacker->owner !== $playerKey
            || ($attacker->zone !== CardInstance::ZONE_FIELD
                && $attacker->zone !== CardInstance::ZONE_FLYING)) {
            return Result::error('Атакующий не на поле');
        }
        if ($attacker->closed) {
            return Result::error('Атакующий закрыт');
        }
        if (CardStats::hasCannotAttack($attacker)) {
            return Result::error('Карта не может атаковать до конца хода');
        }

        $attackLimit = (int) ($attacker->prop['attacks_per_turn'] ?? 1);

        // Берсерк: движение после атаки снимает вторую атаку
        if (!empty($attacker->prop['strike_consecutive'])
            && !empty($attacker->flags['strike_chain_broken'])) {
            $attackLimit = 1;
        }

        $attacksUsed = (int) ($attacker->flags['attacks_used_this_turn'] ?? 0);
        if ($attacksUsed >= $attackLimit) {
            return Result::error('Уже атаковал в этот ход');
        }

        // Берсерк: второй удар только по другой цели
        if (!empty($attacker->prop['strike_targets_unique']) && $attacksUsed > 0) {
            $firstTarget = (int) ($attacker->flags['first_attack_target_id'] ?? 0);
            if ($firstTarget > 0 && $firstTarget === $targetId) {
                return Result::error('Второй удар должен быть по другой цели');
            }
        }

        if (!$target
            || ($target->zone !== CardInstance::ZONE_FIELD
                && $target->zone !== CardInstance::ZONE_FLYING)) {
            return Result::error('Цель не на поле');
        }
        $friendlyFire = $target->owner === $playerKey;
        if ($friendlyFire) {
            $allowedFriendlyTargets = BattleHelper::getAttackTargets(
                $this->state,
                $attacker,
                'strike',
                $playerKey,
                true
            );
            if (!isset($allowedFriendlyTargets[$target->instanceId])) {
                return Result::error('Нельзя атаковать эту свою карту');
            }
        }

        $this->engine->revealCard($this->state, $target);

        $attackerIsFlying = ($attacker->zone === CardInstance::ZONE_FLYING);

        // Перехват Паука
        if ($attackerIsFlying) {
            $interceptors = CardStats::getAirInterceptors($this->state, $attacker->owner);
            if (!empty($interceptors)) {
                $allowedIds = array_map(fn($p) => $p->instanceId, $interceptors);
                if (!in_array($target->instanceId, $allowedIds, true)) {
                    return Result::error('Летун может атаковать только Паука-пересмешника');
                }
            }
        }

        // Универсальная защита (zoa/zoan/zoal + специфичные)
        if (CardStats::hasDefense($this->state, $target, 'strike', $attacker)) {
            return Result::error('Цель защищена от этой атаки');
        }

        if (!$attackerIsFlying) {
            // Ограничение «только по карте напротив» (Циклоп)
            if (!empty($attacker->prop['strike']['opposite'])) {
                if (!CardStats::isOpposite($attacker, $target)) {
                    return Result::error('Удар возможен только по карте напротив');
                }
            } else {
                $drow = abs($target->row - $attacker->row);
                $dcol = abs($target->col - $attacker->col);
                $dist = $drow + $dcol;

                $isAdjacent = ($drow <= 1 && $dcol <= 1 && $dist > 0);

                $isRowExtreme = !empty($attacker->prop['row_extreme'])
                    && $target->row === $attacker->row
                    && (($attacker->col === 1 && $target->col === 5)
                        || ($attacker->col === 5 && $target->col === 1));

                $isLineRange = !empty($attacker->prop['strike_range_line'])
                    && CardStats::isInLine($this->state, $attacker)
                    && $dist > 0
                    && $dist <= (int) $attacker->prop['strike_range_line'];

                if (!$isAdjacent && !$isRowExtreme && !$isLineRange) {
                    return Result::error('Цель не соседняя');
                }
            }
        }

        if (!CardStats::hasAnyStrike($attacker)) {
            return Result::error('Карта не может атаковать');
        }


        // Обязательная атака
        $forced = CardStats::getForcedStrikeTarget($this->state, $attacker);
        if ($forced !== null) {
            $dr = abs($target->row - $attacker->row);
            $dc = abs($target->col - $attacker->col);
            $isValidTarget = $target->closed
                && $target->owner !== $playerKey
                && $dr <= 1 && $dc <= 1 && ($dr + $dc) > 0;

            if (!$isValidTarget) {
                return Result::error('Обязаны атаковать закрытое существо');
            }
        }

        $isDirect = $friendlyFire || CardStats::isDirectStrike($this->state, $attacker, $target);

        $oppKey = $this->state->getOpponentKey($playerKey);
        $defenders = $isDirect
            ? []
            : $this->findDefenders($attacker, $target, $oppKey);

        // Волот: перенаправление вместо выбора защитника
        $redirectConfig = $attacker->prop['strike']['redirect'] ?? null;
        $redirectCandidates = [];

        if ($redirectConfig && !$friendlyFire) {
            foreach ($this->state->cards as $c) {
                if ($c->owner !== $oppKey) continue;
                if ($c->zone !== CardInstance::ZONE_FIELD) continue;
                if ($c->instanceId === $targetId) continue;

                $dr = abs($c->row - $attacker->row);
                $dc = abs($c->col - $attacker->col);
                if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;

                $redirectCandidates[] = $c->instanceId;
            }
        }

        // Пророчество-блок (Дочь перламутра) — до создания strike
        if ($this->engine->tryProphecyBlock($this->state, $attacker, $target, 'strike')) {
            $attacker->closed = true;
            $this->state->bumpVersion();
            return Result::ok(["strike_blocked:{$playerKey}:{$cardId}->{$targetId}"]);
        }
        
        foreach ($this->state->cards as $card) {
            $card->flags['damage_taken_this_strike'] = 0;
        }

        $this->state->battle['strike'] = [
            'attacker_id' => $cardId,
            'target_id'   => $targetId,
            'defender_id' => null,
            'state'       => !empty($redirectCandidates) ? 'waiting_redirect' : 'waiting_defender',
            'attack_dice' => null,
            'defend_dice' => null,
            'result'      => null,
            'confirmed'   => [],
            'defenders'   => $defenders,
            'redirect_candidates' => $redirectCandidates,
            'friendly_fire' => $friendlyFire,
        ];

        // Окно инстантов до броска — openWindow сам проверит, есть ли у кого
        $ip = new InstantProcessor($this->state, $this->engine);
        $ip->openWindow('before', $playerKey);

        if (($this->state->battle['strike']['state'] ?? '') === 'waiting_instant') {
            $this->state->bumpVersion();
            return Result::ok(["strike_declared:{$playerKey}:{$cardId}->{$targetId}", 'instant_window_opened']);
        }

        if (empty($redirectCandidates) && empty($defenders)) {
            $this->resolve();
        }

        $this->state->bumpVersion();
        return Result::ok(["strike_declared:{$playerKey}:{$cardId}->{$targetId}"]);
    }

    public function chooseDefender(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'battle') {
            return Result::error('Сейчас не бой');
        }
        if (empty($this->state->battle['strike'])) {
            return Result::error('Нет сражения');
        }
        if ($this->state->battle['strike']['state'] !== 'waiting_defender') {
            return Result::error('Сейчас не окно защитника');
        }

        $attackerKey = $this->state->getPlayerKeyByUserId(
            $this->state->getCard($this->state->battle['strike']['attacker_id'])->owner === 'host'
                ? $this->state->hostId : $this->state->playerId
        );
        $defenderChooser = $this->state->getOpponentKey($attackerKey);
        if ($playerKey !== $defenderChooser) {
            return Result::error('Сейчас не ваш выбор');
        }

        $defenderId = (int) $cmd->get('defender_id', 0);

        if ($defenderId > 0) {
            $allowed = $this->state->battle['strike']['defenders'] ?? [];
            if (!in_array($defenderId, $allowed, true)) {
                return Result::error('Нельзя выбрать эту карту защитником');
            }
            $this->state->battle['strike']['defender_id'] = $defenderId;

            // Триггер «стал защитником»
            $defender = $this->state->getCard($defenderId);
            if ($defender && !empty($defender->prop['on_become_defender'])) {
                foreach ($defender->prop['on_become_defender'] as $m) {
                    // Спец-эффект: излечить защищаемого (Бьерн)
                    if (($m['type'] ?? '') === 'heal_target') {
                        $targetId = (int) ($this->state->battle['strike']['target_id'] ?? 0);
                        $target = $this->state->getCard($targetId);
                        if ($target && !$target->dying && $target->hp > 0) {
                            $healValue = (int) ($m['value'] ?? 0);
                            $before = $target->hp;
                            $target->hp = min($target->hpMax, $target->hp + $healValue);
                            $healed = $target->hp - $before;
                            if ($healed > 0) {
                                $this->state->battle['strike']['defender_heal'][] = [
                                    'card_id' => $target->instanceId,
                                    'heal'    => $healed,
                                ];
                            }
                        }
                        continue;
                    }

                    // Обычный модификатор (Клаэр)
                    if (empty($m['stat'])) continue;
                    $defender->modifiers[] = [
                        'stat'   => $m['stat'],
                        'value'  => (int) ($m['value'] ?? 1),
                        'expire' => 1,
                        'timing' => 'owner_turn',
                        'source' => $defender->owner,
                    ];
                }
            }
        }

        $this->resolve();
        $this->state->bumpVersion();

        return Result::ok(['defender_chosen']);
    }

    public function chooseRedirect(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'battle') {
            return Result::error('Сейчас не бой');
        }
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || $strike['state'] !== 'waiting_redirect') {
            return Result::error('Сейчас не окно перенаправления');
        }

        $attacker = $this->state->getCard($strike['attacker_id']);
        if (!$attacker) return Result::error('Атакующий не найден');

        $oppKey = $this->state->getOpponentKey($attacker->owner);
        if ($playerKey !== $oppKey) {
            return Result::error('Не ваш выбор');
        }

        $targetId = (int) $cmd->get('target_id', 0);

        if ($targetId > 0) {
            if (!in_array($targetId, $strike['redirect_candidates'] ?? [], true)) {
                return Result::error('Нельзя перенаправить на эту карту');
            }
            $this->state->battle['strike']['target_id'] = $targetId;
        }

        // Перенаправление заменяет выбор защитника — сразу к броску
        $this->state->battle['strike']['defenders'] = [];

        $this->resolve();
        $this->state->bumpVersion();
        return Result::ok(['redirect:' . ($targetId ?: 'skip')]);
    }

    public function resolve(): void
    {
        $strike = &$this->state->battle['strike'];

        $attackerId = $strike['attacker_id'];
        $targetId   = $strike['target_id'];
        $defenderId = $strike['defender_id'];

        $attacker = $this->state->getCard($attackerId);
        $defendCard = $defenderId
            ? $this->state->getCard($defenderId)
            : $this->state->getCard($targetId);

        $attackDice = Dice::roll();

        $noDefendDice = !empty($strike['friendly_fire'])
            || $defendCard->closed
            || CardStats::hasUnanswer($this->state, $attacker, $defendCard);
        $defendDice = $noDefendDice ? 0 : Dice::roll();

        $attackMod = CardStats::getOva($this->state, $attacker, $defendCard) 
             -   CardStats::getClumsyPenalty($attacker);

        $defendMod = $noDefendDice
            ? 0 
            : (CardStats::getOvz($this->state, $defendCard) 
                - CardStats::getClumsyPenalty($defendCard));

        $attackValue = $attackDice + $attackMod;
        $defendValue = $noDefendDice ? 0 : ($defendDice + $defendMod);

        $strike['attack_dice'] = $attackDice;
        $strike['defend_dice'] = $defendDice;
        $strike['attack_mod'] = $attackMod;
        $strike['defend_mod'] = $defendMod;
        $strike['attack_clumsy'] = CardStats::getClumsyPenalty($attacker);
        $strike['defend_clumsy'] = $noDefendDice
            ? 0 
            : CardStats::getClumsyPenalty($defendCard);

        $table = $this->strikeTable($attackValue, $defendValue);
        $strike['result'] = $table;

        // Окно 2 — combat-инстанты
        $attackerKey = $attacker->owner;

        $ip = new InstantProcessor($this->state, $this->engine);
        $ip->openWindow('combat', $attackerKey);

        if (($strike['state'] ?? '') === 'waiting_instant') {
            return;
        }

        // waiting_choice или сразу apply
        if ($table['attack'] !== '' && $table['defend'] !== '') {
            $strike['state'] = 'waiting_choice';
            $strike['choice_winner'] = $table['winner'];
            return;
        }

        $this->apply($table, false);
        if (($strike['state'] ?? '') !== 'waiting_auto_target') {
            $strike['state'] = 'results';
        }
    }

    public function apply(array $table, bool $decrease): void
    {
        $strike = &$this->state->battle['strike'];

        $attacker   = $this->state->getCard($strike['attacker_id']);
        $defenderId = $strike['defender_id'];
        $targetId   = $strike['target_id'];
        $defendCard = $defenderId
            ? $this->state->getCard($defenderId)
            : $this->state->getCard($targetId);

        $attackStrike = $table['attack'];
        $defendStrike = $table['defend'];

        if ($decrease) {
            $attackStrike = $this->decreaseStrike($attackStrike);
            $defendStrike = $this->decreaseStrike($defendStrike);
        }

        // Сведение ударов (Степной волколак)
        $strikeReduction = $defendCard->prop['strike_reduction'] ?? null;
        if (is_array($strikeReduction) && isset($strikeReduction[$attackStrike])) {
            $attackStrike = $strikeReduction[$attackStrike];
        }

        $strike['final'] = [
            'attack'    => $attackStrike,
            'defend'    => $defendStrike,
            'decreased' => $decrease,
        ];

        // Эффекты от удара (включая промах)
        $this->engine->applyStrikeEffects($attacker, $defendCard, $attackStrike);

        if ($attackStrike !== '') {
            if (!empty($attacker->prop['copy_target_strike'])) {
                $val = match ($attackStrike) {
                    'weak'   => $defendCard->strikeWeak,
                    'medium' => $defendCard->strikeMedium,
                    'strong' => $defendCard->strikeStrong,
                    default  => 0,
                };
            } else {
                $val = match ($attackStrike) {
                    'weak'   => $attacker->strikeWeak,
                    'medium' => $attacker->strikeMedium,
                    'strong' => $attacker->strikeStrong,
                    default  => 0,
                };
            }
            $abilityBonus = CardStats::getAbilityBonus($this->state, $attacker, $defendCard, 'strike', $attackStrike);
            $val += $abilityBonus;
            $nextStrikeBonus = CardStats::getNextStrikeBonus($attacker);
            $val += $nextStrikeBonus;
            $reduction = CardStats::getDamageReduction($this->state, $attacker, $defendCard, 'strike');
            $val -= $reduction;
            if ($val < 0) $val = 0;
            if (!empty($strike['damage_cap'])) {
                $val = min($val, (int) $strike['damage_cap']);
            }

            $attackReduction = $this->engine->reduceAttackValueByCellMarkers($this->state, $attacker, $val);
            $val = (int) $attackReduction['value'];
            foreach ($attackReduction['events'] as $event) {
                $this->state->battle['strike']['attack_value_reduction'][] = $event;
            }

            $hitWasBlocked = false;

            // Защита цели
            if (CardStats::hasDefense($this->state, $defendCard, 'strike', $attacker)) {
                $val = 0;
                $hitWasBlocked = true;
            }

            if ($attackStrike === 'weak' && !empty($defendCard->prop['block_weak_strike'])) {
                $val = 0;
                $hitWasBlocked = true;
                $this->state->battle['strike']['blocked_by_weak'] = true;
            }

            $hpBeforeStrikeDamage = $defendCard->hp;
            $this->engine->applyDamage($this->state, $defendCard, $val, 'strike', $attacker);
            $woundsDealt = max(0, $hpBeforeStrikeDamage - $defendCard->hp);
            $this->state->battle['strike']['damage_total'] = $val;
            $this->applyStrongStrikeFollowUpEffects($attacker, $defendCard, $attackStrike);

            // Наложение маркера при попадании (Владыка небес)
            if ($val > 0 && !empty($attacker->prop['on_hit_marker'])) {
                $this->engine->applyMarker($defendCard, $attacker->prop['on_hit_marker'], $attacker->owner);
            }
            $this->state->battle['strike']['attack_reduction'] = $reduction;
            if ($abilityBonus > 0) {
                $this->state->battle['strike']['ability_bonus'] = $abilityBonus;
            }
            if ($nextStrikeBonus > 0) {
                $this->state->battle['strike']['next_strike_bonus'] = $nextStrikeBonus;
                $this->consumeNextStrikeBonuses($attacker);
            }

            $this->triggerStrikeProphecy($attacker, $defendCard);
            $this->engine->applyAnswer($this->state, $defendCard, $attacker, 'strike');
            $this->state->battle['strike']['strike_hit'] = true;
            if ($woundsDealt > 0) {
                $actionResolver = new ActionResolver($this->state, $this->engine);
                if ($attackStrike === 'strong') {
                    $actionResolver->triggerTalionIncarnationToken($attacker);
                }
                $actionResolver->triggerLineOpenOnStrike($attacker);
            }
            if (!$hitWasBlocked) {
                (new ActionResolver($this->state, $this->engine))->openSuccessfulHitOptionalHeal($attacker);
            }

            // Реакция цели на попадание
            if ($val > 0 && !empty($defendCard->prop['on_hit_gain'])) {
                foreach ($defendCard->prop['on_hit_gain'] as $m) {
                    $defendCard->modifiers[] = [
                        'stat'   => $m['stat'],
                        'value'  => (int) ($m['value'] ?? 1),
                        'expire' => $m['expire'] ?? 'end_of_turn',
                        'source' => $defendCard->owner,
                    ];
                }
            }
        } else {
            $this->state->battle['strike']['strike_hit'] = false;
        }
        if ($defendStrike !== '') {
            $val = match ($defendStrike) {
                'weak'   => $defendCard->strikeWeak,
                'medium' => $defendCard->strikeMedium,
                'strong' => $defendCard->strikeStrong,
                default  => 0,
            };
            if (!empty($strike['damage_cap'])) {
                $val = min($val, (int) $strike['damage_cap']);
            }

            // Бонус от способности защитника (Санкторум: +1 к слабому)
            $defAbilityBonus = CardStats::getAbilityBonus(
                $this->state, $defendCard, $attacker, 'strike', $defendStrike
            );
            $val += $defAbilityBonus;

            // Снижение у атакующего (Воин -1 от степных)
            $defReduction = CardStats::getDamageReduction(
                $this->state, $defendCard, $attacker, 'strike'
            );
            if ($defReduction > 0) {
                $this->state->battle['strike']['defend_reduction'] = $defReduction;
            }
            $val -= $defReduction;
            if ($val < 0) $val = 0;

            if (CardStats::hasDefense($this->state, $attacker, 'strike', $defendCard)) {
                $val = 0;
            }
            if ($defendStrike === 'weak' && !empty($attacker->prop['block_weak_strike'])) {
                $val = 0;
                $this->state->battle['strike']['defend_blocked_by_weak'] = true;
            }

            $hpBeforeAnswerDamage = $attacker->hp;
            $blockedAnswersBefore = count((array) ($this->state->battle['strike']['answer_blocked'] ?? []));
            $this->engine->applyDamage($this->state, $attacker, $val, 'answer', $defendCard);
            $blockedAnswersAfter = count((array) ($this->state->battle['strike']['answer_blocked'] ?? []));
            if ($blockedAnswersAfter > $blockedAnswersBefore) {
                $val = 0;
            }
            $answerWoundsDealt = max(0, $hpBeforeAnswerDamage - $attacker->hp);
            $this->state->battle['strike']['defend_damage_total'] = $val;

            if ($answerWoundsDealt > 0) {
                (new ActionResolver($this->state, $this->engine))
                    ->triggerLineOpenOnStrike($defendCard);
            }

            if ($val > 0 && !empty($attacker->prop['on_hit_gain'])) {
                foreach ($attacker->prop['on_hit_gain'] as $m) {
                    $attacker->modifiers[] = [
                        'stat'   => $m['stat'],
                        'value'  => (int) ($m['value'] ?? 1),
                        'expire' => $m['expire'] ?? 'end_of_turn',
                        'source' => $attacker->owner,
                    ];
                }
            }
        }

        if ($defendCard->type === 'fly'
            && $defendCard->zone === CardInstance::ZONE_FIELD
            && $defendCard->hp > 0) {
            $this->engine->moveToFlyingZone($this->state, $defendCard);
        }

        $attacker->flags['attacks_used_this_turn'] = 
            ((int) ($attacker->flags['attacks_used_this_turn'] ?? 0)) + 1;

        if (!empty($attacker->prop['strike_targets_unique']) 
            && empty($attacker->flags['first_attack_target_id'])) {
            $attacker->flags['first_attack_target_id'] = (int) ($strike['target_id'] ?? 0);
        }
        
        $attackLimit = (int) ($attacker->prop['attacks_per_turn'] ?? 1);
        $noClose     = !empty($attacker->prop['no_close_after_attack'])
            || !empty($attacker->flags['no_close_this_turn']);

        if ($attacker->flags['attacks_used_this_turn'] >= $attackLimit && !$noClose) {
            $attacker->closed = true;
        }

        $redirectUsed = !empty($strike['redirect_used']);
        $staysOpen    = !empty($defendCard->prop['defender']['all']);

        if ($defenderId && !$redirectUsed && !$staysOpen) {
            $defendCard->closed = true;
        }

        // Сброс зарядов от пророчества после удара
        $attacker->modifiers = array_values(array_filter(
            $attacker->modifiers,
            fn($m) => ($m['source'] ?? '') !== 'prophecy_charge'
        ));


        $this->engine->flushDeadeatQueue($this->state);
        $primaryDamage = (int) ($this->state->battle['strike']['damage_total'] ?? 0);
        $answerDamage = (int) ($this->state->battle['strike']['defend_damage_total'] ?? 0);
        $targetDamageThisStrike = (int) ($defendCard->flags['damage_taken_this_strike'] ?? 0);
        $attackerDamageThisStrike = (int) ($attacker->flags['damage_taken_this_strike'] ?? 0);
        $this->state->battle['strike']['combat_damage_summary'] = [
            'target_id' => $defendCard->instanceId,
            'attacker_id' => $attacker->instanceId,
            'primary_damage' => $primaryDamage,
            'target_total_this_strike' => $targetDamageThisStrike,
            'target_extra_this_strike' => max(0, $targetDamageThisStrike - $primaryDamage),
            'answer_damage' => $answerDamage,
            'attacker_total_this_strike' => $attackerDamageThisStrike,
            'attacker_extra_this_strike' => max(0, $attackerDamageThisStrike - $answerDamage),
        ];
        $this->state->battle['strike']['damage_applied'] = true;
    }

    private function applyStrongStrikeFollowUpEffects(
        CardInstance $attacker,
        CardInstance $target,
        string $attackStrike
    ): void {
        foreach ($attacker->prop['strike_effects'] ?? [] as $effect) {
            if (empty($effect['wound_target_by_behind_weak_strike'])) continue;

            $levels = $effect['levels'] ?? ['strong'];
            if (!in_array($attackStrike, $levels, true)) continue;

            $behind = $this->getBehindStrikeTargetCard($attacker, $target);
            if ($behind === null) continue;

            $damage = CardStats::getStrikeValue($this->state, $behind, $target, 'weak');
            if ($damage <= 0) continue;

            $this->engine->applyDamage($this->state, $target, $damage, 'impact', $attacker);
            $this->state->battle['strike']['behind_weak_strike_damage'] = [
                'source_id' => $behind->instanceId,
                'target_id' => $target->instanceId,
                'damage' => $damage,
            ];
        }
    }

    private function getBehindStrikeTargetCard(CardInstance $attacker, CardInstance $target): ?CardInstance
    {
        if ($attacker->row === null || $attacker->col === null
            || $target->row === null || $target->col === null) {
            return null;
        }

        $dRow = ($target->row - $attacker->row) <=> 0;
        $dCol = ($target->col - $attacker->col) <=> 0;
        if ($dRow === 0 && $dCol === 0) {
            return null;
        }

        return (new ZoneManager($this->state))->getFieldCard(
            $target->row + $dRow,
            $target->col + $dCol
        );
    }

    private function consumeNextStrikeBonuses(CardInstance $card): void
    {
        $card->modifiers = array_values(array_filter(
            $card->modifiers,
            fn($modifier) => ($modifier['stat'] ?? '') !== 'next_strike_bonus'
        ));
    }

    public function chooseStrikeMode(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'battle') {
            return Result::error('Сейчас не бой');
        }
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || $strike['state'] !== 'waiting_choice') {
            return Result::error('Сейчас нет выбора');
        }

        $attacker = $this->state->getCard($strike['attacker_id']);
        $attackerKey = $attacker->owner;
        $winnerKey = $strike['choice_winner'] === 'attack'
            ? $attackerKey
            : $this->state->getOpponentKey($attackerKey);

        if ($playerKey !== $winnerKey) {
            return Result::error('Сейчас не ваш выбор');
        }

        $mode = (string) $cmd->get('mode', 'normal');
        if (!in_array($mode, ['normal', 'decrease'], true)) {
            return Result::error('Неверный режим');
        }

        $this->apply($strike['result'], $mode === 'decrease');
        if (($this->state->battle['strike']['state'] ?? '') !== 'waiting_auto_target') {
            $this->state->battle['strike']['state'] = 'results';
        }
        $this->state->bumpVersion();

        return Result::ok(["strike_mode:{$mode}"]);
    }

    public function confirmStrike(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'battle') {
            return Result::error('Сейчас не бой');
        }

        if (empty($this->state->battle['strike']) || $this->state->battle['strike']['state'] !== 'results') {
            // пропускаем waiting_push_ack — идём ниже
            $st = $this->state->battle['strike']['state'] ?? null;
            if ($st !== 'waiting_push_ack') {
                return Result::error('Нечего подтверждать');
            }
        }

        // Закрытие после push_ack
        if (($this->state->battle['strike']['state'] ?? '') === 'waiting_push_ack') {
            $confirmed = $this->state->battle['strike']['confirmed'] ?? [];
            if (in_array($playerKey, $confirmed, true)) {
                return Result::error('Уже подтверждено');
            }
            $confirmed[] = $playerKey;
            $this->state->battle['strike']['confirmed'] = $confirmed;

            if (count($confirmed) >= 2) {
                $this->state->battle['strike'] = null;
                $this->engine->finalizeDying($this->state);
            }

            $this->state->bumpVersion();
            return Result::ok(['push_ack_confirmed']);
        }
        
        if (empty($this->state->battle['strike']) || $this->state->battle['strike']['state'] !== 'results') {
            return Result::error('Нечего подтверждать');
        }

        $confirmed = $this->state->battle['strike']['confirmed'] ?? [];
        if (in_array($playerKey, $confirmed, true)) {
            return Result::error('Уже подтверждено');
        }
        $confirmed[] = $playerKey;
        $this->state->battle['strike']['confirmed'] = $confirmed;

        if (count($confirmed) >= 2) {
            if (!empty($this->state->battle['strike']['pending_wounds_resolution'])) {
                $this->state->battle['strike']['wounds_after_result_ack'] = true;
                (new InstantProcessor($this->state, $this->engine))->resumeCombatWounds();
                $this->state->bumpVersion();
                return Result::ok(['wounds_resolution_resumed']);
            }

            $continuation = $this->continueAfterStrikeResultAck($this->state->battle['strike']);
            if ($continuation instanceof Result) {
                return $continuation;
            }
        }

        $this->state->bumpVersion();
        return Result::ok(['strike_confirmed']);
    }

    public function continueAfterStrikeResultAck(array $strike): ?Result
    {
        // Auto — только если ещё не сработал
        if (empty($strike['auto_effects'])) {
            $attacker = $this->state->getCard($strike['attacker_id']);
            if ($attacker && $this->engine->openAutoChoice($this->state, $attacker)) {
                $this->state->bumpVersion();
                return Result::ok(['auto_pending']);
            }
        }

        if (!empty($strike['strike_hit']) && empty($strike['ally_modifier_result'])) {
            if ($this->openGrantAllyModifier()) {
                $this->state->bumpVersion();
                return Result::ok(['ally_modifier_pending']);
            }
        }

        // Close or damage — только если ещё не сработал
        if (!empty($strike['strike_hit']) && empty($strike['close_or_damage_result'])) {
            if ($this->openCloseOrDamageChoice()) {
                $this->state->bumpVersion();
                return Result::ok(['close_or_damage_pending']);
            }
        }

        // Push — только если ещё не сработал
        if (!empty($strike['strike_hit']) && empty($strike['push_result'])) {
            if ($this->openPushChoice()) {
                $this->state->bumpVersion();
                return Result::ok(['push_pending']);
            }
        }

        // Окно turn-инстантов после удара (окно 3)
        $attacker = $this->state->getCard($strike['attacker_id']);
        $attackerKey = $attacker ? $attacker->owner : null;

        $phase = $strike['instant_phase'] ?? null;
        $alreadyAfter = ($phase === 'after');

        if (!$alreadyAfter && $attackerKey) {
            $ip = new InstantProcessor($this->state, $this->engine);
            $ip->openWindow('after', $attackerKey);

            if (($this->state->battle['strike']['state'] ?? '') === 'waiting_instant') {
                $this->state->battle['strike']['confirmed'] = [];
                $this->state->bumpVersion();
                return Result::ok(['instant_after_opened']);
            }
        }

        // Закрываем сражение перед возможным обязательным добиванием после удара.
        $this->state->battle['strike'] = null;
        $this->engine->finalizeDying($this->state);

        if ($this->openAfterStrikeExecute($strike)) {
            $this->state->bumpVersion();
            return Result::ok(['after_strike_execute']);
        }

        return null;
    }

    public function chooseAfterStrikeExecute(string $playerKey, Command $cmd): Result
    {
        $pending = $this->state->battle['pending_after_strike_execute'] ?? null;
        if (!$pending || ($pending['owner'] ?? null) !== $playerKey) {
            return Result::error('Нет выбора добивания');
        }

        $source = $this->state->getCard((int) ($pending['source_id'] ?? 0));
        if (!$source) {
            unset($this->state->battle['pending_after_strike_execute']);
            return Result::error('Источник добивания не найден');
        }

        $targetId = (int) $cmd->get('target_id', 0);
        $candidates = array_map('intval', (array) ($pending['candidate_ids'] ?? []));
        if (!in_array($targetId, $candidates, true)) {
            return Result::error('Нельзя выбрать эту цель');
        }

        $value = (int) ($pending['value'] ?? 0);
        $target = $this->state->getCard($targetId);
        if (!$target || !CardStats::canExecuteTarget($this->state, $source, $target, $value)) {
            unset($this->state->battle['pending_after_strike_execute']);
            return Result::error('Цель добивания больше недоступна');
        }

        unset($this->state->battle['pending_after_strike_execute']);
        $this->markAfterStrikeExecuteUsed($source);

        $result = (new ActionResolver($this->state, $this->engine))->executeForced(
            $playerKey,
            $source->instanceId,
            $targetId,
            $value,
            ($pending['source_name'] ?? 'Карта') . ': добивание'
        );

        $this->state->bumpVersion();
        return $result;
    }

    private function openAfterStrikeExecute(array $strike): bool
    {
        if (empty($strike['strike_hit'])) return false;
        if (($strike['final']['attack'] ?? '') === '') return false;

        $source = $this->state->getCard((int) ($strike['attacker_id'] ?? 0));
        if (!$source) return false;
        if (!array_key_exists('execute', $source->prop)) return false;

        $value = CardStats::getStat($source, 'execute');
        if ($value <= 0) return false;

        $limit = (int) ($source->prop['after_strike_execute_limit'] ?? 2);
        if ($limit > 0 && (int) ($source->flags['after_strike_execute_used_this_turn'] ?? 0) >= $limit) {
            return false;
        }

        $targets = CardStats::findExecuteTargets($this->state, $source, $value);
        if (empty($targets)) return false;

        if (count($targets) === 1) {
            $this->markAfterStrikeExecuteUsed($source);
            (new ActionResolver($this->state, $this->engine))->executeForced(
                $source->owner,
                $source->instanceId,
                $targets[0]->instanceId,
                $value,
                'Добивание'
            );
            return true;
        }

        $this->state->battle['pending_after_strike_execute'] = [
            'owner' => $source->owner,
            'source_id' => $source->instanceId,
            'source_ukid' => $source->ukid,
            'value' => $value,
            'candidate_ids' => array_map(fn(CardInstance $target) => $target->instanceId, $targets),
        ];
        return true;
    }

    private function markAfterStrikeExecuteUsed(CardInstance $source): void
    {
        $source->flags['after_strike_execute_used_this_turn'] =
            ((int) ($source->flags['after_strike_execute_used_this_turn'] ?? 0)) + 1;
    }

    private function hasAnyStrike(CardInstance $card): bool
    {
        return $card->strikeWeak > 0
            || $card->strikeMedium > 0
            || $card->strikeStrong > 0;
    }

    public function openPushChoice(): bool
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike) return false;

        $defendCardId = $strike['defender_id'] ?? $strike['target_id'];
        $defendCard = $this->state->getCard($defendCardId);
        if (!$defendCard) return false;

        $attacker = $this->state->getCard($strike['attacker_id']);
        if (!$attacker) return false;

        $push = null;
        foreach ($attacker->prop['strike_effects'] ?? [] as $eff) {
            if (isset($eff['push'])) {
                $levels = $eff['levels'] ?? null;
                $attackLevel = $strike['final']['attack'] ?? '';
                if ($levels !== null && !in_array($attackLevel, $levels, true)) continue;
                $push = $eff;
                break;
            }
        }
        if (!$push) return false;

        // Направление удара
        $dy = $defendCard->row - $attacker->row;
        $dx = $defendCard->col - $attacker->col;
        $dy = $dy <=> 0;
        $dx = $dx <=> 0;

        $newRow = $defendCard->row + $dy;
        $newCol = $defendCard->col + $dx;

        $canMove = true;
        if ($newRow < 1 || $newRow > 6 || $newCol < 1 || $newCol > 5) {
            $canMove = false;
        }
        if ($canMove) {
            foreach ($this->state->cards as $c) {
                if ($c->zone !== CardInstance::ZONE_FIELD) continue;
                if ($c->row === $newRow && $c->col === $newCol) {
                    $canMove = false;
                    break;
                }
            }
        }

        // Нельзя двигаться — сразу +2 урона
        if (!$canMove) {
            $dmg = (int) ($push['push'] ?? 2);
            $this->engine->applyDamage($this->state, $defendCard, $dmg, 'strike');
            $this->state->battle['strike']['push_result'] = [
                'type'   => 'forced_damage',
                'damage' => $dmg,
                'reason' => 'no_space',
            ];
            $this->state->battle['strike']['state'] = 'waiting_push_ack';
            $this->state->battle['strike']['confirmed'] = [];
            return true;   // ← было false
        }

        $this->state->battle['strike']['pending_push'] = [
            'defender_id' => $defendCard->instanceId,
            'new_row'     => $newRow,
            'new_col'     => $newCol,
            'damage'      => (int) ($push['push'] ?? 2),
        ];
        $this->state->battle['strike']['state'] = 'waiting_push_choice';
        $this->state->battle['strike']['confirmed'] = [];
        return true;
    }

    public function choosePushChoice(string $playerKey, Command $cmd): Result
    {
        if (empty($this->state->battle['strike'])
            || $this->state->battle['strike']['state'] !== 'waiting_push_choice') {
            return Result::error('Сейчас нет выбора push');
        }

        $pp = $this->state->battle['strike']['pending_push'];
        $defendCard = $this->state->getCard($pp['defender_id']);
        if (!$defendCard || $defendCard->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $choice = (string) $cmd->get('choice', '');
        if (!in_array($choice, ['move', 'damage'], true)) {
            return Result::error('Неверный выбор');
        }

        if ($choice === 'move') {
            $defendCard->row = $pp['new_row'];
            $defendCard->col = $pp['new_col'];

            if (!empty($defendCard->prop['lose_coins_on_move']) && $defendCard->coins > 0) {
                $defendCard->coins = 0;
                $this->engine->syncCoinBonus($defendCard);
            }
            
            $this->state->battle['strike']['push_result'] = [
                'type'    => 'moved',
                'new_row' => $pp['new_row'],
                'new_col' => $pp['new_col'],
            ];
        } else {
            $dmg = (int) $pp['damage'];
            $this->engine->applyDamage($this->state, $defendCard, $dmg, 'strike');

            $this->state->battle['strike']['push_result'] = [
                'type'   => 'damage',
                'damage' => $dmg,
            ];
        }

        unset($this->state->battle['strike']['pending_push']);
        $this->state->battle['strike']['state'] = 'results';
        $this->state->battle['strike']['confirmed'] = [];

        $this->state->bumpVersion();
        return Result::ok(["push_choice:{$choice}"]);
    }

    private function triggerStrikeProphecy(CardInstance $attacker, CardInstance $target): void
    {
        $config = $attacker->prop['strike_prophecy'] ?? null;
        if (!$config) return;
        if (!empty($this->state->battle['pending_prophecy'])) return;

        $count = (int) ($config['count'] ?? 2);
        if ($count <= 0) return;

        $pp     = new ProphecyProcessor($this->state, $this->engine);
        $peeked = $pp->peek($attacker->owner, $count);
        if ($peeked === null) return;
        if (count($peeked['cards']) < $count) return;

        $meta = $peeked['meta'];

        if ($meta['all_elite'] && !empty($config['all_elite']['damage'])) {
            $dmg = (int) $config['all_elite']['damage'];
            $this->engine->applyDamage($this->state, $target, $dmg, 'strike', $attacker);
            $title = 'обе элитные — ' . $target->ukid . ' получает +' . $dmg . ' урона';
        } elseif ($meta['all_ordinary'] && !empty($config['all_ordinary']['poison'])) {
            $poison = (int) $config['all_ordinary']['poison'];
            $this->engine->applyPoison($target, $poison, $attacker->owner);
            $title = 'обе рядовые — отравление ' . $poison;
        } else {
            $title = 'пророчество не сработало';
        }

        $actions = [['label' => 'Закрыть', 'cmd' => 'close_prophecy', 'class' => 'skip']];

        $pp->commit($attacker->owner, $attacker, $peeked, 'strike', $title, $actions);
    }

    public function recalcTable(): void
    {
        $strike = &$this->state->battle['strike'];

        // Если result уже был изменён strike_level — не пересчитываем
        if (!empty($strike['level_overridden'])) return;

        $attacker = $this->state->getCard($strike['attacker_id']);
        $defenderId = $strike['defender_id'];
        $defendCard = $defenderId
            ? $this->state->getCard($defenderId)
            : $this->state->getCard($strike['target_id']);

        $attackValue = (int) ($strike['attack_dice'] ?? 0) + (int) ($strike['attack_mod'] ?? 0);
        $defendValue = $defendCard->closed
            ? 0
            : (int) ($strike['defend_dice'] ?? 0) + (int) ($strike['defend_mod'] ?? 0);

        $table = $this->strikeTable($attackValue, $defendValue);
        $strike['result'] = $table;

        // После пересчёта — сброс final
        unset($strike['final']);
    }

    public function getCombatInstants(string $ownerKey, string $phase = 'before', string $type = 'turn'): array
    {
        return (new InstantProcessor($this->state, $this->engine))->getInstants($ownerKey, $phase, $type);
    }

    public function passCombatInstant(string $playerKey): Result
    {
        return (new InstantProcessor($this->state, $this->engine))->passCombat($playerKey);
    }

    public function resolveInstantStack(): void
    {
        (new InstantProcessor($this->state, $this->engine))->resolveStack();
    }
    
    public function openCloseOrDamageChoice(): bool
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike) return false;

        $attacker = $this->state->getCard($strike['attacker_id']);
        if (!$attacker) return false;

        $defendCardId = $strike['defender_id'] ?? $strike['target_id'];
        $defendCard   = $this->state->getCard($defendCardId);
        if (!$defendCard || $defendCard->dying || $defendCard->hp <= 0) return false;

        $eff = null;
        foreach ($attacker->prop['strike_effects'] ?? [] as $e) {
            if (!isset($e['close_or_damage'])) continue;

            $levels      = $e['levels'] ?? null;
            $attackLevel = $strike['final']['attack'] ?? '';
            if ($levels !== null && !in_array($attackLevel, $levels, true)) continue;

            $eff = $e;
            break;
        }
        if (!$eff) return false;

        $config = $eff['close_or_damage'];
        $cond   = $config['condition'] ?? null;

        // target_open — срабатывает только если цель открыта
        if ($cond === 'target_open' && $defendCard->closed) return false;

        $damage = (int) ($config['damage'] ?? 2);

        $this->state->battle['strike']['pending_close_or_damage'] = [
            'defender_id' => $defendCard->instanceId,
            'damage'      => $damage,
        ];
        $this->state->battle['strike']['state']    = 'waiting_close_or_damage';
        $this->state->battle['strike']['confirmed'] = [];
        return true;
    }
    
    public function chooseCloseOrDamage(string $playerKey, Command $cmd): Result
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || ($strike['state'] ?? '') !== 'waiting_close_or_damage') {
            return Result::error('Сейчас нет выбора');
        }

        $pcod    = $strike['pending_close_or_damage'];
        $defCard = $this->state->getCard($pcod['defender_id']);
        if (!$defCard || $defCard->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $choice = (string) $cmd->get('choice', '');
        if (!in_array($choice, ['close', 'damage'], true)) {
            return Result::error('Неверный выбор');
        }

        if ($choice === 'close') {
            $defCard->closed = true;
            $this->state->battle['strike']['close_or_damage_result'] = ['type' => 'closed'];
        } else {
            $dmg = (int) $pcod['damage'];
            $this->engine->applyDamage($this->state, $defCard, $dmg, 'impact');
            $this->state->battle['strike']['close_or_damage_result'] = [
                'type'   => 'damage',
                'damage' => $dmg,
            ];
        }

        unset($this->state->battle['strike']['pending_close_or_damage']);
        $this->state->battle['strike']['state']    = 'results';
        $this->state->battle['strike']['confirmed'] = [];

        $this->state->bumpVersion();
        return Result::ok(['close_or_damage:' . $choice]);
    }

    public function openGrantAllyModifier(): bool
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike) return false;

        $attacker = $this->state->getCard($strike['attacker_id']);
        if (!$attacker) return false;

        $eff = null;
        foreach ($attacker->prop['strike_effects'] ?? [] as $e) {
            if (!isset($e['grant_ally_modifier'])) continue;
            $levels      = $e['levels'] ?? null;
            $attackLevel = $strike['final']['attack'] ?? '';
            if ($levels !== null && !in_array($attackLevel, $levels, true)) continue;
            $eff = $e;
            break;
        }
        if (!$eff) return false;

        $config    = $eff['grant_ally_modifier'];
        $filter    = $config['filter'] ?? 'has_magic';
        $ownerKey  = $attacker->owner;

        $candidates = [];
        foreach ($this->state->cards as $c) {
            if ($c->owner !== $ownerKey) continue;
            if ($c->instanceId === $attacker->instanceId) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;

            if ($filter === 'has_magic') {
                $hasMagic = false;
                foreach ($c->prop['actions'] ?? [] as $a) {
                    $t = $a['type'] ?? '';
                    if (in_array($t, ['discharge', 'magic', 'cast'], true)) {
                        $hasMagic = true;
                        break;
                    }
                }
                if (!$hasMagic) continue;
            }

            $candidates[] = $c->instanceId;
        }

        if (empty($candidates)) return false;

        $this->state->battle['strike']['pending_ally_modifier'] = [
            'source_id'  => $attacker->instanceId,
            'candidates' => $candidates,
            'modifier'   => $config['modifier'] ?? [],
        ];
        $this->state->battle['strike']['state']     = 'waiting_ally_modifier';
        $this->state->battle['strike']['confirmed'] = [];
        return true;
    }

    public function chooseAllyModifier(string $playerKey, Command $cmd): Result
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || ($strike['state'] ?? '') !== 'waiting_ally_modifier') {
            return Result::error('Сейчас нет выбора');
        }

        $pam    = $strike['pending_ally_modifier'];
        $source = $this->state->getCard($pam['source_id']);
        if (!$source || $source->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $targetId = (int) $cmd->get('target_id', 0);
        if (!in_array($targetId, $pam['candidates'], true)) {
            return Result::error('Неверная цель');
        }

        $target = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');

        $m = $pam['modifier'];
        $target->modifiers[] = [
            'stat'   => $m['stat'] ?? 'damage_reduction',
            'value'  => (int) ($m['value'] ?? 1),
            'types'  => $m['types'] ?? null,
            'expire' => $m['expire'] ?? 'end_of_opponent_turn',
            'source' => $playerKey,
        ];

        $this->state->battle['strike']['ally_modifier_result'] = [
            'target_id' => $targetId,
            'stat'      => $m['stat'] ?? 'damage_reduction',
            'value'     => (int) ($m['value'] ?? 1),
        ];

        unset($this->state->battle['strike']['pending_ally_modifier']);
        $this->state->battle['strike']['state']     = 'results';
        $this->state->battle['strike']['confirmed'] = [];

        $this->state->bumpVersion();
        return Result::ok(['ally_modifier:' . $targetId]);
    }
}
