<?php
// src/Core/TurnProcessor.php

declare(strict_types=1);

namespace Berserk\Core;

/**
 * Управление ходом: начало боя, начало/конец хода, стартовые эффекты,
 * триггеры начала хода, выбор в начале хода.
 */
final class TurnProcessor
{
    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {}

    // ─── Начало боя ──────────────────────────────────────────

    public function startBattle(): void
    {
        $firstKey  = $this->state->getFirstPlayerKey();
        $secondKey = $this->state->getOpponentKey($firstKey);

        $hiddenRow = ($secondKey === 'host') ? 1 : 6;

        $zone = new ZoneManager($this->state);

        foreach ($this->state->cards as $card) {
            if ($card->zone !== CardInstance::ZONE_FIELD) continue;

            $card->closed   = false;
            $card->revealed = true;

            $card->flags['moved_this_turn'] = false;
            $card->flags['moved_last_turn'] = false;

            $armor = CardStats::computeArmor($this->state, $card);
            $card->armor    = $armor;
            $card->armorMax = $armor;

            if ($card->owner === $secondKey && $card->row === $hiddenRow) {
                $card->revealed = false;
                continue;
            }

            if ($card->type === 'fly') {
                $zone->toFlying($card);
            }
        }

        $activeKey = 'host';
        foreach (['host', 'player'] as $key) {
            if ($this->state->getPlayer($key)->side === 1) {
                $activeKey = $key;
                break;
            }
        }

        $this->state->battle = [
            'turn'   => 1,
            'active' => $activeKey,
            'strike' => null,
            'hidden_row_revealed' => false
        ];

        // Стартовые эффекты
        $this->applyStartEffects();

        // Проверка выбора в начале первого хода
        $needsChoice = $this->checkCardChoice($activeKey);

        if (!$needsChoice) {
            $this->continueStartTurn($activeKey);
        }
    }

    // ─── Конец хода ──────────────────────────────────────────

    public function endTurn(string $playerKey, Command $cmd): Result
    {
        if ($this->state->status !== 'battle') {
            return Result::error('Сейчас не бой');
        }
        if ($this->state->battle['active'] !== $playerKey) {
            return Result::error('Сейчас не ваш ход');
        }
        if (!empty($this->state->battle['strike'])) {
            return Result::error('Идёт сражение');
        }

        // Обязательная атака
        foreach ($this->state->cards as $c) {
            if ($c->owner !== $playerKey) continue;
            if ($c->zone !== CardInstance::ZONE_FIELD) continue;
            if (CardStats::getForcedStrikeTarget($this->state, $c) !== null) {
                return Result::error('Сначала обязаны атаковать закрытое существо');
            }
        }

        $oppKey = $this->state->getOpponentKey($playerKey);
        $this->state->battle['active'] = $oppKey;

        if ($oppKey === 'host') {
            $this->state->battle['turn']++;
        }

        // Флаги движения
        foreach ($this->state->cards as $card) {
            if ($card->owner === $playerKey) {
                $card->flags['moved_last_turn'] = $card->flags['moved_this_turn'] ?? false;
                $card->flags['moved_this_turn'] = false;
            }
        }

        // Раскрытие скрытого ряда (ход 1) — до фазы
        if (!$this->state->battle['hidden_row_revealed']
            && $playerKey === $this->state->getFirstPlayerKey()) {

            $this->state->battle['hidden_row_revealed'] = true;

            $zone = new ZoneManager($this->state);
            foreach ($this->state->cards as $card) {
                if ($card->zone !== CardInstance::ZONE_FIELD) continue;
                $card->revealed = true;
                if ($card->type === 'fly') {
                    $zone->toFlying($card);
                }
            }
        }

        // Фаза конца хода
        (new TurnPhaseProcessor($this->state, $this->engine))->beginEndPhase($playerKey);

        $this->state->bumpVersion();
        return Result::ok(["turn_ending:{$playerKey}"]);
    }

