<?php
// src/Core/BattleHelper.php

declare(strict_types=1);

namespace Berserk\Core;

final class BattleHelper
{
    public static function hasAnyStrike(CardInstance $card): bool
    {
        return CardStats::hasAnyStrike($card);
    }

    public static function decreaseStrike(string $s): string
    {
        return match ($s) {
            'weak'   => '',
            'medium' => 'weak',
            'strong' => 'medium',
            default  => '',
        };
    }

    public static function diceToLevel(int $dice): string
    {
        if ($dice <= 3) return 'weak';
        if ($dice <= 5) return 'medium';
        return 'strong';
    }

    public static function strikeName(string $level): string
    {
        return match ($level) {
            'weak'   => 'слабый',
            'medium' => 'средний',
            'strong' => 'сильный',
            default  => 'промах',
        };
    }

    /**
     * Что карта умеет из действий.
     * @return array{has_strike:bool, has_uchr:bool}
     */
    public static function abilities(CardInstance $card): array
    {
        $hasUchr = false;
        foreach ($card->prop['actions'] ?? [] as $a) {
            if (($a['type'] ?? '') === 'uchr') { $hasUchr = true; break; }
        }
        return [
            'has_strike' => self::hasAnyStrike($card),
            'has_uchr'   => $hasUchr,
        ];
    }

    /**
     * Доступные клетки для движения выбранной карты.
     * Возвращает ['r_c' => true, ...].
     */
    public static function getMoveCells(GameState $state, CardInstance $card): array
    {
        if (CardStats::isDisabled($card) || $card->move <= 0 || isset($card->markers['rooted'])) {
            return [];
        }

        if (self::movesLimitExhausted($state, $card)) {
            return [];
        }

        $occupied = [];
        foreach ($state->cards as $c) {
            if ($c->zone === CardInstance::ZONE_FIELD) {
                $occupied["{$c->row}_{$c->col}"] = true;
            }
        }

        $dirs = [[-1, 0], [1, 0], [0, -1], [0, 1]];
        if (!empty($card->prop['can_move_diagonal'])) {
            $dirs = array_merge($dirs, [[-1, -1], [-1, 1], [1, -1], [1, 1]]);
        }

        $forced = (!empty($card->prop['forced_strike']))
            ? CardStats::getForcedStrikeTarget($state, $card)
            : null;

        $result = [];
        foreach ($dirs as [$dr, $dc]) {
            $r  = $card->row + $dr;
            $cc = $card->col + $dc;
            if ($r < 1 || $r > 6 || $cc < 1 || $cc > 5) continue;
            if (ZoneManager::hasBlockingMarker($state, "{$r}_{$cc}")) continue;
            if (isset($occupied["{$r}_{$cc}"])) continue;

             // Басаарг: нельзя уходить от обязательной цели
            if ($forced !== null) {
                $oldRow = $card->row;
                $oldCol = $card->col;

                $card->row = $r;
                $card->col = $cc;
                $stillReachable = CardStats::getForcedStrikeTarget($state, $card);
                $card->row = $oldRow;
                $card->col = $oldCol;

                if ($stillReachable === null) continue;
            }
            
            $result["{$r}_{$cc}"] = true;
        }

        // Возница: ход между крайними клетками ряда за 1 move
        if (!empty($card->prop['row_extreme'])
            && ($card->col === 1 || $card->col === 5)) {

            $targetCol = ($card->col === 1) ? 5 : 1;
            $key       = "{$card->row}_{$targetCol}";

            if (!ZoneManager::hasBlockingMarker($state, $key) && !isset($occupied[$key])) {
                $result[$key] = true;
            }
        }

        return $result;
    }

