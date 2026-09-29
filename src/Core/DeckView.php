<?php
// src/Core/DeckView.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Данные деки из справочника — для стадий deck и view.
 * Ничего секретного, состав деки публичен.
 */
final class DeckView
{
    public function __construct(private Db $db) {}

    /**
     * Список доступных стартовых дек (для стадии deck).
     *
     * @return array<int, array{ind:int, name:string, image:string}>
     */
    public function listStarters(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT ind, name, image FROM decks
             WHERE type = 'official' AND status = 'active'
             ORDER BY ind"
        );

        return array_map(fn ($r) => [
            'ind'   => (int) $r['ind'],
            'name'  => (string) $r['name'],
            'image' => (string) ($r['image'] ?? ''),
        ], $rows);
    }

    /**
     * Состав деки (для стадии view).
     * JOIN decks.cards (JSON) с cards по ukid.
     *
     * @return array
     */
    public function forDeck(int $deckId): array
    {
        $row = $this->db->fetchOne(
            "SELECT ind, name, cards FROM decks WHERE ind = " . (int) $deckId
        );

        if (!$row) {
            throw new \RuntimeException("Deck $deckId not found");
        }

        $deckCards = json_decode($row['cards'], true) ?: [];

        if (empty($deckCards)) {
            return [
                'deck_id'  => (int) $row['ind'],
                'name'     => (string) $row['name'],
                'cards'    => [],
                'total'    => 0,
                'elements' => [],
                'elite'    => 0,
                'ordinary' => 0,
            ];
        }

        // Собираем ukid для одного SELECT
        $ukids = array_map(fn ($c) => $c['ukid'], $deckCards);
        $in    = "'" . implode("','", array_map(
            fn ($u) => $this->db->escape($u),
            $ukids
        )) . "'";

        $cardRows = $this->db->fetchAll(
            "SELECT t1.ukid, t1.name, t1.element_id, t1.elite, t1.single, t1.price,
                    t1.health, t1.move, t1.strike_weak, t1.strike_medium, t1.strike_strong,
                    t1.prop, t1.type, t1.class,
                    t2.code AS element_code, t2.name AS element_name
             FROM cards t1
             LEFT JOIN elements t2 ON t2.ind = t1.element_id
             WHERE t1.ukid IN ($in)"
        );

        // Индексируем по ukid
        $cardsByUkid = [];
        foreach ($cardRows as $c) {
            $cardsByUkid[$c['ukid']] = $c;
        }

        $result   = [];
        $total    = 0;
        $elements = [];
        $elite    = 0;
        $ordinary = 0;

        foreach ($deckCards as $item) {
            $ukid  = $item['ukid'];
            $count = (int) ($item['count'] ?? 1);

            if (!isset($cardsByUkid[$ukid])) {
                continue; // карта удалена из справочника
            }

            $card = $cardsByUkid[$ukid];
            $total += $count;

            if ($card['elite']) {
                $elite += $count;
            } else {
                $ordinary += $count;
            }

            $el = $card['element_code'] ?: 'neutral';
            $elements[$el] = ($elements[$el] ?? 0) + $count;

            $result[] = [
                'ukid'         => $ukid,
                'name'         => $card['name'],
                'element'      => $el,
                'class'        => (string) ($card['class'] ?? ''),
                'element_name' => $card['element_name'] ?: 'Нейтральная',
                'elite'        => (bool) $card['elite'],
                'single'       => (bool) $card['single'],
                'price'        => (int) $card['price'],
                'health'       => (int) $card['health'],
                'move'         => (int) $card['move'],
                'strike'       => [
                    'weak'   => (int) $card['strike_weak'],
                    'medium' => (int) $card['strike_medium'],
                    'strong' => (int) $card['strike_strong'],
                ],
                'prop'         => $card['prop'] ? json_decode($card['prop'], true) : [],
                'type'         => (string) ($card['type'] ?? 'creature'),
                'count'        => $count,
            ];
        }

        return [
            'deck_id'  => (int) $row['ind'],
            'name'     => (string) $row['name'],
            'cards'    => $result,
            'total'    => $total,
            'elements' => $elements,
            'elite'    => $elite,
            'ordinary' => $ordinary,
        ];
    }
}
