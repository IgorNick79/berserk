<?php
declare(strict_types=1);
namespace Berserk\View\Ui;
final class PanelTheme
{
    public const DEFAULT = 'grimoire';
    public const ITEMS = ['grimoire'=>'Бронзовый гримуар','stone'=>'Каменный архив','leather'=>'Старая кожа','parchment'=>'Тёмный пергамент'];
    public static function valid(mixed $value): bool { return is_string($value) && isset(self::ITEMS[$value]); }
}
