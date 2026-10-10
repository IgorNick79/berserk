<?php
// src/View/Screen/BattleSnapshotRenderer.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\View\Template;

/**
 * Reusable server-side battle snapshot renderer.
 *
 * It intentionally delegates all battle UI generation to BattleScreen so future
 * AJAX responses use the same presentation logic as the existing full page.
 */
final class BattleSnapshotRenderer
{
    public function __construct(private Template $tpl) {}

    /**
     * @return array{
     *     ui: array{sel:int,mode:string,pile:string},
     *     fragments: array{
     *         field_html:string,
     *         fly_zones_html:string,
     *         panel_html:string,
     *         piles_html:string,
     *         pile_reveal_html:string,
     *         info_panel_html:string
     *     }
     * }
     */
    public function render(
        GameState $state,
        string $playerKey,
        string $role,
        ?string $message,
        array $cardsInfo,
        array $uiState = []
    ): array {
        $result = (new BattleScreen($this->tpl))->prepare(
            $state,
            $playerKey,
            $role,
            $message,
            $cardsInfo,
            $uiState
        );

        $data = $result['data'];

        return [
            'ui' => $data['ui'],
            'fragments' => [
                'field_html' => (string) ($data['field_html'] ?? ''),
                'fly_zones_html' => (string) ($data['fly_zones_html'] ?? ''),
                'panel_html' => (string) ($data['panel_html'] ?? ''),
                'piles_html' => (string) ($data['piles_html'] ?? ''),
                'pile_reveal_html' => (string) ($data['pile_reveal_html'] ?? ''),
                'info_panel_html' => (string) ($data['info_panel_html'] ?? ''),
            ],
        ];
    }
}
