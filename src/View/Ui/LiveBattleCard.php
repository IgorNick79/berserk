<?php
declare(strict_types=1);
namespace Berserk\View\Ui;
use Berserk\Core\{CardInstance, GameState};

/** New live renderer; legacy BattleCardMarkup remains unchanged for replay/rollback. */
final class LiveBattleCard
{
    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function enabled(): bool { return getenv('BERSERK_LEGACY_MINICARDS') !== '1'; }
    public static function render(CardInstance $card, GameState $state, string $viewer, bool $hidden, string $nameHtml, string $hpText): string
    {
        $side = $card->owner === $viewer ? 'friendly' : 'enemy';
        $indicators = '';
        if (!$hidden) {
            foreach ([['coin',$card->coins,'Монеты'],['armor',$card->armor,'Броня'],['poison',ActiveCardEffects::poison($card),'Яд']] as [$key,$value,$label]) {
                if ($value > 0) $indicators .= self::indicator($key, $value, self::e($label . ': ' . $value));
            }
            $effects = ActiveCardEffects::forCard($card, $state);
            if ($effects) {
                $list = '<ul>' . implode('', array_map(fn($text) => '<li>' . self::e($text) . '</li>', $effects)) . '</ul>';
                $indicators .= self::indicator('effects', count($effects), 'Активные эффекты: ' . count($effects), $list);
            }
        }
        $art = '';
        if (!$hidden && preg_match('/\As([0-9]+)_[a-zA-Z0-9_-]+\z/', $card->ukid, $set)) {
            $path = '/assets/cards/s' . $set[1] . '/' . $card->ukid . '.webp';
            if (is_file(dirname(__DIR__, 3) . '/www' . $path)) $art = '<img class="mini-art" src="' . self::e($path) . '" alt="">';
        }
        $classes = 'battle-card battle-mini-card mini-' . $side;
        if ($hidden) $classes .= ' mini-hidden';
        if ($card->closed) $classes .= ' mini-closed';
        if ($card->dying) $classes .= ' mini-dying';
        $label = $hidden ? 'Скрытая карта' : $nameHtml;
        $nameAttr = $hidden ? '' : ' data-mini-name="' . $nameHtml . '"';
        $hp = ($hidden && $hpText === '?/?') ? '' : '<div class="mini-hp"><svg class="mini-heart" viewBox="0 0 20 18" aria-hidden="true"><path d="M10 17 2 9C-3 3 5-3 10 3 15-3 23 3 18 9Z"/></svg><span>' . self::e($hpText) . '</span></div>';
        return '<div class="' . $classes . '" data-mini-card="' . $card->instanceId . '" role="group" aria-label="' . $label . '"' . $nameAttr . '>'
            . $art . '<img class="mini-frame" src="/assets/images/battle/mini/frame_' . $side . '_102x92_plain.svg" alt="">'
            . ($indicators ? '<div class="mini-indicators">' . $indicators . '</div>' : '')
            . ($hidden ? '<span class="mini-question" aria-hidden="true">?</span>' : '')
            . $hp . '</div>';
    }
    private static function indicator(string $key, int $value, string $label, string $html = ''): string
    {
        $asset = in_array($key, ['coin', 'poison'], true) ? $key . '_compact_v2' : $key . '_compact';
        if ($key !== 'effects') return '<span class="mini-indicator" role="img" aria-label="' . $label . '"><img src="/assets/images/battle/mini/' . $asset . '.svg" alt=""><span>' . $value . '</span></span>';
        return '<span class="mini-indicator" data-mini-hint tabindex="0" role="button" aria-label="' . $label . '" aria-expanded="false">'
            . '<img src="/assets/images/battle/mini/' . $asset . '.svg" alt=""><span>' . $value . '</span>'
            . '<span class="mini-hint-text" hidden>' . ($html ?: $label) . '</span></span>';
    }
}
