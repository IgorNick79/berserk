<?php
// src/Core/ProphecyProcessor.php

declare(strict_types=1);

namespace Berserk\Core;

final class ProphecyProcessor
{
    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {}

    /**
     * Снимает N верхних карт колоды игрока. Ничего не мутирует.
     *
     * @return array{cards: CardInstance[], ukids: string[], meta: array}|null
     */
    public function peek(string $ownerKey, int $count): ?array
    {
        $top = [];
        foreach ($this->state->cards as $c) {
            if ($c->owner !== $ownerKey) continue;
            if ($c->zone !== CardInstance::ZONE_DECK) continue;
            if (!empty($c->flags['in_prophecy_pending'])) continue;  // ← new
            $top[] = $c;
        }
        usort($top, function ($a, $b) {
            $ao = $a->order ?? PHP_INT_MAX;
            $bo = $b->order ?? PHP_INT_MAX;
            if ($ao !== $bo) return $ao <=> $bo;
            return $a->instanceId <=> $b->instanceId;
        });
        $shown = array_slice($top, 0, $count);
        if (empty($shown)) return null;

        $ukids       = [];
        $allElite    = true;
        $allOrdinary = true;
        foreach ($shown as $c) {
            $ukids[] = $c->ukid;
            if ($c->elite) $allOrdinary = false;
            else           $allElite    = false;
        }

        $firstPrice = (int) $shown[0]->price;

        return [
            'cards' => $shown,
            'ukids' => $ukids,
            'meta'  => [
                'count'        => count($shown),
                'all_elite'    => $allElite,
                'all_ordinary' => $allOrdinary,
                'first_price'  => $firstPrice,
                'is_odd'       => ($firstPrice % 2) === 1,
                'price'        => $firstPrice,   // обратная совместимость с grezy/block
            ],
        ];
    }

    /**
     * Кладёт карты вниз колоды и открывает pending_prophecy.
     * Сюда позже встроятся реакции Махинатора/Отряда/Ненасыти.
     */
    public function commit(
        string $ownerKey,
        CardInstance $source,
        array $peeked,
        string $context,
        string $title,
        array $actions
    ): void {
        $originalIds = [];
        foreach ($peeked['cards'] as $c) {
            $originalIds[] = $c->instanceId;
        }

        // Собираем карты с prophecy_summon — каждая даёт свою кнопку
        $summonCards = [];
        $hasNonSummon = false;
        foreach ($peeked['cards'] as $c) {
            if (!empty($c->prop['prophecy_summon'])) {
                $summonCards[] = $c;
            } else {
                $hasNonSummon = true;
            }
        }

        // Махинатор не действует на Отряд: если в peeked только Отряды — не показываем
        $machinator = $hasNonSummon ? $this->findAvailableMachinator($ownerKey) : null;
        $hasPending = ($machinator !== null) || !empty($summonCards);

        if ($hasPending) {
            foreach ($peeked['cards'] as $c) {
                $c->flags['in_prophecy_pending'] = true;
            }
        } else {
            foreach ($peeked['cards'] as $c) {
                unset($this->state->cards[$c->instanceId]);
                $c->instanceId = $this->state->nextInstanceId();
                $this->state->cards[$c->instanceId] = $c;
            }
        }

        // Формируем extra-actions в правильном порядке: Отряд, Махинатор
        $extra = [];
        foreach ($summonCards as $c) {
            $extra[] = [
                'label'  => 'Отряд: вызвать',
                'cmd'    => 'summon_start',
                'params' => ['card_id' => $c->instanceId],
            ];
        }
        if ($machinator !== null) {
            $extra[] = [
                'label' => 'Махинатор: карту наверх',
                'cmd'   => 'reorder_start',
            ];
        }

        // Вставляем extra перед close_prophecy
        if (!empty($extra)) {
            $inserted = [];
            foreach ($actions as $a) {
                if (($a['cmd'] ?? '') === 'close_prophecy') {
                    foreach ($extra as $ex) $inserted[] = $ex;
                }
                $inserted[] = $a;
            }
            $actions = $inserted;
        }

        $this->state->battle['pending_prophecy'] = [
            'owner'         => $ownerKey,
            'source_id'     => $source->instanceId,
            'source_ukid'   => $source->ukid,
            'shown_ukids'   => $peeked['ukids'],
            'title'         => $title,
            'actions'       => $actions,
            'context'       => $context,
            'meta'          => $peeked['meta'],
            'original_ids'  => $originalIds,
            'reorder_mode'  => false,
        ];

        $this->applyProphecyCharges($ownerKey, (int) ($peeked['meta']['count'] ?? 1));
    }

