<?php
// src/Core/Autoloader.php

namespace Berserk\Core;

class Autoloader
{
    /** @var array<string, string> */
    private static array $prefixes = [];

    public static function register(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    public static function addNamespace(string $prefix, string $dir): void
    {
        self::$prefixes[$prefix] = rtrim($dir, '/\\') . '/';
    }

    public static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $dir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file = $dir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
}