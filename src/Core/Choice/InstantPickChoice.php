<?php
// src/Core/Choice/InstantPickChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\Core\CardInstance;
use Berserk\Core\InstantProcessor;
use Berserk\View\Ui\PanelSpec;

final class InstantPickChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_instant_pick';
    }

    public function commandTypes(): array
    {
        return ['choose_instant_pick', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pi = $state->battle['pending_instant_pick'] ?? null;
        if (!$pi) return null;

        $source  = $state->getCard($pi['card_id']);
        $srcName = $source ? ($cardsInfo[$source->ukid]['name'] ?? '?') : '?';

        if ($pi['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $srcName . ' — выбор цели',
                isMine: false,
            );
        }

        $condition = $pi['effect']['condition'] ?? null;
        $instantProcessor = new InstantProcessor($state, new Engine());

        $buttons = [];
        foreach ($state->cards as $c) {
            if ($c->zone !== CardInstance::ZONE_FIELD
                && $c->zone !== CardInstance::ZONE_FLYING) continue;
            if ($c->dying || $c->hp <= 0) continue;

            if (($pi['target'] ?? 'enemy') === 'enemy' && $c->owner === $playerKey) continue;
            if (($pi['target'] ?? 'enemy') === 'ally'  && $c->owner !== $playerKey) continue;
            if (($pi['target'] ?? 'enemy') === 'enemy_open_creature') {
                if ($c->owner === $playerKey) continue;
                if ($c->closed) continue;
                if ($c->type !== 'creature' && $c->type !== 'fly') continue;
                if (empty($instantProcessor->findForcedStrikeAdjacentTargets($c))) continue;
            }
            if ($condition === 'target_not_moved' && !empty($c->flags['moved_this_turn'])) continue;
            if ($condition === 'target_closed' && !$c->closed) continue;

            $name = $cardsInfo[$c->ukid]['name'] ?? '?';
            $coords = $c->zone === CardInstance::ZONE_FIELD
                ? ' (' . $c->row . ',' . $c->col . ')' : '';

            $buttons[] = [
                'label' => $name . $coords . ' (' . $c->hp . '/' . $c->hpMax . ')',
                'url'   => "{$baseUrl}&cmd=choose_instant_pick&target_id={$c->instanceId}",
            ];
        }

        $buttons[] = [
            'label' => 'Отмена',
            'url'   => $baseUrl . '&cmd=cancel_pending',
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: $srcName . ': ' . ($pi['label'] ?? 'выбери цель'),
            buttons: $buttons,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        if ($cmd->type === 'cancel_pending') {
            return (new \Berserk\Core\ActionResolver($state, $engine))->cancelPending($playerKey, $cmd);
        }
        $turn = new \Berserk\Core\TurnPhaseProcessor($state, $engine);
        return $turn->chooseInstantPick($playerKey, $cmd);
    }
}
