<?php
// src/Core/Choice/CardChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class CardChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_card_choice';
    }

    public function commandTypes(): array
    {
        return ['choose_card_option'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $pc = $state->battle['pending_card_choice'] ?? null;
        if (!$pc) return null;

        $choiceCard = $state->getCard($pc['card_id']);
        $choiceInfo = $choiceCard ? ($cardsInfo[$choiceCard->ukid] ?? null) : null;

        if (!$choiceCard || !$choiceInfo || $playerKey !== $choiceCard->owner) {
            return new PanelSpec(
                title: 'Выбор карты',
                isMine: false,
            );
        }

        $items = [];
        foreach ($pc['options'] as $i => $opt) {
            $items[] = [
                'value'   => $i,
                'label'   => $opt['label'] ?? ('Вариант ' . ($i + 1)),
                'checked' => ($i === 0),
            ];
        }

        $roleParam = $role === 'host' ? 'first' : 'second';

        return new PanelSpec(
            title: $choiceInfo['name'] . ' — выбери вариант:',
            form: [
                'type'   => 'radio',
                'name'   => 'option_index',
                'items'  => $items,
                'hidden' => [
                    $roleParam => '',
                    'game'     => $state->gameId,
                    'cmd'      => 'choose_card_option',
                    'card_id'  => $choiceCard->instanceId,
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
        $turn = new \Berserk\Core\TurnProcessor($state, $engine);
        return $turn->chooseCardOption($playerKey, $cmd);
    }
}