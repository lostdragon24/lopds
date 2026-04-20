<?php

// install/includes/database.php

/**
 * Обработчик тестирования подключения к БД.
 */
function handleTestConnection($post)
{
    $result = testDatabaseConnection($post);
    if ($result['success']) {
        $_SESSION['db_config'] = [
            'type' => $post['type'],
            'host' => $post['host'] ?? '',
            'port' => $post['port'] ?? '',
            'database' => $post['database'] ?? '',
            'user' => $post['user'] ?? '',
            'password' => $post['password'] ?? '',
            'path' => $post['path'] ?? '',
        ];

        if (isset($result['diagnostics'])) {
            $_SESSION['db_diagnostics'] = $result['diagnostics'];
        }

        error_log('DB config saved to session: '.print_r($_SESSION['db_config'], true));

        session_write_close();
        session_start();
    }

    return $result;
}

/**
 * Тестирование подключения к БД.
 */
function testDatabaseConnection($config)
{
    $diagnostics = [];

    try {
        $type = $config['type'] ?? 'sqlite';

        if ('mysql' === $type) {
            return testMysqlConnection($config, $diagnostics);
        } elseif ('sqlite' === $type) {
            return testSqliteConnection($config, $diagnostics);
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => '❌ Ошибка подключения: '.$e->getMessage(),
            'diagnostics' => $diagnostics,
        ];
    }
}

/**
 * Тестирование MySQL подключения.
 */
