<?php

// lib/ScannerManager.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/SecurityHelper.php';
require_once __DIR__ . '/PageCache.php';
require_once __DIR__ . '/../init.php';

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
        $this->lockFile = $cacheDir . '/scanner.lock';
        $this->logFile = $cacheDir . '/scanner.log';
    }

    private function initDatabase()
    {
        if ($this->db === null) {
            if (!defined('INSTALL_MODE') || INSTALL_MODE !== true) {
                require_once __DIR__ . '/Database.php';
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
            $cmd = escapeshellcmd($this->scannerPath) . ' --version 2>&1';
            $output = shell_exec($cmd);

            if (empty($output)) {
                return null;
            }

            $patterns = [
                '/v([0-9]+\.[0-9]+\.[0-9]+)/i',
                '/version[:\s]+([0-9]+\.[0-9]+\.[0-9]+)/i',
                '/([0-9]+\.[0-9]+\.[0-9]+)/'
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $output, $matches)) {
                    return $matches[1];
                }
            }

            // Если не нашли паттерн, возвращаем первые 50 символов вывода
            return trim(substr($output, 0, 50));

        } catch (Exception $e) {
            my_log("Error getting scanner version: " . $e->getMessage());
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
            'modified' => null
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

        return round($bytes, $precision) . ' ' . $units[$pow];
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

        PageCache::clear();
        my_log("=== SCANNER START ===");
        my_log("=== SCANNER START ===");
        my_log("Mode: $mode, Background: " . ($background ? 'yes' : 'no'));
        my_log("Scanner path: " . $this->scannerPath);
        my_log("Config path: " . $this->configPath);

        if (!$this->isAvailable()) {
            my_log("Scanner not available at: " . $this->scannerPath);
            throw new Exception(sprintf(__('scanner_error_not_available'), $this->scannerPath));
        }

        if ($this->isRunning()) {
            $pid = $this->getPid();
            my_log("Scanner already running with PID: " . $pid);
            throw new Exception(sprintf(__('scanner_error_already_running'), $pid));
        }

        $forInpx = ($mode === 'inpx');

        error_log("Проверка состояния переменной forInpx: " . $forInpx);
        //    if ($forInpx || !file_exists($this->configPath)) {
        //        error_log("start: - запись файла конфигурации: " . $forInpx);
        //        $this->generateScannerConfig($forInpx);
        //    }

        // $this->generateScannerConfig();
        $this->generateScannerConfig($forInpx);

        if (!file_exists($this->configPath)) {
            my_log("Failed to create scanner config at: " . $this->configPath);
            throw new Exception(__('scanner_error_config_failed'));
        }

        if (!is_executable($this->scannerPath)) {
            my_log("Scanner binary is not executable: " . $this->scannerPath);
            throw new Exception(__('scanner_error_not_executable'));
        }

        $cmd = escapeshellcmd($this->scannerPath) . ' ' . escapeshellarg($this->configPath);

        switch ($mode) {
            case 'quick':
                $cmd .= ' --quick';
                break;
            case 'inpx':
                $inpxFile = $this->findInpxFile(Config::getBooksDir());
                if ($inpxFile) {
                    $cmd .= ' --inpx=' . escapeshellarg($inpxFile);
                    my_log("Using INPX file: " . $inpxFile);
                }
                break;
            case 'force':
                $cmd .= ' --force';
                break;
        }

        my_log("Command: " . $cmd);

        if ($background) {
            if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
                $cmd = 'start /B ' . $cmd . ' > NUL 2>&1';
                pclose(popen($cmd, 'r'));
                $pid = null;
                my_log("Started in background on Windows");
            } else {
                $cmd = 'nohup ' . $cmd . ' >> ' . escapeshellarg($this->logFile) . ' 2>&1 & echo $!';
                my_log("Executing: " . $cmd);

                $output = shell_exec($cmd);
                my_log("Shell exec output: " . ($output ?: 'empty'));

                if ($output) {
                    $pid = trim($output);


                    my_log("=== SCANNER DEBUG ===");
                    my_log("Lock file path: " . $this->lockFile);
                    my_log("Lock dir writable: " . (is_writable(dirname($this->lockFile)) ? 'yes' : 'no'));
                    my_log("Command: " . $cmd);
                    my_log("Shell exec output: " . ($output ?? 'null'));

                    if ($output) {
                        $pid = trim($output);
                        my_log("Got PID: " . $pid);

                        if (file_put_contents($this->lockFile, $pid)) {
                            my_log("Lock file created successfully");
                        } else {
                            my_log("FAILED to create lock file: " . $this->lockFile);
                            my_log("Error: " . error_get_last()['message'] ?? 'unknown');
                        }
                    }

                    file_put_contents($this->lockFile, $pid);
                    my_log("Process started with PID: " . $pid);

                    $this->log(sprintf(__('scanner_log_started'), $mode, $pid));
                } else {
                    my_log("Failed to get PID from shell_exec");
                }
            }

            return [
                'success' => true,
                'message' => sprintf(__('scanner_started'), $mode),
                'pid' => $pid ?? null,
                'mode' => $mode
            ];
        } else {
            $output = [];
            $returnCode = 0;
            exec($cmd . ' 2>&1', $output, $returnCode);

            my_log("Return code: " . $returnCode);
            my_log("Output: " . implode("\n", $output));

            $this->log(sprintf(__('scanner_log_completed'), $mode, $returnCode));

            return [
                'success' => $returnCode === 0,
                'message' => implode("\n", $output),
                'return_code' => $returnCode,
                'mode' => $mode
            ];
        }

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

        if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
            exec('taskkill /F /PID ' . $pid);
        } else {
            if (function_exists('posix_kill')) {
                posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
                sleep(2);
                if (posix_kill($pid, 0)) {
                    posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);
                }
            } else {
                exec('kill ' . $pid . ' 2>/dev/null');
                sleep(2);
                exec('kill -9 ' . $pid . ' 2>/dev/null');
            }
        }

        $this->log(sprintf(__('scanner_log_stopped'), $pid));
        @unlink($this->lockFile);

        return [
            'success' => true,
            'message' => __('scanner_stopped')
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
            'last_log' => $this->getLastLogLines(50)
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
                    'scans_count' => 0
                ];
            }

            // Получаем общее количество книг
            $stmt = $db->getConnection()->query("SELECT COUNT(*) FROM books");
            $totalBooks = $stmt->fetchColumn();

            // Получаем количество архивов
            $stmt = $db->getConnection()->query("SELECT COUNT(*) FROM archives");
            $archivesCount = $stmt->fetchColumn();

            // Получаем время последнего сканирования
            $stmt = $db->getConnection()->query("SELECT MAX(last_scanned) FROM archives");
            $lastScan = $stmt->fetchColumn();

            return [
                'total_books' => (int)$totalBooks,
                'archives_count' => (int)$archivesCount,
                'last_scan' => $lastScan ?: null,
                'scans_count' => (int)$archivesCount
            ];

        } catch (Exception $e) {
            my_log("Error getting scanner stats: " . $e->getMessage());
            return [
                'total_books' => 0,
                'archives_count' => 0,
                'last_scan' => null,
                'scans_count' => 0
            ];
        }
    }


    /**
     * Получить общее количество архивов
     */
    private function getTotalScansCount()
    {
        try {
            $db = $this->initDatabase();
            $stmt = $db->getConnection()->query("SELECT COUNT(*) as count FROM archives");
            $result = $stmt->fetch();
            return $result['count'] ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }


    private function formatTimeDiff($timestamp)
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return $diff . ' ' . __('unit_seconds');
        } elseif ($diff < 3600) {
            return floor($diff / 60) . ' ' . __('unit_minutes') . ' ' . ($diff % 60) . ' ' . __('unit_seconds');
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . ' ' . __('unit_hours') . ' ' . floor(($diff % 3600) / 60) . ' ' . __('unit_minutes');
        } else {
            return floor($diff / 86400) . ' ' . __('unit_days') . ' ' . floor(($diff % 86400) / 3600) . ' ' . __('unit_hours');
        }
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

            for ($i = $start; $i < $totalLines; $i++) {
                $file->seek($i);
                $line = $file->current();
                if ($line !== false) {
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

        $header = "[" . date('Y-m-d H:i:s') . "] " . __('scanner_log_created') . "\n";
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
            if ($file == '.' || $file == '..') {
                continue;
            }

            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $found = $this->findInpxFile($path);
                if ($found) {
                    return $found;
                }
            } elseif (is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) == 'inpx') {
                return $path;
            }
        }

        return null;
    }

    public function hasInpxFile()
    {
        return $this->findInpxFile(Config::getBooksDir()) !== null;
    }

    private function generateScannerConfig($forInpx = false)
    {
        $dbConfig = Config::getDbConfig();

        $content = "; Scanner config generated at " . date('Y-m-d H:i:s') . "\n\n";

        $content .= "[database]\n";

        switch ($dbConfig['type']) {
            case 'sqlite':
                $content .= "type = sqlite\n";
                $content .= "path = " . $dbConfig['path'] . "\n";
                break;

            case 'mysql':
                $content .= "type = mysql\n";
                $content .= "host = " . $dbConfig['host'] . "\n";
                $content .= "user = " . $dbConfig['user'] . "\n";
                $content .= "password = " . $dbConfig['pass'] . "\n";
                $content .= "database = " . $dbConfig['name'] . "\n";
                if (!empty($dbConfig['port'])) {
                    $content .= "port = " . $dbConfig['port'] . "\n";
                }
                break;
        }

        $content .= "\n[scanner]\n";

        $booksDir = Config::getBooksDir();
        $booksDir = trim($booksDir, '"\'');
        $content .= "books_dir = " . $booksDir . "\n";

        $logFile = $this->logFile;
        $logFile = trim($logFile, '"\'');
        $content .= "log_file = " . $logFile . "\n";

        $content .= "rescan_unchanged = no\n";
        error_log("Проверям в генераторе конфига состяние INPX: " . $forInpx);
        $content .= "enable_inpx =  " . ($forInpx ? "yes" : "no") . "\n";
        $content .= "clear_database_inpx =  no\n";
        $content .= "hash_algorithm = md5\n";
        $content .= "log_level = info\n";
        $content .= "extract_covers = yes\n";
        $content .= "extract_descriptions = yes\n";
        $content .= "batch_size =  100\n";
        $content .= "num_workers = 1\n";
        $content .= "find_dup =  yes\n";

        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($this->configPath, $content);
        chmod($this->configPath, 0600);

        my_log("Generated scanner config with books_dir: " . $booksDir);
    }



    public function createDatabase($dbType = null, $dbConfig = null)
    {
        try {
            if ($dbConfig === null) {
                $dbConfig = Config::getDbConfig();
            }

            if ($dbType === null) {
                $dbType = $dbConfig['type'];
            }

            if ($dbType === 'sqlite') {
                return $this->createSqliteDatabase($dbConfig);
            } else {
                return $this->createMysqlDatabase($dbConfig);
            }
        } catch (Exception $e) {
            my_log("Error creating database: " . $e->getMessage());
            return [
                'success' => false,
                'message' => __('scanner_error_create_db') . ': ' . $e->getMessage()
            ];
        }
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

        $sql = "
 CREATE TABLE IF NOT EXISTS books (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_path TEXT NOT NULL,
            file_name TEXT NOT NULL,
            file_size INTEGER,
            file_type TEXT,
            archive_path TEXT,
            archive_internal_path TEXT,
            file_hash TEXT UNIQUE,
            title TEXT NOT NULL,
            author TEXT NOT NULL,
            genre TEXT,
            series TEXT,
            series_number INTEGER,
            year INTEGER,
            language TEXT,
            publisher TEXT,
            description TEXT,
            added_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_modified DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_scanned DATETIME DEFAULT CURRENT_TIMESTAMP,
            file_mtime INTEGER,
            UNIQUE(file_path, archive_path, archive_internal_path)
        );
            
        CREATE TABLE IF NOT EXISTS book_ratings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book_id INTEGER NOT NULL,
            user_ip VARCHAR(45) NOT NULL,
            rating INTEGER NOT NULL CHECK (rating >= 1 AND rating <= 5),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_ip, book_id),
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
        );
            
        CREATE TABLE IF NOT EXISTS book_favorites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book_id INTEGER NOT NULL,
            user_ip VARCHAR(45) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_ip, book_id),
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
        );
            
        CREATE TABLE IF NOT EXISTS archives (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            archive_path TEXT UNIQUE NOT NULL,
            archive_hash TEXT,
            file_count INTEGER DEFAULT 0,
            total_size INTEGER DEFAULT 0,
            last_modified INTEGER,
            last_scanned DATETIME DEFAULT CURRENT_TIMESTAMP,
            needs_rescan BOOLEAN DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS bookmarks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_fingerprint VARCHAR(64) NOT NULL,
            book_id INTEGER NOT NULL,
            cfi_range VARCHAR(255) NOT NULL,
            page_number INTEGER DEFAULT 0,
            percentage DECIMAL(5,2) DEFAULT 0,
            note TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_read TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_deleted BOOLEAN DEFAULT 0, type TEXT DEFAULT 'bookmark', color
        TEXT DEFAULT 'yellow', selected_text TEXT, context_before TEXT,
        context_after TEXT, tags TEXT, is_public BOOLEAN DEFAULT 0,
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
        );

        CREATE VIRTUAL  TABLE IF NOT EXISTS bookmarks_fts USING fts5(
            note,
            selected_text,
            context_before,
            context_after,
            tags,
            content='bookmarks',
            content_rowid='id',
            tokenize='unicode61'
        );

            CREATE INDEX IF NOT EXISTS idx_books_file_hash ON books(file_hash);
            CREATE INDEX IF NOT EXISTS idx_books_file_path ON books(file_path);
            CREATE INDEX IF NOT EXISTS idx_books_archive_path ON books(archive_path);
            CREATE INDEX IF NOT EXISTS idx_books_title ON books(title);
            CREATE INDEX IF NOT EXISTS idx_books_author ON books(author);
            CREATE INDEX IF NOT EXISTS idx_books_genre ON books(genre);
            CREATE INDEX IF NOT EXISTS idx_books_series ON books(series);
            CREATE INDEX IF NOT EXISTS idx_books_year ON books(year);
            CREATE INDEX IF NOT EXISTS idx_books_language ON books(language);
            CREATE INDEX IF NOT EXISTS idx_books_publisher ON books(publisher);

            CREATE INDEX IF NOT EXISTS idx_bookmarks_user_book ON bookmarks(user_fingerprint, book_id);
            CREATE INDEX IF NOT EXISTS idx_bookmarks_last_read ON bookmarks(last_read DESC);
            CREATE INDEX IF NOT EXISTS idx_bookmarks_book ON bookmarks(book_id);
            CREATE INDEX IF NOT EXISTS idx_bookmarks_type ON bookmarks(type);
            CREATE INDEX IF NOT EXISTS idx_bookmarks_color ON bookmarks(color);
            CREATE INDEX IF NOT EXISTS idx_bookmarks_public ON bookmarks(is_public);

    CREATE TRIGGER IF NOT EXISTS bookmarks_ai AFTER INSERT ON bookmarks BEGIN
        INSERT INTO bookmarks_fts(rowid, note, selected_text, context_before, context_after, tags)
        VALUES (new.id, new.note, new.selected_text, new.context_before, new.context_after, new.tags);
    END;

    CREATE TRIGGER IF NOT EXISTS bookmarks_ad AFTER DELETE ON bookmarks BEGIN
        INSERT INTO bookmarks_fts(bookmarks_fts, rowid, note, selected_text, context_before, context_after, tags)
        VALUES ('delete', old.id, old.note, old.selected_text, old.context_before, old.context_after, old.tags);
    END;

    CREATE TRIGGER IF NOT EXISTS bookmarks_au AFTER UPDATE ON bookmarks BEGIN
        INSERT INTO bookmarks_fts(bookmarks_fts, rowid, note, selected_text, context_before, context_after, tags)
        VALUES ('delete', old.id, old.note, old.selected_text, old.context_before, old.context_after, old.tags);

        INSERT INTO bookmarks_fts(rowid, note, selected_text, context_before, context_after, tags)
        VALUES (new.id, new.note, new.selected_text, new.context_before, new.context_after, new.tags);
    END;

