<?php
declare(strict_types=1);
namespace Berserk\Auth;

final class AuthService
{
    // Fixed valid bcrypt hash: unknown users perform the same expensive verification.
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
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
        $verified = password_verify($validInput ? $password : '', $row['password_hash'] ?? self::DUMMY_HASH);
        if (!$validInput || !$verified || !$row) throw new \DomainException(self::LOGIN_ERROR);
        return new Account((int)$row['id'], $row['login']);
    }
}