function testMysqlConnection($config, &$diagnostics)
{
    $dsn = "mysql:host={$config['host']}".
           (isset($config['port']) ? ";port={$config['port']}" : '').
           ';charset=utf8mb4';

    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    $stmt = $pdo->query('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA 
                          WHERE SCHEMA_NAME = '.$pdo->quote($config['database']));
    $dbExists = false !== $stmt->fetch();

    return [
        'success' => true,
        'message' => '✅ Подключение к MySQL успешно! '.
                    ($dbExists ? 'База данных существует.' : 'База данных будет создана.'),
        'diagnostics' => $diagnostics,
    ];
}

/**
 * Тестирование SQLite подключения.
 */
function testSqliteConnection($config, &$diagnostics)
{
    $path = $config['path'];

    if (0 !== strpos($path, '/')) {
        $path = realpath(__DIR__.'/../../').'/'.ltrim($path, '/');
    }

    $dir = dirname($path);
    $diagnostics['directory'] = $dir;

    if (!file_exists($dir)) {
        if (!mkdir($dir, 0755, true)) {
            throw new Exception("Не удалось создать директорию: $dir");
        }
    }

    if (!is_writable($dir)) {
        throw new Exception("Директория не доступна для записи: $dir");
    }

    // Просто проверяем, что можем создать файл
    $testFile = $dir.'/test.tmp';
    file_put_contents($testFile, 'test');
    unlink($testFile);

    $diagnostics['dir_writable'] = true;

    return [
        'success' => true,
        'message' => '✅ SQLite база данных может быть создана',
        'diagnostics' => $diagnostics,
    ];
}

/**
 * Обработчик создания базы данных - ГАРАНТИРОВАННО РАБОЧАЯ ВЕРСИЯ.
 */
function handleCreateDatabase($post)
{
    error_log('=== handleCreateDatabase START ===');

    try {
        $dbConfig = $_SESSION['db_config'] ?? [];

        if (empty($dbConfig)) {
            throw new Exception('Database configuration not found');
        }

        $type = $dbConfig['type'] ?? 'sqlite';

        if ('sqlite' === $type) {
            $result = createSqliteDatabase($dbConfig);
        } else {
            $result = createMysqlDatabase($dbConfig);
        }

        if ($result['success']) {
            $_SESSION['db_created'] = true;
            session_write_close();

            return [
                'success' => true,
                'message' => '✅ База данных успешно создана',
                'redirect' => 'index.php?step=4&success=1',
            ];
        }
        throw new Exception($result['message']);
    } catch (Exception $e) {
        error_log('ERROR in handleCreateDatabase: '.$e->getMessage());

        return [
            'success' => false,
            'message' => '❌ Ошибка создания БД: '.$e->getMessage(),
        ];
    }
}

/**
 * Создание SQLite базы данных - ИСПОЛЬЗУЕТ ТОЛЬКО КОМАНДНУЮ СТРОКУ.
 */
function createSqliteDatabase($dbConfig)
{
    $path = $dbConfig['path'];

    // Получаем абсолютный путь
    if (0 !== strpos($path, '/')) {
        $path = realpath(__DIR__.'/../../').'/'.ltrim($path, '/');
    }

    $dbDir = dirname($path);

    // 1. Создаем директорию
    if (!file_exists($dbDir)) {
        if (!mkdir($dbDir, 0755, true)) {
            throw new Exception("Cannot create directory: $dbDir");
        }
    }
    chmod($dbDir, 0755);

    // 2. Удаляем старую базу если есть
    if (file_exists($path)) {
        unlink($path);
    }

    // 3. Удаляем lock-файлы
    foreach (glob($dbDir.'/library.db-*') as $lockFile) {
        unlink($lockFile);
    }

    // 4. СОЗДАЕМ БАЗУ ЧЕРЕЗ SQLITE3 КОМАНДНОЙ СТРОКИ
    $sql = '
        PRAGMA journal_mode = WAL;
        PRAGMA synchronous = NORMAL;
        PRAGMA busy_timeout = 5000;
        PRAGMA foreign_keys = ON;
        
        CREATE TABLE IF NOT EXISTS books (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_path TEXT UNIQUE,
            file_name TEXT,
            file_size INTEGER,
            file_type TEXT,
            archive_path TEXT,
            archive_internal_path TEXT,
            file_hash TEXT,
            title TEXT,
            author TEXT,
            genre TEXT,
            series TEXT,
            series_number INTEGER,
            year INTEGER,
            language TEXT,
            publisher TEXT,
            description TEXT,
            added_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_modified DATETIME,
            last_scanned DATETIME,
            file_mtime INTEGER,
            UNIQUE(file_path, archive_path, archive_internal_path)
        );
        
        CREATE TABLE IF NOT EXISTS archives (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            archive_path TEXT UNIQUE,
            archive_hash TEXT,
            file_count INTEGER DEFAULT 0,
            total_size INTEGER DEFAULT 0,
            last_modified INTEGER,
            last_scanned DATETIME DEFAULT CURRENT_TIMESTAMP,
            needs_rescan BOOLEAN DEFAULT 1
        );
        
        CREATE TABLE IF NOT EXISTS book_ratings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book_id INTEGER NOT NULL,
            user_ip VARCHAR(45) NOT NULL,
            rating INTEGER NOT NULL CHECK (rating >= 1 AND rating <= 5),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
            UNIQUE(user_ip, book_id)
        );
        
        CREATE TABLE IF NOT EXISTS book_favorites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book_id INTEGER NOT NULL,
            user_ip VARCHAR(45) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
            UNIQUE(user_ip, book_id)
        );
        
        CREATE INDEX IF NOT EXISTS idx_books_author ON books(author);
        CREATE INDEX IF NOT EXISTS idx_books_title ON books(title);
        CREATE INDEX IF NOT EXISTS idx_books_genre ON books(genre);
        CREATE INDEX IF NOT EXISTS idx_books_series ON books(series);
        CREATE INDEX IF NOT EXISTS idx_books_added_date ON books(added_date);
        CREATE INDEX IF NOT EXISTS idx_books_file_type ON books(file_type);
        CREATE INDEX IF NOT EXISTS idx_books_year ON books(year);
        CREATE INDEX IF NOT EXISTS idx_books_language ON books(language);
        
        CREATE INDEX IF NOT EXISTS idx_archives_path ON archives(archive_path);
        CREATE INDEX IF NOT EXISTS idx_archives_scanned ON archives(last_scanned);
        
        CREATE INDEX IF NOT EXISTS idx_ratings_book ON book_ratings(book_id);
        CREATE INDEX IF NOT EXISTS idx_ratings_user ON book_ratings(user_ip);
        
        CREATE INDEX IF NOT EXISTS idx_favorites_book ON book_favorites(book_id);
        CREATE INDEX IF NOT EXISTS idx_favorites_user ON book_favorites(user_ip);
        
        .tables
    ';

    // Сохраняем SQL во временный файл
    $tempFile = tempnam(sys_get_temp_dir(), 'db_');
    file_put_contents($tempFile, $sql);

    // Выполняем sqlite3
    $command = 'sqlite3 '.escapeshellarg($path).' < '.escapeshellarg($tempFile).' 2>&1';
    error_log("Executing: $command");

    exec($command, $output, $returnCode);

    // Удаляем временный файл
    unlink($tempFile);

    if (0 !== $returnCode) {
        throw new Exception('SQLite error: '.implode("\n", $output));
    }

    // Проверяем, что таблицы создались
    $checkCommand = 'sqlite3 '.escapeshellarg($path)." \"SELECT name FROM sqlite_master WHERE type='table';\" 2>&1";
    exec($checkCommand, $tables, $returnCode);

    if (0 !== $returnCode || empty($tables)) {
        throw new Exception('Failed to create tables');
    }

    // Устанавливаем права
    chmod($path, 0666);

    // Убиваем все процессы, которые могли остаться
    exec('fuser -k '.escapeshellarg($path).' 2>/dev/null');

    error_log('Database created successfully with tables: '.implode(', ', $tables));

    return [
        'success' => true,
        'message' => 'SQLite database created successfully',
    ];
}