CREATE VIRTUAL TABLE IF NOT EXISTS books_fts USING fts5(
    title,
    author,
    genre,
    series,
    publisher,
    description,
    content='books',
    content_rowid='id'
);

CREATE TRIGGER IF NOT EXISTS books_ai AFTER INSERT ON books BEGIN
    INSERT INTO books_fts(rowid, title, author, genre, series, publisher, description)
    VALUES (new.id, new.title, new.author, new.genre, new.series, new.publisher, new.description);
END;

CREATE TRIGGER IF NOT EXISTS books_ad AFTER DELETE ON books BEGIN
    INSERT INTO books_fts(books_fts, rowid, title, author, genre, series, publisher, description)
    VALUES ('delete', old.id, old.title, old.author, old.genre, old.series, old.publisher, old.description);
END;

CREATE TRIGGER IF NOT EXISTS books_au AFTER UPDATE ON books BEGIN
    INSERT INTO books_fts(books_fts, rowid, title, author, genre, series, publisher, description)
    VALUES ('delete', old.id, old.title, old.author, old.genre, old.series, old.publisher, old.description);

    INSERT INTO books_fts(rowid, title, author, genre, series, publisher, description)
    VALUES (new.id, new.title, new.author, new.genre, new.series, new.publisher, new.description);
