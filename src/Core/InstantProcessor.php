<?php
// src/Core/InstantProcessor.php

declare(strict_types=1);

namespace Berserk\Core;

final class InstantProcessor
{
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

        $result = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $ownerKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->closed) continue;
            if (!empty($card->flags['in_stack'])) continue;
            if (empty($card->prop['instants'])) continue;

            foreach ($card->prop['instants'] as $inst) {
                if (($inst['trigger'] ?? '') !== $type) continue;

                if ($type === 'turn' && $phase === 'before' && !empty($inst['aftermath'])) continue;

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

        // Отшельница — не в стек, а сразу pending
        if (($effect['type'] ?? '') === 'redistribute_wounds') {
            return (new WoundTransferProcessor($this->state, $this->engine))
                ->start($playerKey, $card, [
                    'kind'          => 'hermit',
                    'donor_filter'  => 'damaged_this_strike',
                    'target_filter' => 'own',
                    'max_transfer'  => 0,
                    'coins_cost'    => 0,
                    'on_finish'     => 'strike_after',
                ]);
        }

        // dice_choice — открываем подменю
        if (($effect['type'] ?? '') === 'dice_choice') {
            $this->state->battle['pending_dice_choice'] = [
                'owner'   => $playerKey,
                'card_id' => $card->instanceId,
                'phase'   => $phase,
                'label'   => $inst['name'] ?? 'Ловец',
            ];
            $this->state->bumpVersion();
            return Result::ok(['dice_choice_opened']);
        }

        // Self — сразу в стек
        if ($target === 'self') {
            $card->flags['in_stack'] = true;
            $this->state->battle['strike']['instant_stack'][] = [
                'card_id'   => $card->instanceId,
                'effect'    => $effect,
                'target_id' => $card->instanceId,
                'player'    => $playerKey,
                'label'     => $inst['name'] ?? 'Инстант',
            ];
            $this->state->battle['strike']['instant_passed'] = [];
            $this->state->bumpVersion();
            return Result::ok(['instant_stacked']);
        }

        // Нужен выбор цели
        $this->state->battle['pending_combat_pick'] = [
            'owner'    => $playerKey,
            'card_id'  => $card->instanceId,
            'target'   => $target,
            'effect'   => $effect,
            'label'    => $inst['name'] ?? 'Инстант',
        ];

        $this->state->bumpVersion();
        return Result::ok(['combat_pick_opened']);
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

        $this->state->battle['strike']['instant_stack'][] = [
            'card_id'   => $source->instanceId,
            'effect'    => $pc['effect'],
            'target_id' => $target->instanceId,
            'player'    => $playerKey,
            'label'     => $pc['label'],
        ];

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
        $stack = $strike['instant_stack'] ?? [];
        $stack = array_reverse($stack);

        $phase = $strike['instant_phase'] ?? 'before';
        $summary = [];

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
                if ($phase === 'combat') {
                    $reason = $this->engine->applyCombatEffect(
                        $this->state,
                        $effect,
                        $source,
                        $item['player'],
                        $item['choice'] ?? null
                    );
                    $applied = ($reason === null);
                    $reasonText = $reason ?? '';
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
                }

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

        $source = $this->state->getCard($pi['card_id']);
        if ($source) {
            $cost = (int) ($pi['cost'] ?? 0);
            if ($cost > 0 && $source->coins < $cost) {
                return Result::error('Не хватает монет');
            }
            if ($cost > 0) {
                $source->coins -= $cost;
                $this->engine->syncCoinBonus($source);
            }
            $source->closed = true;
        }

        $this->engine->applyInstantEffect($this->state, $pi['effect'], $source, $target, $playerKey);
        unset($this->state->battle['pending_instant_pick']);

        $src     = $pi['source'] ?? 'phase';
        $listKey = $pi['list_key'] ?? null;

        if ($src === 'turn_instants') {
            if ($listKey !== null) {
                $this->removeTurnInstant($pi['card_id'], $listKey);
            }
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
}
