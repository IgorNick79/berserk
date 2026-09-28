<?php
// src/View/Ui/BattlefieldPosition.php

declare(strict_types=1);

namespace Berserk\View\Ui;

use Berserk\Core\GameState;

final class BattlefieldPosition
{
    private const ROWS = 6;
    private const COLS = 5;

    /**
     * @return array{row:int,col:int}|null
     */
    public static function display(?int $row, ?int $col, string $viewerKey): ?array
    {
        if ($row === null || $col === null) {
            return null;
        }

        if ($viewerKey === GameState::PLAYER_PLAYER) {
            return [
                'row' => self::ROWS + 1 - $row,
                'col' => self::COLS + 1 - $col,
            ];
        }

        return ['row' => $row, 'col' => $col];
    }

    public static function label(?int $row, ?int $col, string $viewerKey): string
    {
        $pos = self::display($row, $col, $viewerKey);
        if ($pos === null) {
            return '';
        }

        return '[' . $pos['row'] . ':' . $pos['col'] . ']';
    }
}
