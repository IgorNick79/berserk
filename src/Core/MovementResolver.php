<?php
// src/Core/MovementResolver.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Отвечает за перемещение существ по полю: обычный ход и прыжок.
 *
 * Здесь находятся проверки перемещения и эффекты, которые срабатывают
 * непосредственно после смены клетки. Общие системные эффекты пока
 * делегируются Engine, чтобы рефакторинг не менял остальную игровую логику.
 */
final class MovementResolver
{
    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {}

    public function move(string $playerKey, Command $cmd): Result
    {
        if ($error = $this->validateTurn($playerKey)) {
            return $error;
        }

        $cardId = (int) $cmd->get('card_id', 0);
        $row    = (int) $cmd->get('row', 0);
        $col    = (int) $cmd->get('col', 0);

        $card = $this->state->getCard($cardId);
        if (!$card || $card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_FIELD) {
            return Result::error('Карта не на поле');
        }
        if ($card->closed) {
            return Result::error('Закрытая карта не может двигаться');
        }

        if (!$this->keepsForcedStrikeTarget($card, $row, $col)) {
            return Result::error('Нельзя уйти от обязательной цели');
        }

        if (isset($card->markers['rooted'])) {
            return Result::error('Карта обездвижена');
        }
        if ($card->move <= 0) {
            return Result::error('Нет ходов');
        }

        $drow = abs($row - $card->row);
        $dcol = abs($col - $card->col);

        $canDiag = !empty($card->prop['can_move_diagonal']);
        $isOrtho = ($drow + $dcol) === 1;
        $isDiag  = $canDiag && $drow === 1 && $dcol === 1;

        $isRowExtreme = !empty($card->prop['row_extreme'])
            && $drow === 0
            && (($card->col === 1 && $col === 5) || ($card->col === 5 && $col === 1));

        if (!$isOrtho && !$isDiag && !$isRowExtreme) {
            return Result::error('Только на соседнюю клетку');
        }

        if ($error = $this->validateDestination($row, $col)) {
            return $error;
        }

        $oldRow = $card->row;
        $oldCol = $card->col;

        $card->row = $row;
        $card->col = $col;
        $card->move--;
        $card->flags['moved_this_turn'] = true;

        $this->afterMove($card, $oldRow, $oldCol, $playerKey);

        $this->state->bumpVersion();

        return Result::ok(["card_moved:{$playerKey}:{$cardId}:{$row}_{$col}"]);
    }

    public function jump(string $playerKey, Command $cmd): Result
    {
        if ($error = $this->validateTurn($playerKey)) {
            return $error;
        }

        $cardId = (int) $cmd->get('card_id', 0);
        $row    = (int) $cmd->get('row', 0);
        $col    = (int) $cmd->get('col', 0);

        $card = $this->state->getCard($cardId);
        if (!$card || $card->owner !== $playerKey || $card->zone !== CardInstance::ZONE_FIELD) {
            return Result::error('Карта не на поле');
        }
        if ($card->closed) {
            return Result::error('Закрытая карта не может двигаться');
        }
        if (!empty($card->flags['moved_this_turn'])) {
            return Result::error('Существо уже двигалось в этот ход');
        }
        if (isset($card->markers['rooted'])) {
            return Result::error('Карта обездвижена');
        }

        $jumpAction = $this->findJumpAction($card);
        if ($jumpAction === null) {
            return Result::error('У карты нет прыжка');
        }

        if ($error = $this->validateDestination($row, $col)) {
            return $error;
        }

        $dr   = abs($row - $card->row);
        $dc   = abs($col - $card->col);
        $dist = $dr + $dc;

        if ($dist === 0) {
            return Result::error('Нельзя прыгнуть на ту же клетку');
        }

        $range = (int) ($jumpAction['range'] ?? 0);
        if ($range > 0 && $dist > $range) {
            return Result::error('Превышена дальность прыжка');
        }

        if (!$this->keepsForcedStrikeTarget($card, $row, $col)) {
            return Result::error('Нельзя уйти от обязательной цели');
        }

        $oldRow = $card->row;
        $oldCol = $card->col;

        $card->row = $row;
        $card->col = $col;
        $card->move = 0;
        $card->flags['moved_this_turn'] = true;

        $this->afterMove($card, $oldRow, $oldCol, $playerKey);

        $this->state->bumpVersion();

        return Result::ok(["card_jumped:{$playerKey}:{$cardId}:{$row}_{$col}"]);
    }

    private function validateTurn(string $playerKey): ?Result
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

