<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../lib/Database.php';
require_once __DIR__.'/../lib/Cache.php';

echo '['.date('Y-m-d H:i:s')."] Обновление кэша топ книг...\n";

$db = Database::getInstance();
$startTime = microtime(true);

// Обновляем топ книги (оптимизированный запрос)
if (Config::getDbType() === 'mysql') {
    $sql = 'SELECT b.id, b.title, b.author, b.series, b.series_number, 
                   b.genre, b.file_type, b.added_date,
                   COALESCE(r_stats.avg_rating, 0) as avg_rating,
                   COALESCE(r_stats.votes_count, 0) as votes_count
            FROM books b
            STRAIGHT_JOIN (
                SELECT book_id, AVG(rating) as avg_rating, COUNT(*) as votes_count
                FROM book_ratings
                GROUP BY book_id
                HAVING COUNT(*) >= 1
                ORDER BY avg_rating DESC, votes_count DESC
                LIMIT 100
            ) r_stats ON b.id = r_stats.book_id
            ORDER BY r_stats.avg_rating DESC, r_stats.votes_count DESC, b.title';
} else {
    $sql = 'SELECT b.id, b.title, b.author, b.series, b.series_number, 
                   b.genre, b.file_type, b.added_date,
                   COALESCE(r_stats.avg_rating, 0) as avg_rating,
                   COALESCE(r_stats.votes_count, 0) as votes_count
            FROM books b
            INNER JOIN (
                SELECT book_id, AVG(rating) as avg_rating, COUNT(*) as votes_count
                FROM book_ratings
                GROUP BY book_id
                HAVING votes_count >= 1
                ORDER BY avg_rating DESC, votes_count DESC
                LIMIT 100
            ) r_stats ON b.id = r_stats.book_id
            ORDER BY r_stats.avg_rating DESC, r_stats.votes_count DESC, b.title';
}

$topBooks = $db->getConnection()->query($sql)->fetchAll();

Cache::set('top_rated_all_v3', $topBooks, 'statistics', 1800);
echo '  ✅ Топ книги обновлены ('.count($topBooks).' книг за '.round(microtime(true) - $startTime, 2)." сек)\n";

// Обновляем статистику (один запрос)
$startTime = microtime(true);
if (Config::isMysql()) {
    $sql = "SELECT 
                (SELECT TABLE_ROWS FROM information_schema.TABLES 
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'books') as total_books,
                (SELECT COUNT(DISTINCT author) FROM books USE INDEX (idx_author_count) 
                 WHERE author IS NOT NULL AND author != '') as total_authors,
                (SELECT COUNT(DISTINCT genre) FROM books USE INDEX (idx_genre_count) 
                 WHERE genre IS NOT NULL AND genre != '') as total_genres,
                (SELECT COUNT(DISTINCT series) FROM books USE INDEX (idx_series_count) 
                 WHERE series IS NOT NULL AND series != '') as total_series";
} else {
    $sql = "SELECT 
                (SELECT COUNT(*) FROM books) as total_books,
                (SELECT COUNT(DISTINCT author) FROM books WHERE author IS NOT NULL AND author != '') as total_authors,
                (SELECT COUNT(DISTINCT genre) FROM books WHERE genre IS NOT NULL AND genre != '') as total_genres,
                (SELECT COUNT(DISTINCT series) FROM books WHERE series IS NOT NULL AND series != '') as total_series";
}

$stmt = $db->getConnection()->query($sql);
$stats = $stmt->fetch();

Cache::set('collection_stats', $stats, 'statistics', 3600);
echo '  ✅ Статистика обновлена за '.round(microtime(true) - $startTime, 2)." сек\n";

echo '['.date('Y-m-d H:i:s')."] Готово\n";