    /**
     * @return array{ok: bool, context?: string, error?: string}
     */
    public function close(string $playerKey): array
    {
        $pp = $this->state->battle['pending_prophecy'] ?? null;
        if (!$pp) return ['ok' => false, 'error' => 'Нет ожидающего выбора'];
        if ($pp['owner'] !== $playerKey) return ['ok' => false, 'error' => 'Не ваш выбор'];

        $context = $pp['context'] ?? 'simple';

        // Финализация карт: снимаем флаг, отправляем вниз тех, кто не наверху
        foreach ($pp['original_ids'] ?? [] as $id) {
            $card = $this->state->getCard($id);
            if (!$card) continue;
            if ($card->zone !== CardInstance::ZONE_DECK) continue;
            if (empty($card->flags['in_prophecy_pending'])) continue;

            unset($card->flags['in_prophecy_pending']);

            if ($card->order === null) {
                unset($this->state->cards[$id]);
                $card->instanceId = $this->state->nextInstanceId();
                $this->state->cards[$card->instanceId] = $card;
            }
            // Если order стоит — оставляем наверху
        }

        unset($this->state->battle['pending_prophecy']);

        return ['ok' => true, 'context' => $context];
    }

    public function transformSeeker(string $playerKey): Result
    {
        $pp = $this->state->battle['pending_prophecy'] ?? null;
        if (!$pp) return Result::error('Нет ожидающего выбора');
        if ($pp['owner'] !== $playerKey) return Result::error('Не ваш выбор');
        if (empty($pp['meta']['all_elite'])) return Result::error('Только для элитной карты');

        $seeker = $this->state->getCard($pp['source_id']);
        if (!$seeker || $seeker->owner !== $playerKey) {
            return Result::error('Карта не найдена');
        }

        $seeker->type = 'fly';
        $seeker->prop = [];
        $seeker->modifiers[] = [
            'stat'   => 'ability_strike',
            'value'  => 1,
            'expire' => 'permanent',
        ];
        (new ZoneManager($this->state))->toFlying($seeker);

        unset($this->state->battle['pending_prophecy']);
        return Result::ok(['seeker_transformed']);
    }

    private function applyProphecyCharges(string $ownerKey, int $shownCount): void
    {
        if ($shownCount <= 0) return;

        foreach ($this->state->cards as $card) {
            if ($card->owner !== $ownerKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->hp <= 0) continue;

            $config = $card->prop['prophecy_charge'] ?? null;
            if (!$config) continue;

            $cap = (int) ($config['cap'] ?? 3);

            $idx = null;
            foreach ($card->modifiers as $i => $m) {
                if (($m['source'] ?? '') === 'prophecy_charge'
                    && ($m['stat'] ?? '') === 'ability_strike') {
                    $idx = $i;
                    break;
                }
            }

            $current = ($idx !== null)
                ? (int) $card->modifiers[$idx]['value']
                : 0;
            $new = min($cap, $current + $shownCount);

            if ($idx !== null) {
                $card->modifiers[$idx]['value'] = $new;
            } else {
                $card->modifiers[] = [
                    'stat'   => 'ability_strike',
                    'value'  => $new,
                    'expire' => 'permanent',
                    'source' => 'prophecy_charge',
                ];
            }
        }
    }

