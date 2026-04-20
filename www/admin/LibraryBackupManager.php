<?php

// admin/LibraryBackupManager.php

require_once __DIR__.'/../init.php';

class LibraryBackupManager
{
    private $backupDir;
    private $booksDir;
    private $db;
    private $maxSize = 1073741824; // 1 GB в байтах
    private $maxBackups = 5;

    public function __construct()
    {
        $this->backupDir = Config::getBasePath().'/backups/library';
        $this->booksDir = rtrim(Config::getBooksDir(), '/');
        $this->db = Database::getInstance();

        // Создаём директорию для бэкапов если её нет
        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * Проверить, можно ли создать бэкап (размер не превышает лимит).
     */
    public function canBackup()
    {
        $size = $this->getLibrarySize();

        return $size <= $this->maxSize;
    }

    /**
     * Получить размер библиотеки.
     */
    public function getLibrarySize()
    {
        if (!file_exists($this->booksDir)) {
            return 0;
        }

        $size = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->booksDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            $size += $file->getSize();
            // Если уже превысили лимит, можно остановиться
            if ($size > $this->maxSize) {
                return $size;
            }
        }

        return $size;
    }

    /**
     * Получить размер библиотеки в читаемом формате.
     */
    public function getLibrarySizeFormatted()
    {
        return $this->formatBytes($this->getLibrarySize());
    }

