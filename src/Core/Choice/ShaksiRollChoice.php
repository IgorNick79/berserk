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
                title: $srcName . ' — прыжок: выбор цели',
                isMine: false,
            );
        }

        $dice=(int)($p['dice']??0);
        $diceHtml=$dice>=1 && $dice<=6 ? '<div class="dice-block"><div class="dice-column"><div class="label">Кубик</div><div class="dice">'.$dice.'</div></div></div>' : '<p>Результат кубика не сохранён.</p>';
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
            $coords = \Berserk\View\Ui\Coords::renderResult($tc->row,$tc->col,$playerKey);
            $suffix = ' — ' . $tc->hp . '/' . $tc->hpMax;
            if ($isSelf) $suffix .= ' — Шакси';
            elseif ($isOwn) $suffix .= ' — моё';
            $label = htmlspecialchars((string)$tn,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')
                .($coords!==''?' '.$coords:'').htmlspecialchars($suffix,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');

            $items[] = ['value' => (int) $tid, 'label_html' => $label];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        $titles = [
            'damage'   => 'ранить на ' . $value,
            'heal'     => 'излечить на ' . $value,
            'get_coin' => 'дать монету (' . $value . ')',
        ];
        $effectLabel = $titles[$effectType] ?? 'применить';
        $title = $srcName . ' — ' . $effectLabel;

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
                text: [$diceHtml],
                buttons: $buttons,
            );
        }

        return new PanelSpec(
            title: $title,
            text: [$diceHtml],
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