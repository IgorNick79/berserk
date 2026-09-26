<?php
// src/Core/Movement/MovementContext.php

declare(strict_types=1);

namespace Berserk\Core\Movement;

use Berserk\Core\CardInstance;

final class MovementContext
{
    public const TYPE_MOVE = 'move';
    public const TYPE_JUMP = 'jump';

    public function __construct(
        public readonly CardInstance $card,
        public readonly string $playerKey,
        public readonly int $fromRow,
        public readonly int $fromCol,
        public readonly int $toRow,
        public readonly int $toCol,
        public readonly string $movementType,
    ) {}

    public function deltaRow(): int
    {
        return $this->toRow - $this->fromRow;
    }

    public function deltaCol(): int
    {
        return $this->toCol - $this->fromCol;
    }

    public function manhattanDistance(): int
    {
        return abs($this->deltaRow()) + abs($this->deltaCol());
    }
}
