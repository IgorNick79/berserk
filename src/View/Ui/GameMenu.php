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

    public static function preparation(string $csrf, bool $sound, bool $finished): string
    {
        return '<details class="game-menu" data-game-menu><summary aria-label="Меню матча" title="Меню матча">☰</summary><div class="game-menu-panel" role="menu" aria-label="Меню матча">'
            .'<a href="/lobby.php" role="menuitem">В лобби</a>'
            .'<button type="button" role="menuitemcheckbox" data-preparation-sound aria-checked="'.($sound?'true':'false').'"'.($finished?' disabled':'').'>Звук <span data-preparation-sound-check>'.($sound?'✓':'').'</span></button>'
            .'<button type="button" role="menuitem" data-preparation-playmat'.($finished?' disabled':'').'>Коврик</button>'
            .'<button type="button" role="menuitem" disabled>Отложить игру <small>Скоро</small></button>'
            .'<button type="button" role="menuitem" disabled title="Debug отключён для аккаунтных игр">Debug</button>'
            .self::account($csrf).'<p data-preparation-menu-notice role="status"></p></div></details>';
    }
}
