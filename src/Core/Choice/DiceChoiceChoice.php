<?php
// src/Core/Choice/DiceChoiceChoice.php

declare(strict_types=1);

namespace Berserk\Core\Choice;

use Berserk\Core\GameState;
use Berserk\Core\Engine;
use Berserk\Core\Command;
use Berserk\Core\Result;
use Berserk\View\Ui\PanelSpec;

final class DiceChoiceChoice implements ChoiceHandlerInterface
{
    public function pendingKey(): string
    {
        return 'pending_dice_choice';
    }

    public function commandTypes(): array
    {
        return ['choose_dice_choice'];
    }

    public function spec(
        GameState $state,
        string $playerKey,
        array $cardsInfo,
        string $baseUrl,
        string $role
    ): ?PanelSpec {
        $dc = $state->battle['pending_dice_choice'] ?? null;
        if (!$dc) return null;

        $card   = $state->getCard($dc['card_id']);
        $cardName = $card ? ($cardsInfo[$card->ukid]['name'] ?? '?') : '?';

        if ($dc['owner'] !== $playerKey) {
            return new PanelSpec(
                title: $cardName . ' — Ловец',
                isMine: false,
            );
        }

        $url = $baseUrl . '&cmd=choose_dice_choice&choice=';

        return new PanelSpec(
            title: $cardName . ' — Удача: выбери действие',
            buttons: [
                ['label' => '+1 к своему кубику',  'url' => $url . 'plus:own'],
                ['label' => '−1 от своего кубика', 'url' => $url . 'minus:own'],
                ['label' => '+1 к чужому кубику',  'url' => $url . 'plus:enemy'],
                ['label' => '−1 от чужого кубика', 'url' => $url . 'minus:enemy'],
                ['label' => 'Переброс обоих',      'url' => $url . 'reroll:any'],
                ['label' => 'Отмена',              'url' => $baseUrl . '&cmd=cancel_pending', 'class' => 'skip'],
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
        return $ar->chooseDiceChoice($playerKey, $cmd);
    }
}