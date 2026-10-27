<?php
// src/Core/Choice/HealInsteadWoundChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class HealInsteadWoundChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_heal_instead_wound'; }
    public function commandTypes(): array { return ['choose_heal_instead_wound', 'cancel_pending']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_heal_instead_wound'] ?? null;
        if (!$p) return null;

        $vozhak = $state->getCard((int) $p['target_id']);
        $vName = $vozhak ? ($cardsInfo[$vozhak->ukid]['name'] ?? '?') : '?';

        if ($p['owner'] !== $playerKey) {
            return new PanelSpec(title: $vName . ' — рана вместо излечения', isMine: false);
        }

        $side = (string) ($_GET['hw_side'] ?? 'enemy');
        if (!in_array($side, ['enemy', 'all'], true)) $side = 'enemy';

        $items = [];
        foreach ($p['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            if ($tc->dying || $tc->hp <= 0) continue;
            $isOwn = ($tc->owner === $playerKey);
            if ($side === 'enemy' && $isOwn) continue;

            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $label = $tn . ' (' . $tc->row . ';' . $tc->col . ') — ' . $tc->hp . '/' . $tc->hpMax;
            if ($isOwn) $label .= ' — моё';

            $items[] = ['value' => (int) $tid, 'label' => $label];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';
        $x = (int) $p['heal_value'];
        $healingSuffix = isset($p['token']) ? '&heal_token=' . rawurlencode($p['token']) : '';

        $toggleLabel = ($side === 'enemy') ? 'Все существа' : 'Только чужие';
        $toggleSide  = ($side === 'enemy') ? 'all' : 'enemy';

        $buttons = [
            ['label' => $toggleLabel, 'url' => $baseUrl . '&hw_side=' . $toggleSide],
        ];

        if (empty($items)) {
            return new PanelSpec(
                title: $vName . ': нет соседей на этой стороне',
                buttons: array_merge($buttons, [[
                    'label' => 'Отмена',
                    'url'   => $baseUrl . '&cmd=cancel_pending' . $healingSuffix,
                    'class' => 'skip',
                ]]),
            );
        }

        $textLines = [];
        if (!empty($p['healed_summary'])) {
            $parts = [];
            foreach ($p['healed_summary'] as $h) {
                $tc = $state->getCard((int) $h['target_id']);
                if (!$tc) continue;
                $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
                $parts[] = $tn . ' +' . (int) $h['heal'] . ' HP';
            }
            if (!empty($parts)) {
                $textLines[] = 'Уже излечены: ' . implode(', ', $parts);
            }
        }

        return new PanelSpec(
            title: $vName . ': рана вместо излечения — выбери соседа (урон ' . $x . ')',
            text: $textLines,
            buttons: $buttons,
            form: [
                'type'   => 'radio',
                'name'   => 'target_id',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_heal_instead_wound',
                    'hw_side'  => $side,
                    'heal_token' => $p['token'] ?? '',
                ],
                'submit' => 'Ранить',
                'cancel' => $baseUrl . '&cmd=cancel_pending' . $healingSuffix,
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
            ->chooseHealInsteadWound($playerKey, $cmd);
    }
}