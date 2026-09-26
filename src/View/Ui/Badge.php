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
    private const MARKER_NAMES = [
        'hunt'       => 'Охота',
        'stun'       => 'Оглушение',
        'poison'     => 'Яд',
        'fire'       => 'Огонь',
        'tremor'     => 'Дрожь',
        'rooted'     => 'Обездвижен',
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
    ];

    public static function forCard(CardInstance $card, ?GameState $state = null): string
    {
        $items = [];

        foreach ($card->markers as $type => $m) {
            $items[] = self::marker($type, $m);
        }

        $sums    = [];
        $expires = [];

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
                    $sums[$stat] = ($sums[$stat] ?? 0) + (int) $val;
                }
            }
        }

        foreach ($sums as $stat => $value) {
            if (!isset(self::MOD_MAP[$stat])) continue;
            $items[] = self::modifier($stat, (int) $value, $expires[$stat] ?? 0);
        }

        if (empty($items)) return '';
        return '<div class="bmarkers">' . implode('', $items) . '</div>';
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

    private static function raw(string $class, string $text): string
    {
        return '<span class="marker marker-'
            . htmlspecialchars($class, ENT_QUOTES) . '">'
            . htmlspecialchars($text, ENT_QUOTES) . '</span>';
    }
}
