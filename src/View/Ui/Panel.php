<?php
// src/View/Ui/Panel.php

declare(strict_types=1);

namespace Berserk\View\Ui;

/**
 * Универсальный рендер диалогового блока InfoPanel.
 */
final class Panel
{
    public static function render(PanelSpec $spec): string
    {
        if (!$spec->isMine) {
            return '<div class="card-choice">'
                . '<h3>' . htmlspecialchars($spec->title, ENT_QUOTES) . '</h3>'
                . '<p class="wait">' . htmlspecialchars($spec->waitText, ENT_QUOTES) . '</p>'
                . '</div>';
        }

        $body = '';

        foreach ($spec->text as $t) {
            $body .= '<div class="panel-text">' . $t . '</div>';
        }

        if (!empty($spec->cards)) {
            $body .= '<div class="task-list">' . implode('', $spec->cards) . '</div>';
        }

        if (!empty($spec->buttons)) {
            $body .= '<div class="death-choice-buttons">';
            foreach ($spec->buttons as $b) {
                $cls   = 'button' . (!empty($b['class']) ? ' ' . $b['class'] : '');
                $url   = htmlspecialchars($b['url'], ENT_QUOTES);
                $label = htmlspecialchars($b['label'], ENT_QUOTES);
                $body .= '<a class="' . $cls . '" href="' . $url . '">' . $label . '</a> ';
            }
            $body .= '</div>';
        }

        if ($spec->form !== null) {
            $body .= self::renderForm($spec->form);
        }

        return '<div class="card-choice">'
            . '<h3>' . htmlspecialchars($spec->title, ENT_QUOTES) . '</h3>'
            . $body
            . '</div>';
    }

    private static function renderForm(array $form): string
    {
        $hidden     = $form['hidden'] ?? [];
        $hiddenHtml = self::renderHidden($hidden);

        $type = $form['type'] ?? 'radio';

        if ($type === 'multi_radio') {
            $body = self::renderMultiRadioGroups($form['groups'] ?? []);
        } else {
            $name = (string) ($form['name'] ?? 'value');
            $items = $form['items'] ?? [];
            $body = self::renderChoiceItems($items, $type, $name);
        }

        $submit = $form['submit'] ?? 'Подтвердить';
        $cancel = $form['cancel'] ?? null;

        $body .= '<button type="submit" class="button">' . htmlspecialchars($submit, ENT_QUOTES) . '</button>';

        if ($cancel !== null) {
            $body .= ' <a class="button skip" href="' . htmlspecialchars($cancel, ENT_QUOTES) . '">Отмена</a>';
        }

        return '<form method="get" class="card-choice-form">'
            . $hiddenHtml
            . $body
            . '</form>';
    }

    private static function renderHidden(array $hidden): string
    {
        $html = '';
        foreach ($hidden as $k => $v) {
            $html .= '<input type="hidden" name="' . htmlspecialchars((string) $k, ENT_QUOTES)
                . '" value="' . htmlspecialchars((string) $v, ENT_QUOTES) . '">';
        }
        return $html;
    }

    private static function renderChoiceItems(array $items, string $type, string $name): string
    {
        $html = '';
        foreach ($items as $it) {
            $value    = htmlspecialchars((string) ($it['value'] ?? ''), ENT_QUOTES);
            $label    = htmlspecialchars((string) ($it['label'] ?? ''), ENT_QUOTES);
            $checked  = !empty($it['checked']) ? ' checked' : '';
            $disabled = !empty($it['disabled']) ? ' disabled' : '';

            $html .= '<label class="choice-radio">'
                . '<input type="' . htmlspecialchars($type, ENT_QUOTES) . '"'
                . ' name="' . htmlspecialchars($name, ENT_QUOTES) . '"'
                . ' value="' . $value . '"' . $checked . $disabled . '>'
                . '<span>' . $label . '</span>'
                . '</label>';
        }
        return $html;
    }

    private static function renderMultiRadioGroups(array $groups): string
    {
        $html = '';
        foreach ($groups as $group) {
            $name  = (string) ($group['name'] ?? 'value');
            $label = (string) ($group['label'] ?? '');
            $items = $group['items'] ?? [];

            $html .= '<div class="multi-radio-group">';

            if ($label !== '') {
                $html .= '<div class="multi-radio-label">'
                    . htmlspecialchars($label, ENT_QUOTES) . '</div>';
            }

            $html .= self::renderChoiceItems($items, 'radio', $name);
            $html .= '</div>';
        }
        return $html;
    }
}