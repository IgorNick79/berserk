<?php
// src/Core/ValhallaProcessor.php

declare(strict_types=1);

namespace Berserk\Core;

final class ValhallaProcessor
{
    public const TRIGGER_TYPES = [
        'strike', 'tap', 'magic', 'shot', 'throw', 'uchr', 'discharge', 'answer'
    ];

    public function __construct(
        private GameState $state,
        private ?Engine $engine = null,
        private ?DamageResolver $damage = null,
    ) {}

    public function markPending(CardInstance $card, string $attackType): void
    {
        if (empty($card->prop['valhalla'])) return;
        if (!in_array($attackType, self::TRIGGER_TYPES, true)) return;
        $card->flags['valhalla_pending'] = true;
    }

    public function collectActive(string $ownerKey): array
    {
        file_put_contents(
            __DIR__ . '/../../debug.log',
            date('[Y-m-d H:i:s] ') . "collectActive owner={$ownerKey}\n",
            FILE_APPEND
        );

        $result = [];
        foreach ($this->state->cards as $card) {
            if ($card->owner !== $ownerKey) continue;
            if ($card->zone !== CardInstance::ZONE_GRAVEYARD) continue;
            if (empty($card->flags['valhalla_active'])) continue;

            // Проверяем, есть ли кандидаты, если эффект требует выбора
            $effects = $card->prop['valhalla'] ?? [];
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
        $effects = $card->prop['valhalla'] ?? [];
        if (!is_array($effects) || empty($effects)) return false;

        $needsChoice = false;
        foreach ($effects as $eff) {
            $t = $eff['target'] ?? 'self';
            if (in_array($t, ['own_creature', 'enemy_creature', 'own_yordling'], true)) {
                $needsChoice = true;
                break;
            }

        }

        if (!$needsChoice) {
            $this->applyAll($card, $ownerKey, null);
            return false;
        }

        $this->state->battle['pending_valhalla_pick'] = [
            'owner'   => $ownerKey,
            'card_id' => $card->instanceId,
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

        unset($this->state->battle['pending_valhalla_pick']);

        // Убираем подзадачу
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
        
        $this->applyAll($card, $playerKey, $target);

        if (!empty($this->state->battle['turn_phase'])) {
            if ($this->engine !== null) {
                (new TurnPhaseProcessor($this->state, $this->engine))->resume();
            }
        }

        $this->state->bumpVersion();
        return Result::ok(['valhalla_applied']);
    }

    /**
     * Собирает кандидатов для pending_valhalla_pick.
     */
    public function collectCandidates(CardInstance $card, string $ownerKey): array
    {
        $effects = $card->prop['valhalla'] ?? [];

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
                if ($ownNeedsCoins && empty($c->prop['save_coins'])) continue;
                $candidates[] = $c->instanceId;
            } elseif ($needEnemy && $c->owner !== $ownerKey) {
                $candidates[] = $c->instanceId;
            } elseif ($needOwnYordling && $c->owner === $ownerKey && $c->class === 'Йордлинг') {
                if ($ownNeedsCoins && empty($c->prop['save_coins'])) continue;
                $candidates[] = $c->instanceId;
            }
        }
        return $candidates;
    }

    private function applyAll(CardInstance $card, string $ownerKey, ?CardInstance $chosen): void
    {
        $effects = $card->prop['valhalla'] ?? [];
        if (!is_array($effects)) return;

        foreach ($effects as $eff) {
            $this->applyEffect($eff, $ownerKey, $chosen);
        }
    }

    private function applyEffect(array $eff, string $ownerKey, ?CardInstance $chosen): void
    {
        $type       = $eff['type'] ?? '';
        $targetType = $eff['target'] ?? 'self';
        $value      = (int) ($eff['value'] ?? 0);

        $target = null;
        if (in_array($targetType, ['own_creature', 'enemy_creature', 'own_yordling'], true)) {
            $target = $chosen;
            if (!$target) return;
        }

        switch ($type) {
            case 'modifier':
                if (!$target) return;
                $target->modifiers[] = [
                    'stat'   => $eff['stat'] ?? 'ability_strike',
                    'value'  => (int) ($eff['value'] ?? 1),
                    'only'   => $eff['only'] ?? null,
                    'expire' => $eff['expire'] ?? 'end_of_turn',
                    'source' => $ownerKey,
                ];
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
                if (!$target) return;
                $max = (int) ($target->prop['coins']['max_value'] ?? 0);
                $target->coins += $value;
                if ($max > 0 && $target->coins > $max) $target->coins = $max;
                if ($this->damage !== null) {
                    $this->damage->syncCoinBonus($target);
                } elseif ($this->engine !== null) {
                    $this->engine->syncCoinBonus($target);
                }
                break;

            case 'heal':
                if (!$target) return;
                $target->hp += $value;
                if ($target->hp > $target->hpMax) $target->hp = $target->hpMax;
                break;
        }
    }
}
