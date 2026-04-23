<?php

class SecurityHelper
{
    private static $instance = null;
    private $rateLimit = [];

    private function __construct()
    {
        // Пустой конструктор
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Добавление заголовков безопасности
     */
    public function addSecurityHeaders()
    {
        // Базовые заголовки безопасности
        header("X-XSS-Protection: 0"); // CSP заменяет этот заголовок
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()");

        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        }

        // ============================================
        // CONTENT SECURITY POLICY (CSP)
        // ============================================
        $directives = [
            "default-src 'self'",
            // Скрипты: разрешаем свои, CDN Bootstrap/jQuery/PDF.js/EPUB.js, inline для текущих шаблонов, WASM для новых версий PDF.js
            "script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline' 'wasm-unsafe-eval'",
            // Стили: CDN, inline (Bootstrap), data: для иконок
            "style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline' data:",
            // Изображения: свои, data/blob для обложек и читателей, внешние (если будут CDN-обложки)
            "img-src 'self' data: blob: https:",
            // Шрифты: CDN Font Awesome, data: для встроенных
            "font-src 'self' https://cdnjs.cloudflare.com data:",
            // Сетевые запросы: только к своему API
            "connect-src 'self'",
            // iframe: читатели FB2/EPUB/PDF используют iframe и blob:
            "frame-src 'self' blob:",
            // Запрет embed/object
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "upgrade-insecure-requests"
        ];

        $csp = implode('; ', $directives);

        // header("Content-Security-Policy-Report-Only: $csp");
        header("Content-Security-Policy: $csp");
    }

    /**
     * Санитизация IP адреса
     */
    public function sanitizeIp($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return '0.0.0.0';
    }

    /**
     * Санитизация поискового запроса
     */
    public function sanitizeSearchQuery($query)
    {
        if (empty($query)) {
            return '';
        }

        $query = strip_tags($query);
        $query = preg_replace('/[^\p{L}\p{N}\s\-_]/u', '', $query);
        $query = trim($query);

        return mb_substr($query, 0, 100);
    }

    /**
     * Санитизация поля поиска
     */
    public function sanitizeSearchField($field)
    {
        $allowed = ['all', 'title', 'author', 'genre', 'series'];
        return in_array($field, $allowed, true) ? $field : 'all';
    }

    /**
     * Санитизация имени файла
     */
    public function sanitizeFilename($filename)
    {
        $filename = basename($filename);
        $filename = preg_replace('/[^\w\-\.\s]/u', '', $filename);
        $filename = preg_replace('/\.{2,}/', '.', $filename);
        return mb_substr($filename, 0, 200);
    }

    /**
     * Запуск сессии если не запущена
     */
    public function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Генерация CSRF токена
     */
    public function generateCsrfToken()
    {
        $this->startSession();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }


    /**
     * Rate limiting
     */
    public function checkRateLimit($key, $limit, $window)
    {
        $now = time();

        if (!isset($this->rateLimit[$key])) {
            $this->rateLimit[$key] = [];
        }

        $this->rateLimit[$key] = array_filter($this->rateLimit[$key], function ($timestamp) use ($now, $window) {
            return $timestamp > ($now - $window);
        });

        if (count($this->rateLimit[$key]) >= $limit) {
            return false;
        }

        $this->rateLimit[$key][] = $now;
        return true;
    }


    public function sanitizeBookContent($content)
    {
        // Разрешённые теги для FB2
        $allowed = '<p><br><h1><h2><h3><strong><em><i><b><ul><ol><li><img><a>';
        $content = strip_tags($content, $allowed);
        // Удалить опасные атрибуты
        $content = preg_replace('/<(\w+)[^>]*?(on\w+)=["\'][^"\']*["\'][^>]*>/i', '<$1>', $content);
        $content = preg_replace('/javascript:/i', '', $content);
        return $content;
    }


    public function validateCsrfToken($token)
    {
        $this->startSession();
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            error_log("CSRF validate: empty token or session token");
            return false;
        }
        $result = hash_equals($_SESSION['csrf_token'], $token);
        error_log("CSRF validate: " . ($result ? "success" : "failed"));
        return $result;
    }


}
