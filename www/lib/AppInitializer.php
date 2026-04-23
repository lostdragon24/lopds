<?php

require_once __DIR__.'/EnvLoader.php';
require_once __DIR__.'/PathManager.php';
require_once __DIR__.'/SessionManager.php';
require_once __DIR__.'/ScannerConfigGenerator.php';

class AppInitializer
{
    private static $initialized = false;

    /**
     * Инициализировать приложение.
     */
    public static function init()
    {
        if (self::$initialized) {
            return;
        }

        // Загружаем переменные окружения
        EnvLoader::load();

        // Создаем необходимые директории
        self::createDirectories();

        // Устанавливаем лимит памяти
        self::setMemoryLimit();

        // Создаем конфиг для сканера
        ScannerConfigGenerator::generate();

        self::$initialized = true;
    }

    /**
     * Создать необходимые директории.
     */
    private static function createDirectories()
    {
        $dirs = [
            PathManager::getCacheDir(),
            PathManager::getCoverCacheDir(),
            PathManager::getDataDir(),
        ];

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Установить лимит памяти.
     */
    private static function setMemoryLimit()
    {
        $memoryLimit = Config::getMemorylimit() ?? null;
        if ($memoryLimit) {
            ini_set('memory_limit', $memoryLimit);
        }
    }

    /**
     * Проверить, инициализировано ли приложение.
     */
    public static function isInitialized()
    {
        return self::$initialized;
    }
}
