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
        return '<div class="' . $classes . '" data-mini-card="' . $card->instanceId . '">'
            . $art . '<img class="mini-frame" src="/assets/images/battle/mini/frame_' . $side . '_wide.svg" alt="">'
            . ($indicators ? '<div class="mini-indicators">' . $indicators . '</div>' : '')
            . '<div class="mini-name' . ($indicators ? ' has-indicators' : '') . '"><span>' . $nameHtml . '</span></div>'
            . '<div class="mini-hp">' . self::e($hpText) . '</div></div>';
    }
    private static function indicator(string $key, int $value, string $label, string $html = ''): string
    {
        return '<span class="mini-indicator" data-mini-hint tabindex="0" role="button" aria-label="' . $label . '" aria-expanded="false">'
            . '<img src="/assets/images/battle/mini/' . $key . '_compact.svg" alt=""><span>' . $value . '</span>'
            . '<span class="mini-hint-text" hidden>' . ($html ?: $label) . '</span></span>';
    }
}
