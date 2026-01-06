<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::getInstance();

// Логируем запуск
error_log("[" . date('Y-m-d H:i:s') . "] Запуск оптимизации БД");

try {
    if (Config::DB_TYPE === 'mysql') {
        // Оптимизация для MySQL
        $db->getConnection()->query("OPTIMIZE TABLE books");
        error_log("Таблица books оптимизирована");
        
        // Анализ таблицы для обновления статистики
        $db->getConnection()->query("ANALYZE TABLE books");
        error_log("Статистика таблицы обновлена");
    } elseif (Config::DB_TYPE === 'sqlite') {
        // Оптимизация для SQLite
        $db->getConnection()->exec("PRAGMA optimize");
        $db->getConnection()->exec("PRAGMA vacuum");
        error_log("SQLite база оптимизирована и сжата");
    }
    
    // Очистка старого кэша
    Cache::clear();
    error_log("Кэш очищен");
    
    error_log("Оптимизация завершена успешно");
    
} catch (Exception $e) {
    error_log("Ошибка при оптимизации: " . $e->getMessage());
}