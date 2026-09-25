<?php
// src/View/Screen/ModeScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameState;
use Berserk\View\Template;

final class ModeScreen
{
    public function __construct(private Template $tpl) {}

    /**
     * @return array{screen: string, data: array}
     */
    public function prepare(
        GameState $state,
        string $playerKey,
        string $role,
        ?string $message
    ): array {
        $isHost    = ($playerKey === 'host');
        $roleParam = ($role === 'host') ? 'first' : 'second';
        $baseUrl   = "?{$roleParam}&game={$state->gameId}";
        $mode      = $state->mode;

        if (!$isHost) {
            $contentHtml = '<p class="wait">Ожидание, пока хост выберет режим игры...</p>';
        } elseif ($mode !== null) {
            $contentHtml = '<p>Режим: <b>' . htmlspecialchars($mode, ENT_QUOTES) . '</b></p>';
        } else {
            $draftUrl  = "{$baseUrl}&cmd=choose_mode&mode=draft";
            $systemUrl = "{$baseUrl}&cmd=choose_mode&mode=system";

            $contentHtml =
                '<p>Хост, выбери режим игры:</p>'
                . '<div class="mode-actions">'
                . '<a class="button wide" href="' . $draftUrl . '">Драфт (5 бустеров, 3×3)</a>'
                . '<a class="button wide" href="' . $systemUrl . '">Системные колоды</a>'
                . '</div>';
        }

        return [
            'screen' => 'mode',
            'data'   => [
                'content_html' => $contentHtml,
                'message'      => $message ?? '',
            ],
        ];
    }
}