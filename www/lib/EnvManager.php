<?php

// lib/EnvManager.php

require_once __DIR__.'/../init.php';

class EnvManager
{
    private $envFile;
    private $envData;
    private $loaded = false;

    public function __construct()
    {
        $this->envFile = __DIR__.'/../config/.env';
    }

    /**
     * Загрузить данные из .env.
     */
    public function load()
    {
        if ($this->loaded) {
            return $this->envData;
        }

        $this->envData = [];

        if (file_exists($this->envFile)) {
            $lines = file($this->envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Пропускаем комментарии
                if (0 === strpos(trim($line), '#')) {
                    continue;
                }

                if (false !== strpos($line, '=')) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, " \t\n\r\0\x0B\"'");
                    $this->envData[$key] = $value;
                }
            }
        } else {
            error_log(__('env_manager_file_not_found').': '.$this->envFile);
        }

        $this->loaded = true;

        return $this->envData;
    }

    /**
     * Получить значение.
     */
    public function get($key, $default = null)
    {
        $this->load();

        return $this->envData[$key] ?? $default;
    }

    /**
     * Получить все значения.
     */
    public function getAll()
    {
        $this->load();

        return $this->envData;
    }

    /**
     * Сохранить данные в .env.
     */
    public function save($data)
    {
        // Загружаем текущие данные, чтобы не потерять существующие значения
        $currentData = $this->load();

        // Объединяем с новыми данными, но сохраняем пароль если он не передан
        $mergedData = $this->mergeWithCurrent($currentData, $data);

        $content = $this->generateContent($mergedData);

        // Создаём бэкап
        $this->backup();

        // Проверяем возможность записи
        if (file_exists($this->envFile) && !is_writable($this->envFile)) {
            throw new Exception(__('env_manager_not_writable'));
        }

        // Проверяем директорию
        $dir = dirname($this->envFile);
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception(sprintf(__('env_manager_cannot_create_dir'), $dir));
            }
        }

        // Сохраняем
        if (false === file_put_contents($this->envFile, $content)) {
            throw new Exception(__('env_manager_save_failed'));
        }

        chmod($this->envFile, 0600);
        $this->reset();

        error_log(__('env_manager_saved'));

        return true;
    }

    /**
     * Объединить текущие данные с новыми, сохраняя пароль.
     */
    private function mergeWithCurrent($current, $new)
    {
        $result = $current;

        foreach ($new as $key => $value) {
            // Специальная обработка для паролей
            if ('DB_PASS' === $key) {
                // Если новый пароль пустой или равен '********', сохраняем старый
                if (empty($value) || '********' === $value) {
                    if (isset($current[$key])) {
                        $result[$key] = $current[$key];
                    }
                    continue;
                }
            }

            // Для всех остальных полей обновляем
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Сгенерировать содержимое .env.
     */
    private function generateContent($data)
    {
        $content = '; '.__('env_manager_header')."\n";
        $content .= '; '.sprintf(__('env_manager_generated'), date('Y-m-d H:i:s'))."\n\n";

        // Секции для лучшей читаемости
        $sections = [
            'site' => ['SITE_TITLE', 'ITEMS_PER_PAGE'],
            'opds' => ['OPDS_TITLE', 'OPDS_AUTHOR', 'OPDS_ID'],
            'database' => ['DB_TYPE', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_PATH'],
            'cache' => ['ENABLE_CACHE', 'USE_APCU', 'CACHE_TTL', 'PAGE_CACHE_ENABLED'],
            'paths' => ['BOOKS_DIR', 'CACHE_DIR', 'COVER_CACHE_DIR', 'SCANNER_PATH'],
            'performance' => ['MEMORY_LIMIT', 'MAX_SEARCH_RESULTS'],
            'security' => ['ADMIN_USER', 'ADMIN_PASSWORD_HASH', 'ADMIN_ALLOWED_IPS'],
        ];

        foreach ($sections as $section => $keys) {
            $content .= "\n; ".__('env_manager_section_'.$section)."\n";
            foreach ($keys as $key) {
                if (isset($data[$key])) {
                    $value = $data[$key];
                    // Экранируем если есть пробелы или специальные символы
                    if (false !== strpos($value, ' ') || false !== strpos($value, '#') || false !== strpos($value, '=')) {
                        $value = '"'.addslashes($value).'"';
                    }
                    $content .= "$key = $value\n";
                }
            }
        }

        return $content;
    }

    /**
     * Создать бэкап
     */
    private function backup()
    {
        if (!file_exists($this->envFile)) {
            return;
        }

        $backupDir = __DIR__.'/../backups/config';
        if (!file_exists($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                error_log(sprintf(__('env_manager_cannot_create_backup_dir'), $backupDir));

                return;
            }
        }

        $backupFile = $backupDir.'/env.backup.'.date('Ymd_His');

        if (!copy($this->envFile, $backupFile)) {
            error_log(sprintf(__('env_manager_backup_failed'), $this->envFile, $backupFile));

            return;
        }

        chmod($backupFile, 0600);
        error_log(sprintf(__('env_manager_backup_created'), basename($backupFile)));

        // Оставляем только последние 10 бэкапов
        $this->cleanupBackups($backupDir, 10);
    }

    /**
     * Очистить старые бэкапы.
     */
    private function cleanupBackups($backupDir, $keep = 10)
    {
        $backups = glob($backupDir.'/env.backup.*');
        if (count($backups) <= $keep) {
            return;
        }

        usort($backups, function ($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        $toDelete = array_slice($backups, 0, count($backups) - $keep);
        $deleted = 0;

        foreach ($toDelete as $file) {
            if (unlink($file)) {
                ++$deleted;
            }
        }

        if ($deleted > 0) {
            error_log(sprintf(__('env_manager_backups_cleaned'), $deleted, $keep));
        }
    }

    /**
     * Восстановить из бэкапа.
     */
    public function restore($backupFile)
    {
        $backupPath = __DIR__.'/../backups/config/'.basename($backupFile);

        if (!file_exists($backupPath)) {
            throw new Exception(sprintf(__('env_manager_backup_not_found'), $backupFile));
        }

        if (!copy($backupPath, $this->envFile)) {
            throw new Exception(sprintf(__('env_manager_restore_failed'), $backupFile));
        }

        chmod($this->envFile, 0600);
        $this->reset();

        error_log(sprintf(__('env_manager_restored'), basename($backupFile)));

        return true;
    }

    /**
     * Сбросить кэш.
     */
    public function reset()
    {
        $this->envData = null;
        $this->loaded = false;
    }

    /**
     * Проверить, существует ли файл .env.
     */
    public function exists()
    {
        return file_exists($this->envFile);
    }

    /**
     * Получить путь к файлу .env.
     */
    public function getFilePath()
    {
        return $this->envFile;
    }

    /**
     * Проверить права доступа к файлу.
     */
    public function checkPermissions()
    {
        $result = [
            'exists' => false,
            'readable' => false,
            'writable' => false,
            'path' => $this->envFile,
        ];

        if (file_exists($this->envFile)) {
            $result['exists'] = true;
            $result['readable'] = is_readable($this->envFile);
            $result['writable'] = is_writable($this->envFile);
            $result['perms'] = substr(sprintf('%o', fileperms($this->envFile)), -4);
        } else {
            $dir = dirname($this->envFile);
            $result['dir_exists'] = file_exists($dir);
            $result['dir_writable'] = is_writable($dir);
        }

        return $result;
    }

    /**
     * Создать файл .env с значениями по умолчанию.
     */
    public function createDefault()
    {
        if ($this->exists()) {
            throw new Exception(__('env_manager_already_exists'));
        }

        $defaults = [
            'SITE_TITLE' => 'Моя домашняя библиотека',
            'ITEMS_PER_PAGE' => '10',
            'OPDS_TITLE' => 'Моя библиотека',
            'OPDS_AUTHOR' => 'Book Lib',
            'OPDS_ID' => 'urn:uuid:your-uuid-here',
            'DB_TYPE' => 'sqlite',
            'DB_PATH' => __DIR__.'/../data/library.db',
            'ENABLE_CACHE' => 'true',
            'USE_APCU' => 'true',
            'CACHE_TTL' => '36000',
            'PAGE_CACHE_ENABLED' => 'true',
            'BOOKS_DIR' => __DIR__.'/../books',
            'CACHE_DIR' => __DIR__.'/../cache',
            'COVER_CACHE_DIR' => __DIR__.'/../cache/covers',
            'SCANNER_PATH' => __DIR__.'/../scanner/book_scanner',
            'MEMORY_LIMIT' => '512M',
            'MAX_SEARCH_RESULTS' => '500',
            'ADMIN_USER' => 'admin',
        ];

        return $this->save($defaults);
    }
}
