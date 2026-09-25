<?php
// src/View/Screen/Battle/Choice/CheckboxChoice.php

declare(strict_types=1);

namespace Berserk\View\Screen\Battle\Choice;

use Berserk\View\Ui\Form;

/**
 * Заголовок + список checkbox + submit.
 * Используется: multi_heal, multi_discharge.
 */
final class CheckboxChoice
{
    /**
     * @param array<string, mixed> $hidden
     * @param array<int, array{value:mixed, label:string, checked?:bool, disabled?:bool}> $items
     */
    public static function render(
        string $title,
        array $hidden,
        string $name,
        array $items,
        string $submitLabel = 'Подтвердить',
        ?string $cancelUrl = null
    ): string {
        $body = Form::choiceList($items, 'checkbox', $name)
            . Form::submit($submitLabel);

        if ($cancelUrl !== null) {
            $body .= ' ' . Form::button('Отмена', $cancelUrl, ['class' => 'skip']);
        }

        return Form::cardChoice($title, Form::form($hidden, $body));
    }
}