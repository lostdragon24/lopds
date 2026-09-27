<?php

// install/includes/database.php

/**
 * Обработчик тестирования подключения к БД
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
            'path' => $post['path'] ?? ''
        ];

        if (isset($result['diagnostics'])) {
            $_SESSION['db_diagnostics'] = $result['diagnostics'];
        }

        error_log("DB config saved to session: " . print_r($_SESSION['db_config'], true));

        session_write_close();
        session_start();
    }
    return $result;
}

/**
 * Тестирование подключения к БД
 */
function testDatabaseConnection($config)
{
    $diagnostics = [];

    try {
        $type = $config['type'] ?? 'sqlite';

        if ($type === 'mysql') {
            return testMysqlConnection($config, $diagnostics);
        } elseif ($type === 'sqlite') {
            return testSqliteConnection($config, $diagnostics);
        }

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => '❌ Ошибка подключения: ' . $e->getMessage(),
            'diagnostics' => $diagnostics
        ];
    }
}

/**
 * Тестирование MySQL подключения
 */
function testMysqlConnection($config, &$diagnostics)
{
    $dsn = "mysql:host={$config['host']}" .
           (isset($config['port']) ? ";port={$config['port']}" : "") .
           ";charset=utf8mb4";

    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);

    $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA 
                          WHERE SCHEMA_NAME = " . $pdo->quote($config['database']));
    $dbExists = $stmt->fetch() !== false;

    return [
        'success' => true,
        'message' => '✅ Подключение к MySQL успешно! ' .
                    ($dbExists ? 'База данных существует.' : 'База данных будет создана.'),
        'diagnostics' => $diagnostics
    ];
}

/**
 * Тестирование SQLite подключения
 */
function testSqliteConnection($config, &$diagnostics)
{
    $path = $config['path'];

    if (strpos($path, '/') !== 0) {
        $path = realpath(__DIR__ . '/../../') . '/' . ltrim($path, '/');
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
    $testFile = $dir . '/test.tmp';
    file_put_contents($testFile, 'test');
    unlink($testFile);

    $diagnostics['dir_writable'] = true;

    return [
        'success' => true,
        'message' => '✅ SQLite база данных может быть создана',
        'diagnostics' => $diagnostics
    ];
}

/**
 * Обработчик создания базы данных - ГАРАНТИРОВАННО РАБОЧАЯ ВЕРСИЯ
 */
function handleCreateDatabase($post)
{
    error_log("=== handleCreateDatabase START ===");

    try {
        $dbConfig = $_SESSION['db_config'] ?? [];

        if (empty($dbConfig)) {
            throw new Exception('Database configuration not found');
        }

        $type = $dbConfig['type'] ?? 'sqlite';

        if ($type === 'sqlite') {
            $result = createSqliteDatabase($dbConfig);
        } else {
            $result = createMysqlDatabase($dbConfig);
        }

        if ($result['success']) {
            $_SESSION['db_created'] = true;
            session_write_close();

            return [
                'success' => true,
                'message' => "✅ База данных успешно создана",
                'redirect' => 'index.php?step=4&success=1'
            ];
        } else {
            throw new Exception($result['message']);
        }

    } catch (Exception $e) {
        error_log("ERROR in handleCreateDatabase: " . $e->getMessage());
        return [
            'success' => false,
            'message' => '❌ Ошибка создания БД: ' . $e->getMessage()
        ];
    }
}

/**
 * Создание SQLite базы данных - ИСПОЛЬЗУЕТ ТОЛЬКО КОМАНДНУЮ СТРОКУ
 */
function createSqliteDatabase($dbConfig)
{
    $path = $dbConfig['path'];

    // Получаем абсолютный путь
    if (strpos($path, '/') !== 0) {
        $baseDir = realpath(__DIR__ . '/../../');
        if ($baseDir === false) {
            throw new Exception("Invalid base directory path");
        }
        $path = $baseDir . '/' . ltrim($path, '/');
    }

    $dbDir = dirname($path);

    // 1. Создаем директорию
    if (!file_exists($dbDir)) {
        if (!mkdir($dbDir, 0755, true)) {
            throw new Exception("Cannot create directory: $dbDir");
        }
    }
    chmod($dbDir, 0755);

    // 2. Удаляем старую базу, если есть
    if (file_exists($path)) {
        if (!unlink($path)) {
            throw new Exception("Cannot delete existing database file: $path");
        }
    }

    // 3. Удаляем lock-файлы (-wal, -shm) именно для этого файла БД
    foreach (glob($path . '-*') as $lockFile) {
        if (is_file($lockFile)) {
            unlink($lockFile);
        }
    }

    // 4. СОЗДАЕМ БАЗУ ЧЕРЕЗ SQLITE3 КОМАНДНОЙ СТРОКИ
    $sql = "
        PRAGMA journal_mode = WAL;
        PRAGMA synchronous = NORMAL;
        PRAGMA busy_timeout = 5000;
        PRAGMA foreign_keys = ON;
        
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

    // Сохраняем SQL во временный файл
    $tempFile = tempnam(sys_get_temp_dir(), 'db_');

    try {
        file_put_contents($tempFile, $sql);

        // Выполняем sqlite3
        $command = "sqlite3 " . escapeshellarg($path) . " < " . escapeshellarg($tempFile) . " 2>&1";
        error_log("Executing: $command");

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("SQLite error: " . implode("\n", $output));
        }

        // Проверяем, что таблицы создались
        $checkCommand = "sqlite3 " . escapeshellarg($path) . " \"SELECT name FROM sqlite_master WHERE type='table';\" 2>&1";
        exec($checkCommand, $tables, $returnCode);

        if ($returnCode !== 0 || empty($tables)) {
            throw new Exception("Failed to create tables");
        }

        // Устанавливаем права на файл БД
        // Примечание: 0666 дает права на запись всем. В продакшене лучше использовать 0664
        // и настроить правильную группу владельца (например, www-data).
        chmod($path, 0664);

        error_log("Database created successfully with tables: " . implode(', ', $tables));

        return [
            'success' => true,
            'message' => 'SQLite database created successfully'
        ];
    } finally {
        // Гарантированно удаляем временный файл даже в случае ошибки
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }
}

/**
 * Создание MySQL базы данных
 */
function createMysqlDatabase($dbConfig)
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

    // 3. Разбиваем SQL на массив отдельных запросов
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
