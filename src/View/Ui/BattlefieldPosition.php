<?php

declare(strict_types=1);
namespace Berserk\View\Ui;
use Berserk\Core\GameState;

final class BattlefieldPosition
{
    /** @return array{row:int,col:int,side:string}|null */
    public static function display(?int $row, ?int $col, string $viewerKey): ?array
    {
        if ($row === null || $col === null || $row < 1 || $row > 6 || $col < 1 || $col > 5
            || !in_array($viewerKey, [GameState::PLAYER_HOST, GameState::PLAYER_PLAYER], true)) {
            return null;
        }
        $owner = $row <= 3 ? GameState::PLAYER_HOST : GameState::PLAYER_PLAYER;
        return [
            'row' => $row <= 3 ? 4 - $row : $row - 3,
            'col' => $viewerKey === GameState::PLAYER_PLAYER ? 6 - $col : $col,
            'side' => $owner === $viewerKey ? 'own' : 'enemy',
        ];
    }

    public static function label(?int $row, ?int $col, string $viewerKey): string
    {
        $pos = self::display($row, $col, $viewerKey);
        if ($pos === null) return '';
        return ($pos['side'] === 'own' ? 'Свой' : 'Вражеский')
            . ' [' . $pos['row'] . ':' . $pos['col'] . ']';
    }
}