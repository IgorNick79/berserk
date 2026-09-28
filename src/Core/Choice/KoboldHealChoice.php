<?php
// src/Core/Choice/KoboldHealChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\Command;
use Berserk\Core\Engine;
use Berserk\Core\GameState;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class KoboldHealChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_kobold_heal';
    }

    public function commandTypes(): array
    {
        return ['choose_kobold_heal', 'cancel_pending'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pending = $state->battle['pending_kobold_heal'] ?? null;
        if (!$pending) return null;

        $card = $state->getCard((int) ($pending['card_id'] ?? 0));
        $name = $card ? ($cardsInfo[$card->ukid]['name'] ?? '?') : '?';
        $value = (int) ($pending['value'] ?? 0);

        if (($pending['owner'] ?? null) !== $playerKey) {
            return new PanelSpec(
                title: $name . ' может излечиться',
                isMine: false,
            );
        }

        return new PanelSpec(
            title: $name . ' может излечиться на ' . $value,
            text: ['Средний удар существа напротив.'],
            buttons: [
                [
                    'label' => 'Излечиться на ' . $value,
                    'url' => $baseUrl . '&cmd=choose_kobold_heal',
                ],
                [
                    'label' => 'Отмена',
                    'url' => $baseUrl . '&cmd=cancel_pending',
                    'class' => 'skip',
                ],
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
        if ($cmd->type === 'cancel_pending') {
            return $ar->cancelPending($playerKey, $cmd);
        }

        return $ar->chooseKoboldHeal($playerKey, $cmd);
    }
}
