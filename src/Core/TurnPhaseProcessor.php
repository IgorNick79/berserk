<?php
// src/Core/TurnPhaseProcessor.php

declare(strict_types=1);

namespace Berserk\Core;

final class TurnPhaseProcessor
{
    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {}

    // ─── Точки входа ─────────────────────────────────────────

    public function beginStartPhase(string $activeKey): void
    {
        $passiveKey = $this->state->getOpponentKey($activeKey);

        $this->state->battle['turn_phase'] = [
            'phase'         => 'start',
            'active_key'    => $activeKey,
            'passive_key'   => $passiveKey,
            'side'          => 'passive',
            'passive_queue' => $this->buildPassiveQueue($activeKey, $passiveKey),
            'active_queue'  => $this->buildActiveQueue($activeKey),
            'sub'           => null,
            'pending_ack'   => null,
        ];

        $this->advance();
    }

    public function beginEndPhase(string $endingKey): void
    {
        $oppKey = $this->state->getOpponentKey($endingKey);

        // active = тот, кто завершает ход; passive = оппонент
        $passiveQueue = $this->buildPassiveQueueEnd($endingKey, $oppKey);
        $activeQueue  = $this->buildActiveQueueEnd($endingKey);

        $this->state->battle['turn_phase'] = [
            'phase'         => 'end',
            'active_key'    => $endingKey,
            'passive_key'   => $oppKey,
            'side'          => 'passive',
            'passive_queue' => $passiveQueue,
            'active_queue'  => $activeQueue,
            'sub'           => null,
            'pending_ack'   => null,
        ];

        $this->advance();
    }

    public function ackPending(string $playerKey): Result
    {
        $tp = $this->state->battle['turn_phase'] ?? null;
        if (!$tp || empty($tp['pending_ack'])) {
            return Result::error('Нет ожидающего подтверждения');
        }

        unset($this->state->battle['turn_phase']['pending_ack']);
        $this->advance();
        $this->state->bumpVersion();
        return Result::ok(['ack']);
    }

    public function runTask(string $playerKey, Command $cmd): Result
    {
        $tp = $this->state->battle['turn_phase'] ?? null;
        if (!$tp) return Result::error('Нет активной фазы');

        $side = $tp['side'];
        $ownerKey = $side === 'passive' ? $tp['passive_key'] : $tp['active_key'];
        if ($playerKey !== $ownerKey) return Result::error('Не ваш выбор');

        $taskId = (string) $cmd->get('task_id', '');
        if ($taskId === '') return Result::error('Не указана задача');

        $queue = $tp[$side . '_queue'];
        $idx = null;
        foreach ($queue as $i => $t) {
            if ($t['id'] === $taskId) { $idx = $i; break; }
        }
        if ($idx === null) return Result::error('Задача не найдена');

        $task = $queue[$idx];
        array_splice($this->state->battle['turn_phase'][$side . '_queue'], $idx, 1);

        // Подочередь (пророчество, вальхалла)
        if (!empty($task['sub'])) {
            $this->state->battle['turn_phase']['sub'] = [
                'parent_type' => $task['type'],
                'remaining'   => $task['sub'],
                'can_close'   => !empty($task['can_close']),
            ];
            $this->state->bumpVersion();
            return Result::ok(['sub_started']);
        }

        // Обычная задача
        $desc = $this->executeTask($task, $tp['active_key'], $tp['passive_key']);

        if ($this->hasPendingFromTask($task['type'])) {
            $this->state->battle['turn_phase']['pending_ack'] = null;
            $this->state->bumpVersion();
            return Result::ok(['task_pending']);
        }

        $this->state->battle['turn_phase']['pending_ack'] = $desc;
        $this->state->bumpVersion();
        return Result::ok(['task_done']);
    }

