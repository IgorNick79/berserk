<?php
// src/Core/Choice/ShaksiRollChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class ShaksiRollChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_shaksi_roll'; }
    public function commandTypes(): array { return ['choose_shaksi_roll']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_shaksi_roll'] ?? null;
        if (!$p) return null;

        $src = $state->getCard((int) $p['source_id']);
        $srcName = $src ? ($cardsInfo[$src->ukid]['name'] ?? '?') : '?';

        if (($p['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $srcName . ' — прыжок, кубик ' . (int) $p['dice'],
                isMine: false,
            );
        }

        $effect     = (array) $p['effect'];
        $effectType = (string) ($effect['type'] ?? '');
        $value      = (int) ($effect['value'] ?? 0);
        $defaultSide = (string) ($effect['default_side'] ?? 'own');
        $includeSelf = !empty($effect['include_self']);

        $side = (string) ($_GET['sh_side'] ?? $defaultSide);
        if (!in_array($side, ['own', 'enemy', 'all'], true)) $side = $defaultSide;

        $items = [];
        foreach ($p['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            if ($tc->dying || $tc->hp <= 0) continue;

            $isSelf = ($tc->instanceId === (int) $p['source_id']);
            $isOwn  = ($tc->owner === $playerKey);

            if (!$isSelf) {
                if ($side === 'own'   && !$isOwn) continue;
                if ($side === 'enemy' &&  $isOwn) continue;
            }

            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $coords = $tc->row !== null
                ? ' (' . $tc->row . ';' . $tc->col . ')'
                : '';
            $label = $tn . $coords . ' — ' . $tc->hp . '/' . $tc->hpMax;
            if ($isSelf) $label .= ' — Шакси';
            elseif ($isOwn) $label .= ' — моё';

            $items[] = ['value' => (int) $tid, 'label' => $label];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        $titles = [
            'damage'   => 'ранить на ' . $value,
            'heal'     => 'излечить на ' . $value,
            'get_coin' => 'дать монету (' . $value . ')',
        ];
        $effectLabel = $titles[$effectType] ?? 'применить';
        $title = $srcName . ': кубик ' . (int) $p['dice'] . ' — ' . $effectLabel;

        $sideOrder = ['own', 'enemy', 'all'];
        $nextSide = $sideOrder[(array_search($side, $sideOrder, true) + 1) % 3];

        $buttons = [[
            'label' => $nextSide === 'own' ? 'Только мои'
                     : ($nextSide === 'enemy' ? 'Только чужие' : 'Все существа'),
            'url'   => $baseUrl . '&sh_side=' . $nextSide,
        ]];

        if (empty($items)) {
            return new PanelSpec(
                title: $title . ' — нет целей на этой стороне',
                buttons: $buttons,
            );
        }

        return new PanelSpec(
            title: $title,
            buttons: $buttons,
            form: [
                'type'   => 'radio',
                'name'   => 'target_id',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_shaksi_roll',
                    'sh_side'  => $side,
                ],
                'submit' => 'Подтвердить',
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
            ->chooseShaksiRoll($playerKey, $cmd);
    }
}