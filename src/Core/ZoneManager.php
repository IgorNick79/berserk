<?php
// src/Core/ZoneManager.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Переходы карт между зонами и запросы по зонам.
 * Все переходы — через этот класс; он же отвечает за инварианты:
 *  - кладбище: полный сброс (маркеры, модификаторы, монеты, броня, позиция);
 *  - летающие: авто-выделение и нормализация слотов;
 *  - поле: проверки занятости и разрешённых клеток при расстановке.
 */
final class ZoneManager
{
    public function __construct(private GameState $state) {}

    // ─── Переходы ─────────────────────────────────────────────

    public function toHand(CardInstance $card): void
    {
        $flyingOwner = $this->flyingOwnerBeforeTransition($card);
        $this->handleLinkedRecruitSourceLeavingBattlefield($card);
        $this->clearPosition($card);
        $card->zone = CardInstance::ZONE_HAND;
        $this->normalizeFlyingSlots($flyingOwner);
    }

    public function toSquad(CardInstance $card): void
    {
        $flyingOwner = $this->flyingOwnerBeforeTransition($card);
        $this->handleLinkedRecruitSourceLeavingBattlefield($card);
        $this->clearPosition($card);
        $card->zone = CardInstance::ZONE_SQUAD;
        $this->normalizeFlyingSlots($flyingOwner);
    }

    public function toDeck(CardInstance $card): void
    {
        $flyingOwner = $this->flyingOwnerBeforeTransition($card);
        $this->handleLinkedRecruitSourceLeavingBattlefield($card);
        $this->clearPosition($card);
        $card->zone = CardInstance::ZONE_DECK;
        $this->normalizeFlyingSlots($flyingOwner);
    }

    public function toSideboard(CardInstance $card): void
    {
        $flyingOwner = $this->flyingOwnerBeforeTransition($card);
        $this->handleLinkedRecruitSourceLeavingBattlefield($card);
        $this->clearPosition($card);
        $card->zone = CardInstance::ZONE_SIDEBOARD;
        $this->normalizeFlyingSlots($flyingOwner);
    }

    public function toField(CardInstance $card, int $row, int $col): void
    {
        $flyingOwner = $this->flyingOwnerBeforeTransition($card);
        $card->zone = CardInstance::ZONE_FIELD;
        $card->row  = $row;
        $card->col  = $col;
        $card->slot = 0;
        $this->normalizeFlyingSlots($flyingOwner);

        // Воскрешение снимает готовность Вальхаллы
        unset($card->flags['valhalla_active']);
        unset($card->markers['valhalla']);
    }

    public function toFlying(CardInstance $card): void
    {
        if ($card->zone === CardInstance::ZONE_FLYING) {
            $this->normalizeFlyingSlots($card->owner);
            return;
        }

        $card->zone = CardInstance::ZONE_FLYING;
        $card->row  = null;
        $card->col  = null;
        $card->slot = $this->nextFlyingSlot($card->owner);

        $this->normalizeFlyingSlots($card->owner);
    }

    /**
     * Полный сброс и переход на кладбище.
     * hpMax сохраняется — для эффектов воскрешения/изгнания.
     */
    public function toGraveyard(CardInstance $card): void
    {
        $flyingOwner = $this->flyingOwnerBeforeTransition($card);
        $this->handleLinkedRecruitSourceLeavingBattlefield($card);

        // Уже инкарнировалась → в изгнание (не удаляем)
        if (!empty($card->flags['incarnated'])) {
            $this->toExile($card);
            return;
        }

        $valhallaReady = !empty($card->flags['valhalla_pending'])
            && !empty($card->prop['valhalla']);

        $card->zone      = CardInstance::ZONE_GRAVEYARD;
        $card->dying     = false;
        $card->closed    = true;
        $card->hp        = 0;
        $card->armor     = 0;
        $card->armorMax  = 0;
        $card->coins     = 0;
        $card->modifiers = [];
        $this->clearPosition($card);

        $inc = $card->prop['incarnation'] ?? null;
        if ($inc !== null) {
            $threshold = is_array($inc) ? (int) ($inc['turns'] ?? 3) : (int) $inc;
            $open      = is_array($inc) && !empty($inc['open']);

            $card->markers = [
                'incarnation' => [
                    'value'     => 0,
                    'threshold' => $threshold,
                    'open'      => $open,
                ],
            ];
        } else {
            $card->markers   = [];
            $card->flags     = [];

            if ($valhallaReady) {
                $card->flags['valhalla_active'] = true;
                $card->markers['valhalla'] = ['value' => 1];
            }
        }

        $this->normalizeFlyingSlots($flyingOwner);
    }

