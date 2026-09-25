<?php
// src/View/Screen/Battle/Choice/MultiRadioChoice.php

declare(strict_types=1);

namespace Berserk\View\Screen\Battle\Choice;

use Berserk\View\Ui\Form;

/**
 * Несколько групп radio в одной форме + submit.
 *
 * @example
 *   MultiRadioChoice::render(
 *       'Заголовок',
 *       ['first' => '', 'game' => 5, 'cmd' => 'foo'],
 *       [
 *           ['name' => 'enemy_id', 'label' => 'Враг', 'items' => [...], 'required' => true],
 *           ['name' => 'own_id',   'label' => 'Своё', 'items' => [...], 'required' => true],
 *       ]
 *   );
 */
final class MultiRadioChoice
{
    public static function render(
        string $title,
        array $hidden,
        array $groups,
        string $submitLabel = 'Подтвердить',
        ?string $cancelUrl = null
    ): string {
        $body = '';
        foreach ($groups as $group) {
            $body .= '<div class="multi-radio-group">';
            if (!empty($group['label'])) {
                $body .= '<div class="multi-radio-label">'
                    . htmlspecialchars((string) $group['label'], ENT_QUOTES)
                    . '</div>';
            }
            $body .= Form::choiceList(
                $group['items'] ?? [],
                'radio',
                (string) $group['name']
            );
            $body .= '</div>';
        }

        $body .= Form::submit($submitLabel);
        if ($cancelUrl !== null) {
            $body .= ' ' . Form::button('Отмена', $cancelUrl, ['class' => 'skip']);
        }

        return Form::cardChoice($title, Form::form($hidden, $body));
    }
}