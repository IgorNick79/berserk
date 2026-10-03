<?php
// src/Core/Choice/GreedTeleportChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class GreedTeleportChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_greed_teleport'; }
    public function commandTypes(): array { return ['choose_greed_teleport']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_greed_teleport'] ?? null;
        if (!$p) return null;

        $src = $state->getCard((int) $p['source_id']);
        $srcName = $src ? ($cardsInfo[$src->ukid]['name'] ?? '?') : '?';

        if ($p['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $srcName . ' — выбор клетки для телепорта',
                isMine: false,
            );
        }

        $isHost = $playerKey === 'host';

        $buttons = [];
        foreach ($p['free_gates'] as $k) {
            [$row, $col] = explode('_', $k);
            $row = (int) $row; $col = (int) $col;

            $uiRow = $isHost ? $row : (7 - $row);
            $uiCol = $isHost ? $col : (6 - $col);

            $buttons[] = [
                'label' => "({$uiRow};{$uiCol})",
                'url'   => "{$baseUrl}&cmd=choose_greed_teleport&row={$row}&col={$col}",
            ];
        }

        return new PanelSpec(
            title: $srcName . ': куда телепортироваться?',
            buttons: $buttons,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        return (new \Berserk\Core\ActionResolver($state, $engine))
            ->chooseGreedTeleport($playerKey, $cmd);
    }
}