    public function toExile(CardInstance $card): void
    {
        $flyingOwner = $this->flyingOwnerBeforeTransition($card);
        $this->handleLinkedRecruitSourceLeavingBattlefield($card);
        $card->zone      = CardInstance::ZONE_EXILE;
        $card->dying     = false;
        $card->closed    = true;
        $card->revealed  = true;
        $card->hp        = 0;
        $card->armor     = 0;
        $card->armorMax  = 0;
        $card->coins     = 0;
        $card->modifiers = [];
        $card->markers   = [];
        $card->flags     = [];
        $this->clearPosition($card);
        $this->normalizeFlyingSlots($flyingOwner);
    }

    public function flushDying(): void
    {
        foreach ($this->state->cards as $card) {
            if ($card->dying) {
                $this->toGraveyard($card);
            }
        }
    }

    // ─── Запросы по полю ─────────────────────────────────────

        /** @return array<int, array> */
    public static function markersAt(GameState $state, string $key): array
    {
        $m = $state->cell_markers[$key] ?? null;
        if (empty($m)) return [];
        // Старый формат: один маркер ['type' => ...]
        if (isset($m['type'])) return [$m];
        // Новый формат: список маркеров
        return is_array($m) ? $m : [];
    }

    public static function hasMarker(GameState $state, string $key): bool
    {
        return !empty(self::markersAt($state, $key));
    }

    public static function hasBlockingMarker(GameState $state, string $key): bool
    {
        foreach (self::markersAt($state, $key) as $m) {
            if (self::markerBlocksMovement($m)) return true;
        }
        return false;
    }

    public static function addMarker(GameState $state, string $key, array $marker): void
    {
        $list = self::markersAt($state, $key);
        $list[] = $marker;
        $state->cell_markers[$key] = $list;
    }

    /** Удалить только маркеры с указанным type (и при необходимости source). */
    public static function removeMarkersByType(GameState $state, string $key, string $type, ?string $source = null): void
    {
        $list = self::markersAt($state, $key);
        $list = array_values(array_filter($list, function ($m) use ($type, $source) {
            if (($m['type'] ?? '') !== $type) return true;
            if ($source !== null && ($m['source'] ?? null) !== $source) return true;
            return false;
        }));
        if (empty($list)) {
            unset($state->cell_markers[$key]);
        } else {
            $state->cell_markers[$key] = $list;
        }
    }

    public static function markerBlocksMovement(array $marker): bool
    {
        // Клетку блокирует только костёр. Остальные маркеры (бомба и т.д.) — нет.
        return ($marker['type'] ?? '') === 'bonfire';
    }

    public function isCellMarked(int $row, int $col): bool
    {
        return self::hasBlockingMarker($this->state, "{$row}_{$col}");
    }

    public function setCellMarker(
        int $row, int $col, string $type,
        int $expire, string $timing, string $source
    ): void {
        $key = "{$row}_{$col}";
        $existing = self::markersAt($this->state, $key);

        // Ищем маркер того же типа от того же источника — обновляем
        foreach ($existing as $i => $m) {
            if (($m['type'] ?? '') === $type && ($m['source'] ?? '') === $source) {
                $existing[$i]['expire'] = $expire;
                $existing[$i]['timing'] = $timing;
                $this->state->cell_markers[$key] = $existing;
                return;
            }
        }

        // Иначе — добавляем
        $existing[] = [
            'type'   => $type,
            'expire' => $expire,
            'timing' => $timing,
            'source' => $source,
        ];
        $this->state->cell_markers[$key] = $existing;
    }
    
    public function isFieldOccupied(int $row, int $col): bool
    {
        return $this->getFieldCard($row, $col) !== null;
    }

    public function getFieldCard(int $row, int $col): ?CardInstance
    {
        foreach ($this->state->cards as $card) {
            if ($card->zone === CardInstance::ZONE_FIELD
                && $card->row === $row
                && $card->col === $col) {
                return $card;
            }
        }
        return null;
    }

    public static function areAdjacentFieldCards(CardInstance $a, CardInstance $b): bool
    {
        if ($a->zone !== CardInstance::ZONE_FIELD || $b->zone !== CardInstance::ZONE_FIELD) {
            return false;
        }
        if ($a->row === null || $a->col === null || $b->row === null || $b->col === null) {
            return false;
        }

        $dr = abs($a->row - $b->row);
        $dc = abs($a->col - $b->col);
        return $dr <= 1 && $dc <= 1 && ($dr + $dc) > 0;
    }

