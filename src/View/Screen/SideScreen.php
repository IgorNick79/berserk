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
        ?string $message
    ): array {
        $linkParam = $role === 'host' ? 'first' : 'second';
        $isChooser = ($state->firstPlayer === $playerKey);

        if ($isChooser) {
            $side1Url = "?{$linkParam}&game={$state->gameId}&cmd=choose_side&side=1";
            $side2Url = "?{$linkParam}&game={$state->gameId}&cmd=choose_side&side=2";
            $contentHtml = '<p>Выбери сторону:</p>'
                . '<a class="button" href="' . $side1Url . '">Сторона I (хожу первым)</a> '
                . '<a class="button" href="' . $side2Url . '">Сторона II (хожу вторым)</a>';
        } else {
            $contentHtml = '<p class="wait">Оппонент выбирает сторону...</p>';
        }

        return [
            'screen' => 'side',
            'data'   => [
                'content_html' => $contentHtml,
                'message'      => $message ?? '',
            ],
        ];
    }
}