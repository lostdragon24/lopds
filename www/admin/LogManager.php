<?php

// admin/LogManager.php

class LogManager
{
    private $phpLogFile;
    private $scannerLogFile;
    private $systemLogFile;

    public function __construct()
    {
        $this->phpLogFile = ini_get('error_log') ?: '/var/log/apache2/error.log';
        $this->scannerLogFile = Config::getCacheDir().'/scanner.log';
        $this->systemLogFile = Config::getCacheDir().'/system.log';

        // Создаём системный лог-файл если не существует
        if (!file_exists($this->systemLogFile)) {
            $this->writeSystemLog(__('log_system_initialized'), 'INFO');
        }
    }

    /**
     * Получить PHP лог.
     */
    public function getPhpLog($lines = 100)
    {
        $log = $this->readLastLines($this->phpLogFile, $lines);

        // Добавляем информацию о файле
        $info = $this->getLogFileInfo($this->phpLogFile);

        return [
            'lines' => $log,
            'file' => $this->phpLogFile,
            'exists' => $info['exists'],
            'size' => $info['size'],
            'size_formatted' => $info['size_formatted'],
            'writable' => $info['writable'],
            'readable' => $info['readable'],
        ];
    }

    /**
     * Получить лог сканера.
     */
    public function getScannerLog($lines = 100)
    {
        $log = $this->readLastLines($this->scannerLogFile, $lines);

        // Добавляем информацию о файле
        $info = $this->getLogFileInfo($this->scannerLogFile);

        // Анализируем содержимое лога
        $stats = $this->analyzeScannerLog($log);

        return [
            'lines' => $log,
            'file' => $this->scannerLogFile,
            'exists' => $info['exists'],
            'size' => $info['size'],
            'size_formatted' => $info['size_formatted'],
            'writable' => $info['writable'],
            'readable' => $info['readable'],
            'stats' => $stats,
        ];
    }

    /**
     * Получить системный лог.
     */
    public function getSystemLog($lines = 100)
    {
        $log = $this->readLastLines($this->systemLogFile, $lines);

        // Добавляем информацию о файле
        $info = $this->getLogFileInfo($this->systemLogFile);

        return [
            'lines' => $log,
            'file' => $this->systemLogFile,
            'exists' => $info['exists'],
            'size' => $info['size'],
            'size_formatted' => $info['size_formatted'],
            'writable' => $info['writable'],
            'readable' => $info['readable'],
        ];
    }

    /**
     * Получить все логи для дашборда (краткая информация).
     */
    public function getLogsSummary()
    {
        $summary = [];

        $logs = [
            'php' => $this->phpLogFile,
            'scanner' => $this->scannerLogFile,
            'system' => $this->systemLogFile,
        ];

        foreach ($logs as $name => $file) {
            $info = $this->getLogFileInfo($file);
            $summary[$name] = [
                'name' => $this->getLogTypeName($name),
                'file' => $file,
                'exists' => $info['exists'],
                'size' => $info['size_formatted'],
                'last_modified' => $info['last_modified'],
                'last_modified_formatted' => $info['last_modified_formatted'],
                'has_errors' => 'php' === $name ? $this->hasPhpErrors() : false,
            ];
        }

        return $summary;
    }

    /**
     * Получить количество ошибок в PHP логе.
     */
    public function getPhpErrorCount($hours = 24)
    {
        if (!file_exists($this->phpLogFile) || !is_readable($this->phpLogFile)) {
            return 0;
        }

        $cutoff = time() - ($hours * 3600);
        $errorCount = 0;

        try {
            $handle = fopen($this->phpLogFile, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    // Ищем строки с ошибками
                    if (preg_match('/\[(.*?)\]/', $line, $matches)) {
                        $logTime = strtotime($matches[1]);
                        if ($logTime >= $cutoff
                            && (false !== stripos($line, 'error')
                             || false !== stripos($line, 'warning')
                             || false !== stripos($line, 'notice'))) {
                            ++$errorCount;
                        }
                    }
                }
                fclose($handle);
            }
        } catch (Exception $e) {
            error_log('Error counting PHP errors: '.$e->getMessage());
        }

