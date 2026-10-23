<?php
declare(strict_types=1);
namespace Berserk\View\Ui;

/** Russian quantity agreement; callers supply forms for the required grammatical case. */
final class RussianNumber
{
    public static function form(int $count, string $one, string $few, string $many): string
    {
        $lastHundred=abs($count % 100);
        if ($lastHundred >= 11 && $lastHundred <= 14) return $many;
        $last=abs($count % 10);
        return $last === 1 ? $one : ($last >= 2 && $last <= 4 ? $few : $many);
    }
}
