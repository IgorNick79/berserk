<?php
// src/View/Screen/DeckScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\Core\DeckView;
use Berserk\View\Template;

final class DeckScreen
{
    public function __construct(
        private DeckView $deckView,
        private Template $tpl,
    ) {}

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

        if ($role === 'host') {
            $decksHtml = '';
            foreach ($this->deckView->listStarters() as $deck) {
                $decksHtml .= $this->tpl->parse('includes/deck_item.tpl', [
                    'ind'  => $deck['ind'],
                    'name' => $deck['name'],
                    'link' => "?{$linkParam}&game={$state->gameId}&cmd=select_deck&deck_id={$deck['ind']}",
                ]);
            }
            return [
                'screen' => 'deck_host',
                'data'   => [
                    'decks_html' => $decksHtml,
                    'message'    => $message ?? '',
                ],
            ];
        }

        return [
            'screen' => 'deck_wait',
            'data'   => [
                'message' => $message ?? '',
            ],
        ];
    }
}