    public function runSub(string $playerKey, Command $cmd): Result
    {
        $tp = $this->state->battle['turn_phase'] ?? null;
        if (!$tp || empty($tp['sub'])) return Result::error('Нет подочереди');

        $side = $tp['side'];
        $ownerKey = $side === 'passive' ? $tp['passive_key'] : $tp['active_key'];
        if ($playerKey !== $ownerKey) return Result::error('Не ваш выбор');

        $subId = (string) $cmd->get('sub_id', '');
        $sub = &$this->state->battle['turn_phase']['sub'];

        $subTask = null;
        foreach ($sub['remaining'] as $s) {
            if (($s['id'] ?? '') === $subId) {
                $subTask = $s;
                break;
            }
        }
        if (!$subTask) {
            $this->state->bumpVersion();
            return Result::error('Подзадача устарела');
        }

        $parentType = $sub['parent_type'];

        // Для prophecy и valhalla НЕ удаляем сразу — удалим после успеха
        if ($parentType !== 'prophecy' && $parentType !== 'valhalla' && $parentType !== 'instants') {
            foreach ($sub['remaining'] as $i => $s) {
                if (($s['id'] ?? '') === $subId) {
                    array_splice($sub['remaining'], $i, 1);
                    break;
                }
            }
        }

        // Выполняем подзадачу (пророчество / вальхалла)
        $this->executeSub($subTask, $sub['parent_type'], $tp['active_key']);

        // Проверяем, создался ли pending
        $hasPending = false;
        if ($parentType === 'prophecy' && !empty($this->state->battle['pending_prophecy']))       $hasPending = true;
        if ($parentType === 'valhalla' && !empty($this->state->battle['pending_valhalla_pick']))  $hasPending = true;
        if ($parentType === 'instants' && !empty($this->state->battle['pending_instant_pick']))   $hasPending = true;

        if ($hasPending) {
            $this->state->battle['turn_phase']['sub']['pending_id'] = $subId;
            $this->state->bumpVersion();
            return Result::ok(['sub_pending']);
        }

        // Pending не создан — удаляем подзадачу сразу
        foreach ($sub['remaining'] as $i => $s) {
            if (($s['id'] ?? '') === $subId) {
                array_splice($sub['remaining'], $i, 1);
                break;
            }
        }

        if (empty($sub['remaining'])) {
            unset($this->state->battle['turn_phase']['sub']);
            $this->advance();
        }

        $this->state->bumpVersion();
        return Result::ok(['sub_done']);
    }

    public function closeSub(string $playerKey, Command $cmd): Result
    {
        $tp = $this->state->battle['turn_phase'] ?? null;
        if (!$tp || empty($tp['sub'])) return Result::error('Нет подочереди');

        $side     = $tp['side'];
        $ownerKey = $side === 'passive' ? $tp['passive_key'] : $tp['active_key'];
        if ($playerKey !== $ownerKey) return Result::error('Не ваш выбор');

        unset($this->state->battle['turn_phase']['sub']);
        $this->advance();

        $this->state->bumpVersion();
        return Result::ok(['sub_closed']);
    }

    public function resume(): void
    {
        $tp = $this->state->battle['turn_phase'] ?? null;
        if (!$tp) return;

        // Если была подочередь — проверим
        if (!empty($tp['sub'])) {
            $sub = &$this->state->battle['turn_phase']['sub'];
            if (empty($sub['remaining'])) {
                unset($this->state->battle['turn_phase']['sub']);
            }
        }

        unset($this->state->battle['turn_phase']['pending_ack']);
        $this->advance();
    }

    // ─── Движок ──────────────────────────────────────────────

    private function advance(): void
    {
        $tp = &$this->state->battle['turn_phase'];
        if (!$tp) return;

        // Если sub активен — не двигаем основную очередь
        if (!empty($tp['sub'])) {
            $tp['pending_ack'] = null;
            return;
        }

        while (true) {
            $side  = $tp['side'];
            $queue = &$tp[$side . '_queue'];

            if (!empty($queue)) {
                // 1 задача → auto-run
                if (count($queue) === 1) {
                    $task = array_shift($queue);

                    if (!empty($task['sub'])) {
                        $tp['sub'] = [
                            'parent_type' => $task['type'],
                            'remaining'   => $task['sub'],
                            'can_close'   => !empty($task['can_close']),
                        ];
                        $tp['pending_ack'] = null;
                        return;
                    }

                    $desc = $this->executeTask($task, $tp['active_key'], $tp['passive_key']);

                    if ($this->hasPendingFromTask($task['type'])) {
                        $tp['pending_ack'] = null;
                        return;
                    }

                    $tp['pending_ack'] = $desc;
                    return;
                }

                // 2+ → ждём клика, карточки рендерит UI
                $tp['pending_ack'] = null;
                return;
            }

            // Очередь пуста → следующая сторона
            if ($side === 'passive') {
                $tp['side'] = 'active';
                continue;
            }

            $this->finish();
            return;
        }
    }

