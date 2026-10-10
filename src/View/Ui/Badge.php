<?php
// src/View/Ui/Badge.php

declare(strict_types=1);

namespace Berserk\View\Ui;

use Berserk\Core\CardInstance;
use Berserk\Core\CardStats;
use Berserk\Core\GameState;

/**
 * Бейджи маркеров и модификаторов для карты.
 * Используется в BattleScreen (поле, FLYING).
 */
final class Badge
{
    private const ELEMENT_LABELS = [
        'plains'    => 'степным',
        'mountains' => 'горным',
        'forests'   => 'лесным',
        'swamps'    => 'болотным',
        'dark'      => 'темным',
    ];

    private const ELEMENT_ICONS = [
        'plains'    => '/assets/images/element-plains.png',
        'mountains' => '/assets/images/element-mountains.png',
        'forests'   => '/assets/images/element-forests.png',
        'swamps'    => '/assets/images/element-swamps.png',
        'dark'      => '/assets/images/element-dark.png',
        'neutral'   => '/assets/images/element-neutral.png',
    ];

    private const MARKER_NAMES = [
        'hunt'       => 'Охота',
        'stun'       => 'Оглушение',
        'poison'     => 'Яд',
        'fire'       => 'Огонь',
        'tremor'     => 'Дрожь',
        'rooted'     => 'Обездвижен',
        'spider_web' => 'Сеть',
        'sand_claws' => 'Когти',
        'prey'       => 'Добыча',
    ];

    private const MOD_MAP = [
        'shield_light'     => ['class' => 'shield', 'showExpire' => true],
        'damage_reduction' => ['class' => 'shield', 'showExpire' => false],
        'direct'           => ['class' => 'direct', 'showExpire' => false],
        'regeneration'     => ['class' => 'regen',  'showExpire' => false],
        'ova'              => ['class' => 'ova',    'showExpire' => false, 'plus' => true],
        'ovz'              => ['class' => 'ovz',    'showExpire' => false, 'plus' => true],
        'ability_strike'   => ['class' => 'strike', 'showExpire' => false, 'plus' => true],
        'ability_discharge' => ['class' => 'strike', 'showExpire' => false, 'plus' => true],
        'move'              => ['class' => 'move',   'showExpire' => false, 'plus' => true],
        'coin_strike_bonus' => ['class' => 'strike', 'showExpire' => false, 'plus' => true],
        'next_action_bonus' => ['class' => 'strike', 'showExpire' => false, 'plus' => true],
        'next_strike_bonus' => ['class' => 'strike', 'showExpire' => false, 'plus' => true],
    ];

    public static function forCard(CardInstance $card, ?GameState $state = null): string
    {
        $items = [];

        foreach ($card->markers as $type => $m) {
            $items[] = self::marker($type, $m);
        }

        $sums    = [];
        $expires = [];
        $propAbilityStrike = 0;

        foreach ($card->modifiers as $m) {
            $stat = (string) ($m['stat'] ?? '');
            if (!isset(self::MOD_MAP[$stat])) continue;

            $sums[$stat] = ($sums[$stat] ?? 0) + (int) ($m['value'] ?? 1);
            if (!isset($expires[$stat])) {
                $expires[$stat] = (int) ($m['expire'] ?? 0);
            }
        }

        // Статичные из prop
        if ($state !== null) {
            $propBadges = CardStats::getActivePropBadges($state, $card);
            foreach ($propBadges as $stat => $val) {
                if ($stat === 'direct') {
                    $sums['direct'] = 1;
                } else {
                    if ($stat === 'ability_strike') {
                        $propAbilityStrike += (int) $val;
                    }
                    $sums[$stat] = ($sums[$stat] ?? 0) + (int) $val;
                }
            }
        }

        $conditionalStrikeDeduction = self::conditionalStrikeDeduction($card, $state);
        $conditionalStrikeBadges = self::conditionalStrikeBadges($card, $state);
        if ($propAbilityStrike !== 0 && $conditionalStrikeDeduction !== 0) {
            $sums['ability_strike'] = ($sums['ability_strike'] ?? 0) - $conditionalStrikeDeduction;
            if (($sums['ability_strike'] ?? 0) === 0) {
                unset($sums['ability_strike']);
            }
        }

        foreach ($sums as $stat => $value) {
            if (!isset(self::MOD_MAP[$stat])) continue;
            $items[] = self::modifier($stat, (int) $value, $expires[$stat] ?? 0);
        }

        foreach ($conditionalStrikeBadges as $badge) {
            $items[] = self::conditionalStrike((int) $badge['value'], (string) $badge['element']);
        }

        if (empty($items)) return '';
        return '<div class="bmarkers">' . implode('', $items) . '</div>';
    }

