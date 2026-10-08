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

    private function normalizeParticipants(array $players): array
    {
        $result = [];
        foreach ($players as $player) {
            $player = (string) $player;
            if ($player === '' || in_array($player, $result, true)) {
                continue;
            }
            $result[] = $player;
        }
        return $result;
    }

    private function instantParticipants(array $strike, string $attackerKey, ?array $allowedPlayers = null): array
    {
        if ($allowedPlayers !== null) {
            return $this->normalizeParticipants($allowedPlayers);
        }
        if (!empty($strike['friendly_fire'])) {
            return [$attackerKey];
        }
        return $this->normalizeParticipants([$attackerKey, $this->state->getOpponentKey($attackerKey)]);
    }

    private function combatParticipants(array $strike): array
    {
        if (!empty($strike['instant_participants']) && is_array($strike['instant_participants'])) {
            return $this->normalizeParticipants($strike['instant_participants']);
        }

        $attacker = !empty($strike['attacker_id']) ? $this->state->getCard((int) $strike['attacker_id']) : null;
        if (!$attacker) {
            return [];
        }
        return $this->instantParticipants($strike, $attacker->owner);
    }

    private function turnParticipants(array $stack): array
    {
        if (!empty($stack['participants']) && is_array($stack['participants'])) {
            return $this->normalizeParticipants($stack['participants']);
        }

        $priority = (string) ($stack['priority'] ?? '');
        if ($priority === '') {
            return [];
        }
        return $this->normalizeParticipants([$priority, $this->state->getOpponentKey($priority)]);
    }

    private function firstParticipantWithInstants(array $participants, array $hasByPlayer): ?string
    {
        foreach ($participants as $player) {
            if (!empty($hasByPlayer[$player])) {
                return $player;
            }
        }
        return null;
    }

    private function allParticipantsPassed(array $participants, array $passed): bool
    {
        foreach ($participants as $player) {
            if (!in_array($player, $passed, true)) {
                return false;
            }
        }
        return !empty($participants);
    }

    private function nextUnpassedParticipant(array $participants, string $current, array $passed): ?string
    {
        if (empty($participants)) {
            return null;
        }

        $count = count($participants);
        $index = array_search($current, $participants, true);
        $start = $index === false ? 0 : ((int) $index + 1);
        for ($step = 0; $step < $count; $step++) {
            $candidate = $participants[($start + $step) % $count];
            if (!in_array($candidate, $passed, true)) {
                return $candidate;
            }
        }
        return null;
    }

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
            if ($card->dying) continue;
            if ($type !== 'turn' && $card->closed) continue;
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
                if ($type === 'turn' && $this->instantRequiresTap($inst) && $card->closed) continue;
                $key = (string) ($inst['key'] ?? ($inst['name'] ?? 'instant'));
                $limit = (int) ($inst['uses_per_turn'] ?? 1);
                if ($limit > 0) {
                    $used = (int) ($card->flags['instant_uses_this_turn'][$key] ?? 0);
                    if ($used >= $limit) continue;
                }

                if ($type === 'combat' && !in_array($this->instantCombatPhase($inst), self::COMBAT_PHASE_ORDER, true)) {
                    continue;
                }
                if ($type === 'combat'
                    && ($inst['target'] ?? 'self') === 'adjacent_ally'
                    && empty($this->findAdjacentAllyTargets($ownerKey))) {
                    continue;
                }

                // Активный игрок в бою не играет turn-инстанты в before/after окне.
                if ($type === 'turn'
                    && $activeKey !== null
                    && $activeKey === $ownerKey
                    && in_array($phase, ['before', 'after'], true)) {
                    continue;
                }

                // Проверка легальных целей (для turn)
                if ($type === 'turn') {
                    $target    = $inst['target'] ?? 'self';
                    $condition = $inst['effect']['condition'] ?? null;

                    if ($target !== 'self' && !$this->hasAnyTurnTarget($card, (string) $target, $condition)) {
                        continue;
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

    private function hasAnyTurnTarget(CardInstance $source, string $target, mixed $condition): bool
    {
        foreach ($this->state->cards as $candidate) {
            if ($this->isLegalTurnTarget($source, $candidate, $target, $condition)) {
                return true;
            }
        }
        return false;
    }

    private function isLegalTurnTarget(
        CardInstance $source,
        CardInstance $candidate,
        string $target,
        mixed $condition
    ): bool {
        if ($candidate->zone !== CardInstance::ZONE_FIELD
            && $candidate->zone !== CardInstance::ZONE_FLYING) return false;
        if ($candidate->dying || $candidate->hp <= 0) return false;

        if ($target === 'enemy' && $candidate->owner === $source->owner) return false;
        if ($target === 'ally' && $candidate->owner !== $source->owner) return false;
        if ($target === 'enemy_open_creature') {
            if ($candidate->owner === $source->owner) return false;
            if ($candidate->closed) return false;
            if (!$this->isCreature($candidate)) return false;
            if (empty($this->findForcedStrikeAdjacentTargets($candidate))) return false;
        }

        if ($condition === 'target_not_moved' && !empty($candidate->flags['moved_this_turn'])) return false;
        if ($condition === 'target_closed' && !$candidate->closed) return false;

        return true;
    }

    private function isCreature(CardInstance $card): bool
    {
        return $card->type === 'creature' || $card->type === 'fly';
    }

    /** @return int[] */
    public function findForcedStrikeAdjacentTargets(CardInstance $attacker): array
    {
        if ($attacker->zone !== CardInstance::ZONE_FIELD
            || $attacker->row === null
            || $attacker->col === null
            || $attacker->closed
            || $attacker->dying
            || $attacker->hp <= 0
            || !CardStats::hasAnyStrike($attacker)) {
            return [];
        }

        $result = [];
        foreach ($this->state->cards as $target) {
            if ($target->instanceId === $attacker->instanceId) continue;
            if ($target->zone !== CardInstance::ZONE_FIELD) continue;
            if ($target->dying || $target->hp <= 0) continue;

            $dr = abs($target->row - $attacker->row);
            $dc = abs($target->col - $attacker->col);
            if ($dr > 1 || $dc > 1 || ($dr + $dc) === 0) continue;

            if (CardStats::hasDefense($this->state, $target, 'strike', $attacker)) continue;

            $result[] = $target->instanceId;
        }
        return $result;
    }

    // ═══════════════════════════════════════════════════════
    //  COMBAT-ОКНО (стек инстантов в фазе боя)
    // ═══════════════════════════════════════════════════════

    public function openWindow(string $phase, string $priorityKey, ?array $allowedPlayers = null): void
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike) return;

        $attacker = $this->state->getCard($strike['attacker_id']);
        if (!$attacker) return;

        $attackerKey = $attacker->owner;
        $participants = $this->instantParticipants($strike, $attackerKey, $allowedPlayers);

        if ($phase !== 'combat') {
            $this->openTurnStackWindow($priorityKey, [
                'type' => $phase === 'after' ? 'strike_after' : 'strike_before',
            ], $phase, $participants);
            return;
        }

        $type = 'combat';
        $hasByPlayer = [];
        foreach ($participants as $player) {
            $hasByPlayer[$player] = !empty($this->getInstants($player, $phase, $type));
        }

        if (!in_array(true, $hasByPlayer, true)) {
            return;
        }

        $passed = [];
        foreach ($hasByPlayer as $player => $hasInstants) {
            if (!$hasInstants) {
                $passed[] = $player;
            }
        }

        $priority = in_array($priorityKey, $participants, true) && !empty($hasByPlayer[$priorityKey])
            ? $priorityKey
            : $this->firstParticipantWithInstants($participants, $hasByPlayer);
        if ($priority === null) {
            return;
        }

        $this->state->battle['strike']['state']            = 'waiting_instant';
        $this->state->battle['strike']['instant_phase']    = $phase;
        $this->state->battle['strike']['instant_priority'] = $priority;
        $this->state->battle['strike']['instant_passed']   = $passed;
        $this->state->battle['strike']['instant_participants'] = $participants;
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
        $participants = $this->combatParticipants($strike);
        if (!in_array($playerKey, $participants, true)) {
            return Result::error('Игрок не участвует в этом окне инстантов');
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
                $combatPhase,
                null,
                (string) ($inst['key'] ?? '')
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
                'instant_key' => (string) ($inst['key'] ?? ''),
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
            'instant_key' => (string) ($inst['key'] ?? ''),
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
        ?string $choice = null,
        string $instantKey = ''
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
                'instant_key' => $instantKey,
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
            (string) ($pc['phase'] ?? $this->phaseForEffect((array) $pc['effect'])),
            null,
            (string) ($pc['instant_key'] ?? '')
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
        $participants = $this->combatParticipants($strike);
        if (!in_array($playerKey, $participants, true)) {
            return Result::error('Игрок не участвует в этом окне инстантов');
        }

        $passed = $strike['instant_passed'] ?? [];
        if (!in_array($playerKey, $passed, true)) {
            $passed[] = $playerKey;
        }
        $this->state->battle['strike']['instant_passed'] = $passed;

        if ($this->allParticipantsPassed($participants, $passed)) {
            $this->resolveStack();
            $this->state->bumpVersion();
            return Result::ok(['instant_stack_resolved']);
        }

        $newPriority = $this->nextUnpassedParticipant($participants, $playerKey, $passed);
        if ($newPriority === null) {
            $this->resolveStack();
            $this->state->bumpVersion();
            return Result::ok(['instant_stack_resolved']);
        }

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
            $this->appendInstantSummary(
                $item,
                $result['applied'],
                $result['reason'],
                $result['source'],
                (array) ($result['result'] ?? [])
            );
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
            return ['source' => null, 'applied' => false, 'reason' => 'источник не найден', 'result' => []];
        }
        if (empty($effect)) {
            return ['source' => $source, 'applied' => false, 'reason' => 'нет эффекта', 'result' => []];
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
            return [
                'source' => $source,
                'applied' => false,
                'reason' => $result->error ?? 'нет подходящих ран',
                'result' => ['transferred' => 0],
            ];
        }

        if ($type === 'redirect_strike' && !$target) {
            return ['source' => $source, 'applied' => false, 'reason' => 'цель не найдена', 'result' => []];
        }

        $before = $this->captureCombatSnapshot();
        $reason = $this->engine->applyCombatEffect(
            $this->state,
            $effect,
            $source,
            (string) $item['player'],
            $item['choice'] ?? null,
            $effectTarget
        );
        $after = $this->captureCombatSnapshot();

        return [
            'source' => $source,
            'applied' => $reason === null,
            'reason' => $reason ?? '',
            'result' => $this->buildCombatEffectResult($effect, $before, $after, (string) $item['player']),
        ];
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

        $before = $this->captureCombatSnapshot();
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
        $after = $this->captureCombatSnapshot();
        $this->appendInstantSummary(
            $item,
            true,
            '',
            $source,
            $this->buildCombatEffectResult(['type' => 'dice_choice'], $before, $after, $playerKey, $choice)
        );
        $this->resumeCombatResolution();
        $this->state->bumpVersion();
        return Result::ok(['dice_choice_resolved']);
    }

    public function completePausedCombatItem(bool $applied, string $reason = '', array $effectResult = []): void
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
        $this->appendInstantSummary($item, $applied, $reason, $source, $effectResult);
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
            $afterResultAck = !empty($strike['wounds_after_result_ack']);
            $strikeForContinuation = $strike;
            unset(
                $strike['instant_priority'],
                $strike['instant_passed'],
                $strike['instant_stack'],
                $strike['instant_next_sequence'],
                $strike['instant_resolution'],
                $strike['pending_wounds_resolution'],
                $strike['wounds_after_result_ack']
            );
            unset($strike['instant_phase']);
            if ($afterResultAck) {
                (new StrikeResolver($this->state, $this->engine))
                    ->continueAfterStrikeResultAck($strikeForContinuation);
                return;
            }
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

    private function appendInstantSummary(
        array $item,
        bool $applied,
        string $reason,
        ?CardInstance $source,
        array $effectResult = []
    ): void
    {
        $this->state->battle['strike']['instant_summary'][] = [
            'label'     => $item['label'] ?? 'Инстант',
            'card_ukid' => $source ? $source->ukid : '',
            'card_id'   => $source ? $source->instanceId : (int) ($item['card_id'] ?? 0),
            'player'    => $item['player'] ?? null,
            'phase'     => $item['phase'] ?? null,
            'sequence'  => (int) ($item['sequence'] ?? 0),
            'instant_key' => (string) ($item['instant_key'] ?? ''),
            'instant_name' => (string) ($item['label'] ?? 'Инстант'),
            'effect_type' => (string) (($item['effect']['type'] ?? '') ?: ''),
            'applied'   => $applied,
            'reason'    => $reason,
            'result'    => $effectResult,
        ];
    }

    private function captureCombatSnapshot(): array
    {
        $strike = $this->state->battle['strike'] ?? [];
        $cards = [];
        foreach ($this->state->cards as $card) {
            $cards[$card->instanceId] = [
                'hp' => $card->hp,
                'damage_taken_this_strike' => (int) ($card->flags['damage_taken_this_strike'] ?? 0),
                'damage_taken_this_turn' => (int) ($card->flags['damage_taken_this_turn'] ?? 0),
            ];
        }

        return [
            'attack_dice' => (int) ($strike['attack_dice'] ?? 0),
            'defend_dice' => (int) ($strike['defend_dice'] ?? 0),
            'target_id' => (int) ($strike['target_id'] ?? 0),
            'defender_id' => (int) ($strike['defender_id'] ?? 0),
            'damage_cap' => $strike['damage_cap'] ?? null,
            'result' => (array) ($strike['result'] ?? []),
            'cards' => $cards,
        ];
    }

    private function buildCombatEffectResult(
        array $effect,
        array $before,
        array $after,
        string $ownerKey,
        ?string $choice = null
    ): array {
        $type = (string) ($effect['type'] ?? '');

        if ($type === 'strike_level') {
            $attacker = $this->state->getCard((int) (($this->state->battle['strike']['attacker_id'] ?? 0)));
            $side = ($attacker && $attacker->owner === $ownerKey) ? 'attack' : 'defend';
            return [
                'side' => $side,
                'before' => (string) ($before['result'][$side] ?? ''),
                'after' => (string) ($after['result'][$side] ?? ''),
            ];
        }

        if ($type === 'damage_on_dice') {
            $damaged = [];
            $total = 0;
            foreach ($after['cards'] as $cardId => $cardAfter) {
                $beforeDamage = (int) ($before['cards'][$cardId]['damage_taken_this_strike'] ?? 0);
                $delta = (int) $cardAfter['damage_taken_this_strike'] - $beforeDamage;
                if ($delta <= 0) continue;
                $damaged[] = ['card_id' => (int) $cardId, 'damage' => $delta];
                $total += $delta;
            }
            return [
                'dice_value' => (int) ($effect['value'] ?? 0),
                'damage_delta' => $total,
                'damaged' => $damaged,
            ];
        }

        if ($type === 'dice_choice') {
            $parts = $choice !== null ? explode(':', $choice) : [];
            $op = $parts[0] ?? '';
            return [
                'choice' => $choice,
                'operation' => $op,
                'attack_before' => (int) $before['attack_dice'],
                'attack_after' => (int) $after['attack_dice'],
                'defend_before' => (int) $before['defend_dice'],
                'defend_after' => (int) $after['defend_dice'],
            ];
        }

        if ($type === 'redirect_strike') {
            return [
                'before_target_id' => (int) $before['target_id'],
                'after_target_id' => (int) $after['target_id'],
            ];
        }

        if ($type === 'damage_cap') {
            return [
                'before_cap' => $before['damage_cap'],
                'after_cap' => $after['damage_cap'],
                'cap' => (int) ($effect['value'] ?? 0),
            ];
        }

        return [];
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

    public function openTurnStackWindow(string $priorityKey, array $continuation, string $phase = 'turn', ?array $allowedPlayers = null): bool
    {
        $participants = $this->normalizeParticipants($allowedPlayers ?? [
            $priorityKey,
            $this->state->getOpponentKey($priorityKey),
        ]);
        $hasByPlayer = [];
        foreach ($participants as $player) {
            $hasByPlayer[$player] = !empty($this->getInstants($player, $phase, 'turn'));
        }
        if (!in_array(true, $hasByPlayer, true)) {
            return false;
        }

        $passed = [];
        foreach ($hasByPlayer as $player => $hasInstants) {
            if (!$hasInstants) {
                $passed[] = $player;
            }
        }

        $priority = in_array($priorityKey, $participants, true) && !empty($hasByPlayer[$priorityKey])
            ? $priorityKey
            : $this->firstParticipantWithInstants($participants, $hasByPlayer);
        if ($priority === null) {
            return false;
        }

        $this->state->battle['turn_instant_stack'] = [
            'state' => 'ordering',
            'phase' => $phase,
            'participants' => $participants,
            'priority' => $priority,
            'passed' => $passed,
            'stack' => [],
            'next_sequence' => 0,
            'continuation' => $continuation,
            'summary' => [],
        ];

        return true;
    }

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

        if (empty($this->getInstants($playerKey, 'turn', 'turn'))) {
            return Result::error('Нет доступных инстантов');
        }
        $this->openTurnStackWindow($playerKey, ['type' => 'manual'], 'turn');

        $this->state->bumpVersion();
        return Result::ok(['turn_instants_opened']);
    }

    public function playTurnInstant(string $playerKey, Command $cmd): Result
    {
        $stackState = $this->state->battle['turn_instant_stack'] ?? null;
        if (!$stackState || ($stackState['state'] ?? '') !== 'ordering') {
            return Result::error('Нет окна инстантов');
        }
        if (($stackState['priority'] ?? null) !== $playerKey) {
            return Result::error('Не ваш приоритет');
        }
        $participants = $this->turnParticipants($stackState);
        if (!in_array($playerKey, $participants, true)) {
            return Result::error('Игрок не участвует в этом окне инстантов');
        }

        $cardId = (int) $cmd->get('card_id', 0);
        $key    = (string) $cmd->get('instant_key', '');

        $found = $this->findAvailableTurnInstant($playerKey, $cardId, $key, (string) ($stackState['phase'] ?? 'turn'));
        if (!$found) return Result::error('Инстант не найден: card_id=' . $cardId . ', key=' . $key);

        $target = $found['target'] ?? 'self';

        if ($target === 'self') {
            $result = $this->pushTurnInstantEntry($playerKey, $found, $cardId);
            if (!$result->success) return $result;
            $this->state->bumpVersion();
            return Result::ok(['turn_instant_stacked']);
        }

        $this->state->battle['pending_instant_pick'] = [
            'owner'    => $playerKey,
            'card_id'  => $cardId,
            'target'   => $target,
            'effect'   => $found['effect'],
            'label'    => $found['label'],
            'source'   => 'turn_stack',
            'list_key' => $key,
            'cost'     => (int) ($found['cost'] ?? 0),
            'payload'  => $found,
        ];

        $this->state->bumpVersion();
        return Result::ok(['instant_pick_opened']);
    }

    public function passTurnInstant(string $playerKey): Result
    {
        $stack = $this->state->battle['turn_instant_stack'] ?? null;
        if (!$stack || ($stack['state'] ?? '') !== 'ordering') {
            return Result::error('Нет окна инстантов');
        }
        if (($stack['priority'] ?? null) !== $playerKey) {
            return Result::error('Не ваш приоритет');
        }
        $participants = $this->turnParticipants($stack);
        if (!in_array($playerKey, $participants, true)) {
            return Result::error('Игрок не участвует в этом окне инстантов');
        }

        $passed = (array) ($stack['passed'] ?? []);
        if (!in_array($playerKey, $passed, true)) {
            $passed[] = $playerKey;
        }
        $this->state->battle['turn_instant_stack']['passed'] = $passed;

        if ($this->allParticipantsPassed($participants, $passed)) {
            $this->resolveTurnStack();
            $this->state->bumpVersion();
            return Result::ok(['turn_instant_stack_resolved']);
        }

        $newPriority = $this->nextUnpassedParticipant($participants, $playerKey, $passed);
        if ($newPriority === null) {
            $this->resolveTurnStack();
            $this->state->bumpVersion();
            return Result::ok(['turn_instant_stack_resolved']);
        }
        $this->state->battle['turn_instant_stack']['priority'] = $newPriority;
        $this->autoPassTurnPriorityIfNoOptions();
        $this->state->bumpVersion();
        return Result::ok(['turn_instant_priority_passed']);
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
        if ($condition === 'target_not_moved' && !empty($target->flags['moved_this_turn'])) {
            return Result::error('Цель уже двигалась в этот ход');
        }
        if ($condition === 'target_closed' && !$target->closed) {
            return Result::error('Цель должна быть закрыта');
        }

        $source = $this->state->getCard((int) $pi['card_id']);
        if (!$source) return Result::error('Источник не найден');
        if (!$this->isLegalTurnTarget($source, $target, (string) ($pi['target'] ?? 'self'), $condition)) {
            return Result::error('Цель недоступна');
        }

        $effect = (array) ($pi['effect'] ?? []);
        if (($effect['type'] ?? '') === 'forced_strike_adjacent') {
            $candidates = $this->findForcedStrikeAdjacentTargets($target);
            if (empty($candidates)) {
                return Result::error('Нет соседней карты для удара');
            }

            $this->state->battle['pending_forced_strike_adjacent'] = [
                'owner' => $playerKey,
                'card_id' => (int) $pi['card_id'],
                'attacker_id' => $target->instanceId,
                'candidates' => $candidates,
                'effect' => $effect,
                'label' => (string) ($pi['label'] ?? 'Инстант'),
                'source' => (string) ($pi['source'] ?? 'turn_stack'),
                'list_key' => (string) ($pi['list_key'] ?? ''),
                'cost' => (int) ($pi['cost'] ?? 0),
                'payload' => (array) ($pi['payload'] ?? []),
            ];
            unset($this->state->battle['pending_instant_pick']);
            $this->state->bumpVersion();
            return Result::ok(['forced_strike_adjacent_pick_opened']);
        }

        $payload = (array) ($pi['payload'] ?? []);
        if (empty($payload)) {
            $payload = [
                'card_id' => (int) $pi['card_id'],
                'key' => (string) ($pi['list_key'] ?? ''),
                'label' => (string) ($pi['label'] ?? 'Инстант'),
                'target' => (string) ($pi['target'] ?? 'self'),
                'effect' => (array) ($pi['effect'] ?? []),
                'cost' => (int) ($pi['cost'] ?? 0),
            ];
        }

        $result = $this->pushTurnInstantEntry($playerKey, $payload, (int) $pi['card_id'], $target->instanceId);
        if (!$result->success) return $result;
        unset($this->state->battle['pending_instant_pick']);

        $this->state->bumpVersion();
        return Result::ok(['turn_instant_stacked']);
    }

    public function chooseForcedStrikeAdjacent(string $playerKey, Command $cmd): Result
    {
        $pending = $this->state->battle['pending_forced_strike_adjacent'] ?? null;
        if (!$pending) return Result::error('Нет ожидающего выбора');
        if (($pending['owner'] ?? null) !== $playerKey) return Result::error('Не ваш выбор');

        $targetId = (int) $cmd->get('target_id', 0);
        if (!in_array($targetId, (array) ($pending['candidates'] ?? []), true)) {
            return Result::error('Неверная цель');
        }

        $attacker = $this->state->getCard((int) ($pending['attacker_id'] ?? 0));
        $target = $this->state->getCard($targetId);
        if (!$attacker || !$target) return Result::error('Цель не найдена');
        if (!in_array($targetId, $this->findForcedStrikeAdjacentTargets($attacker), true)) {
            return Result::error('Цель больше недоступна');
        }

        $payload = (array) ($pending['payload'] ?? []);
        if (empty($payload)) {
            $payload = [
                'card_id' => (int) ($pending['card_id'] ?? 0),
                'key' => (string) ($pending['list_key'] ?? ''),
                'label' => (string) ($pending['label'] ?? 'Инстант'),
                'target' => 'enemy_open_creature',
                'effect' => (array) ($pending['effect'] ?? []),
                'cost' => (int) ($pending['cost'] ?? 0),
            ];
        }
        $payload['effect'] = (array) ($payload['effect'] ?? []);
        $payload['effect']['forced_target_id'] = $targetId;

        $result = $this->pushTurnInstantEntry(
            $playerKey,
            $payload,
            (int) ($pending['card_id'] ?? 0),
            $attacker->instanceId
        );
        if (!$result->success) return $result;

        unset($this->state->battle['pending_forced_strike_adjacent']);
        $this->state->bumpVersion();
        return Result::ok(['turn_instant_stacked']);
    }

    private function findAvailableTurnInstant(string $playerKey, int $cardId, string $key, string $phase): ?array
    {
        foreach ($this->getInstants($playerKey, $phase, 'turn') as $inst) {
            $payload = (array) ($inst['payload'] ?? []);
            if ((int) $inst['card_id'] !== $cardId) continue;
            if ((string) ($payload['key'] ?? '') !== $key) continue;
            return [
                'card_id' => $inst['card_id'],
                'ukid' => $inst['ukid'],
                'key' => $key,
                'label' => $inst['label'],
                'target' => $payload['target'] ?? 'self',
                'effect' => (array) ($payload['effect'] ?? []),
                'cost' => (int) ($payload['coins'] ?? 0),
                'uses_per_turn' => (int) ($payload['uses_per_turn'] ?? 1),
                'close_source' => $this->instantRequiresTap($payload),
            ];
        }
        return null;
    }

    public function declareTurnInstantFromTask(string $playerKey, array $subTask): Result
    {
        $payload = (array) ($subTask['payload'] ?? []);
        $cardId = (int) ($subTask['card_id'] ?? 0);
        $key = (string) ($payload['key'] ?? '');

        if (empty($this->state->battle['turn_instant_stack'])) {
            $this->openTurnStackWindow($playerKey, ['type' => 'turn_phase'], 'turn');
        }

        $found = $this->findAvailableTurnInstant($playerKey, $cardId, $key, 'turn');
        if (!$found) return Result::error('Инстант недоступен');

        if (($found['target'] ?? 'self') === 'self') {
            return $this->pushTurnInstantEntry($playerKey, $found, $cardId);
        }

        $this->state->battle['pending_instant_pick'] = [
            'owner'    => $playerKey,
            'card_id'  => $cardId,
            'target'   => $found['target'],
            'effect'   => $found['effect'],
            'label'    => $found['label'],
            'source'   => 'turn_stack',
            'list_key' => $key,
            'cost'     => (int) ($found['cost'] ?? 0),
            'payload'  => $found,
        ];
        return Result::ok(['instant_pick_opened']);
    }

    private function pushTurnInstantEntry(string $playerKey, array $inst, int $cardId, ?int $targetId = null): Result
    {
        $stack = $this->state->battle['turn_instant_stack'] ?? null;
        if (!$stack || ($stack['state'] ?? '') !== 'ordering') {
            return Result::error('Нет окна инстантов');
        }
        if (($stack['priority'] ?? null) !== $playerKey) {
            return Result::error('Не ваш приоритет');
        }
        $participants = $this->turnParticipants($stack);
        if (!in_array($playerKey, $participants, true)) {
            return Result::error('Игрок не участвует в этом окне инстантов');
        }

        $card = $this->state->getCard($cardId);
        if (!$card || $card->owner !== $playerKey) return Result::error('Карта не ваша');
        if (!empty($card->flags['in_stack'])) return Result::error('Карта уже в стеке');

        $key = (string) ($inst['key'] ?? '');
        $limit = (int) ($inst['uses_per_turn'] ?? 1);
        if ($limit > 0 && (int) ($card->flags['instant_uses_this_turn'][$key] ?? 0) >= $limit) {
            return Result::error('Уже использовано в этот ход');
        }

        $cost = (int) ($inst['cost'] ?? 0);
        if ($cost > 0 && $card->coins < $cost) return Result::error('Не хватает монет');
        if (!empty($inst['close_source']) && $card->closed) return Result::error('Карта закрыта');
        if ($cost > 0) {
            $card->coins -= $cost;
            $this->engine->syncCoinBonus($card);
        }

        $targetId = $targetId ?? $card->instanceId;
        $sequence = (int) ($this->state->battle['turn_instant_stack']['next_sequence'] ?? 0);
        $this->state->battle['turn_instant_stack']['next_sequence'] = $sequence + 1;
        $this->state->battle['turn_instant_stack']['stack'][] = [
            'card_id' => $card->instanceId,
            'player' => $playerKey,
            'instant_key' => $key,
            'label' => (string) ($inst['label'] ?? 'Инстант'),
            'effect' => (array) ($inst['effect'] ?? []),
            'target_id' => $targetId,
            'sequence' => $sequence,
            'cost' => $cost,
            'close_source' => !empty($inst['close_source']),
        ];

        $card->flags['in_stack'] = true;
        $this->markInstantUsed($card, $key);
        $this->state->battle['turn_instant_stack']['passed'] = [];

        return Result::ok(['turn_instant_stacked']);
    }

    private function resolveTurnStack(): void
    {
        $stackState = &$this->state->battle['turn_instant_stack'];
        $stackState['state'] = 'resolving';
        $summary = [];
        $items = array_reverse((array) ($stackState['stack'] ?? []));
        $continuation = (array) ($stackState['continuation'] ?? ['type' => 'manual']);
        $phase = (string) ($stackState['phase'] ?? 'turn');

        foreach ($items as $item) {
            $source = $this->state->getCard((int) ($item['card_id'] ?? 0));
            $target = $this->state->getCard((int) ($item['target_id'] ?? 0));
            $label = (string) ($item['label'] ?? 'Инстант');
            $applied = false;
            $reason = '';
            $before = $target ? $this->captureTurnTargetSnapshot($target) : null;
            $effectResult = [];

            if (!$source) {
                $reason = 'источник не найден';
            } elseif (!$target || $target->dying || $target->hp <= 0) {
                $reason = 'цель не найдена';
            } elseif (empty($item['effect'])) {
                $reason = 'нет эффекта';
            } else {
                $this->engine->applyInstantEffect(
                    $this->state,
                    (array) $item['effect'],
                    $source,
                    $target,
                    (string) $item['player']
                );
                $after = $this->captureTurnTargetSnapshot($target);
                $effectResult = $this->buildTurnEffectResult((array) $item['effect'], $before, $after, $target);
                $effectType = (string) (($item['effect']['type'] ?? '') ?: '');
                $applied = $effectType === 'forced_strike_adjacent'
                    ? !empty($this->state->battle['strike'])
                    : $this->turnEffectChanged($before, $after);
                if (!$applied) {
                    $reason = 'эффект ничего не изменил';
                }
            }

            if ($source && !empty($item['close_source'])) {
                $source->closed = true;
            }
            if ($source) {
                unset($source->flags['in_stack']);
            }
            $summary[] = [
                'label' => $label,
                'card_ukid' => $source ? $source->ukid : '',
                'player' => $item['player'] ?? null,
                'applied' => $applied,
                'reason' => $reason,
                'sequence' => (int) ($item['sequence'] ?? 0),
                'card_id' => $source ? $source->instanceId : (int) ($item['card_id'] ?? 0),
                'target_id' => $target ? $target->instanceId : (int) ($item['target_id'] ?? 0),
                'effect_type' => (string) (($item['effect']['type'] ?? '') ?: ''),
                'result' => $effectResult,
            ];
        }

        $this->engine->finalizeDying($this->state);
        unset($this->state->battle['turn_instant_stack']);
        if (empty($items)) {
            $this->continueAfterTurnStack($continuation);
            return;
        }

        $this->state->battle['turn_instant_result'] = [
            'summary' => $summary,
            'continuation' => $continuation,
            'phase' => $phase,
        ];
        $this->state->battle['instant_result'] = ['summary' => $summary];
    }

    public function ackTurnInstantResult(string $playerKey): Result
    {
        $pending = $this->state->battle['turn_instant_result'] ?? null;
        if (!$pending) {
            return Result::error('Нет результата инстантов');
        }

        $continuation = (array) ($pending['continuation'] ?? ['type' => 'manual']);
        unset($this->state->battle['turn_instant_result']);
        unset($this->state->battle['instant_result']);
        $this->continueAfterTurnStack($continuation);
        $this->state->bumpVersion();
        return Result::ok(['turn_instant_result_ack']);
    }

    private function continueAfterTurnStack(array $continuation): void
    {
        $type = (string) ($continuation['type'] ?? 'manual');
        if ($type === 'turn_phase') {
            $this->completeTurnPhaseInstantSubtask();
            if (!empty($this->state->battle['turn_phase'])) {
                (new TurnPhaseProcessor($this->state, $this->engine))->resume();
            }
            return;
        }
        if ($type === 'strike_before') {
            $this->resumeAfterWindow();
            return;
        }
        if ($type === 'strike_after') {
            $this->state->battle['strike'] = null;
            $this->engine->finalizeDying($this->state);
            return;
        }
    }

    private function autoPassTurnPriorityIfNoOptions(): void
    {
        while (true) {
            $stack = $this->state->battle['turn_instant_stack'] ?? null;
            if (!$stack || ($stack['state'] ?? '') !== 'ordering') return;

            $priority = (string) ($stack['priority'] ?? '');
            if ($priority === '') return;
            $participants = $this->turnParticipants($stack);
            if (!in_array($priority, $participants, true)) return;

            $phase = (string) ($stack['phase'] ?? 'turn');
            if (!empty($this->getInstants($priority, $phase, 'turn'))) {
                return;
            }

            $passed = (array) ($stack['passed'] ?? []);
            if (!in_array($priority, $passed, true)) {
                $passed[] = $priority;
            }
            $this->state->battle['turn_instant_stack']['passed'] = $passed;

            if ($this->allParticipantsPassed($participants, $passed)) {
                $this->resolveTurnStack();
                return;
            }

            $newPriority = $this->nextUnpassedParticipant($participants, $priority, $passed);
            if ($newPriority === null) {
                $this->resolveTurnStack();
                return;
            }
            $this->state->battle['turn_instant_stack']['priority'] = $newPriority;
        }
    }

    private function captureTurnTargetSnapshot(CardInstance $target): array
    {
        return [
            'hp' => $target->hp,
            'hp_max' => $target->hpMax,
            'closed' => $target->closed,
            'dying' => $target->dying,
            'zone' => $target->zone,
            'damage_taken_this_turn' => (int) ($target->flags['damage_taken_this_turn'] ?? 0),
            'markers' => $target->markers,
        ];
    }

    private function turnEffectChanged(?array $before, array $after): bool
    {
        if ($before === null) return false;
        return $before !== $after;
    }

    private function buildTurnEffectResult(array $effect, ?array $before, array $after, CardInstance $target): array
    {
        if ($before === null) return [];

        $result = [
            'target_id' => $target->instanceId,
            'hp_before' => (int) $before['hp'],
            'hp_after' => (int) $after['hp'],
            'closed_before' => (bool) $before['closed'],
            'closed_after' => (bool) $after['closed'],
        ];

        $type = (string) ($effect['type'] ?? '');
        if ($type === 'heal_turn_wounds') {
            $result['turn_wounds_before'] = (int) $before['damage_taken_this_turn'];
        }
        if ($type === 'marker') {
            $result['markers_before'] = $before['markers'];
            $result['markers_after'] = $after['markers'];
        }
        if ($type === 'forced_strike_adjacent') {
            $result['forced_attacker_id'] = $target->instanceId;
            $result['forced_target_id'] = (int) ($effect['forced_target_id'] ?? 0);
        }

        return $result;
    }

    private function completeTurnPhaseInstantSubtask(): void
    {
        if (empty($this->state->battle['turn_phase']['sub']['pending_id'])) return;
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

    private function markInstantUsed(CardInstance $card, string $key): void
    {
        if ($key === '') return;
        if (!isset($card->flags['instant_uses_this_turn']) || !is_array($card->flags['instant_uses_this_turn'])) {
            $card->flags['instant_uses_this_turn'] = [];
        }
        $card->flags['instant_uses_this_turn'][$key] =
            ((int) ($card->flags['instant_uses_this_turn'][$key] ?? 0)) + 1;
    }

    private function instantRequiresTap(array $instant): bool
    {
        if (array_key_exists('tap_source', $instant)) {
            return (bool) $instant['tap_source'];
        }
        if (array_key_exists('close_source', $instant)) {
            return (bool) $instant['close_source'];
        }
        if (!empty($instant['no_close'])) {
            return false;
        }
        return true;
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
