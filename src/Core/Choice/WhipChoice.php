<?php
// src/Core/Choice/WhipChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\{CreatureChoice, PanelSpec};

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

        $links = [];
        foreach ($pw['targets'] as $tid) {
            $links[] = [
                'instanceId' => $tid,
                'url' => "{$baseUrl}&cmd=choose_whip_target&target_id={$tid}",
                'suffix' => (int) $tid === (int) $pw['source_id'] ? ' (сам)' : '',
            ];
        }
        $buttons = CreatureChoice::immediateButtons($state, $playerKey, $cardsInfo, $links);
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
