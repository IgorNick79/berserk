<?php
// src/Core/BoosterGenerator.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Генератор бустера: 12 карт = 7 common + 4 uncommon + 1 rare/ultra.
 * Ultra — 10% (вместо rare).
 * Внутри бустера дублей нет.
 */
final class BoosterGenerator
{
    public function __construct(private Db $db) {}

    /**
     * @return string[] массив ukid (12 штук)
     */
    public function generate(): array
    {
        $picks = [];

        $picks = array_merge($picks, $this->pick('common',   7, $picks));
        $picks = array_merge($picks, $this->pick('uncommon', 4, $picks));

        $rareRarity = (random_int(1, 10) === 1) ? 'ultrarare' : 'rare';
        $picks = array_merge($picks, $this->pick($rareRarity, 1, $picks));

        return $picks;
    }

    /**
     * @param string[] $exclude ukid, которые уже в бустере
     * @return string[]
     */
    private function pick(string $rarity, int $count, array $exclude): array
    {
        if ($count <= 0) return [];

        $excludeSql = '';
        if (!empty($exclude)) {
            $safe = array_map(fn($u) => $this->db->escape((string) $u), $exclude);
            $excludeSql = " AND ukid NOT IN ('" . implode("','", $safe) . "')";
        }

        $rows = $this->db->fetchAll(
            "SELECT ukid FROM cards
             WHERE rarity = '" . $this->db->escape($rarity) . "'" . $excludeSql . "
             ORDER BY RAND()
             LIMIT " . $count
        );

        return array_map(fn($r) => (string) $r['ukid'], $rows);
    }
}