    /**
     * Вызывается из TurnPhaseProcessor::finish() когда фаза конца хода завершена.
     */
    public function afterEndPhase(string $endingKey, string $nextActiveKey): void
    {
        $this->activateScheduledCardMarkers($endingKey);

        // Ледяной дождь Криоманта — действует ровно один ход противника.
        $limit = $this->state->battle['moves_limit'] ?? null;
        if ($limit && ($limit['owner'] ?? '') === $endingKey) {
            unset($this->state->battle['moves_limit']);
        }

        // Истечение маркеров
        foreach ($this->state->cards as $card) {
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;

            if (!empty($card->markers)) {
                foreach ($card->markers as $type => &$m) {
                    if (!isset($m['expire'])) continue;
                    $timing = $m['timing'] ?? 'source_turn';
                    $source = $m['source'] ?? null;

                    $shouldTick = false;
                    if ($timing === 'source_turn' && $source === $endingKey) {
                        $shouldTick = true;
                    } elseif ($timing === 'owner_turn' && $card->owner === $endingKey) {
                        $shouldTick = true;
                    }

                    if ($shouldTick && !empty($m['skip_first_tick'])) {
                        $m['skip_first_tick'] = false;
                        continue;
                    }
                    if ($shouldTick) {
                        $m['expire']--;
                        if ($m['expire'] <= 0) {
                            unset($card->markers[$type]);
                        }
                    }
                }
                unset($m);
            }
        }

        // Снятие модификаторов
        foreach ($this->state->cards as $card) {
            if (empty($card->modifiers)) continue;

            $kept = [];
            foreach ($card->modifiers as $m) {
                $exp = $m['expire'] ?? '';
                if ($exp === 'end_of_turn') continue;
                if ($exp === 'end_of_opponent_turn' && $endingKey !== $card->owner) continue;

                if (is_int($exp) || (is_string($exp) && ctype_digit($exp))) {
                    $timing = $m['timing'] ?? 'source_turn';
                    $source = $m['source'] ?? null;
                    $shouldTick = false;
                    if ($timing === 'source_turn' && $source === $endingKey) $shouldTick = true;
                    elseif ($timing === 'owner_turn' && $card->owner === $endingKey) $shouldTick = true;
                    elseif ($timing === 'not_source_turn' && $source !== $endingKey) $shouldTick = true;

                    if ($shouldTick) {
                        $newExp = (int) $exp - 1;
                        if ($newExp <= 0) continue;
                        $m['expire'] = $newExp;
                    }
                }

                $kept[] = $m;
            }
            $card->modifiers = $kept;
        }

        foreach ($this->state->cards as $card) {
            if ($card->owner !== $endingKey) continue;
            if (empty($card->prop['movement_direction_bonus'])) continue;

            unset($card->flags['movement_direction_bonus']);
        }

        // Тик маркеров клеток
        foreach ($this->state->cell_markers as $key => $markers) {
            $list = ZoneManager::markersAt($this->state, $key);
            $changed = false;

            foreach ($list as $i => $m) {
                if (!isset($m['expire'])) continue;
                $timing = $m['timing'] ?? 'source_turn';
                $source = $m['source'] ?? null;

                $shouldTick = false;
                if ($timing === 'end_of_opponent_turn' && $source !== $endingKey) {
                    $shouldTick = true;
                } elseif ($timing === 'end_of_turn' && $source === $endingKey) {
                    $shouldTick = true;
                }

                if ($shouldTick) {
                    $list[$i]['expire']--;
                    $changed = true;
                    if ($list[$i]['expire'] <= 0) {
                        unset($list[$i]);
                    }
                }
            }

            if ($changed) {
                $list = array_values($list);
                if (empty($list)) {
                    unset($this->state->cell_markers[$key]);
                } else {
                    $this->state->cell_markers[$key] = $list;
                }
            }
        }

        // Pre-turn choice (Оборотень и подобные) — до фазы начала хода
        if ($this->checkCardChoice($nextActiveKey)) {
            // pending_card_choice уже выставлен; continueStartTurn будет
            // вызван из chooseCardOption()
            return;
        }

        // Продолжаем старт нового хода
        $this->continueStartTurn($nextActiveKey);
    }

    // ─── Начало хода ─────────────────────────────────────────

