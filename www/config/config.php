<?php

// /config/config.php
// Подключаем классы ТОЛЬКО при необходимости (ленивая загрузка)

if (!defined('LOPDS_ROOT')) {
    define('LOPDS_ROOT', dirname(__DIR__));
}

// ============================================
// БЫСТРАЯ ЗАГРУЗКА .env (без лишних классов)
// ============================================

// Загружаем .env простым способом
$env = [];
$envFile = __DIR__.'/.env';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (0 === strpos(trim($line), '#')) {
            continue;
        }
        if (false !== strpos($line, '=')) {
            list($key, $value) = explode('=', $line, 2);
            $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }
    }
}

function env($key, $default = null)
{
    global $env;

    return $env[$key] ?? $default;
}

// ============================================
// ОСНОВНОЙ КЛАСС CONFIG (без тяжелых require)
// ============================================

class Config
{
    // Статические переменные для кэширования
    private static $booksDir;
    private static $cacheDir;
    private static $scannerPath;
    private static $dbConfig;

    // ===== БЫСТРЫЕ МЕТОДЫ (без require) =====

    public static function getSiteTitle()
    {
        return env('SITE_TITLE', 'Моя домашняя библиотека');
    }

    public static function getItemsPerPage()
    {
        return (int) env('ITEMS_PER_PAGE', 20);
    }

    // ===== ПУТИ (с кэшированием) =====

    public static function getBasePath()
    {
        return LOPDS_ROOT;
    }

    public static function getBooksDir()
    {
        if (null === self::$booksDir) {
            self::$booksDir = rtrim(env('BOOKS_DIR', LOPDS_ROOT.'/books'), '/');
        }

        return self::$booksDir;
    }

    public static function getCacheDir()
    {
        if (null === self::$cacheDir) {
            self::$cacheDir = rtrim(env('CACHE_DIR', LOPDS_ROOT.'/cache'), '/');
        }

        return self::$cacheDir;
    }

    public static function getCoverCacheDir()
    {
        return self::getCacheDir().'/covers';
    }

    public static function getScannerPath()
    {
        if (null === self::$scannerPath) {
            self::$scannerPath = env('SCANNER_PATH', LOPDS_ROOT.'/scanner/book_scanner');
        }

        return self::$scannerPath;
    }

    public static function getScannerConfig()
    {
        return LOPDS_ROOT.'/config/config.ini';
    }

    public static function getDbPath()
    {
        return env('DB_PATH', LOPDS_ROOT.'/data/library.db');
    }

    // ===== БАЗА ДАННЫХ (ленивая загрузка) =====

    public static function getDbConfig()
    {
        if (null === self::$dbConfig) {
            $type = env('DB_TYPE', 'sqlite');

            if ('sqlite' === $type) {
                self::$dbConfig = [
                    'type' => 'sqlite',
                    'path' => self::getDbPath(),
                ];
            } else {
                self::$dbConfig = [
                    'type' => 'mysql',
                    'host' => env('DB_HOST', 'localhost'),
                    'name' => env('DB_NAME', 'mybook'),
                    'user' => env('DB_USER', 'root'),
                    'pass' => env('DB_PASS', ''),
                    'port' => env('DB_PORT', '3306'),
                ];
            }
        }

        return self::$dbConfig;
    }

    public static function getDbType()
    {
        return env('DB_TYPE', 'sqlite');
    }

    // ===== СЕССИИ И CSRF =====

    public static function startSecureSession()
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        return self::getCsrfToken();
    }

    public static function getCsrfToken()
    {
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function validateCsrfToken($token)
    {
        if (empty($token)) {
            return false;
        }
        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // ===== ЖАНРЫ (ленивая загрузка через GenreManager) =====

    public static function getReadableGenre($genreCode)
    {
        // Загружаем GenreManager только когда нужен
        static $genreManager = null;
        if (null === $genreManager) {
            require_once LOPDS_ROOT.'/lib/GenreManager.php';
            $genreManager = true;
        }

        return GenreManager::getReadableName($genreCode);
    }

    public static function getAllGenres()
    {
        static $genres = null;
        if (null === $genres) {
            require_once LOPDS_ROOT.'/lib/GenreManager.php';
            $genres = GenreManager::getAllGenres();
        }

        return $genres;
    }

    public static function getGenresByCategory()
    {
        static $categories = null;
        if (null === $categories) {
            require_once LOPDS_ROOT.'/lib/GenreManager.php';
            $categories = GenreManager::getGenresByCategory();
        }

        return $categories;
    }

    // ===== MIME-ТИПЫ =====

    public static function getMimeType($fileType)
    {
        $mimeTypes = [
            'fb2' => 'application/x-fictionbook+xml',
            'epub' => 'application/epub+zip',
            'pdf' => 'application/pdf',
            'mobi' => 'application/x-mobipocket-ebook',
            'txt' => 'text/plain',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
        ];

        return $mimeTypes[strtolower($fileType)] ?? 'application/octet-stream';
    }

    // ===== ВСПОМОГАТЕЛЬНЫЕ =====

    public static function isDevelopment()
    {
        return file_exists(__DIR__.'/.dev');
    }

    // ===== КОНСТАНТЫ ДЛЯ СОВМЕСТИМОСТИ =====

    public const DB_TYPE = 'mysql';
    public const DB_HOST = 'localhost';
    public const SITE_TITLE = 'Моя домашняя библиотека';
    public const ITEMS_PER_PAGE = 10;
    public const OPDS_TITLE = 'Моя библиотека';
    public const OPDS_AUTHOR = 'Book Lib';
    public const OPDS_ID = 'urn:uuid:your-uuid-here';
    public const ENABLE_CACHE = true;
    public const USE_APCU = true;
    public const CACHE_TTL = 36000;

    public const PERFORMANCE = [
        'enable_page_cache' => true,
        'memory_limit' => '512M',
        'max_search_results' => 500,
        'enable_query_logging' => false,
    ];

    public const CACHE_CONFIG = [
        'search_results' => ['ttl' => 300],
        'book_data' => ['ttl' => 3600],
        'statistics' => ['ttl' => 1800],
        'page_cache' => ['ttl' => 300],
    ];

    public const COVER_PROCESSING = [
        'max_width' => 800,
        'max_height' => 1200,
        'quality' => 85,
        'enable_file_cache' => true,
        'enable_apcu_cache' => true,
        'apcu_ttl' => 3600,
    ];

    public const SEARCH_OPTIMIZATION = [
        'enable_fulltext' => false,
        'min_word_length' => 3,
        'cache_search_results' => true,
        'search_cache_ttl' => 300,
    ];

    public const ALLOWED_ORDER_BY = ['id', 'title', 'author', 'year', 'added_date'];
    public const ALLOWED_ORDER_DIR = ['ASC', 'DESC'];
}
