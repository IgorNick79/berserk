<?php
// src/Core/Choice/SeggerShieldChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class SeggerShieldChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_segger_shield'; }
    public function commandTypes(): array { return ['choose_segger_shield']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pending = $state->battle['pending_segger_shield'] ?? null;
        if (!$pending) return null;

        $source  = $state->getCard((int) $pending['source_id']);
        $srcName = $source ? ($cardsInfo[$source->ukid]['name'] ?? '?') : '?';

        if (($pending['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(title: $srcName . ' — защита', isMine: false);
        }

        $max = (int) $pending['max_value'];

        $names = [];
        foreach ($pending['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            $names[] = $cardsInfo[$tc->ukid]['name'] ?? '?';
        }

        $items = [];
        for ($i = 1; $i <= $max; $i++) {
            $items[] = [
                'value'   => $i,
                'label'   => (string) $i,
                'checked' => $i === $max,
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $srcName . ': выбери число защиты',
            text: [
                'Соседи (' . implode(', ', $names) . ') получат защиту '
                . 'от немагических атак до конца следующего хода противника.',
            ],
            form: [
                'type'   => 'radio',
                'name'   => 'value',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_segger_shield',
                ],
                'submit' => 'Применить',
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
            ->chooseSeggerShield($playerKey, $cmd);
    }
}