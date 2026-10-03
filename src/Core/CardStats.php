<?php
// src/Core/CardStats.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Расчёт характеристик карты в контексте партии.
 * Все методы чистые — не мутируют state.
 */
final class CardStats
{
    // ─── Базовое ─────────────────────────────────────────────

    public static function hasAnyStrike(CardInstance $card): bool
    {
        if (!empty($card->prop['copy_target_strike'])) return true;

        return $card->strikeWeak > 0
            || $card->strikeMedium > 0
            || $card->strikeStrong > 0;
    }

    public static function isMeleeAction(string $type): bool
    {
        return in_array($type, ['strike', 'answer', 'tap', 'magic', 'execute'], true);
    }

    public static function getStat(CardInstance $card, string $stat): int
    {
        $value = 0;
        $prop = $card->prop[$stat] ?? null;

        if (is_numeric($prop)) {
            $value = (int) $prop;
        } elseif (is_array($prop)) {
            $value = (int) ($prop['value'] ?? 0);
        }

        foreach ($card->modifiers as $modifier) {
            if (($modifier['stat'] ?? '') === $stat) {
                $value += (int) ($modifier['value'] ?? 0);
            }
        }

        return $value;
    }

    public static function canExecuteTarget(
        GameState $state,
        CardInstance $attacker,
        CardInstance $target,
        int $value,
        bool $near = false
    ): bool {
        if ($target->zone !== CardInstance::ZONE_FIELD
            && $target->zone !== CardInstance::ZONE_FLYING) {
            return false;
        }
        if ($target->dying || $target->hp <= 0) return false;
        if ($target->owner === $attacker->owner) return false;
        if ($target->type === 'fly') return false;
        if (!empty($target->prop['incorporeal'])) return false;
        if ($target->hp > $value) return false;

        if ($near) {
            $dr = abs($target->row - $attacker->row);
            $dc = abs($target->col - $attacker->col);
            if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return CardInstance[]
     */
    public static function findExecuteTargets(
        GameState $state,
        CardInstance $attacker,
        int $value,
        bool $near = false
    ): array {
        if ($value <= 0) return [];

        $targets = [];
        foreach ($state->cards as $target) {
            if ($target->instanceId === $attacker->instanceId) continue;
            if (self::canExecuteTarget($state, $attacker, $target, $value, $near)) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    public static function getStrikeValue(
        GameState $state,
        CardInstance $attacker,
        CardInstance $target,
        string $level
    ): int {
        $base = match ($level) {
            'weak' => $attacker->strikeWeak,
            'medium' => $attacker->strikeMedium,
            'strong' => $attacker->strikeStrong,
            default => 0,
        };

        $value = $base + self::getAbilityBonus($state, $attacker, $target, 'strike', $level);
        return max(0, $value);
    }

    // ─── Кубик: ova / ovz ────────────────────────────────────

    public static function getOva(GameState $state, CardInstance $card, ?CardInstance $target = null): int
    {
        $val = 0;

        $ova = $card->prop['ova'] ?? 0;
        if ($ova) {
            if (is_array($ova)) {
                $ok = self::checkLineCondition($state, $card, $ova)
                    && (empty($ova['condition']) || self::checkCondition($ova['condition'], $state, $card));
                if ($ok) {
                    $val = (int) ($ova['value'] ?? 0);
                }
            } else {
                $val = (int) $ova;
            }
        }

        foreach ($card->modifiers as $m) {
            if (($m['stat'] ?? '') === 'ova') $val += (int) ($m['value'] ?? 0);
        }

        // Песчаные когти: +1 к кубику если цель помечена союзником
        if ($target && !empty($target->markers['sand_claws'])) {
            $sc = $target->markers['sand_claws'];
            if (($sc['source'] ?? null) === $card->owner) {
                $val += (int) ($sc['value'] ?? 1);
            }
        }

        return $val;
    }

    public static function getOvz(GameState $state, CardInstance $card): int
    {
        $val = 0;

        $ovz = $card->prop['ovz'] ?? 0;
        if ($ovz) {
            if (is_array($ovz)) {
                if (self::checkLineCondition($state, $card, $ovz)) {
                    $val = (int) ($ovz['value'] ?? 0);
                }
            } else {
                $val = (int) $ovz;
            }
        }

        foreach ($card->modifiers as $m) {
            if (($m['stat'] ?? '') === 'ovz') $val += (int) ($m['value'] ?? 0);
        }

        return $val;
    }

    public static function hasDefense(
        GameState $state,
        CardInstance $target,
        string $actionType,
        ?CardInstance $attacker = null
    ): bool {
        $prop = $target->prop;

        // Универсальные по типу атакующего
        $universal = ['zoa'];
        if ($attacker !== null) {
            $attackerType = $attacker->type ?? 'creature';
            if ($attackerType === 'creature') $universal[] = 'zoan';
            if ($attackerType === 'fly')      $universal[] = 'zoal';
        }

        // Специфичные по типу действия
        $map = [
            'throw'     => ['zot', 'zoda'],
            'shot'      => ['zov', 'zoda'],
            'discharge' => ['zoda', 'zoz', 'zom', 'zor'],
            'magic'     => ['zoz', 'zom'],
            'cast'      => ['zom'],
        ];

        $defs = array_merge($map[$actionType] ?? [], $universal);

        foreach ($defs as $def) {
            if ($attacker !== null && self::isDefenseIgnored($attacker, $def)) {
                continue;
            }

            $d = $prop[$def] ?? null;
            if (!empty($d)) {
                if (is_array($d) && !empty($d['line']) && !self::isInLine($state, $target)) {
                    continue;
                }
                return true;
            }

            foreach ($target->modifiers as $m) {
                if (($m['stat'] ?? '') === $def && !empty($m['value'])) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function isDefenseIgnored(CardInstance $attacker, string $defense): bool
    {
        $ignore = $attacker->prop['ignore'] ?? null;
        if (!$ignore) return false;

        if (is_array($ignore) && array_is_list($ignore)) {
            return in_array($defense, $ignore, true);
        }
        if (is_array($ignore) && !empty($ignore[$defense])) {
            return true;
        }
        return false;
    }

    // ─── Модификаторы урона ─────────────────────────────────

    public static function getShotBonus(CardInstance $card): int
    {
        $bonus = 0;
        foreach ($card->modifiers as $m) {
            if (($m['stat'] ?? '') === 'shot_bonus') {
                $bonus += (int) ($m['value'] ?? 0);
            }
        }
        return $bonus;
    }

    public static function getNextActionBonus(CardInstance $card, string $actionType): int
    {
        $bonus = 0;
        foreach ($card->modifiers as $m) {
            if (($m['stat'] ?? '') !== 'next_action_bonus') continue;

            $types = $m['types'] ?? null;
            if (is_array($types) && !in_array($actionType, $types, true)) continue;

            $bonus += (int) ($m['value'] ?? 0);
        }

        return $bonus;
    }

    public static function getNextStrikeBonus(CardInstance $card): int
    {
        $bonus = 0;
        foreach ($card->modifiers as $m) {
            if (($m['stat'] ?? '') === 'next_strike_bonus') {
                $bonus += (int) ($m['value'] ?? 0);
            }
        }

        return $bonus;
    }

    public static function getEffectiveRange(GameState $state, CardInstance $card, array $action): int
    {
        $range = (int) ($action['range'] ?? 0);
        if ($range <= 0) {
            return $range;
        }

        $actionType = (string) ($action['type'] ?? '');
        return $range
            + self::getColumnRangeAuraBonus($state, $card, $actionType)
            + self::getActionRangeBonus($card, $actionType);
    }

    public static function getActionRangeBonus(CardInstance $card, string $actionType): int
    {
        if ($actionType === '') return 0;

        $bonus = 0;
        foreach ($card->modifiers as $m) {
            if (($m['stat'] ?? '') !== 'action_range') continue;

            $types = $m['types'] ?? null;
            if (is_array($types) && !in_array($actionType, $types, true)) continue;

            $bonus += (int) ($m['value'] ?? 0);
        }
        return $bonus;
    }

    public static function getColumnRangeAuraBonus(GameState $state, CardInstance $card, string $actionType): int
    {
        if ($actionType === '') return 0;
        if ($card->zone !== CardInstance::ZONE_FIELD) return 0;
        if ($card->dying || $card->hp <= 0) return 0;

        $bonus = 0;
        foreach ($state->cards as $source) {
            if ($source->dying || $source->hp <= 0) continue;
            if ($source->zone !== CardInstance::ZONE_FIELD) continue;
            if ($source->col !== $card->col) continue;

            $aura = $source->prop['column_range_aura'] ?? null;
            if (!is_array($aura)) continue;

            $target = (string) ($aura['target'] ?? 'ally');
            if ($target === 'ally' && $source->owner !== $card->owner) continue;
            if ($target === 'enemy' && $source->owner === $card->owner) continue;

            $types = $aura['types'] ?? [];
            if (!is_array($types) || !in_array($actionType, $types, true)) continue;

            $bonus += (int) ($aura['value'] ?? 1);
        }

        return $bonus;
    }

    public static function getAbilityBonus(
        GameState $state,
        CardInstance $attacker,
        CardInstance $target,
        string $actionType = 'strike',
        string $level = null
    ): int {
        $result = 0;

        // Модификаторы
        foreach ($attacker->modifiers as $m) {
            $stat = $m['stat'] ?? '';
            if ($stat === 'ability_strike') {
                $result += (int) ($m['value'] ?? 0);
            } elseif ($stat === 'ability_discharge' && $actionType === 'discharge') {
                $result += (int) ($m['value'] ?? 0);
            } elseif ($stat === 'coin_strike_bonus' && $actionType === 'strike') {
                $result += (int) ($m['value'] ?? 0);
            }
        }

        // Собираем ability как массив
        $abilities = $attacker->prop['ability'] ?? null;
        if (!$abilities) return $result;

        if (isset($abilities['value'])) {
            // Одиночный объект
            $abilities = [$abilities];
        }

        foreach ($abilities as $ability) {
            if (!is_array($ability)) continue;

            $conditionOk = empty($ability['condition']) 
                || self::checkCondition($ability['condition'], $state, $attacker);

            $onlyOk = empty($ability['only']) 
                || in_array($actionType, $ability['only'], true);

            $levelOk = empty($ability['level']) 
                || ($level !== null && in_array($level, $ability['level'], true));

            $value = (int) ($ability['value'] ?? 0);

            if (!$conditionOk || !$onlyOk || !$levelOk || $value === 0) continue;

            // Специальные фильтры
            if (!empty($ability['line']) && !self::isInLine($state, $attacker)) continue;
            if (!empty($ability['closed']) && !$target->closed) continue;
            if (!empty($ability['target_type']) && $target->type !== $ability['target_type']) continue;

            // per_ally — бонус за каждого союзника с фильтром (Зомби)
            if (!empty($ability['per_ally'])) {
                $per = $ability['per_ally'];
                $count = 0;

                foreach ($state->cards as $ally) {
                    if ($ally->zone !== CardInstance::ZONE_FIELD
                        && $ally->zone !== CardInstance::ZONE_FLYING) continue;
                    if ($ally->owner !== $attacker->owner) continue;
                    if ($ally->instanceId === $attacker->instanceId) continue;
                    if ($ally->dying) continue;

                    if (!empty($per['element']) && $ally->element !== $per['element']) continue;

                    $count++;
                }

                $bonus = $value * $count;
                if (isset($ability['max']) && $bonus > (int) $ability['max']) {
                    $bonus = (int) $ability['max'];
                }
                $result += $bonus;
                continue;
            }

            // +N по цели с маркером (Владыка небес)
            if (!empty($ability['target_marker'])) {
                if (!isset($target->markers[$ability['target_marker']])) continue;
                $result += $value;
                continue;
            }

            if (!empty($ability['position'])) {
                $pos = $ability['position'];

                if ($pos === 'opposite') {
                    if (!self::isOpposite($attacker, $target)) continue;
                } elseif ($pos === 'row_mirror') {
                    if ($target->row !== (7 - $attacker->row)) continue;
                } elseif ($pos === 'enemy_second_row') {
                    // Второй ряд противника: row 5 для host-карты, row 2 для player-карты
                    $enemyRow = ($attacker->owner === 'host') ? 5 : 2;
                    if ($target->row !== $enemyRow) continue;
                } else {
                    continue;
                }

                $result += $value;
                continue;
            }

            // +N по защитникам (Вилохвост)
            if (!empty($ability['defender'])) {
                $strike = $state->battle['strike'] ?? null;
                $isDefender = $strike
                    && ($strike['defender_id'] ?? null) === $target->instanceId;

                if (!$isDefender) continue;
                $result += $value;
                continue;
            }

            // Element / types / prop
            $hasFilter = isset($ability['element']) 
                || isset($ability['types']) 
                || isset($ability['prop']);

            if (!$hasFilter) {
                $result += $value;
                continue;
            }

            $matched = false;

            if (isset($ability['element']) && $target->element === $ability['element']) {
                $matched = true;
            }

            if (!$matched && isset($ability['types']) && is_array($ability['types'])) {
                foreach ($target->prop['actions'] ?? [] as $a) {
                    if (in_array($a['type'] ?? '', $ability['types'], true)) {
                        $matched = true;
                        break;
                    }
                }
            }

            if (!$matched && isset($ability['prop']) && is_array($ability['prop'])) {
                foreach ($ability['prop'] as $flag) {
                    if (!empty($target->prop[$flag])) {
                        $matched = true;
                        break;
                    }
                }
            }

            if ($matched) $result += $value;
        }

        return $result;
    }

    public static function canSpendCoins(CardInstance $card): bool
    {
        // Явный запрет
        if (!empty($card->prop['coins_receive_deny'])) return false;

        // A. coins[type].spend (Арбалетчик, Мастер топора, Пустотник, Повелитель)
        $coinsProp = $card->prop['coins'] ?? null;
        if (is_array($coinsProp)) {
            foreach ($coinsProp as $key => $cfg) {
                if ($key === 'max_value') continue;
                if (is_array($cfg) && !empty($cfg['spend'])) return true;
            }
        }

        // B. coin_strike_bonus (Камнедрев — бьёт и тратит все монеты)
        if (!empty($card->prop['coin_strike_bonus'])) return true;

        // C. actions[].coins > 0 (Знахарь, Болотник, Волхв)
        foreach ($card->prop['actions'] ?? [] as $a) {
            if ((int) ($a['coins'] ?? 0) > 0) return true;
        }

        return false;
    }

    public static function getDamageReduction(
        GameState $state,
        CardInstance $attacker,
        CardInstance $target,
        string $actionType
    ): int {
        $total = 0;

        // Из prop
        $reductions = $target->prop['damage_reduction'] ?? [];
        if (is_array($reductions)) {
            foreach ($reductions as $r) {
                $types = $r['types'] ?? null;
                if ($types !== null && !in_array($actionType, $types, true)) continue;

                if (!empty($r['require_no_wounds']) && $target->hp < $target->hpMax) continue;
                if (isset($r['max_price']) && $attacker->price > (int) $r['max_price']) continue;
                if (isset($r['element']) && $attacker->element !== $r['element']) continue;
                if (isset($r['attacker_type']) && $attacker->type !== $r['attacker_type']) continue;
                if (!empty($r['line']) && !self::isInLine($state, $target)) continue;

                if (!empty($r['attacker_direct'])) {
                    if (!self::isDirectStrike($state, $attacker, $target)) continue;
                }
                if (!empty($r['attacker_diagonal'])) {
                    $dr = abs($attacker->row - $target->row);
                    $dc = abs($attacker->col - $target->col);
                    if ($dr !== 1 || $dc !== 1) continue;
                }

                $total += (int) ($r['value'] ?? 0);
            }
        }

        if ($target->element === 'plains' && $target->zone === CardInstance::ZONE_FIELD) {
            foreach ($state->cards as $neighbor) {
                if ($neighbor->dying) continue;
                if ($neighbor->owner !== $target->owner) continue;
                if ($neighbor->instanceId === $target->instanceId) continue;
                if ($neighbor->zone !== CardInstance::ZONE_FIELD) continue;

                $aura = $neighbor->prop['aura_plains_ranged'] ?? null;
                if (!$aura) continue;

                if ($neighbor->row !== $target->row) continue;
                if (abs($neighbor->col - $target->col) !== 1) continue;

                $types = $aura['types'] ?? null;
                if ($types !== null && !in_array($actionType, $types, true)) continue;

                $total += (int) ($aura['value'] ?? 1);
                break;
            }
        }

        // Из модификаторов (Грезы Архааля и т.п.)
        foreach ($target->modifiers as $m) {
            if (($m['stat'] ?? '') !== 'damage_reduction') continue;
            $types = $m['types'] ?? null;
            if ($types !== null && !in_array($actionType, $types, true)) continue;
            $total += (int) ($m['value'] ?? 0);
        }

        return $total;
    }

    // ─── Броня и строй ───────────────────────────────────────

    public static function computeArmor(GameState $state, CardInstance $card): int
    {
        $val = 0;

        // Базовая броня из prop
        $armor = $card->prop['armor'] ?? null;
        if ($armor && is_array($armor)) {
            if (self::checkLineCondition($state, $card, $armor)) {
                $val = (int) ($armor['value'] ?? 0);
            }
        }

        // Модификаторы (инкарнация, способности)
        foreach ($card->modifiers as $m) {
            if (($m['stat'] ?? '') === 'armor') {
                $val += (int) ($m['value'] ?? 0);
            }
        }

        return $val;
    }

    public static function hasLine(CardInstance $card): bool
    {
        if (!empty($card->prop['has_line'])) return true;
        return self::hasLineDeep($card->prop);
    }

    public static function hasCannotAttack(CardInstance $card): bool
    {
        foreach ($card->modifiers as $m) {
            if (($m['stat'] ?? '') === 'cannot_attack') return true;
        }
        return false;
    }

    public static function isOffensiveAction(string $type): bool
    {
        return in_array($type, ['strike', 'uchr', 'shot', 'throw', 'discharge', 'magic', 'cast', 'tap', 'impact', 'execute', 'dissonance', 'sand_claws', 'bomb_shot'], true);
    }

    private static function hasLineDeep(array $arr): bool
    {
        foreach ($arr as $key => $val) {
            if ($key === 'line' && !empty($val)) return true;
            if (is_array($val) && self::hasLineDeep($val)) return true;
        }
        return false;
    }

    public static function isInLine(GameState $state, CardInstance $card): bool
    {
        if ($card->zone !== CardInstance::ZONE_FIELD) return false;
        if ($card->type === 'fly') return false;

        foreach ($state->cards as $other) {
            if ($other->dying) continue;
            if ($other->owner !== $card->owner) continue;
            if ($other->instanceId === $card->instanceId) continue;
            if ($other->zone !== CardInstance::ZONE_FIELD) continue;
            if ($other->type === 'fly') continue;
            if (!self::hasLine($other)) continue;

            $dr = abs($other->row - $card->row);
            $dc = abs($other->col - $card->col);
            if ($dr + $dc === 1) return true;
        }
        return false;
    }

    /**
     * @return CardInstance[]
     */
    public static function getLineGroup(GameState $state, CardInstance $card): array
    {
        if ($card->zone !== CardInstance::ZONE_FIELD) return [];
        if ($card->type === 'fly') return [];
        if (!self::hasLine($card)) return [];

        $result = [$card];
        foreach ($state->cards as $candidate) {
            if ($candidate->instanceId === $card->instanceId) continue;
            if ($candidate->owner !== $card->owner) continue;
            if ($candidate->zone !== CardInstance::ZONE_FIELD) continue;
            if ($candidate->type === 'fly') continue;
            if ($candidate->dying) continue;
            if (!self::hasLine($candidate)) continue;

            $dr = abs($candidate->row - $card->row);
            $dc = abs($candidate->col - $card->col);
            if ($dr + $dc === 1) {
                $result[] = $candidate;
            }
        }

        return $result;
    }

    private static function checkLineCondition(GameState $state, CardInstance $card, array $prop): bool
    {
        if (empty($prop['line'])) return true; // строй не требуется

        if ($card->zone !== CardInstance::ZONE_FIELD) return false;
        if ($card->type === 'fly') return false;

        $wantElite = $prop['line_elite'] ?? null;

        foreach ($state->cards as $other) {
            if ($other->dying) continue;
            if ($other->owner !== $card->owner) continue;
            if ($other->instanceId === $card->instanceId) continue;
            if ($other->zone !== CardInstance::ZONE_FIELD) continue;
            if ($other->type === 'fly') continue;
            if (!self::hasLine($other)) continue;

            $dr = abs($other->row - $card->row);
            $dc = abs($other->col - $card->col);
            if ($dr + $dc !== 1) continue;

            // Если нужно конкретное elite — проверяем
            if ($wantElite !== null && $other->elite !== $wantElite) continue;

            return true;
        }
        return false;
    }

    public static function checkCondition($condition, GameState $state, CardInstance $card): bool
    {
        if ($condition === null || $condition === '') return true;

        if (is_string($condition)) {
            return match ($condition) {
                'enemy_front_row_empty'     => self::isEnemyFrontRowEmpty($state, $card),
                'ally_opposite_no_wounds'   => self::hasAllyOppositeNoWounds($state, $card),
                'more_ally_near_than_enemy' => self::hasMoreAlliesNearThanEnemies($state, $card),
                'line_count_2_plus'         => self::countLineNeighbors($state, $card) >= 2,
                default                     => true,
            };
        }

        if (is_array($condition)) {
            $type = $condition['type'] ?? '';
            return match ($type) {
                'enemies_near' => self::countEnemiesNearMatching($state, $card, $condition)
                    >= (int) ($condition['count'] ?? 1),
                default => true,
            };
        }

        return true;
    }

    public static function isEnemyFrontRowEmpty(GameState $state, CardInstance $card): bool
    {
        // Первый ряд противника — row 4 для host, row 3 для player
        $enemyFrontRow = ($card->owner === 'host') ? 4 : 3;

        foreach ($state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->owner === $card->owner) continue;
            if ($c->row === $enemyFrontRow) return false;
        }
        return true;
    }

    public static function canAttackFlying(GameState $state, CardInstance $card): bool
    {
        $caf = $card->prop['can_attack_flying'] ?? null;
        if (!$caf) return false;

        // Может быть просто true или {condition: ...}
        if ($caf === true) return true;

        if (is_array($caf) && !empty($caf['condition'])) {
            return self::checkCondition($caf['condition'], $state, $card);
        }

        return false;
    }

    public static function isDirectStrike(GameState $state, CardInstance $attacker, CardInstance $target): bool
    {
        // Модификатор direct (Бегущая)
        foreach ($attacker->modifiers as $m) {
            if (($m['stat'] ?? '') === 'direct' && !empty($m['value'])) {
                return true;
            }
        }

        // Владыка небес: direct_if_marker
        $abilities = $attacker->prop['ability'] ?? null;
        if ($abilities) {
            $list = isset($abilities['value']) ? [$abilities] : $abilities;
            foreach ($list as $a) {
                if (!is_array($a)) continue;
                if (!empty($a['direct_if_marker']) && isset($target->markers[$a['direct_if_marker']])) {
                    return true;
                }
            }
        }

        $direct = $attacker->prop['direct'] ?? false;

        if ($direct === true) return true;

        // Условный unanswer (Римаанды)
        if (self::hasUnanswer($state, $attacker, $target)) {
            return true;
        }
        
        if (is_array($direct) && !empty($direct['check']) && !empty($direct['types'])) {
            foreach ($target->prop['actions'] ?? [] as $a) {
                if (in_array($a['type'] ?? '', $direct['types'], true)) {
                    return true;
                }
            }
        }

        if (is_array($direct) && !empty($direct['check']) && !empty($direct['closed'])) {
            if ($target->closed) return true;
        }

        if (is_array($direct) && !empty($direct['check']) && !empty($direct['element'])) {
            if ($target->element === $direct['element']) {
                return true;
            }
        }

        if (is_array($direct) && !empty($direct['check']) && !empty($direct['condition'])) {
            if (self::checkCondition($direct['condition'], $state, $attacker)) {
                return true;
            }
        }

        if (is_array($direct) && !empty($direct['check']) && !empty($direct['target_condition'])) {
            $cond = $direct['target_condition'];
            if ($cond === 'isolated_horizontally') {
                if (self::isTargetIsolatedHorizontally($state, $attacker, $target)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function getClumsyPenalty(CardInstance $card): int
    {
        $c = $card->prop['clumsy'] ?? 0;
        return is_array($c) ? (int) ($c['value'] ?? 0) : (int) $c;
    }

    /**
     * Возвращает карту, которую обязательно должна атаковать карта с forced_strike.
     *
     * Правило: если у карты есть forced_strike и рядом с ней находится
     * закрытое существо противника — карта обязана атаковать его в свой ход.
     *
     * @return CardInstance|null  Цель для обязательной атаки, либо null если нет.
    */
    public static function getForcedStrikeTarget(GameState $state, CardInstance $card): ?CardInstance
    {
        if (empty($card->prop['forced_strike'])) return null;
        if ($card->zone !== CardInstance::ZONE_FIELD) return null;
        if ($card->closed) return null;
        if (!self::hasAnyStrike($card)) return null;

        foreach ($state->cards as $target) {
            if ($target->zone !== CardInstance::ZONE_FIELD) continue;
            if ($target->owner === $card->owner) continue;
            if (!$target->closed) continue;

            $dr = abs($target->row - $card->row);
            $dc = abs($target->col - $card->col);
            if ($dr <= 1 && $dc <= 1 && ($dr + $dc) > 0) {
                return $target;
            }
        }
        return null;
    }

    /**
     * Проверяет, что напротив карты (в том же столбце, на 1 ряд
     * в сторону противника) стоит своё существо без ран.
     */
    public static function hasAllyOppositeNoWounds(GameState $state, CardInstance $card): bool
    {
        if ($card->zone !== CardInstance::ZONE_FIELD) return false;

        // Направление «к противнику»: host — увеличение row, player — уменьшение
        $step = ($card->owner === 'host') ? 1 : -1;
        $oppRow = $card->row + $step;
        $oppCol = $card->col;

        foreach ($state->cards as $other) {
            if ($other->zone !== CardInstance::ZONE_FIELD) continue;
            if ($other->owner !== $card->owner) continue;
            if ($other->instanceId === $card->instanceId) continue;
            if ($other->row !== $oppRow || $other->col !== $oppCol) continue;

            // Без ран = полное HP
            return $other->hp === $other->hpMax;
        }
        return false;
    }

    /**
     * Координаты клетки напротив карты (тот же столбец, +1 ряд в сторону врага).
     * @return ?array{row:int, col:int}
     */
    public static function oppositeCell(CardInstance $card): ?array
    {
        if ($card->row === null || $card->col === null) return null;
        if ($card->zone !== CardInstance::ZONE_FIELD) return null;

        $step = $card->owner === 'host' ? 1 : -1;
        $row  = $card->row + $step;

        if ($row < 1 || $row > 6) return null;
        return ['row' => $row, 'col' => $card->col];
    }

    /**
     * Карта, стоящая напротив (любая — своя или чужая). Либо null.
     */
    public static function getOppositeFieldCard(GameState $state, CardInstance $card): ?CardInstance
    {
        $cell = self::oppositeCell($card);
        if ($cell === null) return null;

        foreach ($state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->row === $cell['row'] && $c->col === $cell['col']) {
                return $c;
            }
        }
        return null;
    }
    
    /**
     * Проверяет, что target стоит строго напротив attacker.
     * Напротив = зеркальный ряд (7 - row), тот же столбец.
     */
    public static function isOpposite(CardInstance $attacker, CardInstance $target): bool
    {
        if ($attacker->zone !== CardInstance::ZONE_FIELD) return false;
        if ($target->zone !== CardInstance::ZONE_FIELD) return false;

        if ($attacker->col !== $target->col) return false;

        // Направление «вперёд» зависит от владельца:
        // host идёт в сторону увеличения row, player — в сторону уменьшения
        $step = $attacker->owner === 'host' ? 1 : -1;

        return $target->row === $attacker->row + $step;
    }

    public static function hasUnanswer(
        GameState $state,
        CardInstance $attacker,
        CardInstance $target
    ): bool {
        $unanswer = $attacker->prop['unanswer'] ?? null;
        if (!$unanswer) return false;

        if ($unanswer === true) return true;

        if (is_array($unanswer) && !empty($unanswer['check'])) {
            if (($unanswer['condition'] ?? '') === 'isolated_horizontally') {
                return self::isTargetIsolatedHorizontally($state, $attacker, $target);
            }
            $markerKey = $unanswer['target_marker'] ?? null;
            if ($markerKey !== null && isset($target->markers[$markerKey])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Проверяет, что у цели слева и справа нет других существ её владельца.
     * Если цель на краю поля — проверяем только с одной стороны.
     */
    public static function isTargetIsolatedHorizontally(
        GameState $state,
        CardInstance $attacker,
        CardInstance $target
    ): bool {
        $owner = $target->owner;

        foreach ($state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->instanceId === $target->instanceId) continue;
            if ($c->owner !== $owner) continue;
            if ($c->row !== $target->row) continue;
            if (abs($c->col - $target->col) !== 1) continue;

            return false;
        }
        return true;
    }

    public static function hasZoal(GameState $state, CardInstance $card): bool
    {
        return self::hasDefense($state, $card, 'strike', null) && !empty($card->prop['zoal']);
    }

    public static function hasAllyPriceNear(
        GameState $state,
        CardInstance $card,
        int $minPrice
    ): bool {
        foreach ($state->cards as $c) {
            if ($c->owner !== $card->owner) continue;
            if ($c->instanceId === $card->instanceId) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->dying || $c->hp <= 0) continue;
            if ($c->price < $minPrice) continue;

            $dr = abs($c->row - $card->row);
            $dc = abs($c->col - $card->col);
            if ($dr <= 1 && $dc <= 1 && ($dr + $dc) > 0) return true;
        }
        return false;
    }

    /**
     * Возвращает Пауков-пересмешников противника, живых, на поле.
     * @return CardInstance[]
     */
    public static function getAirInterceptors(GameState $state, string $attackerKey): array
    {
        $result = [];
        foreach ($state->cards as $card) {
            if ($card->owner === $attackerKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD) continue;
            if ($card->dying || $card->hp <= 0) continue;
            if (empty($card->prop['air_intercept'])) continue;
            $result[] = $card;
        }
        return $result;
    }

    /**
     * Единый маппинг stat → человекочитаемая метка.
     */
    public static function statLabel(string $stat): string
    {
        return match ($stat) {
            'ova'            => 'ОВА',
            'ovz'            => 'ОВЗ',
            'ability_strike' => 'удар',
            'direct'         => 'направка',
            'regeneration'   => 'реген',
            'move'           => 'Ход',
            'shot_bonus'     => 'Выстрел',
            'next_action_bonus' => 'Действие',
            'zoal'              => 'ЗОАЛ',
            'damage_reduction'  => 'Защита',
            'shield_light'      => 'Щит',
            'coin_strike_bonus' => 'Атака',
            'action_range'      => 'Дальность',
            default             => $stat,
        };
    }

    /**
     * @return CardInstance[]
     */
    public static function getRangedInterceptors(
        GameState $state,
        string $attackerKey,
        ?string $actionType = null
    ): array {
        $result = [];
        foreach ($state->cards as $card) {
            if ($card->owner === $attackerKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->hp <= 0) continue;

            $config = $card->prop['ranged_intercept'] ?? null;
            if ($config === null) continue;

            // Массив типов — проверяем конкретный тип
            if (is_array($config)) {
                if ($actionType === null) continue;
                if (!in_array($actionType, $config, true)) continue;
            } elseif ($config === true) {
                // Legacy: true = shot + throw
                if ($actionType !== null && !in_array($actionType, ['shot', 'throw'], true)) continue;
            } else {
                continue;
            }

            if (!self::hasAdjacentEnemy($state, $card)) continue;

            $result[] = $card;
        }
        return $result;
    }

    public static function hasAdjacentEnemy(GameState $state, CardInstance $card): bool
    {
        for ($dr = -1; $dr <= 1; $dr++) {
            for ($dc = -1; $dc <= 1; $dc++) {
                if ($dr === 0 && $dc === 0) continue;
                $r = $card->row + $dr;
                $c = $card->col + $dc;
                if ($r < 1 || $r > 6 || $c < 1 || $c > 5) continue;

                foreach ($state->cards as $other) {
                    if ($other->owner === $card->owner) continue;
                    if ($other->zone !== CardInstance::ZONE_FIELD) continue;
                    if ($other->dying || $other->hp <= 0) continue;
                    if ($other->row === $r && $other->col === $c) return true;
                }
            }
        }
        return false;
    }

    public static function hasOwnCreatureInAllRows(GameState $state, string $playerKey): bool
    {
        $filled = [];
        foreach ($state->cards as $c) {
            if ($c->owner !== $playerKey) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->dying || $c->hp <= 0) continue;
            $filled[$c->row] = true;
        }

        for ($r = 1; $r <= 6; $r++) {
            if (empty($filled[$r])) return false;
        }
        return true;
    }

    public static function hasMoreAlliesNearThanEnemies(GameState $state, CardInstance $card): bool
    {
        if ($card->zone !== CardInstance::ZONE_FIELD) return false;

        $allies  = 0;
        $enemies = 0;

        foreach ($state->cards as $other) {
            if ($other->zone !== CardInstance::ZONE_FIELD) continue;
            if ($other->instanceId === $card->instanceId) continue;
            if ($other->dying || $other->hp <= 0) continue;

            $dr = abs($other->row - $card->row);
            $dc = abs($other->col - $card->col);
            if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;

            if ($other->owner === $card->owner) $allies++;
            else                                $enemies++;
        }

        return $allies > $enemies;
    }

    /**
     * Собирает активные бейджи из prop (для отображения на карте).
     * @return array<string, mixed>  stat => value
     */
    public static function getActivePropBadges(GameState $state, CardInstance $card): array
    {
        $result = [];

        // OVA
        $ova = $card->prop['ova'] ?? null;
        if ($ova !== null) {
            $val = 0;
            $ok  = true;

            if (is_numeric($ova)) {
                $val = (int) $ova;
            } elseif (is_array($ova)) {
                if (!empty($ova['line']) && !self::checkLineCondition($state, $card, $ova)) {
                    $ok = false;
                }
                if ($ok && !empty($ova['condition'])
                    && !self::checkCondition($ova['condition'], $state, $card)) {
                    $ok = false;
                }
                $val = (int) ($ova['value'] ?? 0);
            }

            if ($ok && $val !== 0) $result['ova'] = $val;
        }

        // OVZ
        $ovz = $card->prop['ovz'] ?? null;
        if ($ovz !== null) {
            $val = 0;
            $ok  = true;

            if (is_numeric($ovz)) {
                $val = (int) $ovz;
            } elseif (is_array($ovz)) {
                if (!empty($ovz['line']) && !self::checkLineCondition($state, $card, $ovz)) {
                    $ok = false;
                }
                if ($ok && !empty($ovz['condition'])
                    && !self::checkCondition($ovz['condition'], $state, $card)) {
                    $ok = false;
                }
                $val = (int) ($ovz['value'] ?? 0);
            }

            if ($ok && $val !== 0) $result['ovz'] = $val;
        }

        // Direct
        $direct = $card->prop['direct'] ?? null;
        if ($direct !== null) {
            $show = false;
            if ($direct === true) {
                $show = true;
            } elseif (is_array($direct)) {
                if (!empty($direct['condition'])) {
                    $show = self::checkCondition($direct['condition'], $state, $card);
                } elseif (!empty($direct['check'])
                    && empty($direct['types'])
                    && empty($direct['element'])
                    && empty($direct['closed'])
                    && empty($direct['target_condition'])) {
                    $show = true;
                }
            }
            if ($show) $result['direct'] = 1;
        }

        // Regeneration
        $regen = $card->prop['regeneration'] ?? null;
        if ($regen !== null) {
            $val = is_array($regen) ? (int) ($regen['value'] ?? 0) : (int) $regen;
            if ($val > 0) $result['regeneration'] = $val;
        }

        // Ability — условные бонусы к удару
        $abilities = $card->prop['ability'] ?? null;
        if ($abilities !== null) {
            if (isset($abilities['value'])) $abilities = [$abilities];

            $abilityStrikeBonus = 0;
            foreach ($abilities as $a) {
                if (!is_array($a)) continue;
                $val = (int) ($a['value'] ?? 0);
                if ($val === 0) continue;

                // Только strike — прочие типы (magic/discharge/throw) не показываем
                if (!empty($a['only']) && !in_array('strike', $a['only'], true)) continue;
                if (!empty($a['types'])) continue;

                if (!empty($a['condition'])
                    && !self::checkCondition($a['condition'], $state, $card)) continue;

                $abilityStrikeBonus += $val;
            }

            if ($abilityStrikeBonus !== 0) {
                $result['ability_strike'] = $abilityStrikeBonus;
            }
        }

        return $result;
    }

    /**
     * Считает союзников в строю (ортогонально, 4 клетки).
     */
    public static function countLineNeighbors(GameState $state, CardInstance $card): int
    {
        if ($card->zone !== CardInstance::ZONE_FIELD) return 0;
        if ($card->type === 'fly') return 0;

        $count = 0;
        foreach ($state->cards as $other) {
            if ($other->dying) continue;
            if ($other->owner !== $card->owner) continue;
            if ($other->instanceId === $card->instanceId) continue;
            if ($other->zone !== CardInstance::ZONE_FIELD) continue;
            if ($other->type === 'fly') continue;
            if (!self::hasLine($other)) continue;

            $dr = abs($other->row - $card->row);
            $dc = abs($other->col - $card->col);
            if ($dr + $dc !== 1) continue;

            $count++;
        }
        return $count;
    }

    private static function countEnemiesNearMatching(
        GameState $state, CardInstance $card, array $condition
    ): int {
        $count = 0;
        foreach ($state->cards as $c) {
            if ($c->owner === $card->owner) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->dying || $c->hp <= 0) continue;

            $dr = abs($c->row - $card->row);
            $dc = abs($c->col - $card->col);
            if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;

            if (!self::matchesEnemyFilter($c, $condition)) continue;
            $count++;
        }
        return $count;
    }

    private static function matchesEnemyFilter(CardInstance $c, array $condition): bool
    {
        if (isset($condition['weak_min']) && $c->strikeWeak < (int) $condition['weak_min']) {
            return false;
        }
        if (!empty($condition['has_magic']) && !self::enemyHasMagic($c)) {
            return false;
        }
        return true;
    }

    public static function enemyHasMagic(CardInstance $card): bool
    {
        foreach ($card->prop['actions'] ?? [] as $a) {
            $t = $a['type'] ?? '';
            if (in_array($t, ['discharge', 'magic', 'cast'], true)) return true;
        }
        return false;
    }
}
