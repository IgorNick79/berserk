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

        $items = [];
        foreach ($p['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            $items[] = ['value' => $tc->instanceId, 'label' => $cardsInfo[$tc->ukid]['name'] ?? '?'];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';
        $max = (int) $p['max_targets'];

        return new PanelSpec(
            title: $srcName . ': выбери до ' . $max . ' существ без ран (по 1 урона)',
            form: [
                'type'   => 'checkbox',
                'name'   => 'target_ids[]',
                'items'  => $items,
                'side_toggle' => true,
                'side_default' => 'enemy',
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