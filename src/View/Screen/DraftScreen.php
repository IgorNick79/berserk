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
        $isSolomon = $state->settings->isSolomonDraft();
        $ui         = new PrepareUi($this->tpl);

        $selectedIdx = isset($_GET['card']) ? (int) $_GET['card'] : -1;
        if ($selectedIdx < 0 || $selectedIdx > 8) $selectedIdx = -1;
        $requestedUkid = $_GET['card_ukid'] ?? null;
        $requestedVersion = $_GET['card_version'] ?? null;
        if (!is_string($requestedUkid) || !is_scalar($requestedVersion)
            || (string) $requestedVersion !== (string) $state->version
            || ($draft['grid'][$selectedIdx] ?? null) !== $requestedUkid) {
            $selectedIdx = -1;
        }

        $cardActionsHtml = $isSolomon && $isMyTurn
            ? '<div class="pregame-card-actions"><button type="button" class="button small" data-solomon-panel-action="select" disabled>Выбрать</button><button type="button" class="button small" data-solomon-panel-action="cancel" disabled>Отмена</button></div>' : '';

        // ─── Сетка 3×3 ───────────────────────────────────
        $cells = [];
        for ($i = 0; !$isSolomon && $i < 9; $i++) {
            $ukid = $draft['grid'][$i] ?? null;

            if ($ukid === null) {
                $cells[] = '<div class="pregame-grid-card" data-grid-position="' . $i . '" data-grid-occupied="0">' . $ui->card([
                    'link'     => $baseUrl,
                    'disabled' => true,
                    'class'    => 'prepare-card-link--empty',
                    'info'     => ['name' => '—', 'strike' => []],
                ]) . '</div>';
                continue;
            }

            $info = $cardsInfo[$ukid] ?? null;
            $link = "{$baseUrl}&card={$i}&card_ukid=" . rawurlencode($ukid) . "&card_version={$state->version}";

            $cells[] = '<div class="pregame-grid-card" data-grid-position="' . $i . '" data-grid-occupied="1">'
                . $ui->card([
                    'ukid' => $ukid,
                    'info' => $info ?? ['name' => $ukid, 'strike' => []],
                    'link' => $link,
                ]) . '<template data-grid-preview>' . $ui->information(['ukid' => $ukid], $cardsInfo) . '</template></div>';
        }

        $columnsHtml = '<div class="pregame-grid-columns" aria-label="Столбцы"><span>1</span><span>2</span><span>3</span></div>';
        $gridHtml = $isSolomon ? '' : $columnsHtml;
        for ($r = 0; !$isSolomon && $r < 3; $r++) {
            $gridHtml .= '<div class="draft-row"><span class="pregame-grid-row-number">' . ['I', 'II', 'III'][$r] . '</span>';
            for ($c = 0; $c < 3; $c++) {
                $gridHtml .= $cells[$r * 3 + $c];
            }
            $gridHtml .= '</div>';
        }

        if (!$isSolomon) $gridHtml .= $columnsHtml;

        if ($isSolomon) {
            $gridHtml = '<div class="solomon-stack" data-solomon-stack data-selection-key="'
                . (int) $state->gameId . '-' . $playerKey . '" data-preview-position="' . $selectedIdx . '" data-decision="' . (int) ($draft['decision_id'] ?? 1) . '">';
            foreach ($draft['grid'] as $position => $ukid) {
                $info = $cardsInfo[$ukid] ?? ['name' => $ukid, 'strike' => []];
                $gridHtml .= '<div class="solomon-stack__card" data-solomon-position="' . (int) $position . '">'
                    . '<div class="solomon-stack__selection">'
                    . ($isMyTurn ? '<label class="solomon-stack__select"><input type="checkbox" name="positions[]" value="' . (int) $position
                        . '" form="solomon-decision"><span>Выбрать</span></label>' : '')
                    . '</div>'
                    . $ui->card(['ukid' => $ukid, 'info' => $info, 'link' => $baseUrl . '&card=' . (int) $position
                        . '&card_ukid=' . rawurlencode($ukid) . '&card_version=' . $state->version])
                    . '<template data-solomon-preview>' . $ui->information(['ukid' => $ukid], $cardsInfo) . $cardActionsHtml . '</template></div>';
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
        $hostPickedCount = count($draft['picked']['host'] ?? []);
        $playerPickedCount = count($draft['picked']['player'] ?? []);
        $isDiscrete = ($draft['grid_mode'] ?? $state->settings->draftGridMode()) === GameSettings::DRAFT_GRID_MODE_DISCRETE;

        $finishHtml = '';
        $gridControls = $isSolomon ? '' : '<div class="pregame-grid-controls" aria-hidden="true"></div>';
        $actionsHtml = '';
        if ($isMyTurn && !$isSolomon) {
            $gridControls = '<div class="pregame-grid-controls" aria-label="Выбор строки или колонки">';
            foreach (['row' => 'Строка', 'col' => 'Колонка'] as $axis => $label) {
                $gridControls .= '<div class="pregame-grid-control-row">';
                foreach ([1 => 'I', 2 => 'II', 3 => 'III'] as $number => $roman) {
                    $positions = $axis === 'row' ? range(($number - 1) * 3, $number * 3 - 1) : [$number - 1, $number + 2, $number + 5];
                    $hasCards = count(array_filter($positions, fn($pos) => ($draft['grid'][$pos] ?? null) !== null)) > 0;
                    $gridControls .= '<button type="button" class="button" data-grid-pick data-command="draft_' . $axis
                        . '" data-axis="' . $axis . '" data-index="' . $number . '" aria-pressed="false"'
                        . ($hasCards ? '' : ' disabled') . '>' . $label . ' ' . ($axis === 'row' ? $roman : $number) . '</button>';
                }
                $gridControls .= '</div>';
            }
            $gridControls .= '<div class="pregame-grid-extra-actions">';
            if (!$isDiscrete) $gridControls .= '<button type="button" class="button skip" data-grid-pick data-command="draft_pass" aria-pressed="false">Пас</button>';
            if ($hostPickedCount >= GameSettings::MIN_DECK_SIZE && $playerPickedCount >= GameSettings::MIN_DECK_SIZE) {
                $finishHtml = '<div class="pregame-grid-finish"><button type="button" class="button" data-grid-pick data-command="finish_draft" aria-pressed="false">Закончить драфт</button></div>';
            }
            $gridControls .= '</div></div>';
            $actionsHtml = '<form method="get" id="grid-decision" class="prepare-actions">'
                . '<input type="hidden" name="' . $roleParam . '" value="1">'
                . '<input type="hidden" name="game" value="' . (int) $state->gameId . '">'
                . '<input type="hidden" name="cmd" data-grid-command disabled>'
                . '<input type="hidden" data-grid-coordinate disabled>'
                . '<button type="submit" class="button prepare-action" data-grid-confirm disabled>Подтвердить</button></form>';
        }
        if ($isSolomon && $isMyTurn) {
            $actionsHtml = '<form method="get" id="solomon-decision" class="prepare-actions">'
                . '<input type="hidden" name="' . $roleParam . '" value="1">'
                . '<input type="hidden" name="game" value="' . (int) $state->gameId . '">'
                . '<input type="hidden" name="decision_id" value="' . (int) ($draft['decision_id'] ?? 1) . '">'
                . '<input type="hidden" name="expected_version" value="' . (int) $state->version . '">'
                . '<button class="button prepare-action" name="cmd" value="draft_confirm"' . ($isMyTurn ? '' : ' disabled') . '>Подтвердить</button>'
                . '<button class="button prepare-action skip" name="cmd" value="draft_pass"' . ($isMyTurn ? '' : ' disabled') . '>Пас</button></form>';
        }
        $previewHtml = $ui->information($selectedCard, $cardsInfo);
        if ($previewHtml !== '' && $isSolomon) $previewHtml .= $cardActionsHtml;
        $timerHtml = $this->timerHtml($state, $playerKey, $now);
        $historyHtml = $this->historyHtml($state, $playerKey, $cardsInfo, $ui);
        $scriptHtml = $this->scriptHtml($state, $syncUrl, $now);
        $scriptHtml .= $isSolomon ? '<script src="/assets/js/solomon_selection.js?v=5" defer></script>'
            : '<script src="/assets/js/grid_selection.js?v=2" defer></script>';

        // ─── Статистика набранного пула ──────────────────
        $myPicked = $draft['picked'][$playerKey] ?? [];

        $totalCount  = count($myPicked);
        $goldCount   = 0;
        $silverCount = 0;
        $priceSum    = 0;
        $priceCount  = 0;
        $elementCodeCounts = array_fill_keys(['dark', 'plains', 'forests', 'swamps', 'mountains', 'neutral'], 0);
        $elementNames = ['dark'=>'Тьма', 'plains'=>'Степи', 'forests'=>'Леса', 'swamps'=>'Болота', 'mountains'=>'Горы', 'neutral'=>'Нейтральная'];
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

            $code = (string) ($info['element_code'] ?? $info['element'] ?? '');
            if (!isset($elementCodeCounts[$code])) $code = array_search($code, $elementNames, true);
            if (is_string($code) && isset($elementCodeCounts[$code])) $elementCodeCounts[$code]++;

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

        $poolLeft = count($draft['pool'] ?? []) + ($isSolomon ? count($draft['recycle'] ?? []) : 0);
        $panelSummary = '';
        $panelFooter = '';
        $draftInfo = '';
        $draftBottom = '';
        $panelSummary = $finishHtml . '<section class="pregame-pool-stats" aria-label="Статистика выбранного пула">'
            . '<div class="pregame-pool-totals">Выбрано: <b>' . $totalCount . '</b> · Средняя цена: <b>' . $avgPrice . '</b></div>'
            . '<div class="pregame-pool-icons"><div class="pregame-pool-currencies">'
            . $this->statisticIcon('/assets/images/card/elite.png', 'Золотые карты', $goldCount)
            . $this->statisticIcon('/assets/images/card/ordinary.png', 'Серебряные карты', $silverCount)
            . '</div><div class="pregame-pool-elements">';
        foreach ($elementNames as $code => $label) {
            $panelSummary .= $this->statisticIcon('/assets/images/element/' . $code . '.png', $label, $elementCodeCounts[$code]);
        }
        $panelSummary .= '</div></div></section>';
        $panelFooter = '<div class="pregame-draft-footer">'
            . ($isMyTurn ? '<div class="pregame-footer-decisions">' . $actionsHtml . '</div>'
                . '<div class="pregame-selection-count">Выбрано: <b ' . ($isSolomon ? 'data-solomon-selected' : 'data-grid-selected') . '>0</b></div>' : '')
            . '<button type="button" class="pregame-history-control" ' . ($isSolomon ? 'data-solomon-history' : 'data-grid-history') . ' aria-label="История выбора" aria-pressed="false" title="История выбора">'
            . '<img src="/assets/images/battle/game_log_160.png" alt="" width="40" height="40"><span class="pregame-history-tooltip" aria-hidden="true">История выбора</span></button></div>';
        $draftBottom = $gridControls;

        return ['screen' => 'draft', 'data' => [
            'draft_class'   => $isSolomon ? 'screen-draft--solomon' : 'screen-draft--grid',
            'panel_title'   => $isSolomon ? '#' . (int) ($draft['decision_id'] ?? 1) . ' · ' . ($isMyTurn ? 'Ваш ход' : 'Ход противника') : ($isMyTurn ? 'Ваш ход' : 'Ход противника'),
            'panel_phase'   => ($isSolomon ? 'Соломон драфт' : 'Grid Draft') . ' · Пул: ' . $poolLeft,
            'panel_actions_html' => $panelFooter,
            'panel_summary_html' => $panelSummary,
            'draft_info_html' => $draftInfo,
            'draft_bottom_html' => $draftBottom,
            'turn_label'    => $isMyTurn ? 'Твой ход' : 'Ход оппонента',
            'host_picked'   => $hostPickedCount,
            'player_picked' => $playerPickedCount,
            'pool_left'     => count($draft['pool'] ?? []) + ($isSolomon ? count($draft['recycle'] ?? []) : 0),

            'my_total'      => $totalCount,
            'my_gold'       => $goldCount,
            'my_silver'     => $silverCount,
            'my_avg_price'  => $avgPrice,
            'my_elements_html' => $elementsHtml,

            'grid_attributes' => $isSolomon ? '' : 'data-grid-selection-key="' . (int) $state->gameId . '-' . $playerKey . '" data-grid-version="' . (int) $state->version . '"',
            'grid_html'     => $gridHtml,
            'preview_html'  => '',
            'panel_html'    => $previewHtml,
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
            return '<div class="draft-timer draft-timer--unlimited" role="timer" aria-label="Без ограничений времени">—:—</div>';
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
        $attribute = $state->settings->isSolomonDraft() ? 'data-solomon-history-content' : 'data-grid-history-content';
        return '<template ' . $attribute . '>' . $this->panelHistoryHtml($history, $playerKey, $cardsInfo, $ui) . '</template>';
    }

    private function statisticIcon(string $url, string $label, int $count): string
    {
        $label = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<button type="button" class="pregame-hint pregame-pool-stat" aria-label="' . $label . ': ' . $count . '">'
            . '<img src="' . $url . '" alt="" width="24" height="24"><span>' . $count . '</span>'
            . '<template data-pregame-hint>' . $label . ': ' . $count . '</template></button>';
    }

    /** Only historical allocations already visible in the existing Draft history. */
    private function panelHistoryHtml(array $history, string $playerKey, array $cardsInfo, PrepareUi $ui): string
    {
        $html = '<section class="pregame-draft-history"><h3>История выбора</h3>';
        if ($history === []) $html .= '<p class="wait">Пока нет выбранных карт.</p>';
        foreach ($history as $event) {
            $owner = (string) ($event['player'] ?? '');
            $html .= '<div class="draft-history__event"><div class="draft-history__owner">' . ($owner === $playerKey ? 'Мой выбор' : 'Выбор противника') . '</div><div class="draft-history__cards">';
            foreach ((array) ($event['cards'] ?? []) as $ukid) {
                $ukid = (string) $ukid;
                $info = $cardsInfo[$ukid] ?? ['name'=>$ukid, 'strike'=>[]];
                $html .= '<div class="pregame-history-entry">' . $ui->card(['ukid'=>$ukid, 'info'=>$info, 'link'=>'#', 'class'=>'draft-history__card'])
                    . '<template data-history-card-preview>' . $ui->information(['ukid'=>$ukid], $cardsInfo) . '</template></div>';
            }
            $html .= '</div></div>';
        }
        return $html . '</section>';
    }

    private function scriptHtml(GameState $state, string $syncUrl, int $now): string
    {
        $snapshot = DraftTimer::snapshot($state, (string) ($state->draft['turn'] ?? 'host'), $now);

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
