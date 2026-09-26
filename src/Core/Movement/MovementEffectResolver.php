<?php
// src/Core/Movement/MovementEffectResolver.php

declare(strict_types=1);

namespace Berserk\Core\Movement;

use Berserk\Core\CardInstance;
use Berserk\Core\Engine;
use Berserk\Core\GameState;

final class MovementEffectResolver
{
    public function __construct(
        private GameState $state,
        private Engine $engine,
    ) {}

    public function apply(MovementContext $context): void
    {
        $card = $context->card;

        $this->applyMovePenalty($card);
        $this->engine->syncCoinBonus($card);
        $this->applyOnMoveEffects($context);
        $this->applyRialaMovement($context);
        $this->applyAirinTriggers($context);

        $this->engine->clearRootedBySource($this->state, $card->instanceId);
        $this->engine->refreshArmor($this->state);
        $this->maybeOpenForcedStrike($card, $context->playerKey);
    }

    private function applyMovePenalty(CardInstance $card): void
    {
        if (!empty($card->prop['lose_coins_on_move']) && $card->coins > 0) {
            $card->coins = 0;
        }
    }

    private function applyOnMoveEffects(MovementContext $context): void
    {
        $card = $context->card;

        $effects = $card->prop['on_move'] ?? [];
        if (is_array($effects)) {
            foreach ($effects as $effect) {
                if (($effect['type'] ?? '') === 'modifier') {
                    $card->modifiers[] = [
                        'stat'   => $effect['stat'] ?? 'ability_strike',
                        'value'  => (int) ($effect['value'] ?? 1),
                        'expire' => $effect['expire'] ?? 'end_of_turn',
                    ];
                }
            }
        }

        $halfEffects = $card->prop['on_move_half'] ?? [];
        if (!is_array($halfEffects) || empty($halfEffects)) {
            return;
        }

        $fromHalf = $this->halfOf($card->owner, $context->fromRow);
        $toHalf   = $this->halfOf($card->owner, $context->toRow);

        foreach ($halfEffects as $effect) {
            if (($effect['from'] ?? '') !== $fromHalf) continue;
            if (($effect['to'] ?? '') !== $toHalf) continue;

            foreach ($effect['modifiers'] ?? [] as $modifier) {
                $card->modifiers[] = [
                    'stat'   => $modifier['stat'],
                    'value'  => (int) ($modifier['value'] ?? 1),
                    'expire' => $modifier['expire'] ?? 'end_of_turn',
                    'source' => $card->owner,
                ];
            }

            foreach ($effect['flags'] ?? [] as $flag) {
                $card->flags[$flag] = true;
            }
        }
    }

    private function halfOf(string $owner, int $row): string
    {
        if ($owner === 'host') {
            return $row <= 3 ? 'own' : 'enemy';
        }

        return $row >= 4 ? 'own' : 'enemy';
    }

    private function applyRialaMovement(MovementContext $context): void
    {
        $card = $context->card;
        if (empty($card->prop['riala_movement'])) {
            return;
        }
        if ($context->movementType !== MovementContext::TYPE_MOVE) {
            return;
        }

        $direction = $this->orthogonalDirection($context);
        if ($direction === null) {
            return;
        }

        $counts = $card->flags['riala_movement_dirs'] ?? [];
        if (!is_array($counts)) {
            $counts = [];
        }

        $counts[$direction] = ((int) ($counts[$direction] ?? 0)) + 1;
        $card->flags['riala_movement_dirs'] = $counts;

        if (empty($card->flags['riala_direct_granted_this_turn'])
            && max($counts) >= 2) {
            $card->modifiers[] = [
                'stat'   => 'direct',
                'value'  => true,
                'expire' => 'end_of_turn',
                'source' => 'riala_movement',
            ];
            $card->flags['riala_direct_granted_this_turn'] = true;
        }

        if (empty($card->flags['riala_ova_granted_this_turn'])
            && count(array_filter($counts, fn($count) => (int) $count > 0)) >= 2) {
            $card->modifiers[] = [
                'stat'   => 'ova',
                'value'  => 2,
                'expire' => 'end_of_turn',
                'source' => 'riala_movement',
            ];
            $card->flags['riala_ova_granted_this_turn'] = true;
        }
    }

    private function orthogonalDirection(MovementContext $context): ?string
    {
        return match ([$context->deltaRow(), $context->deltaCol()]) {
            [-1, 0] => 'up',
            [1, 0] => 'down',
            [0, -1] => 'left',
            [0, 1] => 'right',
            default => null,
        };
    }

    private function applyAirinTriggers(MovementContext $context): void
    {
        $moved = $context->card;

        $hasMagic = false;
        foreach ($moved->prop['actions'] ?? [] as $action) {
            $type = $action['type'] ?? '';
            if (in_array($type, ['discharge', 'magic', 'cast'], true)) {
                $hasMagic = true;
                break;
            }
        }
        if (!$hasMagic) {
            return;
        }

        foreach ($this->state->cards as $airin) {
            if ($airin->owner !== $moved->owner) continue;
            if ($airin->instanceId === $moved->instanceId) continue;
            if ($airin->zone !== CardInstance::ZONE_FIELD) continue;
            if (empty($airin->prop['airin_trigger'])) continue;
            if ($airin->dying || $airin->hp <= 0) continue;

            if ($this->isAdjacent($context->fromRow, $context->fromCol, $airin->row, $airin->col)) {
                continue;
            }
            if (!$this->isAdjacent($context->toRow, $context->toCol, $airin->row, $airin->col)) {
                continue;
            }

            $used = (int) ($airin->flags['airin_triggered_this_turn'] ?? 0);
            if ($used >= 2) continue;

            $airin->flags['airin_triggered_this_turn'] = $used + 1;
            $airin->modifiers[] = [
                'stat'   => 'ability_discharge',
                'value'  => 1,
                'expire' => 'end_of_turn',
                'source' => $moved->owner,
            ];
        }
    }

    private function isAdjacent(int $r1, int $c1, int $r2, int $c2): bool
    {
        $dr = abs($r1 - $r2);
        $dc = abs($c1 - $c2);

        return $dr <= 1 && $dc <= 1 && ($dr + $dc) > 0;
    }

    private function maybeOpenForcedStrike(CardInstance $card, string $playerKey): void
    {
        if (empty($card->prop['forced_strike'])) return;
        if ($card->move > 0) return;
        if ($card->closed) return;

        $candidates = [];
        foreach ($this->state->cards as $target) {
            if ($target->zone !== CardInstance::ZONE_FIELD) continue;
            if ($target->owner === $card->owner) continue;
            if (!$target->closed) continue;
            if ($target->dying || $target->hp <= 0) continue;

            $dr = abs($target->row - $card->row);
            $dc = abs($target->col - $card->col);
            if ($dr <= 1 && $dc <= 1 && ($dr + $dc) > 0) {
                $candidates[] = $target->instanceId;
            }
        }

        if (empty($candidates)) return;

        $this->state->battle['pending_forced_strike'] = [
            'owner'       => $playerKey,
            'attacker_id' => $card->instanceId,
            'candidates'  => $candidates,
        ];
    }
}
