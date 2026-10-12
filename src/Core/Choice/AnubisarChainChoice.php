<?php
// src/Core/Choice/AnubisarChainChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Result;
use Berserk\Core\StrikeResolver;
use Berserk\View\Ui\PanelSpec;

final class AnubisarChainChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_anubisar_chain';
    }

    public function commandTypes(): array
    {
        return ['choose_anubisar_chain'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pending = $state->battle['pending_anubisar_chain'] ?? null;
        if (!$pending) return null;

        $source = $state->getCard((int) ($pending['source_id'] ?? 0));
        $sourceName = $source ? ($cardsInfo[$source->ukid]['name'] ?? '?') : '?';

        if (($pending['owner'] ?? '') !== $playerKey) {
            return new PanelSpec(
                title: $sourceName . ' — цепи Анубисара',
                isMine: false,
            );
        }

        $items = [];
        foreach (($pending['candidate_ids'] ?? []) as $targetId) {
            $target = $state->getCard((int) $targetId);
            if (!$target || $target->dying || $target->hp <= 0) continue;
            $name = $cardsInfo[$target->ukid]['name'] ?? '?';
            $coord = $target->row !== null && $target->col !== null
                ? ' (' . $target->row . ',' . $target->col . ')'
                : '';
            $items[] = [
                'value' => $target->instanceId,
                'label' => $name . $coord . ' — ' . $target->hp . '/' . $target->hpMax,
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $sourceName . ' — выбери две цели для цепей:',
            form: [
                'type' => 'checkbox',
                'name' => 'target_ids[]',
                'items' => $items,
                'hidden' => [
                    $roleParam => '',
                    'game' => $state->gameId,
                    'cmd' => 'choose_anubisar_chain',
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
        return (new StrikeResolver($state, $engine))->chooseAnubisarChain($playerKey, $cmd);
    }
}