        return $errorCount;
    }

    /**
     * Записать сообщение в системный лог.
     */
    public function writeSystemLog($message, $level = 'INFO')
    {
        $logEntry = sprintf(
            "[%s] [%s] %s - %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $_SERVER['REMOTE_ADDR'] ?? 'CLI',
            $message
        );

        file_put_contents($this->systemLogFile, $logEntry, FILE_APPEND | LOCK_EX);

        // Очищаем старые записи если файл слишком большой (больше 10 MB)
        if (file_exists($this->systemLogFile) && filesize($this->systemLogFile) > 10 * 1024 * 1024) {
            $this->rotateSystemLog();
        }
    }

    /**
     * Очистить лог-файл.
     */
    public function clearLog($logType)
    {
        $logFile = $this->getLogFileByType($logType);

        if (!$logFile) {
            throw new Exception(__('log_invalid_type'));
        }

        if (!file_exists($logFile)) {
            // Файл не существует - создаём пустой
            file_put_contents($logFile, '');
            chmod($logFile, 0644);

            return true;
        }

        if (!is_writable($logFile)) {
            throw new Exception(__('log_not_writable'));
        }

        // Очищаем файл
        if (unlink($logFile)) {
            // Создаём новый с заголовком
            $header = sprintf(
                "[%s] [INFO] %s - %s\n",
                date('Y-m-d H:i:s'),
                $_SERVER['REMOTE_ADDR'] ?? 'CLI',
                __('log_cleared')
            );
            file_put_contents($logFile, $header);
            chmod($logFile, 0644);

            return true;
        }

        throw new Exception(__('log_clear_failed'));
    }

    /**
     * Скачать лог-файл.
     */
    public function downloadLog($logType)
    {
        $logFile = $this->getLogFileByType($logType);

        if (!$logFile || !file_exists($logFile)) {
            throw new Exception(__('log_file_not_found'));
        }

        if (!is_readable($logFile)) {
            throw new Exception(__('log_not_readable'));
        }

        $filename = basename($logFile);
        $size = filesize($logFile);

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="'.$filename.'_'.date('Y-m-d').'.log"');
        header('Content-Length: '.$size);
        header('Cache-Control: private, max-age=0, must-revalidate');

        readfile($logFile);
        exit;
    }

    /**
     * Ротация системного лога (создание бэкапа).
     */
    private function rotateSystemLog()
    {
        if (!file_exists($this->systemLogFile)) {
            return;
        }

        $backupFile = $this->systemLogFile.'.backup.'.date('Ymd_His');
        rename($this->systemLogFile, $backupFile);

        // Оставляем только последние 5 бэкапов
        $backups = glob($this->systemLogFile.'.backup.*');
        if (count($backups) > 5) {
            usort($backups, function ($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            $toDelete = array_slice($backups, 0, count($backups) - 5);
            foreach ($toDelete as $file) {
                unlink($file);
            }
        }

        // Создаём новый лог-файл
        file_put_contents($this->systemLogFile, '');
        chmod($this->systemLogFile, 0644);

        $this->writeSystemLog(__('log_rotated'), 'INFO');
    }

    /**
     * Прочитать последние N строк из файла.
     */
    private function readLastLines($file, $lines)
    {
        if (!file_exists($file) || !is_readable($file)) {
            return [];
        }

        $data = [];
        try {
            $handle = fopen($file, 'r');
            if ($handle) {
                // Перемещаемся в конец файла
                fseek($handle, -min(filesize($file), 102400), SEEK_END);

                // Читаем построчно
                while (!feof($handle)) {
                    $line = fgets($handle);
                    if (false !== $line) {
                        $data[] = rtrim($line);
                        if (count($data) > $lines) {
                            array_shift($data);
                        }
                    }
                }
                fclose($handle);
            }
        } catch (Exception $e) {
            error_log('Error reading log file: '.$e->getMessage());
        }

        // Обрабатываем каждую строку для форматирования
        foreach ($data as &$line) {
            $line = $this->formatLogLine($line);
        }

        return $data;
    }

    /**
     * Форматировать строку лога для отображения.
     */
    private function formatLogLine($line)
    {
        // Определяем уровень лога
        $levels = [
            'ERROR' => 'danger',
            'WARNING' => 'warning',
            'NOTICE' => 'info',
            'INFO' => 'secondary',
            'SUCCESS' => 'success',
            'DEBUG' => 'light',
        ];

        foreach ($levels as $level => $class) {
            if (false !== stripos($line, $level)) {
                $line = [
                    'text' => htmlspecialchars($line),
                    'level' => $level,
                    'class' => $class,
                ];
                break;
            }
        }

        if (!is_array($line)) {
            $line = [
                'text' => htmlspecialchars($line),
                'level' => 'INFO',
                'class' => 'secondary',
            ];
        }

        return $line;
    }

    /**
     * Получить информацию о лог-файле.
     */
    private function getLogFileInfo($file)
    {
        $info = [
            'exists' => false,
            'size' => 0,
            'size_formatted' => '0 B',
            'writable' => false,
            'readable' => false,
            'last_modified' => 0,
            'last_modified_formatted' => '',
        ];

        if (file_exists($file)) {
            $info['exists'] = true;
            $info['size'] = filesize($file);
            $info['size_formatted'] = $this->formatBytes($info['size']);
            $info['writable'] = is_writable($file);
            $info['readable'] = is_readable($file);
            $info['last_modified'] = filemtime($file);
            $info['last_modified_formatted'] = date('d.m.Y H:i:s', $info['last_modified']);
        }

        return $info;
    }

    /**
     * Получить лог-файл по типу.
     */
    private function getLogFileByType($type)
    {
        switch ($type) {
            case 'php':
                return $this->phpLogFile;
            case 'scanner':
                return $this->scannerLogFile;
            case 'system':
                return $this->systemLogFile;
            default:
                return null;
        }
    }

    /**
     * Получить название типа лога.
     */
    private function getLogTypeName($type)
    {
        $names = [
            'php' => __('log_type_php'),
            'scanner' => __('log_type_scanner'),
            'system' => __('log_type_system'),
        ];

        return $names[$type] ?? $type;
    }

    /**
     * Проверить наличие ошибок в PHP логе.
     */
    private function hasPhpErrors()
    {
        if (!file_exists($this->phpLogFile) || !is_readable($this->phpLogFile)) {
            return false;
        }

        $content = file_get_contents($this->phpLogFile, false, null, -1, 10240);

        return false !== stripos($content, 'error') || false !== stripos($content, 'warning');
    }

    /**
     * Анализировать лог сканера.
     */
    private function analyzeScannerLog($logLines)
    {
        $stats = [
            'total_entries' => count($logLines),
            'errors' => 0,
            'warnings' => 0,
            'success' => 0,
            'last_scan' => null,
        ];

        foreach ($logLines as $line) {
            $text = is_array($line) ? $line['text'] : $line;

            if (false !== stripos($text, 'error')) {
                ++$stats['errors'];
            }
            if (false !== stripos($text, 'warning')) {
                ++$stats['warnings'];
            }
            if (false !== stripos($text, 'success') || false !== stripos($text, 'completed')) {
                ++$stats['success'];
            }
            if (false !== stripos($text, 'scan started') && !$stats['last_scan']) {
                $stats['last_scan'] = $text;
            }
        }

        return $stats;
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
}
