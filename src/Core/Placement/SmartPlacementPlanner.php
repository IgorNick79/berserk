<?php
declare(strict_types=1);

namespace Berserk\Core\Placement;

/** Ranks arrangements over the current placement options. Only the first action is executable. */
final class SmartPlacementPlanner
{
    private const WIDTH = 16;
    private const BUDGET = 4096;

    public static function plan(array $observation, array $legal): array
    {
        $empty = ['action'=>null, 'position'=>[], 'score'=>null, 'factors'=>[],
            'examined'=>0, 'coverage'=>'partial', 'reason'=>'unsupported_snapshot'];
        $side = $observation['viewer'] ?? null;
        if (!in_array($side, ['host', 'player'], true) || $side !== ($legal['viewer'] ?? null)
            || !array_key_exists('version', $observation) || !array_key_exists('version', $legal)
            || $observation['version'] !== $legal['version']
            || ($observation['stage'] ?? null) !== 'place' || ($legal['stage'] ?? null) !== 'place'
            || ($legal['decision'] ?? null) !== 'placement'
            || !in_array($legal['coverage'] ?? null, ['complete', 'partial'], true)) return $empty;

        $cards = $base = $squad = $options = [];
        foreach ($observation['cards'] as $card) {
            if ($card['owner'] !== $side || !in_array($card['zone'], ['field', 'squad'], true)) continue;
            $id = $card['id'];
            $cards[$id] = $card;
            if ($card['zone'] === 'squad') $squad[$id] = true;
            else $base[$id] = [$card['row'], $card['col']];
        }
        foreach ($legal['actions'] as $option) {
            if ($option['type'] !== 'place_card') continue;
            $p = $option['payload'];
            if (!isset($cards[$p['card_id']])) continue;
            $options[$p['card_id']][$p['row'] . ':' . $p['col']] = $option;
        }
        foreach ($options as &$cells) ksort($cells, SORT_STRING);
        unset($cells);
        ksort($base, SORT_NUMERIC);
        $order = array_keys($squad);
        usort($order, static fn(int $a, int $b): int =>
            (count($options[$a] ?? []) <=> count($options[$b] ?? [])) ?: ($a <=> $b));

        $best = self::evaluate($cards, $base, $side, null);
        $beam = [$best];
        $examined = 0;
        // Skip branches preserve useful partial suggestions when some cards have no current cells.
        foreach ($order as $id) {
            $next = $beam;
            foreach ($beam as $node) {
                foreach ($options[$id] ?? [] as $option) {
                    $cell = [$option['payload']['row'], $option['payload']['col']];
                    if (in_array($cell, $node['position'], true)) continue;
                    if ($examined >= self::BUDGET) break 3;
                    $next[] = self::evaluate($cards, $node['position'] + [$id=>$cell], $side, $node['action'] ?? $option);
                    $examined++;
                }
            }
            usort($next, [self::class, 'compare']);
            $beam = array_slice($next, 0, self::WIDTH);
            $best = $beam[0];
        }

        // Once no unplaced card can be added, allow a strictly improving, confirmed reposition.
        if ($best['action'] === null && $squad === []) {
            foreach ($base as $id => $_) {
                foreach ($options[$id] ?? [] as $option) {
                    if ($examined >= self::BUDGET) break 2;
                    $cell = [$option['payload']['row'], $option['payload']['col']];
                    if (in_array($cell, $base, true)) continue;
                    $candidate = self::evaluate($cards, array_replace($base, [$id=>$cell]), $side, $option);
                    $examined++;
                    if ($candidate['score'] > $best['score']) $best = $candidate;
                }
            }
        }
        return $best + [
            'examined'=>$examined, 'coverage'=>'partial',
            'all_cards_assigned'=>count($best['position']) === count($cards),
            'reason'=>$best['action'] === null ? 'no_supported_improvement' : 'ranked_current_options',
        ];
    }

    private static function compare(array $a, array $b): int
    {
        return (count($b['position']) <=> count($a['position']))
            ?: ($b['score'] <=> $a['score'])
            ?: strcmp(json_encode($a['position'], JSON_THROW_ON_ERROR), json_encode($b['position'], JSON_THROW_ON_ERROR));
    }

    private static function evaluate(array $cards, array $position, string $side, ?array $action): array
    {
        $factors = ['frontline'=>0, 'cover'=>0, 'attack_access'=>0, 'ranged_access'=>0, 'mobility'=>0, 'formation'=>0];
        $details = [];
        $forward = $side === 'host' ? 1 : -1;
        $front = $side === 'host' ? 3 : 4;
        foreach ($position as $id => [$row, $col]) {
            $card = $cards[$id];
            $depth = abs($front - $row);
            $durability = ($card['hp'] ?? 0) + ($card['armor'] ?? 0);
            $strike = $card['strike']['strong'] ?? 0;
            $move = $card['move_max'] ?? 0;
            $support = $range = 0;
            foreach ($card['properties']['current_actions'] ?? [] as $ability) {
                if (isset($ability['coins_required']) && $ability['coins_required'] !== ($card['coins'] ?? null)) continue;
                if (($ability['coins'] ?? 0) > ($card['coins'] ?? 0) || isset($ability['additional_condition'])) continue;
                if (in_array($ability['type'], ['heal','revive','give_coin'], true)) $support++;
                if (in_array($ability['type'], ['shot','throw','discharge','cast'], true)) {
                    $range = max($range, $ability['range'] ?? 0);
                }
            }
            $covered = false;
            $neighbours = 0;
            foreach ($position as $other => [$r, $c]) {
                if ($other === $id) continue;
                if (($cards[$other]['type'] ?? null) !== 'fly'
                    && ($r - $row) * $forward > 0 && abs($c - $col) <= 1) $covered = true;
                if (max(abs($r - $row), abs($c - $col)) === 1) $neighbours++;
            }
            $blocked = in_array([$row + $forward, $col], $position, true);
            // Flyers are scored conservatively: placement coordinates may cease to apply at reveal.
            $ground = ($card['type'] ?? null) !== 'fly';
            // Project coverage across the opposing front. These are directions, not targets.
            // A friendly occupant blocks a move, but is not a generic projectile obstacle.
            $lanes = 0;
            if ($ground && $range > 0) {
                $reach = $range - abs($front + $forward - $row);
                if ($reach >= 0) {
                    $lanes = min(5, $col + $reach) - max(1, $col - $reach) + 1;
                }
            }
            $local = [
                'frontline'=>$ground && $depth === 0 ? $durability - 4 * $support : 0,
                'cover'=>$ground && $covered ? 2 * $support + max(0, 6 - $durability) : 0,
                'attack_access'=>$ground ? $strike * (2 - $depth) : 0,
                'ranged_access'=>3 * $lanes,
                'mobility'=>$ground && $blocked ? -2 * min($move, 3) : 0,
                'formation'=>($neighbours === 0 && count($position) > 1 ? -2 : 0)
                    - ($durability < 6 && $neighbours >= 3 ? 2 : 0),
            ];
            foreach ($local as $key => $value) $factors[$key] += $value;
            $details[$id] = ['cell'=>[$row,$col], 'covered'=>$covered,'ranged_lanes'=>$lanes,'forward_blocked'=>$blocked,'factors'=>$local];
        }
        return ['action'=>$action, 'position'=>$position, 'score'=>array_sum($factors), 'factors'=>$factors, 'cards'=>$details];
    }
}
