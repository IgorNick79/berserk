<?php
// src/Core/Choice/WildHealerMoveChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class WildHealerMoveChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_wild_healer_move'; }
    public function commandTypes(): array { return ['choose_wild_healer_heal', 'cancel_pending']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_wild_healer_move'] ?? null;
        if (!$p) return null;

        $healer = $state->getCard((int) $p['card_id']);
        $hName = $healer ? ($cardsInfo[$healer->ukid]['name'] ?? '?') : '?';

        if ($p['owner'] !== $playerKey) {
            return new PanelSpec(title: $hName . ' — излечение при перемещении', isMine: false);
        }

        // Дефолт — мои существа
        $side = (string) ($_GET['wh_side'] ?? 'own');
        if (!in_array($side, ['own', 'all'], true)) $side = 'own';

        $items = [];
        foreach ($p['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            if ($tc->dying || $tc->hp <= 0) continue;
            $isOwn = ($tc->owner === $playerKey);
            if ($side === 'own' && !$isOwn) continue;

            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $label = $tn . ' (' . $tc->row . ';' . $tc->col . ') — ' . $tc->hp . '/' . $tc->hpMax;
            if (!$isOwn) $label .= ' — чужое';

            $items[] = ['value' => (int) $tid, 'label' => $label];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';
        $x = (int) $p['value'];

        $toggleLabel = ($side === 'own') ? 'Все существа' : 'Только мои';
        $toggleSide  = ($side === 'own') ? 'all' : 'own';

        $buttons = [
            ['label' => $toggleLabel, 'url' => $baseUrl . '&wh_side=' . $toggleSide],
        ];

        if (empty($items)) {
            return new PanelSpec(
                title: $hName . ': нет целей на этой стороне',
                buttons: array_merge($buttons, [[
                    'label' => 'Отмена',
                    'url'   => $baseUrl . '&cmd=cancel_pending',
                    'class' => 'skip',
                ]]),
            );
        }

        return new PanelSpec(
            title: $hName . ': излечить соседа на ' . $x . '?',
            buttons: $buttons,
            form: [
                'type'   => 'radio',
                'name'   => 'target_id',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_wild_healer_heal',
                    'wh_side'  => $side,
                ],
                'submit' => 'Излечить',
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
        return (new \Berserk\Core\ActionResolver($state, $engine))
            ->chooseWildHealerHeal($playerKey, $cmd);
    }
}