<?php
// src/Core/Choice/OpponentRowMarkerChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class OpponentRowMarkerChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_opponent_row_marker';
    }

    public function commandTypes(): array
    {
        return ['choose_opponent_row_marker', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pending = $state->battle['pending_opponent_row_marker'] ?? null;
        if (!$pending) return null;

        $card = $state->getCard((int) ($pending['card_id'] ?? 0));
        $name = $card ? ($cardsInfo[$card->ukid]['name'] ?? '?') : '?';

        if (($pending['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $name . ' — выбор ряда противника',
                isMine: false,
            );
        }

        $buttons = [];
        foreach ((array) ($pending['rows'] ?? []) as $row) {
            $row = (int) $row;
            $buttons[] = [
                'label' => 'Ряд ' . $row,
                'url'   => "{$baseUrl}&cmd=choose_opponent_row_marker&row={$row}",
            ];
        }
        $buttons[] = [
            'label' => 'Отмена',
            'url'   => $baseUrl . '&cmd=cancel_pending',
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: $name . ': выбери ряд половины противника',
            buttons: $buttons,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $resolver = new \Berserk\Core\ActionResolver($state, $engine);
        if ($cmd->type === 'cancel_pending') {
            return $resolver->cancelPending($playerKey, $cmd);
        }
        return $resolver->chooseOpponentRowMarker($playerKey, $cmd);
    }
}
