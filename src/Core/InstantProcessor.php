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

    private const COMBAT_WINDOW_DICE = 'dice';
    private const COMBAT_WINDOW_MAIN = 'main';
    private const COMBAT_WINDOW_WOUNDS = 'wounds';

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

    private function combatSourceAvailable(CardInstance $card, array $instant, string $type, string $phase, ?array $strike): bool
    {
        if (!$strike) return true;
        $attacker = (int) ($strike['attacker_id'] ?? 0);
        $defender = (int) (($strike['defender_id'] ?? 0) ?: ($strike['target_id'] ?? 0));
        $redirect = ($instant['effect']['type'] ?? '') === 'redirect_strike';
        if ($card->instanceId === $attacker) {
            return $type === 'combat' && $redirect && empty($strike['matriarch_order']);
        }
        if ($card->instanceId !== $defender) return true;
        // A turn instant resolves before engagement and pays its usual closure.
        // During combat only redirection (Mage's trick) is an exception.
        return $type === 'turn' ? $phase === 'before' : $redirect;
    }

    /**
     * Возвращает список доступных инстантов для игрока.
     * $phase: 'before' | 'combat' | 'after'  (для combat-окна)
     * $type:  'turn'                          (для turn-потока)
     */
    public function getInstants(string $ownerKey, string $phase = 'before', string $type = 'turn'): array
    {
        return $this->availableInstants($ownerKey, $phase, $type);
    }

    /** Lifecycle trigger only, not admission. A dying suppressor may release a living source. */
    public function hasCombatWoundsSource(): bool
    {
        foreach ($this->state->cards as $card) {
            if ($card->hp <= 0 || $card->dying || !$card->revealed
                || !in_array($card->zone,[CardInstance::ZONE_FIELD,CardInstance::ZONE_FLYING],true)) continue;
            foreach ($card->prop['instants']??[] as $inst) {
                if (($inst['trigger']??'')==='combat' && $this->instantCombatPhase($inst)==='wounds') return true;
            }
        }
        return false;
    }

    /** Source legality is shared with the special defender-redirection route. No priority is granted here. */
    public function getSourceInstants(CardInstance $card, string $phase, string $type, ?string $ownPending = null): array
    {
        if (!in_array($type, ['turn', 'combat'], true)) return [];
        if (!in_array($card->zone, [CardInstance::ZONE_FIELD, CardInstance::ZONE_FLYING], true)
            || $card->dying || $card->hp <= 0 || !$card->revealed || !empty($card->flags['in_stack'])
            || $this->isCardInPending($card, $ownPending)) return [];
        $strike = $this->state->battle['strike']
            ?? ($this->state->battle['turn_instant_stack']['continuation']['combat_origin_strike'] ?? null);
        // Combat has its existing effective-closure rules; turn uses the ability's tap contract.
        if ($type === 'combat' && CardStats::combatClosed($this->state, $card)) return [];
        $attacker = $this->state->getCard((int) ($strike['attacker_id'] ?? 0));
        if ($type === 'turn' && $attacker?->owner === $card->owner && in_array($phase, ['before', 'after'], true)) return [];
        $result = [];
        foreach ($card->effectiveProp()['instants'] ?? [] as $inst) {
            if (($inst['trigger'] ?? '') !== $type) continue;
            if (!$this->combatSourceAvailable($card, $inst, $type, $phase, $strike)) continue;
            if (!empty($strike['matriarch_order']) && ($inst['effect']['type'] ?? '') === 'redirect_strike') continue;
            if ($this->instantRequiresTap($inst) && $card->closed
                && ($type === 'turn' || !empty($strike['matriarch_order']))) continue;
            $key = (string) ($inst['key'] ?? ($inst['name'] ?? 'instant'));
            $limit = (int) ($inst['uses_per_turn'] ?? 1);
            if ($limit > 0 && (int) ($card->flags['instant_uses_this_turn'][$key] ?? 0) >= $limit) continue;
            if ($card->coins < max(0, (int) ($inst['coins'] ?? 0))) continue;
            if (!CardStats::checkCondition($inst['condition'] ?? null, $this->state, $card)) continue;
            if ($type === 'combat' && (!in_array($this->instantCombatPhase($inst), self::COMBAT_PHASE_ORDER, true)
                || ($phase === 'combat' && !$this->combatInstantAllowedInCurrentWindow($inst)))) continue;
            if ($type === 'combat' && ($inst['effect']['type'] ?? '') === 'redistribute_wounds'
                && (empty($strike['damage_applied'])
                    || !(new WoundTransferProcessor($this->state,$this->engine))->canRedistributeCombatWounds($card))) continue;
            $result[] = $inst;
        }
        return $result;
    }

    private function availableInstants(string $ownerKey, string $phase, string $type, ?string $ownPending = null): array
    {
        $result = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $ownerKey) continue;
            foreach ($this->getSourceInstants($card, $phase, $type, $ownPending) as $inst) {
                if ($type === 'combat'
                    && ($inst['target'] ?? 'self') === 'adjacent_ally'
                    && (($inst['effect']['type'] ?? '') === 'redirect_strike')) {
                    continue;
                }
                $target = (string) ($inst['target'] ?? 'self');
                $condition = $inst['effect']['condition'] ?? null;
                if ($target === 'self'
                    ? !$this->isLegalInstantTarget($card, $card, $target, $condition)
                    : !$this->hasAnyInstantTarget($card, $target, $condition)) continue;

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

    private function hasAnyInstantTarget(CardInstance $source, string $target, mixed $condition): bool
    {
        foreach ($this->state->cards as $candidate) {
            if ($this->isLegalInstantTarget($source, $candidate, $target, $condition)) {
                return true;
            }
        }
        return false;
    }

    /** Pure target predicate. Source availability and command priority remain separate checks. */
    public function isLegalInstantTarget(
        CardInstance $source,
        CardInstance $candidate,
        string $target,
        mixed $condition
    ): bool {
        if ($candidate->zone !== CardInstance::ZONE_FIELD
            && $candidate->zone !== CardInstance::ZONE_FLYING) return false;
        if ($candidate->dying || $candidate->hp <= 0) return false;

        if ($target === 'self' && $candidate->instanceId !== $source->instanceId) return false;
        if ($target === 'enemy' && $candidate->owner === $source->owner) return false;
        if ($target === 'ally' && $candidate->owner !== $source->owner) return false;
        if ($target === 'adjacent_ally'
            && !in_array($candidate->instanceId, $this->findAdjacentAllyTargets($source->owner), true)) return false;
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

    public function openWindow(
        string $phase,
        string $priorityKey,
        ?array $allowedPlayers = null,
        ?string $combatWindow = null
    ): bool
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike) return false;

        $attacker = $this->state->getCard($strike['attacker_id']);
        if (!$attacker) return false;

        $attackerKey = $attacker->owner;
        $participants = $this->instantParticipants($strike, $attackerKey, $allowedPlayers);

        if ($phase !== 'combat') {
            $opened = $this->openTurnStackWindow($priorityKey, [
                'type' => $phase === 'after' ? 'strike_after' : 'strike_before',
            ], $phase, $participants);
            if ($opened && !empty($strike['continuation_frame'])) {
                $this->state->battle['turn_instant_stack']['continuation']['combat_origin_strike'] = $strike;
                CombatContinuation::suspend($this->state, $strike);
                $this->state->battle['strike'] = null;
            }
            return $opened;
        }

        $combatWindow = in_array($combatWindow, [self::COMBAT_WINDOW_DICE, self::COMBAT_WINDOW_MAIN, self::COMBAT_WINDOW_WOUNDS], true)
            ? $combatWindow
            : null;
        $hadPreviousWindow = array_key_exists('combat_instant_window', $this->state->battle['strike']);
        $previousWindow = $this->state->battle['strike']['combat_instant_window'] ?? null;
        if ($combatWindow !== null) {
            $this->state->battle['strike']['combat_instant_window'] = $combatWindow;
        } else {
            unset($this->state->battle['strike']['combat_instant_window']);
        }

        $type = 'combat';
        $hasByPlayer = [];
        foreach ($participants as $player) {
            $hasByPlayer[$player] = !empty($this->getInstants($player, $phase, $type));
        }

        if (!in_array(true, $hasByPlayer, true)) {
            if ($hadPreviousWindow) {
                $this->state->battle['strike']['combat_instant_window'] = $previousWindow;
            } else {
                unset($this->state->battle['strike']['combat_instant_window']);
            }
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
            if ($hadPreviousWindow) {
                $this->state->battle['strike']['combat_instant_window'] = $previousWindow;
            } else {
                unset($this->state->battle['strike']['combat_instant_window']);
            }
            return false;
        }

        $this->state->battle['strike']['state']            = 'waiting_instant';
        $this->state->battle['strike']['instant_phase']    = $phase;
        $this->state->battle['strike']['instant_priority'] = $priority;
        $this->state->battle['strike']['instant_passed']   = $passed;
        $this->state->battle['strike']['instant_participants'] = $participants;
        $this->state->battle['strike']['instant_played']   = [];
        $this->state->battle['strike']['instant_stack']    = [];
        unset($this->state->battle['strike']['instant_resolution']);
        return true;
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
        $inst = $this->findAvailableInstant($playerKey, $cardId, $key, $phase, $wantedType);
        if (!$inst) return Result::error('Инстант недоступен');

        $target = $inst['target'] ?? 'self';
        $effect = $inst['effect'] ?? [];
        $combatPhase = $this->instantCombatPhase($inst);
        if ($wantedType === 'combat' && !in_array($combatPhase, self::COMBAT_PHASE_ORDER, true)) {
            return Result::error('Не задана фаза combat-инстанта');
        }
        if ($wantedType === 'combat' && !$this->combatInstantAllowedInCurrentWindow($inst)) {
            return Result::error('Инстант недоступен в текущем окне');
        }
        if ($wantedType === 'combat'
            && $target === 'adjacent_ally'
            && (($effect['type'] ?? '') === 'redirect_strike')) {
            return Result::error('Перенаправление выбирается в окне защитника');
        }

        // Self — сразу в стек
        if ($target === 'self') {
            $this->payCombatInstant($card, $inst);
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

    /** Commit only after source and concrete target validation, never at picker opening. */
    private function payCombatInstant(CardInstance $card, array $inst): void
    {
        $cost = max(0, (int) ($inst['coins'] ?? 0));
        if ($cost > 0) {
            $card->coins -= $cost;
            $this->engine->syncCoinBonus($card);
        }
        $this->markInstantUsed($card, (string) ($inst['key'] ?? ''));
    }

    public function chooseTarget(string $playerKey, Command $cmd): Result
    {
        $pc = $this->state->battle['pending_combat_pick'] ?? null;
        if (!$pc) return Result::error('Нет ожидающего выбора');
        if ($pc['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $target = $this->state->getCard((int) $cmd->get('target_id', 0));
        if (!$target) return Result::error('Цель не найдена');

        $source = $this->state->getCard($pc['card_id']);
        if (!$source) return Result::error('Источник не найден');
        if (EffectiveFeatures::suppressed($source)) {
            unset($this->state->battle['pending_combat_pick']);
            $this->state->bumpVersion();
            return Result::ok(['combat_pick_suppressed']);
        }
        $strike = $this->state->battle['strike'] ?? [];
        if (($strike['state'] ?? null) !== 'waiting_instant' || ($strike['instant_priority'] ?? null) !== $playerKey
            || !in_array($playerKey, $this->combatParticipants($strike), true)) return Result::error('Не ваш приоритет');
        $phase = (string) ($strike['instant_phase'] ?? 'combat');
        $type = $phase === 'combat' ? 'combat' : 'turn';
        $inst = $this->findAvailableInstant($playerKey, $source->instanceId, (string) ($pc['instant_key'] ?? ''), $phase, $type, 'pending_combat_pick');
        if (!$inst) return Result::error('Инстант недоступен');
        if (!$this->isLegalInstantTarget($source, $target, (string) ($inst['target'] ?? 'self'), $inst['effect']['condition'] ?? null)) {
            return Result::error('Цель недоступна');
        }

        $this->payCombatInstant($source, $inst);
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
            if ($this->completeEmptyCombatWindow()) {
                $this->state->bumpVersion();
                return Result::ok(['instant_window_closed']);
            }
            $this->resolveStack();
            $this->state->bumpVersion();
            return Result::ok(['instant_stack_resolved']);
        }

        $newPriority = $this->nextUnpassedParticipant($participants, $playerKey, $passed);
        if ($newPriority === null) {
            if ($this->completeEmptyCombatWindow()) {
                $this->state->bumpVersion();
                return Result::ok(['instant_window_closed']);
            }
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
        $summary = $strike['healing_summary'] ?? [];
        unset($strike['healing_summary']);

        if ($phase === 'combat') {
            if (($strike['combat_instant_window'] ?? '') === self::COMBAT_WINDOW_WOUNDS) {
                $strike['wounds_after_result_ack'] = true;
                $this->resolveCombatPhases(true);
            } else $this->resolveCombatPhases();
            return;
        }

        $stack = $strike['instant_stack'] ?? [];
        $stack = array_reverse($stack);

        foreach ($stack as $index => $item) {
            $source = $this->state->getCard($item['card_id']);
            $target = $this->state->getCard($item['target_id']);

            $effect = (array) ($item['effect'] ?? []);
            $label  = $item['label'] ?? 'Инстант';

            if (isset($item['_card_heal_done'])) {
                $summary[]=['label'=>$label,'card_ukid'=>$source?->ukid??'', 'player'=>$item['player'],
                    'applied'=>$item['_card_heal_done']['restored']>0||$item['_card_heal_done']['replaced'],
                    'reason'=>$item['_card_heal_done']['message']??'','card_heal'=>$item['_card_heal_done']];
            } elseif ($source && EffectiveFeatures::suppressed($source)) {
                $summary[] = [
                    'label' => $label, 'card_ukid' => $source->ukid,
                    'player' => $item['player'] ?? null,
                    'applied' => false, 'reason' => 'Потеря памяти',
                ];
            } elseif (!$target) {
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
                    $effect + ['_heal_return_to'=>['handler'=>'instant','kind'=>'legacy','item'=>$item,
                        'remaining'=>array_slice($stack,$index+1),'summary'=>$summary]],
                    $source,
                    $target,
                    $item['player']
                );
                if (CardHealing::waiting($this->state)) return;
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
                if (empty($strike['matriarch_order']) || $this->combatItemRequiresTap($item)) $source->closed = true;
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
            if (!empty($strike['coin_post_combat_effects'])) {
                $strike['instant_phase'] = 'after';
                (new StrikeResolver($this->state, $this->engine))->continueAfterStrikeResultAck($strike);
                return;
            }
            (new CoinAbilities($this->state, $this->engine))->completeCombat($strike);
            CombatContinuation::localDone($this->state, $this->state->battle['strike'] ?? []);
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
                InformationalWaitManager::publish($this->state);
                $strike['state'] = 'results';
            }
            return;
        }

        if ($phase === 'before') {
            $attacker = $this->state->getCard($strike['attacker_id']);

            $cancelled = false;
            if (!$attacker) $cancelled = true;
            elseif ($attacker->dying || $attacker->hp <= 0) $cancelled = true;
            elseif (CardStats::combatClosed($this->state, $attacker)) $cancelled = true;
            elseif ($attacker->zone !== CardInstance::ZONE_FIELD
                && $attacker->zone !== CardInstance::ZONE_FLYING) $cancelled = true;

            if ($cancelled) {
                CombatContinuation::localDone($this->state, $this->state->battle['strike'] ?? []);
                $this->state->battle['strike'] = null;
                $this->engine->finalizeDying($this->state);
                return;
            }
        }

        $this->continueAfterStrikeBeforeWindow();
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
                if (empty($strike['matriarch_order']) || $this->combatItemRequiresTap($item)) $result['source']->closed = true;
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
        $window = (string) ($this->state->battle['strike']['combat_instant_window'] ?? '');

        foreach (self::COMBAT_PHASE_ORDER as $phase) {
            if ($woundsOnly && $phase !== 'wounds') continue;
            if (!$woundsOnly && $phase === 'wounds') continue;
            if (!$woundsOnly && $window === self::COMBAT_WINDOW_DICE && $phase !== 'dice') continue;
            if (!$woundsOnly && $window === self::COMBAT_WINDOW_MAIN && $phase === 'dice') continue;

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
            'mode' => $woundsOnly ? 'wounds' : ($window !== '' ? $window : 'combat'),
            'queue' => $queue,
        ];
    }

    private function resolveCombatItem(array $item): array|string
    {
        $source = $this->state->getCard((int) ($item['card_id'] ?? 0));
        $target = $this->state->getCard((int) ($item['target_id'] ?? 0));
        $effect = (array) ($item['effect'] ?? []);
        $type = (string) ($effect['type'] ?? '');
        $effectTarget = $type === 'damage_cap' ? null : $target;

        if (!$source) {
            return ['source' => null, 'applied' => false, 'reason' => 'источник не найден', 'result' => []];
        }
        if (EffectiveFeatures::suppressed($source)) {
            return ['source' => $source, 'applied' => false, 'reason' => 'Потеря памяти', 'result' => []];
        }
        if (!$this->combatSourceAvailable($source, ['effect' => $effect], 'combat', 'combat', $this->state->battle['strike'] ?? null)) {
            return ['source' => $source, 'applied' => false, 'reason' => 'существо занято сражением', 'result' => []];
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

        if(in_array($type,['heal','heal_turn_wounds'],true) && $target) {
            $value=$type==='heal_turn_wounds'?(int)($target->flags['damage_taken_this_turn']??0):(int)($effect['value']??1);
            $r=(new CardHealing($this->state,$this->engine))->begin($source,$target,$value,$type,['handler'=>'instant','kind'=>'combat']);
            if($r===null)return 'paused';
            return ['source'=>$source,'applied'=>$r['restored']>0||$r['replaced'],'reason'=>$r['message']??'','result'=>['card_heal'=>$r]];
        }

        $before = $this->captureCombatSnapshot();
        $effect['_resolution_type'] = (string)(($item['action_type'] ?? '') ?: 'instant');
        $reason = $this->engine->applyCombatEffect(
            $this->state,
            $effect,
            $source,
            (string) $item['player'],
            $item['choice'] ?? null,
            $effectTarget
        );
        if ($reason === null && !in_array($type, ['damage_cap','damage_on_dice'], true)) ActionResolution::effect($this->state, $source, (string)(($item['action_type'] ?? '') ?: 'instant'), 'instant', ['effect_type'=>$type]);
        if(CardHealing::waiting($this->state)) {
            CardHealing::defer($this->state,['handler'=>'instant','kind'=>'combat_after_effect','before'=>$before,'effect'=>$effect,'owner'=>$item['player']]);
            return 'paused';
        }
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
        if (EffectiveFeatures::suppressed($source)) {
            unset($this->state->battle['pending_dice_choice']);
            // Use the existing failed-item completion, including its normal
            // tap commitment. Suppression never opens the card or refunds costs.
            $this->completePausedCombatItem(false, 'Потеря памяти');
            $this->state->bumpVersion();
            return Result::ok(['dice_choice_suppressed']);
        }

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
        if (empty($strike['matriarch_order']) || $this->combatItemRequiresTap($item)) $source->closed = true;
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

    public function completePausedCombatItem(bool $applied, string $reason = '', array $effectResult = [], bool $closeSource = true): void
    {
        $queue = $this->state->battle['strike']['instant_resolution']['queue'] ?? [];
        if (empty($queue)) return;

        $item = $queue[0];
        $source = $this->state->getCard((int) ($item['card_id'] ?? 0));
        array_shift($this->state->battle['strike']['instant_resolution']['queue']);
        if ($source) {
            if ($closeSource && (empty($this->state->battle['strike']['matriarch_order']) || $this->combatItemRequiresTap($item))) $source->closed = true;
            unset($source->flags['in_stack']);
        }
        if ($applied && $source && ($item['effect']['type'] ?? '') !== 'redistribute_wounds')
            ActionResolution::effect($this->state, $source, (string)(($item['action_type'] ?? '') ?: 'instant'), 'instant', ['effect_type'=>$item['effect']['type'] ?? '']);
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
                $strike['state'] = 'results';
                unset($strike['combat_instant_window']);
                (new StrikeResolver($this->state, $this->engine))
                    ->continueAfterStrikeResultAck($strikeForContinuation);
                return;
            }
            if (($strike['state'] ?? '') !== 'waiting_auto_target') {
                InformationalWaitManager::publish($this->state);
                $strike['state'] = 'results';
            }
            return;
        }

        if ($mode === self::COMBAT_WINDOW_DICE) {
            unset(
                $strike['instant_priority'],
                $strike['instant_passed'],
                $strike['instant_stack'],
                $strike['instant_next_sequence'],
                $strike['instant_resolution'],
                $strike['instant_phase'],
                $strike['combat_instant_window']
            );
            (new StrikeResolver($this->state, $this->engine))->recalcTable();
            $strike['instant_result_stage'] = self::COMBAT_WINDOW_DICE;
            InformationalWaitManager::publish($this->state);
            $strike['state'] = 'results';
            $strike['confirmed'] = [];
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
            unset($strike['combat_instant_window']);
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
            InformationalWaitManager::publish($this->state);
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
        if ($applied && $source) {
            $strike=$this->state->battle['strike']??[];
            $this->state->historyRecorder?->liveEvent($this->state,'ability.applied',[
                'source_id'=>$source->instanceId,'ability'=>$item['label']??'Инстант',
                '_private_owner'=>$source->owner],array_values(array_unique(array_filter([
                    $source->instanceId,$strike['attacker_id']??null,($strike['defender_id']??null)?:($strike['target_id']??null)]))));
        }
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

    private function combatInstantAllowedInCurrentWindow(array $inst): bool
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike) {
            return true;
        }

        $window = (string) ($strike['combat_instant_window'] ?? '');
        if ($window === '') {
            return $this->instantCombatPhase($inst) !== 'wounds';
        }

        return $this->combatInstantAllowedInWindow($inst, $window);
    }

    private function combatInstantAllowedInWindow(array $inst, string $window): bool
    {
        $combatPhase = $this->instantCombatPhase($inst);
        if ($window === self::COMBAT_WINDOW_DICE) {
            return $combatPhase === 'dice';
        }
        if ($window === self::COMBAT_WINDOW_MAIN) {
            return $combatPhase !== 'dice' && $combatPhase !== 'wounds';
        }
        if ($window === self::COMBAT_WINDOW_WOUNDS) return $combatPhase === 'wounds';
        if ($combatPhase === 'wounds') return false;
        return true;
    }

    private function completeEmptyCombatWindow(): bool
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || ($strike['instant_phase'] ?? null) !== 'combat') {
            return false;
        }
        if (!empty($strike['instant_stack'])) {
            return false;
        }

        $window = (string) ($strike['combat_instant_window'] ?? self::COMBAT_WINDOW_MAIN);
        unset(
            $this->state->battle['strike']['instant_priority'],
            $this->state->battle['strike']['instant_passed'],
            $this->state->battle['strike']['instant_participants'],
            $this->state->battle['strike']['instant_played'],
            $this->state->battle['strike']['instant_stack'],
            $this->state->battle['strike']['instant_next_sequence'],
            $this->state->battle['strike']['instant_phase'],
            $this->state->battle['strike']['combat_instant_window']
        );

        if ($window === self::COMBAT_WINDOW_DICE) {
            $attacker = $this->state->getCard((int) ($strike['attacker_id'] ?? 0));
            $attackerKey = $attacker ? $attacker->owner : '';
            if ($attackerKey !== '' && $this->openWindow('combat', $attackerKey, null, self::COMBAT_WINDOW_MAIN)) {
                return true;
            }
        }

        if ($window === self::COMBAT_WINDOW_WOUNDS) {
            $this->state->battle['strike']['state'] = 'results';
            (new StrikeResolver($this->state,$this->engine))->continueAfterStrikeResultAck($this->state->battle['strike']);
            return true;
        }

        $this->continueAfterCombatMainWindow();
        return true;
    }

    public function continueAfterCombatDiceResult(): void
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike || ($strike['instant_result_stage'] ?? '') !== self::COMBAT_WINDOW_DICE) {
            return;
        }

        unset($this->state->battle['strike']['instant_result_stage']);
        unset($this->state->battle['strike']['instant_summary']);
        $this->state->battle['strike']['confirmed'] = [];

        $attacker = $this->state->getCard((int) ($strike['attacker_id'] ?? 0));
        $attackerKey = $attacker ? $attacker->owner : '';
        if ($attackerKey !== '' && $this->openWindow('combat', $attackerKey, null, self::COMBAT_WINDOW_MAIN)) {
            return;
        }

        $this->continueAfterCombatMainWindow();
    }

    private function continueAfterCombatMainWindow(): void
    {
        $strike = &$this->state->battle['strike'];
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
            InformationalWaitManager::publish($this->state);
            $strike['state'] = 'results';
        }
    }

    private function findAdjacentAllyTargets(string $playerKey): array
    {
        $strike = $this->state->battle['strike'] ?? null;
        if (!$strike) return [];

        $currentTarget = $this->state->getCard((int) ($strike['target_id'] ?? 0));
        if (!$currentTarget) return [];

        return ZoneManager::adjacentFieldAllyIds($this->state, $currentTarget, $playerKey);
    }

    public function continueAfterStrikeBeforeWindow(): void
    {
        if (empty($this->state->battle['strike'])) {
            return;
        }

        $strike = &$this->state->battle['strike'];

        if (!$this->strikeCanContinueAfterBeforeWindow($strike)) {
            if (isset($strike[ActionResolution::ID])) ActionResolution::resolve($this->state, $strike, 'cancelled', ['hit'=>false]);
            CombatContinuation::localDone($this->state, $this->state->battle['strike'] ?? []);
            $this->state->battle['strike'] = null;
            $this->engine->finalizeDying($this->state);
            return;
        }

        if (!empty($strike['redirect_candidates'])) {
            $strike['state'] = 'waiting_redirect';
            return;
        }
        $attacker = $this->state->getCard((int) ($strike['attacker_id'] ?? 0));
        $defenderKey = $attacker ? $this->state->getOpponentKey($attacker->owner) : null;
        $mageOptions = $defenderKey !== null
            ? (new StrikeResolver($this->state, $this->engine))->getMageRedirectOptions($defenderKey)
            : [];

        if (!empty($strike['defenders']) || !empty($mageOptions)) {
            $strike['state'] = 'waiting_defender';
            return;
        }
        (new StrikeResolver($this->state, $this->engine))->resolve();
    }

    private function strikeCanContinueAfterBeforeWindow(array $strike): bool
    {
        if (empty($strike['attacker_id']) && empty($strike['target_id'])) {
            return true;
        }

        $attacker = $this->state->getCard((int) ($strike['attacker_id'] ?? 0));
        $target = $this->state->getCard((int) ($strike['target_id'] ?? 0));
        if (!$this->isLiveCombatant($attacker) || !$this->isLiveCombatant($target)) {
            return false;
        }
        return !CardStats::combatClosed($this->state, $attacker);
    }

    private function isLiveCombatant(?CardInstance $card): bool
    {
        if (!$card) return false;
        if ($card->dying || $card->hp <= 0) return false;
        return $card->zone === CardInstance::ZONE_FIELD || $card->zone === CardInstance::ZONE_FLYING;
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
        $stack = $this->state->battle['turn_instant_stack'] ?? [];
        if (($stack['state'] ?? null) !== 'ordering' || ($stack['priority'] ?? null) !== $playerKey
            || !in_array($playerKey, $this->turnParticipants($stack), true)) return Result::error('Не ваш приоритет');

        $target = $this->state->getCard((int) $cmd->get('target_id', 0));
        if (!$target) return Result::error('Цель не найдена');

        $source = $this->state->getCard((int) $pi['card_id']);
        if (!$source) return Result::error('Источник не найден');
        if (EffectiveFeatures::suppressed($source)) {
            unset($this->state->battle['pending_instant_pick']);
            $this->state->bumpVersion();
            return Result::ok(['instant_pick_suppressed']);
        }
        $phase = (string) ($this->state->battle['turn_instant_stack']['phase'] ?? 'turn');
        $available = $this->findAvailableTurnInstant($playerKey, $source->instanceId, (string) ($pi['list_key'] ?? ''), $phase, 'pending_instant_pick');
        if (!$available) return Result::error('Инстант недоступен');
        if (!$this->isLegalInstantTarget($source, $target, (string) $available['target'], $available['effect']['condition'] ?? null)) {
            return Result::error('Цель недоступна');
        }

        $effect = (array) ($available['effect'] ?? []);
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

        $result = $this->pushTurnInstantEntry($playerKey, $payload, (int) $pi['card_id'], $target->instanceId, 'pending_instant_pick');
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
        $source = $this->state->getCard((int) ($pending['card_id'] ?? 0));
        if ($source && EffectiveFeatures::suppressed($source)) {
            unset($this->state->battle['pending_forced_strike_adjacent']);
            $this->state->bumpVersion();
            return Result::ok(['forced_strike_instant_suppressed']);
        }

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
            $attacker->instanceId,
            'pending_forced_strike_adjacent'
        );
        if (!$result->success) return $result;

        unset($this->state->battle['pending_forced_strike_adjacent']);
        $this->state->bumpVersion();
        return Result::ok(['turn_instant_stacked']);
    }

    private function findAvailableInstant(string $playerKey, int $cardId, string $key, string $phase, string $type, ?string $ownPending = null): ?array
    {
        foreach ($this->availableInstants($playerKey, $phase, $type, $ownPending) as $inst) {
            $payload = (array) ($inst['payload'] ?? []);
            if ((int) $inst['card_id'] !== $cardId) continue;
            if ((string) ($payload['key'] ?? '') !== $key) continue;
            return $payload;
        }
        return null;
    }

    private function findAvailableTurnInstant(string $playerKey, int $cardId, string $key, string $phase, ?string $ownPending = null): ?array
    {
        $payload = $this->findAvailableInstant($playerKey, $cardId, $key, $phase, 'turn', $ownPending);
        if ($payload === null) return null;
        return [
                'card_id' => $cardId,
                'ukid' => $this->state->getCard($cardId)->ukid,
                'key' => $key,
                'label' => $payload['name'] ?? 'Инстант',
                'target' => $payload['target'] ?? 'self',
                'effect' => (array) ($payload['effect'] ?? []),
                'action_type' => (string) ($payload['action_type'] ?? ''),
                'cost' => (int) ($payload['coins'] ?? 0),
                'uses_per_turn' => (int) ($payload['uses_per_turn'] ?? 1),
                'close_source' => $this->instantRequiresTap($payload),
            ];
    }

    public function declareTurnInstantFromTask(string $playerKey, array $subTask): Result
    {
        $payload = (array) ($subTask['payload'] ?? []);
        $cardId = (int) ($subTask['card_id'] ?? 0);
        $key = (string) ($payload['key'] ?? '');

        $found = $this->findAvailableTurnInstant($playerKey, $cardId, $key, 'turn');
        if (!$found) return Result::error('Инстант недоступен');
        if (empty($this->state->battle['turn_instant_stack'])) {
            $this->openTurnStackWindow($playerKey, ['type' => 'turn_phase'], 'turn');
        }
        $stack = $this->state->battle['turn_instant_stack'] ?? [];
        if (($stack['state'] ?? null) !== 'ordering' || ($stack['priority'] ?? null) !== $playerKey
            || !in_array($playerKey, $this->turnParticipants($stack), true)) return Result::error('Не ваш приоритет');

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

    private function pushTurnInstantEntry(string $playerKey, array $inst, int $cardId, ?int $targetId = null, ?string $ownPending = null): Result
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
        $fresh = $this->findAvailableTurnInstant($playerKey, $cardId, $key, (string) ($stack['phase'] ?? 'turn'), $ownPending);
        if (!$fresh) return Result::error('Инстант недоступен');
        $target = $this->state->getCard($targetId ?? $cardId);
        if (!$target || !$this->isLegalInstantTarget($card, $target, (string) $fresh['target'], $fresh['effect']['condition'] ?? null)) {
            return Result::error('Цель недоступна');
        }
        // The secondary forced-strike choice is validated by its native handler above.
        if (isset($inst['effect']['forced_target_id'])) $fresh['effect']['forced_target_id'] = $inst['effect']['forced_target_id'];
        $inst = $fresh;
        $cost = (int) ($inst['cost'] ?? 0);
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
            'action_type' => (string) ($inst['action_type'] ?? ''),
        ];

        $card->flags['in_stack'] = true;
        $this->markInstantUsed($card, $key);
        $this->state->battle['turn_instant_stack']['passed'] = [];

        return Result::ok(['turn_instant_stacked']);
    }

    public function resumeContinuationStack(): void
    {
        $this->resolveTurnStack();
    }

    private function resolveTurnStack(): void
    {
        $tagged = CombatContinuation::active($this->state);
        $stackState = $this->state->battle['turn_instant_stack'];
        $stackState['state'] = 'resolving';
        if ($tagged) unset($this->state->battle['turn_instant_stack']);
        else $this->state->battle['turn_instant_stack']['state'] = 'resolving';
        $summary = (array) ($stackState['resolved_summary'] ?? []);
        $items = $stackState['remaining'] ?? array_reverse((array) ($stackState['stack'] ?? []));
        $continuation = (array) ($stackState['continuation'] ?? ['type' => 'manual']);
        $phase = (string) ($stackState['phase'] ?? 'turn');

        foreach ($items as $index => $item) {
            $watermark = (int) ($this->state->battle['combat_continuation']['next_id'] ?? 0);
            $source = $this->state->getCard((int) ($item['card_id'] ?? 0));
            $target = $this->state->getCard((int) ($item['target_id'] ?? 0));
            $label = (string) ($item['label'] ?? 'Инстант');
            $applied = false;
            $reason = '';
            $before = $target ? $this->captureTurnTargetSnapshot($target) : null;
            $effectResult = [];

            if (isset($item['_card_heal_done'])) {
                $before=$item['_heal_before'];$after=$target?$this->captureTurnTargetSnapshot($target):null;
                $effectResult=$target?$this->buildTurnEffectResult((array)$item['effect'],$before,$after,$target):[];
                $effectResult['card_heal']=$item['_card_heal_done'];
                $applied=!empty($item['_effect_completed'])?$this->turnEffectChanged($before,$after):($item['_card_heal_done']['restored']>0 || $item['_card_heal_done']['replaced']);
                $reason=$item['_card_heal_done']['message']??($applied?'':'излечение не восстановило HP');
            } elseif (!$source) {
                $reason = 'источник не найден';
            } elseif (EffectiveFeatures::suppressed($source)) {
                $reason = 'Потеря памяти';
            } elseif (!$target || $target->dying || $target->hp <= 0) {
                $reason = 'цель не найдена';
            } elseif (empty($item['effect'])) {
                $reason = 'нет эффекта';
            } elseif ($this->isTurnInstantDefended($item, $source, $target)) {
                $reason = 'цель защищена';
            } else {
                $resolutionItem = $item;
                ActionResolution::bind($this->state, $resolutionItem, $source, (string)(($item['action_type'] ?? '') ?: 'instant'), 'instant',
                    $continuation['combat_origin_strike'][ActionResolution::ID] ?? null);
                ActionResolution::resolve($this->state, $resolutionItem, 'performed', ['effect_type'=>$item['effect']['type'] ?? '']);
                $resolutionScope = $this->state->battle[ActionResolution::KEY]['scope_id'] ?? null;
                $this->state->battle[ActionResolution::KEY]['scope_id'] = $resolutionItem[ActionResolution::ID];
                try {
                    $this->engine->applyInstantEffect(
                        $this->state,
                        (array) $item['effect'] + ['_heal_return_to'=>['handler'=>'instant','kind'=>'turn',
                            'stack'=>$stackState,'summary'=>$summary,'remaining'=>array_slice($items,$index+1),
                            'item'=>$item,'before'=>$before]],
                        $source,
                        $target,
                        (string) $item['player']
                    );
                } finally {
                    if ($resolutionScope === null) unset($this->state->battle[ActionResolution::KEY]['scope_id']);
                    else $this->state->battle[ActionResolution::KEY]['scope_id'] = $resolutionScope;
                }
                if (CardHealing::waiting($this->state)) {
                    if(!in_array($item['effect']['type']??'', ['heal','heal_turn_wounds'],true))
                        CardHealing::defer($this->state,['handler'=>'instant','kind'=>'turn_after_effect',
                            'stack'=>$stackState,'summary'=>$summary,'remaining'=>array_slice($items,$index+1),'item'=>$item,'before'=>$before]);
                    return;
                }
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

            if ($applied && $source && $target) {
                $this->state->historyRecorder?->liveEvent($this->state,'ability.applied',[
                    'source_id'=>$source->instanceId,'ability'=>$label,'_private_owner'=>$source->owner],[$source->instanceId,$target->instanceId]);
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
                'action_type' => (string) ($item['action_type'] ?? ''),
                'result' => $effectResult,
            ];
            if ($tagged && CombatContinuation::busy($this->state)) {
                $stackState['remaining'] = array_slice($items, $index + 1);
                $stackState['resolved_summary'] = $summary;
                $stackState['frame_watermark'] = $watermark;
                $this->state->battle['combat_continuation']['stacks'][] = $stackState;
                return;
            }
        }

        $this->engine->finalizeDying($this->state);
        unset($this->state->battle['turn_instant_stack']);
        if (empty($summary)) {
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

    private function isTurnInstantDefended(array $item, CardInstance $source, CardInstance $target): bool
    {
        $actionType = (string) ($item['action_type'] ?? '');
        if ($actionType === '') return false;
        if (!in_array($actionType, ['strike', 'uchr', 'shot', 'throw', 'discharge', 'magic', 'cast', 'tap'], true)) return false;
        if ($target->owner === $source->owner) return false;

        return CardStats::hasDefense($this->state, $target, $actionType, $source);
    }

    public function ackTurnInstantResult(string $playerKey): Result
    {
        $pending = $this->state->battle['turn_instant_result'] ?? null;
        if (!$pending) {
            return Result::error('Нет результата инстантов');
        }

        $continuation = (array) ($pending['continuation'] ?? ['type' => 'manual']);
        if (!empty($continuation['combat_origin_strike'])) {
            $continuation['combat_origin_strike']['after_instant_summary'] = $pending['summary'] ?? [];
        }
        if (!empty($continuation['coin_origin_strike'])) {
            $continuation['coin_origin_strike']['after_instant_summary'] = $pending['summary'] ?? [];
        }
        unset($this->state->battle['turn_instant_result']);
        unset($this->state->battle['instant_result']);
        $this->continueAfterTurnStack($continuation);
        $this->state->bumpVersion();
        return Result::ok(['turn_instant_result_ack']);
    }

    private function continueAfterTurnStack(array $continuation): void
    {
        $type = (string) ($continuation['type'] ?? 'manual');
        if (!empty($continuation['combat_origin_strike'])) {
            $origin = $continuation['combat_origin_strike'];
            CombatContinuation::resume($this->state, $origin);
            if ($type === 'strike_before') {
                $this->continueAfterStrikeBeforeWindow();
            } else {
                $origin['instant_phase'] = 'after';
                $this->state->battle['strike']['instant_phase'] = 'after';
                (new StrikeResolver($this->state, $this->engine))->continueAfterStrikeResultAck($origin);
            }
            return;
        }
        if ($type === 'turn_phase') {
            $this->completeTurnPhaseInstantSubtask();
            if (!empty($this->state->battle['turn_phase'])) {
                (new TurnPhaseProcessor($this->state, $this->engine))->resume();
            }
            return;
        }
        if ($type === 'strike_before') {
            $this->continueAfterStrikeBeforeWindow();
            return;
        }
        if ($type === 'strike_after') {
            if (!empty($continuation['coin_origin_strike'])) {
                // An after-instant may start another mandatory combat; resume the origin only after it.
                $this->state->battle['coin_post_combat_continuation'] = $continuation['coin_origin_strike'];
                return;
            }
            (new CoinAbilities($this->state, $this->engine))->completeCombat($this->state->battle['strike'] ?? []);
            CombatContinuation::localDone($this->state, $this->state->battle['strike'] ?? []);
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

    private function combatItemRequiresTap(array $item): bool
    {
        $source = $this->state->getCard((int) ($item['card_id'] ?? 0));
        // This is the declared tap contract, not feature execution. Suppression
        // must not turn an existing no_close instant into a new tap cost.
        foreach ($source?->prop['instants'] ?? [] as $instant) {
            if (($instant['key'] ?? '') === ($item['instant_key'] ?? '')) return $this->instantRequiresTap($instant);
        }
        return true;
    }

    private function isCardInPending(CardInstance $card, ?string $ownPending = null): bool
    {
        $containsSource = static function(array $value) use (&$containsSource, $card): bool {
            foreach (['attacker_id', 'card_id', 'source_id', 'healer_id'] as $field) {
                if ((int) ($value[$field] ?? 0) === $card->instanceId) return true;
            }
            foreach ($value as $nested) if (is_array($nested) && $containsSource($nested)) return true;
            return false;
        };
        foreach ($this->state->battle as $key => $val) {
            if (!is_string($key) || !str_starts_with($key, 'pending_')) continue;
            if ($key === $ownPending) continue;
            if (!is_array($val)) continue;
            if ($containsSource($val)) return true;
        }
        return false;
    }
    public function continueCardHealing(array $to,array $r): Result
    {
        if($to['kind']==='combat_after_effect') {
            $result=$this->buildCombatEffectResult($to['effect'],$to['before'],$this->captureCombatSnapshot(),$to['owner']);
            $this->completePausedCombatItem(true,'',$result);return Result::ok(['card_heal_combat_instant_continued']);
        }
        if($to['kind']==='combat') {
            $this->completePausedCombatItem($r['restored']>0||$r['replaced'],$r['message']??'',['card_heal'=>$r]);
            return Result::ok(['card_heal_combat_instant_continued']);
        }
        $item=$to['item'];$item['_card_heal_done']=$r;
        if(in_array($to['kind'],['turn','turn_after_effect'],true)) {
            if($to['kind']==='turn_after_effect')$item['_effect_completed']=true;
            $item['_heal_before']=$to['before'];$stack=$to['stack'];
            $stack['remaining']=array_merge([$item],$to['remaining']);$stack['resolved_summary']=$to['summary'];
            $this->state->battle['turn_instant_stack']=$stack;
            $this->resolveTurnStack();
        } else {
            $this->state->battle['strike']['instant_stack']=array_reverse(array_merge([$item],$to['remaining']));
            $this->state->battle['strike']['healing_summary']=$to['summary'];$this->resolveStack();
        }
        return Result::ok(['card_heal_instant_continued']);
    }

}
