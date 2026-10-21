<?php
declare(strict_types=1);
namespace Berserk\Auth;

final class Account
{
    public function __construct(public readonly int $id, public readonly string $login)
    {
        if ($id <= 0) throw new \RuntimeException('Invalid account identity');
    }

    public static function loginKey(string $login): string
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{3,32}\z/D', $login)) {
            throw new \InvalidArgumentException('Логин: 3–32 латинских буквы, цифры, _ или -.');
        }
        return strtolower($login);
    }

    public static function validatePassword(string $password): void
    {
        // PASSWORD_DEFAULT is bcrypt on the supported PHP 8.1 runtime: never truncate at 72 bytes.
        if (strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new \InvalidArgumentException('Пароль должен содержать от 10 до 72 байт без нулевых символов.');
        }
    }
}
