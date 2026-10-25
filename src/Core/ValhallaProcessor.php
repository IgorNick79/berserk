<?php
// src/Core/ValhallaProcessor.php

declare(strict_types=1);

namespace Berserk\Core;

final class ValhallaProcessor
{
    public const TRIGGER_TYPES = [
        'strike', 'tap', 'magic', 'shot', 'throw', 'uchr', 'discharge', 'answer', 'strangulation'
    ];

    public function __construct(
        private GameState $state,
        private ?Engine $engine = null,
        private ?DamageResolver $damage = null,
    ) {}

    public function markPending(CardInstance $card, string $attackType): void
    {
        if (empty($card->effectiveProp()['valhalla'])) return;
        if (!in_array($attackType, self::TRIGGER_TYPES, true)) return;
        $card->flags['valhalla_pending'] = true;
    }

    public function collectActive(string $ownerKey): array
    {
        $result = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $ownerKey) continue;
            if ($card->zone !== CardInstance::ZONE_GRAVEYARD) continue;
            if (empty($card->flags['valhalla_active'])) continue;

            // Проверяем, есть ли кандидаты, если эффект требует выбора
            $effects = $card->effectiveProp()['valhalla'] ?? [];
            $needsChoice = false;
            foreach ($effects as $eff) {
                $t = $eff['target'] ?? 'self';
                if (in_array($t, ['own_creature', 'enemy_creature', 'own_yordling'], true)) {
                    $needsChoice = true;
                    break;
                }
            }
            if ($needsChoice && empty($this->collectCandidates($card, $ownerKey))) {
                continue;
            }

