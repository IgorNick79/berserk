<?php
// src/Core/Choice/DvergGiftChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class DvergGiftChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_dverg_gift'; }
    public function commandTypes(): array { return ['choose_dverg_gift']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_dverg_gift'] ?? null;
        if (!$p) return null;

        $dverg = $state->getCard((int) $p['source_id']);
        $dName = $dverg ? ($cardsInfo[$dverg->ukid]['name'] ?? '?') : '?';

        if (($p['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(title: $dName . ' — дух мухомора', isMine: false);
        }

        $items = [];
        foreach ($p['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $coords = ' (' . $tc->row . ';' . $tc->col . ')';
            $items[] = [
                'value' => (int) $tid,
                'label' => $tn . $coords . ' — ' . $tc->hp . '/' . $tc->hpMax,
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $dName . ': выбери союзника для духа мухомора',
            text: ['Цель получит магический удар той же величины, что её strike, +1. До конца хода.'],
            form: [
                'type'   => 'radio',
                'name'   => 'target_id',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_dverg_gift',
                ],
                'submit' => 'Дать',
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
            ->chooseDvergGiftTarget($playerKey, $cmd);
    }
}