<?php
// src/Core/Choice/ForcedStrikeAdjacentChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\InstantProcessor;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class ForcedStrikeAdjacentChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_forced_strike_adjacent'; }
    public function commandTypes(): array { return ['choose_forced_strike_adjacent', 'cancel_pending']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pending = $state->battle['pending_forced_strike_adjacent'] ?? null;
        if (!$pending) return null;

        $attacker = $state->getCard((int) ($pending['attacker_id'] ?? 0));
        $attName = $attacker ? ($cardsInfo[$attacker->ukid]['name'] ?? '?') : '?';

        if (($pending['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(title: $attName . ' — выбор цели удара', isMine: false);
        }

        $buttons = [];
        foreach ((array) ($pending['candidates'] ?? []) as $targetId) {
            $target = $state->getCard((int) $targetId);
            if (!$target) continue;

            $name = $cardsInfo[$target->ukid]['name'] ?? '?';
            $coords = $target->row !== null && $target->col !== null
                ? ' (' . $target->row . ',' . $target->col . ')'
                : '';
            $buttons[] = [
                'label' => $name . $coords . ' (' . $target->hp . '/' . $target->hpMax . ')',
                'url'   => "{$baseUrl}&cmd=choose_forced_strike_adjacent&target_id={$target->instanceId}",
            ];
        }

        $buttons[] = [
            'label' => 'Отмена',
            'url'   => $baseUrl . '&cmd=cancel_pending',
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: $attName . ': выберите соседнюю карту для удара',
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

        return (new InstantProcessor($state, $engine))->chooseForcedStrikeAdjacent($playerKey, $cmd);
    }
}
