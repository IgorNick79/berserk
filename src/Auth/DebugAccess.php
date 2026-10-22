<?php
declare(strict_types=1);
namespace Berserk\Auth;

use Berserk\Core\{GameSettings,GameState};

/** One permission, sourced only from private auth configuration. Never a match role or payload flag. */
final class DebugAccess
{
    private static ?Account $current = null;

    public static function bind(Account $account): void { self::$current = $account; }
    public static function authenticated(): bool { return self::$current !== null; }

    public static function allowsId(int $id): bool
    {
        $file = dirname(__DIR__, 2).'/config/auth.local.php';
        try { $config = is_file($file) ? require $file : []; }
        catch (\Throwable) { return false; }
        if (!is_array($config)) return false;
        $ids = $config['debug_account_ids'] ?? null;
        return is_array($ids) && array_is_list($ids) && count($ids) === 2
            && is_int($ids[0]) && is_int($ids[1]) && $ids[0] > 0 && $ids[1] > 0 && $ids[0] !== $ids[1]
            && in_array($id, $ids, true);
    }

    public static function forPlayer(GameState $state, string $key): bool
    {
        return self::$current !== null && $state->mode === GameSettings::MODE_LUCKTEST
            && isset($state->players[$key]) && $state->players[$key]->userId === self::$current->id
            && self::allowsId(self::$current->id);
    }
}
