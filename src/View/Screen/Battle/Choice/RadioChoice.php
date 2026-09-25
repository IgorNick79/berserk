<?php
// src/View/Screen/Battle/Choice/RadioChoice.php

declare(strict_types=1);

namespace Berserk\View\Screen\Battle\Choice;

use Berserk\View\Ui\Form;

/**
 * Заголовок + список radio + submit.
 * Используется: coin_spend, self_wound, card_choice, transfer (шаг 2).
 */
final class RadioChoice
{
    /**
     * @param array<string, mixed> $hidden  hidden-поля формы
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
        $body = Form::choiceList($items, 'radio', $name)
            . Form::submit($submitLabel);

        if ($cancelUrl !== null) {
            $body .= ' ' . Form::button('Отмена', $cancelUrl, ['class' => 'skip']);
        }

        return Form::cardChoice($title, Form::form($hidden, $body));
    }
}