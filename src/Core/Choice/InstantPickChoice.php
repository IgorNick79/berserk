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
use Berserk\View\Ui\{CardSelectionSpec, CreatureChoice, Form, PanelSpec};

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

        $rows = [];
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

            $rows[] = ['value'=>$c->instanceId];
        }

        $cancel = $baseUrl . '&cmd=cancel_pending';
        return CreatureChoice::targetPanel(
            $state, $playerKey, $cardsInfo, $rows, 'choose_instant_pick:' . $state->version,
            $srcName . ': ' . ($pi['label'] ?? 'выбери цель'),
            Form::battleHidden($role, $state->gameId, 'choose_instant_pick'),
            $cancel,
            mode: ($pi['effect']['type'] ?? '') === 'heal' ? CardSelectionSpec::DEFAULT_MODE : CardSelectionSpec::SIMPLE,
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
