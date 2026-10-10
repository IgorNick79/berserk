<?php
// src/Core/Choice/IncarnationWoundChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class IncarnationWoundChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_incarnation_wound'; }

    public function commandTypes(): array
    {
        return ['choose_incarnation_wound'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $queue = $state->battle['pending_incarnation_wound'] ?? [];
        if (empty($queue)) return null;

        $item = $queue[0];
        $src = $state->getCard((int) $item['source_id']);
        $srcName = $src ? ($cardsInfo[$src->ukid]['name'] ?? '?') : '?';

        if ($item['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $srcName . ': могильная хватка',
                isMine: false,
            );
        }

        // Дефолт — чужие, кнопка переключает на "Все"
        $side = (string) ($_GET['iw_side'] ?? 'enemy');
        if (!in_array($side, ['enemy', 'all'], true)) $side = 'enemy';

        $count = (int) $item['count'];
        $value = (int) $item['value'];

        $items = [];
        foreach ($item['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            if ($tc->dying || $tc->hp <= 0) continue;
            $isOwn = ($tc->owner === $playerKey);
            if ($side === 'enemy' && $isOwn) continue;

            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $label = $tn . ' (' . $tc->row . ';' . $tc->col . ') — '
                . $tc->hp . '/' . $tc->hpMax;
            if ($isOwn) $label .= ' — моё';

            $items[] = [
                'value' => (int) $tid,
                'label' => $label,
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        $toggleLabel = ($side === 'enemy') ? 'Все существа' : 'Только чужие';
        $toggleSide  = ($side === 'enemy') ? 'all' : 'enemy';

        $buttons = [
            [
                'label' => $toggleLabel,
                'url'   => $baseUrl . '&iw_side=' . $toggleSide,
            ],
        ];

        if (empty($items)) {
            return new PanelSpec(
                title: $srcName . ': могильная хватка — нет целей на этой стороне',
                buttons: $buttons,
            );
        }

        return new PanelSpec(
            title: $srcName . ': могильная хватка — выбери ' . $count
                . ' цел' . ($count === 1 ? 'ь' : 'и') . ' (по ' . $value . ' урона)',
            buttons: $buttons,
            form: [
                'type'   => 'checkbox',
                'name'   => 'target_ids[]',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_incarnation_wound',
                    'iw_side'  => $side,
                ],
                'submit' => 'Ранить',
            ],
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        return (new \Berserk\Core\ActionResolver($state, $engine))
            ->chooseIncarnationWound($playerKey, $cmd);
    }
}