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
        ?string $message,
        array $deckData = []
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

        $panelHtml = '<section class="pregame-turn-info"><div class="dice-block">'
            . '<div class="dice-column"><div class="label">Ты</div><div class="dice dice-' . (int) $myDice . '">' . (int) $myDice . '</div></div>'
            . '<div class="dice-column"><div class="label">Оппонент</div><div class="dice dice-' . (int) $oppDice . '">' . (int) $oppDice . '</div></div>'
            . '</div><p><b>' . ($youFirst ? 'Ты выбираешь, кто ходит первым' : 'Оппонент выбирает, кто ходит первым') . '</b></p></section>';

        return [
            'screen' => 'turn',
            'data'   => array_merge($deckData, [
                'panel_html' => $panelHtml,
                'panel_actions_html' => $confirmHtml,
                'my_dice'      => $myDice,
                'opp_dice'     => $oppDice,
                'first_text'   => $youFirst ? 'Ты ходишь первым' : 'Первым ходит оппонент',
                'confirm_html' => $confirmHtml,
                'message'      => $message ?? '',
            ]),
        ];
    }
}