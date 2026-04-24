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


    // ===== МЕТОДЫ ДЛЯ OPDS =====

public static function getOpdsTitle()
{
    return env('OPDS_TITLE', 'Моя библиотека');
}

public static function getOpdsAuthor()
{
    return env('OPDS_AUTHOR', 'Book Lib');
}

public static function getOpdsId()
{
    return env('OPDS_ID', 'urn:uuid:your-uuid-here');
}

   
public static function getOpdsDefaultLang()
{
    $lang = env('OPDS_DEFAULT_LANG', null);
    
    if ($lang === null) {
        $lang = env('OPDS_LANG', 'ru');
        
        if ($lang !== 'ru') {
            error_log("Using deprecated OPDS_LANG, please rename to OPDS_DEFAULT_LANG in .env");
        }
    }
    
    return $lang;
}

public static function isCacheEnabled()
{
    return env('ENABLE_CACHE', 'true');
}

public static function isUseApcu()
{
    return env('USE_APCU', 'true');
}

public static function isCacheTtl()
{
    return env('CACHE_TTL', '36000');
}

public static function isPageCache()
{
    return filter_var(env('PAGE_CACHE_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
}

public static function getMemorylimit()
{
    return  env('MEMORY_LIMIT', '512M');
}


public static function isQuerylogging()
{
    return filter_var(env('Q_L', 'false'), FILTER_VALIDATE_BOOLEAN);
}


public static function isPageCacheEnabled()
{
    return filter_var(env('PAGE_CACHE_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
}

    /**
     * Проверить, используется ли SQLite.
     */
    public static function isSqlite()
    {
        return 'sqlite' === self::getDbType();
    }

    /**
     * Проверить, используется ли MySQL.
     */
    public static function isMysql()
    {
        return 'mysql' === self::getDbType();
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
	return SessionManager::getCsrfToken();

    }

    public static function getCsrfToken()
    {
	return SessionManager::getCsrfToken();
    }

    public static function validateCsrfToken($token)
    {
	return SessionManager::validateCsrfToken($token);
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

    public const CACHE_CONFIG = [
        'search_results' => ['ttl' => 300],
        'book_data' => ['ttl' => 3600],
        'statistics' => ['ttl' => 1800],
        'page_cache' => ['ttl' => 300],
    ];

    public const SEARCH_OPTIMIZATION = [
        'enable_fulltext' => false,
        'min_word_length' => 3,
        'cache_search_results' => true,
        'search_cache_ttl' => 300,
    ];

    public const COVER_PROCESSING = [
        'max_width' => 800,
        'max_height' => 1200,
        'quality' => 85,
        'enable_file_cache' => true,
        'enable_apcu_cache' => true,
        'apcu_ttl' => 3600,
    ];

}