    /**
     * @return array<int,array{element:string,value:int}>
     */
    private static function conditionalStrikeBadges(CardInstance $card, ?GameState $state): array
    {
        return self::conditionalStrikeAbilities($card, $state, false);
    }

    private static function conditionalStrikeDeduction(CardInstance $card, ?GameState $state): int
    {
        $sum = 0;
        foreach (self::conditionalStrikeAbilities($card, $state, true) as $ability) {
            $sum += (int) $ability['value'];
        }
        return $sum;
    }

    /**
     * @return array<int,array{element:string,value:int}>
     */
    private static function conditionalStrikeAbilities(
        CardInstance $card,
        ?GameState $state,
        bool $includeLevelRestricted
    ): array
    {
        $abilities = $card->prop['ability'] ?? null;
        if ($abilities === null) return [];
        if (isset($abilities['value'])) $abilities = [$abilities];
        if (!is_array($abilities)) return [];

        $result = [];

        foreach ($abilities as $ability) {
            if (!is_array($ability)) continue;
            if (empty($ability['element'])) continue;
            if (!empty($ability['types'])) continue;
            if (!empty($ability['only']) && !in_array('strike', $ability['only'], true)) continue;
            if (!$includeLevelRestricted && !empty($ability['level'])) continue;
            if (!empty($ability['condition'])
                && ($state === null || !CardStats::checkCondition($ability['condition'], $state, $card))) {
                continue;
            }

            $value = (int) ($ability['value'] ?? 0);
            if ($value === 0) continue;

            $element = (string) $ability['element'];
            $result[$element] = [
                'element' => $element,
                'value'   => ($result[$element]['value'] ?? 0) + $value,
            ];
        }

        return array_values($result);
    }

    private static function marker(string $type, $m): string
    {
        $name = self::MARKER_NAMES[$type] ?? $type;
        $val  = null;

        if (is_array($m)) {
            if (isset($m['value'])) {
                $val = $m['value'];
            } elseif (isset($m['sources']) && is_array($m['sources'])) {
                $val = count($m['sources']);
                if ($val === 1) $val = null;
            }
        } elseif (is_numeric($m)) {
            $val = $m;
        }

        $text = $name;
        $alwaysShowValue = ['poison'];
        if ($val !== null && ($val > 1 || in_array($type, $alwaysShowValue, true))) {
            $text .= ' ' . $val;
        }

        return self::raw($type, $text);
    }

    private static function modifier(string $stat, int $value, int $expire): string
    {
        $cfg  = self::MOD_MAP[$stat];
        $text = CardStats::statLabel($stat);

        if (!empty($cfg['showExpire'])) {
            $text .= ' ' . $expire;
        } elseif (!empty($cfg['plus'])) {
            $text .= ' +' . $value;
        } elseif ($value !== 1) {
            $text .= ' ' . $value;
        }

        return self::raw($cfg['class'], $text);
    }

    private static function conditionalStrike(int $value, string $element): string
    {
        $conditionHtml = self::elementConditionHtml($element);
        $sign = $value > 0 ? '+' : '';

        return '<span class="marker marker-conditional-strike">'
            . '<span class="marker-conditional-strike__value">удар ' . htmlspecialchars($sign . (string) $value, ENT_QUOTES) . '</span>'
            . $conditionHtml
            . '</span>';
    }

    private static function elementConditionHtml(string $element): string
    {
        $icon = self::ELEMENT_ICONS[$element] ?? null;
        $label = self::ELEMENT_LABELS[$element] ?? $element;

        if (is_string($icon) && $icon !== '' && self::assetExists($icon)) {
            return '<img class="marker-element-icon" src="'
                . htmlspecialchars($icon, ENT_QUOTES)
                . '" alt="по '
                . htmlspecialchars($label, ENT_QUOTES)
                . '">';
        }

        return '<span class="marker-element-fallback">по '
            . htmlspecialchars($label, ENT_QUOTES)
            . '</span>';
    }

    private static function assetExists(string $webPath): bool
    {
        if (!str_starts_with($webPath, '/assets/')) return false;

        $fullPath = dirname(__DIR__, 3) . '/www' . $webPath;
        return is_file($fullPath);
    }

    private static function raw(string $class, string $text): string
    {
        return '<span class="marker marker-'
            . htmlspecialchars($class, ENT_QUOTES) . '">'
            . htmlspecialchars($text, ENT_QUOTES) . '</span>';
    }
}
