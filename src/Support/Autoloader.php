<?php

namespace HLM\Filters\Support;

final class Autoloader
{
    private const PREFIX = 'HLM\\Filters\\';
    private const BASE_DIR = __DIR__ . '/../';

    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    private static function autoload(string $class): void
    {
        if (strpos($class, self::PREFIX) !== 0) {
            return;
        }

        $relative = substr($class, strlen(self::PREFIX));
        $path = self::BASE_DIR . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($path)) {
            require $path;
        }
    }
}
