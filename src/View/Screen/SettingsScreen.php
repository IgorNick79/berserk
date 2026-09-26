<?php
// src/View/Screen/SettingsScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameSettings;
use Berserk\Core\GameState;

final class SettingsScreen
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
        $roleParam = ($role === 'host') ? 'first' : 'second';
        $baseUrl   = "?{$roleParam}&game={$state->gameId}";
        $isHost    = ($playerKey === GameState::PLAYER_HOST);

        if (!$isHost) {
            return [
                'screen' => 'settings',
                'data'   => [
                    'title'         => 'Настройки',
                    'content_html'  => '<p class="wait">Ожидание, пока хост настроит игру...</p>',
                    'actions_html'  => '',
                    'message'       => $message ?? '',
                ],
            ];
        }

        return match ($state->mode) {
            GameSettings::MODE_SYSTEM => $this->system($state, $baseUrl, $message),
            GameSettings::MODE_DRAFT  => $this->draft($state, $roleParam, $message),
            default                   => $this->unsupported($state, $message),
        };
    }

    private function system(GameState $state, string $baseUrl, ?string $message): array
    {
        $selection = htmlspecialchars($state->settings->systemDeckSelection(), ENT_QUOTES);

        return [
            'screen' => 'settings',
            'data'   => [
                'title'        => 'Системные колоды',
                'content_html' => '<div class="settings-list">'
                    . '<div><b>Выбор колоды</b>: ' . $selection . '</div>'
                    . '</div>',
                'actions_html' => '<a class="button wide" href="' . $baseUrl . '&cmd=confirm_settings">Продолжить</a>',
                'message'      => $message ?? '',
            ],
        ];
    }

    private function draft(GameState $state, string $roleParam, ?string $message): array
    {
        $settings = $state->settings;
        $boosters = $settings->draftBoosters();
        $isRandom = $settings->draftPickMode() === GameSettings::DRAFT_PICK_MODE_RANDOM;

        $contentHtml = '<div class="settings-list">'
            . '<div><b>Тип</b>: Grid</div>'
            . '<div><b>Способ</b>: '
                . ($isRandom ? 'Автоматический' : 'Ручной')
                . '</div>'
            . '<div><b>Сетка</b>: ' . htmlspecialchars((string) $settings->draftGridSize(), ENT_QUOTES) . '×'
                . htmlspecialchars((string) $settings->draftGridSize(), ENT_QUOTES) . '</div>'
            . '<div><b>Профиль бустера</b>: '
                . htmlspecialchars($settings->draftBoosterProfile(), ENT_QUOTES) . '</div>'
            . '</div>';

        $actionsHtml = '<form method="get" class="settings-form">'
            . '<input type="hidden" name="' . htmlspecialchars($roleParam, ENT_QUOTES) . '" value="1">'
            . '<input type="hidden" name="game" value="' . (int) $state->gameId . '">'
            . '<input type="hidden" name="cmd" value="confirm_settings">'
            . '<input type="hidden" name="draft_pick_mode" value="' . GameSettings::DRAFT_PICK_MODE_MANUAL . '">'
            . '<label class="settings-field">'
            . '<span>Бустеров</span>'
            . '<input type="number" name="boosters" min="1" max="10" step="1" value="'
                . htmlspecialchars((string) $boosters, ENT_QUOTES) . '">'
            . '</label>'
            . '<label class="settings-field">'
            . '<span>Автоматический драфт</span>'
            . '<input type="checkbox" name="draft_pick_mode" value="' . GameSettings::DRAFT_PICK_MODE_RANDOM . '"'
                . ($isRandom ? ' checked' : '') . '>'
            . '</label>'
            . '<button class="button wide" type="submit">Начать драфт</button>'
            . '</form>';

        return [
            'screen' => 'settings',
            'data'   => [
                'title'        => 'Драфт',
                'content_html' => $contentHtml,
                'actions_html' => $actionsHtml,
                'message'      => $message ?? '',
            ],
        ];
    }

    private function unsupported(GameState $state, ?string $message): array
    {
        $mode = htmlspecialchars((string) ($state->mode ?? '—'), ENT_QUOTES);

        return [
            'screen' => 'settings',
            'data'   => [
                'title'        => 'Настройки',
                'content_html' => '<p class="wait">Режим пока не поддерживается: ' . $mode . '</p>',
                'actions_html' => '',
                'message'      => $message ?? '',
            ],
        ];
    }
}