    public static function getJumpCells(GameState $state, CardInstance $card): array
    {
        if (CardStats::isDisabled($card) || !empty($card->flags['moved_this_turn']) || isset($card->markers['rooted'])) {
            return [];
        }

        if (self::movesLimitExhausted($state, $card)) {
            return [];
        }

        $jumpAction = null;
        foreach ($card->prop['actions'] ?? [] as $a) {
            if (($a['type'] ?? '') === 'jump') {
                $jumpAction = $a;
                break;
            }
        }
        if (!$jumpAction) return [];

        $range = (int) ($jumpAction['range'] ?? 0);
        if ($range <= 0) return [];

        $occupied = [];
        foreach ($state->cards as $c) {
            if ($c->zone === CardInstance::ZONE_FIELD) {
                $occupied["{$c->row}_{$c->col}"] = true;
            }
        }

        $result = [];
        for ($r = 1; $r <= 6; $r++) {
            for ($c = 1; $c <= 5; $c++) {
                if ($r === $card->row && $c === $card->col) continue;
                if (ZoneManager::hasBlockingMarker($state, "{$r}_{$c}")) continue;
                if (isset($occupied["{$r}_{$c}"])) continue;

                $dist = abs($r - $card->row) + abs($c - $card->col);
                if ($dist > $range) continue;

                $result["{$r}_{$c}"] = true;
            }
        }
        return $result;
    }

