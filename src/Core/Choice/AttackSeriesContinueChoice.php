<?php
// src/Core/Choice/AttackSeriesContinueChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class AttackSeriesContinueChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_attack_series_choice'; }

    public function commandTypes(): array
    {
        return ['attack_series_next', 'attack_series_end', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $p = $state->battle['pending_attack_series_choice'] ?? null;
        if (!$p) return null;

        $hydra = $state->getCard((int) $p['source_id']);
        $hName = $hydra ? ($cardsInfo[$hydra->ukid]['name'] ?? '?') : '?';

        if (($p['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $hName . ' — продолжение серии',
                isMine: false,
            );
        }

        $buttons = [];
        foreach ($p['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            if ($tc->dying || $tc->hp <= 0) continue;

            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $pos = $tc->row . ';' . $tc->col;

            $buttons[] = [
                'label' => $tn . ' (' . $pos . ') — ' . $tc->hp . '/' . $tc->hpMax,
                'url'   => $baseUrl . '&cmd=attack_series_next&target_id=' . $tid,
            ];
        }

        $buttons[] = [
            'label' => 'Закончить серию',
            'url'   => $baseUrl . '&cmd=attack_series_end',
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: $hName . ': продолжить серию атак?',
            text: ['Атакуй следующего соседа или закончи серию — тогда Гидра закроется.'],
            buttons: $buttons,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ar = new \Berserk\Core\ActionResolver($state, $engine);

        if ($cmd->type === 'attack_series_end' || $cmd->type === 'cancel_pending') {
            return $ar->chooseAttackSeriesEnd($playerKey, $cmd);
        }

        return $ar->chooseAttackSeriesNext($playerKey, $cmd);
    }
}