END;

    ";

        $pdo->exec($sql);

        return [
            'success' => true,
            'message' => __('scanner_db_created')
        ];
    }

    public function createMysqlDatabase($dbConfig)
    {
        // 1. Жесткая валидация имени базы данных (только буквы, цифры и подчеркивания)
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbConfig['database']);
        if (empty($dbName)) {
            throw new Exception('Invalid database name provided in config');
        }

        $dsn = "mysql:host={$dbConfig['host']}" .
               (isset($dbConfig['port']) ? ";port={$dbConfig['port']}" : "") .
               ";charset=utf8mb4";

        $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);

        // 2. Создаем базу данных и переключаемся на неё
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");

        // 3. Разбиваем SQL на массив отдельных запросов (PDO не поддерживает multi-statements в exec)
        $queries = [
            "CREATE TABLE IF NOT EXISTS books (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_path VARCHAR(500),
            file_name VARCHAR(255),
            file_size BIGINT,
            file_type VARCHAR(20),
            archive_path VARCHAR(500),
            archive_internal_path VARCHAR(500),
            file_hash VARCHAR(64),
            title VARCHAR(255),
            author VARCHAR(255),
            genre VARCHAR(100),
            series VARCHAR(255),
            series_number INT,
            year INT,
            language VARCHAR(50),
            publisher VARCHAR(255),
            description TEXT,
            added_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_modified DATETIME,
            last_scanned DATETIME,
            file_mtime INT,
            UNIQUE KEY unique_file_hash (file_hash),
            UNIQUE KEY unique_book (file_path(191), archive_path(191), archive_internal_path(191)),
            UNIQUE KEY unique_title_author (title(191), author(191)),
            INDEX idx_author (author(100)),
            INDEX idx_title (title(100)),
            INDEX idx_genre (genre(50)),
            INDEX idx_series (series(100)),
            INDEX idx_added_date (added_date),
            INDEX idx_file_type (file_type),
            INDEX idx_year (year),
            FULLTEXT INDEX ft_books_search (title, author, genre, series, publisher, description)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS archives (
            id INT AUTO_INCREMENT PRIMARY KEY,
            archive_path TEXT,
            archive_hash VARCHAR(64),
            file_count INT,
            total_size BIGINT,
            last_modified BIGINT,
            last_scanned TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            needs_rescan BOOLEAN DEFAULT TRUE,
            UNIQUE KEY unique_archive (archive_path(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS book_ratings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            book_id INT NOT NULL,
            user_ip VARCHAR(45) NOT NULL,
            rating TINYINT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT chk_rating_range CHECK (rating >= 1 AND rating <= 5),
            CONSTRAINT unique_user_book UNIQUE (user_ip, book_id),
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
            INDEX idx_ratings_book (book_id),
            INDEX idx_ratings_user (user_ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS book_favorites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            book_id INT NOT NULL,
            user_ip VARCHAR(45) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT unique_user_favorite UNIQUE (user_ip, book_id),
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
            INDEX idx_favorites_book (book_id),
            INDEX idx_favorites_user (user_ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS bookmarks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_fingerprint VARCHAR(64) NOT NULL,
            book_id INT NOT NULL,
            cfi_range VARCHAR(255) NOT NULL,
            page_number INT DEFAULT 0,
            percentage DECIMAL(5,2) DEFAULT 0.00,
            note TEXT,
            type VARCHAR(20) DEFAULT 'bookmark',
            color VARCHAR(20) DEFAULT 'yellow',
            selected_text TEXT,
            context_before TEXT,
            context_after TEXT,
            tags TEXT,
            is_public TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_read TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_deleted TINYINT(1) DEFAULT 0,
            INDEX idx_bookmarks_user_book (user_fingerprint, book_id),
            INDEX idx_bookmarks_last_read (last_read),
            INDEX idx_bookmarks_book (book_id),
            INDEX idx_bookmarks_type (type),
            INDEX idx_bookmarks_color (color),
            INDEX idx_bookmarks_public (is_public),
            FULLTEXT INDEX ft_bookmarks_search (note, selected_text, context_before, context_after, tags),
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS bookmark_tags (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_fingerprint VARCHAR(64) NOT NULL,
            name VARCHAR(50) NOT NULL,
            color VARCHAR(20) DEFAULT 'default',
            usage_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_tag (user_fingerprint, name),
            INDEX idx_tags_user (user_fingerprint),
            INDEX idx_tags_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];

        // 4. Выполняем запросы по одному
        foreach ($queries as $query) {
            $pdo->exec($query);
        }

        return [
            'success' => true,
            'message' => 'MySQL database created successfully'
        ];
    }




    public function checkDatabaseExists()
    {
        try {
            $dbConfig = Config::getDbConfig();

            if ($dbConfig['type'] === 'sqlite') {
                return file_exists($dbConfig['path']);
            } else {
                try {
                    $pdo = new PDO(
                        "mysql:host={$dbConfig['host']}",
                        $dbConfig['user'],
                        $dbConfig['pass']
                    );
                    $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA 
                                         WHERE SCHEMA_NAME = " . $pdo->quote($dbConfig['name']));
                    return $stmt->fetch() !== false;
                } catch (Exception $e) {
                    return false;
                }
            }
        } catch (Exception $e) {
            my_log("Error checking database exists: " . $e->getMessage());
            return false;
        }
    }

    public function checkTablesExist()
    {
        try {
            if (!class_exists('Database', false)) {
                require_once __DIR__ . '/Database.php';
            }

            $db = Database::getInstance();
            if (!$db->isAvailable()) {
                return false;
            }

            $pdo = $db->getConnection();
            $dbConfig = Config::getDbConfig();

            if ($dbConfig['type'] === 'sqlite') {
                $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books'");
                return $stmt->fetch() !== false;
            } else {
                $stmt = $pdo->query("SHOW TABLES LIKE 'books'");
                return $stmt->fetch() !== false;
            }
        } catch (Exception $e) {
            my_log("Error checking tables exist: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Импорт метаданных из INPX-файла (быстрый импорт).
     * @return array Результат операции
     * @throws Exception
     */
    public function importInpx()
    {
        error_log("importInpx: - начало функции");

        if (!$this->isAvailable()) {
            throw new Exception(sprintf(__('scanner_error_not_available'), $this->scannerPath));
        }
        if ($this->isRunning()) {
            $pid = $this->getPid();
            throw new Exception(sprintf(__('scanner_error_already_running'), $pid));
        }

        // Явно генерируем конфиг с enable_inpx = yes
        $this->generateScannerConfig(true);

        // Запускаем в фоне с режимом inpx
        return $this->start(true, 'inpx');
    }

}
