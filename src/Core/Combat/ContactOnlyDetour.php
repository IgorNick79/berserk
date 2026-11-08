<?php
declare(strict_types=1);

namespace Berserk\Core\Combat;

/** Distances on the current public board. Only a certified first step can be returned. */
final class ContactOnlyDetour
{
    /** Return the moves subject to arbitration and their safe, decreasing subset. */
    public static function evaluate(array $observation, array $legal, array $visited): array
    {
        $result = ['restricted'=>[], 'decreasing'=>[], 'allowed'=>[], 'fallback'=>null];
        $field = array_column(array_filter($observation['cards'], static fn($card) => $card['zone'] === 'field'), null, 'id');
        $eligible = array_filter($field, static fn(array $card): bool =>
            ($card['contact_inventory'] ?? null) === 'absent' && $card['hp'] > 3
        );
        if ($eligible === []) {
            return $result;
        }
        $moves = array_filter($legal['actions'], static fn(array $action): bool =>
            $action['type'] === 'move' && isset($eligible[$action['payload']['card_id']])
        );
        // Reuse the existing snapshot gates and per-channel contact goals.
        $geometry = PositionalProgressFallback::evaluate($observation, array_replace($legal, ['actions'=>$moves]), $visited);
        if (!$geometry['sources']) {
            return $result;
        }
        $occupied = [];
        foreach ($field as $card) {
            $occupied[$card['row'].':'.$card['col']] = $card['id'];
        }

        $best = null;
        $bestRank = null;
        foreach ($geometry['sources'] as $id => $profile) {
            $source = $field[$id];
            if (!$profile['goals']) {
                continue;
            }
            [$distance, $expanded] = self::distances($profile['goals'], $occupied, $id);
            $from = $source['row'].':'.$source['col'];
            $before = $distance[$from] ?? null;
            if ($before === null) {
                continue;
            }
            foreach ($moves as $action) {
                if ($action['payload']['card_id'] !== $id) {
                    continue;
                }
                $destination = $action['payload'];
                $result['restricted'][$id] = true;
                $row = $destination['row'];
                $col = $destination['col'];
                $key = $row.':'.$col;
                if (isset($visited[$id][$key]) || isset($occupied[$key])
                    || abs($row-$source['row'])+abs($col-$source['col']) !== 1
                    || ($distance[$key] ?? PHP_INT_MAX) >= $before) {
                    continue;
                }
                $result['decreasing'][serialize($action)] = true;
                $isolated = true;
                foreach ($field as $other) {
                    if ($other['id'] !== $id && $other['owner'] === $source['owner'] && self::adjacent($destination, $other)) {
                        $isolated = false;
                    }
                }
                if (self::exposed($source, $destination, $observation['cards'])) {
                    continue;
                }
                $result['allowed'][serialize($action)] = true;
                $score = 3 - ($isolated ? 2 : 0);
                $rank = [$distance[$key], -$score, $row, $col, $source['row'], $source['col'], $id];
                if ($bestRank === null || $rank < $bestRank) {
                    $bestRank = $rank;
                    $best = ['action'=>$action, 'reason'=>'contact_only_detour', 'score'=>$score,
                        'factors'=>['detour_progress'=>3, 'isolated'=>$isolated ? -2 : 0],
                        'detour'=>['distance_before'=>$before, 'distance_after'=>$distance[$key],
                            'expanded'=>$expanded, 'goals'=>count($profile['goals'])]];
                }
            }
        }
        $result['fallback'] = $best;
        return $result;
    }

    private static function distances(array $goals, array $occupied, int $source): array
    {
        $queue = [];
        $distance = [];
        foreach ($goals as $goal) {
            $queue[] = [$goal['row'], $goal['col']];
            $distance[$goal['row'].':'.$goal['col']] = 0;
        }
        for ($head = 0; $head < count($queue) && $head < 200; $head++) {
            [$row, $col] = $queue[$head];
            $depth = $distance[$row.':'.$col];
            if ($depth >= 8) {
                continue;
            }
            foreach ([[-1,0],[0,-1],[0,1],[1,0]] as [$dr,$dc]) {
                $r = $row+$dr;
                $c = $col+$dc;
                $key = $r.':'.$c;
                if ($r<1 || $r>6 || $c<1 || $c>5 || isset($distance[$key])
                    || (isset($occupied[$key]) && $occupied[$key] !== $source)) {
                    continue;
                }
                $distance[$key] = $depth+1;
                $queue[] = [$r,$c];
            }
        }
        return [$distance,$head];
    }

    private static function exposed(array $source, array $destination, array $cards): bool
    {
        foreach ($cards as $enemy) {
            if ($enemy['owner'] === $source['owner'] || $enemy['zone'] !== 'field'
                || $enemy['closed'] || $enemy['hp'] <= 0) {
                continue;
            }
            if (self::adjacent($destination, $enemy) && !self::adjacent($source, $enemy)) {
                return true;
            }
        }
        return false;
    }

    private static function adjacent(array $a, array $b): bool
    {
        return max(abs($a['row']-$b['row']), abs($a['col']-$b['col'])) <= 1;
    }
}
