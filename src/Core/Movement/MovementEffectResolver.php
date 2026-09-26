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
        $this->applyMovementDirectionBonus($context);
        $this->applyAirinTriggers($context);

        $this->engine->clearRootedBySource($this->state, $card->instanceId);
        $this->engine->refreshArmor($this->state);
        $this->maybeOpenForcedDirectionalMove($context);
        $this->maybeOpenForcedStrike($card, $context->playerKey);
    }

    public function applyForcedPositionChange(MovementContext $context): void
    {
        $this->applyMovePenalty($context->card);
        $this->engine->syncCoinBonus($context->card);
        $this->applyAirinTriggers($context);
        $this->engine->refreshArmor($this->state);
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

    private function applyMovementDirectionBonus(MovementContext $context): void
    {
        $card = $context->card;
        $config = $card->prop['movement_direction_bonus'] ?? null;
        if (!is_array($config)) {
            return;
        }
        if ($context->movementType !== MovementContext::TYPE_MOVE) {
            return;
        }

        $direction = $this->orthogonalDirection($context);
        if ($direction === null) {
            return;
        }

        $state = $card->flags['movement_direction_bonus'] ?? [];
        if (!is_array($state)) {
            $state = [];
        }

        $counts = $state['directions'] ?? [];
        if (!is_array($counts)) {
            $counts = [];
        }
        $counts[$direction] = ((int) ($counts[$direction] ?? 0)) + 1;
        $state['directions'] = $counts;

        $sameDirection = $config['same_direction'] ?? null;
        if (empty($state['same_direction_granted'])
            && is_array($sameDirection)
            && (int) ($sameDirection['moves'] ?? 0) > 0
            && max($counts) >= (int) $sameDirection['moves']
            && $this->grantConfiguredMovementModifier($card, $sameDirection['modifier'] ?? null, $config)) {
            $state['same_direction_granted'] = true;
        }

        $differentDirections = $config['different_directions'] ?? null;
        if (empty($state['different_directions_granted'])
            && is_array($differentDirections)
            && (int) ($differentDirections['count'] ?? 0) > 0
            && count(array_filter($counts, fn($count) => (int) $count > 0)) >= (int) $differentDirections['count']
            && $this->grantConfiguredMovementModifier($card, $differentDirections['modifier'] ?? null, $config)) {
            $state['different_directions_granted'] = true;
        }

        $card->flags['movement_direction_bonus'] = $state;
    }

    private function grantConfiguredMovementModifier(CardInstance $card, mixed $modifier, array $config): bool
    {
        if (!is_array($modifier) || empty($modifier['stat'])) {
            return false;
        }

        $applied = [
            'stat'   => (string) $modifier['stat'],
            'value'  => array_key_exists('value', $modifier) ? $modifier['value'] : 1,
            'source' => 'movement_direction_bonus',
        ];
        if (array_key_exists('expire', $config)) {
            $applied['expire'] = $config['expire'];
        }

        $card->modifiers[] = $applied;
        return true;
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

    private function maybeOpenForcedDirectionalMove(MovementContext $context): void
    {
        $card = $context->card;
        $config = $card->prop['force_opponent_directional_move'] ?? null;
        if (!is_array($config)) return;
        if ($context->movementType !== MovementContext::TYPE_MOVE) return;
        if ($context->manhattanDistance() !== 1) return;

        $direction = ForcedMovementResolver::relativeDirectionForDelta(
            $card->owner,
            $context->deltaRow(),
            $context->deltaCol(),
        );
        if ($direction === null) return;

        $distance = (int) ($config['distance'] ?? 1);
        if ($distance <= 0) return;

        $responder = $this->state->getOpponentKey($card->owner);
        $forced = new ForcedMovementResolver($this->state, $this->engine);
        $fallback = (array) ($config['fallback'] ?? []);

        if (empty($forced->eligibleTargets($responder, $direction, $distance))) {
            ForcedMovementResolver::applyFallback($card, $fallback);
            return;
        }

        $this->state->battle[ForcedMovementResolver::PENDING_KEY] = [
            'owner'              => $responder,
            'source_id'          => $card->instanceId,
            'source_owner'       => $card->owner,
            'relative_direction' => $direction,
            'distance'           => $distance,
            'stage'              => 'card',
            'selected_id'        => null,
            'fallback'           => $fallback,
        ];
    }
}
