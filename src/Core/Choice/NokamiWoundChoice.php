<?php
// src/Core/Choice/NokamiWoundChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class NokamiWoundChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_nokami_wound'; }
    public function commandTypes(): array { return ['choose_nokami_wound', 'cancel_pending']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $queue = $state->battle['pending_nokami_wound'] ?? [];
        if (empty($queue)) return null;

        $item = $queue[0];
        $src = $state->getCard((int) $item['source_id']);
        $srcName = $src ? ($cardsInfo[$src->ukid]['name'] ?? '?') : '?';
        $poisonedName = $cardsInfo[$item['poisoned_ukid']]['name'] ?? '?';

        if ($item['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $srcName . ': ' . $poisonedName . ' отравлен, выбор цели',
                isMine: false,
            );
        }

        $buttons = [];
        foreach ($item['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $label = $tn . ' (' . $tc->row . ';' . $tc->col . ') — ' . $tc->hp . '/' . $tc->hpMax;
            if ($tc->owner === $playerKey) $label .= ' — моё';

            $buttons[] = [
                'label' => $label,
                'url'   => "{$baseUrl}&cmd=choose_nokami_wound&target_id={$tid}",
            ];
        }

        $buttons[] = [
            'label' => 'Отмена',
            'url'   => "{$baseUrl}&cmd=choose_nokami_wound&target_id=0",
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: $srcName . ': ' . $poisonedName . ' получил урон от яда. Ранить кого-то рядом на '
                . (int) $item['value'] . '?',
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
            ->chooseNokamiWound($playerKey, $cmd);
    }
}