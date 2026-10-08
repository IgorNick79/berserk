<?php
// src/Core/Prepare/BoosterGenerator.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

use Berserk\Core\Db;

/**
 * Генератор бустера: 12 карт = 7 common + 4 uncommon + 1 rare/ultra.
 * Ultra — 10% (вместо rare).
 * Внутри бустера дублей нет.
 */
final class BoosterGenerator
{
    /** @var array<string,array<int,array<string,mixed>>> */
    private array $cardsByRarity = [];

    public function __construct(private Db $db) {}

    /**
     * @return string[] массив ukid (12 штук)
     */
    public function generate(): array
    {
        $poolCounts = [];
        return $this->generateBooster($poolCounts);
    }

    /**
     * @return string[]
     */
    public function generatePool(int $boosterCount): array
    {
        if ($boosterCount <= 0) {
            throw new \RuntimeException('Неверное количество бустеров');
        }

        $pool = [];
        $poolCounts = [];
        for ($i = 0; $i < $boosterCount; $i++) {
            $booster = $this->generateBooster($poolCounts);
            foreach ($booster as $ukid) {
                $pool[] = $ukid;
            }
        }

        return $pool;
    }

    /**
     * @param array<string,int> $poolCounts
     * @return string[]
     */
    private function generateBooster(array &$poolCounts): array
    {
        $candidateCounts = $poolCounts;
        $picks = [];

        $picks = array_merge($picks, $this->pick('common', 7, $picks, $candidateCounts));
        $picks = array_merge($picks, $this->pick('uncommon', 4, $picks, $candidateCounts));

        $rareRarity = (random_int(1, 10) === 1) ? 'ultrarare' : 'rare';
        $picks = array_merge($picks, $this->pick($rareRarity, 1, $picks, $candidateCounts));
        $poolCounts = $candidateCounts;

        return $picks;
    }

    /**
     * @param string[] $exclude ukid, которые уже в бустере
     * @param array<string,int> $poolCounts
     * @return string[]
     */
    private function pick(string $rarity, int $count, array $exclude, array &$poolCounts): array
    {
        if ($count <= 0) return [];

        $rows = $this->cardsForRarity($rarity);
        shuffle($rows);

        $excludeSet = array_fill_keys(array_map('strval', $exclude), true);
        $candidateCounts = $poolCounts;
        $picked = [];
        foreach ($rows as $row) {
            $ukid = (string) ($row['ukid'] ?? '');
            if ($ukid === '' || isset($excludeSet[$ukid])) continue;

            $limit = DraftCopyRules::poolLimit($row);
            if (($candidateCounts[$ukid] ?? 0) >= $limit) continue;

            $picked[] = $ukid;
            $candidateCounts[$ukid] = ($candidateCounts[$ukid] ?? 0) + 1;
            $excludeSet[$ukid] = true;

            if (count($picked) >= $count) {
                $poolCounts = $candidateCounts;
                return $picked;
            }
        }

        throw new \RuntimeException("Недостаточно карт редкости {$rarity} для бустера с лимитами копий");
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function cardsForRarity(string $rarity): array
    {
        if (!isset($this->cardsByRarity[$rarity])) {
            $this->cardsByRarity[$rarity] = $this->db->fetchAll(
                "SELECT ukid, prop FROM cards
                 WHERE rarity = '" . $this->db->escape($rarity) . "'"
            );
        }

        return $this->cardsByRarity[$rarity];
    }
}
