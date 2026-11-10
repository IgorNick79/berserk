<?php
// src/Core/Choice/CombatPickChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\Core\CardInstance;
use Berserk\Core\ZoneManager;
use Berserk\View\Ui\{CreatureChoice, Form, PanelSpec};

final class CombatPickChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_combat_pick';
    }

    public function commandTypes(): array
    {
        return ['choose_combat_pick', 'cancel_pending'];
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
                $adjacentIds = ZoneManager::adjacentFieldAllyIds($state, $currentTarget, $playerKey);
            }
        }

        $rows = [];
        foreach ($state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;
            if (!(new \Berserk\Core\InstantProcessor($state, new Engine()))->isLegalInstantTarget(
                $source, $c, (string) ($pc['target'] ?? 'enemy'), $condition)) continue;

            if (($pc['target'] ?? 'enemy') === 'enemy' && $c->owner === $playerKey) continue;
            if (($pc['target'] ?? 'enemy') === 'ally'  && $c->owner !== $playerKey) continue;
            if (($pc['target'] ?? '') === 'adjacent_ally' && !in_array($c->instanceId, $adjacentIds, true)) continue;
            if ($condition === 'target_not_moved' && !empty($c->flags['moved_this_turn'])) continue;

            $rows[] = ['value'=>$c->instanceId];
        }

        $token = isset($pc['pick_token']) ? ['pick_token'=>$pc['pick_token']] : [];
        $cancel = $baseUrl . '&cmd=cancel_pending' . ($token ? '&pick_token=' . rawurlencode($pc['pick_token']) : '');
        return CreatureChoice::targetPanel(
            $state, $playerKey, $cardsInfo, $rows, 'choose_combat_pick:' . ($pc['pick_token'] ?? $state->version),
            $srcName . ': ' . ($pc['label'] ?? 'выбери цель'),
            Form::battleHidden($role, $state->gameId, 'choose_combat_pick', $token),
            $cancel,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ar = new \Berserk\Core\ActionResolver($state, $engine);
        if ($cmd->type === 'cancel_pending') {
            $pending = $state->battle['pending_combat_pick'] ?? null;
            if (isset($pending['pick_token']) && !hash_equals($pending['pick_token'], (string) $cmd->get('pick_token', ''))) {
                return Result::error('Устаревший выбор');
            }
            return $ar->cancelPending($playerKey, $cmd);
        }
        return $ar->chooseCombatPick($playerKey, $cmd);
    }
}
