<?php
declare(strict_types=1);
namespace Berserk\View\Ui;

final class LuckTestDebugFields
{
    public static function toggle(bool $enabled): string
    {
        return '<input type="hidden" name="lucktest_url_roll" value="0">'
            . '<label class="debug-url-toggle"><input type="checkbox" name="lucktest_url_roll" value="1"' . ($enabled ? ' checked' : '') . '><span>Разрешить debug_roll в URL</span></label>'
            . '<p class="debug-help">Выключено — обычные случайные броски. Включено — тестовые аккаунты могут задавать броски через <code>&amp;debug_roll=6,1,4</code> или <code>6*</code>; <code>off</code> возвращает случайные броски.</p>';
    }

    public static function scenario(string $value, bool $quick): string
    {
        $example = json_encode(['cards'=>[
            ['owner'=>'host','ukid'=>'s1_101'],
            ['owner'=>'player','ukid'=>'s1_136'],
        ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return '<div class="debug-scenario" data-lucktest-scenario' . ($quick ? '' : ' hidden') . '>'
            . '<label class="debug-json-label">Обязательные карты (JSON)<textarea name="lucktest_scenario" aria-label="Обязательные карты (JSON)" maxlength="4096" rows="7" spellcheck="false" placeholder="Вставьте и отредактируйте JSON из примера"' . ($quick ? '' : ' disabled') . '>' . htmlspecialchars($value, ENT_QUOTES) . '</textarea></label>'
            . '<details class="debug-json-help"><summary>Формат и пример JSON</summary>'
            . '<dl><dt>cards</dt><dd>Список обязательных карт. Остальные места заполняются случайно.</dd><dt>owner</dt><dd><code>host</code> — создатель игры; <code>player</code> — второй игрок.</dd><dt>ukid</dt><dd>Код карты, например <code>s1_101</code>.</dd><dt>row, col</dt><dd>Необязательные целые координаты: row 1–6, col 1–5. Задаются вместе; клетка должна соответствовать правилам начальной расстановки.</dd><dt>hp</dt><dd>Необязательное начальное здоровье карты: целое число от 1 до её максимального HP. Например, hp: 1 — начать с раненым существом. Для deck не задаётся.</dd></dl>'
            . '<div class="debug-code"><div class="debug-code-header"><span>JSON</span><button type="button" class="debug-copy" data-debug-copy aria-label="Скопировать весь пример JSON" title="Скопировать JSON"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg></button></div><pre><code>' . htmlspecialchars($example, ENT_QUOTES) . '</code></pre><span class="debug-copy-status" role="status"></span></div>'
            . '<p class="debug-help">Не больше выбранного количества карт для каждой стороны. Поле можно оставить пустым. Пример копируется целиком; измените его и вставьте в поле выше.</p></details></div>';
    }
}
