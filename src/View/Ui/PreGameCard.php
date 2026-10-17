<?php
declare(strict_types=1);
namespace Berserk\View\Ui;

use Berserk\View\Template;

/** Static catalog presentation only; never reads GameState or exposes a catalog to JS. */
final class PreGameCard
{
    private const RARITIES = [
        'common' => 'Обычная', 'uncommon' => 'Необычная',
        'rare' => 'Редкая', 'ultrarare' => 'Ультраредкая',
    ];
    private const ELEMENTS = [
        'dark' => 'Тьма', 'plains' => 'Степи', 'forests' => 'Леса',
        'swamps' => 'Болота', 'mountains' => 'Горы', 'neutral' => 'Нейтральная',
    ];

    public function __construct(private Template $tpl) {}

    /**
     * $visibleCards must be scoped by the caller to cards this viewer may inspect.
     * Do not pass buildRenderCardInfo's complete internal catalog here.
     * Entries contain catalog fields, never CardInstance's dynamic battle stats.
     * Optional instance_id is identity metadata only and is not rendered.
     */
    public function render(string $ukid, array $visibleCards): string
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]+\z/D', $ukid) || !isset($visibleCards[$ukid])) {
            return '<p class="pregame-empty">Карта недоступна.</p>';
        }
        $card = $visibleCards[$ukid];
        $name = self::escape((string) ($card['name'] ?? $ukid));
        $url = '/assets/cards/s1/' . rawurlencode($ukid) . '.webp';
        $image = self::assetExists($url)
            ? '<img class="pregame-card-image" src="' . $url . '" alt="' . $name . '" width="400" height="250">'
            : '<div class="pregame-card-image pregame-card-image--missing">Изображение недоступно</div>';
        $strike = (array) ($card['strike'] ?? []);
        return (string) $this->tpl->parse('includes/pregame/card.tpl', [
            'name' => $name,
            'image_html' => $image,
            'hp' => self::value($card['health'] ?? null),
            'move' => self::value($card['move'] ?? null),
            'strike' => implode('–', array_map(fn($key) => self::value($strike[$key] ?? null), ['weak', 'medium', 'strong'])),
            'rarity_html' => self::icon((string) ($card['rarity'] ?? ''), self::RARITIES, '/assets/images/rarity/s1_', 'Редкость'),
            'element_html' => self::icon((string) ($card['element_code'] ?? ''), self::ELEMENTS, '/assets/images/element-', 'Стихия', (string) ($card['element_label'] ?? '')),
            'description_html' => CardDescription::render((string) ($card['descr'] ?? '')),
        ]);
    }

    private static function value(mixed $value): string
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? self::escape((string) $value) : '—';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function assetExists(string $url): bool
    {
        return is_file(dirname(__DIR__, 3) . '/www' . $url);
    }

    private static function icon(string $code, array $allowed, string $prefix, string $kind, string $label = ''): string
    {
        if (!isset($allowed[$code])) return '<span title="' . $kind . '">' . $kind . ': —</span>';
        $label = $label !== '' ? $label : $allowed[$code];
        $text = self::escape($kind . ': ' . $label);
        $url = $prefix . $code . '.png';
        return '<span class="pregame-card-icon" title="' . $text . '">'
            . (self::assetExists($url) ? '<img src="' . $url . '" alt="' . $text . '" width="20" height="20">' : $text)
            . '</span>';
    }
}
