<?php
// src/View/Screen/TurnScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;

final class TurnScreen
{
    /**
     * @return array{screen: string, data: array}
     */
    public function prepare(
        GameState $state,
        string $playerKey,
        string $role,
        ?string $message
    ): array {
        $linkParam = $role === 'host' ? 'first' : 'second';

        $me  = $state->getPlayer($playerKey);
        $opp = $state->getPlayer($state->getOpponentKey($playerKey));

        $myDice   = $me->dice  ?? 0;
        $oppDice  = $opp->dice ?? 0;
        $youFirst = ($state->firstPlayer === $playerKey);

        if (!$me->isConfirmed('turn')) {
            $confirmUrl  = "?{$linkParam}&game={$state->gameId}&cmd=confirm_turn";
            $confirmHtml = '<a class="button" href="' . $confirmUrl . '">Продолжить</a>';
        } else {
            $confirmHtml = '<p class="wait">Ожидание оппонента...</p>';
        }

        return [
            'screen' => 'turn',
            'data'   => [
                'my_dice'      => $myDice,
                'opp_dice'     => $oppDice,
                'first_text'   => $youFirst ? 'Ты ходишь первым' : 'Первым ходит оппонент',
                'confirm_html' => $confirmHtml,
                'message'      => $message ?? '',
            ],
        ];
    }
}