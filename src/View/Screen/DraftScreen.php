<?php
// src/View/Screen/DraftScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\Core\GameSettings;
use Berserk\Core\Prepare\DraftTimer;
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
                'timer_html'    => '',
                'history_html'  => '',
                'draft_script_html' => '',
                'message'       => $message ?? '',
            ]];
        }

        $now = time();
        DraftTimer::ensureRuntime($state, $now);
        $roleParam = ($role === 'host') ? 'first' : 'second';
        $baseUrl   = "?{$roleParam}&game={$state->gameId}";
        $syncUrl   = "{$baseUrl}&cmd=draft_sync";
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
        $isDiscrete = ($draft['grid_mode'] ?? $state->settings->draftGridMode()) === GameSettings::DRAFT_GRID_MODE_DISCRETE;

        if ($isMyTurn) {
            for ($r = 1; $r <= 3; $r++) {
                $actions[] = ['label' => 'Строка ' . $r, 'url' => $baseUrl . '&cmd=draft_row&row=' . $r];
            }

            for ($c = 1; $c <= 3; $c++) {
                $actions[] = ['label' => 'Колонка ' . $c, 'url' => $baseUrl . '&cmd=draft_col&col=' . $c];
            }

            if (!$isDiscrete) {
                $actions[] = ['label' => 'Пас', 'url' => $baseUrl . '&cmd=draft_pass', 'class' => 'skip'];
            }

            if ($hostPickedCount >= GameSettings::MIN_DECK_SIZE && $playerPickedCount >= GameSettings::MIN_DECK_SIZE) {
                $actions[] = ['label' => 'Закончить драфт', 'url' => $baseUrl . '&cmd=finish_draft'];
            }
        } else {
            $actions[] = ['label' => 'Ожидание хода оппонента...', 'url' => '#', 'enabled' => false];
        }

        $actionsHtml = $ui->actions($actions);
        $previewHtml = $ui->preview($selectedCard, [], 'Выбери карту в сетке');
        $timerHtml = $this->timerHtml($state, $playerKey, $now);
        $historyHtml = $this->historyHtml($state, $playerKey, $cardsInfo, $ui);
        $scriptHtml = $this->scriptHtml($state, $syncUrl, $now);

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
            'timer_html'    => $timerHtml,
            'history_html'  => $historyHtml,
            'draft_script_html' => $scriptHtml,
            'message'       => $message ?? '',
        ]];

    }

    private function timerHtml(GameState $state, string $playerKey, int $now): string
    {
        $snapshot = DraftTimer::snapshot($state, $playerKey, $now);
        if (($snapshot['mode'] ?? '') === GameSettings::DRAFT_TIMER_UNLIMITED) {
            return '<div class="draft-timer draft-timer--unlimited">Без ограничений времени</div>';
        }

        $turn = (string) ($snapshot['turn'] ?? '');
        $turnLabel = $turn === $playerKey ? 'твой ход' : 'ход оппонента';
        $action = $this->formatSeconds((int) ($snapshot['remaining_action'] ?? 0));
        $host = $this->formatSeconds((int) (($snapshot['remaining_total']['host'] ?? 0)));
        $player = $this->formatSeconds((int) (($snapshot['remaining_total']['player'] ?? 0)));

        return '<div class="draft-timer" data-draft-timer>'
            . '<span>Таймер: <b data-draft-action-time>' . $action . '</b> (' . htmlspecialchars($turnLabel, ENT_QUOTES) . ')</span>'
            . '<span>host: <b data-draft-total-host>' . $host . '</b></span>'
            . '<span>player: <b data-draft-total-player>' . $player . '</b></span>'
            . '</div>';
    }

    private function historyHtml(GameState $state, string $playerKey, array $cardsInfo, PrepareUi $ui): string
    {
        $history = array_reverse((array) ($state->draft['history'] ?? []));
        if (empty($history)) {
            return '<details class="draft-history"><summary>История выбора</summary><p class="wait">Пока нет выбранных карт.</p></details>';
        }

        $html = '<details class="draft-history"><summary>История выбора</summary>';
        foreach ($history as $event) {
            $owner = (string) ($event['player'] ?? '');
            $label = $owner === $playerKey ? 'Мой выбор' : ($owner === 'host' ? 'host' : 'player');
            $html .= '<div class="draft-history__event">'
                . '<div class="draft-history__owner">' . htmlspecialchars($label, ENT_QUOTES) . '</div>'
                . '<div class="draft-history__cards">';
            foreach ((array) ($event['cards'] ?? []) as $ukid) {
                $info = $cardsInfo[$ukid] ?? ['name' => (string) $ukid, 'strike' => []];
                $html .= $ui->card([
                    'ukid' => (string) $ukid,
                    'info' => $info,
                    'disabled' => true,
                    'class' => 'draft-history__card',
                ]);
            }
            $html .= '</div></div>';
        }
        return $html . '</details>';
    }

    private function scriptHtml(GameState $state, string $syncUrl, int $now): string
    {
        $snapshot = DraftTimer::snapshot($state, (string) ($state->draft['turn'] ?? 'host'), $now);
        if (($snapshot['mode'] ?? '') === GameSettings::DRAFT_TIMER_UNLIMITED) {
            return '';
        }

        return '<script src="/assets/js/draft_timer.js?v=1" defer'
            . ' data-sync-url="' . htmlspecialchars($syncUrl, ENT_QUOTES) . '"'
            . ' data-version="' . (int) $state->version . '"'
            . ' data-server-now="' . $now . '"'
            . ' data-deadline-at="' . (int) ($snapshot['deadline_at'] ?? 0) . '"'
            . ' data-host-total="' . (int) (($snapshot['remaining_total']['host'] ?? 0)) . '"'
            . ' data-player-total="' . (int) (($snapshot['remaining_total']['player'] ?? 0)) . '"'
            . '></script>';
    }

    private function formatSeconds(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;
        return sprintf('%d:%02d', $minutes, $rest);
    }
}
