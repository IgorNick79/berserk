<?php
// src/Core/Choice/HolvertOpenChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\ActionResolver;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class HolvertOpenChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_holvert_open';
    }

    public function commandTypes(): array
    {
        return ['choose_holvert_open'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pending = $state->battle['pending_holvert_open'] ?? null;
        if (!$pending) return null;

        $source = $state->getCard((int) ($pending['source_id'] ?? 0));
        $sourceName = $source ? ($cardsInfo[$source->ukid]['name'] ?? '?') : '?';

        if (($pending['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $sourceName . ': открыть существо',
                isMine: false,
                waitText: 'Ожидание выбора существа в строю оппонентом...',
            );
        }

        $items = [];
        foreach ((array) ($pending['candidate_ids'] ?? []) as $id) {
            $card = $state->getCard((int) $id);
            if (!$card) continue;

            $name = $cardsInfo[$card->ukid]['name'] ?? $card->ukid;
            $items[] = [
                'value' => $card->instanceId,
                'label' => $name . ' #' . $card->instanceId,
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $sourceName . ': выберите существо в строю, которое будет открыто',
            form: [
                'type' => 'radio',
                'name' => 'target_id',
                'items' => $items,
                'hidden' => [
                    $roleParam => '',
                    'game' => $state->gameId,
                    'cmd' => 'choose_holvert_open',
                ],
                'submit' => 'Открыть',
            ],
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        return (new ActionResolver($state, $engine))->chooseHolvertOpen($playerKey, $cmd);
    }
}