    public function startReorder(string $playerKey): Result
    {
        $pp = $this->state->battle['pending_prophecy'] ?? null;
        if (!$pp) return Result::error('Нет ожидающего выбора');
        if ($pp['owner'] !== $playerKey) return Result::error('Не ваш выбор');
        if (!empty($pp['reorder_mode'])) return Result::error('Уже в режиме перестановки');

        $machinator = $this->findAvailableMachinator($playerKey);
        if ($machinator === null) return Result::error('Махинатор недоступен');
        $machinator->flags['prophecy_reorder_used_this_turn'] = true;

        $cards = [];
        foreach ($pp['original_ids'] as $id) {
            $card = $this->state->getCard($id);
            if (!$card) continue;
            if (!empty($card->prop['prophecy_summon'])) continue;  // Отряд не двигаем
            $cards[] = $card;
        }
        if (empty($cards)) return Result::error('Нет карт для перестановки');

        if (count($cards) === 1) {
            $this->putOnTop($cards[0]);

            // Убираем кнопку Махинатора, остальное оставляем
            $actions = [];
            foreach ($pp['actions'] as $a) {
                if (($a['cmd'] ?? '') === 'reorder_start') continue;
                $actions[] = $a;
            }

            $this->state->battle['pending_prophecy'] = [
                'owner'        => $pp['owner'],
                'source_id'    => $pp['source_id'],
                'source_ukid'  => $pp['source_ukid'],
                'shown_ukids'  => $pp['shown_ukids'],
                'title'        => 'Карта отправлена наверх колоды',
                'actions'      => $actions,
                'context'      => $pp['context'],
                'meta'         => $pp['meta'],
                'original_ids' => $pp['original_ids'],
                'reorder_mode' => false,
            ];

            return Result::ok(['reorder_single']);
        }

        $this->state->battle['pending_prophecy']['reorder_mode'] = true;
        $this->state->battle['pending_prophecy']['reorder_remaining']
            = array_map(fn($c) => $c->instanceId, $cards);
        return Result::ok(['reorder_mode']);
    }

    public function reorderCard(string $playerKey, int $cardId, string $direction): Result
    {
        $pp = $this->state->battle['pending_prophecy'] ?? null;
        if (!$pp) return Result::error('Нет ожидающего выбора');
        if ($pp['owner'] !== $playerKey) return Result::error('Не ваш выбор');
        if (empty($pp['reorder_mode'])) return Result::error('Не режим перестановки');

        $remaining = $pp['reorder_remaining'] ?? [];
        if (!in_array($cardId, $remaining, true)) {
            return Result::error('Карта уже обработана');
        }

        $card = $this->state->getCard($cardId);
        if (!$card) return Result::error('Карта не найдена');

        if ($direction === 'top') {
            $this->putOnTop($card);
        } else {
            // Вниз — снимаем order, карта уйдёт вниз при close()
            $card->order = null;
        }

        $remaining = array_values(array_filter(
            $remaining,
            fn($id) => $id !== $cardId
        ));

        $this->state->battle['pending_prophecy']['reorder_remaining'] = $remaining;

        if (empty($remaining)) {
            $pp = $this->state->battle['pending_prophecy'];

            $actions = [];
            foreach ($pp['actions'] as $a) {
                if (($a['cmd'] ?? '') === 'reorder_start') continue;
                $actions[] = $a;
            }

            $this->state->battle['pending_prophecy'] = [
                'owner'        => $pp['owner'],
                'source_id'    => $pp['source_id'],
                'source_ukid'  => $pp['source_ukid'],
                'shown_ukids'  => $pp['shown_ukids'],
                'title'        => 'Карты разложены',
                'actions'      => $actions,
                'context'      => $pp['context'],
                'meta'         => $pp['meta'],
                'original_ids' => $pp['original_ids'],
                'reorder_mode' => false,
            ];
        }

        return Result::ok(['reorder_card:' . $direction]);
    }

