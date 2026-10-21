<?php
declare(strict_types=1);
namespace Berserk\Auth;

/** Explicit cross-version policy; no runtime hash generation for unknown logins. */
final class PasswordPolicy
{
    public const COST = 12;
    // Public synthetic hashes, never account credentials. Used only for verification work.
    private const DUMMY = [
        4 => '$2y$04$EKxTpRrRQ4jdj/3tYyq5BegRL38uczdphnj3uNL5nS2cIhdi/hnVu',
        5 => '$2y$05$VuYaq0KK7ZzqvtKkVr1yku8ZDcVMB32x1yxz48wCnWMu6isQM5.1q',
        6 => '$2y$06$7R64H7Rm40FziGT/oNJ9heyQR/7IHEhvfo.AX3gZverSxfzFdYEfu',
        7 => '$2y$07$WdjPXewE1k7bKiT9elDVIufh075BK.cgxkCayFj9dhcNT2QCWiLKa',
        8 => '$2y$08$pnAEjfVGcvVAIpnjp.nQbOMOVOdMKVJEWoE.e0vI3VIboanRocx5O',
        9 => '$2y$09$yhQyb6C.oydk9/uIVkJ4sObtxvY3APuGJHCSfJtYB32L9UzsrUqvy',
        10 => '$2y$10$m3Xm/Jiv2IPynmXm2fwvgeNOPJqN5avEyEzf8RkghAbccpl9oNEKa',
        11 => '$2y$11$lNjsYIyBNK2YcaQmF3HhqORp/KMZkABD6JzGizuMGkEsopLVdTYjK',
        12 => '$2y$12$iNBgiy7KoEREq4jUi9r/NurlNWVc2B4rSQXg7zJURdBteifjxAen2',
    ];

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost'=>self::COST]);
    }

    public static function verify(string $password, ?string $stored): bool
    {
        $hash = $stored ?? self::DUMMY[self::COST];
        $info = password_get_info($hash);
        if ($info['algoName'] === 'unknown') {
            password_verify($password, self::DUMMY[self::COST]);
            return false;
        }
        $verified = password_verify($password, $hash);
        if ($info['algoName'] === 'bcrypt') {
            $cost = (int)($info['options']['cost'] ?? 0);
            // Real cost c + padding c..11 = 2^12 units, including old cost10 accounts.
            // Higher-cost existing hashes retain native verification; never downgrade them.
            if ($cost >= 4 && $cost < self::COST) {
                for ($pad=$cost; $pad<self::COST; $pad++) password_verify($password,self::DUMMY[$pad]);
            }
        }
        return $stored !== null && $verified;
    }
}
