<?php
// src/Core/Choice/RowSpellPickChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class RowSpellPickChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_row_spell_pick';
    }

    public function commandTypes(): array
    {
        return ['choose_row_spell_targets'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_row_spell_pick'] ?? null;
        if (!$p) return null;

        $src = $state->getCard((int) $p['source_id']);
        $srcName = $src ? ($cardsInfo[$src->ukid]['name'] ?? '?') : '?';

        if ($p['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $srcName . ' — выбор целей',
                isMine: false,
            );
        }

        $x = (int) $p['x'];
        $items = [];
        foreach ($p['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            $info = $cardsInfo[$tc->ukid] ?? null;
            $name = $info['name'] ?? '?';
            $coord = '(' . $tc->row . ';' . $tc->col . ')';
            $items[] = [
                'value' => (int) $tid,
                'label' => $name . ' ' . $coord . ' — ' . $tc->hp . '/' . $tc->hpMax,
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $srcName . ': выбери ровно ' . $x . ' цел' . ($x === 1 ? 'ь' : 'и'),
            form: [
                'type'   => 'checkbox',
                'name'   => 'target_ids[]',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_row_spell_targets',
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
            ->chooseRowSpellTargets($playerKey, $cmd);
    }
}