        return null;
    }

    private function validateDestination(int $row, int $col): ?Result
    {
        // Сначала границы: не передаём заведомо некорректную клетку в ZoneManager.
        if ($row < 1 || $row > 6 || $col < 1 || $col > 5) {
            return Result::error('За пределами поля');
        }

        $zone = new ZoneManager($this->state);
        if ($zone->isFieldOccupied($row, $col)) {
            return Result::error('Клетка занята');
        }
        if (!empty($this->state->cell_markers["{$row}_{$col}"])) {
            return Result::error('На клетке маркер');
        }

        return null;
    }

    private function findJumpAction(CardInstance $card): ?array
    {
        foreach ($card->prop['actions'] ?? [] as $action) {
            if (($action['type'] ?? '') === 'jump') {
                return $action;
            }
        }

        return null;
    }

    /**
     * Басаарг: если у карты уже есть обязательная цель, перемещение не должно
     * позволять уйти так, чтобы эта цель перестала быть достижимой.
     */
    private function keepsForcedStrikeTarget(CardInstance $card, int $row, int $col): bool
    {
        if (empty($card->prop['forced_strike'])) {
            return true;
        }

        $forced = CardStats::getForcedStrikeTarget($this->state, $card);
        if ($forced === null) {
            return true;
        }

        $oldRow = $card->row;
        $oldCol = $card->col;

        $card->row = $row;
        $card->col = $col;
        $stillReachable = CardStats::getForcedStrikeTarget($this->state, $card);
        $card->row = $oldRow;
        $card->col = $oldCol;

        return $stillReachable !== null;
    }

    private function afterMove(
        CardInstance $card,
        int $oldRow,
        int $oldCol,
        string $playerKey,
    ): void {
        $this->applyMovePenalty($card);
        $this->engine->syncCoinBonus($card);
        $this->applyOnMoveEffects($card, $oldRow, $oldCol);
        $this->applyAirinTriggers($card, $oldRow, $oldCol);

        // Если двинулся Василиск — снять его rooted со всех целей.
        $this->engine->clearRootedBySource($this->state, $card->instanceId);

        $this->engine->refreshArmor($this->state);
        $this->maybeOpenForcedStrike($card, $playerKey);
    }

    private function applyMovePenalty(CardInstance $card): void
    {
        if (!empty($card->prop['lose_coins_on_move']) && $card->coins > 0) {
            $card->coins = 0;
        }
    }

    private function applyOnMoveEffects(CardInstance $card, int $oldRow = 0, int $oldCol = 0): void
    {
        $effects = $card->prop['on_move'] ?? [];
        if (is_array($effects)) {
            foreach ($effects as $effect) {
                if (($effect['type'] ?? '') === 'modifier') {
                    $card->modifiers[] = [
                        'stat'   => $effect['stat'] ?? 'ability_strike',
                        'value'  => (int) ($effect['value'] ?? 1),
                        'expire' => $effect['expire'] ?? 'end_of_turn',
                    ];
                }
            }
        }

        $halfEffects = $card->prop['on_move_half'] ?? [];
        if (!is_array($halfEffects) || empty($halfEffects)) {
            return;
        }
        if ($oldRow === 0 || $oldCol === 0) {
            return;
        }

        $fromHalf = $this->halfOf($card->owner, $oldRow);
        $toHalf   = $this->halfOf($card->owner, $card->row);

        foreach ($halfEffects as $effect) {
            if (($effect['from'] ?? '') !== $fromHalf) continue;
            if (($effect['to'] ?? '') !== $toHalf) continue;

            foreach ($effect['modifiers'] ?? [] as $modifier) {
                $card->modifiers[] = [
                    'stat'   => $modifier['stat'],
                    'value'  => (int) ($modifier['value'] ?? 1),
                    'expire' => $modifier['expire'] ?? 'end_of_turn',
                    'source' => $card->owner,
                ];
            }

            foreach ($effect['flags'] ?? [] as $flag) {
                $card->flags[$flag] = true;
            }
        }
    }

    private function halfOf(string $owner, int $row): string
    {
        if ($owner === 'host') {
            return $row <= 3 ? 'own' : 'enemy';
        }

        return $row >= 4 ? 'own' : 'enemy';
    }

    private function applyAirinTriggers(CardInstance $moved, int $oldRow, int $oldCol): void
    {
        $hasMagic = false;
        foreach ($moved->prop['actions'] ?? [] as $action) {
            $type = $action['type'] ?? '';
            if (in_array($type, ['discharge', 'magic', 'cast'], true)) {
                $hasMagic = true;
                break;
            }
        }
        if (!$hasMagic) {
            return;
        }

        foreach ($this->state->cards as $airin) {
            if ($airin->owner !== $moved->owner) continue;
            if ($airin->instanceId === $moved->instanceId) continue;
            if ($airin->zone !== CardInstance::ZONE_FIELD) continue;
            if (empty($airin->prop['airin_trigger'])) continue;
            if ($airin->dying || $airin->hp <= 0) continue;

            if ($this->isAdjacent($oldRow, $oldCol, $airin->row, $airin->col)) {
                continue;
            }
            if (!$this->isAdjacent($moved->row, $moved->col, $airin->row, $airin->col)) {
                continue;
            }

            $used = (int) ($airin->flags['airin_triggered_this_turn'] ?? 0);
            if ($used >= 2) continue;

            $airin->flags['airin_triggered_this_turn'] = $used + 1;
            $airin->modifiers[] = [
                'stat'   => 'ability_discharge',
                'value'  => 1,
                'expire' => 'end_of_turn',
                'source' => $moved->owner,
            ];
        }
    }

    private function isAdjacent(int $r1, int $c1, int $r2, int $c2): bool
    {
        $dr = abs($r1 - $r2);
        $dc = abs($c1 - $c2);

        return $dr <= 1 && $dc <= 1 && ($dr + $dc) > 0;
    }

    private function maybeOpenForcedStrike(CardInstance $card, string $playerKey): void
    {
        if (empty($card->prop['forced_strike'])) return;
        if ($card->move > 0) return;
        if ($card->closed) return;

        $candidates = [];
        foreach ($this->state->cards as $target) {
            if ($target->zone !== CardInstance::ZONE_FIELD) continue;
            if ($target->owner === $card->owner) continue;
            if (!$target->closed) continue;
            if ($target->dying || $target->hp <= 0) continue;

            $dr = abs($target->row - $card->row);
            $dc = abs($target->col - $card->col);
            if ($dr <= 1 && $dc <= 1 && ($dr + $dc) > 0) {
                $candidates[] = $target->instanceId;
            }
        }

        if (empty($candidates)) return;

        $this->state->battle['pending_forced_strike'] = [
            'owner'       => $playerKey,
            'attacker_id' => $card->instanceId,
            'candidates'  => $candidates,
        ];
    }
}
