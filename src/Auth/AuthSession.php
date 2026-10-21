<?php
declare(strict_types=1);
namespace Berserk\Auth;

final class AuthSession
{
    public const NAME = 'BERSERKAUTH5A';
    public const PRINCIPAL = 'berserk_authenticated_user_id';

    public static function start(bool $allowLoopbackHttp = false): void
    {
        $secure = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTPS'] ?? '') === '1';
        // Never trust forwarded headers from arbitrary clients.
        $loopback = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true);
        if (!$secure && !($allowLoopbackHttp && $loopback)) throw new \DomainException('Для авторизации требуется HTTPS.', 403);
        if (session_status() !== PHP_SESSION_NONE) throw new \RuntimeException('Unexpected session');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name(self::NAME);
        session_cache_limiter(''); // Endpoint owns its explicit private/no-store policy.
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        if (!session_start()) throw new \RuntimeException('Session unavailable');
    }

    public static function id(): ?int
    {
        $id = $_SESSION[self::PRINCIPAL] ?? null;
        return is_int($id) && $id > 0 ? $id : null;
    }

    public static function csrf(): string
    {
        return $_SESSION['auth_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function requireCsrf(mixed $token): void
    {
        if (!is_string($token) || !isset($_SESSION['auth_csrf']) || !hash_equals($_SESSION['auth_csrf'], $token)) {
            throw new \DomainException('Недействительный запрос. Обновите страницу.', 403);
        }
    }

    public static function login(Account $account): void
    {
        // Replace all state, including legacy role/user_id and test bindings.
        if (!session_regenerate_id(true)) throw new \RuntimeException('Session rotation failed');
        $_SESSION = [self::PRINCIPAL => $account->id, 'auth_csrf'=>bin2hex(random_bytes(32))];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        $cookie = session_get_cookie_params();
        setcookie(self::NAME, '', ['expires'=>1,'path'=>$cookie['path'],'secure'=>$cookie['secure'],'httponly'=>true,'samesite'=>'Lax']);
        if (!session_destroy()) throw new \RuntimeException('Logout failed');
    }
}
