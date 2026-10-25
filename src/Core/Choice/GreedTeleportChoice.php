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

        $buttons = [];
        foreach ($p['free_gates'] as $k) {
            [$row, $col] = explode('_', $k);
            $row = (int) $row; $col = (int) $col;

            if (\Berserk\Core\ZoneManager::hasBlockingMarker($state, $k)) continue;
            $occupied = false;
            foreach ($state->cards as $other) {
                if ($other->zone === \Berserk\Core\CardInstance::ZONE_FIELD && $other->row === $row && $other->col === $col && $other->instanceId !== $p['source_id']) $occupied = true;
            }
            if ($occupied) continue;
            $buttons[] = [
                'label' => \Berserk\View\Ui\BattlefieldPosition::label($row, $col, $playerKey),
                'label_html' => \Berserk\View\Ui\Coords::render($row, $col, $playerKey),
                'url' => "{$baseUrl}&cmd=choose_greed_teleport&row={$row}&col={$col}",
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