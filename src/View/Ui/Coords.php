<?php

declare(strict_types=1);
namespace Berserk\View\Ui;

final class Coords
{
    public static function rowLabel(int $row): string
    {
        return [1 => 'I', 2 => 'II', 3 => 'III'][$row] ?? '';
    }

    /** Compact result badge; the existing render()/label() contracts stay unchanged. */
    public static function renderResult(mixed $row, mixed $col, string $viewerKey): string
    {
        if (!is_int($row) || !is_int($col)) return '';
        $pos = BattlefieldPosition::display($row, $col, $viewerKey);
        if ($pos === null) return '';
        $label = '# [' . self::rowLabel($pos['row']) . ':' . $pos['col'] . ']';
        return '<span class="coords coords--' . $pos['side'] . '">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
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