    public function continueStartTurn(string $activeKey, bool $fromIncarnation = false): void
    {
        if (!$fromIncarnation) {
            $this->removeSpiderWebsForSourceOwner($activeKey);

            foreach ($this->state->cards as $card) {
                if ($card->owner !== $activeKey) continue;
                if ($card->zone !== CardInstance::ZONE_FIELD
                    && $card->zone !== CardInstance::ZONE_FLYING) continue;

                // Сброс «ран этого хода» у ВСЕХ карт (не только активного)
                foreach ($this->state->cards as $c) {
                    if ($c->zone !== CardInstance::ZONE_FIELD
                        && $c->zone !== CardInstance::ZONE_FLYING) continue;
                    $c->flags['damage_taken_this_turn'] = 0;
                }
                $card->flags['ranged_hits_this_turn'] = 0;
            }
            // Открытие в начале хода противника
            $oppKey = $this->state->getOpponentKey($activeKey);
            foreach ($this->state->cards as $card) {
                if ($card->owner !== $oppKey) continue;
                if ($card->zone !== CardInstance::ZONE_FIELD
                    && $card->zone !== CardInstance::ZONE_FLYING) continue;
                if (empty($card->prop['open_on_opponent_turn'])) continue;
                if (!$card->closed) continue;

                $card->closed = false;
            }
            // 1. Открытие карт + восстановление move
            foreach ($this->state->cards as $card) {
                if ($card->zone !== CardInstance::ZONE_FIELD
                    && $card->zone !== CardInstance::ZONE_FLYING) continue;

                foreach (array_keys($card->flags) as $flagKey) {
                    if (str_starts_with((string) $flagKey, 'trigger_used_this_turn:')) {
                        unset($card->flags[$flagKey]);
                    }
                }
                unset($card->flags['ranged_redirect_used_this_turn']);

                if ($card->owner === $activeKey) {
                    if (!isset($card->markers['stun'])) {
                        $card->closed = false;
                    }
                    $card->move = $card->effectiveMove();
                    $card->flags['choice_done'] = false;
                    $card->flags['any_death_used_this_turn'] = false;
                    $card->flags['prophecy_done_this_turn'] = false;
                    $card->flags['attack_block_used_this_turn'] = false;
                    $card->flags['prophecy_reorder_used_this_turn'] = false;
                    $card->flags['no_close_this_turn'] = false;
                    $card->flags['shot_used_this_turn'] = false;
                    $card->flags['attacks_used_this_turn'] = 0;
                    $card->flags['after_strike_execute_used_this_turn'] = 0;
                    $card->flags['instant_uses_this_turn'] = [];
                    unset($card->flags['first_attack_target_id']);
                    unset($card->flags['strike_chain_broken']);
                    unset($card->flags['teleport_adjacent_bonus_used']);
                    unset($card->flags['on_heal_open_used_this_turn']);
                    unset($card->flags['nokami_used_this_turn']);
                }
            }

            // Airin — сброс счётчика в начале каждого хода (независимо от владельца)
            foreach ($this->state->cards as $card) {
                if ($card->zone !== CardInstance::ZONE_FIELD
                    && $card->zone !== CardInstance::ZONE_FLYING) continue;
                if (!empty($card->prop['airin_trigger'])) {
                    $card->flags['airin_triggered_this_turn'] = 0;
                }
            }

            // 2. Броня
            foreach ($this->state->cards as $card) {
                if ($card->zone !== CardInstance::ZONE_FIELD
                    && $card->zone !== CardInstance::ZONE_FLYING) continue;

                $armor = CardStats::computeArmor($this->state, $card);
                $card->armor    = $armor;
                $card->armorMax = $armor;
            }
        }

        if ($fromIncarnation) {
            // Продолжение после инкарнации — не запускаем фазу заново
            return;
        }

        // 4. Фаза начала хода (яд пассивного → регенерация активного и т.д.)
        (new TurnPhaseProcessor($this->state, $this->engine))->beginStartPhase($activeKey);
    }

    private function activateScheduledCardMarkers(string $endingKey): void
    {
        $scheduled = (array) ($this->state->battle['scheduled_card_markers'] ?? []);
        if (empty($scheduled)) return;

        $kept = [];
        foreach ($scheduled as $entry) {
            if (($entry['source_owner'] ?? null) !== $endingKey
                || ($entry['activate'] ?? '') !== 'end_of_current_turn') {
                $kept[] = $entry;
                continue;
            }

            $target = $this->state->getCard((int) ($entry['target_id'] ?? 0));
            if (!$target
                || ($target->zone !== CardInstance::ZONE_FIELD
                    && $target->zone !== CardInstance::ZONE_FLYING)
                || $target->dying
                || $target->hp <= 0) {
                continue;
            }

            $marker = (array) ($entry['marker'] ?? []);
            $type = (string) ($marker['type'] ?? '');
            if ($type === '') continue;

            $target->markers[$type] = [
                'value' => 1,
                'source' => (string) ($entry['source_owner'] ?? ''),
                'source_id' => (int) ($entry['source_id'] ?? 0),
                'timing' => (string) ($marker['timing'] ?? 'source_next_turn_start'),
            ];
        }

        if (empty($kept)) {
            unset($this->state->battle['scheduled_card_markers']);
        } else {
            $this->state->battle['scheduled_card_markers'] = array_values($kept);
        }
    }

