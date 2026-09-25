<?php
// src/Core/Choice/IncarnationChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\Core\CardInstance;
use Berserk\View\Ui\PanelSpec;

final class IncarnationChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_incarnation';
    }

    public function commandTypes(): array
    {
        return ['choose_incarnation_cell'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pi = $state->battle['pending_incarnation'] ?? null;
        if (!$pi) return null;

        $card = $state->getCard($pi['current']);
        $name = $card ? ($cardsInfo[$card->ukid]['name'] ?? '?') : '?';

        if ($pi['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $name . ' инкарнирует',
                isMine: false,
                waitText: 'Ожидание выбора клетки оппонентом...',
            );
        }

        $backRow = $playerKey === 'host' ? 1 : 6;
        $buttons = [];
        for ($col = 1; $col <= 5; $col++) {
            $occupied = false;
            foreach ($state->cards as $c) {
                if ($c->zone === CardInstance::ZONE_FIELD
                    && $c->row === $backRow && $c->col === $col) {
                    $occupied = true;
                    break;
                }
            }
            if ($occupied) continue;

            $buttons[] = [
                'label' => 'Клетка ' . $backRow . '_' . $col,
                'url'   => "{$baseUrl}&cmd=choose_incarnation_cell&row={$backRow}&col={$col}",
            ];
        }

        if (empty($buttons)) {
            return new PanelSpec(
                title: 'Нет места для инкарнации ' . $name,
                isMine: true,
            );
        }

        return new PanelSpec(
            title: $name . ' инкарнирует — выбери клетку в заднем ряду',
            buttons: $buttons,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $turn = new \Berserk\Core\TurnProcessor($state, $engine);
        return $turn->chooseIncarnationCell($playerKey, $cmd);
    }
}