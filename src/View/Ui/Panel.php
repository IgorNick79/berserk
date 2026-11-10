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
                . '<h3>' . Coords::text($spec->title) . '</h3>'
                . '<p class="wait">' . htmlspecialchars($spec->waitText, ENT_QUOTES) . '</p>'
                . '</div>';
        }

        $body = $spec->cellPicker !== null ? CellMarkerPicker::render($spec->cellPicker) : '';

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
                // As with radio labels below, only server-rendered markup opts in.
                $label = $b['label_html'] ?? Coords::text((string) $b['label']);
                $fallback = !empty($b['cell_marker_target']) ? ' data-cell-marker-fallback' : '';
                $body .= '<a class="' . htmlspecialchars($cls, ENT_QUOTES) . '" href="' . $url . '"' . $fallback . '>' . $label . '</a> ';
            }
            $body .= '</div>';
        }

        if ($spec->form !== null) {
            $body .= self::renderForm($spec->form, $spec->cardSelection);
        }

        $pickerAttr = $spec->cellPicker !== null
            ? ' data-cell-marker-picker="' . htmlspecialchars($spec->cellPicker['id'], ENT_QUOTES) . '"' : '';
        return '<div class="card-choice"' . $pickerAttr . '>'
            . '<h3>' . Coords::text($spec->title) . '</h3>'
            . $body
            . '</div>';
    }

    private static function renderForm(array $form, ?CardSelectionSpec $selection): string
    {
        $hidden     = $form['hidden'] ?? [];
        $hiddenHtml = self::renderHidden($hidden);

        $type = $form['type'] ?? 'radio';

        if ($selection !== null) {
            if ($selection->maxSelect !== 1 || $selection->minSelect < 0 || $selection->minSelect > 1
                || $selection->confirmation !== CardSelectionSpec::CONFIRM) {
                throw new \LogicException('Panel supports only confirmed single-card selection');
            }
            $items = $selection->candidates;
            foreach ($items as &$item) {
                $item['checked'] = in_array($item['instanceId'], $selection->selected, true);
            }
            unset($item);
            $body = count(array_unique(array_column($items, 'side'))) > 1 ? CreatureChoice::controls() : '';
            if ($selection->mode === CardSelectionSpec::EXTENDED) {
                $body .= '<div class="creature-choice-sides" data-card-rows hidden role="group" aria-label="Ряд существа">';
                foreach (['all'=>'Все', '1'=>'I', '2'=>'II', '3'=>'III', 'fly'=>'Летающие'] as $row => $label) {
                    $body .= '<button type="button" data-card-row-filter="' . $row . '" aria-pressed="false">' . $label . '</button>';
                }
                $body .= '</div>';
            }
            $body .= self::renderChoiceItems($items, 'radio', $form['name'], $selection->minSelect === 1);
        } elseif ($type === 'multi_radio') {
            $body = self::renderMultiRadioGroups($form['groups'] ?? []);
        } else {
            $name = (string) ($form['name'] ?? 'value');
            $items = $form['items'] ?? [];
            $body = (!empty($form['side_toggle']) ? CreatureChoice::controls($form['side_default'] ?? 'enemy') : '') . self::renderChoiceItems($items, $type, $name);
        }

        $submit = $form['submit'] ?? 'Подтвердить';
        $cancel = $form['cancel'] ?? null;

        $body .= '<button type="submit" class="button">' . htmlspecialchars($submit, ENT_QUOTES) . '</button>';

        if ($cancel !== null) {
            $body .= ' <a class="button skip" href="' . htmlspecialchars($cancel, ENT_QUOTES) . '">Отмена</a>';
        }

        $context = $selection === null ? '' : ' data-card-selection="' . htmlspecialchars($selection->context, ENT_QUOTES)
            . '" data-card-min-select="' . $selection->minSelect . '"';
        if ($selection !== null && $selection->battlefieldOwner !== null) {
            $context .= ' data-card-field-owner="' . htmlspecialchars($selection->battlefieldOwner, ENT_QUOTES) . '"';
        }
        return '<form method="get" class="card-choice-form"' . $context . '>'
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

    private static function renderChoiceItems(array $items, string $type, string $name, bool $required = false): string
    {
        $html = '';
        foreach ($items as $it) {
            $value    = htmlspecialchars((string) ($it['value'] ?? ''), ENT_QUOTES);
            // label_html is trusted server-rendered markup; plain labels remain escaped.
            $label = $it['label_html'] ?? Coords::text((string) ($it['label'] ?? ''));
            $checked  = !empty($it['checked']) ? ' checked' : '';
            $disabled = !empty($it['disabled']) ? ' disabled' : '';

            $side = isset($it['side']) ? ' data-creature-owner="' . htmlspecialchars($it['side'], ENT_QUOTES) . '"' : '';
            if (isset($it['instanceId'])) {
                $side .= ' data-card-instance="' . (int) $it['instanceId']
                    . '" data-card-row="' . htmlspecialchars($it['row'], ENT_QUOTES) . '"';
            }
            $html .= '<label class="choice-radio"' . $side . '>'
                . '<input type="' . htmlspecialchars($type, ENT_QUOTES) . '"'
                . ' name="' . htmlspecialchars($name, ENT_QUOTES) . '"'
                . ' value="' . $value . '"' . $checked . $disabled . ($required ? ' required' : '') . '>'
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
