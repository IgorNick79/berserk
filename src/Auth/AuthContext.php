<?php
declare(strict_types=1);
namespace Berserk\Auth;

/** The Stage 5A session, shared by lobby and gameplay; never imports PHPSESSID. */
final class AuthContext
{
    public static function gameDb(): \Berserk\Core\Db
    {
        $file = dirname(__DIR__,2).'/config/db.php';
        if (!is_file($file)) throw new \RuntimeException('Game configuration unavailable');
        $config = require $file;
        if (!is_array($config)) throw new \RuntimeException('Game configuration unavailable');
        return new \Berserk\Core\Db($config);
    }

    public static function start(): Account
    {
        $file = dirname(__DIR__,2).'/config/auth.local.php';
        if (!is_file($file)) throw new \RuntimeException('Auth configuration unavailable');
        $config = require $file;
        AuthSession::start(($config['allow_loopback_http'] ?? false) === true);
        $id = AuthSession::id();
        if ($id === null) throw new \DomainException('authentication_required',401);
        $account = (new AuthStore($config['db']))->account($id);
        if ($account === null) throw new \DomainException('authentication_required',401);
        return $account;
    }
}
