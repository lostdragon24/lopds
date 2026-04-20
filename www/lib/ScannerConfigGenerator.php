<?php

require_once __DIR__.'/DbConfig.php';
require_once __DIR__.'/PathManager.php';

class ScannerConfigGenerator
{
    /**
     * Сгенерировать конфиг для сканера.
     */
    public static function generate()
    {
        $configFile = PathManager::getScannerConfig();
        $configDir = dirname($configFile);

        // Создаем директорию для конфига если нужно
        if (!file_exists($configDir)) {
            @mkdir($configDir, 0755, true);
        }

        $content = self::buildConfigContent();

        file_put_contents($configFile, $content);
        @chmod($configFile, 0600);

        return $configFile;
    }

    /**
     * Собрать содержимое конфига.
     */
    private static function buildConfigContent()
    {
        $dbConfig = DbConfig::getConfig();

        $content = "[database]\n";
        $content .= 'type = '.$dbConfig['type']."\n";

        if (DbConfig::isSqlite()) {
            $content .= 'path = '.$dbConfig['path']."\n";
        } else {
            $content .= 'host = '.$dbConfig['host']."\n";
            $content .= 'user = '.$dbConfig['user']."\n";
            $content .= 'password = '.$dbConfig['pass']."\n";
            $content .= 'database = '.$dbConfig['name']."\n";
        }

        $content .= "\n[scanner]\n";
        $content .= 'books_dir = '.PathManager::getBooksDir()."\n";
        $content .= 'log_file = '.PathManager::getCacheDir()."/scanner.log\n";

        $content .= "rescan_unchanged =  no\n";
        $content .= "enable_inpx =  no\n";
        $content .= "clear_database_inpx =  no\n";
        $content .= "log_level =  no\n";
        $content .= "hash_algorithm =  md5\n";

        return $content;
    }
}