    /**
     * @return int[]
     */
    public static function adjacentFieldAllyIds(GameState $state, CardInstance $origin, string $owner): array
    {
        if ($origin->owner !== $owner) {
            return [];
        }

        $ids = [];
        foreach ($state->cards as $card) {
            if ($card->owner !== $owner) continue;
            if ($card->instanceId === $origin->instanceId) continue;
            if ($card->dying || $card->hp <= 0) continue;
            if (!self::areAdjacentFieldCards($origin, $card)) continue;

            $ids[] = $card->instanceId;
        }
        return $ids;
    }

    public function countInZone(string $owner, string $zone): int
    {
        $n = 0;
        foreach ($this->state->cards as $card) {
            if ($card->owner === $owner && $card->zone === $zone) $n++;
        }
        return $n;
    }

    // ─── Расстановка ─────────────────────────────────────────

    public static function cellLevels(string $playerKey): array
    {
        return $playerKey === GameState::PLAYER_HOST
            ? [
                ['1_2','1_3','1_4','2_2','2_3','2_4','3_2','3_3','3_4'],
                ['1_1','1_5','2_1','2_5'],
                ['3_1','3_5'],
            ]
            : [
                ['4_1','4_2','4_3','4_4','4_5','5_2','5_3','5_4','6_2','6_3','6_4'],
                ['5_1','5_5','6_1','6_5'],
            ];
    }

    public function isCellAllowed(string $playerKey, int $row, int $col): bool
    {
        $levels  = self::cellLevels($playerKey);
        $cellKey = "{$row}_{$col}";

        $occupied = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner === $playerKey
                && $card->zone === CardInstance::ZONE_FIELD) {
                $occupied["{$card->row}_{$card->col}"] = true;
            }
        }

        foreach ($levels as $i => $level) {
            if (!in_array($cellKey, $level, true)) continue;

            for ($j = 0; $j < $i; $j++) {
                foreach ($levels[$j] as $prevCell) {
                    if (!isset($occupied[$prevCell])) return false;
                }
            }
            return true;
        }
        return false;
    }

    // ─── Внутреннее ──────────────────────────────────────────

    private function normalizeFlyingSlots(?string $owner = null): void
    {
        if ($owner === null) {
            foreach ([GameState::PLAYER_HOST, GameState::PLAYER_PLAYER] as $playerKey) {
                $this->normalizeFlyingSlots($playerKey);
            }
            return;
        }

        $cards = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner === $owner && $card->zone === CardInstance::ZONE_FLYING) {
                $cards[] = $card;
            }
        }

        usort($cards, static function (CardInstance $a, CardInstance $b): int {
            $aSlot = $a->slot > 0 ? $a->slot : PHP_INT_MAX;
            $bSlot = $b->slot > 0 ? $b->slot : PHP_INT_MAX;
            return [$aSlot, $a->instanceId] <=> [$bSlot, $b->instanceId];
        });

        $slot = 1;
        foreach ($cards as $card) {
            $card->row = null;
            $card->col = null;
            $card->slot = $slot++;
        }
    }

    private function flyingOwnerBeforeTransition(CardInstance $card): ?string
    {
        return $card->zone === CardInstance::ZONE_FLYING ? $card->owner : null;
    }

    private function nextFlyingSlot(string $owner): int
    {
        $max = 0;
        foreach ($this->state->cards as $card) {
            if ($card->owner === $owner && $card->zone === CardInstance::ZONE_FLYING) {
                $max = max($max, $card->slot);
            }
        }
        return $max + 1;
    }

    private function clearPosition(CardInstance $card): void
    {
        $card->row  = null;
        $card->col  = null;
        $card->slot = 0;
    }

    private function handleLinkedRecruitSourceLeavingBattlefield(CardInstance $card): void
    {
        if ($card->zone !== CardInstance::ZONE_FIELD
            && $card->zone !== CardInstance::ZONE_FLYING) {
            return;
        }
        if (($card->flags['deal_linked_recruit']['role'] ?? null) !== 'source') {
            return;
        }

        $companionId = (int) ($card->flags['deal_linked_recruit']['linked_instance_id'] ?? 0);
        if ($companionId <= 0) {
            return;
        }

        $companion = $this->state->getCard($companionId);
        if (!$companion) {
            return;
        }
        if (($companion->flags['deal_linked_recruit']['role'] ?? null) !== 'companion') {
            return;
        }
        if ((int) ($companion->flags['deal_linked_recruit']['linked_instance_id'] ?? 0) !== $card->instanceId) {
            return;
        }
        if ($companion->zone !== CardInstance::ZONE_FIELD
            && $companion->zone !== CardInstance::ZONE_FLYING) {
            return;
        }
        if ($companion->dying || $companion->hp <= 0) {
            return;
        }

        $companion->hp = 0;
        $companion->dying = true;

        if (empty($this->state->battle['strike'])) {
            $this->toGraveyard($companion);
        }
    }
}
