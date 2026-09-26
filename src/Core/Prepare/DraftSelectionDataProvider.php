<?php
// src/Core/Prepare/DraftSelectionDataProvider.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

use Berserk\Core\Db;

final class DraftSelectionDataProvider
{
    public function __construct(private Db $db) {}

    /** @param string[] $ukids */
    public function loadCardsByUkid(array $ukids): array
    {
        $unique = array_values(array_unique(array_map('strval', $ukids)));
        if (empty($unique)) return [];

        $in = "'" . implode("','", array_map(fn($u) => $this->db->escape($u), $unique)) . "'";
        $rows = $this->db->fetchAll(
            "SELECT ind, ukid, name, power, single, price, health, move, elite, type, class,
                    strike_weak, strike_medium, strike_strong, element_id, prop
             FROM cards WHERE ukid IN ($in)"
        );

        $cards = [];
        foreach ($rows as $row) {
            $cards[(string) $row['ukid']] = $row;
        }
        return $cards;
    }

    /** @param array<string,array<string,mixed>> $cardsByUkid */
    public function loadSynergyByPair(array $cardsByUkid): array
    {
        $cardIds = [];
        foreach ($cardsByUkid as $card) {
            $ind = (int) ($card['ind'] ?? 0);
            if ($ind > 0) $cardIds[] = $ind;
        }
        $cardIds = array_values(array_unique($cardIds));
        if (count($cardIds) < 2) return [];

        $in = implode(',', $cardIds);

        try {
            $rows = $this->db->fetchAll(
                "SELECT deck_id, type, card_id
                 FROM card_synergy
                 WHERE card_id IN ($in)
                   AND type IN ('draft', 'sealed', 'standard')"
            );
        } catch (\Throwable) {
            return [];
        }

        return self::buildSynergyByPair($cardsByUkid, $rows);
    }

    /**
     * @param array<string,array<string,mixed>> $cardsByUkid
     * @param array<int,array<string,mixed>> $rows
     */
    public static function buildSynergyByPair(array $cardsByUkid, array $rows): array
    {
        $ukidByInd = [];
        foreach ($cardsByUkid as $ukid => $card) {
            $ind = (int) ($card['ind'] ?? 0);
            if ($ind > 0) {
                $ukidByInd[$ind] = (string) ($card['ukid'] ?? $ukid);
            }
        }

        $deckCards = [];
        foreach ($rows as $row) {
            $ukid = $ukidByInd[(int) ($row['card_id'] ?? 0)] ?? null;
            if ($ukid === null) continue;

            $deckKey = (string) $row['type'] . ':' . (string) $row['deck_id'];
            $deckCards[$deckKey]['type'] = (string) $row['type'];
            $deckCards[$deckKey]['cards'][$ukid] = true;
        }

        $pairs = [];
        foreach ($deckCards as $deck) {
            $cards = array_keys($deck['cards']);
            $count = count($cards);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $key = self::pairKey($cards[$i], $cards[$j]);
                    $type = $deck['type'];
                    $pairs[$key][$type] = ($pairs[$key][$type] ?? 0) + 1;
                }
            }
        }

        return $pairs;
    }

    private static function pairKey(string $a, string $b): string
    {
        return $a < $b ? $a . "\0" . $b : $b . "\0" . $a;
    }
}
