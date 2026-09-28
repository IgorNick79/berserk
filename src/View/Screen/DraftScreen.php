<?php
// src/View/Screen/DraftScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\Core\GameSettings;
use Berserk\View\Template;
use Berserk\View\Ui\ElementLabels;
use Berserk\View\Ui\PrepareUi;

final class DraftScreen
{
    public function __construct(private Template $tpl) {}

    public function prepare(
        GameState $state,
        string $playerKey,
        string $role,
        ?string $message,
        array $cardsInfo,
        array $elementLabels = []
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
                'preview_html'  => '',
                'message'       => $message ?? '',
            ]];
        }

        $roleParam = ($role === 'host') ? 'first' : 'second';
        $baseUrl   = "?{$roleParam}&game={$state->gameId}";
        $isMyTurn  = ($draft['turn'] === $playerKey);
        $ui         = new PrepareUi($this->tpl);

        $selectedIdx = isset($_GET['card']) ? (int) $_GET['card'] : -1;
        if ($selectedIdx < 0 || $selectedIdx > 8) $selectedIdx = -1;

        // ─── Сетка 3×3 ───────────────────────────────────
        $cells = [];
        for ($i = 0; $i < 9; $i++) {
            $ukid = $draft['grid'][$i] ?? null;

            if ($ukid === null) {
                $cells[] = $ui->card([
                    'link'     => $baseUrl,
                    'disabled' => true,
                    'class'    => 'prepare-card-link--empty',
                    'info'     => ['name' => '—', 'strike' => []],
                ]);
                continue;
            }

            $info = $cardsInfo[$ukid] ?? null;
            $link = "{$baseUrl}&card={$i}";

            $cells[] = $ui->card([
                'ukid'     => $ukid,
                'info'     => $info ?? ['name' => $ukid, 'strike' => []],
                'link'     => $link,
                'selected' => $selectedIdx === $i,
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
        $selectedCard = null;
        if ($selectedIdx >= 0 && !empty($draft['grid'][$selectedIdx])) {
            $ukid = $draft['grid'][$selectedIdx];
            $info = $cardsInfo[$ukid] ?? null;

            if ($info) {
                $selectedCard = ['ukid' => $ukid, 'info' => $info];
            }
        }

        // ─── Кнопки действий ─────────────────────────────
        $actions = [];
        $hostPickedCount = count($draft['picked']['host'] ?? []);
        $playerPickedCount = count($draft['picked']['player'] ?? []);

        if ($isMyTurn) {
            for ($r = 1; $r <= 3; $r++) {
                $actions[] = ['label' => 'Строка ' . $r, 'url' => $baseUrl . '&cmd=draft_row&row=' . $r];
            }

            for ($c = 1; $c <= 3; $c++) {
                $actions[] = ['label' => 'Колонка ' . $c, 'url' => $baseUrl . '&cmd=draft_col&col=' . $c];
            }

            $actions[] = ['label' => 'Пас', 'url' => $baseUrl . '&cmd=draft_pass', 'class' => 'skip'];

            if ($hostPickedCount >= GameSettings::MIN_DECK_SIZE && $playerPickedCount >= GameSettings::MIN_DECK_SIZE) {
                $actions[] = ['label' => 'Закончить драфт', 'url' => $baseUrl . '&cmd=finish_draft'];
            }
        } else {
            $actions[] = ['label' => 'Ожидание хода оппонента...', 'url' => '#', 'enabled' => false];
        }

        $actionsHtml = $ui->actions($actions);
        $previewHtml = $ui->preview($selectedCard, [], 'Выбери карту в сетке');

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

            $el = isset($info['element']) ? ElementLabels::label((string) $info['element'], $elementLabels) : null;
            if ($el && $el !== '—') {
                $elementCounts[$el] = ($elementCounts[$el] ?? 0) + 1;
            }
        }

        $avgPrice = $priceCount > 0 ? round($priceSum / $priceCount, 1) : 0;

        // Строим HTML для стихий — только те, где count > 0
        $elementsHtml = '';
        foreach ($elementCounts as $name => $cnt) {
            if ($cnt <= 0) continue;
            $elementsHtml .= '<span class="prepare-element">'
                . htmlspecialchars($name, ENT_QUOTES)
                . ': <b>' . $cnt . '</b>'
                . '</span>';
        }
        arsort($elementCounts);

        return ['screen' => 'draft', 'data' => [
            'turn_label'    => $isMyTurn ? 'Твой ход' : 'Ход оппонента',
            'host_picked'   => $hostPickedCount,
            'player_picked' => $playerPickedCount,
            'pool_left'     => count($draft['pool'] ?? []),

            'my_total'      => $totalCount,
            'my_gold'       => $goldCount,
            'my_silver'     => $silverCount,
            'my_avg_price'  => $avgPrice,
            'my_elements_html' => $elementsHtml,

            'grid_html'     => $gridHtml,
            'preview_html'  => $previewHtml,
            'actions_html'  => $actionsHtml,
            'message'       => $message ?? '',
        ]];

    }
}