    /**
     * Создать бэкап библиотеки.
     */
    public function createBackup($name = null)
    {
        // Проверяем размер
        $size = $this->getLibrarySize();
        if ($size > $this->maxSize) {
            throw new Exception(sprintf(__('backup_library_too_large'), $this->formatBytes($size), $this->formatBytes($this->maxSize)));
        }

        // Генерируем имя бэкапа
        if (empty($name)) {
            $name = 'library_backup_'.date('Y-m-d_H-i-s');
        } else {
            $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
        }

        $backupFile = $this->backupDir.'/'.$name.'.zip';

        // Проверяем, не существует ли уже такой файл
        if (file_exists($backupFile)) {
            $backupFile = $this->backupDir.'/'.$name.'_'.time().'.zip';
        }

        // Создаём ZIP архив
        $zip = new ZipArchive();
        if (true !== $zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new Exception(__('backup_library_cannot_create'));
        }

        // Добавляем файлы в архив
        $this->addFilesToZip($zip, $this->booksDir, '');

        // Добавляем информацию о бэкапе
        $backupInfo = [
            'created_at' => date('Y-m-d H:i:s'),
            'books_count' => $this->getBooksCount(),
            'library_size' => $size,
            'php_version' => PHP_VERSION,
            'site_title' => Config::getSiteTitle(),
        ];

        $zip->addFromString('backup_info.json', json_encode($backupInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $zip->close();

        // Проверяем, что архив создался
        if (!file_exists($backupFile)) {
            throw new Exception(__('backup_library_create_failed'));
        }

        // Очищаем старые бэкапы
        $this->cleanupOldBackups();

        return [
            'success' => true,
            'filename' => basename($backupFile),
            'size' => filesize($backupFile),
            'size_formatted' => $this->formatBytes(filesize($backupFile)),
            'message' => __('backup_library_created'),
        ];
    }

    /**
     * Рекурсивно добавить файлы в ZIP.
     */
    private function addFilesToZip($zip, $source, $target)
    {
        $files = scandir($source);

        foreach ($files as $file) {
            if ('.' == $file || '..' == $file) {
                continue;
            }

            $sourcePath = $source.'/'.$file;
            $targetPath = empty($target) ? $file : $target.'/'.$file;

            if (is_dir($sourcePath)) {
                $zip->addEmptyDir($targetPath);
                $this->addFilesToZip($zip, $sourcePath, $targetPath);
            } else {
                $zip->addFile($sourcePath, $targetPath);
            }
        }
    }

    /**
     * Получить список бэкапов.
     */
    public function getBackups()
    {
        $files = glob($this->backupDir.'/library_backup_*.zip');
        $backups = [];

        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'size_formatted' => $this->formatBytes(filesize($file)),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'timestamp' => filemtime($file),
                'info' => $this->getBackupInfo($file),
            ];
        }

        // Сортируем по дате (новые сверху)
        usort($backups, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        return $backups;
    }

    /**
     * Получить информацию о бэкапе из файла backup_info.json.
     */
    private function getBackupInfo($backupFile)
    {
        $zip = new ZipArchive();
        if (true === $zip->open($backupFile)) {
            $infoContent = $zip->getFromName('backup_info.json');
            $zip->close();

            if ($infoContent) {
                return json_decode($infoContent, true);
            }
        }

        return null;
    }

    /**
     * Восстановить библиотеку из бэкапа.
     */
    public function restoreBackup($filename)
    {
        $backupFile = $this->backupDir.'/'.basename($filename);

        if (!file_exists($backupFile)) {
            throw new Exception(__('backup_library_not_found'));
        }

        // Создаём временную директорию для извлечения
        $tempDir = sys_get_temp_dir().'/library_restore_'.uniqid();
        mkdir($tempDir, 0755, true);

        try {
            // Извлекаем архив
            $zip = new ZipArchive();
            if (true !== $zip->open($backupFile)) {
                throw new Exception(__('backup_library_cannot_open'));
            }

            $zip->extractTo($tempDir);
            $zip->close();

            // Проверяем наличие backup_info.json
            if (!file_exists($tempDir.'/backup_info.json')) {
                throw new Exception(__('backup_library_invalid'));
            }

            // Создаём бэкап текущей библиотеки перед восстановлением
            $this->createBackup('before_restore_'.date('Y-m-d_H-i-s'));

            // Очищаем текущую директорию книг (но не удаляем саму директорию)
            $this->clearBooksDirectory();

            // Копируем файлы из временной директории (исключая backup_info.json)
            $this->copyRestoredFiles($tempDir, $this->booksDir);

            // Очищаем кэш
            Cache::invalidateByType('statistics');
            Cache::invalidateByType('search_results');

            return [
                'success' => true,
                'message' => __('backup_library_restored'),
            ];
        } catch (Exception $e) {
            throw $e;
        } finally {
            // Удаляем временную директорию
            $this->deleteDirectory($tempDir);
        }
    }

    /**
     * Очистить директорию книг (удалить все файлы, но оставить папки).
     */
    private function clearBooksDirectory()
    {
        if (!file_exists($this->booksDir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->booksDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
    }

    /**
     * Скопировать восстановленные файлы.
     */
    private function copyRestoredFiles($source, $target)
    {
        if (!file_exists($target)) {
            mkdir($target, 0755, true);
        }

        $files = scandir($source);

        foreach ($files as $file) {
            if ('.' == $file || '..' == $file || 'backup_info.json' == $file) {
                continue;
            }

            $sourcePath = $source.'/'.$file;
            $targetPath = $target.'/'.$file;

            if (is_dir($sourcePath)) {
                $this->copyRestoredFiles($sourcePath, $targetPath);
            } else {
                copy($sourcePath, $targetPath);
                chmod($targetPath, 0644);
            }
        }
    }

    /**
     * Удалить бэкап
     */
    public function deleteBackup($filename)
    {
        $backupFile = $this->backupDir.'/'.basename($filename);

        if (!file_exists($backupFile)) {
            throw new Exception(__('backup_library_not_found'));
        }

        if (!unlink($backupFile)) {
            throw new Exception(__('backup_library_delete_failed'));
        }

        return [
            'success' => true,
            'message' => __('backup_library_deleted'),
        ];
    }

    /**
     * Скачать бэкап
     */
    public function downloadBackup($filename)
    {
        $backupFile = $this->backupDir.'/'.basename($filename);

        if (!file_exists($backupFile)) {
            throw new Exception(__('backup_library_not_found'));
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.basename($backupFile).'"');
        header('Content-Length: '.filesize($backupFile));
        header('Cache-Control: private, max-age=0, must-revalidate');

        readfile($backupFile);
        exit;
    }

    /**
     * Очистить старые бэкапы.
     */
    private function cleanupOldBackups()
    {
        $backups = $this->getBackups();

        if (count($backups) <= $this->maxBackups) {
            return;
        }

        $toDelete = array_slice($backups, $this->maxBackups);

        foreach ($toDelete as $backup) {
            $this->deleteBackup($backup['filename']);
        }
    }

    /**
     * Получить количество книг.
     */
    private function getBooksCount()
    {
        try {
            $stmt = $this->db->getConnection()->query('SELECT COUNT(*) FROM books');

            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Удалить директорию рекурсивно.
     */
    private function deleteDirectory($dir)
    {
        if (!file_exists($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Форматировать байты.
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision).' '.$units[$pow];
    }

    /**
     * Получить статистику использования диска для бэкапов.
     */
    public function getBackupStats()
    {
        $backups = $this->getBackups();
        $totalSize = 0;

        foreach ($backups as $backup) {
            $totalSize += $backup['size'];
        }

        return [
            'count' => count($backups),
            'total_size' => $totalSize,
            'total_size_formatted' => $this->formatBytes($totalSize),
            'max_backups' => $this->maxBackups,
            'free_space' => disk_free_space($this->backupDir),
            'free_space_formatted' => $this->formatBytes(disk_free_space($this->backupDir)),
        ];
    }
}