    private function finish(): void
    {
        $phase     = $this->state->battle['turn_phase']['phase'] ?? 'start';
        $activeKey = $this->state->battle['turn_phase']['active_key'] ?? null;
        $passiveKey = $this->state->battle['turn_phase']['passive_key'] ?? null;
        unset($this->state->battle['turn_phase']);

        if ($activeKey === null) return;

        if ($phase === 'end') {
            // Фаза конца хода завершена — технический слой + передача хода
            (new TurnProcessor($this->state, $this->engine))
                ->afterEndPhase($activeKey, $passiveKey);
            return;
        }

        (new TurnProcessor($this->state, $this->engine))->afterStartPhase($activeKey);
    }

    // ─── Сборка очередей ─────────────────────────────────────

    private function buildPassiveQueue(string $activeKey, string $passiveKey): array
    {
        $queue = [];

        // Яд (bulk)
        if ($this->wouldPoison($activeKey)) {
            $queue[] = ['id' => 'poison', 'type' => 'poison', 'label' => 'Яд'];
        }

        // opponent_turn_start — по пропу
        $otsCards = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $passiveKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;
            if (empty($card->prop['opponent_turn_start'])) continue;
            $otsCards[] = $card;
        }
        $queue = array_merge($queue, $this->buildTurnStartTasks($otsCards, 'opponent_turn_start'));

        // Turn-инстанты — одна задача с подочередью
        $instants = $this->buildInstantSubTasks($passiveKey);

        if (!empty($instants)) {
            array_unshift($queue, [
                'id'        => 'instants',
                'type'      => 'instants',
                'label'     => 'Инстанты',
                'sub'       => $instants,
                'can_close' => true,
            ]);
        }

