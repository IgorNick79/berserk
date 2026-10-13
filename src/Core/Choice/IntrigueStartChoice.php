<?php
// src/Core/Choice/IntrigueStartChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class IntrigueStartChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_intrigue_start'; }
    public function commandTypes(): array { return ['choose_intrigue_start']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $queue = $state->battle['pending_intrigue_start'] ?? [];
        if (empty($queue)) return null;

        $item = $queue[0];
        $src = $state->getCard((int) $item['source_id']);
        $srcName = $src ? ($cardsInfo[$src->ukid]['name'] ?? '?') : '?';
        $effectType = (string) ($item['effect']['type'] ?? '');
        $count = (int) $item['count'];

        if (($item['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $srcName . ' — интриги',
                isMine: false,
            );
        }

        /// Toggle только для poison. Дефолт — чужие, кнопка переключает на «Все».
        $side = 'enemy';
        if ($effectType === 'poison') {
            $side = (string) ($_GET['in_side'] ?? 'enemy');
            if (!in_array($side, ['all', 'enemy'], true)) $side = 'enemy';
        }

        $items = [];
        foreach ($item['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            if ($tc->dying || $tc->hp <= 0) continue;

            $isOwn = ($tc->owner === $playerKey);
            if ($effectType === 'poison' && $side === 'enemy' && $isOwn) continue;

            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $coords = $tc->zone === \Berserk\Core\CardInstance::ZONE_FIELD
                ? ' (' . $tc->row . ';' . $tc->col . ')'
                : '';
            $label = $tn . $coords . ' — ' . $tc->hp . '/' . $tc->hpMax;
            if ($isOwn) $label .= ' — моё';

            $items[] = ['value' => (int) $tid, 'label' => $label];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        $buttons = [];
        if ($effectType === 'poison') {
            $toggleLabel = ($side === 'all') ? 'Только чужие' : 'Все существа';
            $toggleSide  = ($side === 'all') ? 'enemy' : 'all';
            $buttons[] = [
                'label' => $toggleLabel,
                'url'   => $baseUrl . '&in_side=' . $toggleSide,
            ];
        }

        if (empty($items)) {
            return new PanelSpec(
                title: $srcName . ': нет целей на этой стороне',
                buttons: $buttons,
            );
        }

        $actionLabel = $effectType === 'close' ? 'Закрыть' : 'Отравить';
        $title = $srcName . ': выбери ' . $count . ' цел'
            . ($count === 1 ? 'ь' : 'и') . ' для интриги';

        return new PanelSpec(
            title: $title,
            buttons: $buttons,
            form: [
                'type'   => 'checkbox',
                'name'   => 'target_ids[]',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_intrigue_start',
                    'in_side'  => $side,
                ],
                'submit' => $actionLabel,
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
            ->chooseIntrigueStart($playerKey, $cmd);
    }
}