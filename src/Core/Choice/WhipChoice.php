<?php
// src/Core/Choice/WhipChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

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

        $buttons = [];
        foreach ($pw['targets'] as $tid) {
            $tc = $state->getCard($tid);
            if (!$tc) continue;
            $ti = $cardsInfo[$tc->ukid] ?? null;
            $tn = $ti ? $ti['name'] : '?';
            $suffix = ($tc->instanceId === $pw['source_id']) ? ' (сам)' : '';

            $buttons[] = [
                'label' => $tn . $suffix . ' (' . $tc->hp . '/' . $tc->hpMax . ')',
                'url'   => "{$baseUrl}&cmd=choose_whip_target&target_id={$tid}",
            ];
        }

        $buttons[] = [
            'label' => 'Отмена',
            'url'   => $baseUrl . '&cmd=cancel_pending',
            'class' => 'skip',
        ];
        
        return new PanelSpec(
            title: $srcName . ' — щелчок хлыста: выбери цель',
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
        if ($cmd->type === 'cancel_pending') {
            return $ar->cancelPending($playerKey, $cmd);
        }
        return $ar->chooseWhipTarget($playerKey, $cmd);
    }
}
