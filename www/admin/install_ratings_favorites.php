<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getInstance();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Установка системы рейтингов и избранного</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { background: #f0f0f0; padding: 15px; margin: 10px 0; border-radius: 5px; }
        pre { background: #e8e8e8; padding: 10px; overflow: auto; }
        .btn { display: inline-block; padding: 10px 15px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 5px; }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h1>🛠️ Установка системы рейтингов и избранного</h1>";

if (isset($_GET['action']) && $_GET['action'] === 'install') {
    echo "<div class='info'>";
    echo "<h3>Установка таблиц...</h3>";
    
    try {
        if (Config::DB_TYPE === 'mysql') {
            echo "<h4>Создание таблиц для MySQL...</h4>";
            
            // Таблица рейтингов
            $sql1 = "CREATE TABLE IF NOT EXISTS book_ratings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                book_id INT NOT NULL,
                user_ip VARCHAR(45) NOT NULL,
                rating TINYINT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT chk_rating_range CHECK (rating >= 1 AND rating <= 5),
                CONSTRAINT unique_user_book UNIQUE (user_ip, book_id),
                FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $db->getConnection()->exec($sql1);
            echo "<p class='success'>✅ Таблица book_ratings создана</p>";
            
            // Индексы для рейтингов
            $db->getConnection()->exec("CREATE INDEX IF NOT EXISTS idx_book_rating_book ON book_ratings (book_id)");
            $db->getConnection()->exec("CREATE INDEX IF NOT EXISTS idx_book_rating_user ON book_ratings (user_ip)");
            echo "<p class='success'>✅ Индексы для book_ratings созданы</p>";
            
            // Таблица избранного
            $sql2 = "CREATE TABLE IF NOT EXISTS book_favorites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                book_id INT NOT NULL,
                user_ip VARCHAR(45) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT unique_user_favorite UNIQUE (user_ip, book_id),
                FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $db->getConnection()->exec($sql2);
            echo "<p class='success'>✅ Таблица book_favorites создана</p>";
            
            // Индексы для избранного
            $db->getConnection()->exec("CREATE INDEX IF NOT EXISTS idx_favorite_book ON book_favorites (book_id)");
            $db->getConnection()->exec("CREATE INDEX IF NOT EXISTS idx_favorite_user ON book_favorites (user_ip)");
            echo "<p class='success'>✅ Индексы для book_favorites созданы</p>";
            
        } elseif (Config::DB_TYPE === 'sqlite') {
            echo "<h4>Создание таблиц для SQLite...</h4>";
            
            // Таблица рейтингов для SQLite
            $sql1 = "CREATE TABLE IF NOT EXISTS book_ratings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                book_id INTEGER NOT NULL,
                user_ip TEXT NOT NULL,
                rating INTEGER NOT NULL CHECK (rating >= 1 AND rating <= 5),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_ip, book_id),
                FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
            )";
            
            $db->getConnection()->exec($sql1);
            echo "<p class='success'>✅ Таблица book_ratings создана</p>";
            
            // Таблица избранного для SQLite
            $sql2 = "CREATE TABLE IF NOT EXISTS book_favorites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                book_id INTEGER NOT NULL,
                user_ip TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_ip, book_id),
                FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
            )";
            
            $db->getConnection()->exec($sql2);
            echo "<p class='success'>✅ Таблица book_favorites создана</p>";
            
            // Для SQLite индексы создаются автоматически для PRIMARY KEY
            // но можно создать дополнительные
            $db->getConnection()->exec("CREATE INDEX IF NOT EXISTS idx_book_rating_book ON book_ratings (book_id)");
            $db->getConnection()->exec("CREATE INDEX IF NOT EXISTS idx_book_rating_user ON book_ratings (user_ip)");
            $db->getConnection()->exec("CREATE INDEX IF NOT EXISTS idx_favorite_book ON book_favorites (book_id)");
            $db->getConnection()->exec("CREATE INDEX IF NOT EXISTS idx_favorite_user ON book_favorites (user_ip)");
            echo "<p class='success'>✅ Индексы созданы</p>";
        }
        
        // Проверяем создание таблиц
        echo "<h4>Проверка созданных таблиц...</h4>";
        
        if (Config::DB_TYPE === 'mysql') {
            $stmt = $db->getConnection()->query("SHOW TABLES LIKE 'book_ratings'");
            $ratingsTable = $stmt->fetch();
            
            $stmt = $db->getConnection()->query("SHOW TABLES LIKE 'book_favorites'");
            $favoritesTable = $stmt->fetch();
            
        } elseif (Config::DB_TYPE === 'sqlite') {
            $stmt = $db->getConnection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='book_ratings'");
            $ratingsTable = $stmt->fetch();
            
            $stmt = $db->getConnection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='book_favorites'");
            $favoritesTable = $stmt->fetch();
        }
        
        if ($ratingsTable) {
            echo "<p class='success'>✅ Таблица book_ratings успешно создана</p>";
        } else {
            echo "<p class='error'>❌ Ошибка: таблица book_ratings не создана</p>";
        }
        
        if ($favoritesTable) {
            echo "<p class='success'>✅ Таблица book_favorites успешно создана</p>";
        } else {
            echo "<p class='error'>❌ Ошибка: таблица book_favorites не создана</p>";
        }
        
        // Оптимизация таблиц
        if (Config::DB_TYPE === 'mysql') {
            $db->getConnection()->exec("OPTIMIZE TABLE book_ratings, book_favorites");
            echo "<p class='success'>✅ Таблицы оптимизированы</p>";
        } elseif (Config::DB_TYPE === 'sqlite') {
            $db->getConnection()->exec("PRAGMA optimize");
            echo "<p class='success'>✅ База данных оптимизирована</p>";
        }
        
        echo "<hr>";
        echo "<h3 class='success'>✅ Установка завершена успешно!</h3>";
        echo "<p>Теперь вы можете использовать систему рейтингов и избранного.</p>";
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Ошибка при установке: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>Ошибка SQL: " . htmlspecialchars($sql1 ?? $sql2 ?? '') . "</pre>";
    }
    
    echo "</div>";
    
    echo "<div style='margin: 20px 0;'>";
    echo "<a href='../index.php' class='btn'>Перейти в библиотеку</a>";
    echo "<a href='../favorites.php' class='btn' style='background: #dc3545;'>Проверить избранное</a>";
    echo "<a href='../top_rated.php' class='btn' style='background: #ffc107; color: #000;'>Проверить рейтинги</a>";
    echo "</div>";
    
} else {
    // Показываем информацию о системе
    echo "<div class='info'>";
    echo "<h3>Информация о системе</h3>";
    echo "<p><strong>Тип базы данных:</strong> " . Config::DB_TYPE . "</p>";
    echo "<p><strong>Хост:</strong> " . Config::DB_HOST . "</p>";
    echo "<p><strong>База данных:</strong> " . Config::DB_NAME . "</p>";
    
    try {
        // Проверяем существующие таблицы
        echo "<h4>Существующие таблицы:</h4>";
        
        if (Config::DB_TYPE === 'mysql') {
            $stmt = $db->getConnection()->query("SHOW TABLES LIKE 'book_%'");
            $tables = $stmt->fetchAll();
        } elseif (Config::DB_TYPE === 'sqlite') {
            $stmt = $db->getConnection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'book_%'");
            $tables = $stmt->fetchAll();
        }
        
        if (!empty($tables)) {
            echo "<ul>";
            foreach ($tables as $table) {
                $tableName = $table[0] ?? $table['name'] ?? '';
                echo "<li>" . htmlspecialchars($tableName) . "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>Таблицы для рейтингов и избранного еще не созданы.</p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>Ошибка при проверке таблиц: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    echo "</div>";
    
    // Кнопка для установки
    echo "<div style='margin: 20px 0;'>";
    echo "<h3>Установка системы рейтингов и избранного</h3>";
    echo "<p>Эта операция создаст две новые таблицы в базе данных:</p>";
    echo "<ol>";
    echo "<li><strong>book_ratings</strong> - для хранения оценок книг пользователями</li>";
    echo "<li><strong>book_favorites</strong> - для хранения избранных книг пользователей</li>";
    echo "</ol>";
    echo "<p><em>Примечание: Данные будут идентифицироваться по IP-адресу пользователя.</em></p>";
    
    echo "<a href='?action=install' class='btn' onclick='return confirm(\"Вы уверены, что хотите установить систему рейтингов и избранного?\")'>Установить систему</a>";
    echo "<a href='../index.php' class='btn' style='background: #6c757d;'>Отмена</a>";
    echo "</div>";
}

echo "</body></html>";
?>