    private function removeSpiderWebsForSourceOwner(string $activeKey): void
    {
        foreach ($this->state->cards as $card) {
            if (empty($card->markers['spider_web'])) continue;
            $marker = (array) $card->markers['spider_web'];
            if (($marker['timing'] ?? '') !== 'source_next_turn_start') continue;
            if (($marker['source'] ?? null) !== $activeKey) continue;
            unset($card->markers['spider_web']);
        }
    }

    /**
     * Вызывается из TurnPhaseProcessor::finish() после завершения фазы.
     * Здесь — то, что должно идти после яда/регена:
     * пророчество, инкарнация, choice_on_turn_start.
     */
    public function afterStartPhase(string $activeKey): void
    {
        if (!empty($this->state->battle['strike'])) return;
        if ($this->state->winner !== null) return;

    }

    /**
     * Инкарнация: +1 жетон, проверка готовых, возврат летунов, pending для обычных.
     */
    public function processIncarnation(string $activeKey): array
    {
        $events = [];

        // 1. Инкремент жетонов
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_GRAVEYARD) continue;
            if (!array_key_exists('incarnation', $card->prop)) continue;
            if (!empty($card->flags['incarnation_ready'])) continue;
            if (empty($card->markers['incarnation'])) continue;

            $card->markers['incarnation']['value']++;
            $newValue = (int) $card->markers['incarnation']['value'];
            $threshold = (int) ($card->markers['incarnation']['threshold'] ?? 0);

            // Триггер "получил жетон" — до сброса в 0 и до ready
            (new IncarnationTokenProcessor($this->state, $this->engine))
                ->onTokenGranted($card, $newValue);

