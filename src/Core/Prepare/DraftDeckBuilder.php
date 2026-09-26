<?php
// src/Core/Prepare/DraftDeckBuilder.php

declare(strict_types=1);

namespace Berserk\Core\Prepare;

use Berserk\Core\Db;

final class DraftDeckBuilder
{
    public function __construct(private Db $db) {}

    public function buildDeckCards(array $ukids): array
    {
        if (empty($ukids)) return [];

        $counts = [];
        foreach ($ukids as $ukid) {
            $counts[$ukid] = ($counts[$ukid] ?? 0) + 1;
        }

        $unique = array_keys($counts);
        $in = "'" . implode("','", array_map(fn($u) => $this->db->escape((string) $u), $unique)) . "'";

        $elements = [];
        foreach ($this->db->fetchAll("SELECT ind, code FROM elements") as $e) {
            $elements[(int) $e['ind']] = $e['code'];
        }

        $rows = $this->db->fetchAll(
            "SELECT ukid, price, health, move, elite, type, class,
                    strike_weak, strike_medium, strike_strong, element_id, prop
             FROM cards WHERE ukid IN ($in)"
        );
        $byUkid = [];
        foreach ($rows as $r) $byUkid[$r['ukid']] = $r;

        $result = [];
        foreach ($counts as $ukid => $count) {
            $r = $byUkid[$ukid] ?? null;
            if (!$r) continue;

            $result[] = [
                'ukid'          => $ukid,
                'count'         => $count,
                'price'         => (int) $r['price'],
                'elite'         => (bool) $r['elite'],
                'element'       => $elements[(int) $r['element_id']] ?? 'neutral',
                'health'        => (int) $r['health'],
                'move'          => (int) $r['move'],
                'strike_weak'   => (int) $r['strike_weak'],
                'strike_medium' => (int) $r['strike_medium'],
                'strike_strong' => (int) $r['strike_strong'],
                'prop'          => $r['prop'] ? json_decode($r['prop'], true) : [],
                'type'          => $r['type'] ?? 'creature',
                'class'         => $r['class'] ?? '',
            ];
        }
        return $result;
    }
}
