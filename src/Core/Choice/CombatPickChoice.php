<?php
// src/Core/Choice/CombatPickChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\Core\CardInstance;
use Berserk\View\Ui\PanelSpec;

final class CombatPickChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_combat_pick';
    }

    public function commandTypes(): array
    {
        return ['choose_combat_pick'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pc = $state->battle['pending_combat_pick'] ?? null;
        if (!$pc) return null;

        $source  = $state->getCard($pc['card_id']);
        $srcName = $source ? ($cardsInfo[$source->ukid]['name'] ?? '?') : '?';

        if ($pc['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $srcName . ' — выбор цели',
                isMine: false,
            );
        }

        $condition = $pc['effect']['condition'] ?? null;
        $adjacentIds = [];
        if (($pc['target'] ?? '') === 'adjacent_ally') {
            $strike = $state->battle['strike'] ?? null;
            $currentTarget = $strike ? $state->getCard((int) ($strike['target_id'] ?? 0)) : null;
            if ($currentTarget) {
                foreach ($state->cards as $candidate) {
                    if ($candidate->owner !== $playerKey) continue;
                    if ($candidate->instanceId === $currentTarget->instanceId) continue;
                    if ($candidate->zone !== CardInstance::ZONE_FIELD || $currentTarget->zone !== CardInstance::ZONE_FIELD) continue;
                    $dr = abs($candidate->row - $currentTarget->row);
                    $dc = abs($candidate->col - $currentTarget->col);
                    if ($dr <= 1 && $dc <= 1 && ($dr + $dc) > 0) {
                        $adjacentIds[] = $candidate->instanceId;
                    }
                }
            }
        }

        $buttons = [];
        foreach ($state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;

            if (($pc['target'] ?? 'enemy') === 'enemy' && $c->owner === $playerKey) continue;
            if (($pc['target'] ?? 'enemy') === 'ally'  && $c->owner !== $playerKey) continue;
            if (($pc['target'] ?? '') === 'adjacent_ally' && !in_array($c->instanceId, $adjacentIds, true)) continue;
            if ($condition === 'target_not_moved' && !empty($c->flags['moved_this_turn'])) continue;

            $name = $cardsInfo[$c->ukid]['name'] ?? '?';
            $coords = $c->zone === CardInstance::ZONE_FIELD
                ? ' (' . $c->row . ',' . $c->col . ')' : '';

            $buttons[] = [
                'label' => $name . $coords . ' (' . $c->hp . '/' . $c->hpMax . ')',
                'url'   => "{$baseUrl}&cmd=choose_combat_pick&target_id={$c->instanceId}",
            ];
        }

        $buttons[] = [
            'label' => 'Отмена',
            'url'   => $baseUrl . '&cmd=cancel_pending',
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: $srcName . ': ' . ($pc['label'] ?? 'выбери цель'),
            buttons: $buttons,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ar = new \Berserk\Core\ActionResolver($state, $engine);
        return $ar->chooseCombatPick($playerKey, $cmd);
    }
}
