<?php
// src/Core/Choice/ForcedDirectionalMoveChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Movement\ForcedMovementResolver;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class ForcedDirectionalMoveChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return ForcedMovementResolver::PENDING_KEY;
    }

    public function commandTypes(): array
    {
        return ['choose_forced_directional_move', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pending = $state->battle[ForcedMovementResolver::PENDING_KEY] ?? null;
        if (!$pending) return null;

        if (($pending['owner'] ?? '') !== $playerKey) {
            return new PanelSpec(
                title: 'Обязательное перемещение',
                isMine: false,
            );
        }

        $forced = new ForcedMovementResolver($state, new Engine());
        $deltaRow = (int) ($pending['delta_row'] ?? 0);
        $deltaCol = (int) ($pending['delta_col'] ?? 0);

        $buttons = [];
        foreach ($forced->eligibleTargets($playerKey, $deltaRow, $deltaCol) as $card) {
            $name = $cardsInfo[$card->ukid]['name'] ?? $card->ukid;
            $destination = $forced->destination($card, $deltaRow, $deltaCol);
            if ($destination === null) continue;

            $buttons[] = [
                'label' => $name . ' -> (' . $destination['row'] . ',' . $destination['col'] . ')',
                'url'   => "{$baseUrl}&cmd=choose_forced_directional_move&target_id={$card->instanceId}",
            ];
        }
        $buttons[] = [
            'label' => 'Закрыть',
            'url'   => "{$baseUrl}&cmd=cancel_pending",
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: 'Выберите существо для обязательного перемещения',
            buttons: $buttons,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $pending = $state->battle[ForcedMovementResolver::PENDING_KEY] ?? null;
        if (!$pending) return Result::error('Нет ожидающего выбора');
        if (($pending['owner'] ?? '') !== $playerKey) return Result::error('Не ваш выбор');

        if ($cmd->type === 'cancel_pending') {
            return $this->decline($state, $pending);
        }

        $forced = new ForcedMovementResolver($state, $engine);
        $deltaRow = (int) ($pending['delta_row'] ?? 0);
        $deltaCol = (int) ($pending['delta_col'] ?? 0);
        $targetId = (int) $cmd->get('target_id', 0);
        $target = $state->getCard($targetId);

        if (!$target || $target->owner !== $playerKey) {
            return Result::error('Неверная цель');
        }
        if (!$forced->canMove($target, $deltaRow, $deltaCol)) {
            return $this->decline($state, $pending, 'forced_directional_move_impossible');
        }

        unset($state->battle[ForcedMovementResolver::PENDING_KEY]);
        return $forced->move($target, $deltaRow, $deltaCol);
    }

    private function decline(GameState $state, array $pending, string $event = 'forced_directional_move_declined'): Result
    {
        $source = $state->getCard((int) ($pending['source_id'] ?? 0));
        unset($state->battle[ForcedMovementResolver::PENDING_KEY]);

        if ($source) {
            ForcedMovementResolver::applyFallback($source, (array) ($pending['fallback'] ?? []));
        }

        $state->bumpVersion();
        return Result::ok([$event]);
    }
}