            $result[] = $card;
        }
        return $result;
    }

    /**
     * Начинает исполнение. Если нужен выбор цели — ставит pending и возвращает true.
     */
    public function beginExecute(CardInstance $card, string $ownerKey): bool
    {
        $effects = $card->effectiveProp()['valhalla'] ?? [];
        if (!is_array($effects) || empty($effects)) return false;
        $repeat = $this->consumeRagnarValhallaRepeat($ownerKey);

        $needsChoice = false;
        foreach ($effects as $eff) {
            $t = $eff['target'] ?? 'self';
            if (in_array($t, ['own_creature', 'enemy_creature', 'own_yordling'], true)) {
                $needsChoice = true;
                break;
            }

        }

        if (!$needsChoice) {
            $this->applyAll($card, $ownerKey, null, $repeat);
            return false;
        }

        $this->state->battle['pending_valhalla_pick'] = [
            'owner'     => $ownerKey,
            'card_id'   => $card->instanceId,
            'remaining' => $repeat,
            'repeat_source' => $repeat > 1 ? 'ragnar' : null,
            'repeat_index'  => 1,
            'repeat_total'  => $repeat,
        ];
        return true;
    }

    public function chooseTarget(string $playerKey, Command $cmd): Result
    {
        $pv = $this->state->battle['pending_valhalla_pick'] ?? null;
        if (!$pv) return Result::error('Нет ожидающего выбора');
        if ($pv['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        $card = $this->state->getCard($pv['card_id']);
        if (!$card) return Result::error('Карта не найдена');

        $targetId = (int) $cmd->get('target_id', 0);
        $target   = $this->state->getCard($targetId);
        if (!$target) return Result::error('Цель не найдена');
        if (!in_array($targetId, $this->collectCandidates($card, $playerKey), true)) {
            return Result::error('Недопустимая цель');
        }

        unset($this->state->battle['pending_valhalla_pick']);

        $this->applyAll($card, $playerKey, $target);

        $remaining = max(1, (int) ($pv['remaining'] ?? $pv['repeat'] ?? 1)) - 1;
        if ($remaining > 0 && !empty($this->collectCandidates($card, $playerKey))) {
            $this->state->battle['pending_valhalla_pick'] = [
                'owner'     => $playerKey,
                'card_id'   => $card->instanceId,
                'remaining' => $remaining,
                'repeat_source' => $pv['repeat_source'] ?? null,
                'repeat_index'  => ((int) ($pv['repeat_index'] ?? 1)) + 1,
                'repeat_total'  => (int) ($pv['repeat_total'] ?? (($pv['repeat'] ?? 1))),
            ];
            $this->state->bumpVersion();
            return Result::ok(['valhalla_applied', 'valhalla_pick_pending']);
        }

        $this->finishPendingSubtask();

        if (!empty($this->state->battle['turn_phase'])) {
            if ($this->engine !== null) {
                (new TurnPhaseProcessor($this->state, $this->engine))->resume();
            }
        }

        $this->state->bumpVersion();
        return Result::ok(['valhalla_applied']);
    }

    /** Stop only the unapplied remainder; Ragnar's consumed repeat is not refunded. */
    public function cancel(string $playerKey): Result
    {
        $pending = $this->state->battle['pending_valhalla_pick'] ?? null;
        if (!$pending) return Result::error('Нет ожидающего выбора');
        if ($pending['owner'] !== $playerKey) return Result::error('Не ваш выбор');

        unset($this->state->battle['pending_valhalla_pick']);
        $this->finishPendingSubtask();
        if ($this->engine !== null && !empty($this->state->battle['turn_phase'])) {
            (new TurnPhaseProcessor($this->state, $this->engine))->resume();
        }
        $this->state->bumpVersion();
        return Result::ok(['cancelled:valhalla_pick']);
    }

    private function finishPendingSubtask(): void
    {
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
    }

    /**
     * Собирает кандидатов для pending_valhalla_pick.
     */
    public function collectCandidates(CardInstance $card, string $ownerKey): array
    {
        $effects = $card->effectiveProp()['valhalla'] ?? [];

        $needOwn        = false;
        $needEnemy      = false;
        $needOwnYordling = false;
        $ownNeedsCoins   = false;

        foreach ($effects as $eff) {
            $t = $eff['target'] ?? 'self';
            if ($t === 'own_creature')    $needOwn        = true;
            if ($t === 'enemy_creature')  $needEnemy      = true;
            if ($t === 'own_yordling')    $needOwnYordling = true;

            if (($eff['type'] ?? '') === 'get_coins'
                && in_array($t, ['own_creature', 'own_yordling'], true)) {
                $ownNeedsCoins = true;
            }
        }

        $candidates = [];
        foreach ($this->state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;

            if ($needOwn && $c->owner === $ownerKey) {
                if ($ownNeedsCoins && empty($c->effectiveProp()['save_coins'])) continue;
                $candidates[] = $c->instanceId;
            } elseif ($needEnemy && $c->owner !== $ownerKey) {
                $candidates[] = $c->instanceId;
            } elseif ($needOwnYordling && $c->owner === $ownerKey && CardStats::hasClass($c, 'Йордлинг')) {
                if ($ownNeedsCoins && empty($c->effectiveProp()['save_coins'])) continue;
                $candidates[] = $c->instanceId;
            }
        }
        return $candidates;
    }

    private function applyAll(CardInstance $card, string $ownerKey, ?CardInstance $chosen, int $repeat = 1): void
    {
        $effects = $card->effectiveProp()['valhalla'] ?? [];
        if (!is_array($effects)) return;

        $repeat = max(1, min(2, $repeat));
        for ($i = 0; $i < $repeat; $i++) {
            foreach ($effects as $eff) {
                $this->applyEffect($card, $eff, $ownerKey, $chosen);
            }
        }
    }

    private function applyEffect(CardInstance $source, array $eff, string $ownerKey, ?CardInstance $chosen): void
    {
        $type       = $eff['type'] ?? '';
        $targetType = $eff['target'] ?? 'self';
        $value      = (int) ($eff['value'] ?? 0);

        $target = null;
        if ($targetType === 'self') {
            $target = $source;
        }
        if (in_array($targetType, ['own_creature', 'enemy_creature', 'own_yordling'], true)) {
            $target = $chosen;
            if (!$target) return;
            if (($target->zone !== CardInstance::ZONE_FIELD && $target->zone !== CardInstance::ZONE_FLYING)
                || $target->dying
                || $target->hp <= 0) {
                return;
            }
        }

        $journalHpBefore=$target?->hp;
        switch ($type) {
            case 'ragnar_first_strike_unanswer':
                $source->flags['ragnar_valhalla_resolved_this_turn'] =
                    ((int) ($source->flags['ragnar_valhalla_resolved_this_turn'] ?? 0)) + 1;
                break;

            case 'modifier':
                if (!$target) return;
                $target->modifiers[] = [
                    'stat'   => $eff['stat'] ?? 'ability_strike',
                    'value'  => (int) ($eff['value'] ?? 1),
                    'only'   => $eff['only'] ?? null,
                    'expire' => $eff['expire'] ?? 'end_of_turn',
                    'source' => $ownerKey,
                ];
                $this->state->historyRecorder?->liveEvent($this->state,'ability.applied',[
                    'source_id'=>$source->instanceId,'ability'=>'Вальхалла','_private_owner'=>$source->owner],[$source->instanceId,$target->instanceId]);
                break;

            case 'damage':
                if (!$target) return;
                if ($this->damage !== null) {
                    $this->damage->applyDamage($target, $value, 'impact');
                } elseif ($this->engine !== null) {
                    $this->engine->applyDamage($this->state, $target, $value, 'impact');
                }
                break;

            case 'get_coins':
                if (!$target || (!empty($target->effectiveProp()['coins_receive_deny'])
                    && $target->instanceId !== $source->instanceId)) return;
                if ($this->engine === null && !empty($target->effectiveProp()['coin_strike_bonus'])) {
                    throw new \LogicException('Valhalla get_coins requires Engine coin bonus synchronization');
                }

                $max = (int) ($target->effectiveProp()['coins']['max_value'] ?? 0);
                $target->coins += $value;
                if ($max > 0 && $target->coins > $max) $target->coins = $max;

                if ($this->engine !== null) {
                    $this->engine->syncCoinBonus($target);
                }
                break;

            case 'heal':
                if (!$target) return;
                $target->hp += $value;
                if ($target->hp > $target->hpMax) $target->hp = $target->hpMax;
                $this->state->historyRecorder?->healed($this->state,$target,$journalHpBefore,$source,'valhalla');
                break;
        }
    }

    private function consumeRagnarValhallaRepeat(string $ownerKey): int
    {
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $ownerKey) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD && $card->zone !== CardInstance::ZONE_FLYING) continue;
            if ($card->dying || $card->hp <= 0) continue;
            if (empty($card->effectiveProp()['ragnar_valhalla_double'])) continue;
            if (!empty($card->flags['ragnar_valhalla_double_used_this_turn'])) continue;

            $card->flags['ragnar_valhalla_double_used_this_turn'] = true;
            return 2;
        }

        return 1;
    }
}
