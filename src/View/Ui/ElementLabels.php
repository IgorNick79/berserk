<?php
// src/View/Ui/ElementLabels.php

declare(strict_types=1);

namespace Berserk\View\Ui;

final class ElementLabels
{
    /** @param array<string,string> $labels */
    public static function label(string $codeOrLabel, array $labels): string
    {
        return $labels[$codeOrLabel] ?? $codeOrLabel;
    }
}
