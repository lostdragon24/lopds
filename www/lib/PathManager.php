<?php

require_once __DIR__.'/EnvLoader.php';

class PathManager
{
    private static $basePath;
    private static $booksDir;
    private static $cacheDir;
    private static $coverCacheDir;
    private static $scannerPath;
    private static $scannerConfig;
    private static $dbPath;

    /**
     * Получить базовый путь к директории сайта.
     */
    public static function getBasePath()
    {
        if (null === self::$basePath) {
            self::$basePath = dirname(__DIR__); // Поднимаемся на уровень выше /lib
        }

        return self::$basePath;
    }

    /**
     * Получить путь к директории с книгами.
     */
    public static function getBooksDir()
    {
        if (null === self::$booksDir) {
            self::$booksDir = EnvLoader::get('BOOKS_DIR', self::getBasePath().'/books/');
            // Убираем возможные кавычки
            self::$booksDir = trim(self::$booksDir, '"\'');
            // Убираем лишние слэши в конце
            self::$booksDir = rtrim(self::$booksDir, '/');
            error_log('PathManager::getBooksDir() = '.self::$booksDir);
        }

        return self::$booksDir;
    }

    /**
     * Получить путь к кэшу.
     */
    public static function getCacheDir()
    {
        if (null === self::$cacheDir) {
            self::$cacheDir = EnvLoader::get('CACHE_DIR', self::getBasePath().'/cache');
        }

        return self::$cacheDir;
    }

    /**
     * Получить путь к кэшу обложек.
     */
    public static function getCoverCacheDir()
    {
        if (null === self::$coverCacheDir) {
            self::$coverCacheDir = EnvLoader::get('COVER_CACHE_DIR', self::getCacheDir().'/covers');
        }

        return self::$coverCacheDir;
    }

    /**
     * Получить путь к сканеру.
     */
    public static function getScannerPath()
    {
        if (null === self::$scannerPath) {
            self::$scannerPath = EnvLoader::get('SCANNER_PATH', self::getBasePath().'/scanner/book_scanner');
        }

        return self::$scannerPath;
    }

    /**
     * Получить путь к конфигу сканера.
     */
    public static function getScannerConfig()
    {
        if (null === self::$scannerConfig) {
            self::$scannerConfig = self::getBasePath().'/config/config.ini';
        }

        return self::$scannerConfig;
    }

    /**
     * Получить путь к SQLite базе данных.
     */
    public static function getDbPath()
    {
        if (null === self::$dbPath) {
            self::$dbPath = EnvLoader::get('DB_PATH', self::getBasePath().'/data/library.db');
        }

        return self::$dbPath;
    }

    /**
     * Получить путь к директории данных.
     */
    public static function getDataDir()
    {
        return self::getBasePath().'/data';
    }

    /**
     * Сбросить все пути (для тестов).
     */
    public static function reset()
    {
        self::$basePath = null;
        self::$booksDir = null;
        self::$cacheDir = null;
        self::$coverCacheDir = null;
        self::$scannerPath = null;
        self::$scannerConfig = null;
        self::$dbPath = null;
    }
}
