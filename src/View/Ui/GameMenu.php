<?php
declare(strict_types=1);
namespace Berserk\View\Ui;

final class GameMenu
{
    public static function account(string $csrf): string
    {
        $token=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8');
        return '<a href="/auth.php?page=account" role="menuitem">Профиль</a><form method="post" action="/auth.php?page=logout"><input type="hidden" name="csrf" value="'.$token.'"><button type="submit" role="menuitem">Выйти</button></form>';
    }

    private static function debug(?\Berserk\Core\GameState $state, string $key, string $csrf): string
    {
        if ($state === null || !\Berserk\Auth\DebugAccess::forPlayer($state,$key) || $state->status === 'game_over') return '';
        if ($state->status !== 'settings' || $state->cards !== []) return '<details><summary>Debug</summary><p>Настройка бросков закрыта после запуска подготовки.</p></details>';
        $raw = $state->lucktestDebug['rolls'][(string)$state->getPlayer($key)->userId]['raw'] ?? 'off';
        return '<details><summary>Debug</summary><form method="post"><input type="hidden" name="game" value="'.$state->gameId.'">'
            .'<input type="hidden" name="cmd" value="set_lucktest_debug"><input type="hidden" name="csrf" value="'.htmlspecialchars($csrf,ENT_QUOTES).'">'
            .'<input type="hidden" name="prepare_version" value="'.(int)$state->persistenceVersion.'">'
            .'<label><input aria-label="Броски Dice" placeholder="Броски Dice: 6,1,4 или off" name="debug_roll" maxlength="1024" value="'.htmlspecialchars($raw,ENT_QUOTES).'"></label><button type="submit">Сохранить Debug</button></form></details>';
    }

    public static function preparation(string $csrf, bool $sound, bool $finished, ?\Berserk\Core\GameState $state = null, string $playerKey = ''): string
    {
        return '<details class="game-menu" data-game-menu><summary aria-label="Меню матча" title="Меню матча">☰</summary><div class="game-menu-panel" role="menu" aria-label="Меню матча">'
            .'<a href="/lobby.php" role="menuitem">В лобби</a>'
            .'<button type="button" role="menuitemcheckbox" data-preparation-sound aria-checked="'.($sound?'true':'false').'"'.($finished?' disabled':'').'>Звук <span data-preparation-sound-check>'.($sound?'✓':'').'</span></button>'
            .'<button type="button" role="menuitem" data-preparation-playmat'.($finished?' disabled':'').'>Коврик</button>'
            .'<button type="button" role="menuitem" disabled>Отложить игру <small>Скоро</small></button>'
            .self::debug($state,$playerKey,$csrf)
            .self::account($csrf).'<p data-preparation-menu-notice role="status"></p></div></details>';
    }
}
