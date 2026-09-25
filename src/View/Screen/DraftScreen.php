<?php
// src/View/Screen/DraftScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\View\Template;

final class DraftScreen
{
    public function __construct(private Template $tpl) {}

    public function prepare(
        GameState $state,
        string $playerKey,
        string $role,
        ?string $message,
        array $cardsInfo
    ): array {
        $draft = $state->draft ?? null;
        if (!$draft) {
            return ['screen' => 'draft', 'data' => [
                'turn_label'    => '—',
                'host_picked'   => 0,
                'player_picked' => 0,
                'pool_left'     => 0,
                'my_total'      => 0,
                'my_gold'       => 0,
                'my_silver'     => 0,
                'my_avg_price'  => 0,
                'grid_html'     => '<p class="wait">Драфт не активен</p>',
                'actions_html'  => '',
                'panel_html'    => '',
                'message'       => $message ?? '',
            ]];
        }

        $roleParam = ($role === 'host') ? 'first' : 'second';
        $baseUrl   = "?{$roleParam}&game={$state->gameId}";
        $isMyTurn  = ($draft['turn'] === $playerKey);

        $selectedIdx = isset($_GET['sel']) ? (int) $_GET['sel'] : -1;
        if ($selectedIdx < 0 || $selectedIdx > 8) $selectedIdx = -1;

        // ─── Сетка 3×3 ───────────────────────────────────
        $cells = [];
        for ($i = 0; $i < 9; $i++) {
            $ukid = $draft['grid'][$i] ?? null;

            if ($ukid === null) {
                $cells[] = $this->tpl->parse('includes/draft_card.tpl', [
                    'link'       => $baseUrl,   // пустая — не кликабельна (вернёт на ту же страницу)
                    'card_class' => 'empty',
                    'elite'      => '',
                    'name'       => '—',
                    'element'    => '',
                    'health'     => '',
                    'move'       => '',
                    'weak'       => '',
                    'medium'     => '',
                    'strong'     => '',
                    'price'      => '',
                ]);
                continue;
            }

            $info = $cardsInfo[$ukid] ?? null;
            $link = "{$baseUrl}&sel={$i}";

            $info = $cardsInfo[$ukid] ?? null;
            $cells[] = $this->tpl->parse('includes/draft_card.tpl', [
                'link'       => $link,
                'card_class' => '',
                'elite'      => !empty($info['elite']) ? 'elite' : '',
                'name'       => $info ? htmlspecialchars($info['name'], ENT_QUOTES) : $ukid,
                'element'    => $info ? htmlspecialchars($info['element'] ?? '—', ENT_QUOTES) : '—',
                'health'     => $info['health'] ?? '?',
                'move'       => $info['move']   ?? '?',
                'weak'       => $info['strike']['weak']   ?? '?',
                'medium'     => $info['strike']['medium'] ?? '?',
                'strong'     => $info['strike']['strong'] ?? '?',
                'price'      => $info['price']  ?? '?',
            ]);
        }

        $gridHtml = '';
        for ($r = 0; $r < 3; $r++) {
            $gridHtml .= '<div class="draft-row">';
            for ($c = 0; $c < 3; $c++) {
                $gridHtml .= $cells[$r * 3 + $c];
            }
            $gridHtml .= '</div>';
        }

        // ─── Панель выбранной карты ──────────────────────
        $panelHtml = '';
        if ($selectedIdx >= 0 && !empty($draft['grid'][$selectedIdx])) {
            $ukid = $draft['grid'][$selectedIdx];
            $info = $cardsInfo[$ukid] ?? null;

            if ($info) {
                $imgHtml = '<img src="/assets/cards/s1/' . htmlspecialchars($ukid, ENT_QUOTES) . '.jpg"'
                    . ' alt="' . htmlspecialchars($info['name'], ENT_QUOTES) . '"'
                    . ' class="panel-card-img"'
                    . ' onerror="this.style.display=\'none\'">';

                $panelHtml = $this->tpl->parse('includes/draft_panel.tpl', [
                    'image_html' => $imgHtml,
                    'name'       => htmlspecialchars($info['name'], ENT_QUOTES),
                    'hp'         => $info['health'] ?? '?',
                    'hp_max'     => $info['health'] ?? '?',
                ]);
            }
        }

        // ─── Кнопки действий ─────────────────────────────
        $actionsHtml = '';
        if ($isMyTurn) {
            $actionsHtml .= '<div class="draft-actions__rows">';
            for ($r = 1; $r <= 3; $r++) {
                $actionsHtml .= '<a class="button" href="' . $baseUrl . '&cmd=draft_row&row=' . $r . '">Строка ' . $r . '</a> ';
            }
            $actionsHtml .= '</div>';

            $actionsHtml .= '<div class="draft-actions__cols">';
            for ($c = 1; $c <= 3; $c++) {
                $actionsHtml .= '<a class="button" href="' . $baseUrl . '&cmd=draft_col&col=' . $c . '">Колонка ' . $c . '</a> ';
            }
            $actionsHtml .= '</div>';

            $actionsHtml .= '<div class="draft-actions__pass">';
            if (!$draft['pass_blocked']) {
                $actionsHtml .= '<a class="button skip" href="' . $baseUrl . '&cmd=draft_pass">Пас</a>';
            } else {
                $actionsHtml .= '<span class="button skip disabled">Пас недоступен</span>';
            }
            $actionsHtml .= '</div>';
        } else {
            $actionsHtml = '<p class="wait">Ожидание хода оппонента...</p>';
        }

        // ─── Статистика набранного пула ──────────────────
        $myPicked = $draft['picked'][$playerKey] ?? [];

        $totalCount  = count($myPicked);
        $goldCount   = 0;
        $silverCount = 0;
        $priceSum    = 0;
        $priceCount  = 0;
        $elementCounts = [];   // ['Горы' => 3, 'Леса' => 2, ...]

        foreach ($myPicked as $u) {
            $info = $cardsInfo[$u] ?? null;
            if (!$info) continue;

            if (!empty($info['elite'])) $goldCount++;
            else                        $silverCount++;

            if (isset($info['price'])) {
                $priceSum += (int) $info['price'];
                $priceCount++;
            }

            $el = $info['element'] ?? null;
            if ($el && $el !== '—') {
                $elementCounts[$el] = ($elementCounts[$el] ?? 0) + 1;
            }
        }

        $avgPrice = $priceCount > 0 ? round($priceSum / $priceCount, 1) : 0;

        // Строим HTML для стихий — только те, где count > 0
        $elementsHtml = '';
        foreach ($elementCounts as $name => $cnt) {
            if ($cnt <= 0) continue;
            $elementsHtml .= '<span class="draft-element">'
                . htmlspecialchars($name, ENT_QUOTES)
                . ': <b>' . $cnt . '</b>'
                . '</span>';
        }
        arsort($elementCounts);

        return ['screen' => 'draft', 'data' => [
            'turn_label'    => $isMyTurn ? 'Твой ход' : 'Ход оппонента',
            'host_picked'   => count($draft['picked']['host'] ?? []),
            'player_picked' => count($draft['picked']['player'] ?? []),
            'pool_left'     => count($draft['pool'] ?? []),

            'my_total'      => $totalCount,
            'my_gold'       => $goldCount,
            'my_silver'     => $silverCount,
            'my_avg_price'  => $avgPrice,
            'my_elements_html' => $elementsHtml,

            'grid_html'     => $gridHtml,
            'actions_html'  => $actionsHtml,
            'panel_html'    => $panelHtml,
            'message'       => $message ?? '',
        ]];

    }
}