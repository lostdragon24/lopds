<?php

// cron/optimize_tables.php

// Определяем константу, чтобы config.php знал, что это не прямой доступ
define('LOPDS_ROOT', __DIR__.'/..');

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../lib/Database.php';
require_once __DIR__.'/../lib/Cache.php';

$db = Database::getInstance();
$dbType = Config::getDbType();

echo "=====================================\n";
echo 'ОПТИМИЗАЦИЯ БАЗЫ ДАННЫХ ('.strtoupper($dbType).")\n";
echo "=====================================\n";
echo 'Старт: '.date('Y-m-d H:i:s')."\n\n";

// Логируем запуск
error_log('['.date('Y-m-d H:i:s').'] Запуск полной оптимизации БД');

try {
    // ========== 1. ОПТИМИЗАЦИЯ В ЗАВИСИМОСТИ ОТ ТИПА БД ==========
    if ('mysql' === $dbType) {
        echo "MySQL оптимизация:\n";
        echo "------------------\n";

        // 1.1 Создаем индексы для таблицы books (если нет)
        $indexes = [
            'idx_author' => 'author(100)',
            'idx_title' => 'title(100)',
            'idx_genre' => 'genre(50)',
            'idx_series' => 'series(100)',
            'idx_added_date' => 'added_date',
            'idx_year' => 'year',
            'idx_author_added' => 'author(100), added_date',
            'idx_author_count' => 'author(100)',
            'idx_genre_count' => 'genre(50)',
            'idx_series_count' => 'series(100)',
        ];

        echo "\nПроверка индексов books:\n";

        // Получаем существующие индексы
        $existingIndexes = [];
        $stmt = $db->getConnection()->query('SHOW INDEX FROM books');
        while ($row = $stmt->fetch()) {
            $existingIndexes[$row['Key_name']] = true;
        }

        foreach ($indexes as $name => $columns) {
            if (!isset($existingIndexes[$name])) {
                try {
                    $db->getConnection()->exec("CREATE INDEX $name ON books ($columns)");
                    echo "  ✅ Создан индекс: $name ($columns)\n";
                } catch (Exception $e) {
                    echo "  ❌ Ошибка создания $name: ".$e->getMessage()."\n";
                }
            } else {
                echo "  ✓ Индекс уже существует: $name\n";
            }
        }

        // 1.2 Индексы для book_ratings
        echo "\nПроверка индексов book_ratings:\n";
        $ratingIndexes = [
            'idx_book_rating_book' => 'book_id',
            'idx_book_rating_user' => 'user_ip',
            'idx_book_rating_composite' => 'book_id, rating',
        ];

        $existingIndexes = [];
        $stmt = $db->getConnection()->query('SHOW INDEXES FROM book_ratings');
        while ($row = $stmt->fetch()) {
            $existingIndexes[$row['Key_name']] = true;
        }

        foreach ($ratingIndexes as $name => $columns) {
            if (!isset($existingIndexes[$name])) {
                try {
                    $db->getConnection()->exec("CREATE INDEX $name ON book_ratings ($columns)");
                    echo "  ✅ Создан индекс: $name ($columns)\n";
                } catch (Exception $e) {
                    echo "  ❌ Ошибка создания $name: ".$e->getMessage()."\n";
                }
            } else {
                echo "  ✓ Индекс уже существует: $name\n";
            }
        }

        // 1.3 Индексы для book_favorites
        echo "\nПроверка индексов book_favorites:\n";
        $favIndexes = [
            'idx_favorite_book' => 'book_id',
            'idx_favorite_user' => 'user_ip',
            'idx_favorite_composite' => 'book_id, user_ip',
        ];

        $existingIndexes = [];
        $stmt = $db->getConnection()->query('SHOW INDEXES FROM book_favorites');
        while ($row = $stmt->fetch()) {
            $existingIndexes[$row['Key_name']] = true;
        }

        foreach ($favIndexes as $name => $columns) {
            if (!isset($existingIndexes[$name])) {
                try {
                    $db->getConnection()->exec("CREATE INDEX $name ON book_favorites ($columns)");
                    echo "  ✅ Создан индекс: $name ($columns)\n";
                } catch (Exception $e) {
                    echo "  ❌ Ошибка создания $name: ".$e->getMessage()."\n";
                }
            } else {
                echo "  ✓ Индекс уже существует: $name\n";
            }
        }

        // 1.4 Анализ и оптимизация таблиц
        echo "\nОптимизация таблиц:\n";
        $tables = ['books', 'book_ratings', 'book_favorites'];
        foreach ($tables as $table) {
            try {
                $db->getConnection()->exec("OPTIMIZE TABLE $table");
                echo "  ✅ Таблица $table оптимизирована\n";

                $db->getConnection()->exec("ANALYZE TABLE $table");
                echo "  ✅ Статистика таблицы $table обновлена\n";
            } catch (Exception $e) {
                echo "  ❌ Ошибка оптимизации $table: ".$e->getMessage()."\n";
            }
        }
    } elseif ('sqlite' === $dbType) {
        echo "SQLite оптимизация:\n";
        echo "------------------\n";

        // 2.1 PRAGMA оптимизации
        $pragmas = [
            'PRAGMA journal_mode = WAL',
            'PRAGMA synchronous = NORMAL',
            'PRAGMA cache_size = -64000',
            'PRAGMA temp_store = memory',
            'PRAGMA mmap_size = 268435456', // 256MB для memory-mapped I/O
        ];

        foreach ($pragmas as $pragma) {
            try {
                $db->getConnection()->exec($pragma);
                echo "  ✅ $pragma\n";
            } catch (Exception $e) {
                echo '  ❌ Ошибка: '.$e->getMessage()."\n";
            }
        }

        // 2.2 Создание индексов для SQLite
        echo "\nСоздание индексов:\n";

        $sqliteIndexes = [
            'CREATE INDEX IF NOT EXISTS idx_books_author ON books(author)',
            'CREATE INDEX IF NOT EXISTS idx_books_title ON books(title)',
            'CREATE INDEX IF NOT EXISTS idx_books_genre ON books(genre)',
            'CREATE INDEX IF NOT EXISTS idx_books_series ON books(series)',
            'CREATE INDEX IF NOT EXISTS idx_books_added_date ON books(added_date)',
            'CREATE INDEX IF NOT EXISTS idx_books_year ON books(year)',

            'CREATE INDEX IF NOT EXISTS idx_book_ratings_book_id ON book_ratings(book_id)',
            'CREATE INDEX IF NOT EXISTS idx_book_ratings_user_ip ON book_ratings(user_ip)',
            'CREATE INDEX IF NOT EXISTS idx_book_ratings_composite ON book_ratings(book_id, rating)',

            'CREATE INDEX IF NOT EXISTS idx_book_favorites_book_id ON book_favorites(book_id)',
            'CREATE INDEX IF NOT EXISTS idx_book_favorites_user_ip ON book_favorites(user_ip)',
            'CREATE INDEX IF NOT EXISTS idx_book_favorites_composite ON book_favorites(book_id, user_ip)',
        ];

        foreach ($sqliteIndexes as $sql) {
            try {
                $db->getConnection()->exec($sql);
                echo "  ✅ $sql\n";
            } catch (Exception $e) {
                echo '  ❌ Ошибка: '.$e->getMessage()."\n";
            }
        }

        // 2.3 VACUUM (сжатие базы)
        echo "\nСжатие базы данных (VACUUM):\n";
        try {
            $db->getConnection()->exec('VACUUM');
            echo "  ✅ База данных сжата\n";
        } catch (Exception $e) {
            echo '  ❌ Ошибка VACUUM: '.$e->getMessage()."\n";
        }
    }

    // ========== 3. ОБЩИЕ ОПТИМИЗАЦИИ ДЛЯ ОБЕИХ БД ==========
    echo "\n";
    echo "=====================================\n";
    echo "ОБЩИЕ ОПТИМИЗАЦИИ\n";
    echo "=====================================\n";

    // 3.1 Очистка старых данных (если нужно)
    echo "\nОчистка старых данных:\n";
    try {
        // Удаляем записи о рейтингах для несуществующих книг
        $stmt = $db->getConnection()->prepare('
            DELETE FROM book_ratings
            WHERE book_id NOT IN (SELECT id FROM books)
        ');
        $stmt->execute();
        $deleted = $stmt->rowCount();
        echo "  ✅ Удалено $deleted записей рейтингов для несуществующих книг\n";

        // Удаляем записи избранного для несуществующих книг
        $stmt = $db->getConnection()->prepare('
            DELETE FROM book_favorites
            WHERE book_id NOT IN (SELECT id FROM books)
        ');
        $stmt->execute();
        $deleted = $stmt->rowCount();
        echo "  ✅ Удалено $deleted записей избранного для несуществующих книг\n";
    } catch (Exception $e) {
        echo '  ❌ Ошибка очистки: '.$e->getMessage()."\n";
    }

    // 3.2 Обновление кэша
    echo "\nОбновление кэша:\n";
    try {
        // Очищаем старый кэш
        Cache::clear();
        echo "  ✅ Кэш APCu очищен\n";

        // Удаляем файловый кэш
        $cacheDir = Config::getCacheDir();
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir.'/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            echo '  ✅ Файловый кэш очищен ('.count($files)." файлов)\n";
        }

        // Прогреваем кэш для топ книг
        echo "\nПрогрев кэша:\n";

        // Топ книги
        $start = microtime(true);
        $topBooks = $db->getConnection()->query('
            SELECT b.id, b.title, b.author, b.series, b.series_number,
                   b.genre, b.file_type, b.added_date,
                   COALESCE(AVG(r.rating), 0) as avg_rating,
                   COUNT(r.id) as votes_count
            FROM books b
            LEFT JOIN book_ratings r ON b.id = r.book_id
            GROUP BY b.id
            HAVING votes_count >= 1
            ORDER BY avg_rating DESC, votes_count DESC, b.title
            LIMIT 100
        ')->fetchAll();

        Cache::set('top_rated_all_v3', $topBooks, 1800);
        $time = microtime(true) - $start;
        echo '  ✅ Кэш топ книг обновлен ('.round($time, 2)." сек)\n";

        // Статистика
        $start = microtime(true);
        $stats = [
            'total_books' => $db->getConnection()->query('SELECT COUNT(*) FROM books')->fetchColumn(),
            'total_authors' => $db->getConnection()->query('SELECT COUNT(DISTINCT author) FROM books WHERE author IS NOT NULL')->fetchColumn(),
            'total_genres' => $db->getConnection()->query('SELECT COUNT(DISTINCT genre) FROM books WHERE genre IS NOT NULL')->fetchColumn(),
            'total_series' => $db->getConnection()->query('SELECT COUNT(DISTINCT series) FROM books WHERE series IS NOT NULL')->fetchColumn(),
        ];

        Cache::set('collection_stats', $stats, 3600);
        $time = microtime(true) - $start;
        echo '  ✅ Кэш статистики обновлен ('.round($time, 2)." сек)\n";
    } catch (Exception $e) {
        echo '  ❌ Ошибка обновления кэша: '.$e->getMessage()."\n";
    }

    // ========== 4. ИТОГ ==========
    echo "\n";
    echo "=====================================\n";
    echo "ИТОГИ ОПТИМИЗАЦИИ\n";
    echo "=====================================\n";

    // Статистика таблиц
    if ('mysql' === $dbType) {
        $stmt = $db->getConnection()->query("
            SELECT
                table_name,
                ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb,
                table_rows
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name IN ('books', 'book_ratings', 'book_favorites')
        ");

        while ($row = $stmt->fetch()) {
            echo $row['table_name'].': '.$row['size_mb'].' MB, '.number_format($row['table_rows'])." строк\n";
        }
    }

    $totalTime = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
    echo "\nВремя выполнения: ".round($totalTime, 2)." сек\n";
    echo 'Завершено: '.date('Y-m-d H:i:s')."\n";

    error_log('['.date('Y-m-d H:i:s').'] Оптимизация завершена за '.round($totalTime, 2).' сек');
} catch (Exception $e) {
    error_log('['.date('Y-m-d H:i:s').'] Ошибка при оптимизации: '.$e->getMessage());
    echo "\n❌ ОШИБКА: ".$e->getMessage()."\n";
}

echo "\n=====================================\n";
