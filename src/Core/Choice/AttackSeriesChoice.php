<?php
// src/Core/Choice/AttackSeriesChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class AttackSeriesChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_attack_series'; }

    public function commandTypes(): array
    {
        return [
            'attack_series_add',
            'attack_series_remove',
            'attack_series_reset',
            'attack_series_start',
            'cancel_pending',
        ];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $series = $state->battle['pending_attack_series'] ?? null;
        if (!$series) return null;

        $hydra = $state->getCard((int) $series['source_id']);
        $hName = $hydra ? ($cardsInfo[$hydra->ukid]['name'] ?? '?') : '?';

        if (($series['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(title: $hName . ' — серия атак', isMine: false);
        }

        if (!empty($series['active'])) {
            return new PanelSpec(
                title: $hName . ': серия атак идёт',
                isMine: false,
                waitText: 'Ожидание сражения...',
            );
        }

        $buttons = [];

        // Кандидаты — добавить/убрать из очереди
        foreach ($series['candidates'] as $tid) {
            $tc = $state->getCard((int) $tid);
            if (!$tc) continue;
            if ($tc->dying || $tc->hp <= 0) continue;

            $inQueue = in_array($tid, $series['chosen'], true);
            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
            $pos = $tc->row . ';' . $tc->col;

            $label = ($inQueue ? '✓ ' : '') . $tn . ' (' . $pos . ') — '
                . $tc->hp . '/' . $tc->hpMax;

            $cmdType = $inQueue ? 'attack_series_remove' : 'attack_series_add';
            $buttons[] = [
                'label' => $label,
                'url'   => $baseUrl . '&cmd=' . $cmdType . '&target_id=' . $tid,
                'class' => $inQueue ? 'skip' : '',
            ];
        }

        $textLines = [];
        if (!empty($series['chosen'])) {
            $queueNames = [];
            foreach ($series['chosen'] as $i => $tid) {
                $tc = $state->getCard((int) $tid);
                if (!$tc) continue;
                $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';
                $queueNames[] = ($i + 1) . '. ' . $tn;
            }
            $textLines[] = 'Очередь: ' . implode(' → ', $queueNames);
        } else {
            $textLines[] = 'Кликните по целям, чтобы добавить их в очередь.';
        }

        if (!empty($series['chosen'])) {
            $buttons[] = [
                'label' => 'Начать серию',
                'url'   => $baseUrl . '&cmd=attack_series_start',
            ];
            $buttons[] = [
                'label' => 'Сбросить',
                'url'   => $baseUrl . '&cmd=attack_series_reset',
                'class' => 'skip',
            ];
        }

        $buttons[] = [
            'label' => 'Отмена',
            'url'   => $baseUrl . '&cmd=cancel_pending',
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: $hName . ': серия атак — выбери цели по порядку',
            text: $textLines,
            buttons: $buttons,
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
            ->chooseAttackSeries($playerKey, $cmd);
    }
}