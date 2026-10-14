<?php

declare(strict_types=1);
namespace Berserk\View\Ui;

final class Coords
{
    public static function rowLabel(int $row): string
    {
        return [1 => 'I', 2 => 'II', 3 => 'III'][$row] ?? '';
    }

    public static function render(?int $row, ?int $col, string $viewerKey): string
    {
        $pos = BattlefieldPosition::display($row, $col, $viewerKey);
        if ($pos === null) return '';
        return '<span class="coords coords--' . $pos['side'] . '">'
            . htmlspecialchars(BattlefieldPosition::label($row, $col, $viewerKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</span>';
    }
}