            if ($threshold > 0 && $newValue >= $threshold) {
                $card->markers['incarnation']['value'] = 0;
                $card->flags['incarnation_ready'] = true;
                $events[$card->instanceId] = [
                    'instance_id' => $card->instanceId,
                    'event'       => 'ready',
                ];
            } else {
                $events[$card->instanceId] = [
                    'instance_id' => $card->instanceId,
                    'event'       => 'progress',
                    'value'       => $card->markers['incarnation']['value'],
                    'threshold'   => $threshold,
                ];
            }
        }

        // 2. Летуны — сразу возвращаются
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_GRAVEYARD) continue;
            if (!array_key_exists('incarnation', $card->prop)) continue;
            if (empty($card->flags['incarnation_ready'])) continue;

            $inc     = $card->prop['incarnation'] ?? null;
            $incType = is_array($inc) ? ($inc['type'] ?? null) : null;
            if ($incType !== 'fly') continue;

            $this->incarnateFlyer($card, $inc);
            $events[$card->instanceId] = [
                'instance_id' => $card->instanceId,
                'event'       => 'returned',
            ];
        }

        // 3. Обычные — pending, если есть место
        $zone    = new ZoneManager($this->state);
        $backRow = $activeKey === 'host' ? 1 : 6;
        $hasFree = false;
        for ($col = 1; $col <= 5; $col++) {
            if (!$zone->isFieldOccupied($backRow, $col)) { $hasFree = true; break; }
        }

        $queue = [];
        if ($hasFree) {
            foreach ($this->state->cards as $card) {
                if ($card->owner !== $activeKey) continue;
                if ($card->zone !== CardInstance::ZONE_GRAVEYARD) continue;
                if (!array_key_exists('incarnation', $card->prop)) continue;
                if (empty($card->flags['incarnation_ready'])) continue;

                $inc     = $card->prop['incarnation'] ?? null;
                $incType = is_array($inc) ? ($inc['type'] ?? null) : null;
                if ($incType === 'fly') continue;

                $queue[] = $card->instanceId;
            }
        }

        if (!empty($queue)) {
            $this->state->battle['pending_incarnation'] = [
                'owner'   => $activeKey,
                'queue'   => $queue,
                'current' => $queue[0],
            ];
        }

        // Убираем из событий тех, кто попал в pending — их судьба решится в окне выбора
        foreach ($queue as $id) {
            unset($events[$id]);
        }

        return array_values($events);
    }

    // ─── Выбор в начале хода ────────────────────────────────

    public function checkCardChoice(string $activeKey): bool
    {
        foreach ($this->state->cards as $card) {
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->owner !== $activeKey) continue;
            if (empty($card->prop['pre_turn_start'])) continue;
            if (!empty($card->flags['choice_done'])) continue;

            $config = $card->prop['pre_turn_start'];
            $this->state->battle['pending_card_choice'] = [
                'card_id' => $card->instanceId,
                'options' => $config['options'] ?? [],
                'expire'  => $config['expire'] ?? 'end_of_turn',
            ];
            return true;
        }
        return false;
    }

    public function chooseCardOption(string $playerKey, Command $cmd): Result
    {
        $pc = $this->state->battle['pending_card_choice'] ?? null;
        if (!$pc) {
            return Result::error('Нет ожидающего выбора');
        }

        $card = $this->state->getCard($pc['card_id']);
        if (!$card || $card->owner !== $playerKey) {
            return Result::error('Не ваш выбор');
        }

        $index = (int) $cmd->get('option_index', -1);
        $options = $pc['options'] ?? [];
        if (!isset($options[$index])) {
            return Result::error('Неверный вариант');
        }

        $option = $options[$index];
        $expire = $pc['expire'] ?? 'end_of_turn';

        foreach ($option['modifiers'] ?? [] as $m) {
            $card->modifiers[] = [
                'stat'   => $m['stat'],
                'value'  => $m['value'],
                'expire' => $expire,
            ];
        }

        $card->flags['choice_done'] = true;
        unset($this->state->battle['pending_card_choice']);

        $this->continueStartTurn($playerKey);

        $this->state->bumpVersion();
        return Result::ok(["card_choice:{$index}"]);
    }

    // ─── Стартовые эффекты ──────────────────────────────────

    public function applyStartEffects(): void
    {
        foreach ($this->state->cards as $card) {
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;

            $start = $card->prop['start'] ?? null;
            if (!$start || !is_array($start)) continue;

            $cardSide = $this->state->getPlayer($card->owner)->side ?? 0;
            $wantedSide = (int) ($start['side'] ?? 0);

            if ($wantedSide > 0 && $wantedSide !== $cardSide) {
                continue;
            }

            $this->applyStartEffect($card, $start);
        }
    }

    private function applyStartEffect(CardInstance $card, array $start): void
    {
        $type = $start['type'] ?? '';

        switch ($type) {
            case 'get_coins':
                $coins = (int) ($start['coins'] ?? 1);
                $max = (int) ($card->prop['coins']['max_value'] ?? 0);

                $card->coins += $coins;
                if ($max > 0 && $card->coins > $max) {
                    $card->coins = $max;
                }
                break;
        }
    }

    // ─── Триггеры начала хода ───────────────────────────────


    private function applyPositionModifier(CardInstance $card, array $effect): void
    {
        $pos = $effect['position'] ?? '';

        $nearRow = ($card->owner === 'host') ? 3 : 4;
        $farRow  = ($card->owner === 'host') ? 1 : 6;

        if ($pos === 'near' && $card->row !== $nearRow) return;
        if ($pos === 'far'  && $card->row !== $farRow)  return;

        foreach ($effect['modifiers'] ?? [] as $m) {
            $card->modifiers[] = [
                'stat'   => $m['stat'],
                'value'  => $m['value'],
                'expire' => 'end_of_turn',
            ];
        }
    }

    public function chooseIncarnationCell(string $playerKey, Command $cmd): Result
    {
        $pi = $this->state->battle['pending_incarnation'] ?? null;
        if (!$pi) return Result::error('Нет ожидающего выбора');
        if ($pi['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $row = (int) $cmd->get('row', 0);
        $col = (int) $cmd->get('col', 0);

        // Только задний ряд владельца
        $backRow = $playerKey === 'host' ? 1 : 6;
        if ($row !== $backRow) {
            return Result::error('Только задний ряд');
        }
        if ($col < 1 || $col > 5) {
            return Result::error('За пределами поля');
        }

        $zone = new ZoneManager($this->state);
        if ($zone->isFieldOccupied($row, $col)) {
            return Result::error('Клетка занята');
        }
        if (ZoneManager::hasBlockingMarker($this->state, "{$row}_{$col}")) {
            return Result::error('На клетке маркер');
        }

        $card = $this->state->getCard($pi['current']);
        if (!$card) return Result::error('Карта не найдена');

        $inc = $card->prop['incarnation'] ?? null;
        $open = is_array($inc) && !empty($inc['open']);

        // Возврат: базовые настройки + reset
        $zone->toField($card, $row, $col);
        $card->hp       = $card->hpMax;
        $card->move     = $card->moveMax;
        $card->armor    = 0;                // пересчитается в refreshArmor
        $card->armorMax = 0;
        $card->coins    = 0;
        $card->closed   = !$open;
        $card->dying    = false;
        $card->revealed = true;
        $card->markers  = [];               // снимаем маркер incarnation
        $card->modifiers = [];              // уже пустые, но на всякий
        $card->flags = [];                  // сбрасываем moved_this_turn, choice_done и т.д.
        $card->flags['incarnated'] = true;

        // Бафы после инкарнации
        $abilities = is_array($inc) && isset($inc['abilities']) && is_array($inc['abilities'])
            ? $inc['abilities']
            : [];

        foreach ($abilities as $stat => $val) {
            $card->modifiers[] = [
                'stat'   => $stat,
                'value'  => (int) $val,
                'expire' => 'end_of_turn',
            ];
        }

        // Пересчёт брони (учитывает строй)
        $this->engine->refreshArmor($this->state);

        // Снимаем из очереди
        array_shift($this->state->battle['pending_incarnation']['queue']);

        if (empty($this->state->battle['pending_incarnation']['queue'])) {
            unset($this->state->battle['pending_incarnation']);

            if (!empty($this->state->battle['turn_phase'])) {
                (new TurnPhaseProcessor($this->state, $this->engine))->resume();
            } else {
                $this->continueStartTurn($playerKey, true);
            }
        } else {
            $this->state->battle['pending_incarnation']['current'] =
                $this->state->battle['pending_incarnation']['queue'][0];
        }

        $this->state->bumpVersion();
        return Result::ok(["incarnated:{$card->instanceId}:{$row}_{$col}"]);
    }

    private function incarnateFlyer(CardInstance $card, array $inc): void
    {
        $zone = new ZoneManager($this->state);
        $zone->toFlying($card);

        // Смена типа (creature → fly)
        if (isset($inc['type'])) {
            $card->type = (string) $inc['type'];
        }

        $open = !empty($inc['open']);

        $card->hp        = $card->hpMax;
        $card->move      = $card->moveMax;
        $card->armor     = 0;
        $card->armorMax  = 0;
        $card->coins     = 0;
        $card->closed    = !$open;
        $card->dying     = false;
        $card->revealed  = true;
        $card->markers   = [];
        $card->modifiers = [];
        $card->flags     = [];
        $card->flags['incarnated'] = true;

        // Бафы после инкарнации
        $abilities = isset($inc['abilities']) && is_array($inc['abilities'])
            ? $inc['abilities']
            : [];

        foreach ($abilities as $stat => $val) {
            $card->modifiers[] = [
                'stat'   => $stat,
                'value'  => (int) $val,
                'expire' => 'end_of_turn',
            ];
        }

        $this->engine->refreshArmor($this->state);
    }

    public function triggerProphecy(string $activeKey): bool
    {
        $pp = new ProphecyProcessor($this->state, $this->engine);

        foreach ($this->state->cards as $card) {
            if ($card->owner !== $activeKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if (empty($card->prop['prophecy'])) continue;
            if (!empty($card->flags['prophecy_done_this_turn'])) continue;

            $count = (int) $card->prop['prophecy'];
            if ($count <= 0) continue;

            $peeked = $pp->peek($activeKey, $count);
            if ($peeked === null) {
                $card->flags['prophecy_done_this_turn'] = true;
                continue;
            }

            $isTransform = !empty($card->prop['prophecy_transform']);
            $allElite    = $peeked['meta']['all_elite'];

            if ($isTransform && $allElite) {
                $title   = 'Показана элитная карта. Трансформировать?';
                $actions = [
                    ['label' => 'Трансформировать', 'cmd' => 'transform_seeker'],
                    ['label' => 'Пропустить',       'cmd' => 'close_prophecy', 'class' => 'skip'],
                ];
            } else {
                $title   = 'Пророчество ' . $count;
                $actions = [
                    ['label' => 'Закрыть', 'cmd' => 'close_prophecy', 'class' => 'skip'],
                ];
            }

            $pp->commit($activeKey, $card, $peeked, 'turn_start', $title, $actions);

            $card->flags['prophecy_done_this_turn'] = true;
            return true;
        }
        return false;
    }

    public function closeProphecy(string $playerKey, Command $cmd): Result
    {
        $pp = new ProphecyProcessor($this->state, $this->engine);
        $res = $pp->close($playerKey);
        if (!$res['ok']) return Result::error($res['error']);

        $context = $res['context'];

        // Если в подочереди — удаляем обработанную подзадачу
        if (!empty($this->state->battle['turn_phase']['sub']['pending_id'])) {
            $pid = $this->state->battle['turn_phase']['sub']['pending_id'];
            unset($this->state->battle['turn_phase']['sub']['pending_id']);

            $sub = &$this->state->battle['turn_phase']['sub'];
            foreach ($sub['remaining'] as $i => $s) {
                if (($s['id'] ?? '') === $pid) {
                    array_splice($sub['remaining'], $i, 1);
                    break;
                }
            }
            if (empty($sub['remaining'])) {
                unset($this->state->battle['turn_phase']['sub']);
            }
        }

        // Внутри фазы начала хода — следующее пророчество, иначе resume
        if (!empty($this->state->battle['turn_phase'])) {
            if ($this->triggerProphecy($playerKey)) {
                $this->state->bumpVersion();
                return Result::ok(['prophecy_next']);
            }
            (new TurnPhaseProcessor($this->state, $this->engine))->resume();
            $this->state->bumpVersion();
            return Result::ok(['prophecy_closed']);
        }

        // Fallback (вне фазы)
        if ($context === 'block') {
            $this->state->bumpVersion();
            return Result::ok(['prophecy_closed']);
        }
        if (in_array($context, ['turn_start', 'simple', 'seeker'], true)) {
            if ($this->triggerProphecy($playerKey)) {
                $this->state->bumpVersion();
                return Result::ok(['prophecy_next']);
            }
            $this->continueStartTurn($playerKey, true);
        }

        $this->state->bumpVersion();
        return Result::ok(['prophecy_closed']);
    }

    public function transformSeeker(string $playerKey, Command $cmd): Result
    {
        $pp = new ProphecyProcessor($this->state, $this->engine);
        $res = $pp->transformSeeker($playerKey);
        if ($res->error !== null) {   // ← тут заменить на правильный API
            return $res;
        }

        if ($this->triggerProphecy($playerKey)) {
            $this->state->bumpVersion();
            return Result::ok(['seeker_transformed_next']);
        }
        $this->continueStartTurn($playerKey, true);

        $this->state->bumpVersion();
        return Result::ok(['seeker_transformed']);
    }

    public function triggerProphecyForCard(CardInstance $card, string $activeKey): void
    {
        if (empty($card->prop['prophecy'])) return;
        if (!empty($card->flags['prophecy_done_this_turn'])) return;

        $count = (int) $card->prop['prophecy'];
        if ($count <= 0) return;

        $pp = new ProphecyProcessor($this->state, $this->engine);
        $peeked = $pp->peek($activeKey, $count);
        if ($peeked === null) return;

        $isTransform = !empty($card->prop['prophecy_transform']);
        $allElite    = $peeked['meta']['all_elite'];

        if ($isTransform && $allElite) {
            $title   = 'Показана элитная карта. Трансформировать?';
            $actions = [
                ['label' => 'Трансформировать', 'cmd' => 'transform_seeker'],
                ['label' => 'Пропустить',       'cmd' => 'close_prophecy', 'class' => 'skip'],
            ];
        } else {
            $title   = 'Пророчество ' . $count;
            $actions = [
                ['label' => 'Закрыть', 'cmd' => 'close_prophecy', 'class' => 'skip'],
            ];
        }

        $pp->commit($activeKey, $card, $peeked, 'turn_start', $title, $actions);

        $card->flags['prophecy_done_this_turn'] = true;
    }
}
