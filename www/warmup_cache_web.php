<?php

// warmup_cache_web.php - прогреваем кэш через веб-запросы
// Запускать через curl или wget

define('LOPDS_ROOT', __DIR__);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/Cache.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/init.php';

echo "<pre>\n";
echo "=== Warming up cache (APCu enabled) ===\n";

$db = Database::getInstance();
$itemsPerPage = Config::getItemsPerPage();

// Проверяем APCu
echo "APCu: " . (extension_loaded('apcu') && apcu_enabled() ? "ENABLED" : "DISABLED") . "\n\n";

// 1. Прогреваем переводы
echo "1. Loading translations...\n";
$test = __('home');
echo "   OK\n";

// 2. Прогреваем жанры
echo "2. Loading genres...\n";
$genres = GenreManager::getAllGenres();
echo "   Loaded " . count($genres) . " genres\n";

// 3. Прогреваем статус БД
echo "3. Checking database status...\n";
$checker = DatabaseChecker::getInstance();
$status = $checker->getDetailedStatus(true);
echo "   Database available: " . ($status['database_available'] ? 'yes' : 'no') . "\n";

// 4. Прогреваем статистику
echo "4. Loading statistics...\n";
$stats = $db->getCollectionStats();
Cache::set('collection_stats_sidebar', $stats, 'statistics', 3600);
echo "   Stats loaded: " . $stats['total_books'] . " books\n";

// 5. Прогреваем первые 5 страниц
echo "5. Warming catalog pages...\n";
for ($page = 1; $page <= 5; $page++) {
    $cacheKey = 'books_page_' . $page . '_recent';
    $books = $db->getRecentBooks($itemsPerPage, ($page - 1) * $itemsPerPage);
    $total = $db->getTotalBooksCount();
    Cache::set($cacheKey, ['books' => $books, 'total' => $total], 'search_results', 300);
    echo "   Page $page: " . count($books) . " books\n";
}

// 6. Прогреваем рейтинги для первых 50 книг
echo "6. Warming ratings...\n";
$books = $db->getRecentBooks(50, 0);
$bookIds = array_column($books, 'id');
$ratings = $db->getRatingsForBooks($bookIds);
echo "   Loaded ratings for " . count($ratings) . " books\n";

// 7. Прогреваем топ рейтингов
echo "7. Warming top rated...\n";
$topBooks = $db->getTopRatedBooks(50);
Cache::set('top_rated_all_v3', $topBooks, 'statistics', 1800);
echo "   Loaded " . count($topBooks) . " top books\n";

// 8. Прогреваем авторов
echo "8. Warming authors...\n";
$authors = $db->getTopAuthors(50);
Cache::set('top_authors_v2_50', $authors, 'statistics', 7200);
echo "   Loaded " . count($authors) . " authors\n";

// 9. Прогреваем главную страницу
echo "9. Warming index page...\n";
ob_start();
require __DIR__ . '/index.php';
ob_end_clean();
echo "   Index page cached\n";

// 10. Статистика
ob_start();
require __DIR__ . '/stats.php';
ob_end_clean();



echo "\n=== Cache warmup completed ===\n";
echo "</pre>";
