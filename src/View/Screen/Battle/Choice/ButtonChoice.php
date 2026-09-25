<?php
// src/View/Screen/Battle/Choice/ButtonChoice.php

declare(strict_types=1);

namespace Berserk\View\Screen\Battle\Choice;

use Berserk\View\Ui\Form;

/**
 * Заголовок + группа кнопок-ссылок (GET).
 * Используется: transfer (шаг 1), any_death, incarnation, redirect, auto_target.
 */
final class ButtonChoice
{
    /**
     * @param array<int, array{label:string, url:string, class?:string}> $items
     */
    public static function render(
        string $title,
        array $items,
        string $wrapperClass = 'death-choice-buttons'
    ): string {
        return Form::cardChoice($title, Form::buttonList($items, $wrapperClass));
    }

    /**
     * Без заголовка — просто группа кнопок.
     * @param array<int, array{label:string, url:string, class?:string}> $items
     */
    public static function bare(
        array $items,
        string $wrapperClass = 'death-choice-buttons'
    ): string {
        return Form::buttonList($items, $wrapperClass);
    }
}