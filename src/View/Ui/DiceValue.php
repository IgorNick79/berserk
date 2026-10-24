<?php
declare(strict_types=1);
namespace Berserk\View\Ui;
final class DiceValue
{
    /** Raw die and modifier already resolved by the engine. No clamping or rules here. */
    public static function render(int $die, int $modifier=0): string
    {
        if ($die===0) return '<span class="panel-dice-none" aria-label="Без броска">—</span>';
        $face=$die>=1 && $die<=6
            ? '<img class="panel-dice-face" src="/assets/images/battle/dice/dice_'.$die.'.svg" alt="'.$die.'" width="32" height="32">'
            : '<span class="panel-dice-number">'.$die.'</span>';
        $sign=$modifier>0?'+':'';
        $label='Бросок: '.$die.($modifier!==0?', модификатор: '.$sign.$modifier.', итог: '.($die+$modifier):'');
        return '<span class="panel-dice-value" role="img" aria-label="'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'">'.$face
            .($modifier!==0?'<span class="panel-dice-modifier" aria-hidden="true">'.$sign.$modifier.'</span><span class="panel-dice-total" aria-hidden="true">= '.($die+$modifier).'</span>':'').'</span>';
    }
}
