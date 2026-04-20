<?php

// lib/SessionManager.php

class SessionManager
{
    private static $started = false;
    private static $csrfToken;

    /**
     * Запустить сессию, если она еще не запущена.
     */
    public static function start()
    {
        if (self::$started || PHP_SESSION_NONE !== session_status()) {
            return;
        }

        // Настройки безопасности сессии
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        if (isset($_SERVER['HTTPS'])) {
            ini_set('session.cookie_secure', 1);
        }

        // Имя сессии уже установлено в init.php
        session_start();
        self::$started = true;
    }

    /**
     * Получить CSRF токен (создать если нет).
     */
    public static function getCsrfToken()
    {
        self::start();

        if (null === self::$csrfToken) {
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            self::$csrfToken = $_SESSION['csrf_token'];
        }

        return self::$csrfToken;
    }

    /**
     * Получить значение из сессии.
     */
    public static function get($key, $default = null)
    {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    /**
     * Установить значение в сессию.
     */
    public static function set($key, $value)
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Удалить значение из сессии.
     */
    public static function delete($key)
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /**
     * Очистить сессию.
     */
    public static function destroy()
    {
        self::start();
        $_SESSION = [];
        session_destroy();
        self::$started = false;
    }
}
