<?php
// src/Core/Choice/RowPickChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class RowPickChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_row_pick';
    }

    public function commandTypes(): array
    {
        return ['choose_row', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_row_pick'] ?? null;
        if (!$p) return null;

        $card = $state->getCard((int) $p['card_id']);
        $name = $card ? ($cardsInfo[$card->ukid]['name'] ?? '?') : '?';

        if ($p['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $name . ' — выбор ряда',
                isMine: false,
            );
        }

        $buttons = [];
        for ($uiRow = 1; $uiRow <= 6; $uiRow++) {
            $buttons[] = [
                'label' => 'Ряд ' . $uiRow,
                'url'   => "{$baseUrl}&cmd=choose_row&row={$uiRow}",
            ];
        }
        $buttons[] = [
            'label' => 'Отмена',
            'url'   => $baseUrl . '&cmd=cancel_pending',
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: $name . ': выбери ряд',
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
            return (new \Berserk\Core\ActionResolver($state, $engine))
                ->cancelPending($playerKey, $cmd);
        }
        return (new \Berserk\Core\ActionResolver($state, $engine))
            ->chooseRow($playerKey, $cmd);
    }
}