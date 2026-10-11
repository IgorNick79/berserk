<?php
// src/Core/Choice/BloodStormChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class BloodStormChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_blood_storm'; }
    public function commandTypes(): array { return ['choose_blood_storm', 'cancel_pending']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_blood_storm'] ?? null;
        if (!$p) return null;

        $src = $state->getCard((int) $p['card_id']);
        $srcName = $src ? ($cardsInfo[$src->ukid]['name'] ?? '?') : '?';

        if ($p['owner'] !== $playerKey) {
            return new PanelSpec(title: $srcName . ' — буря крови', isMine: false);
        }

        $side = (string) ($_GET['bs_side'] ?? 'enemy');
        if (!in_array($side, ['enemy', 'all'], true)) $side = 'enemy';

        $items = [];
        foreach ($p['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            if ($tc->dying || $tc->hp <= 0) continue;
            $isOwn = ($tc->owner === $playerKey);
            if ($side === 'enemy' && $isOwn) continue;

            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $label = $tn . ' (' . $tc->row . ';' . $tc->col . ') — '
                . $tc->hp . '/' . $tc->hpMax;
            if ($isOwn) $label .= ' — моё';

            $items[] = ['value' => (int) $tid, 'label' => $label];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';
        $x = (int) $p['x'];

        $toggleLabel = ($side === 'enemy') ? 'Все существа' : 'Только чужие';
        $toggleSide  = ($side === 'enemy') ? 'all' : 'enemy';

        $buttons = [
            [
                'label' => $toggleLabel,
                'url'   => $baseUrl . '&bs_side=' . $toggleSide,
            ],
        ];

        if (empty($items)) {
            return new PanelSpec(
                title: $srcName . ': нет целей на этой стороне',
                buttons: array_merge($buttons, [[
                    'label' => 'Отмена',
                    'url'   => $baseUrl . '&cmd=cancel_pending',
                    'class' => 'skip',
                ]]),
            );
        }

        return new PanelSpec(
            title: $srcName . ': буря крови — выбери цель (урон ' . $x . ')',
            buttons: $buttons,
            form: [
                'type'   => 'radio',
                'name'   => 'target_id',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_blood_storm',
                    'bs_side'  => $side,
                ],
                'submit' => 'Ударить',
                'cancel' => $baseUrl . '&cmd=cancel_pending',
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
            ->chooseBloodStormTarget($playerKey, $cmd);
    }
}