    private function findAvailableMachinator(string $ownerKey): ?CardInstance
    {
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $ownerKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->hp <= 0) continue;
            if (empty($card->prop['prophecy_reorder'])) continue;
            if (!empty($card->flags['prophecy_reorder_used_this_turn'])) continue;
            return $card;
        }
        return null;
    }

    private function putOnTop(CardInstance $card): void
    {
        // Наименьший order среди всех карт колоды владельца - 1
        $minOrder = null;
        foreach ($this->state->cards as $c) {
            if ($c->owner !== $card->owner) continue;
            if ($c->zone !== CardInstance::ZONE_DECK) continue;
            if ($c->instanceId === $card->instanceId) continue;
            $o = $c->order ?? PHP_INT_MAX;
            if ($minOrder === null || $o < $minOrder) $minOrder = $o;
        }
        $card->order = ($minOrder === null || $minOrder === PHP_INT_MAX)
            ? 0
            : $minOrder - 1;
    }

    public function startSummon(string $playerKey, int $cardId): Result
    {
        $pp = $this->state->battle['pending_prophecy'] ?? null;
        if (!$pp) return Result::error('Нет ожидающего выбора');
        if ($pp['owner'] !== $playerKey) return Result::error('Не ваш выбор');
        if (!empty($pp['reorder_mode']) || !empty($pp['summon_mode'])) {
            return Result::error('Уже в другом режиме');
        }

        $card = $this->state->getCard($cardId);
        if (!$card) return Result::error('Карта не найдена');
        if ($card->zone !== CardInstance::ZONE_DECK) return Result::error('Карта не в колоде');
        if (empty($card->flags['in_prophecy_pending'])) {
            return Result::error('Карта не в пророчестве');
        }
        if (empty($card->prop['prophecy_summon'])) {
            return Result::error('Не карта вызова');
        }

        // Собираем свободные клетки с хотя бы одним живым соседом
        $cells = [];
        for ($r = 1; $r <= 6; $r++) {
            for ($c = 1; $c <= 5; $c++) {
                if ($this->isFieldOccupied($r, $c)) continue;
                if (ZoneManager::hasBlockingMarker($state, "{$r}_{$c}")) continue;
                if (!$this->hasNeighborCreature($r, $c)) continue;
                $cells[] = "{$r}_{$c}";
            }
        }

        if (empty($cells)) {
            return Result::error('Нет подходящих клеток');
        }

        $this->state->battle['pending_prophecy']['summon_mode']      = true;
        $this->state->battle['pending_prophecy']['summon_step']      = 'cell';
        $this->state->battle['pending_prophecy']['summon_card_id']   = $cardId;
        $this->state->battle['pending_prophecy']['summon_cells']     = $cells;

        return Result::ok(['summon_cells']);
    }

    public function summonChooseCell(string $playerKey, int $row, int $col): Result
    {
        $pp = $this->state->battle['pending_prophecy'] ?? null;
        if (!$pp) return Result::error('Нет ожидающего выбора');
        if ($pp['owner'] !== $playerKey) return Result::error('Не ваш выбор');
        if (empty($pp['summon_mode']) || ($pp['summon_step'] ?? '') !== 'cell') {
            return Result::error('Не тот шаг');
        }

        $key = "{$row}_{$col}";
        if (!in_array($key, $pp['summon_cells'] ?? [], true)) {
            return Result::error('Неверная клетка');
        }

        // Соседи
        $creatures = [];
        for ($dr = -1; $dr <= 1; $dr++) {
            for ($dc = -1; $dc <= 1; $dc++) {
                if ($dr === 0 && $dc === 0) continue;
                $r = $row + $dr;
                $c = $col + $dc;
                if ($r < 1 || $r > 6 || $c < 1 || $c > 5) continue;

                foreach ($this->state->cards as $card) {
                    if ($card->zone !== CardInstance::ZONE_FIELD
                        && $card->zone !== CardInstance::ZONE_FLYING) continue;
                    if ($card->row !== $r || $card->col !== $c) continue;
                    if ($card->dying || $card->hp <= 0) continue;
                    $creatures[] = $card->instanceId;
                    break;
                }
            }
        }

        $this->state->battle['pending_prophecy']['summon_chosen_cell'] = [$row, $col];

        // Если соседей нет — сразу финализируем выход без яда
        if (empty($creatures)) {
            return $this->finishSummon($playerKey, null);
        }

        $this->state->battle['pending_prophecy']['summon_step']      = 'creature';
        $this->state->battle['pending_prophecy']['summon_creatures'] = $creatures;

        return Result::ok(['summon_creatures']);
    }

    public function summonChooseCreature(string $playerKey, int $targetId): Result
    {
        $pp = $this->state->battle['pending_prophecy'] ?? null;
        if (!$pp) return Result::error('Нет ожидающего выбора');
        if ($pp['owner'] !== $playerKey) return Result::error('Не ваш выбор');
        if (empty($pp['summon_mode']) || ($pp['summon_step'] ?? '') !== 'creature') {
            return Result::error('Не тот шаг');
        }

        if (!in_array($targetId, $pp['summon_creatures'] ?? [], true)) {
            return Result::error('Неверная цель');
        }

        return $this->finishSummon($playerKey, $targetId);
    }

    public function summonCancel(string $playerKey): Result
    {
        $pp = $this->state->battle['pending_prophecy'] ?? null;
        if (!$pp) return Result::error('Нет ожидающего выбора');
        if ($pp['owner'] !== $playerKey) return Result::error('Не ваш выбор');
        if (empty($pp['summon_mode'])) return Result::error('Не в режиме вызова');

        $this->state->battle['pending_prophecy'] = [
            'owner'        => $pp['owner'],
            'source_id'    => $pp['source_id'],
            'source_ukid'  => $pp['source_ukid'],
            'shown_ukids'  => $pp['shown_ukids'],
            'title'        => $pp['title'],
            'actions'      => $pp['actions'],
            'context'      => $pp['context'],
            'meta'         => $pp['meta'],
            'original_ids' => $pp['original_ids'],
            'reorder_mode' => false,
        ];

        return Result::ok(['summon_cancelled']);
    }

    private function finishSummon(string $playerKey, ?int $poisonTargetId): Result
    {
        $pp = $this->state->battle['pending_prophecy'] ?? null;
        if (!$pp) return Result::error('Нет ожидающего выбора');

        $cardId = (int) $pp['summon_card_id'];
        [$row, $col] = $pp['summon_chosen_cell'];

        $card = $this->state->getCard($cardId);
        if (!$card) return Result::error('Карта не найдена');
        if ($card->zone !== CardInstance::ZONE_DECK) {
            return Result::error('Карта уже не в колоде');
        }

        // Яд
        if ($poisonTargetId !== null) {
            $target = $this->state->getCard($poisonTargetId);
            if ($target) {
                $this->engine->applyPoison($target, 1, $playerKey);
            }
        }

        // Выход на поле
        unset($card->flags['in_prophecy_pending']);
        $card->closed   = true;
        $card->revealed = true;
        $card->dying    = false;
        $card->hp       = $card->hpMax;
        $card->move     = $card->moveMax;

        (new ZoneManager($this->state))->toField($card, $row, $col);

        // Пересобираем pending_prophecy с нуля
        $ids = array_values(array_filter(
            $pp['original_ids'] ?? [],
            fn($id) => (int) $id !== $cardId
        ));

        $actions = [];
        foreach ($pp['actions'] as $a) {
            // Убираем конкретно эту кнопку Отряда
            if (($a['cmd'] ?? '') === 'summon_start'
                && (int) ($a['params']['card_id'] ?? 0) === $cardId) {
                continue;
            }
            $actions[] = $a;
        }

        $this->state->battle['pending_prophecy'] = [
            'owner'        => $pp['owner'],
            'source_id'    => $pp['source_id'],
            'source_ukid'  => $pp['source_ukid'],
            'shown_ukids'  => $pp['shown_ukids'],
            'title'        => $pp['title'],
            'actions'      => $actions,
            'context'      => $pp['context'],
            'meta'         => $pp['meta'],
            'original_ids' => $ids,
            'reorder_mode' => false,
        ];

        return Result::ok(['summoned:' . $card->instanceId]);
    }

    private function isFieldOccupied(int $row, int $col): bool
    {
        foreach ($this->state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->row === $row && $c->col === $col) return true;
        }
        return false;
    }

    private function hasNeighborCreature(int $row, int $col): bool
    {
        for ($dr = -1; $dr <= 1; $dr++) {
            for ($dc = -1; $dc <= 1; $dc++) {
                if ($dr === 0 && $dc === 0) continue;
                $r = $row + $dr;
                $c = $col + $dc;
                if ($r < 1 || $r > 6 || $c < 1 || $c > 5) continue;

                foreach ($this->state->cards as $card) {
                    if ($card->zone !== CardInstance::ZONE_FIELD
                        && $card->zone !== CardInstance::ZONE_FLYING) continue;
                    if ($card->row !== $r || $card->col !== $c) continue;
                    if ($card->dying || $card->hp <= 0) continue;
                    return true;
                }
            }
        }
        return false;
    }
}