<?php
// src/View/Ui/PrepareUi.php

declare(strict_types=1);

namespace Berserk\View\Ui;

use Berserk\View\Template;

final class PrepareUi
{
    public function __construct(private Template $tpl) {}

    public function informationWithControls(?array $card, array $catalog): string
    {
        $html = $this->information($card, $catalog);
        return $html !== '' && $card !== null ? $this->withCardControls($html, $card['ukid']) : $html;
    }

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
            'count_html' => isset($data['count']) ? '<div class="prepare-card__count">×' . (int) $data['count']
                . (!empty($data['count_note']) ? '<span class="prepare-card__count-note">' . htmlspecialchars((string) $data['count_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>' : '') . '</div>' : '',
            'element'    => htmlspecialchars((string) ($info['element'] ?? ''), ENT_QUOTES),
            'health'     => htmlspecialchars((string) ($info['health'] ?? '?'), ENT_QUOTES),
            'move'       => htmlspecialchars((string) ($info['move'] ?? '?'), ENT_QUOTES),
            'weak'       => htmlspecialchars((string) ($info['strike']['weak'] ?? '?'), ENT_QUOTES),
            'medium'     => htmlspecialchars((string) ($info['strike']['medium'] ?? '?'), ENT_QUOTES),
            'strong'     => htmlspecialchars((string) ($info['strike']['strong'] ?? '?'), ENT_QUOTES),
            'price'      => htmlspecialchars((string) ($info['price'] ?? '?'), ENT_QUOTES),
        ]);
    }

    /** $card must come from the current viewer's visible screen list, never directly from a request. */
    public function information(?array $card, array $catalog): string
    {
        if ($card === null) return '';
        $ukid = (string) ($card['ukid'] ?? '');
        // Use catalog values rather than modified recruit costs or instance battle stats.
        $info = $catalog[$ukid] ?? null;
        if (!is_array($info)) return '';
        return (new PreGameCard($this->tpl))->render($ukid, [$ukid => $info]);
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
            $imageHtml = '<img src="/assets/cards/s1/original/' . htmlspecialchars($ukid, ENT_QUOTES) . '.jpg"'
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
            'notes_html'  => (string) ($info['notes_html'] ?? ''),
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
    public function thumbnail( array $catalog, array $data): string
    {
        if (!empty($data['legacy'])) return $this->card($data);
        if (!empty($data['disabled']) || empty($data['ukid'])) return '<span class="prepare-card-link prepare-card-link--disabled"><span class="prepare-card view-thumbnail" aria-hidden="true"></span></span>';
        $data += ['info'=>[], 'count'=>1, 'selected'=>false, 'class'=>'view-element-'.($catalog[$data['ukid']]['element_code'] ?? 'neutral')];
        if (!str_contains($data['class'], 'view-element-')) $data['class'] = 'view-element-'.($catalog[$data['ukid']]['element_code'] ?? 'neutral').' '.$data['class'];
        $info = array_replace($data['info'], $catalog[$data['ukid']] ?? []);
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ukid = $data['ukid'];
        $path = '/assets/cards/s1/' . rawurlencode($ukid) . '.webp';
        $art = '<img class="view-art" src="'.$e($path).'" width="400" height="250" alt="">';
        $element = explode(' ', $data['class'])[0];
        $element = substr($element, strlen('view-element-'));
        $cost = (!empty($info['elite']) ? 'elite' : 'ordinary') . (!empty($info['single']) ? '-unique' : '');
        $elementHtml = '<span class="view-element" data-mini-hint data-view-element tabindex="0" role="button" aria-label="'.$e($info['element_label']??$info['element']??$element).'"><img src="/assets/images/element/'.$e($element).'.png" alt=""><span class="mini-hint-text" hidden>'.$e($info['element_label']??$info['element']??$element).'</span></span>';
        return '<a aria-label="'.$e($info['name']??$ukid).'" class="prepare-card-link '.$e($data['class']).'" href="'.$e($data['link']).'" data-ukid="'.$e($ukid).'">'
            . '<div data-mini-art-only data-mini-card="view:'.$e($data['link']).'" data-mini-name="'.$e($info['name']??$ukid).'" class="prepare-card view-thumbnail'.($data['selected']?' prepare-card--selected':'').'">'.$art
            . (empty($data['element_in_cost']) ? $elementHtml : '')
            . '<span class="view-cost"><img src="/assets/images/card/'.$cost.'.png" alt="'.(!empty($info['elite'])?'Золотой':'Серебряный').(!empty($info['single'])?', уникальный':'').' кристалл">'.$e($info['price']??$data['info']['price']??0)
            . (!empty($data['element_in_cost']) ? $elementHtml : '')
            . '<span class="prepare-card__count">×'.(int)$data['count'].'</span></span></div></a>';
    }

    public function withCardControls(string $panelHtml, string $ukid): string
    {
            $original='/assets/cards/s1/'.rawurlencode($ukid).'.webp';
            $panelHtml=str_replace('<div class="pregame-card-media">', '<div class="pregame-card-media panel-card-media"><button type="button" class="panel-card-preview" data-card-original="'.$original.'" aria-label="Увеличить изображение">', $panelHtml);
            $panelHtml=preg_replace('~(</div>\s*<div class="pregame-card-stats")~', '</button><button type="button" class="panel-card-close" data-view-close aria-label="Закрыть карту"><img src="/assets/images/battle/close.png" width="28" height="28" alt=""></button>$1', $panelHtml, 1);
        return $panelHtml;
    }
}
