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

        if (!$isHost) {
            $contentHtml = '<p class="wait">Ожидание, пока хост выберет режим игры...</p>';
        } else {
            $contentHtml = '<form method="get" class="mode-actions">'
                . '<input type="hidden" name="' . $roleParam . '" value="1">'
                . '<input type="hidden" name="game" value="' . (int) $state->gameId . '">'
                . '<input type="hidden" name="cmd" value="choose_mode">';
            foreach (['draft' => 'Draft — сетка', 'solomon' => 'Solomon Draft', 'sealed' => 'Sealed', 'lucktest' => 'LuckTest', 'system' => 'Системные колоды'] as $value => $label) {
                $contentHtml .= '<label class="settings-option"><input type="radio" name="mode" value="' . $value . '"'
                    . ($value === 'draft' ? ' checked' : '') . '><span>' . $label . '</span></label>';
            }
            $contentHtml .= '<button class="button wide" type="submit">Подтвердить</button></form>';
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
