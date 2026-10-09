<?php
// src/View/Screen/SettingsScreen.php

declare(strict_types=1);

namespace Berserk\View\Screen;

use Berserk\Core\GameSettings;
use Berserk\Core\GameState;
use Berserk\Core\BoosterSettings;

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
        $boosters = max(GameSettings::DRAFT_BOOSTERS_MIN, min(GameSettings::DRAFT_BOOSTERS_MAX, $settings->draftBoosters()));
        $isRandom = $settings->draftPickMode() === GameSettings::DRAFT_PICK_MODE_RANDOM;
        $gridMode = $settings->draftGridMode();
        $autoSide = $settings->draftAutoSide();
        $timerMode = $settings->draftTimerMode();
        $timerTotal = $settings->draftTimerTotalSeconds();
        if ($timerTotal <= 0) {
            $timerTotal = GameSettings::DRAFT_TIMER_DEFAULT_TOTAL_SECONDS;
        }
        $timerAction = $settings->draftTimerActionSeconds();
        if ($timerAction <= 0) {
            $timerAction = GameSettings::DRAFT_TIMER_DEFAULT_ACTION_SECONDS;
        }
        $booster = $settings->boosterConfig();
        $rareChance = 100 - $booster['ultra_rare_chance'];
        $boosterSize = BoosterSettings::BOOSTER_SIZE;

        $boosterHtml = '';
        for ($count = GameSettings::DRAFT_BOOSTERS_MIN; $count <= GameSettings::DRAFT_BOOSTERS_MAX; $count++) {
            $boosterHtml .= $this->radioTile('boosters', (string) $count, (string) $count, $boosters === $count);
        }

        $contentHtml = '<p class="settings-mode-title">Grid 3×3</p>';

        $actionsHtml = '<form method="get" class="settings-form settings-draft-form" data-settings-form="draft">'
            . '<input type="hidden" name="' . $this->esc($roleParam) . '" value="1">'
            . '<input type="hidden" name="game" value="' . (int) $state->gameId . '">'
            . '<input type="hidden" name="cmd" value="confirm_settings">'
            . '<input type="hidden" name="draft_pick_mode" value="' . GameSettings::DRAFT_PICK_MODE_MANUAL . '">'
            . '<section class="settings-section">'
            . '<h2>Основные настройки</h2>'
            . '<fieldset class="settings-fieldset">'
            . '<legend>Количество бустеров</legend>'
            . '<div class="settings-option-grid settings-option-grid--compact">' . $boosterHtml . '</div>'
            . '</fieldset>'
            . '<fieldset class="settings-fieldset">'
            . '<legend>Состав бустера</legend>'
            . '<div class="settings-booster" data-booster-config data-total="' . $boosterSize . '">'
            . '<div class="settings-booster-bar" aria-hidden="true">'
            . '<span class="settings-booster-bar__common" data-booster-bar="common" style="width:' . $this->percent($booster['common'], $boosterSize) . '%"></span>'
            . '<span class="settings-booster-bar__uncommon" data-booster-bar="uncommon" style="width:' . $this->percent($booster['uncommon'], $boosterSize) . '%"></span>'
            . '<span class="settings-booster-bar__rare" data-booster-bar="rare_slots" style="width:' . $this->percent($booster['rare_slots'], $boosterSize) . '%"></span>'
            . '</div>'
            . '<div class="settings-booster-total">Всего карт: <b data-booster-total>' . $boosterSize . '</b></div>'
            . $this->rangeSlider('booster_common', 'Common', $booster['common'], 0, $boosterSize, 1, 'карт', 'common')
            . $this->rangeSlider('booster_uncommon', 'Uncommon', $booster['uncommon'], 0, $boosterSize, 1, 'карт', 'uncommon')
            . $this->rangeSlider('booster_rare_slots', 'Rare slots', $booster['rare_slots'], 0, $boosterSize, 1, 'карт', 'rare_slots')
            . '</div>'
            . '</fieldset>'
            . '<fieldset class="settings-fieldset">'
            . '<legend>Редкая / ультраредкая карта</legend>'
            . '<div class="settings-range" data-rarity-chance>'
            . '<div class="settings-range__head">'
            . '<span class="settings-stepper__label">Шанс редкой</span>'
            . '<output class="settings-stepper__value" data-rarity-output>' . $rareChance . '% / ' . $booster['ultra_rare_chance'] . '%</output>'
            . '</div>'
            . '<input type="range" name="booster_rare_chance" min="0" max="100" step="10" value="' . $rareChance . '" data-rarity-input>'
            . '<small>Второе значение считается как шанс ультраредкой.</small>'
            . '</div>'
            . '</fieldset>'
            . '<fieldset class="settings-fieldset">'
            . '<legend>Режим сетки</legend>'
            . '<div class="settings-option-grid">'
            . $this->radioTile('draft_grid_mode', GameSettings::DRAFT_GRID_MODE_CONTINUOUS, 'Обычный', $gridMode === GameSettings::DRAFT_GRID_MODE_CONTINUOUS, 'Пополнение после каждого выбора.')
            . $this->radioTile('draft_grid_mode', GameSettings::DRAFT_GRID_MODE_DISCRETE, 'Дискретный', $gridMode === GameSettings::DRAFT_GRID_MODE_DISCRETE, 'Два выбора на раунд без пополнения между ними.')
            . '</div>'
            . '</fieldset>'
            . '</section>'
            . '<section class="settings-section">'
            . '<h2>Таймер</h2>'
            . '<fieldset class="settings-fieldset">'
            . '<legend>Режим таймера</legend>'
            . '<div class="settings-option-grid">'
            . $this->radioTile('draft_timer_mode', GameSettings::DRAFT_TIMER_DEFAULT, 'По умолчанию', $timerMode === GameSettings::DRAFT_TIMER_DEFAULT, '10 мин / 30 сек')
            . $this->radioTile('draft_timer_mode', GameSettings::DRAFT_TIMER_CUSTOM, 'Настраиваемый', $timerMode === GameSettings::DRAFT_TIMER_CUSTOM, 'Выберите общий лимит и время на действие.')
            . $this->radioTile('draft_timer_mode', GameSettings::DRAFT_TIMER_UNLIMITED, 'Без ограничений', $timerMode === GameSettings::DRAFT_TIMER_UNLIMITED, 'Драфт без отсчёта времени.')
            . '</div>'
            . '</fieldset>'
            . '<div class="settings-dependent' . ($timerMode === GameSettings::DRAFT_TIMER_CUSTOM ? '' : ' is-hidden') . '" data-settings-dependent="timer-custom">'
            . $this->stepper(
                'draft_timer_total',
                'Общее время',
                (int) ($timerTotal / 60),
                (int) (GameSettings::DRAFT_TIMER_CUSTOM_TOTAL_MIN_SECONDS / 60),
                (int) (GameSettings::DRAFT_TIMER_CUSTOM_TOTAL_MAX_SECONDS / 60),
                1,
                'мин'
            )
            . $this->stepper(
                'draft_timer_action',
                'Время на действие',
                $timerAction,
                GameSettings::DRAFT_TIMER_CUSTOM_ACTION_MIN_SECONDS,
                GameSettings::DRAFT_TIMER_CUSTOM_ACTION_MAX_SECONDS,
                GameSettings::DRAFT_TIMER_CUSTOM_ACTION_STEP_SECONDS,
                'сек'
            )
            . '</div>'
            . '</section>'
            . '<section class="settings-section">'
            . '<h2>Автоматический выбор</h2>'
            . '<label class="settings-toggle">'
            . '<input type="checkbox" name="draft_pick_mode" value="' . GameSettings::DRAFT_PICK_MODE_RANDOM . '" data-settings-toggle="auto-draft"'
                . ($isRandom ? ' checked' : '') . '>'
            . '<span><b>Автоматический драфт</b><small>Если включено, выбранная сторона будет драфтить автоматически.</small></span>'
            . '</label>'
            . '<div class="settings-dependent' . ($isRandom ? '' : ' is-hidden') . '" data-settings-dependent="auto-draft">'
            . '<fieldset class="settings-fieldset">'
            . '<legend>Кто выбирает автоматически</legend>'
            . '<div class="settings-option-grid">'
            . $this->radioTile('draft_auto_side', GameSettings::DRAFT_AUTO_SIDE_BOTH, 'Оба', $autoSide === GameSettings::DRAFT_AUTO_SIDE_BOTH)
            . $this->radioTile('draft_auto_side', GameSettings::DRAFT_AUTO_SIDE_HOST, 'Хост', $autoSide === GameSettings::DRAFT_AUTO_SIDE_HOST)
            . $this->radioTile('draft_auto_side', GameSettings::DRAFT_AUTO_SIDE_PLAYER, 'Соперник', $autoSide === GameSettings::DRAFT_AUTO_SIDE_PLAYER)
            . '</div>'
            . '</fieldset>'
            . '</div>'
            . '</section>'
            . '<div class="settings-submit-row"><button class="button wide settings-submit" type="submit">Начать драфт</button></div>'
            . '</form>'
            . '<script src="/assets/js/settings.js?v=1" defer></script>';

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

    private function radioTile(string $name, string $value, string $label, bool $checked, string $hint = ''): string
    {
        return '<label class="settings-option">'
            . '<input type="radio" name="' . $this->esc($name) . '" value="' . $this->esc($value) . '"'
                . ($checked ? ' checked' : '') . '>'
            . '<span><b>' . $this->esc($label) . '</b>'
            . ($hint !== '' ? '<small>' . $this->esc($hint) . '</small>' : '')
            . '</span>'
            . '</label>';
    }

    private function stepper(string $name, string $label, int $value, int $min, int $max, int $step, string $unit): string
    {
        $value = max($min, min($max, $value));
        return '<div class="settings-stepper" data-settings-stepper data-min="' . $min . '" data-max="' . $max . '" data-step="' . $step . '">'
            . '<span class="settings-stepper__label">' . $this->esc($label) . '</span>'
            . '<div class="settings-stepper__control">'
            . '<button type="button" class="settings-stepper__button" data-stepper-action="down" aria-label="Уменьшить">−</button>'
            . '<output class="settings-stepper__value" data-stepper-output>' . $this->esc((string) $value) . ' ' . $this->esc($unit) . '</output>'
            . '<button type="button" class="settings-stepper__button" data-stepper-action="up" aria-label="Увеличить">+</button>'
            . '<input type="hidden" name="' . $this->esc($name) . '" value="' . $value . '" data-stepper-input data-unit="' . $this->esc($unit) . '">'
            . '</div>'
            . '</div>';
    }

    private function rangeSlider(string $name, string $label, int $value, int $min, int $max, int $step, string $unit, string $slot): string
    {
        return '<div class="settings-range" data-booster-row="' . $this->esc($slot) . '">'
            . '<div class="settings-range__head">'
            . '<span class="settings-stepper__label">' . $this->esc($label) . '</span>'
            . '<output class="settings-stepper__value" data-booster-output="' . $this->esc($slot) . '">' . $value . ' ' . $this->esc($unit) . '</output>'
            . '</div>'
            . '<input type="range" name="' . $this->esc($name) . '" value="' . $value . '" min="' . $min . '" max="' . $max . '" step="' . $step . '" data-booster-slot="' . $this->esc($slot) . '" data-unit="' . $this->esc($unit) . '">'
            . '</div>';
    }

    private function percent(int $value, int $total): int
    {
        return $total > 0 ? (int) round(($value / $total) * 100) : 0;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
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
