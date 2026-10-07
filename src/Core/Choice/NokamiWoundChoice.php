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

        if ($item['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $srcName . ': реакция на яд',
                isMine: false,
            );
        }

        // Дефолт — только существа противника. Кнопка переключает на "Все".
        $side = (string) ($_GET['n_side'] ?? 'enemy');
        if (!in_array($side, ['enemy', 'all'], true)) $side = 'enemy';

        $items = [];
        $first = true;
        foreach ($item['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            if ($tc->dying || $tc->hp <= 0) continue;
            $isOwn = ($tc->owner === $playerKey);
            if ($side === 'enemy' && $isOwn) continue;

            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $label = $tn . ' (' . $tc->row . ';' . $tc->col . ') — ' . $tc->hp . '/' . $tc->hpMax;
            if ($isOwn) $label .= ' — моё';

            $items[] = [
                'value'   => (int) $tid,
                'label'   => $label,
                'checked' => $first,
            ];
            $first = false;
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        $toggleLabel = ($side === 'enemy') ? 'Все существа' : 'Только чужие';
        $toggleSide  = ($side === 'enemy') ? 'all' : 'enemy';

        // Без cmd — просто смена GET-параметра, страница перерисуется.
        $buttons = [
            [
                'label' => $toggleLabel,
                'url'   => $baseUrl . '&n_side=' . $toggleSide,
            ],
        ];

        if (empty($items)) {
            return new PanelSpec(
                title: $srcName . ': нет целей на этой стороне',
                buttons: array_merge($buttons, [[
                    'label' => 'Отмена',
                    'url'   => $baseUrl . '&cmd=choose_nokami_wound&target_id=0',
                    'class' => 'skip',
                ]]),
            );
        }

        return new PanelSpec(
            title: $srcName . ': кто-то получил урон от яда. Ранить существо рядом на '
                . (int) $item['value'] . '?',
            buttons: $buttons,
            form: [
                'type'   => 'radio',
                'name'   => 'target_id',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_nokami_wound',
                    'n_side'   => $side,
                ],
                'submit' => 'Ранить',
                'cancel' => $baseUrl . '&cmd=choose_nokami_wound&target_id=0',
            ],
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