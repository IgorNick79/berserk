<?php
// src/Core/Choice/WhipChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\{CreatureChoice, Form, PanelSpec};

final class WhipChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_whip';
    }

    public function commandTypes(): array
    {
        return ['choose_whip_target', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pw = $state->battle['pending_whip'] ?? null;
        if (!$pw) return null;

        $source = $state->getCard($pw['source_id']);
        $srcName = $source ? ($cardsInfo[$source->ukid]['name'] ?? '?') : '?';

        if ($pw['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $srcName . ' — щелчок хлыста',
                isMine: false,
            );
        }

        $items = [];
        foreach ($pw['targets'] as $targetId) {
            $target = $state->getCard((int) $targetId);
            if (!$target || $target->dying || $target->hp <= 0) continue;
            $items[] = [
                'value' => $target->instanceId,
                'suffix' => $target->instanceId === (int) $pw['source_id'] ? ' (сам)' : '',
            ];
        }

        return CreatureChoice::targetPanel(
            $state, $playerKey, $cardsInfo, $items, 'whip:' . $pw['source_id'] . ':' . ($state->battle['turn'] ?? 0),
            $srcName . ' — Щелчок хлыста: выберите цель',
            Form::battleHidden($role, $state->gameId, 'choose_whip_target'),
            $baseUrl . '&cmd=cancel_pending',
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ar = new \Berserk\Core\ActionResolver($state, $engine);
        if ($cmd->type === 'cancel_pending') {
            return $ar->cancelPending($playerKey, $cmd);
        }
        return $ar->chooseWhipTarget($playerKey, $cmd);
    }
}
