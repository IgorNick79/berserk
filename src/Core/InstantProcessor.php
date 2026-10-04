<?php
// src/Core/InstantProcessor.php

declare(strict_types=1);

namespace Berserk\Core;

final class InstantProcessor
{
    public const COMBAT_PHASE_ORDER = [
        'redirect',
        'dice',
        'power',
        'value',
        'setter',
        'wounds',
    ];

    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {}

    // ═══════════════════════════════════════════════════════
    //  ОБЩЕЕ: список доступных инстантов
    // ═══════════════════════════════════════════════════════

    /**
     * Возвращает список доступных инстантов для игрока.
     * $phase: 'before' | 'combat' | 'after'  (для combat-окна)
     * $type:  'turn'                          (для turn-потока)
     */
    public function getInstants(string $ownerKey, string $phase = 'before', string $type = 'turn'): array
    {
        $strike = $this->state->battle['strike'] ?? null;

        $activeKey = null;
        if ($strike && !empty($strike['attacker_id'])) {
            $attacker  = $this->state->getCard($strike['attacker_id']);
            $activeKey = $attacker ? $attacker->owner : null;
        }

        $strikeAttackerId = $strike !== null
            ? (int) ($strike['attacker_id'] ?? 0)
            : 0;

        $result = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $ownerKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;
            if (!empty($card->flags['in_stack'])) continue;
            if (empty($card->prop['instants'])) continue;

            // Атакующий уже объявил действие (strike/shot/...) — свои инстанты
            // в этом сражении не играет, даже если это тот же владелец.
            if ($strikeAttackerId > 0 && $strikeAttackerId === $card->instanceId) {
                continue;
            }
            // Карта в активном pending — тоже занята: отменить и только потом
            // играть инстанты. Даже если pending отменяемый.
            if ($this->isCardInPending($card)) {
                continue;
            }

            foreach ($card->prop['instants'] as $inst) {
                if (($inst['trigger'] ?? '') !== $type) continue;
                $key = (string) ($inst['key'] ?? ($inst['name'] ?? 'instant'));
                $limit = (int) ($inst['uses_per_turn'] ?? 1);
                if ($limit > 0) {
                    $used = (int) ($card->flags['instant_uses_this_turn'][$key] ?? 0);
                    if ($used >= $limit) continue;
                }

                if ($type === 'turn' && $phase === 'before' && !empty($inst['aftermath'])) continue;
                if ($type === 'combat' && !in_array($this->instantCombatPhase($inst), self::COMBAT_PHASE_ORDER, true)) {
                    continue;
                }
                if ($type === 'combat'
                    && ($inst['target'] ?? 'self') === 'adjacent_ally'
                    && empty($this->findAdjacentAllyTargets($ownerKey))) {
                    continue;
                }

                // Активный игрок в бою не играет turn-инстанты (кроме aftermath).
                if ($type === 'turn'
                    && $activeKey !== null
                    && $activeKey === $ownerKey
                    && in_array($phase, ['before', 'after'], true)
                    && empty($inst['aftermath'])) {
                    continue;
                }

                // Проверка легальных целей (для turn)
                if ($type === 'turn') {
                    $target    = $inst['target'] ?? 'self';
                    $condition = $inst['effect']['condition'] ?? null;

                    if ($target !== 'self') {
                        $hasAny = false;
                        foreach ($this->state->cards as $c) {
                            if ($c->zone !== CardInstance::ZONE_FIELD
                                && $c->zone !== CardInstance::ZONE_FLYING) continue;
                            if ($c->dying || $c->hp <= 0) continue;
                            if ($target === 'enemy' && $c->owner === $card->owner) continue;
                            if ($target === 'ally'  && $c->owner !== $card->owner) continue;
                            if ($condition === 'target_not_moved' && !empty($c->flags['moved_this_turn'])) continue;
                            if ($condition === 'target_closed' && !$c->closed) continue;
                            $hasAny = true;
                            break;
                        }
                        if (!$hasAny) continue;
                    }

                    // Специфично для heal_turn_wounds (Хронос)
                    if (($inst['effect']['type'] ?? '') === 'heal_turn_wounds') {
                        $hasWounded = false;
                        foreach ($this->state->cards as $c) {
                            if ($c->owner !== $ownerKey) continue;
                            if ($c->zone !== CardInstance::ZONE_FIELD
                                && $c->zone !== CardInstance::ZONE_FLYING) continue;
                            if ($c->dying || $c->hp <= 0) continue;
                            if ((int) ($c->flags['damage_taken_this_turn'] ?? 0) > 0) {
                                $hasWounded = true;
                                break;
                            }
                        }
                        if (!$hasWounded) continue;
                    }

                    // Стоимость инстанта (coins)
                    $cost = (int) ($inst['coins'] ?? 0);
                    if ($cost > 0 && $card->coins < $cost) continue;
                }

                $result[] = [
                    'card_id' => $card->instanceId,
                    'ukid'    => $card->ukid,
                    'row'     => $card->row,
                    'col'     => $card->col,
                    'label'   => $inst['name'] ?? 'Инстант',
                    'payload' => $inst,
                    'cost'    => (int) ($inst['coins'] ?? 0),
                ];
            }
        }
        return $result;
    }

    // ═══════════════════════════════════════════════════════
    //  COMBAT-ОКНО (стек инстантов в фазе боя)
    // ═══════════════════════════════════════════════════════

    public function openWindow(string $phase, string $priorityKey): void
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike) return;

        $attacker = $this->state->getCard($strike['attacker_id']);
        if (!$attacker) return;

        $attackerKey = $attacker->owner;
        $oppKey      = $this->state->getOpponentKey($attackerKey);

        $type = ($phase === 'combat') ? 'combat' : 'turn';
        $attackerHas = !empty($this->getInstants($attackerKey, $phase, $type));
        $oppHas      = !empty($this->getInstants($oppKey, $phase, $type));

        // Ни у кого нет — окно не открываем
        if (!$attackerHas && !$oppHas) {
            return;
        }

        $passed = [];
        if (!$attackerHas) {
            $passed[] = $attackerKey;
        }
        if (!$oppHas) {
            $passed[] = $oppKey;
        }

        // Если кто-то уже автоматически пропущен — приоритет у того, у кого есть
        $priority = $priorityKey;
        if (!$attackerHas) $priority = $oppKey;
        elseif (!$oppHas)  $priority = $attackerKey;

        $this->state->battle['strike']['state']            = 'waiting_instant';
        $this->state->battle['strike']['instant_phase']    = $phase;
        $this->state->battle['strike']['instant_priority'] = $priority;
        $this->state->battle['strike']['instant_passed']   = $passed;
        $this->state->battle['strike']['instant_played']   = [];
        $this->state->battle['strike']['instant_stack']    = [];
        unset($this->state->battle['strike']['instant_resolution']);
    }

    public function playCombat(string $playerKey, int $cardId, string $key): Result
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || $strike['state'] !== 'waiting_instant') {
            return Result::error('Нет окна инстантов');
        }
        if (($strike['instant_priority'] ?? null) !== $playerKey) {
            return Result::error('Не ваш приоритет');
        }

        $phase = $strike['instant_phase'] ?? 'before';
        $wantedType = ($phase === 'combat') ? 'combat' : 'turn';

        $card = $this->state->getCard($cardId);
        if (!$card || $card->owner !== $playerKey) {
            return Result::error('Карта не ваша');
        }
        if ($card->closed) return Result::error('Карта закрыта');
        if (!empty($card->flags['in_stack'])) {
            return Result::error('Карта уже в стеке');
        }

        $inst = null;
        foreach ($card->prop['instants'] ?? [] as $i) {
            if (($i['trigger'] ?? '') !== $wantedType) continue;
            if ($phase === 'before' && !empty($i['aftermath'])) continue;
            if (($i['key'] ?? '') === $key) { $inst = $i; break; }
        }
        if (!$inst) return Result::error('Инстант не найден');

        $target = $inst['target'] ?? 'self';
        $effect = $inst['effect'] ?? [];
        $combatPhase = $this->instantCombatPhase($inst);
        if ($wantedType === 'combat' && !in_array($combatPhase, self::COMBAT_PHASE_ORDER, true)) {
            return Result::error('Не задана фаза combat-инстанта');
        }

        // Self — сразу в стек
        if ($target === 'self') {
            $card->flags['in_stack'] = true;
            $this->state->battle['strike']['instant_stack'][] = $this->buildStackItem(
                $card,
                $effect,
                $card->instanceId,
                $playerKey,
                $inst['name'] ?? 'Инстант',
                $combatPhase
            );
            $this->state->battle['strike']['instant_passed'] = [];
            $this->state->bumpVersion();
            return Result::ok(['instant_stacked']);
        }

        if ($target === 'adjacent_ally') {
            if (empty($this->findAdjacentAllyTargets($playerKey))) {
                return Result::error('Нет соседней союзной цели');
            }
            $this->state->battle['pending_combat_pick'] = [
                'owner'    => $playerKey,
                'card_id'  => $card->instanceId,
                'target'   => $target,
                'effect'   => $effect,
                'phase'    => $combatPhase,
                'label'    => $inst['name'] ?? 'Инстант',
            ];

            $this->state->bumpVersion();
            return Result::ok(['combat_pick_opened']);
        }

        // Нужен выбор цели
        $this->state->battle['pending_combat_pick'] = [
            'owner'    => $playerKey,
            'card_id'  => $card->instanceId,
            'target'   => $target,
            'effect'   => $effect,
            'phase'    => $combatPhase,
            'label'    => $inst['name'] ?? 'Инстант',
        ];

        $this->state->bumpVersion();
        return Result::ok(['combat_pick_opened']);
    }

    private function buildStackItem(
        CardInstance $card,
        array $effect,
        int $targetId,
        string $playerKey,
        string $label,
        string $phase,
        ?string $choice = null
    ): array {
        $strike = $this->state->battle['strike'] ?? [];
        $sequence = (int) ($strike['instant_next_sequence'] ?? 0);
        $this->state->battle['strike']['instant_next_sequence'] = $sequence + 1;

        $item = [
                'card_id'   => $card->instanceId,
                'effect'    => $effect,
                'target_id' => $targetId,
                'player'    => $playerKey,
                'label'     => $label,
                'phase'     => $phase,
                'sequence'  => $sequence,
                'strike_target_id' => (int) ($strike['defender_id'] ?: ($strike['target_id'] ?? 0)),
            ];
        if ($choice !== null) {
            $item['choice'] = $choice;
        }
        return $item;
    }

    public function chooseTarget(string $playerKey, Command $cmd): Result
    {
        $pc = $this->state->battle['pending_combat_pick'] ?? null;
        if (!$pc) return Result::error('Нет ожидающего выбора');
        if ($pc['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $target = $this->state->getCard((int) $cmd->get('target_id', 0));
        if (!$target) return Result::error('Цель не найдена');

        if ($pc['target'] === 'enemy' && $target->owner === $playerKey) {
            return Result::error('Только на врага');
        }
        if ($pc['target'] === 'ally' && $target->owner !== $playerKey) {
            return Result::error('Только на союзника');
        }
        if ($pc['target'] === 'adjacent_ally'
            && !in_array($target->instanceId, $this->findAdjacentAllyTargets($playerKey), true)) {
            return Result::error('Только соседнее союзное существо цели удара');
        }
        if ($target->zone !== CardInstance::ZONE_FIELD
            && $target->zone !== CardInstance::ZONE_FLYING) {
            return Result::error('Цель не на поле');
        }

        $cond = $pc['effect']['condition'] ?? null;
        if ($cond === 'target_not_moved' && !empty($target->flags['moved_this_turn'])) {
            return Result::error('Цель уже двигалась в этот ход');
        }

        $source = $this->state->getCard($pc['card_id']);
        if (!$source) return Result::error('Источник не найден');

        $source->flags['in_stack'] = true;

        $this->state->battle['strike']['instant_stack'][] = $this->buildStackItem(
            $source,
            (array) $pc['effect'],
            $target->instanceId,
            $playerKey,
            $pc['label'],
            (string) ($pc['phase'] ?? $this->phaseForEffect((array) $pc['effect']))
        );

        $this->state->battle['strike']['instant_passed'] = [];

        unset($this->state->battle['pending_combat_pick']);

        $this->state->bumpVersion();
        return Result::ok(['combat_pick_stacked']);
    }

    public function passCombat(string $playerKey): Result
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || $strike['state'] !== 'waiting_instant') {
            return Result::error('Нет окна инстантов');
        }
        if (($strike['instant_priority'] ?? null) !== $playerKey) {
            return Result::error('Не ваш приоритет');
        }

        $passed = $strike['instant_passed'] ?? [];
        if (!in_array($playerKey, $passed, true)) {
            $passed[] = $playerKey;
        }
        $this->state->battle['strike']['instant_passed'] = $passed;

        if (count($passed) >= 2) {
            $this->resolveStack();
            $this->state->bumpVersion();
            return Result::ok(['instant_stack_resolved']);
        }

        $attacker = $this->state->getCard($strike['attacker_id']);
        $attackerKey = $attacker->owner;
        $oppKey = $this->state->getOpponentKey($attackerKey);
        $newPriority = $playerKey === $attackerKey ? $oppKey : $attackerKey;

        $this->state->battle['strike']['instant_priority'] = $newPriority;

        $this->state->bumpVersion();
        return Result::ok(['priority_passed']);
    }

    public function resolveStack(): void
    {
        $strike = &$this->state->battle['strike'];
        $phase = $strike['instant_phase'] ?? 'before';
        $summary = [];

        if ($phase === 'combat') {
            $this->resolveCombatPhases();
            return;
        }

        $stack = $strike['instant_stack'] ?? [];
        $stack = array_reverse($stack);

        foreach ($stack as $item) {
            $source = $this->state->getCard($item['card_id']);
            $target = $this->state->getCard($item['target_id']);

            $effect = (array) ($item['effect'] ?? []);
            $label  = $item['label'] ?? 'Инстант';

            if (!$target) {
                $summary[] = [
                    'label'     => $label,
                    'card_ukid' => $source ? $source->ukid : '',
                    'player'    => $item['player'] ?? null,
                    'applied'   => false,
                    'reason'    => 'цель не найдена',
                ];
            } elseif (empty($effect)) {
                $summary[] = [
                    'label'     => $label,
                    'card_ukid' => $source ? $source->ukid : '',
                    'player'    => $item['player'] ?? null,
                    'applied'   => false,
                    'reason'    => 'нет эффекта',
                ];
            } else {
                $this->engine->applyInstantEffect(
                    $this->state,
                    $effect,
                    $source,
                    $target,
                    $item['player']
                );
                $applied = true;
                $reasonText = '';

                $summary[] = [
                    'label'     => $label,
                    'card_ukid' => $source ? $source->ukid : '',
                    'player'    => $item['player'] ?? null,
                    'applied'   => $applied,
                    'reason'    => $reasonText,
                ];
            }

            if ($source) {
                $source->closed = true;
                unset($source->flags['in_stack']);
            }
        }

        $this->state->battle['strike']['instant_summary'] = $summary;

        unset(
            $strike['instant_priority'],
            $strike['instant_passed'],
            $strike['instant_stack']
        );

        $phase = $strike['instant_phase'] ?? 'before';
        unset($strike['instant_phase']);

        if ($phase === 'after') {
            $this->state->battle['strike'] = null;
            $this->engine->finalizeDying($this->state);
            return;
        }

        if ($phase === 'combat') {
            (new StrikeResolver($this->state, $this->engine))->recalcTable();
            $table = $strike['result'] ?? null;

            if ($table && $table['attack'] !== '' && $table['defend'] !== '') {
                $strike['state'] = 'waiting_choice';
                $strike['choice_winner'] = $table['winner'];
                return;
            }

            (new StrikeResolver($this->state, $this->engine))
                ->apply($table ?? ['attack' => '', 'defend' => '', 'winner' => ''], false);

            if (($strike['state'] ?? '') !== 'waiting_auto_target') {
                $strike['state'] = 'results';
            }
            return;
        }

        if ($phase === 'before') {
            $attacker = $this->state->getCard($strike['attacker_id']);

            $cancelled = false;
            if (!$attacker) $cancelled = true;
            elseif ($attacker->dying || $attacker->hp <= 0) $cancelled = true;
            elseif ($attacker->closed) $cancelled = true;
            elseif ($attacker->zone !== CardInstance::ZONE_FIELD
                && $attacker->zone !== CardInstance::ZONE_FLYING) $cancelled = true;

            if ($cancelled) {
                $this->state->battle['strike'] = null;
                $this->engine->finalizeDying($this->state);
                return;
            }
        }

        $this->resumeAfterWindow();
    }

    public function resumeCombatResolution(): void
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || ($strike['instant_phase'] ?? null) !== 'combat') return;

        $this->resolveCombatPhases();
    }

    public function resumeCombatWounds(): void
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || empty($strike['pending_wounds_resolution'])) return;

        unset($this->state->battle['strike']['pending_wounds_resolution']);
        $this->resolveCombatPhases(true);
    }

    public function hasPendingCombatWounds(): bool
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || empty($strike['instant_stack'])) return false;

        foreach ($strike['instant_stack'] as $item) {
            if (($item['phase'] ?? '') === 'wounds') return true;
        }
        return false;
    }

    private function resolveCombatPhases(bool $woundsOnly = false): void
    {
        $strike = &$this->state->battle['strike'];

        if (empty($strike['instant_resolution'])) {
            $this->startCombatResolution($woundsOnly);
        }

        while (!empty($strike['instant_resolution']['queue'])) {
            $item = $strike['instant_resolution']['queue'][0];
            $phase = (string) ($item['phase'] ?? '');

            if ($phase === 'wounds' && !$woundsOnly && empty($strike['damage_applied'])) {
                $strike['pending_wounds_resolution'] = true;
                $this->finishCombatResolution(false);
                return;
            }

            $result = $this->resolveCombatItem($item);
            if ($result === 'paused') {
                return;
            }

            array_shift($this->state->battle['strike']['instant_resolution']['queue']);
            if ($result['source']) {
                $result['source']->closed = true;
                unset($result['source']->flags['in_stack']);
            }
            $this->appendInstantSummary($item, $result['applied'], $result['reason'], $result['source']);
            $strike = &$this->state->battle['strike'];
        }

        $this->finishCombatResolution(true);
    }

    private function startCombatResolution(bool $woundsOnly): void
    {
        $stack = $this->state->battle['strike']['instant_stack'] ?? [];
        $queue = [];

        foreach (self::COMBAT_PHASE_ORDER as $phase) {
            if ($woundsOnly && $phase !== 'wounds') continue;
            if (!$woundsOnly && $phase === 'wounds') continue;

            $items = array_values(array_filter(
                $stack,
                fn(array $item): bool => ($item['phase'] ?? $this->phaseForEffect((array) ($item['effect'] ?? []))) === $phase
            ));
            usort($items, fn(array $a, array $b): int => ((int) ($b['sequence'] ?? 0)) <=> ((int) ($a['sequence'] ?? 0)));
            foreach ($items as $item) {
                $queue[] = $item;
            }
        }

        $this->state->battle['strike']['instant_resolution'] = [
            'mode' => $woundsOnly ? 'wounds' : 'combat',
            'queue' => $queue,
        ];
    }

    private function resolveCombatItem(array $item): array|string
    {
        $source = $this->state->getCard((int) ($item['card_id'] ?? 0));
        $target = $this->state->getCard((int) ($item['target_id'] ?? 0));
        $effect = (array) ($item['effect'] ?? []);
        $type = (string) ($effect['type'] ?? '');
        $effectTarget = $target;
        if ($type === 'damage_cap' && (int) ($item['strike_target_id'] ?? 0) > 0) {
            $effectTarget = $this->state->getCard((int) $item['strike_target_id']);
        }

        if (!$source) {
            return ['source' => null, 'applied' => false, 'reason' => 'источник не найден'];
        }
        if (empty($effect)) {
            return ['source' => $source, 'applied' => false, 'reason' => 'нет эффекта'];
        }

        if ($type === 'dice_choice' && !isset($item['choice'])) {
            $this->state->battle['pending_dice_choice'] = [
                'owner'   => $item['player'],
                'card_id' => $source->instanceId,
                'label'   => $item['label'] ?? 'Ловец',
                'resume'  => 'combat_resolution',
            ];
            return 'paused';
        }

        if ($type === 'redistribute_wounds') {
            $result = (new WoundTransferProcessor($this->state, $this->engine))
                ->start((string) $item['player'], $source, [
                    'kind'          => 'hermit',
                    'donor_filter'  => 'damaged_this_strike',
                    'target_filter' => 'own',
                    'max_transfer'  => 0,
                    'coins_cost'    => 0,
                    'on_finish'     => 'combat_resolution',
                ]);
            if ($result->success) {
                return 'paused';
            }
            return ['source' => $source, 'applied' => false, 'reason' => $result->error ?? 'нет подходящих ран'];
        }

        if ($type === 'redirect_strike' && !$target) {
            return ['source' => $source, 'applied' => false, 'reason' => 'цель не найдена'];
        }

        $reason = $this->engine->applyCombatEffect(
            $this->state,
            $effect,
            $source,
            (string) $item['player'],
            $item['choice'] ?? null,
            $effectTarget
        );

        return ['source' => $source, 'applied' => $reason === null, 'reason' => $reason ?? ''];
    }

    public function applyDiceChoice(string $playerKey, string $choice): Result
    {
        $dc = $this->state->battle['pending_dice_choice'] ?? null;
        if (!$dc) return Result::error('Нет ожидающего выбора');
        if ($dc['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $valid  = ['plus:own', 'minus:own', 'plus:enemy', 'minus:enemy', 'reroll:any'];
        if (!in_array($choice, $valid, true)) {
            return Result::error('Неверный выбор');
        }

        $strike = $this->state->battle['strike'] ?? null;
        $queue = $strike['instant_resolution']['queue'] ?? [];
        if (empty($queue)) return Result::error('Очередь инстантов пуста');

        $item = $queue[0];
        $source = $this->state->getCard((int) ($item['card_id'] ?? 0));
        if (!$source) return Result::error('Карта не найдена');

        $reason = $this->engine->applyCombatEffect(
            $this->state,
            ['type' => 'dice_choice'],
            $source,
            $playerKey,
            $choice
        );
        if ($reason !== null) {
            return Result::error($reason);
        }

        unset($this->state->battle['pending_dice_choice']);
        array_shift($this->state->battle['strike']['instant_resolution']['queue']);
        $source->closed = true;
        unset($source->flags['in_stack']);
        $item['choice'] = $choice;
        $this->appendInstantSummary($item, true, '', $source);
        $this->resumeCombatResolution();
        $this->state->bumpVersion();
        return Result::ok(['dice_choice_resolved']);
    }

    public function completePausedCombatItem(bool $applied, string $reason = ''): void
    {
        $queue = $this->state->battle['strike']['instant_resolution']['queue'] ?? [];
        if (empty($queue)) return;

        $item = $queue[0];
        $source = $this->state->getCard((int) ($item['card_id'] ?? 0));
        array_shift($this->state->battle['strike']['instant_resolution']['queue']);
        if ($source) {
            $source->closed = true;
            unset($source->flags['in_stack']);
        }
        $this->appendInstantSummary($item, $applied, $reason, $source);
        $this->resumeCombatResolution();
    }

    private function finishCombatResolution(bool $done): void
    {
        $strike = &$this->state->battle['strike'];
        $mode = (string) ($strike['instant_resolution']['mode'] ?? 'combat');
        if (!$done) {
            unset($strike['instant_resolution']);
            return;
        }

        if ($mode === 'wounds') {
            unset(
                $strike['instant_priority'],
                $strike['instant_passed'],
                $strike['instant_stack'],
                $strike['instant_next_sequence'],
                $strike['instant_resolution'],
                $strike['pending_wounds_resolution']
            );
            unset($strike['instant_phase']);
            if (($strike['state'] ?? '') !== 'waiting_auto_target') {
                $strike['state'] = 'results';
            }
            return;
        }

        $hasWounds = $this->hasPendingCombatWounds();

        unset(
            $strike['instant_priority'],
            $strike['instant_passed'],
            $strike['instant_resolution']
        );

        if ($hasWounds) {
            $strike['pending_wounds_resolution'] = true;
        } else {
            unset($strike['instant_stack'], $strike['instant_next_sequence']);
            unset($strike['instant_phase']);
        }

        (new StrikeResolver($this->state, $this->engine))->recalcTable();
        $table = $strike['result'] ?? null;

        if ($table && $table['attack'] !== '' && $table['defend'] !== '') {
            $strike['state'] = 'waiting_choice';
            $strike['choice_winner'] = $table['winner'];
            return;
        }

        (new StrikeResolver($this->state, $this->engine))
            ->apply($table ?? ['attack' => '', 'defend' => '', 'winner' => ''], false);

        if (($strike['state'] ?? '') !== 'waiting_auto_target') {
            $strike['state'] = 'results';
        }
    }

    private function appendInstantSummary(array $item, bool $applied, string $reason, ?CardInstance $source): void
    {
        $this->state->battle['strike']['instant_summary'][] = [
            'label'     => $item['label'] ?? 'Инстант',
            'card_ukid' => $source ? $source->ukid : '',
            'player'    => $item['player'] ?? null,
            'phase'     => $item['phase'] ?? null,
            'applied'   => $applied,
            'reason'    => $reason,
        ];
    }

    private function instantCombatPhase(array $inst): string
    {
        return (string) ($inst['phase'] ?? $this->phaseForEffect((array) ($inst['effect'] ?? [])));
    }

    private function phaseForEffect(array $effect): string
    {
        return match ((string) ($effect['type'] ?? '')) {
            'redirect_strike' => 'redirect',
            'dice_choice', 'damage_on_dice' => 'dice',
            'strike_level' => (($effect['mode'] ?? '') === 'reduce_one') ? 'value' : 'power',
            'damage_cap' => 'setter',
            'redistribute_wounds' => 'wounds',
            default => '',
        };
    }

    private function findAdjacentAllyTargets(string $playerKey): array
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike) return [];

        $currentTarget = $this->state->getCard((int) ($strike['target_id'] ?? 0));
        if (!$currentTarget) return [];

        return ZoneManager::adjacentFieldAllyIds($this->state, $currentTarget, $playerKey);
    }

    private function resumeAfterWindow(): void
    {
        $strike = &$this->state->battle['strike'];

        if (!empty($strike['redirect_candidates'])) {
            $strike['state'] = 'waiting_redirect';
            return;
        }
        if (!empty($strike['defenders'])) {
            $strike['state'] = 'waiting_defender';
            return;
        }
        (new StrikeResolver($this->state, $this->engine))->resolve();
    }

    // ═══════════════════════════════════════════════════════
    //  TURN-ПОТОК (инстанты в main phase / фазе хода)
    // ═══════════════════════════════════════════════════════

    public function openTurnInstants(string $playerKey, Command $cmd): Result
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

        $instants = $this->getInstants($playerKey, 'before');

        if (empty($instants)) {
            return Result::error('Нет доступных инстантов');
        }

        $list = [];
        foreach ($instants as $i) {
            $list[] = [
                'card_id' => $i['card_id'],
                'ukid'    => $i['ukid'],
                'key'     => $i['payload']['key'] ?? '',
                'label'   => $i['label'],
                'target'  => $i['payload']['target'] ?? 'self',
                'effect'  => $i['payload']['effect'] ?? [],
                'cost'    => $i['cost'],
                'uses_per_turn' => (int) ($i['payload']['uses_per_turn'] ?? 1),
            ];
        }

        $this->state->battle['pending_turn_instants'] = [
            'owner' => $playerKey,
            'list'  => $list,
        ];

        $this->state->bumpVersion();
        return Result::ok(['turn_instants_opened']);
    }

    public function playTurnInstant(string $playerKey, Command $cmd): Result
    {
        $ti = $this->state->battle['pending_turn_instants'] ?? null;
        if (!$ti) return Result::error('Нет окна инстантов');
        if ($ti['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $cardId = (int) $cmd->get('card_id', 0);
        $key    = (string) $cmd->get('instant_key', '');

        $found = null;
        foreach ($ti['list'] as $item) {
            if ((int) $item['card_id'] === $cardId && (string) $item['key'] === $key) {
                $found = $item;
                break;
            }
        }
        if (!$found) {
            return Result::error('Инстант не найден: card_id=' . $cardId . ', key=' . $key);
        }

        $card = $this->state->getCard($cardId);
        if (!$card || $card->owner !== $playerKey) {
            return Result::error('Карта не ваша');
        }
        if ($card->closed) return Result::error('Карта закрыта');
        $limit = (int) ($found['uses_per_turn'] ?? 1);
        $useKey = (string) ($found['key'] ?? '');
        if ($limit > 0 && (int) ($card->flags['instant_uses_this_turn'][$useKey] ?? 0) >= $limit) {
            return Result::error('Уже использовано в этот ход');
        }

        $target = $found['target'] ?? 'self';
        $cost   = (int) ($found['cost'] ?? 0);

        if ($target === 'self') {
            if ($cost > 0 && $card->coins < $cost) {
                return Result::error('Не хватает монет');
            }
            if ($cost > 0) {
                $card->coins -= $cost;
                $this->engine->syncCoinBonus($card);
            }
            $card->closed = true;
            $this->engine->applyInstantEffect($this->state, $found['effect'], $card, $card, $playerKey);
            $this->engine->finalizeDying($this->state);
            $this->markInstantUsed($card, $useKey);

            $this->removeTurnInstant($cardId, $key);

            $this->state->bumpVersion();
            return Result::ok(['turn_instant_played']);
        }

        // Нужен выбор цели
        $this->state->battle['pending_instant_pick'] = [
            'owner'    => $playerKey,
            'card_id'  => $cardId,
            'target'   => $target,
            'effect'   => $found['effect'],
            'label'    => $found['label'],
            'source'   => 'turn_instants',
            'list_key' => $key,
            'cost'     => $cost,
        ];

        $this->state->bumpVersion();
        return Result::ok(['instant_pick_opened']);
    }

    public function removeTurnInstant(int $cardId, string $key): void
    {
        if (empty($this->state->battle['pending_turn_instants'])) return;

        $list = [];
        foreach ($this->state->battle['pending_turn_instants']['list'] as $item) {
            if ($item['card_id'] === $cardId && $item['key'] === $key) continue;
            $list[] = $item;
        }

        if (empty($list)) {
            unset($this->state->battle['pending_turn_instants']);
        } else {
            $this->state->battle['pending_turn_instants']['list'] = $list;
        }
    }

    public function chooseTurnTarget(string $playerKey, Command $cmd): Result
    {
        $pi = $this->state->battle['pending_instant_pick'] ?? null;
        if (!$pi) return Result::error('Нет ожидающего выбора');
        if ($pi['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $target = $this->state->getCard((int) $cmd->get('target_id', 0));
        if (!$target) return Result::error('Цель не найдена');

        if ($pi['target'] === 'enemy' && $target->owner === $playerKey) {
            return Result::error('Только на врага');
        }
        if ($pi['target'] === 'ally' && $target->owner !== $playerKey) {
            return Result::error('Только на союзника');
        }
        if ($target->zone !== CardInstance::ZONE_FIELD
            && $target->zone !== CardInstance::ZONE_FLYING) {
            return Result::error('Цель не на поле');
        }
        $condition = $pi['effect']['condition'] ?? null;
        if ($condition === 'target_closed' && !$target->closed) {
            return Result::error('Цель должна быть закрыта');
        }

        $source = $this->state->getCard($pi['card_id']);
        if ($source) {
            $key = (string) ($pi['list_key'] ?? '');
            $limit = 1;
            foreach ($source->prop['instants'] ?? [] as $inst) {
                if (($inst['key'] ?? '') === $key) {
                    $limit = (int) ($inst['uses_per_turn'] ?? 1);
                    break;
                }
            }
            if ($limit > 0 && (int) ($source->flags['instant_uses_this_turn'][$key] ?? 0) >= $limit) {
                return Result::error('Уже использовано в этот ход');
            }
            $cost = (int) ($pi['cost'] ?? 0);
            if ($cost > 0 && $source->coins < $cost) {
                return Result::error('Не хватает монет');
            }
            if ($cost > 0) {
                $source->coins -= $cost;
                $this->engine->syncCoinBonus($source);
            }
            $source->closed = true;
            $this->markInstantUsed($source, $key);
        }

        $this->engine->applyInstantEffect($this->state, $pi['effect'], $source, $target, $playerKey);
        $this->engine->finalizeDying($this->state);
        unset($this->state->battle['pending_instant_pick']);

        $src     = $pi['source'] ?? 'phase';
        $listKey = $pi['list_key'] ?? null;

        if ($src === 'turn_instants') {
            unset($this->state->battle['pending_turn_instants']);
        } else {
            // Убираем подзадачу из turn_phase
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

            if (!empty($this->state->battle['turn_phase'])) {
                (new TurnPhaseProcessor($this->state, $this->engine))->resume();
            }
        }

        $this->state->bumpVersion();
        return Result::ok(['instant_applied']);
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

    private function isCardInPending(CardInstance $card): bool
    {
        foreach ($this->state->battle as $key => $val) {
            if (!is_string($key) || !str_starts_with($key, 'pending_')) continue;
            if (!is_array($val)) continue;

            foreach (['attacker_id', 'card_id', 'source_id', 'healer_id'] as $field) {
                if ((int) ($val[$field] ?? 0) === $card->instanceId) {
                    return true;
                }
            }
        }
        return false;
    }
}