        return $this->sortByCoordinates($queue, $passiveKey);
    }

    private function buildActiveQueue(string $activeKey): array
    {
        $queue = [];

        // Turn-инстанты — в самом конце очереди
        $instants = $this->buildInstantSubTasks($activeKey);

        if (!empty($instants)) {
            $queue[] = [
                'id'        => 'instants',
                'type'      => 'instants',
                'label'     => 'Инстанты',
                'sub'       => $instants,
                'can_close' => true,
            ];
        }

        // 1. Инкарнация
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_GRAVEYARD) continue;
            if (empty($card->markers['incarnation'])) continue;
            $queue[] = ['id' => 'incarnation', 'type' => 'incarnation', 'label' => 'Инкарнация'];
            break;
        }

        // 2. Вальхалла (подочередь)
        $valhallaCards = (new ValhallaProcessor($this->state, $this->engine))
            ->collectActive($activeKey);

        if (!empty($valhallaCards)) {
            $sub = [];
            foreach ($valhallaCards as $c) {
                $sub[] = [
                    'id'      => 'valhalla_' . $c->instanceId,
                    'card_id' => $c->instanceId,
                    'ukid'    => $c->ukid,
                ];
            }
            $queue[] = [
                'id'          => 'valhalla',
                'type'        => 'valhalla',
                'label'       => 'Вальхалла',
                'sub'         => $sub,
                'can_close'   => true,
            ];
        }

        // 3. Пророчество
        $prophets = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if (empty($card->prop['prophecy'])) continue;
            if (!empty($card->flags['prophecy_done_this_turn'])) continue;
            $prophets[] = $card;
        }
        if (!empty($prophets)) {
            $sub = [];
            foreach ($prophets as $c) {
                $sub[] = [
                    'id'      => 'prophecy_' . $c->instanceId,
                    'card_id' => $c->instanceId,
                    'ukid'    => $c->ukid,
                    'row'     => $c->row,
                    'col'     => $c->col,
                ];
            }
            $queue[] = [
                'id'    => 'prophecy',
                'type'  => 'prophecy',
                'label' => 'Пророчество',
                'sub'   => $sub,
            ];
        }

        // 4. Регенерация (bulk)
        if ($this->wouldRegenerate($activeKey)) {
            $queue[] = ['id' => 'regen', 'type' => 'regen', 'label' => 'Регенерация'];
        }

        // 5. Монеты (bulk)
        if ($this->wouldGainCoins($activeKey)) {
            $queue[] = ['id' => 'coins', 'type' => 'get_coins', 'label' => 'Монеты'];
        }

        // 6. turn_start-абилки (кроме get_coins)
        $tsCards = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;
            if (empty($card->prop['turn_start'])) continue;

            $hasNonCoin = false;
            foreach ($card->prop['turn_start'] as $eff) {
                if (($eff['type'] ?? '') !== 'get_coins') { $hasNonCoin = true; break; }
            }
            if ($hasNonCoin) $tsCards[] = $card;
        }
        $queue = array_merge($queue, $this->buildTurnStartTasks($tsCards, 'turn_start'));

        return $queue;
    }

    private function buildInstantSubTasks(string $ownerKey): array
    {
        $instants = [];
        foreach ((new InstantProcessor($this->state, $this->engine))->getInstants($ownerKey, 'before', 'turn') as $inst) {
            $payload = $inst['payload'] ?? [];
            if (!empty($payload['aftermath'])) continue;

            $instants[] = [
                'id'      => 'instant_' . $inst['card_id'] . '_' . ($payload['key'] ?? ''),
                'card_id' => $inst['card_id'],
                'ukid'    => $inst['ukid'],
                'row'     => $inst['row'],
                'col'     => $inst['col'],
                'label'   => $inst['label'],
                'payload' => $payload,
            ];
        }

        return $instants;
    }

    /**
     * Собирает tasks из карт с turn_start эффектами (кроме get_coins).
     * Стек → одна запись, не-стек → по записи на карту.
     */
    private function buildTurnStartTasks(array $cards, string $propKey): array
    {
        $tasks = [];

        foreach ($cards as $card) {
            $effects = $card->prop[$propKey] ?? [];
            if (!is_array($effects)) continue;

            foreach ($effects as $eff) {
                if (($eff['type'] ?? '') === 'get_coins') continue;

                $stacked = !empty($eff['stacked']);
                $label   = (string) ($eff['label'] ?? $card->ukid);

                $tasks[] = [
                    'id'      => $propKey . '_' . $card->instanceId . '_' . count($tasks),
                    'type'    => $propKey . ':' . ($eff['type'] ?? ''),
                    'label'   => $label,
                    'card_id' => $card->instanceId,
                    'ukid'    => $card->ukid,
                    'row'     => $card->row,
                    'col'     => $card->col,
                    'stacked' => $stacked,
                    'payload' => $eff,
                ];
            }
        }

        return $tasks;
    }

    private function sortByCoordinates(array $tasks, string $owner): array
    {
        usort($tasks, function ($a, $b) use ($owner) {
            $ar = $a['row'] ?? 99; $ac = $a['col'] ?? 99;
            $br = $b['row'] ?? 99; $bc = $b['col'] ?? 99;

            if ($owner === 'host') {
                // ряд 3 → 2 → 1, столбцы 1→5
                $ar = 100 - $ar; $br = 100 - $br;
            } else {
                // ряд 4 → 5 → 6, столбцы 5→1
                $ac = 100 - $ac; $bc = 100 - $bc;
            }

            return [$ar, $ac] <=> [$br, $bc];
        });
        return $tasks;
    }

    // ─── Исполнение ──────────────────────────────────────────

    private function executeTask(array $task, string $activeKey, string $passiveKey)
    {
        [$base, $sub] = explode(':', $task['type'] . ':') + ['', ''];

        return match ($base) {
            'poison'              => $this->executePoison($activeKey),
            'regen'               => $this->executeRegen($activeKey),
            'get_coins'           => $this->executeGetCoins($activeKey),
            'incarnation'         => $this->executeIncarnation($activeKey),
            'turn_start'          => $this->executeTurnStartEffect($task, $activeKey),
            'opponent_turn_start' => $this->executeTurnStartEffect($task, $passiveKey),
            'turn_end'            => $this->executeTurnEndEffect($task, $activeKey),
            'opponent_turn_end'   => $this->executeTurnEndEffect($task, $passiveKey),
            'prophecy'            => ['label' => 'Пророчество', 'items' => []],
            'valhalla'            => ['label' => 'Вальхалла', 'items' => []],
            default               => ['label' => $task['label'] ?? '', 'items' => []],
        };
    }

    private function executeSub(array $subTask, string $parentType, string $activeKey): void
    {
        // Пророчество — вызывает существующий triggerProphecy
        // (он возьмёт топдек первого искателя и поставит pending_prophecy)
        // Вальхалла — аналогично (когда будет)
        if ($parentType === 'prophecy') {
            $card = $this->state->getCard($subTask['card_id']);
            if (!$card) return;

            (new TurnProcessor($this->state, $this->engine))
                ->triggerProphecyForCard($card, $activeKey);
        } elseif ($parentType === 'valhalla') {
            $card = $this->state->getCard($subTask['card_id']);
            if (!$card) return;

            $vp = new ValhallaProcessor($this->state, $this->engine);
            $vp->beginExecute($card, $activeKey);

            // Если pending_valhalla_pick открылся — ждём выбора
            if (!empty($this->state->battle['pending_valhalla_pick'])) {
                // не удаляем подзадачу — вернёмся к ней после выбора
            }
        } elseif ($parentType === 'instants') {
            $card = $this->state->getCard($subTask['card_id']);
            if (!$card) return;

            $ownerKey = $card->owner;   // ← владелец карты, не активный хода

            $inst   = $subTask['payload'] ?? [];
            $key    = (string) ($inst['key'] ?? '');
            $target = $inst['target'] ?? 'self';
            if (!$this->instantStillAvailable($ownerKey, $card->instanceId, $key)) return;

            if ($target === 'self') {
                $cost = (int) ($inst['coins'] ?? 0);
                if ($cost > 0) {
                    if ($card->coins < $cost) return;
                    $card->coins -= $cost;
                    $this->engine->syncCoinBonus($card);
                }
                $card->closed = true;
                $this->engine->applyInstantEffect($this->state, $inst['effect'] ?? [], $card, $card, $ownerKey);
                $this->engine->finalizeDying($this->state);
                $this->markInstantUsed($card, $key);
                return;
            }

            $cost = (int) ($inst['coins'] ?? 0);
            if ($cost > 0 && $card->coins < $cost) {
                // пропускаем, недостаточно монет
                return;
            }

            $this->state->battle['pending_instant_pick'] = [
                'owner'   => $ownerKey,
                'card_id' => $card->instanceId,
                'target'  => $target,
                'effect'  => $inst['effect'] ?? [],
                'label'   => $subTask['label'] ?? 'Инстант',
                'cost'    => $cost,
                'source'   => 'phase',
                'list_key' => $key,
            ];
        }
    }

    private function instantStillAvailable(string $ownerKey, int $cardId, string $key): bool
    {
        foreach ((new InstantProcessor($this->state, $this->engine))->getInstants($ownerKey, 'before', 'turn') as $inst) {
            if ((int) $inst['card_id'] === $cardId && (string) ($inst['payload']['key'] ?? '') === $key) {
                return true;
            }
        }
        return false;
    }

    private function markInstantUsed(CardInstance $card, string $key): void
    {
        if ($key === '') return;
        if (!isset($card->flags['instant_uses_this_turn']) || !is_array($card->flags['instant_uses_this_turn'])) {
            $card->flags['instant_uses_this_turn'] = [];
        }
        $card->flags['instant_uses_this_turn'][$key] =
            ((int) ($card->flags['instant_uses_this_turn'][$key] ?? 0)) + 1;
    }

    private function executePoison(string $activeKey): array
    {
        $affected = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;
            if (empty($card->markers['poison'])) continue;

            $poison = (int) $card->markers['poison']['value'];
            if ($poison <= 0) continue;

            $hpBefore = $card->hp;
            $this->engine->applyDamage($this->state, $card, $poison, 'poison');

            $affected[] = ['instance_id' => $card->instanceId, 'delta' => -($hpBefore - $card->hp)];
        }

        return ['label' => 'Яд', 'items' => $affected];
    }

    private function executeRegen(string $activeKey): array
    {
        $affected = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;

            $regen = $this->engine->getRegenerationAmount($card);
            if ($regen <= 0) continue;
            if ($card->hp >= $card->hpMax) continue;

            $hpBefore = $card->hp;
            $card->hp += $regen;
            if ($card->hp > $card->hpMax) $card->hp = $card->hpMax;

            $affected[] = ['instance_id' => $card->instanceId, 'delta' => $card->hp - $hpBefore];
        }

        return ['label' => 'Регенерация', 'items' => $affected];
    }

    private function executeGetCoins(string $activeKey): array
    {
        $affected    = [];
        $isFirstTurn = ($this->state->battle['turn'] === 1);

        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;
            if (empty($card->prop['turn_start'])) continue;

            foreach ($card->prop['turn_start'] as $eff) {
                if (($eff['type'] ?? '') !== 'get_coins') continue;

                if (!empty($eff['line']) && !CardStats::isInLine($this->state, $card)) continue;

                // «Если не двигался прошлый ход» — Камнедрев
                if (!empty($eff['only_if_not_moved_last_turn'])) {
                    if ($isFirstTurn) continue;
                    if (!empty($card->flags['moved_last_turn'])) continue;
                }

                $coins  = (int) ($eff['coins'] ?? 1);
                $max    = (int) ($card->prop['coins']['max_value'] ?? 0);
                $before = $card->coins;

                $card->coins += $coins;
                if ($max > 0 && $card->coins > $max) $card->coins = $max;
                $this->engine->syncCoinBonus($card);
                
                if ($card->coins > $before) {
                    $affected[] = ['instance_id' => $card->instanceId, 'delta' => $card->coins - $before];
                }
            }
        }

        return ['label' => 'Монеты', 'items' => $affected];
    }

    private function executeIncarnation(string $activeKey): array
    {
        $events = (new TurnProcessor($this->state, $this->engine))->processIncarnation($activeKey);
        return ['label' => 'Инкарнация', 'items' => $events];
    }

    private function executeTurnStartEffect(array $task, string $ownerKey): array
    {
        $card = $this->state->getCard($task['card_id']);
        if (!$card) return ['label' => $task['label'], 'items' => []];

        $eff = $task['payload'] ?? [];
        $type = $eff['type'] ?? '';
        $label = $task['label'] ?? '';
        $items = [];

        switch ($type) {
            case 'whip':
                $targets = [$card->instanceId];
                foreach ($this->state->cards as $other) {
                    if ($other->owner !== $card->owner) continue;
                    if ($other->instanceId === $card->instanceId) continue;
                    if ($other->zone !== CardInstance::ZONE_FIELD) continue;
                    $dr = abs($other->row - $card->row);
                    $dc = abs($other->col - $card->col);
                    if ($dr <= 1 && $dc <= 1 && ($dr + $dc) > 0) $targets[] = $other->instanceId;
                }
                $this->state->battle['pending_whip'] = [
                    'owner'       => $ownerKey,
                    'source_id'   => $card->instanceId,
                    'source_ukid' => $card->ukid,
                    'targets'     => $targets,
                    'value'       => (int) ($eff['value'] ?? 1),
                ];
                return ['label' => $label, 'items' => []];

            case 'damage':
                if (($eff['target'] ?? '') === 'opposite') {
                    $opp = $this->findOpposite($card);

                    $isVeryFirstTurn = $this->state->battle['turn'] === 1
                        && $ownerKey === $this->state->getFirstPlayerKey();

                    if (!$opp) {
                        return [
                            'label'     => 'Свойство',
                            'source_id' => $card->instanceId,
                            'items'     => [],
                            'note'      => 'не нанес урона (нет цели напротив)',
                        ];
                    }

                    $value = (int) ($eff['value'] ?? 1);

                    if (!$isVeryFirstTurn
                        && isset($eff['value_if_not_moved'])
                        && empty($opp->flags['moved_last_turn'])) {
                        $value = (int) $eff['value_if_not_moved'];
                    }

                    $this->engine->applyDamage($this->state, $opp, $value, 'impact');
                    $items[] = ['instance_id' => $opp->instanceId, 'delta' => -$value];

                    return [
                        'label'     => 'Свойство',
                        'source_id' => $card->instanceId,
                        'items'     => $items,
                    ];
                }
                return ['label' => $label, 'items' => $items];

            case 'modifier':
                $stat  = (string) ($eff['stat'] ?? 'ova');
                $value = (int) ($eff['value'] ?? 1);

                $card->modifiers[] = [
                    'stat'   => $stat,
                    'value'  => $value,
                    'expire' => $eff['expire'] ?? 'end_of_turn',
                    'source' => $ownerKey,
                ];

                $statLabel = CardStats::statLabel($stat);

                return ['label' => $label, 'items' => [[
                    'instance_id' => $card->instanceId,
                    'text'        => $statLabel . ' +' . $value,
                ]]];

            default:
                return ['label' => $label, 'items' => []];
        }
    }

    private function executeTurnEndEffect(array $task, string $ownerKey)
    {
        $card = $this->state->getCard($task['card_id']);
        if (!$card) return ['label' => $task['label'], 'items' => []];

        $effects = $card->prop['turn_end'] ?? $card->prop['opponent_turn_end'] ?? [];
        if (!is_array($effects)) return ['label' => $task['label'], 'items' => []];

        $items = [];
        foreach ($effects as $eff) {
            $type = $eff['type'] ?? '';

            if ($type === 'discharge') {
                // Найти цель — для Алвалинда пока без цели, просто «разряд» (заглушка, бьёт ближайшего)
                $target = $this->findNearestEnemy($card, $ownerKey);
                if ($target) {
                    $value = (int) ($eff['value'] ?? 1);
                    $this->engine->applyDamage($this->state, $target, $value, 'discharge', $card);
                    $items[] = ['instance_id' => $target->instanceId, 'delta' => -$value];
                }
            } elseif ($type === 'heal') {
                $value = (int) ($eff['value'] ?? 1);
                $card->hp += $value;
                if ($card->hp > $card->hpMax) $card->hp = $card->hpMax;
                $items[] = ['instance_id' => $card->instanceId, 'delta' => $value];
            } elseif ($type === 'get_coins') {
                $value = (int) ($eff['coins'] ?? 1);
                $card->coins += $value;
                $this->engine->syncCoinBonus($card);
                $items[] = ['instance_id' => $card->instanceId, 'delta' => $value];
            } elseif ($type === 'modifier') {
                $card->modifiers[] = [
                    'stat'   => $eff['stat'] ?? 'ova',
                    'value'  => (int) ($eff['value'] ?? 1),
                    'expire' => $eff['expire'] ?? 'permanent',
                    'source' => $ownerKey,
                ];
                $items[] = ['instance_id' => $card->instanceId, 'text' => CardStats::statLabel($eff['stat'] ?? 'ova') . ' +' . (int) ($eff['value'] ?? 1)];
            }
        }

        return ['label' => $task['label'] ?? 'Конец хода', 'source_id' => $card->instanceId, 'items' => $items];
    }

    private function findNearestEnemy(CardInstance $card, string $ownerKey): ?CardInstance
    {
        foreach ($this->state->cards as $c) {
            if ($c->owner === $ownerKey) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->dying || $c->hp <= 0) continue;
            return $c;  // простейшее — первый враг
        }
        return null;
    }

    private function findOpposite(CardInstance $card): ?CardInstance
    {
        $step = $card->owner === 'host' ? 1 : -1;
        $row  = $card->row + $step;
        foreach ($this->state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if ($c->owner === $card->owner) continue;
            if ($c->row === $row && $c->col === $card->col) return $c;
        }
        return null;
    }

    // ─── Вспомогательное ─────────────────────────────────────

    private function hasPendingFromTask(string $type): bool
    {
        $battle = $this->state->battle;
        [$base] = explode(':', $type . ':') + [''];

        if ($base === 'poison' && !empty($battle['pending_any_death'])) return true;
        if ($base === 'incarnation' && !empty($battle['pending_incarnation'])) return true;
        if ($base === 'turn_start' || $base === 'opponent_turn_start') {
            if (!empty($battle['pending_whip'])) return true;
        }
        if ($base === 'instant' && !empty($battle['pending_instant_pick'])) return true;

        return false;
    }

    public function isActive(): bool
    {
        return !empty($this->state->battle['turn_phase']);
    }

        private function wouldGainCoins(string $activeKey): bool
    {
        $isFirstTurn = ($this->state->battle['turn'] === 1);

        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;
            if (empty($card->prop['turn_start'])) continue;

            foreach ($card->prop['turn_start'] as $eff) {
                if (($eff['type'] ?? '') !== 'get_coins') continue;
                if (!empty($eff['line']) && !CardStats::isInLine($this->state, $card)) continue;

                if (!empty($eff['only_if_not_moved_last_turn'])) {
                    if ($isFirstTurn) continue;
                    if (!empty($card->flags['moved_last_turn'])) continue;
                }

                $max = (int) ($card->prop['coins']['max_value'] ?? 0);
                if ($max > 0 && $card->coins >= $max) continue;   // уже максимум

                return true;
            }
        }
        return false;
    }

    private function wouldRegenerate(string $activeKey): bool
    {
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;

            if ($this->engine->getRegenerationAmount($card) <= 0) continue;

            // Уже ранен — реген точно что-то сделает
            if ($card->hp < $card->hpMax) return true;

            // Полный, но с ядом — passive-очередь тикнет и создаст рану,
            // значит regen-таск должен стоять в очереди заранее
            if (!empty($card->markers['poison'])) return true;
        }
        return false;
    }

    private function wouldPoison(string $activeKey): bool
    {
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;
            if (!empty($card->markers['poison'])) return true;
        }
        return false;
    }

    private function executeInstant(array $task, string $activeKey)
    {
        $card = $this->state->getCard($task['card_id']);
        if (!$card) return '';

        $inst   = $task['payload'] ?? [];
        $target = $inst['target'] ?? 'self';

        // Self-эффект — применяем сразу
        if ($target === 'self') {
            $card->closed = true;
            $this->engine->applyInstantEffect($this->state, $inst['effect'] ?? [], $card, $card, $activeKey);
            return ['label' => $task['label'], 'items' => []];
        }

        // Нужен выбор цели
        $this->state->battle['pending_instant_pick'] = [
            'owner'   => $activeKey,
            'card_id' => $task['card_id'],
            'target'  => $target,
            'effect'  => $inst['effect'] ?? [],
            'label'   => $task['label'] ?? 'Инстант',
        ];

        return '';
    }

    public function chooseInstantPick(string $playerKey, Command $cmd): Result
    {
        return (new InstantProcessor($this->state, $this->engine))->chooseTurnTarget($playerKey, $cmd);
    }

    private function buildPassiveQueueEnd(string $activeKey, string $passiveKey): array
    {
        $queue = [];

        // Turn-инстанты — в начале очереди
        $instants = $this->buildInstantSubTasks($passiveKey);
        if (!empty($instants)) {
            $queue[] = [
                'id'        => 'instants',
                'type'      => 'instants',
                'label'     => 'Инстанты',
                'sub'       => $instants,
                'can_close' => true,
            ];
        }

        // opponent_turn_end
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $passiveKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;
            if (empty($card->prop['opponent_turn_end'])) continue;

            $queue[] = [
                'id'      => 'ote:' . $card->instanceId,
                'type'    => 'opponent_turn_end',
                'card_id' => $card->instanceId,
                'ukid'    => $card->ukid,
                'label'   => 'Свойство',
            ];
        }

        return $queue;
    }

    private function buildActiveQueueEnd(string $activeKey): array
    {
        $queue = [];

        // 1. Инстанты (turn trigger) — как в начале пока не надо (есть кнопка)
        /*$instants = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;
            if (empty($card->prop['instants'])) continue;

            foreach ($card->prop['instants'] as $inst) {
                if (($inst['trigger'] ?? '') !== 'turn') continue;
                $instants[] = [
                    'id'      => 'instant_' . $card->instanceId . '_' . ($inst['key'] ?? ''),
                    'card_id' => $card->instanceId,
                    'ukid'    => $card->ukid,
                    'row'     => $card->row,
                    'col'     => $card->col,
                    'label'   => $inst['name'] ?? 'Инстант',
                    'payload' => $inst,
                ];
            }
        }
        if (!empty($instants)) {
            $queue[] = [
                'id'        => 'instants',
                'type'      => 'instants',
                'label'     => 'Инстанты',
                'sub'       => $instants,
                'can_close' => true,
            ];
        }*/

        // 2. turn_end-эффекты (per-card)
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;
            if (empty($card->prop['turn_end'])) continue;

            $queue[] = [
                'id'      => 'te:' . $card->instanceId,
                'type'    => 'turn_end',
                'card_id' => $card->instanceId,
                'ukid'    => $card->ukid,
                'row'     => $card->row,
                'col'     => $card->col,
                'label'   => 'Конец хода',
            ];
        }

        return $queue;
    }
}
