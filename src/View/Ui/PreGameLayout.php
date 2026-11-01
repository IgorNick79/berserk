<?php
declare(strict_types=1);
namespace Berserk\View\Ui;

use Berserk\View\Template;

/** Slots accept trusted, server-rendered HTML; title is plain text. */
final class PreGameLayout
{
    public function __construct(private Template $tpl) {}

    private const TITLES = [
        'mode' => 'Режим игры', 'settings' => 'Настройки', 'draft' => 'Grid Draft',
        'deck_host' => 'Выбор колод', 'deck_wait' => 'Выбор колод', 'view' => 'Просмотр колоды',
        'turn' => 'Бросок кубиков', 'side' => 'Бросок кубиков', 'deal' => 'Выбор отряда', 'place' => 'Расстановка',
    ];

    public static function supports(string $screen): bool
    {
        return isset(self::TITLES[$screen]);
    }

    /** Assemble only screen presentation; command routing stays in index.php. */
    public function renderScreen(string $screen, array $data): string
    {
        if (!self::supports($screen)) throw new \InvalidArgumentException('Not a PreGame screen');
        $title = match ($screen) {
            'settings' => (string) ($data['title'] ?? self::TITLES[$screen]),
            'view' => 'Твоя дека: ' . (string) ($data['deck_name'] ?? '—'),
            'draft' => (string) ($data['panel_title'] ?? self::TITLES[$screen]),
            default => self::TITLES[$screen],
        };
        $roll = in_array($screen, ['turn','side'], true);
        if ($roll) {
            $title = 'Твоя дека: ' . (string) ($data['deck_name'] ?? '—');
            $summary = preg_replace('/<div class="pregame-pool-totals">.*?<\/div>/s', '', $data['panel_summary_html'] ?? '');
            $summary = preg_replace_callback('/<button\b([^>]*)>/', static function ($match) {
                preg_match_all('/(?:class|aria-label)="[^"]*"/', $match[1], $attributes);
                return '<span ' . implode(' ', $attributes[0]) . '>';
            }, $summary);
            $data['panel_summary_html'] = str_replace('</button>', '</span>', $summary);
            $data['panel_actions_html'] = self::actionsFooter($data['panel_actions_html'] ?? '');
        }
        if ($screen === 'place') $data['match_pause_mask_html'] = $this->tpl->parse('includes/match_pause_mask.tpl', ['pause_mask_hidden'=>'hidden']);
        $content = (string) ($data['panel_html'] ?? '');
        if ($roll) $content = '<header class="pregame-card-heading panel-card-heading"><h3 class="pregame-card-title panel-card-title">Бросок кубиков</h3></header>' . $content;
        $html = $this->render((string) $this->tpl->parse('screen/' . $screen . '.tpl', $data),
            $title, $content, (string) ($data['timer_html'] ?? ''), (string) ($data['panel_actions_html'] ?? ''),
            $content === '' ? 'statistics' : 'card', (string) ($data['panel_phase'] ?? ''), (string) ($data['panel_summary_html'] ?? ''));
        if (in_array($screen, ['view','deal','place'], true) || $roll) $html=str_replace(['pregame-info-panel','pregame-panel-content','pregame-panel-footer'], ['pregame-info-panel info-panel','pregame-panel-content info-panel-content','pregame-panel-footer info-panel-footer'], $html);
        if (in_array($screen, ['view','deal','place'], true) || $roll) $html = str_replace('class="pregame-layout"', 'class="pregame-layout" data-panel-theme="grimoire"', $html);
        if ($screen === 'place') $html = str_replace(['class="pregame-layout"','class="pregame-main"'], ['class="pregame-layout battle-shell" data-mini-card-design="v2"','class="pregame-main battle-main"'], $html);
        return $html;
    }

    public static function actionsFooter(string $confirmHtml): string
    {
        return '<div class="pregame-view-confirm info-actions">' . $confirmHtml . '<div class="battle-icon-actions"><button type="button" class="battle-icon-control" data-open-pause aria-label="Пауза игры" title="Пауза игры"><img src="/assets/images/battle/pause_160.png" width="28" height="28" alt=""><span class="battle-icon-tooltip" aria-hidden="true">Пауза игры</span></button><span class="battle-chat-anchor" data-chat-anchor></span></div></div><div data-view-notice role="status"></div>';
    }

    public function render(string $mainHtml, string $title, string $contentHtml = '', string $timerHtml = '', string $footerHtml = '', string $contentKind = 'statistics', string $phase = '', string $summaryHtml = ''): string
    {
        if (!in_array($contentKind, ['statistics', 'card', 'history'], true)) {
            throw new \InvalidArgumentException('Unknown PreGame panel content');
        }
        return (string) $this->tpl->parse('includes/pregame/layout.tpl', [
            'main_html' => $mainHtml,
            'title' => htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'content_html' => $contentHtml === '' ? '<p class="pregame-empty">Дополнительная информация появится здесь.</p>' : $contentHtml,
            'timer_html' => $timerHtml,
            'footer_html' => $footerHtml,
            'summary_html' => $summaryHtml,
            'content_kind' => $contentKind,
            'phase' => htmlspecialchars($phase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ]);
    }
}
