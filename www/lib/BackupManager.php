<?php

// lib/BackupManager.php

require_once __DIR__ . '/../init.php';

class BackupManager
{
    private $backupDir;

    public function __construct($backupDir = null)
    {
        if ($backupDir === null) {
            $backupDir = __DIR__ . '/../backups/config';
        }
        $this->backupDir = $backupDir;

        if (!file_exists($this->backupDir)) {
            if (!mkdir($this->backupDir, 0755, true)) {
                error_log(sprintf(__('backup_error_create_dir'), $this->backupDir));
            }
        }
    }

    /**
     * Получить список бэкапов
     */
    public function getBackups()
    {
        $files = glob($this->backupDir . '/env.backup.*');
        $backups = [];

        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'size_formatted' => $this->formatBytes(filesize($file)),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'timestamp' => filemtime($file),
                'path' => $file
            ];
        }

        // Сортируем по дате (новые сверху)
        usort($backups, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        return $backups;
    }

    /**
     * Восстановить из бэкапа
     */
    public function restore($filename, $targetFile)
    {
        $backupFile = $this->backupDir . '/' . basename($filename);

        if (!file_exists($backupFile)) {
            throw new Exception(sprintf(__('backup_error_not_found'), $filename));
        }

        if (!is_readable($backupFile)) {
            throw new Exception(sprintf(__('backup_error_not_readable'), $backupFile));
        }

        // Создаём бэкап текущего файла перед восстановлением
        $this->createBackup($targetFile);

        if (!copy($backupFile, $targetFile)) {
            throw new Exception(sprintf(__('backup_error_restore_failed'), $filename));
        }

        chmod($targetFile, 0600);

        error_log(sprintf(__('backup_restored'), $filename, $targetFile));

        return true;
    }

    /**
     * Создать бэкап файла
     */
    public function createBackup($sourceFile)
    {
        if (!file_exists($sourceFile)) {
            error_log(sprintf(__('backup_error_source_not_found'), $sourceFile));
            return false;
        }

        if (!is_readable($sourceFile)) {
            error_log(sprintf(__('backup_error_source_not_readable'), $sourceFile));
            return false;
        }

        $backupFile = $this->backupDir . '/env.backup.' . date('Ymd_His');

        if (!copy($sourceFile, $backupFile)) {
            error_log(sprintf(__('backup_error_copy_failed'), $sourceFile, $backupFile));
            return false;
        }

        chmod($backupFile, 0600);

        error_log(sprintf(__('backup_created'), basename($backupFile)));

        // Оставляем только последние 10 бэкапов
        $this->cleanup(10);

        return $backupFile;
    }

    /**
     * Очистить старые бэкапы
     */
    public function cleanup($keep = 10)
    {
        $backups = $this->getBackups();
        if (count($backups) <= $keep) {
            return;
        }

        $toDelete = array_slice($backups, $keep);
        $deleted = 0;

        foreach ($toDelete as $backup) {
            $filePath = $this->backupDir . '/' . $backup['filename'];
            if (unlink($filePath)) {
                $deleted++;
                error_log(sprintf(__('backup_deleted'), $backup['filename']));
            } else {
                error_log(sprintf(__('backup_error_delete'), $backup['filename']));
            }
        }

        if ($deleted > 0) {
            error_log(sprintf(__('backup_cleanup_completed'), $deleted, $keep));
        }

        return $deleted;
    }

    /**
     * Получить информацию о бэкапах (размер, количество)
     */
    public function getBackupInfo()
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
            'oldest' => !empty($backups) ? end($backups)['date'] : null,
            'newest' => !empty($backups) ? $backups[0]['date'] : null
        ];
    }

    /**
     * Удалить конкретный бэкап
     */
    public function deleteBackup($filename)
    {
        $backupFile = $this->backupDir . '/' . basename($filename);

        if (!file_exists($backupFile)) {
            throw new Exception(sprintf(__('backup_error_not_found'), $filename));
        }

        if (!unlink($backupFile)) {
            throw new Exception(sprintf(__('backup_error_delete'), $filename));
        }

        error_log(sprintf(__('backup_deleted'), $filename));

        return true;
    }

    /**
     * Создать бэкап с пользовательским именем
     */
    public function createNamedBackup($sourceFile, $name)
    {
        if (!file_exists($sourceFile)) {
            throw new Exception(sprintf(__('backup_error_source_not_found'), $sourceFile));
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
        $backupFile = $this->backupDir . '/' . $safeName . '.backup.' . date('Ymd_His');

        if (!copy($sourceFile, $backupFile)) {
            throw new Exception(sprintf(__('backup_error_copy_failed'), $sourceFile, $backupFile));
        }

        chmod($backupFile, 0600);

        return $backupFile;
    }

    /**
     * Форматировать байты
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Проверить, достаточно ли места для создания бэкапа
     */
    public function hasEnoughSpace($requiredBytes)
    {
        $freeSpace = disk_free_space($this->backupDir);

        if ($freeSpace === false) {
            error_log(__('backup_error_cant_check_space'));
            return true; // Не можем проверить - предполагаем что хватит
        }

        return $freeSpace >= $requiredBytes;
    }

    /**
     * Получить статистику использования диска для бэкапов
     */
    public function getDiskStats()
    {
        $total = disk_total_space($this->backupDir);
        $free = disk_free_space($this->backupDir);

        if ($total === false || $free === false) {
            return null;
        }

        $used = $total - $free;

        return [
            'total' => $total,
            'total_formatted' => $this->formatBytes($total),
            'free' => $free,
            'free_formatted' => $this->formatBytes($free),
            'used' => $used,
            'used_formatted' => $this->formatBytes($used),
            'percent_used' => round(($used / $total) * 100, 1),
            'percent_free' => round(($free / $total) * 100, 1)
        ];
    }
}
