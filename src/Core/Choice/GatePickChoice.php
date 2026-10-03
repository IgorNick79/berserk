<?php
// src/Core/Choice/GatePickChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class GatePickChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_gate_pick'; }
    public function commandTypes(): array { return ['choose_gate', 'cancel_pending']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_gate_pick'] ?? null;
        if (!$p) return null;

        $src = $state->getCard((int) $p['source_id']);
        $srcName = $src ? ($cardsInfo[$src->ukid]['name'] ?? '?') : '?';

        if ($p['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $srcName . ' — выбор клеток для врат',
                isMine: false,
            );
        }

        $need   = (int) $p['count'];
        $chosen = count($p['chosen']);
        $left   = $need - $chosen;

        $isHost = $playerKey === 'host';

        $buttons = [];
        foreach ($p['candidates'] as $k) {
            [$row, $col] = explode('_', $k);
            $row = (int) $row; $col = (int) $col;

            // Зеркалирование для player
            $uiRow = $isHost ? $row : (7 - $row);
            $uiCol = $isHost ? $col : (6 - $col);

            $buttons[] = [
                'label' => "({$uiRow};{$uiCol})",
                'url'   => "{$baseUrl}&cmd=choose_gate&row={$row}&col={$col}",
            ];
        }
        $buttons[] = [
            'label' => 'Отмена',
            'url'   => $baseUrl . '&cmd=cancel_pending',
            'class' => 'skip',
        ];

        $title = $srcName . ': выбери клетку для врат';
        if ($chosen > 0) {
            $title .= ' (осталось ' . $left . ')';
        } else {
            $title .= ' (нужно ' . $need . ')';
        }

        return new PanelSpec(title: $title, buttons: $buttons);
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
            ->chooseGate($playerKey, $cmd);
    }
}