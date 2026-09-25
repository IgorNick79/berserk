<?php
// src/View/Ui/Form.php

declare(strict_types=1);

namespace Berserk\View\Ui;

/**
 * Низкоуровневые HTML-элементы форм.
 * Все методы статические, возвращают готовый HTML.
 * Экранирование внутри — снаружи не надо думать об htmlspecialchars.
 */
final class Form
{
    // ─── Инпуты ──────────────────────────────────────────────

    /**
     * @param array{checked?:bool, disabled?:bool, class?:string, id?:string, attrs?:array} $opts
     */
    public static function radio(string $name, $value, string $label, array $opts = []): string
    {
        return self::inputWithLabel('radio', $name, $value, $label, $opts);
    }

    public static function checkbox(string $name, $value, string $label, array $opts = []): string
    {
        return self::inputWithLabel('checkbox', $name, $value, $label, $opts);
    }

    /**
     * Список однотипных input'ов (radio или checkbox).
     *
     * @param array<int, array{value:mixed, label:string, checked?:bool, disabled?:bool}> $items
     * @param array{class?:string} $opts
     */
    public static function choiceList(array $items, string $type, string $name, array $opts = []): string
    {
        $html = '';
        foreach ($items as $item) {
            $itemOpts = [
                'checked'  => !empty($item['checked']),
                'disabled' => !empty($item['disabled']),
            ];
            if (!empty($opts['class'])) {
                $itemOpts['class'] = $opts['class'];
            }
            $html .= self::$type($name, $item['value'], (string) $item['label'], $itemOpts);
        }
        return $html;
    }

    // ─── Кнопки ──────────────────────────────────────────────

    /**
     * Кнопка-ссылка (GET-переход).
     *
     * @param array{class?:string, attrs?:array} $opts
     */
    public static function button(string $label, string $url, array $opts = []): string
    {
        $classes = ['button'];
        if (!empty($opts['class'])) {
            $classes[] = $opts['class'];
        }

        $attrs = self::buildAttrs(array_merge($opts['attrs'] ?? [], [
            'href'  => $url,
            'class' => implode(' ', $classes),
        ]));

        return '<a' . $attrs . '>' . self::esc($label) . '</a>';
    }

    /**
     * Кнопка submit.
     *
     * @param array{class?:string, attrs?:array} $opts
     */
    public static function submit(string $label, array $opts = []): string
    {
        $classes = ['button'];
        if (!empty($opts['class'])) {
            $classes[] = $opts['class'];
        }

        $attrs = self::buildAttrs(array_merge($opts['attrs'] ?? [], [
            'type'  => 'submit',
            'class' => implode(' ', $classes),
        ]));

        return '<button' . $attrs . '>' . self::esc($label) . '</button>';
    }

    /**
     * Группа кнопок-ссылок.
     *
     * @param array<int, array{label:string, url:string, class?:string}> $items
     */
    public static function buttonList(array $items, string $wrapperClass = 'death-choice-buttons'): string
    {
        $html = '';
        foreach ($items as $item) {
            $opts = [];
            if (!empty($item['class'])) {
                $opts['class'] = $item['class'];
            }
            $html .= self::button($item['label'], $item['url'], $opts) . ' ';
        }
        return '<div class="' . self::esc($wrapperClass) . '">' . $html . '</div>';
    }

    // ─── Форма ───────────────────────────────────────────────

    /**
     * Обёртка <form method="get"> с hidden-полями.
     *
     * @param array<string, mixed> $hidden  hidden-поля (name => value)
     * @param array{class?:string} $opts
     */
    public static function form(array $hidden, string $body, array $opts = []): string
    {
        $class = $opts['class'] ?? 'card-choice-form';

        $hiddenHtml = '';
        foreach ($hidden as $name => $value) {
            $hiddenHtml .= '<input type="hidden" name="' . self::esc((string) $name)
                . '" value="' . self::esc((string) $value) . '">';
        }

        return '<form method="get" class="' . self::esc($class) . '">'
            . $hiddenHtml
            . $body
            . '</form>';
    }

    /**
     * Стандартный набор hidden-полей для боевых форм:
     * роль (first/second), game, cmd.
     *
     * @param array<string, mixed> $extra  дополнительные hidden
     */
    public static function battleHidden(string $role, int $gameId, string $cmd, array $extra = []): array
    {
        return array_merge([
            $role === 'host' ? 'first' : 'second' => '',
            'game' => $gameId,
            'cmd'  => $cmd,
        ], $extra);
    }

    // ─── Обёртка выбора ──────────────────────────────────────

    /**
     * Общий контейнер «карточка с выбором»: заголовок + тело.
     */
    public static function cardChoice(string $title, string $body): string
    {
        return '<div class="card-choice">'
            . '<h3>' . self::esc($title) . '</h3>'
            . $body
            . '</div>';
    }

    // ─── Внутреннее ──────────────────────────────────────────

    private static function inputWithLabel(
        string $type, string $name, $value, string $label, array $opts
    ): string {
        $attrs = [
            'type'  => $type,
            'name'  => $name,
            'value' => (string) $value,
        ];

        if (!empty($opts['checked']))  $attrs['checked']  = 'checked';
        if (!empty($opts['disabled'])) $attrs['disabled'] = 'disabled';
        if (!empty($opts['id']))       $attrs['id']       = (string) $opts['id'];
        if (!empty($opts['attrs']))    $attrs = array_merge($attrs, $opts['attrs']);

        $inputHtml = '<input' . self::buildAttrs($attrs) . '>';
        $labelHtml = '<span>' . self::esc($label) . '</span>';

        $class = 'choice-radio';
        if (!empty($opts['class'])) {
            $class .= ' ' . $opts['class'];
        }

        return '<label class="' . self::esc($class) . '">' . $inputHtml . $labelHtml . '</label>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function buildAttrs(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $name => $value) {
            if ($value === null || $value === false) continue;
            if ($value === true) {
                $parts[] = self::esc((string) $name);
                continue;
            }
            $parts[] = self::esc((string) $name) . '="' . self::esc((string) $value) . '"';
        }
        return $parts ? ' ' . implode(' ', $parts) : '';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES);
    }
}