<?php
// src/Core/Prepare/DraftSelectionEvaluator.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

final class DraftSelectionEvaluator
{
    private const DEFAULT_POWER = 3.0;
    private const RANDOMNESS_SCALE = 0.55;
    private const MIN_WEIGHT = 0.05;
    private const MAX_WEIGHT = 20.0;

    private const ROLE_TARGETS = [
        'frontline' => 12,
        'pinger'   => 8,
        'healer'   => 2,
    ];

    private const ROLE_WEIGHTS = [
        'frontline' => 1.25,
        'pinger'   => 1.55,
        'healer'   => 0.75,
    ];

    private const SYNERGY_TYPE_WEIGHTS = [
        'standard' => 1.0,
        'sealed'   => 0.7,
        'draft'    => 0.5,
    ];

    /**
     * @param array<string,array<string,mixed>> $cardsByUkid
     * @param array<string,array<string,int>> $synergyByPair pair key => type => co-occurrence count
     */
    public function __construct(
        private array $cardsByUkid,
        private array $synergyByPair = [],
    ) {}

    /**
     * Scores the whole candidate selection against the resulting draft state.
     *
     * @param string[] $currentUkids
     * @param string[] $candidateUkids
     */
    public function evaluate(array $currentUkids, array $candidateUkids): float
    {
        $candidateUkids = array_values(array_filter($candidateUkids, fn($u) => $u !== null && $u !== ''));
        if (empty($candidateUkids)) return 0.0;

        $currentCards = $this->cardsFor($currentUkids);
        $candidateCards = $this->cardsFor($candidateUkids);
        if (empty($candidateCards)) return 0.0;

        $afterCards = array_merge($currentCards, $candidateCards);

        return 1.0
            + $this->powerContribution($candidateCards)
            + $this->roleContribution($currentCards, $afterCards)
            + $this->costContribution($currentCards, $candidateCards, $afterCards)
            + $this->flyerContribution($currentCards, $candidateCards)
            + $this->singleContribution($currentUkids, $candidateCards)
            + $this->duplicateContribution($currentUkids, $candidateUkids, $candidateCards)
            + $this->synergyContribution($currentUkids, $candidateUkids)
            + count($candidateCards) * 0.25;
    }

    /**
     * @param string[] $currentUkids
     * @param string[] $candidateUkids
     */
    public function weight(array $currentUkids, array $candidateUkids): float
    {
        $weight = exp($this->evaluate($currentUkids, $candidateUkids) * self::RANDOMNESS_SCALE);
        return max(self::MIN_WEIGHT, min(self::MAX_WEIGHT, $weight));
    }

    /** @param string[] $ukids */
    private function cardsFor(array $ukids): array
    {
        $cards = [];
        foreach ($ukids as $ukid) {
            $key = (string) $ukid;
            if (isset($this->cardsByUkid[$key])) {
                $cards[] = $this->normalizeCard($this->cardsByUkid[$key] + ['ukid' => $key]);
            }
        }
        return $cards;
    }

    private function normalizeCard(array $card): array
    {
        $prop = $card['prop'] ?? [];
        if (is_string($prop)) {
            $decoded = json_decode($prop, true);
            $prop = is_array($decoded) ? $decoded : [];
        }

        return [
            'ukid'          => (string) ($card['ukid'] ?? ''),
            'power'         => $this->positiveFloat($card['power'] ?? null, self::DEFAULT_POWER),
            'single'        => !empty($card['single']),
            'price'         => max(0, (int) ($card['price'] ?? 0)),
            'health'        => max(0, (int) ($card['health'] ?? 0)),
            'elite'         => !empty($card['elite']),
            'type'          => (string) ($card['type'] ?? 'creature'),
            'strike_weak'   => max(0, (int) ($card['strike_weak'] ?? 0)),
            'strike_medium' => max(0, (int) ($card['strike_medium'] ?? 0)),
            'strike_strong' => max(0, (int) ($card['strike_strong'] ?? 0)),
            'prop'          => is_array($prop) ? $prop : [],
        ];
    }

    private function positiveFloat(mixed $value, float $fallback): float
    {
        if (!is_numeric($value)) return $fallback;
        $number = (float) $value;
        return $number > 0.0 ? $number : $fallback;
    }

    private function powerContribution(array $candidateCards): float
    {
        $sum = 0.0;
        foreach ($candidateCards as $card) {
            $sum += ((float) $card['power'] - self::DEFAULT_POWER) * 0.42;
        }
        return $sum;
    }

    private function roleContribution(array $currentCards, array $afterCards): float
    {
        $score = 0.0;
        foreach (self::ROLE_TARGETS as $role => $target) {
            $before = $this->countRole($currentCards, $role);
            $after = $this->countRole($afterCards, $role);
            $beforeNeed = sqrt((float) min($before, $target));
            $afterNeed = sqrt((float) min($after, $target));
            $score += ($afterNeed - $beforeNeed) * self::ROLE_WEIGHTS[$role];
        }
        return $score;
    }

