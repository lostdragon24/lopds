<?php

// lib/ScannerManager.php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/SecurityHelper.php';
require_once __DIR__.'/../init.php';

class ScannerManager
{
    private $db;
    private $security;
    private $scannerPath;
    private $configPath;
    private $lockFile;
    private $logFile;

    public function __construct()
    {
        $this->security = SecurityHelper::getInstance();
        $this->scannerPath = Config::getScannerPath();
        $this->configPath = Config::getScannerConfig();

        $cacheDir = Config::getCacheDir();
        $this->lockFile = $cacheDir.'/scanner.lock';
        $this->logFile = $cacheDir.'/scanner.log';
    }

    private function initDatabase()
    {
        if (null === $this->db) {
            if (!defined('INSTALL_MODE') || INSTALL_MODE !== true) {
                require_once __DIR__.'/Database.php';
                $this->db = Database::getInstance();
            }
        }

        return $this->db;
    }

    public function isAvailable()
    {
        return file_exists($this->scannerPath) && is_executable($this->scannerPath);
    }

    public function getVersion()
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            // Выполняем команду и получаем вывод
            $cmd = escapeshellcmd($this->scannerPath).' --version 2>&1';
            $output = shell_exec($cmd);

            if (empty($output)) {
                return null;
            }

            $patterns = [
                '/v([0-9]+\.[0-9]+\.[0-9]+)/i',
                '/version[:\s]+([0-9]+\.[0-9]+\.[0-9]+)/i',
                '/([0-9]+\.[0-9]+\.[0-9]+)/',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $output, $matches)) {
                    return $matches[1];
                }
            }

            // Если не нашли паттерн, возвращаем первые 50 символов вывода
            return trim(substr($output, 0, 50));
        } catch (Exception $e) {
            error_log('Error getting scanner version: '.$e->getMessage());

            return null;
        }
    }

    public function getScannerInfo()
    {
        $info = [
            'available' => $this->isAvailable(),
            'path' => $this->scannerPath,
            'version' => null,
            'executable' => false,
            'readable' => false,
            'size' => null,
            'size_formatted' => null,
            'modified' => null,
        ];

        if ($info['available']) {
            $info['executable'] = is_executable($this->scannerPath);
            $info['readable'] = is_readable($this->scannerPath);
            $info['version'] = $this->getVersion();

            if (file_exists($this->scannerPath)) {
                $info['size'] = filesize($this->scannerPath);
                $info['size_formatted'] = $this->formatBytes($info['size']);
                $info['modified'] = date('d.m.Y H:i:s', filemtime($this->scannerPath));
            }
        }

        return $info;
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision).' '.$units[$pow];
    }

    public function createDatabase($dbType = null, $dbConfig = null)
    {
        try {
            if (null === $dbConfig) {
                $dbConfig = Config::getDbConfig();
            }

            if (null === $dbType) {
                $dbType = $dbConfig['type'];
            }

            if ('sqlite' === $dbType) {
                return $this->createSqliteDatabase($dbConfig);
            }

            return $this->createMysqlDatabase($dbConfig);
        } catch (Exception $e) {
            error_log('Error creating database: '.$e->getMessage());

            return [
                'success' => false,
                'message' => __('scanner_error_create_db').': '.$e->getMessage(),
            ];
        }
    }

    public function getLogFile()
    {
        return $this->logFile;
    }

    public function isRunning()
    {
        if (file_exists($this->lockFile)) {
            $pid = trim(file_get_contents($this->lockFile));
            if ($pid && function_exists('posix_kill')) {
                // Даем процессу время на запуск
                usleep(100000); // 0.1 секунды
                if (posix_kill($pid, 0)) {
                    return true;
                }
            }
            // Процесс мёртв, удаляем lock-файл
            @unlink($this->lockFile);
        }

        return false;
    }

    public function getPid()
    {
        if (file_exists($this->lockFile)) {
            return trim(file_get_contents($this->lockFile));
        }

        return null;
    }

    public function getStartTime()
    {
        if (file_exists($this->lockFile)) {
            return filemtime($this->lockFile);
        }

        return null;
    }

    public function start($background = true, $mode = 'normal')
    {
        error_log('=== SCANNER START ===');
        error_log("Mode: $mode, Background: ".($background ? 'yes' : 'no'));
        error_log('Scanner path: '.$this->scannerPath);
        error_log('Config path: '.$this->configPath);

        if (!$this->isAvailable()) {
            error_log('Scanner not available at: '.$this->scannerPath);
            throw new Exception(sprintf(__('scanner_error_not_available'), $this->scannerPath));
        }

        if ($this->isRunning()) {
            $pid = $this->getPid();
            error_log('Scanner already running with PID: '.$pid);
            throw new Exception(sprintf(__('scanner_error_already_running'), $pid));
        }

        $this->generateScannerConfig();

        if (!file_exists($this->configPath)) {
            error_log('Failed to create scanner config at: '.$this->configPath);
            throw new Exception(__('scanner_error_config_failed'));
        }

        if (!is_executable($this->scannerPath)) {
            error_log('Scanner binary is not executable: '.$this->scannerPath);
            throw new Exception(__('scanner_error_not_executable'));
        }

        $cmd = escapeshellcmd($this->scannerPath).' '.escapeshellarg($this->configPath);

        switch ($mode) {
            case 'quick':
                $cmd .= ' --quick';
                break;
            case 'inpx':
                $inpxFile = $this->findInpxFile(Config::getBooksDir());
                if ($inpxFile) {
                    $cmd .= ' --inpx='.escapeshellarg($inpxFile);
                    error_log('Using INPX file: '.$inpxFile);
                }
                break;
            case 'force':
                $cmd .= ' --force';
                break;
        }

        error_log('Command: '.$cmd);

        if ($background) {
            if (0 == strncasecmp(PHP_OS, 'WIN', 3)) {
                $cmd = 'start /B '.$cmd.' > NUL 2>&1';
                pclose(popen($cmd, 'r'));
                $pid = null;
                error_log('Started in background on Windows');
            } else {
                $cmd = 'nohup '.$cmd.' >> '.escapeshellarg($this->logFile).' 2>&1 & echo $!';
                error_log('Executing: '.$cmd);

                $output = shell_exec($cmd);
                error_log('Shell exec output: '.($output ?: 'empty'));

                if ($output) {
                    $pid = trim($output);

                    error_log('=== SCANNER DEBUG ===');
                    error_log('Lock file path: '.$this->lockFile);
                    error_log('Lock dir writable: '.(is_writable(dirname($this->lockFile)) ? 'yes' : 'no'));
                    error_log('Command: '.$cmd);
                    error_log('Shell exec output: '.($output ?? 'null'));

                    if ($output) {
                        $pid = trim($output);
                        error_log('Got PID: '.$pid);

                        if (file_put_contents($this->lockFile, $pid)) {
                            error_log('Lock file created successfully');
                        } else {
                            error_log('FAILED to create lock file: '.$this->lockFile);
                            error_log('Error: '.error_get_last()['message'] ?? 'unknown');
                        }
                    }

                    file_put_contents($this->lockFile, $pid);
                    error_log('Process started with PID: '.$pid);

                    $this->log(sprintf(__('scanner_log_started'), $mode, $pid));
                } else {
                    error_log('Failed to get PID from shell_exec');
                }
            }

            return [
                'success' => true,
                'message' => sprintf(__('scanner_started'), $mode),
                'pid' => $pid ?? null,
                'mode' => $mode,
            ];
        }
        $output = [];
        $returnCode = 0;
        exec($cmd.' 2>&1', $output, $returnCode);

        error_log('Return code: '.$returnCode);
        error_log('Output: '.implode("\n", $output));

        $this->log(sprintf(__('scanner_log_completed'), $mode, $returnCode));

        return [
            'success' => 0 === $returnCode,
            'message' => implode("\n", $output),
            'return_code' => $returnCode,
            'mode' => $mode,
        ];

        // Сбрасываем синглтон DatabaseChecker
        $checker = DatabaseChecker::getInstance();
        $reflection = new ReflectionClass($checker);
        $dbAvailable = $reflection->getProperty('dbAvailable');
        $dbAvailable->setAccessible(true);
        $dbAvailable->setValue($checker, null);

        $tablesExist = $reflection->getProperty('tablesExist');
        $tablesExist->setAccessible(true);
        $tablesExist->setValue($checker, null);

        $cachedStatus = $reflection->getProperty('cachedStatus');
        $cachedStatus->setAccessible(true);
        $cachedStatus->setValue($checker, null);
    }

    public function stop()
    {
        if (!$this->isRunning()) {
            return ['success' => false, 'message' => __('scanner_error_not_running')];
        }

        $pid = trim(file_get_contents($this->lockFile));

        if (0 == strncasecmp(PHP_OS, 'WIN', 3)) {
            exec('taskkill /F /PID '.$pid);
        } else {
            if (function_exists('posix_kill')) {
                posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
                sleep(2);
                if (posix_kill($pid, 0)) {
                    posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);
                }
            } else {
                exec('kill '.$pid.' 2>/dev/null');
                sleep(2);
                exec('kill -9 '.$pid.' 2>/dev/null');
            }
        }

        $this->log(sprintf(__('scanner_log_stopped'), $pid));
        @unlink($this->lockFile);

        return [
            'success' => true,
            'message' => __('scanner_stopped'),
        ];
    }

    public function getStatus()
    {
        $status = [
            'available' => $this->isAvailable(),
            'running' => $this->isRunning(),
            'scanner_path' => $this->scannerPath,
            'config_path' => $this->configPath,
            'log_file' => $this->logFile,
            'last_log' => $this->getLastLogLines(50),
        ];

        if ($this->isRunning()) {
            $pid = $this->getPid();
            if ($pid) {
                $status['pid'] = $pid;
            }
            $startTime = $this->getStartTime();
            if ($startTime) {
                $status['started_at'] = date('Y-m-d H:i:s', $startTime);
                $status['running_for'] = $this->formatTimeDiff($startTime);
            }
        }

        return $status;
    }

    public function getStats()
    {
        try {
            $db = $this->initDatabase();
            if (!$db || !$db->isAvailable()) {
                return [
                    'total_books' => 0,
                    'archives_count' => 0,
                    'last_scan' => null,
                    'scans_count' => 0,
                ];
            }

            // Получаем общее количество книг
            $stmt = $db->getConnection()->query('SELECT COUNT(*) FROM books');
            $totalBooks = $stmt->fetchColumn();

            // Получаем количество архивов
            $stmt = $db->getConnection()->query('SELECT COUNT(*) FROM archives');
            $archivesCount = $stmt->fetchColumn();

            // Получаем время последнего сканирования
            $stmt = $db->getConnection()->query('SELECT MAX(last_scanned) FROM archives');
            $lastScan = $stmt->fetchColumn();

            return [
                'total_books' => (int) $totalBooks,
                'archives_count' => (int) $archivesCount,
                'last_scan' => $lastScan ?: null,
                'scans_count' => (int) $archivesCount,
            ];
        } catch (Exception $e) {
            error_log('Error getting scanner stats: '.$e->getMessage());

            return [
                'total_books' => 0,
                'archives_count' => 0,
                'last_scan' => null,
                'scans_count' => 0,
            ];
        }
    }

    /**
     * Получить общее количество архивов.
     */
    private function getTotalScansCount()
    {
        try {
            $db = $this->initDatabase();
            $stmt = $db->getConnection()->query('SELECT COUNT(*) as count FROM archives');
            $result = $stmt->fetch();

            return $result['count'] ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getArchivesCount()
    {
        try {
            $db = $this->initDatabase();
            $stmt = $db->getConnection()->query('SELECT COUNT(*) as count FROM archives');
            $result = $stmt->fetch();

            return $result['count'] ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getLastScanTime()
    {
        try {
            $db = $this->initDatabase();
            $stmt = $db->getConnection()->query(
                'SELECT MAX(last_scanned) as last FROM archives'
            );
            $result = $stmt->fetch();

            return $result['last'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function formatTimeDiff($timestamp)
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return $diff.' '.__('unit_seconds');
        } elseif ($diff < 3600) {
            return floor($diff / 60).' '.__('unit_minutes').' '.($diff % 60).' '.__('unit_seconds');
        } elseif ($diff < 86400) {
            return floor($diff / 3600).' '.__('unit_hours').' '.floor(($diff % 3600) / 60).' '.__('unit_minutes');
        }

        return floor($diff / 86400).' '.__('unit_days').' '.floor(($diff % 86400) / 3600).' '.__('unit_hours');
    }

    private function log($message)
    {
        $logEntry = sprintf(
            "[%s] %s\n",
            date('Y-m-d H:i:s'),
            $message
        );
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public function getLastLogLines($lines = 100)
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $log = [];
        try {
            $file = new SplFileObject($this->logFile, 'r');
            $file->seek(PHP_INT_MAX);
            $totalLines = $file->key();

            $start = max(0, $totalLines - $lines);

            for ($i = $start; $i < $totalLines; ++$i) {
                $file->seek($i);
                $line = $file->current();
                if (false !== $line) {
                    $log[] = $line;
                }
            }
        } catch (Exception $e) {
            $allLines = file($this->logFile);
            if ($allLines) {
                $log = array_slice($allLines, -$lines);
            }
        }

        return $log;
    }

    public function clearLog()
    {
        if (file_exists($this->logFile)) {
            if (!unlink($this->logFile)) {
                throw new Exception(__('scanner_error_clear_log'));
            }
        }

        $header = '['.date('Y-m-d H:i:s').'] '.__('scanner_log_created')."\n";
        file_put_contents($this->logFile, $header);
        chmod($this->logFile, 0644);

        return true;
    }

    public function findInpxFile($dir)
    {
        if (!is_dir($dir)) {
            return null;
        }

        $files = scandir($dir);
        foreach ($files as $file) {
            if ('.' == $file || '..' == $file) {
                continue;
            }

            $path = $dir.'/'.$file;

            if (is_dir($path)) {
                $found = $this->findInpxFile($path);
                if ($found) {
                    return $found;
                }
            } elseif (is_file($path) && 'inpx' == strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                return $path;
            }
        }

        return null;
    }

    public function hasInpxFile()
    {
        return null !== $this->findInpxFile(Config::getBooksDir());
    }

    private function generateScannerConfig()
    {
        $dbConfig = Config::getDbConfig();

        $content = '; Scanner config generated at '.date('Y-m-d H:i:s')."\n\n";

        $content .= "[database]\n";

        switch ($dbConfig['type']) {
            case 'sqlite':
                $content .= "type = sqlite\n";
                $content .= 'path = '.$dbConfig['path']."\n";
                break;

            case 'mysql':
                $content .= "type = mysql\n";
                $content .= 'host = '.$dbConfig['host']."\n";
                $content .= 'user = '.$dbConfig['user']."\n";
                $content .= 'password = '.$dbConfig['pass']."\n";
                $content .= 'database = '.$dbConfig['name']."\n";
                if (!empty($dbConfig['port'])) {
                    $content .= 'port = '.$dbConfig['port']."\n";
                }
                break;
        }

        $content .= "\n[scanner]\n";

        $booksDir = Config::getBooksDir();
        $booksDir = trim($booksDir, '"\'');
        $content .= 'books_dir = '.$booksDir."\n";

        $logFile = $this->logFile;
        $logFile = trim($logFile, '"\'');
        $content .= 'log_file = '.$logFile."\n";

        $content .= "rescan_unchanged = no\n";
        $content .= "hash_algorithm = md5\n";
        $content .= "log_level = info\n";
        $content .= "extract_covers = yes\n";
        $content .= "extract_descriptions = yes\n";

        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($this->configPath, $content);
        chmod($this->configPath, 0600);

        error_log('Generated scanner config with books_dir: '.$booksDir);
    }

    private function createSqliteDatabase($dbConfig)
    {
        $dbFile = $dbConfig['path'];
        $dbDir = dirname($dbFile);

        if (!file_exists($dbDir)) {
            if (!mkdir($dbDir, 0755, true)) {
                throw new Exception(sprintf(__('scanner_error_create_dir'), $dbDir));
            }
        }

        $pdo = new PDO("sqlite:$dbFile");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = '
            CREATE TABLE IF NOT EXISTS books (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                author TEXT,
                series TEXT,
                series_number TEXT,
                genre TEXT,
                year INTEGER,
                language TEXT,
                publisher TEXT,
                file_path TEXT UNIQUE,
                file_type TEXT,
                archive_path TEXT,
                archive_internal_path TEXT,
                file_hash TEXT,
                added_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                description TEXT
            );
            
            CREATE TABLE IF NOT EXISTS book_ratings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                book_id INTEGER NOT NULL,
                user_ip TEXT NOT NULL,
                rating INTEGER NOT NULL CHECK (rating >= 1 AND rating <= 5),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_ip, book_id),
                FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
            );
            
            CREATE TABLE IF NOT EXISTS book_favorites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                book_id INTEGER NOT NULL,
                user_ip TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_ip, book_id),
                FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
            );
            
            CREATE TABLE IF NOT EXISTS archives (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                archive_path TEXT UNIQUE,
                last_scanned DATETIME,
                file_count INTEGER DEFAULT 0
            );
            
            CREATE INDEX IF NOT EXISTS idx_books_author ON books(author);
            CREATE INDEX IF NOT EXISTS idx_books_title ON books(title);
            CREATE INDEX IF NOT EXISTS idx_books_genre ON books(genre);
            CREATE INDEX IF NOT EXISTS idx_books_series ON books(series);
            CREATE INDEX IF NOT EXISTS idx_books_added_date ON books(added_date);
        ';

        $pdo->exec($sql);

        return [
            'success' => true,
            'message' => __('scanner_db_created'),
        ];
    }

    public function checkDatabaseExists()
    {
        try {
            $dbConfig = Config::getDbConfig();

            if ('sqlite' === $dbConfig['type']) {
                return file_exists($dbConfig['path']);
            }
            try {
                $pdo = new PDO(
                    "mysql:host={$dbConfig['host']}",
                    $dbConfig['user'],
                    $dbConfig['pass']
                );
                $stmt = $pdo->query('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA 
                                         WHERE SCHEMA_NAME = '.$pdo->quote($dbConfig['name']));

                return false !== $stmt->fetch();
            } catch (Exception $e) {
                return false;
            }
        } catch (Exception $e) {
            error_log('Error checking database exists: '.$e->getMessage());

            return false;
        }
    }

    public function checkTablesExist()
    {
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__.'/Database.php';
            }

            $db = Database::getInstance();
            if (!$db->isAvailable()) {
                return false;
            }

            $pdo = $db->getConnection();
            $dbConfig = Config::getDbConfig();

            if ('sqlite' === $dbConfig['type']) {
                $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books'");

                return false !== $stmt->fetch();
            }
            $stmt = $pdo->query("SHOW TABLES LIKE 'books'");

            return false !== $stmt->fetch();
        } catch (Exception $e) {
            error_log('Error checking tables exist: '.$e->getMessage());

            return false;
        }
    }
}
