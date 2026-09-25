<?php
// src/Core/Choice/TurnPhaseChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;
use Berserk\View\Ui\TaskCard;

final class TurnPhaseChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'turn_phase'; }
    public function commandTypes(): array { return ['turn_task']; }

    public function isActive(GameState $state): bool
    {
        $tp = $state->battle['turn_phase'] ?? null;
        if (!$tp) return false;
        if (!empty($tp['pending_ack'])) return false;
        if (!empty($tp['sub'])) return false;

        $side  = $tp['side'];
        $queue = $tp[$side . '_queue'] ?? [];

        // При 1 задаче — auto-run (TurnPhaseProcessor::advance() сам вызовет)
        return count($queue) >= 2;
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $tp = $state->battle['turn_phase'] ?? null;
        if (!$tp) return null;

        $side     = $tp['side'];
        $ownerKey = $side === 'passive' ? $tp['passive_key'] : $tp['active_key'];
        $queue    = $tp[$side . '_queue'] ?? [];

        if ($ownerKey !== $playerKey) {
            return new PanelSpec(
                title: 'Оппонент выбирает действие',
                isMine: false,
            );
        }

        $cards = [];
        foreach ($queue as $task) {
            $url = $baseUrl . '&cmd=turn_task&task_id=' . urlencode($task['id']);

            $render = [
                'label'      => $task['label'] ?? '',
                'card_ukid'  => $task['card_ukid'] ?? $task['ukid'] ?? null,
                'row'        => $task['row'] ?? null,
                'col'        => $task['col'] ?? null,
                'hint'       => $task['label'] ?? '',
                'is_instant' => !empty($task['is_instant']),
            ];
            $cards[] = TaskCard::render($render, $url, $cardsInfo);
        }

        return new PanelSpec(
            title: 'Выбери действие:',
            cards: $cards,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $tp = new \Berserk\Core\TurnPhaseProcessor($state, $engine);
        return $tp->runTask($playerKey, $cmd);
    }
}