    private function costContribution(array $currentCards, array $candidateCards, array $afterCards): float
    {
        $score = 0.0;
        foreach ($candidateCards as $card) {
            if ((int) $card['price'] === 3) $score += 0.18;
            if ((int) $card['price'] >= 6) $score -= 0.08;
        }

        foreach ([true, false] as $elite) {
            $avgBefore = $this->averageCost($currentCards, $elite);
            $avgAfter = $this->averageCost($afterCards, $elite);
            if ($avgAfter === null) continue;

            if ($avgAfter > 5.0) {
                $score -= ($avgAfter - 5.0) * ($avgBefore !== null && $avgBefore > 5.0 ? 0.42 : 0.22);
            } elseif ($avgAfter >= 4.0) {
                $score += 0.10;
            }
        }

        return $score;
    }

    private function averageCost(array $cards, bool $elite): ?float
    {
        $sum = 0;
        $count = 0;
        foreach ($cards as $card) {
            if ((bool) $card['elite'] !== $elite) continue;
            $sum += (int) $card['price'];
            $count++;
        }
        return $count > 0 ? $sum / $count : null;
    }

    private function flyerContribution(array $currentCards, array $candidateCards): float
    {
        $flyers = 0;
        foreach ($currentCards as $card) {
            if ($this->hasRole($card, 'flyer')) $flyers++;
        }

        $score = 0.0;
        foreach ($candidateCards as $card) {
            if (!$this->hasRole($card, 'flyer')) continue;
            $flyers++;
            if ($flyers >= 6) {
                $score -= 0.55;
            } elseif ($flyers >= 4) {
                $score -= 0.25;
            } else {
                $score += 0.10;
            }
        }
        return $score;
    }

    private function singleContribution(array $currentUkids, array $candidateCards): float
    {
        $currentCounts = array_count_values(array_map('strval', $currentUkids));
        $score = 0.0;
        foreach ($candidateCards as $card) {
            if (!empty($card['single']) && ($currentCounts[$card['ukid']] ?? 0) > 0) {
                $score -= 0.35;
            }
        }
        return $score;
    }

    private function duplicateContribution(array $currentUkids, array $candidateUkids, array $candidateCards): float
    {
        $counts = array_count_values(array_map('strval', array_merge($currentUkids, $candidateUkids)));
        $seenCandidate = [];
        $score = 0.0;
        foreach ($candidateCards as $card) {
            $ukid = $card['ukid'];
            if (!empty($card['single'])) continue;
            if (isset($seenCandidate[$ukid])) continue;
            $seenCandidate[$ukid] = true;
            $extra = max(0, ($counts[$ukid] ?? 0) - 3);
            $score -= $extra * 0.08;
        }
        return $score;
    }

    private function synergyContribution(array $currentUkids, array $candidateUkids): float
    {
        if (empty($this->synergyByPair)) return 0.0;

        $pairs = [];
        foreach ($candidateUkids as $candidate) {
            foreach ($currentUkids as $current) {
                $pairs[$this->pairKey((string) $candidate, (string) $current)] = true;
            }
        }

        $candidateCount = count($candidateUkids);
        for ($i = 0; $i < $candidateCount; $i++) {
            for ($j = $i + 1; $j < $candidateCount; $j++) {
                $pairs[$this->pairKey((string) $candidateUkids[$i], (string) $candidateUkids[$j])] = true;
            }
        }

        if (empty($pairs)) return 0.0;

        $sum = 0.0;
        $matched = 0;
        foreach (array_keys($pairs) as $pairKey) {
            $byType = $this->synergyByPair[$pairKey] ?? null;
            if (!$byType) continue;

            $pairScore = 0.0;
            foreach ($byType as $type => $count) {
                $weight = self::SYNERGY_TYPE_WEIGHTS[$type] ?? 0.5;
                $pairScore += log(1 + max(0, (int) $count)) * $weight;
            }

            $sum += min(1.0, $pairScore / 4.0);
            $matched++;
        }

        return $matched > 0 ? ($sum / $matched) * 0.90 : 0.0;
    }

    private function countRole(array $cards, string $role): int
    {
        $count = 0;
        foreach ($cards as $card) {
            if ($this->hasRole($card, $role)) $count++;
        }
        return $count;
    }

    private function hasRole(array $card, string $role): bool
    {
        return match ($role) {
            'frontline' => $this->isFrontline($card),
            'pinger'   => $this->hasActionType($card, ['shot', 'throw', 'discharge']),
            'healer'   => $this->hasActionType($card, ['heal', 'multi_heal']) || $this->hasPropType($card['prop'], ['heal']),
            'flyer'    => (string) $card['type'] === 'fly' || $this->hasPropType($card['prop'], ['become_fly']),
            default    => false,
        };
    }

    private function isFrontline(array $card): bool
    {
        $maxStrike = max((int) $card['strike_weak'], (int) $card['strike_medium'], (int) $card['strike_strong']);
        return (int) $card['health'] >= 6 && $maxStrike >= 2;
    }

    private function hasActionType(array $card, array $types): bool
    {
        foreach (($card['prop']['actions'] ?? []) as $action) {
            if (is_array($action) && in_array((string) ($action['type'] ?? ''), $types, true)) {
                return true;
            }
        }
        return false;
    }

    private function hasPropType(mixed $value, array $types): bool
    {
        if (!is_array($value)) return false;
        if (isset($value['type']) && in_array((string) $value['type'], $types, true)) return true;
        foreach ($value as $item) {
            if ($this->hasPropType($item, $types)) return true;
        }
        return false;
    }

    private function pairKey(string $a, string $b): string
    {
        return $a < $b ? $a . "\0" . $b : $b . "\0" . $a;
    }
}
