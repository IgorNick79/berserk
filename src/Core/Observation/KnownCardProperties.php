<?php
declare(strict_types=1);

namespace Berserk\Core\Observation;

use Berserk\Core\CardInstance;

/** Card-local facts only. Contextual defenses and hidden providers stay unknown. */
final class KnownCardProperties
{
    private const DEFENSES = [
        'zoa', 'zoan', 'zoal', 'zoz', 'zor', 'zom', 'zoda', 'zov', 'zot', 'zoo', 'stamina',
    ];
    private const ACTIONS = [
        'shot', 'throw', 'discharge', 'magic', 'cast', 'tap', 'heal', 'revive',
        'execute', 'strangle', 'give_coin', 'multi_discharge', 'jump',
    ];

    public static function build(CardInstance $card): array
    {
        $effective = $card->effectiveProp();
        $printed = self::actions($card->prop);
        $current = self::actions($effective);
        $defenses = [];
        foreach (self::DEFENSES as $key) {
            $declared = $card->prop[$key] ?? null;
            if (empty($declared)) continue;
            $direct = $effective[$key] ?? null;
            $defenses[$key] = [
                'declared' => true,
                // Presence of a direct property is not a guarantee against a given attack.
                'direct' => empty($direct) ? 'absent' : (is_array($direct) ? 'conditional' : 'present'),
                'outcome' => 'unknown',
            ];
        }
        return [
            'defenses' => $defenses,
            'declared_actions' => $printed,
            'current_actions' => $current,
            'passives' => array_values(array_filter(['direct', 'vampire', 'has_line'],
                static fn(string $key): bool => ($card->prop[$key] ?? null) === true)),
            'contextual_effects' => 'unknown',
        ];
    }

    private static function actions(array $prop): array
    {
        $out = [];
        foreach ($prop['actions'] ?? [] as $action) {
            $type = $action['type'] ?? null;
            if (!in_array($type, self::ACTIONS, true)) continue;
            $fact = ['type' => $type, 'availability' => 'unknown'];
            foreach (['coins', 'coins_required', 'range', 'value', 'max_targets'] as $key) {
                if (is_int($action[$key] ?? null)) $fact[$key] = $action[$key];
            }
            if (isset($action['condition'])) $fact['additional_condition'] = 'unknown';
            $out[] = $fact;
        }
        return $out;
    }
}
