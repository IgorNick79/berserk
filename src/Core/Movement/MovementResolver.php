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
        if (CardStats::isDisabled($card)) {
            $reason = CardStats::disabledReason($card);
            return Result::error($reason !== '' ? $reason : 'Карта не может двигаться');
        }

        if (!$this->keepsForcedStrikeTarget($card, $row, $col)) {
            return Result::error('Нельзя уйти от обязательной цели');
        }

        if ($err = $this->checkMovesLimit($card)) {
            return $err;
        }

        if (\Berserk\Core\Strangulation::count($card) > 0) {
            return Result::error('Самостоятельное передвижение запрещено маркерами удушения');
        }
        if (isset($card->markers['rooted'])) {
            return Result::error('Карта обездвижена');
        }
        if ($card->move <= 0) {
            return Result::error('Нет ходов');
        }

        $drow = abs($row - $card->row);
        $dcol = abs($col - $card->col);

        $canDiag = !empty($card->effectiveProp()['can_move_diagonal']);
        $isOrtho = ($drow + $dcol) === 1;
        $isDiag  = $canDiag && $drow === 1 && $dcol === 1;

        $isRowExtreme = !empty($card->effectiveProp()['row_extreme'])
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
        $this->markMovedUnderLimit($card);

        if (!empty($card->effectiveProp()['strike_consecutive'])
            && (int) ($card->flags['attacks_used_this_turn'] ?? 0) > 0) {
            $card->flags['strike_chain_broken'] = true;
        }

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

        $this->state->historyRecorder?->moved($this->state,$card,$oldRow,$oldCol);
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
        if (CardStats::isDisabled($card)) {
            $reason = CardStats::disabledReason($card);
            return Result::error($reason !== '' ? $reason : 'Карта не может двигаться');
        }
        if (!empty($card->flags['moved_this_turn'])) {
            return Result::error('Существо уже двигалось в этот ход');
        }
        if (\Berserk\Core\Strangulation::count($card) > 0) {
            return Result::error('Самостоятельное передвижение запрещено маркерами удушения');
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

        if ($err = $this->checkMovesLimit($card)) {
            return $err;
        }

        return $this->executeJump($card, $row, $col, true);
    }

    private function executeJump(CardInstance $card, int $row, int $col, bool $spendAction): Result
    {
        $playerKey = $card->owner;
        $cardId = $card->instanceId;
        $oldRow = $card->row;
        $oldCol = $card->col;

        $card->row = $row;
        $card->col = $col;
        if ($spendAction) {
            $card->move = 0;
            $card->flags['moved_this_turn'] = true;
        }
        $this->markMovedUnderLimit($card);

        // Берсерк: движение после атаки ломает цепочку
        if ($spendAction && !empty($card->effectiveProp()['strike_consecutive'])
            && (int) ($card->flags['attacks_used_this_turn'] ?? 0) > 0) {
            $card->flags['strike_chain_broken'] = true;
        }

        $context = new MovementContext(
            card: $card,
            playerKey: $playerKey,
            fromRow: $oldRow,
            fromCol: $oldCol,
            toRow: $row,
            toCol: $col,
            movementType: MovementContext::TYPE_JUMP,
            origin: $spendAction ? 'ordinary' : 'passive',
        );
        $this->effects->apply($context);
        $this->tryShaksiRoll($card, $playerKey);

        $this->state->bumpVersion();

        return Result::ok(["card_jumped:{$playerKey}:{$cardId}:{$row}_{$col}"]);
    }

    /** Effect-driven jump: the same destinations and movement effects, without paying an action. */
    public function triggeredJumpCells(CardInstance $card, int $range): array
    {
        if (\Berserk\Core\Strangulation::count($card) > 0) return [];
        if ($card->zone !== CardInstance::ZONE_FIELD || $card->dying || $card->hp <= 0) return [];
        $cells = \Berserk\Core\BattleHelper::getJumpCells($this->state, $card, $range);
        foreach (array_keys($cells) as $key) {
            [$row, $col] = array_map('intval', explode('_', $key));
            if (!$this->keepsForcedStrikeTarget($card, $row, $col)) unset($cells[$key]);
        }
        return $cells;
    }

    public function triggeredJump(CardInstance $card, int $range, int $row, int $col): Result
    {
        if (!isset($this->triggeredJumpCells($card, $range)["{$row}_{$col}"])) {
            return Result::error('Недопустимая клетка прыжка');
        }
        return $this->executeJump($card, $row, $col, false);
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
        if (ZoneManager::hasBlockingMarker($this->state, "{$row}_{$col}")) {
            return Result::error('На клетке маркер');
        }

        return null;
    }

    private function findJumpAction(CardInstance $card): ?array
    {
        foreach ($card->effectiveProp()['actions'] ?? [] as $action) {
            if (($action['type'] ?? '') === 'jump') {
                return $action;
            }
        }

        return null;
    }

    private function keepsForcedStrikeTarget(CardInstance $card, int $row, int $col): bool
    {
        if (empty($card->effectiveProp()['forced_strike'])) {
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

    private function checkMovesLimit(CardInstance $card): ?Result
    {
        $limit = $this->state->battle['moves_limit'] ?? null;
        if (!$limit) return null;
        if (($limit['owner'] ?? '') !== $card->owner) return null;

        $moved = (array) ($limit['moved_ids'] ?? []);
        if (in_array($card->instanceId, $moved, true)) return null;

        if (count($moved) >= (int) ($limit['limit'] ?? 0)) {
            return Result::error('Ледяной дождь: превышен лимит движущихся существ');
        }
        return null;
    }

    private function markMovedUnderLimit(CardInstance $card): void
    {
        $limit = &$this->state->battle['moves_limit'];
        if (!$limit) return;
        if (($limit['owner'] ?? '') !== $card->owner) return;

        $moved = &$limit['moved_ids'];
        if (!is_array($moved)) $moved = [];
        if (in_array($card->instanceId, $moved, true)) return;
        $moved[] = $card->instanceId;
    }

    private function tryShaksiRoll(CardInstance $card, string $playerKey): void
    {
        $config = $card->effectiveProp()['on_jump_roll'] ?? null;
        if (!is_array($config) || empty($config)) return;
        if ($card->zone !== CardInstance::ZONE_FIELD) return;
        if ($card->row === null || $card->col === null) return;

        $dice = \Berserk\Core\CardRoll::roll($this->state, $card);

        $matchedKey = null;
        $effect = null;
        foreach ($config as $range => $eff) {
            $parts = explode('-', (string) $range);
            if (count($parts) !== 2) continue;
            $min = (int) $parts[0];
            $max = (int) $parts[1];
            if ($dice >= $min && $dice <= $max) {
                $matchedKey = (string) $range;
                $effect = (array) $eff;
                break;
            }
        }
        if ($effect === null) return;

        $candidates = $this->shaksiCandidates($card, $effect);
        if (empty($candidates)) return;

        $this->state->battle['pending_shaksi_roll'] = [
            'owner'      => $playerKey,
            'source_id'  => $card->instanceId,
            'dice'       => $dice,
            'range'      => $matchedKey,
            'effect'     => $effect,
            'candidates' => $candidates,
        ];
    }

    private function shaksiCandidates(CardInstance $card, array $effect): array
    {
        $nearby = [];
        foreach ($this->state->cards as $c) {
            if ($c->instanceId === $card->instanceId) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->dying || $c->hp <= 0) continue;

            $dr = abs($c->row - $card->row);
            $dc = abs($c->col - $card->col);
            if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;

            $nearby[] = $c;
        }

        if (empty($nearby)) return [];

        $type = (string) ($effect['type'] ?? '');
        $candidates = [];

        foreach ($nearby as $c) {
            if ($type === 'get_coin') {
                if (!empty($c->effectiveProp()['coins_receive_deny'])) continue;
                $max = (int) ($c->effectiveProp()['coins']['max_value'] ?? 0);
                if ($max > 0 && $c->coins >= $max) continue;
            }
            $candidates[] = $c->instanceId;
        }

        if (!empty($effect['include_self']) && $card->hp < $card->hpMax) {
            $candidates[] = $card->instanceId;
        }

        return $candidates;
    }

}
