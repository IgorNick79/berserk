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
            $decks = $this->deckView->listStarters();
            return [
                'screen' => 'deck_host',
                'data'   => [
                    'role_param'           => $linkParam,
                    'game_id'              => $state->gameId,
                    'host_manual_checked'  => 'checked',
                    'host_random_checked'  => '',
                    'player_manual_checked'=> 'checked',
                    'player_random_checked'=> '',
                    'host_decks_html'      => $this->deckTiles($decks, 'deck_id'),
                    'player_decks_html'    => $this->deckTiles($decks, 'other_deck_id'),
                    'empty_decks_html'     => $decks === [] ? '<p class="deck-select-empty">Нет доступных системных колод.</p>' : '',
                    'message'              => $message ?? '',
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

    /**
     * @param array<int, array<string, mixed>> $decks
     */
    private function deckTiles(array $decks, string $fieldName): string
    {
        $html = '';
        foreach ($decks as $deck) {
            $id = (int) ($deck['ind'] ?? 0);
            $name = $this->esc((string) ($deck['name'] ?? 'Колода'));
            $html .= '<label class="settings-option deck-select-tile">'
                . '<input type="radio" name="' . $this->esc($fieldName) . '" value="' . $id . '">'
                . '<span class="deck-select-tile__body">'
                . '<span class="deck-select-tile__image" aria-hidden="true"></span>'
                . '<span class="deck-select-tile__text"><b>' . $name . '</b></span>'
                . '</span>'
                . '</label>';
        }
        return $html;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
