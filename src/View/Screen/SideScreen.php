<?php
// src/View/Screen/SideScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;

final class SideScreen
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
        $isChooser = ($state->firstPlayer === $playerKey);

        if ($isChooser) {
            $contentHtml = '<form id="pregame-side-choice" class="pregame-side-choice" method="get">'
                . '<input type="hidden" name="' . $linkParam . '" value="1">'
                . '<input type="hidden" name="game" value="' . (int) $state->gameId . '">'
                . '<input type="hidden" name="cmd" value="choose_side">'
                . '<fieldset><legend>Выбери, кто ходит первым:</legend>'
                . '<label><input type="radio" name="side" value="1" checked> Ходить первым (сторона I)</label>'
                . '<label><input type="radio" name="side" value="2"> Ходить вторым (сторона II)</label>'
                . '</fieldset></form>';
            $footerHtml = '<button type="submit" class="button" form="pregame-side-choice">Продолжить</button>';
        } else {
            $contentHtml = '<p class="wait">Оппонент выбирает, кто ходит первым...</p>';
            $footerHtml = '';
        }

        return [
            'screen' => 'side',
            'data'   => array_merge($deckData, [
                'panel_html' => '<section class="pregame-side-info">' . $contentHtml . '</section>',
                'panel_actions_html' => $footerHtml,
                'content_html' => $contentHtml,
                'message'      => $message ?? '',
            ]),
        ];
    }
}