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
        $diceHtml=$dice>=1 && $dice<=6 ? '<div class="dice-block"><div class="dice-column"><div class="label">Кубик</div><div class="dice">'.\Berserk\View\Ui\DiceValue::render($dice).'</div></div></div>' : '<p>Результат кубика не сохранён.</p>';
        $effect     = (array) $p['effect'];
        $effectType = (string) ($effect['type'] ?? '');
        $value      = (int) ($effect['value'] ?? 0);
        $defaultSide = $effectType === 'damage' ? 'enemy' : 'own';
        $items = [];
        foreach ($p['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc || $tc->dying || $tc->hp <= 0) continue;
            $items[] = \Berserk\View\Ui\CreatureChoice::item($tc,
                $cardsInfo[$tc->ukid]['name'] ?? '?', $playerKey, $effectType === 'heal');
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        $titles = [
            'damage'   => 'ранить на ' . $value,
            'heal'     => 'излечить на ' . $value,
            'get_coin' => 'дать монету (' . $value . ')',
        ];
        $effectLabel = $titles[$effectType] ?? 'применить';
        $title = $srcName . ' — ' . $effectLabel;

        return new PanelSpec(
            title: $title,
            text: [$diceHtml],
            form: [
                'type'   => 'radio',
                'name'   => 'target_id',
                'items'  => $items,
                'side_toggle' => true,
                'side_default' => $defaultSide,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_shaksi_roll',
                ],
                'submit' => 'Подтвердить',
            ],
            cardSelection: \Berserk\View\Ui\CreatureChoice::confirmed($state, $playerKey, $cardsInfo, $items,
                'shaksi:' . $p['source_id'] . ':' . ($p['dice'] ?? 0) . ':' . $effectType),
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