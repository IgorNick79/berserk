<?php
// src/Core/Movement/MovementResolver.php

declare(strict_types=1);

namespace Berserk\Core\Movement;

use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Result;
use Berserk\Core\CardStats;
use Berserk\Core\ZoneManager;

final class MovementResolver
{
    private MovementEffectResolver $effects;

    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {
        $this->effects = new MovementEffectResolver($state, $engine);
    }

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

        $context = new MovementContext(
            card: $card,
            playerKey: $playerKey,
            fromRow: $oldRow,
            fromCol: $oldCol,
            toRow: $row,
            toCol: $col,
            movementType: MovementContext::TYPE_MOVE,
        );
        $this->effects->apply($context);

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

        $context = new MovementContext(
            card: $card,
            playerKey: $playerKey,
            fromRow: $oldRow,
            fromCol: $oldCol,
            toRow: $row,
            toCol: $col,
            movementType: MovementContext::TYPE_JUMP,
        );
        $this->effects->apply($context);

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
        if ($row < 1 || $row > 6 || $col < 1 || $col > 5) {
            return Result::error('За пределами поля');
        }

        $zone = new ZoneManager($this->state);
        if ($zone->isFieldOccupied($row, $col)) {
            return Result::error('Клетка занята');
        }
        $cm = $this->state->cell_markers["{$row}_{$col}"] ?? null;
        if ($cm !== null && ZoneManager::markerBlocksMovement($cm)) {
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
}
