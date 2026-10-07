<?php
// src/Core/Choice/ParticlePickChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class ParticlePickChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_particle_pick'; }
    public function commandTypes(): array { return ['choose_particle_pick', 'cancel_pending']; }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_particle_pick'] ?? null;
        if (!$p) return null;

        $src = $state->getCard((int) $p['card_id']);
        $srcName = $src ? ($cardsInfo[$src->ukid]['name'] ?? '?') : '?';

        if ($p['owner'] !== $playerKey) {
            return new PanelSpec(title: $srcName . ' — частица души', isMine: false);
        }

        $side = (string) ($_GET['p_side'] ?? 'enemy'); // enemy | own

        $items = [];
        foreach ($p['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            $isOwn = ($tc->owner === $playerKey);
            if ($side === 'enemy' && $isOwn) continue;
            if ($side === 'own'   && !$isOwn) continue;

            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $label = $tn . ' (' . $tc->row . ';' . $tc->col . ') — ' . $tc->hp . '/' . $tc->hpMax;
            if ($isOwn) $label .= ' — моё';

            $items[] = ['value' => (int) $tid, 'label' => $label];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        if (empty($items)) {
            return new PanelSpec(
                title: $srcName . ': нет целей без ран на этой стороне',
                buttons: [
                    [
                        'label' => $side === 'enemy' ? 'Мои существа' : 'Существа противника',
                        'url'   => $baseUrl . '&cmd=choose_particle_pick&p_side=' . ($side === 'enemy' ? 'own' : 'enemy'),
                    ],
                    [
                        'label' => 'Отмена',
                        'url'   => $baseUrl . '&cmd=cancel_pending',
                        'class' => 'skip',
                    ],
                ],
            );
        }

        $max = (int) $p['max_targets'];

        $buttons = [
            [
                'label' => $side === 'enemy' ? 'Мои существа' : 'Существа противника',
                'url'   => $baseUrl . '&cmd=choose_particle_pick&p_side=' . ($side === 'enemy' ? 'own' : 'enemy'),
            ],
        ];

        return new PanelSpec(
            title: $srcName . ': выбери до ' . $max . ' существ без ран (по 1 урона)',
            buttons: $buttons,
            form: [
                'type'   => 'checkbox',
                'name'   => 'target_ids[]',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_particle_pick',
                ],
                'submit' => 'Применить',
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
            ->chooseParticlePick($playerKey, $cmd);
    }
}