<?php
declare(strict_types=1);
namespace Berserk\Auth;

final class AuthService
{
    public const LOGIN_ERROR = 'Неверный логин или пароль.';

    public function __construct(private AuthStore $store, private string $secret) {}

    public function register(string $login, string $password, string $address): Account
    {
        $key = Account::loginKey($login);
        Account::validatePassword($password);
        if (!$this->store->reserveAttempt($key,$address,$this->secret,'register')) throw new \OverflowException('Слишком много попыток. Повторите позже.');
        return $this->store->register($login,$password);
    }

    public function login(string $login, string $password, string $address): Account
    {
        // Malformed logins are charged to the IP bucket too.
        try { $key = Account::loginKey($login); } catch (\InvalidArgumentException) { $key = '!invalid'; }
        if (!$this->store->reserveAttempt($key,$address,$this->secret)) throw new \OverflowException('Слишком много попыток. Повторите позже.');
        $row = $key === '!invalid' ? null : $this->store->find($key);
        $validInput = strlen($password) >= 10 && strlen($password) <= 72 && !str_contains($password,"\0");
        $verified = PasswordPolicy::verify($validInput ? $password : '', $row['password_hash'] ?? null);
        if (!$validInput || !$verified || !$row) throw new \DomainException(self::LOGIN_ERROR);
        return new Account((int)$row['id'], $row['login']);
    }
}