    /**
     * Возможные цели для выбранного действия.
     * Возвращает [instance_id => true, ...].
     */
    public static function getAttackTargets(
        GameState $state,
        CardInstance $card,
        string $mode,
        string $playerKey,
        bool $allowFriendlyFire = false,
    ): array {
        if (CardStats::isDisabled($card)) {
            return [];
        }
        if (!str_starts_with($mode, 'action:') && CardStats::hasCannotAttack($card)) {
            return [];
        }

        $attackLimit = (int) ($card->prop['attacks_per_turn'] ?? 1);

        if (!empty($card->prop['strike_consecutive'])
            && !empty($card->flags['strike_chain_broken'])) {
            $attackLimit = 1;
        }

        $attacksUsed = (int) ($card->flags['attacks_used_this_turn'] ?? 0);
        if ($attacksUsed >= $attackLimit) return [];

        $firstTargetId = 0;
        if (!empty($card->prop['strike_targets_unique']) && $attacksUsed > 0) {
            $firstTargetId = (int) ($card->flags['first_attack_target_id'] ?? 0);
        }
        
        $result = [];
        $allowFriendlyFire = $allowFriendlyFire && CardStats::canFriendlyFireMode($card, $mode);

        if (str_starts_with($mode, 'action:')) {
            $actionKey = substr($mode, 7);
            $action = null;
            foreach ($card->prop['actions'] ?? [] as $a) {
                $key = $a['key'] ?? $a['type'] ?? '';
                if ($key === $actionKey) {
                    $action = $a;
                    break;
                }
            }
            if (!$action) return [];

            $type = $action['type'] ?? '';
            $allowFriendlyAction = $allowFriendlyFire && CardStats::canFriendlyFireAction($action);
            if (CardStats::isDisabled($card)) {
                return [];
            }
            if (CardStats::hasCannotAttack($card) && CardStats::isOffensiveAction($type)) {
                return [];
            }

            if (!empty($action['condition'])
                && !CardStats::checkCondition($action['condition'], $state, $card)) {
                return [];
            }

            // Перехват летающих для любых атакующих действий
            if ($card->zone === CardInstance::ZONE_FLYING
                && in_array($type, ['shot', 'throw', 'discharge', 'magic', 'cast', 'tap'], true)) {

                $interceptors = CardStats::getAirInterceptors($state, $playerKey);
                if (!empty($interceptors)) {
                    foreach ($interceptors as $p) {
                        $result[$p->instanceId] = true;
                    }
                    return $result;
                }
            }

            // Перехват: только для shot / throw / discharge
            if (in_array($type, ['shot', 'throw', 'discharge'], true)) {
                $interceptors = CardStats::getRangedInterceptors($state, $playerKey, $type);
                if (!empty($interceptors)) {
                    $attackerIsFlying = ($card->zone === CardInstance::ZONE_FLYING);

                    foreach ($interceptors as $p) {
                        $pIsFlying = ($p->zone === CardInstance::ZONE_FLYING);

                        if (!$attackerIsFlying && !$pIsFlying) {
                            $drow = abs($p->row - $card->row);
                            $dcol = abs($p->col - $card->col);
                            $maxd = max($drow, $dcol);

                            // Соседняя клетка запрещена для выстрела
                            if ($maxd <= 1 && empty($action['near_shot'])) continue;
                        }

                        $result[$p->instanceId] = true;
                    }
                    return $result;
                }
            }

            if (!empty($action['self_destroy']) 
                || !empty($action['self'])
                || ($type ?? '') === 'become_fly'
                || ($type ?? '') === 'place_cell_marker'
                || ($type ?? '') === 'mark_opponent_row'
                || ($type ?? '') === 'jump') {
                return $result; // цели не подсвечиваются
            }

            foreach ($state->cards as $target) {
                if ($target->zone !== CardInstance::ZONE_FIELD
                    && $target->zone !== CardInstance::ZONE_FLYING) continue;
                if ($target->instanceId === $card->instanceId) continue;

                if ($type === 'tap') {
                    if (!empty($action['self']) && $target->instanceId !== $card->instanceId) continue;
                    if (!empty($action['own']) && $target->owner !== $playerKey) continue;
                    if (empty($action['own'])
                        && empty($action['self'])
                        && $target->owner === $playerKey
                        && !$allowFriendlyAction) continue;
                    if (!empty($action['near'])) {
                        $dr = abs($target->row - $card->row);
                        $dc = abs($target->col - $card->col);
                        if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;
                    }
                } elseif ($type === 'heal') {
                    if ($target->type !== 'creature' && $target->type !== 'fly') continue;
                    if (!empty($action['own']) && $target->owner !== $playerKey) continue;
                    if (!empty($action['self']) && $target->instanceId !== $card->instanceId) continue;
                    if (!empty($action['near'])) {
                        $dr = abs($target->row - $card->row);
                        $dc = abs($target->col - $card->col);
                        if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;
                    }
                } elseif ($type === 'execute') {
                    if (!CardStats::canExecuteTarget(
                        $state,
                        $card,
                        $target,
                        (int) ($action['value'] ?? 0),
                        !empty($action['near'])
                    )) continue;
                } elseif ($type === 'magic') {
                    if ($target->owner === $playerKey && !$allowFriendlyAction) continue;

                    $dr = abs($target->row - $card->row);
                    $dc = abs($target->col - $card->col);
                    $dist = $dr + $dc;

                    $range = CardStats::getEffectiveRange($state, $card, $action);
                    if ($range > 0) {
                        if ($dist > $range) continue;
                        if ($dist === 0) continue;
                    } else {
                        if ($dr > 1 || $dc > 1 || $dist === 0) continue;
                    }
                } elseif ($type === 'cast' && ($action['target'] ?? '') === 'ally_other') {
                    if ($target->owner !== $playerKey) continue;
                    if ($target->instanceId === $card->instanceId) continue;
                    // ok — цель-союзник
                } elseif ($type === 'self_wound_strike') {
                    if ($target->owner === $playerKey) continue;
                    if (!CardStats::isOpposite($card, $target)) continue;
                    if ($target->zone !== CardInstance::ZONE_FIELD) continue;
                } elseif ($type === 'steal_strike') {
                    if ($target->owner === $playerKey) continue;
                    if ($target->zone !== CardInstance::ZONE_FIELD) continue;
                    if (!empty($card->flags['steal_weapon_used'])) continue;

                    $dr = abs($target->row - $card->row);
                    $dc = abs($target->col - $card->col);
                    if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;
                } elseif ($type === 'give_coin') {
                    if ($target->owner !== $playerKey) continue;
                    if ($target->instanceId === $card->instanceId) continue;
                    if ($target->zone !== CardInstance::ZONE_FIELD
                        && $target->zone !== CardInstance::ZONE_FLYING) continue;
                    if ($target->dying || $target->hp <= 0) continue;
                    if (!CardStats::canSpendCoins($target)) continue;

                    $max = (int) ($target->prop['coins']['max_value'] ?? 0);
                    if ($max > 0 && $target->coins >= $max) continue;
                } elseif ($type === 'steal_coin') {
                    if ($target->owner === $playerKey) continue;
                    if ($target->zone !== CardInstance::ZONE_FIELD
                        && $target->zone !== CardInstance::ZONE_FLYING) continue;
                    if ($target->dying || $target->hp <= 0) continue;
                    if ((int) $target->coins <= 0) continue;
                    // без дальности — любая цель с монеткой
                } elseif ($type === 'blood_tap') {
                    if ($target->owner === $playerKey) continue;
                    if ($target->zone !== CardInstance::ZONE_FIELD
                        && $target->zone !== CardInstance::ZONE_FLYING) continue;
                } elseif ($type === 'poison_target') {
                    if ($target->zone !== CardInstance::ZONE_FIELD
                        && $target->zone !== CardInstance::ZONE_FLYING) continue;
                    // любой — свой или чужой
                } elseif ($type === 'apply_delayed_marker') {
                    if (($action['target'] ?? '') === 'enemy_non_flying') {
                        if ($target->owner === $playerKey) continue;
                        if ($target->zone !== CardInstance::ZONE_FIELD) continue;
                        if ($target->type === 'fly') continue;
                    }
                    $range = CardStats::getEffectiveRange($state, $card, $action);
                    if ($range > 0) {
                        $dist = abs($target->row - $card->row) + abs($target->col - $card->col);
                        if ($dist === 0 || $dist > $range) continue;
                    }
                } elseif ($type === 'dissonance') {
                    if ($target->owner === $playerKey) continue;
                    if ($target->zone !== CardInstance::ZONE_FIELD) continue;
                } elseif ($type === 'dive') {
                    if ($target->owner === $playerKey) continue;
                    if ($target->zone !== CardInstance::ZONE_FIELD) continue;
                    if ($target->type === 'fly') continue;
                    if ($target->dying || $target->hp <= 0) continue;

                    // есть ли хотя бы одна свободная соседняя клетка
                    $hasFree = false;
                    for ($dr = -1; $dr <= 1; $dr++) {
                        for ($dc = -1; $dc <= 1; $dc++) {
                            if ($dr === 0 && $dc === 0) continue;
                            $r = $target->row + $dr;
                            $c = $target->col + $dc;
                            if ($r < 1 || $r > 6 || $c < 1 || $c > 5) continue;

                            $occupied = false;
                            foreach ($state->cards as $o) {
                                if ($o->zone === CardInstance::ZONE_FIELD
                                    && $o->row === $r && $o->col === $c) {
                                    $occupied = true;
                                    break;
                                }
                            }
                            if ($occupied) continue;
                            if (ZoneManager::hasBlockingMarker($state, "{$r}_{$c}")) continue;

                            $hasFree = true;
                            break 2;
                        }
                    }
                    if (!$hasFree) continue;
                } else {
                    // shot / throw / discharge / cast
                    if ($target->owner === $playerKey && !$allowFriendlyAction) continue;

                    $targetIsFlying = ($target->zone === CardInstance::ZONE_FLYING);
                    $attackerIsFlying = ($card->zone === CardInstance::ZONE_FLYING);

                    if (!$targetIsFlying && !$attackerIsFlying) {
                        $dr = abs($target->row - $card->row);
                        $dc = abs($target->col - $card->col);
                        $maxd = max($dr, $dc);
                        $dist = $dr + $dc;

                        if ($maxd <= 1 && empty($action['near_shot'])) continue;

                        $range = CardStats::getEffectiveRange($state, $card, $action);
                        if ($range > 0 && $dist > $range) continue;
                    }
                }

                $result[$target->instanceId] = true;
            }

        } elseif ($mode === 'strike') {
            if (!self::hasAnyStrike($card)) return [];

            $attackerIsFlying = ($card->zone === CardInstance::ZONE_FLYING);
            $canAttackFlying  = CardStats::canAttackFlying($state, $card);

            // Берсерк: вторая атака — только по другой цели.
            // Первую цель не подсвечиваем, чтобы UI не расходился с declare.
            $alreadyHitId = 0;
            if (!empty($card->prop['strike_targets_unique']) && $attacksUsed > 0) {
                $alreadyHitId = (int) ($card->flags['first_attack_target_id'] ?? 0);
            }

            // Перехват летающих: только Пауки противника
            if ($attackerIsFlying) {
                $interceptors = CardStats::getAirInterceptors($state, $playerKey);
                if (!empty($interceptors)) {
                    foreach ($interceptors as $p) {
                        $result[$p->instanceId] = true;
                    }
                    return $result;
                }
            }

            foreach ($state->cards as $target) {
                if ($alreadyHitId > 0 && $target->instanceId === $alreadyHitId) continue;
                if ($target->zone !== CardInstance::ZONE_FIELD
                    && $target->zone !== CardInstance::ZONE_FLYING) continue;
                if ($target->instanceId === $card->instanceId) continue;
                if ($target->owner === $playerKey && !$allowFriendlyFire) continue;

                $targetIsFlying = ($target->zone === CardInstance::ZONE_FLYING);

                if (CardStats::hasDefense($state, $target, 'strike', $card)) {
                    continue;
                } elseif ($attackerIsFlying) {
                    // Летун бьёт любую цель без ограничений соседства
                    // (перехват Паука уже отфильтрован выше)
                } elseif ($targetIsFlying) {
                    if (!$canAttackFlying) continue;
                } else {
                    if (!empty($card->prop['strike']['opposite'])) {
                        if (!CardStats::isOpposite($card, $target)) continue;
                    } else {
                        $dr = abs($target->row - $card->row);
                        $dc = abs($target->col - $card->col);
                        $dist = $dr + $dc;

                        $isAdjacent = ($dr <= 1 && $dc <= 1 && $dist > 0);

                        $isRowExtreme = !empty($card->prop['row_extreme'])
                            && $target->row === $card->row
                            && (($card->col === 1 && $target->col === 5)
                                || ($card->col === 5 && $target->col === 1));

                        // Страж чертогов: дальность 2 в строю
                        $isLineRange = !empty($card->prop['strike_range_line'])
                            && CardStats::isInLine($state, $card)
                            && $dist > 0
                            && $dist <= (int) $card->prop['strike_range_line'];

                        if (!$isAdjacent && !$isRowExtreme && !$isLineRange) continue;
                    }
                }

                $result[$target->instanceId] = true;
            }
        } elseif ($mode === 'uchr') {
            foreach ($state->cards as $target) {
                if ($target->zone !== CardInstance::ZONE_FIELD
                    && $target->zone !== CardInstance::ZONE_FLYING) continue;
                if ($target->instanceId === $card->instanceId) continue;
                if ($target->owner === $playerKey && !$allowFriendlyFire) continue;

                $dr = abs($target->row - $card->row);
                $dc = abs($target->col - $card->col);
                if (($dr + $dc) !== 2) continue;
                if ($dr !== 0 && $dc !== 0) continue;

                $midR = (int) (($card->row + $target->row) / 2);
                $midC = (int) (($card->col + $target->col) / 2);
                $blocked = false;
                foreach ($state->cards as $m) {
                    if ($m->zone !== CardInstance::ZONE_FIELD) continue;
                    if ($m->row === $midR && $m->col === $midC && $m->owner !== $playerKey) {
                        $blocked = true;
                        break;
                    }
                }
                if (!$blocked) {
                    $result[$target->instanceId] = true;
                }
            }
        }

        return $result;
    }

    public static function isFriendlyFireTarget(CardInstance $attacker, CardInstance $target, string $mode): bool
    {
        return $target->owner === $attacker->owner
            && $target->instanceId !== $attacker->instanceId
            && CardStats::canFriendlyFireMode($attacker, $mode);
    }

    private static function movesLimitExhausted(GameState $state, CardInstance $card): bool
    {
        $limit = $state->battle['moves_limit'] ?? null;
        if (!$limit) return false;
        if (($limit['owner'] ?? '') !== $card->owner) return false;

        $moved = (array) ($limit['moved_ids'] ?? []);
        if (in_array($card->instanceId, $moved, true)) return false;

        return count($moved) >= (int) ($limit['limit'] ?? 0);
    }
}
