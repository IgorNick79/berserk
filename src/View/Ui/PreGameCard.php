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
        $stats = self::stat('hp', 'heart', self::value($card['health'] ?? null), 'Здоровье');
        if (($card['type'] ?? '') !== 'fly') {
            $stats .= self::stat('move', 'move', self::value($card['move'] ?? null), 'Ходов');
        }
        $stats .= self::stat('strike', 'sword', implode('-', array_map(fn($key) => self::value($strike[$key] ?? null), ['weak', 'medium', 'strong'])), 'Простой удар');
        if (($card['type'] ?? '') === 'fly') $stats .= self::hint('/assets/images/card/fly.png', 'Летающее существо');
        $description = CardDescription::render((string) ($card['descr'] ?? ''));
        $costIcon = '/assets/images/card/' . (!empty($card['elite']) ? 'elite' : 'ordinary') . (!empty($card['single']) ? '-unique' : '') . '.png';
        $class = trim((string) ($card['class'] ?? ''));
        return (string) $this->tpl->parse('includes/pregame/card.tpl', [
            'name' => $name,
            'image_html' => $image,
            'price' => self::value($card['price'] ?? null),
            'cost_icon_html' => self::assetExists($costIcon) ? '<img src="' . $costIcon . '" alt="' . (!empty($card['elite']) ? 'Элитная' : 'Обычная') . (!empty($card['single']) ? ', уникальная' : '') . '" width="24" height="24">' : '',
            'stat_values_html' => $stats,
            'rarity_html' => self::icon((string) ($card['rarity'] ?? ''), self::RARITIES, '/assets/images/rarity/s1_', 'Редкость'),
            'element_html' => self::icon((string) ($card['element_code'] ?? ''), self::ELEMENTS, '/assets/images/element/', 'Стихия', (string) ($card['element_label'] ?? '')),
            'class_html' => $class === '' ? '' : self::hint('/assets/images/card/class.png', 'Класс существа: ' . $class),
            'description_html' => $description === '' ? '' : self::hint('/assets/images/card/info@2x.png', 'Описание карты', $description),
        ]);
    }

    private static function stat(string $stat, string $icon, string $value, string $label): string
    {
        return '<button type="button" class="pregame-hint pregame-stat-value" data-stat="' . $stat . '" aria-label="' . self::escape($label) . ': ' . $value . '">'
            . '<img src="/assets/images/card/' . $icon . '.png" alt="" width="24" height="24">'
            . '<span class="pregame-stat-number">' . $value . '</span>'
            . '<template data-pregame-hint>' . self::escape($label) . ': ' . $value . '</template></button>';
    }

    private static function hint(string $url, string $label, string $content = ''): string
    {
        $text = self::escape($label);
        return '<button type="button" class="pregame-hint" aria-label="' . $text . '" aria-expanded="false">'
            . (self::assetExists($url) ? '<img src="' . $url . '" alt="" width="24" height="24">' : '<span>' . $text . '</span>')
            . '<template data-pregame-hint>' . ($content === '' ? $text : $content) . '</template></button>';
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
        $url = $prefix . $code . '.png';
        return self::hint($url, $kind . ': ' . $label);
    }
}