/**
 * Создание MySQL базы данных.
 */
function createMysqlDatabase($dbConfig)
{
    $dsn = "mysql:host={$dbConfig['host']}".
           (isset($dbConfig['port']) ? ";port={$dbConfig['port']}" : '').
           ';charset=utf8mb4';

    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    // Создаем базу данных если не существует
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['database']}` 
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbConfig['database']}`");

    // SQL для создания таблиц
    $sql = '
        CREATE TABLE IF NOT EXISTS books (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_path VARCHAR(500) UNIQUE,
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
            UNIQUE KEY unique_file (file_path, archive_path, archive_internal_path),
            
            INDEX idx_author (author),
            INDEX idx_title (title),
            INDEX idx_genre (genre),
            INDEX idx_series (series),
            INDEX idx_added_date (added_date),
            INDEX idx_file_type (file_type),
            INDEX idx_year (year),
            INDEX idx_language (language)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS archives (
            id INT AUTO_INCREMENT PRIMARY KEY,
            archive_path VARCHAR(500) UNIQUE,
            archive_hash VARCHAR(64),
            file_count INT DEFAULT 0,
            total_size BIGINT DEFAULT 0,
            last_modified INT,
            last_scanned DATETIME DEFAULT CURRENT_TIMESTAMP,
            needs_rescan BOOLEAN DEFAULT 1,
            
            INDEX idx_archive_path (archive_path),
            INDEX idx_last_scanned (last_scanned)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS book_ratings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            book_id INT NOT NULL,
            user_ip VARCHAR(45) NOT NULL,
            rating TINYINT NOT NULL CHECK (rating >= 1 AND rating <= 5),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_book (user_ip, book_id),
            
            INDEX idx_ratings_book (book_id),
            INDEX idx_ratings_user (user_ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS book_favorites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            book_id INT NOT NULL,
            user_ip VARCHAR(45) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_favorite (user_ip, book_id),
            
            INDEX idx_favorites_book (book_id),
            INDEX idx_favorites_user (user_ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ';

    $pdo->exec($sql);

    return [
        'success' => true,
        'message' => 'MySQL database created successfully',
    ];
}
