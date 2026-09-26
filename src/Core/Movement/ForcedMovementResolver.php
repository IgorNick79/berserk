<?php
// src/Core/Movement/ForcedMovementResolver.php

declare(strict_types=1);

namespace Berserk\Core\Movement;

use Berserk\Core\CardInstance;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Result;
use Berserk\Core\ZoneManager;

final class ForcedMovementResolver
{
    public const PENDING_KEY = 'pending_forced_directional_move';

    private MovementEffectResolver $effects;

    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {
        $this->effects = new MovementEffectResolver($state, $engine);
    }

    /**
     * @return CardInstance[]
     */
    public function eligibleTargets(string $playerKey, int $deltaRow, int $deltaCol): array
    {
        $targets = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $playerKey) continue;
            if (!$this->canMove($card, $deltaRow, $deltaCol)) continue;

            $targets[] = $card;
        }

        return $targets;
    }

    public function canMove(CardInstance $card, int $deltaRow, int $deltaCol): bool
    {
        if ($card->zone !== CardInstance::ZONE_FIELD) return false;
        if ($card->type !== 'creature') return false;
        if ($card->dying || $card->hp <= 0) return false;
        if (isset($card->markers['rooted'])) return false;

        return $this->destination($card, $deltaRow, $deltaCol) !== null;
    }

    /**
     * @return ?array{row:int,col:int}
     */
    public function destination(CardInstance $card, int $deltaRow, int $deltaCol): ?array
    {
        if ($deltaRow === 0 && $deltaCol === 0) return null;

        $row = (int) $card->row + $deltaRow;
        $col = (int) $card->col + $deltaCol;

        if ($row < 1 || $row > 6 || $col < 1 || $col > 5) {
            return null;
        }

        $zone = new ZoneManager($this->state);
        if ($zone->isFieldOccupied($row, $col)) {
            return null;
        }
        if (!empty($this->state->cell_markers["{$row}_{$col}"])) {
            return null;
        }

        return ['row' => $row, 'col' => $col];
    }

    public function move(CardInstance $card, int $deltaRow, int $deltaCol): Result
    {
        if (!$this->canMove($card, $deltaRow, $deltaCol)) {
            return Result::error('Существо не может быть перемещено');
        }

        $destination = $this->destination($card, $deltaRow, $deltaCol);
        if ($destination === null) {
            return Result::error('Нет доступной клетки');
        }

        $oldRow = (int) $card->row;
        $oldCol = (int) $card->col;

        $card->row = $destination['row'];
        $card->col = $destination['col'];

        $context = new MovementContext(
            card: $card,
            playerKey: $card->owner,
            fromRow: $oldRow,
            fromCol: $oldCol,
            toRow: $card->row,
            toCol: $card->col,
            movementType: MovementContext::TYPE_FORCED_MOVE,
        );
        $this->effects->applyForcedPositionChange($context);

        $this->state->bumpVersion();
        return Result::ok(["forced_moved:{$card->owner}:{$card->instanceId}:{$card->row}_{$card->col}"]);
    }

    public static function applyFallback(CardInstance $source, array $fallback): bool
    {
        $modifier = $fallback['modifier'] ?? null;
        if (!is_array($modifier) || empty($modifier['stat'])) {
            return false;
        }

        $applied = [
            'stat'   => (string) $modifier['stat'],
            'value'  => array_key_exists('value', $modifier) ? $modifier['value'] : 1,
            'expire' => $modifier['expire'] ?? 'end_of_turn',
        ];

        foreach (['types', 'consume', 'source'] as $key) {
            if (array_key_exists($key, $modifier)) {
                $applied[$key] = $modifier[$key];
            }
        }

        $source->modifiers[] = $applied;
        return true;
    }
}
