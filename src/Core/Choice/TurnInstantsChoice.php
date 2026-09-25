<?php
// src/Core/Choice/TurnInstantsChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;
use Berserk\View\Ui\TaskCard;

final class TurnInstantsChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string { return 'pending_turn_instants'; }
    public function commandTypes(): array { return ['play_turn_instant']; }

    public function isActive(GameState $state): bool
    {
        return !empty($state->battle['pending_turn_instants']);
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $ti = $state->battle['pending_turn_instants'] ?? null;
        if (!$ti) return null;

        if ($ti['owner'] !== $playerKey) {
            return new PanelSpec(
                title: 'Инстанты',
                isMine: false,
            );
        }

        $cards = [];
        foreach ($ti['list'] as $inst) {
            $url = "{$baseUrl}&cmd=play_turn_instant&card_id={$inst['card_id']}"
                . "&instant_key=" . urlencode($inst['key'] ?? '');

            $render = [
                'label'      => $inst['label'] ?? '',
                'card_ukid'  => $inst['ukid'] ?? null,
                'row'        => null,
                'col'        => null,
                'hint'       => $inst['label'] ?? '',
                'is_instant' => true,
            ];
            $cards[] = TaskCard::render($render, $url, $cardsInfo);
        }

        return new PanelSpec(
            title: 'Выбери инстант:',
            cards: $cards,
            buttons: [[
                'label' => 'Закрыть',
                'url'   => $baseUrl . '&cmd=cancel_pending',
                'class' => 'skip',
            ]],
        );
    }

    public function apply(
        GameState $state,
        Engine $engine,
        string $playerKey,
        Command $cmd
    ): Result {
        $ar = new \Berserk\Core\ActionResolver($state, $engine);
        return $ar->playTurnInstant($playerKey, $cmd);
    }
}