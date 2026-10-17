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
        'turn' => 'Бросок кубика', 'side' => 'Выбор стороны', 'deal' => 'Выбор отряда', 'place' => 'Расстановка',
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
        $content = (string) ($data['panel_html'] ?? '');
        return $this->render((string) $this->tpl->parse('screen/' . $screen . '.tpl', $data),
            $title, $content, (string) ($data['timer_html'] ?? ''), '', $content === '' ? 'statistics' : 'card');
    }

    public function render(string $mainHtml, string $title, string $contentHtml = '', string $timerHtml = '', string $footerHtml = '', string $contentKind = 'statistics'): string
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
            'content_kind' => $contentKind,
        ]);
    }
}
