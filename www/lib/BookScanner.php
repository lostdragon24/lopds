<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/SecurityHelper.php';

class BookScanner
{
    /**
     * Запустить сканирование безопасно.
     */
    public static function runScan()
    {
        $security = SecurityHelper::getInstance();

        // Проверка, что сканер находится в разрешенной директории
        $scannerPath = realpath(Config::SCANNER_PATH);
        $allowedDir = realpath(__DIR__.'/../');

        if (false === $scannerPath || 0 !== strpos($scannerPath, $allowedDir)) {
            throw new Exception('Invalid scanner path');
        }

        if (!file_exists($scannerPath)) {
            throw new Exception('Scanner binary not found');
        }

        if (!file_exists(Config::SCANNER_CONFIG)) {
            throw new Exception('Scanner config not found');
        }

        // Проверка прав на выполнение
        if (!is_executable($scannerPath)) {
            throw new Exception('Scanner binary is not executable');
        }

        // Используем escapeshellcmd и escapeshellarg для безопасности
        $command = escapeshellcmd($scannerPath).' '.
                  escapeshellarg(Config::SCANNER_CONFIG).' 2>&1';

        $output = [];
        $returnCode = 0;

        // Устанавливаем временный лимит выполнения
        set_time_limit(300); // 5 минут

        exec($command, $output, $returnCode);

        if (0 !== $returnCode) {
            throw new Exception('Scanner failed with code: '.$returnCode);
        }

        // Логируем успешное сканирование
        error_log('Scan completed successfully at '.date('Y-m-d H:i:s'));

        return $output;
    }

    /**
     * Проверить статус сканера безопасно.
     */
    public static function getScanStatus()
    {
        // Используем безопасную команду
        $scannerName = basename(Config::SCANNER_PATH);
        $command = 'pgrep -f '.escapeshellarg($scannerName).' 2>/dev/null';

        $output = [];
        exec($command, $output, $returnCode);

        return !empty($output);
    }

    /**
     * Проверить, можно ли запустить сканер сейчас
     */
    public static function canRunScan()
    {
        // Проверяем, не запущен ли уже сканер
        if (self::getScanStatus()) {
            return false;
        }

        // Проверяем права на запись в директорию книг
        if (!is_writable(Config::BOOKS_DIR)) {
            return false;
        }

        return true;
    }
}
