<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getInstance();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Оптимизация базы данных</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        .info { background: #f0f0f0; padding: 15px; margin: 10px 0; border-radius: 5px; }
        pre { background: #e8e8e8; padding: 10px; overflow: auto; }
        table { border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
    </style>
</head>
<body>
    <h1>🛠️ Оптимизация базы данных</h1>";

if (Config::DB_TYPE === 'mysql') {
    echo "<div class='info'>";
    echo "<h3>MySQL Оптимизация</h3>";
    
    $action = $_GET['action'] ?? '';
    
    if ($action === 'create_fulltext') {
        echo "<h4>Создание FULLTEXT индекса...</h4>";
        try {
            $db->getConnection()->exec("ALTER TABLE books ADD FULLTEXT ft_search (title, author, genre, series)");
            echo "<p class='success'>✅ FULLTEXT индекс создан</p>";
        } catch (Exception $e) {
            echo "<p class='error'>❌ Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    if ($action === 'create_indexes') {
        echo "<h4>Создание обычных индексов...</h4>";
        try {
            $indexes = [
                "CREATE INDEX idx_books_author ON books (author(100))",
                "CREATE INDEX idx_books_title ON books (title(100))",
                "CREATE INDEX idx_books_genre ON books (genre(50))",
                "CREATE INDEX idx_books_series ON books (series(100))",
                "CREATE INDEX idx_books_added_date ON books (added_date)",
            ];
            
            foreach ($indexes as $sql) {
                try {
                    $db->getConnection()->exec($sql);
                    echo "<p class='success'>✅ $sql</p>";
                } catch (Exception $e) {
                    echo "<p class='warning'>⚠️ $sql - " . htmlspecialchars($e->getMessage()) . "</p>";
                }
            }
            
        } catch (Exception $e) {
            echo "<p class='error'>❌ Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    if ($action === 'optimize') {
        echo "<h4>Оптимизация таблицы...</h4>";
        try {
            $db->getConnection()->exec("OPTIMIZE TABLE books");
            echo "<p class='success'>✅ Таблица books оптимизирована</p>";
        } catch (Exception $e) {
            echo "<p class='error'>❌ Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    try {
        // 1. Проверяем структуру таблицы
        echo "<h4>Структура таблицы books:</h4>";
        $stmt = $db->getConnection()->query("DESCRIBE books");
        $columns = $stmt->fetchAll();
        
        echo "<table>";
        echo "<tr><th>Поле</th><th>Тип</th><th>Null</th><th>Ключ</th><th>По умолчанию</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td>{$col['Field']}</td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>{$col['Key']}</td>";
            echo "<td>{$col['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // 2. Проверяем существующие индексы
        echo "<h4>Текущие индексы:</h4>";
        $stmt = $db->getConnection()->query("SHOW INDEXES FROM books");
        $indexes = $stmt->fetchAll();
        
        if (empty($indexes)) {
            echo "<p class='warning'>⚠️ Индексы не найдены</p>";
        } else {
            echo "<table>";
            echo "<tr><th>Название</th><th>Колонка</th><th>Тип</th><th>Уникальность</th><th>Длина</th></tr>";
            foreach ($indexes as $index) {
                echo "<tr>";
                echo "<td>{$index['Key_name']}</td>";
                echo "<td>{$index['Column_name']}</td>";
                echo "<td>{$index['Index_type']}</td>";
                echo "<td>" . ($index['Non_unique'] == 0 ? 'Да' : 'Нет') . "</td>";
                echo "<td>{$index['Sub_part']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // 3. Статистика по таблице
        echo "<h4>Статистика таблицы:</h4>";
try {
    // Два отдельных запроса для обхода ONLY_FULL_GROUP_BY
    $stmt = $db->getConnection()->query("SELECT COUNT(*) as total_rows FROM books");
    $countResult = $stmt->fetch();
    $totalRows = $countResult['total_rows'] ?? 0;
    
    $stmt = $db->getConnection()->query("
        SELECT 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as total_mb,
            ROUND(SUM(data_length) / 1024 / 1024, 2) as data_mb,
            ROUND(SUM(index_length) / 1024 / 1024, 2) as index_mb
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE() 
        AND table_name = 'books'
        GROUP BY table_name
    ");
    
    if ($stmt) {
        $stats = $stmt->fetch();
        echo "<pre>";
        echo "Всего записей: " . number_format($totalRows) . "\n";
        echo "Общий размер: " . ($stats['total_mb'] ?? 0) . " MB\n";
        echo "Размер данных: " . ($stats['data_mb'] ?? 0) . " MB\n";
        echo "Размер индексов: " . ($stats['index_mb'] ?? 0) . " MB\n";
        echo "</pre>";
    }


} catch (Exception $e) {
    echo "<p class='error'>❌ Ошибка статистики: " . htmlspecialchars($e->getMessage()) . "</p>";
}
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    echo "</div>";
    
    // Кнопки для управления
    echo "<h3>Действия:</h3>";
    echo "<div style='margin: 20px 0;'>";
    echo "<a href='?action=create_indexes' class='button' style='padding: 10px; background: #007bff; color: white; text-decoration: none; margin: 5px; display: inline-block;'>Создать обычные индексы</a>";
    echo "<a href='?action=create_fulltext' class='button' style='padding: 10px; background: #28a745; color: white; text-decoration: none; margin: 5px; display: inline-block;'>Создать FULLTEXT индекс</a>";
    echo "<a href='?action=optimize' class='button' style='padding: 10px; background: #17a2b8; color: white; text-decoration: none; margin: 5px; display: inline-block;'>Оптимизировать таблицу</a>";
    echo "</div>";
    
} elseif (Config::DB_TYPE === 'sqlite') {
    echo "<div class='info'>";
    echo "<h3>SQLite Оптимизация</h3>";
    
    try {
        // 1. Создаем индексы для SQLite
        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_books_author ON books (author)",
            "CREATE INDEX IF NOT EXISTS idx_books_title ON books (title)",
            "CREATE INDEX IF NOT EXISTS idx_books_genre ON books (genre)",
            "CREATE INDEX IF NOT EXISTS idx_books_series ON books (series)",
            "CREATE INDEX IF NOT EXISTS idx_books_added_date ON books (added_date)",
        ];
        
        foreach ($indexes as $sql) {
            $db->getConnection()->exec($sql);
            echo "<p class='success'>✅ $sql</p>";
        }
        
        // 2. Оптимизация SQLite
        $db->getConnection()->exec("PRAGMA optimize");
        echo "<p class='success'>✅ База данных оптимизирована</p>";
        
        // 3. Показываем информацию
        $stmt = $db->getConnection()->query("PRAGMA index_list('books')");
        $indexes = $stmt->fetchAll();
        
        echo "<h4>Список индексов:</h4>";
        echo "<pre>";
        foreach ($indexes as $index) {
            echo "{$index['name']} - {$index['origin']}\n";
        }
        echo "</pre>";
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    echo "</div>";
}

echo "<hr>";
echo "<h3>📊 Проверка поиска</h3>";

echo "<form method='post'>
    <input type='text' name='test_query' placeholder='Тестовый запрос' value='Пушкин'>
    <select name='test_field'>
        <option value='all'>Везде</option>
        <option value='author'>Автор</option>
        <option value='title'>Название</option>
        <option value='genre'>Жанр</option>
        <option value='series'>Серия</option>
    </select>
    <button type='submit' name='run_test'>Запустить тест</button>
</form>";

if (isset($_POST['run_test'])) {
    $testQuery = $_POST['test_query'] ?? 'Пушкин';
    $testField = $_POST['test_field'] ?? 'all';
    
    echo "<h4>Результаты теста для запроса: '{$testQuery}' (поле: {$testField})</h4>";
    
    // Тест производительности
    $times = [];
    $resultsCount = [];
    
    for ($i = 0; $i < 3; $i++) { // 3 прогона для усреднения
        $start = microtime(true);
        $results = $db->searchBooks($testQuery, $testField, 1, 10);
        $time = microtime(true) - $start;
        $times[] = $time;
        $resultsCount[] = count($results);
        
        if ($i == 0) {
            echo "<p>Найдено книг: " . count($results) . "</p>";
            if (count($results) > 0) {
                echo "<ul>";
                foreach (array_slice($results, 0, 5) as $book) {
                    echo "<li>" . htmlspecialchars($book['title'] ?? 'Без названия') . " - " . htmlspecialchars($book['author'] ?? 'Неизвестен') . "</li>";
                }
                echo "</ul>";
            }
        }
        
        usleep(100000); // 0.1 сек пауза
    }
    
    $avgTime = array_sum($times) / count($times);
    $avgCount = array_sum($resultsCount) / count($resultsCount);
    
    echo "<pre>";
    echo "Среднее время поиска: " . round($avgTime * 1000, 2) . " ms\n";
    echo "Вариация времени: " . round((max($times) - min($times)) * 1000, 2) . " ms\n";
    echo "Тип БД: " . Config::DB_TYPE . "\n";
    echo "</pre>";
}

echo "<hr>";
echo "<p><a href='../index.php'>Вернуться в библиотеку</a> | <a href='../stats.php'>Статистика</a></p>";
echo "</body></html>";