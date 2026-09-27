<?php
// src/Core/ZoneManager.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Переходы карт между зонами и запросы по зонам.
 * Все переходы — через этот класс; он же отвечает за инварианты:
 *  - кладбище: полный сброс (маркеры, модификаторы, монеты, броня, позиция);
 *  - летающие: авто-выделение слота;
 *  - поле: проверки занятости и разрешённых клеток при расстановке.
 */
final class ZoneManager
{
    public function __construct(private GameState $state) {}

    // ─── Переходы ─────────────────────────────────────────────

    public function toHand(CardInstance $card): void
    {
        $this->handleLinkedRecruitSourceLeavingBattlefield($card);
        $this->clearPosition($card);
        $card->zone = CardInstance::ZONE_HAND;
    }

    public function toSquad(CardInstance $card): void
    {
        $this->handleLinkedRecruitSourceLeavingBattlefield($card);
        $this->clearPosition($card);
        $card->zone = CardInstance::ZONE_SQUAD;
    }

    public function toDeck(CardInstance $card): void
    {
        $this->handleLinkedRecruitSourceLeavingBattlefield($card);
        $this->clearPosition($card);
        $card->zone = CardInstance::ZONE_DECK;
    }

    public function toField(CardInstance $card, int $row, int $col): void
    {
        $card->zone = CardInstance::ZONE_FIELD;
        $card->row  = $row;
        $card->col  = $col;
        $card->slot = 0;

        // Воскрешение снимает готовность Вальхаллы
        unset($card->flags['valhalla_active']);
        unset($card->markers['valhalla']);
    }

    public function toFlying(CardInstance $card): void
    {
        $used = [];
        foreach ($this->state->cards as $c) {
            if ($c->zone === CardInstance::ZONE_FLYING
                && $c->owner === $card->owner) {
                $used[$c->slot] = true;
            }
        }

        $slot = 1;
        while (isset($used[$slot])) $slot++;

        $card->zone = CardInstance::ZONE_FLYING;
        $card->row  = null;
        $card->col  = null;
        $card->slot = $slot;
    }

    /**
     * Полный сброс и переход на кладбище.
     * hpMax сохраняется — для эффектов воскрешения/изгнания.
     */
    public function toGraveyard(CardInstance $card): void
    {
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
    }

    public function toExile(CardInstance $card): void
    {
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

    public function isCellMarked(int $row, int $col): bool
    {
        $key = "{$row}_{$col}";
        return !empty($this->state->cell_markers[$key]);
    }

    public function getCellMarker(int $row, int $col): ?array
    {
        $key = "{$row}_{$col}";
        return $this->state->cell_markers[$key] ?? null;
    }

    public function setCellMarker(int $row, int $col, string $type, int $expire, string $timing, string $source): void
    {
        $key = "{$row}_{$col}";
        $this->state->cell_markers[$key] = [
            'type'   => $type,
            'expire' => $expire,
            'timing' => $timing,
            'source' => $source,
        ];
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
