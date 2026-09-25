<?php
// src/Core/Choice/MultiDischargeChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\Core\CardStats;
use Berserk\View\Ui\PanelSpec;

final class MultiDischargeChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_multi_discharge';
    }

    public function commandTypes(): array
    {
        return ['choose_multi_discharge'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pmd = $state->battle['pending_multi_discharge'] ?? null;
        if (!$pmd) return null;

        $attCard = $state->getCard($pmd['attacker_id']);
        $attName = $attCard ? ($cardsInfo[$attCard->ukid]['name'] ?? '?') : '?';

        if (!$attCard || $attCard->owner !== $playerKey) {
            return new PanelSpec(
                title: $attName . ' — разряд',
                isMine: false,
            );
        }

        $max   = (int) $pmd['max_targets'];
        $value = (int) $pmd['value'];

        $items = [];
        foreach ($pmd['candidates'] as $tid) {
            $tc = $state->getCard($tid);
            if (!$tc) continue;
            $tn = $cardsInfo[$tc->ukid]['name'] ?? '?';

            $defended = CardStats::hasDefense($state, $tc, 'discharge');
            $hint     = $defended ? ' (защита)' : '';

            $items[] = [
                'value' => $tid,
                'label' => $tn . ' (' . $tc->hp . '/' . $tc->hpMax . ')' . $hint,
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $attName . ' — выбери до ' . $max . ' целей (по ' . $value . ' урона):',
            form: [
                'type'   => 'checkbox',
                'name'   => 'target_ids[]',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_multi_discharge',
                ],
                'submit' => 'Подтвердить',
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
        $ar = new \Berserk\Core\ActionResolver($state, $engine);
        return $ar->chooseMultiDischarge($playerKey, $cmd);
    }
}