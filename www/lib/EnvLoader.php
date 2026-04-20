<?php

class EnvLoader
{
    private static $env;
    private static $loaded = false;

    /**
     * Загрузить переменные из .env файла.
     */
    public static function load()
    {
        if (self::$loaded) {
            return;
        }

        self::$env = [];
        $envFile = __DIR__.'/../config/.env';

        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (false !== strpos($line, '=') && 0 !== strpos($line, '#')) {
                    list($key, $value) = explode('=', $line, 2);
                    self::$env[trim($key)] = trim($value);
                }
            }
        }

        self::$loaded = true;
    }

    /**
     * Получить значение переменной окружения.
     */
    public static function get($key, $default = null)
    {
        self::load();

        return self::$env[$key] ?? $default;
    }

    /**
     * Получить все переменные.
     */
    public static function getAll()
    {
        self::load();

        return self::$env;
    }

    /**
     * Сбросить загрузку (для тестов).
     */
    public static function reset()
    {
        self::$env = null;
        self::$loaded = false;
    }
}
