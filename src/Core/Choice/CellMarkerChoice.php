<?php
// src/Core/Choice/CellMarkerChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class CellMarkerChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_cell_marker_pick';
    }

    public function commandTypes(): array
    {
        return ['choose_cell_marker'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pm = $state->battle['pending_cell_marker_pick'] ?? null;
        if (!$pm) return null;

        $attCard = $state->getCard($pm['card_id']);
        $attName = $attCard ? ($cardsInfo[$attCard->ukid]['name'] ?? '?') : '?';

        if ($pm['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $attName . ' — ' . $pm['label'],
                isMine: false,
            );
        }

        $buttons = [];
        foreach ($pm['cells'] as $cellKey) {
            [$r, $c] = explode('_', $cellKey);
            $buttons[] = [
                'label' => "({$r},{$c})",
                'url'   => "{$baseUrl}&cmd=choose_cell_marker&row={$r}&col={$c}",
            ];
        }
        $buttons[] = [
            'label' => 'Отмена',
            'url'   => $baseUrl . '&cmd=cancel_pending',
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: $attName . ' — ' . $pm['label'] . ': выбери клетку',
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
        return $ar->chooseCellMarker($playerKey, $cmd);
    }
}