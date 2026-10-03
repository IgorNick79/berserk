<?php
// src/Core/Choice/DestroySelfAndTargetChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\ActionResolver;
use Berserk\Core\CardInstance;
use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class DestroySelfAndTargetChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_destroy_self_and_target';
    }

    public function commandTypes(): array
    {
        return ['choose_destroy_self_and_target', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pending = $state->battle['pending_destroy_self_and_target'] ?? null;
        if (!$pending) return null;

        $source = $state->getCard((int) ($pending['source_id'] ?? 0));
        $sourceName = $source ? ($cardsInfo[$source->ukid]['name'] ?? $source->ukid) : '?';
        $actionName = (string) ($pending['action']['name'] ?? 'Последний путь');

        if (($pending['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $sourceName . ': «' . $actionName . '»',
                isMine: false,
                waitText: 'Ожидание выбора существа оппонентом...',
            );
        }

        $side = (string) ($_GET['target_side'] ?? 'enemy');
        if ($side !== 'own') $side = 'enemy';

        $buttons = [
            [
                'label' => 'Существа противника',
                'url'   => $baseUrl . '&target_side=enemy',
                'class' => $side === 'enemy' ? '' : 'skip',
            ],
            [
                'label' => 'Мои существа',
                'url'   => $baseUrl . '&target_side=own',
                'class' => $side === 'own' ? '' : 'skip',
            ],
        ];

        foreach ((array) ($pending['target_ids'] ?? []) as $id) {
            $card = $state->getCard((int) $id);
            if (!$card) continue;

            $wantOwner = $side === 'own' ? $playerKey : $this->opponent($playerKey);
            if ($card->owner !== $wantOwner) continue;
            if ($card->zone !== CardInstance::ZONE_FIELD
                && $card->zone !== CardInstance::ZONE_FLYING) continue;

            $name = $cardsInfo[$card->ukid]['name'] ?? $card->ukid;
            $zone = $card->zone === CardInstance::ZONE_FLYING ? ' (летит)' : '';
            $buttons[] = [
                'label' => $name . $zone . ' #' . $card->instanceId,
                'url'   => "{$baseUrl}&cmd=choose_destroy_self_and_target&target_id={$card->instanceId}",
            ];
        }

        $buttons[] = [
            'label' => 'Отмена',
            'url'   => $baseUrl . '&cmd=cancel_pending',
            'class' => 'skip',
        ];

        return new PanelSpec(
            title: $sourceName . ': «' . $actionName . '» — выберите существо',
            buttons: $buttons,
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $resolver = new ActionResolver($state, $engine);
        if ($cmd->type === 'cancel_pending') {
            return $resolver->cancelPending($playerKey, $cmd);
        }
        return $resolver->chooseDestroySelfAndTarget($playerKey, $cmd);
    }

    private function opponent(string $playerKey): string
    {
        return $playerKey === GameState::PLAYER_HOST
            ? GameState::PLAYER_PLAYER
            : GameState::PLAYER_HOST;
    }
}
