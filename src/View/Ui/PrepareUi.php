<?php
// src/View/Ui/PrepareUi.php

declare(strict_types=1);

namespace Berserk\View\Ui;

use Berserk\View\Template;

final class PrepareUi
{
    public function __construct(private Template $tpl) {}

    public function card(array $data): string
    {
        $info = (array) ($data['info'] ?? []);
        $ukid = (string) ($data['ukid'] ?? '');
        $disabled = !empty($data['disabled']);
        $linkClass = (string) ($data['class'] ?? '');
        if ($disabled) {
            $linkClass .= ($linkClass === '' ? '' : ' ') . 'prepare-card-link--disabled';
        }

        return (string) $this->tpl->parse('includes/prepare_card.tpl', [
            'link'       => htmlspecialchars((string) ($data['link'] ?? '#'), ENT_QUOTES),
            'class'      => htmlspecialchars($linkClass, ENT_QUOTES),
            'selected'   => !empty($data['selected']) ? ' prepare-card--selected' : '',
            'disabled'   => $disabled ? ' prepare-card--disabled' : '',
            'elite'      => !empty($info['elite']) ? ' prepare-card--elite' : '',
            'ukid'       => htmlspecialchars($ukid, ENT_QUOTES),
            'instance_id'=> isset($data['instance_id']) ? (int) $data['instance_id'] : '',
            'name'       => htmlspecialchars((string) ($info['name'] ?? $ukid ?: '—'), ENT_QUOTES),
            'count_html' => isset($data['count']) ? '<div class="prepare-card__count">×' . (int) $data['count'] . '</div>' : '',
            'element'    => htmlspecialchars((string) ($info['element'] ?? ''), ENT_QUOTES),
            'health'     => htmlspecialchars((string) ($info['health'] ?? '?'), ENT_QUOTES),
            'move'       => htmlspecialchars((string) ($info['move'] ?? '?'), ENT_QUOTES),
            'weak'       => htmlspecialchars((string) ($info['strike']['weak'] ?? '?'), ENT_QUOTES),
            'medium'     => htmlspecialchars((string) ($info['strike']['medium'] ?? '?'), ENT_QUOTES),
            'strong'     => htmlspecialchars((string) ($info['strike']['strong'] ?? '?'), ENT_QUOTES),
            'price'      => htmlspecialchars((string) ($info['price'] ?? '?'), ENT_QUOTES),
        ]);
    }

    public function preview(?array $card, array $actions = [], string $emptyText = ''): string
    {
        if ($card === null) {
            return $emptyText === '' ? '' : '<div class="prepare-card-preview prepare-card-preview--empty">' . htmlspecialchars($emptyText, ENT_QUOTES) . '</div>';
        }

        $info = (array) ($card['info'] ?? []);
        $ukid = (string) ($card['ukid'] ?? '');
        $name = (string) ($info['name'] ?? $ukid ?: '—');
        $health = (string) ($info['health'] ?? '?');

        $imageHtml = '';
        if ($ukid !== '') {
            $imageHtml = '<img src="/assets/cards/s1/' . htmlspecialchars($ukid, ENT_QUOTES) . '.jpg"'
                . ' alt="' . htmlspecialchars($name, ENT_QUOTES) . '"'
                . ' class="prepare-card-preview__image"'
                . ' onerror="this.style.display=\'none\'">';
        }

        $actionsHtml = $this->actions($actions);

        return (string) $this->tpl->parse('includes/prepare_preview.tpl', [
            'image_html'  => $imageHtml,
            'name'        => htmlspecialchars($name, ENT_QUOTES),
            'ukid'        => htmlspecialchars($ukid, ENT_QUOTES),
            'instance_id' => isset($card['instance_id']) ? '#' . (int) $card['instance_id'] : '',
            'element'     => htmlspecialchars((string) ($info['element'] ?? '—'), ENT_QUOTES),
            'price'       => htmlspecialchars((string) ($info['price'] ?? '?'), ENT_QUOTES),
            'health'      => htmlspecialchars($health, ENT_QUOTES),
            'hp_max'      => htmlspecialchars((string) ($info['hp_max'] ?? $health), ENT_QUOTES),
            'move'        => htmlspecialchars((string) ($info['move'] ?? '?'), ENT_QUOTES),
            'weak'        => htmlspecialchars((string) ($info['strike']['weak'] ?? '?'), ENT_QUOTES),
            'medium'      => htmlspecialchars((string) ($info['strike']['medium'] ?? '?'), ENT_QUOTES),
            'strong'      => htmlspecialchars((string) ($info['strike']['strong'] ?? '?'), ENT_QUOTES),
            'actions_html'=> $actionsHtml,
        ]);
    }

    public function actions(array $actions): string
    {
        $html = '';
        foreach ($actions as $action) {
            $label = htmlspecialchars((string) ($action['label'] ?? ''), ENT_QUOTES);
            if ($label === '') continue;

            $class = 'button prepare-action';
            if (!empty($action['class'])) {
                $class .= ' ' . htmlspecialchars((string) $action['class'], ENT_QUOTES);
            }

            if (empty($action['enabled']) && array_key_exists('enabled', $action)) {
                $html .= '<span class="' . $class . ' disabled">' . $label . '</span>';
                continue;
            }

            $url = htmlspecialchars((string) ($action['url'] ?? '#'), ENT_QUOTES);
            $html .= '<a class="' . $class . '" href="' . $url . '">' . $label . '</a>';
        }

        return $html === '' ? '' : '<div class="prepare-actions">' . $html . '</div>';
    }
}
