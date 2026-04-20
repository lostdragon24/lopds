<?php
// stats.php

define('LOPDS_ROOT', __DIR__);

require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/Cache.php';
require_once 'lib/PageCache.php';
require_once 'init.php';

PageCache::start('stats_optimized_v2');

$db = Database::getInstance();
$dbType = Config::getDbType();

// Ключ кэша с учётом даты (обновляется раз в час)
$cacheKey = 'stats_complete_data_'.date('YmdH');
$cachedData = Cache::get($cacheKey, 'statistics');

if (null !== $cachedData) {
    // Данные уже в кэше – отдаём их
    $stats = $cachedData['stats'];
    $topAuthors = $cachedData['topAuthors'];
    $genres = $cachedData['genres'];
    $ratingStats = $cachedData['ratingStats'];
    $favoritesStats = $cachedData['favoritesStats'];
    $topRatedBooks = $cachedData['topRatedBooks'];
    $popularFavorites = $cachedData['popularFavorites'];
    $fileTypes = $cachedData['fileTypes'];
} else {
    // ========== ОСНОВНОЙ ЗАПРОС – ВСЯ ОБЩАЯ СТАТИСТИКА ЗА РАЗ ==========
    if ('mysql' === $dbType) {
        $sql = "SELECT
                    (SELECT COUNT(*) FROM books) AS total_books,
                    (SELECT COUNT(DISTINCT author) FROM books WHERE author IS NOT NULL AND author != '') AS total_authors,
                    (SELECT COUNT(DISTINCT genre) FROM books WHERE genre IS NOT NULL AND genre != '') AS total_genres,
                    (SELECT COUNT(DISTINCT series) FROM books WHERE series IS NOT NULL AND series != '') AS total_series,
                    (SELECT MAX(added_date) FROM books) AS last_update,
                    (SELECT COUNT(*) FROM books WHERE archive_path IS NOT NULL AND archive_path != '') AS books_in_archives,
                    
                    -- Статистика рейтингов
                    (SELECT COUNT(DISTINCT book_id) FROM book_ratings) AS rated_books,
                    (SELECT COUNT(*) FROM book_ratings) AS total_ratings,
                    (SELECT COALESCE(AVG(rating), 0) FROM book_ratings) AS avg_rating,
                    (SELECT COUNT(DISTINCT user_ip) FROM book_ratings) AS unique_voters,
                    
                    -- Статистика избранного
                    (SELECT COUNT(DISTINCT book_id) FROM book_favorites) AS favorited_books,
                    (SELECT COUNT(*) FROM book_favorites) AS total_favorites,
                    (SELECT COUNT(DISTINCT user_ip) FROM book_favorites) AS users_with_favorites";
    } else {
        // SQLite
        $sql = "SELECT
                    (SELECT COUNT(*) FROM books) AS total_books,
                    (SELECT COUNT(DISTINCT author) FROM books WHERE author IS NOT NULL AND author != '') AS total_authors,
                    (SELECT COUNT(DISTINCT genre) FROM books WHERE genre IS NOT NULL AND genre != '') AS total_genres,
                    (SELECT COUNT(DISTINCT series) FROM books WHERE series IS NOT NULL AND series != '') AS total_series,
                    (SELECT MAX(added_date) FROM books) AS last_update,
                    (SELECT COUNT(*) FROM books WHERE archive_path IS NOT NULL AND archive_path != '') AS books_in_archives,
                    
                    (SELECT COUNT(DISTINCT book_id) FROM book_ratings) AS rated_books,
                    (SELECT COUNT(*) FROM book_ratings) AS total_ratings,
                    (SELECT COALESCE(AVG(rating), 0) FROM book_ratings) AS avg_rating,
                    (SELECT COUNT(DISTINCT user_ip) FROM book_ratings) AS unique_voters,
                    
                    (SELECT COUNT(DISTINCT book_id) FROM book_favorites) AS favorited_books,
                    (SELECT COUNT(*) FROM book_favorites) AS total_favorites,
                    (SELECT COUNT(DISTINCT user_ip) FROM book_favorites) AS users_with_favorites";
    }

    $stmt = $db->getConnection()->query($sql);
    $basic = $stmt->fetch();

    // Формируем массив stats из полученных данных
    $stats = [
        'total_books' => (int) $basic['total_books'],
        'total_authors' => (int) $basic['total_authors'],
        'total_genres' => (int) $basic['total_genres'],
        'total_series' => (int) $basic['total_series'],
        'last_update' => $basic['last_update'],
        'books_in_archives' => (int) $basic['books_in_archives'],
    ];

    $ratingStats = [
        'rated_books' => (int) $basic['rated_books'],
        'total_ratings' => (int) $basic['total_ratings'],
        'avg_rating' => (float) $basic['avg_rating'],
        'unique_voters' => (int) $basic['unique_voters'],
    ];

    $favoritesStats = [
        'favorited_books' => (int) $basic['favorited_books'],
        'total_favorites' => (int) $basic['total_favorites'],
        'users_with_favorites' => (int) $basic['users_with_favorites'],
    ];

    // ========== ФОРМАТЫ ФАЙЛОВ ==========
    $stmt = $db->getConnection()->query('
        SELECT file_type, COUNT(*) as count
        FROM books
        WHERE file_type IS NOT NULL
        GROUP BY file_type
        ORDER BY count DESC
    ');
    $fileTypes = $stmt->fetchAll();

    // ========== ТОП-20 АВТОРОВ ==========
    if ('mysql' === Config::getDbType()) {
        // Пробуем использовать индекс, но если его нет - просто выполняем запрос
        try {
            $sql = "SELECT author, COUNT(*) as count
                FROM books USE INDEX (idx_author_count)
                WHERE author IS NOT NULL AND author != ''
                GROUP BY author
                ORDER BY count DESC
                LIMIT 20";
            $stmt = $db->getConnection()->query($sql);
        } catch (Exception $e) {
            // Если индекс не существует, выполняем запрос без подсказки
            error_log('Index idx_author_count not found, using simple query: '.$e->getMessage());
            $sql = "SELECT author, COUNT(*) as count
                FROM books 
                WHERE author IS NOT NULL AND author != ''
                GROUP BY author
                ORDER BY count DESC
                LIMIT 20";
            $stmt = $db->getConnection()->query($sql);
        }
    } else {
        $sql = "SELECT author, COUNT(*) as count
            FROM books 
            WHERE author IS NOT NULL AND author != ''
            GROUP BY author
            ORDER BY count DESC
            LIMIT 20";
        $stmt = $db->getConnection()->query($sql);
    }
    $topAuthors = $stmt->fetchAll();

    // ========== ТОП-50 ЖАНРОВ ==========
    try {
        if ('mysql' === Config::getDbType()) {
            try {
                $sql = "SELECT genre, COUNT(*) as count
                    FROM books USE INDEX (idx_genre_count)
                    WHERE genre IS NOT NULL AND genre != ''
                    GROUP BY genre
                    ORDER BY count DESC
                    LIMIT 50";
                $stmt = $db->getConnection()->query($sql);
            } catch (Exception $e) {
                error_log('Index idx_genre_count not found, using simple query: '.$e->getMessage());
                $sql = "SELECT genre, COUNT(*) as count
                    FROM books 
                    WHERE genre IS NOT NULL AND genre != ''
                    GROUP BY genre
                    ORDER BY count DESC
                    LIMIT 50";
                $stmt = $db->getConnection()->query($sql);
            }
        } else {
            $sql = "SELECT genre, COUNT(*) as count
                FROM books 
                WHERE genre IS NOT NULL AND genre != ''
                GROUP BY genre
                ORDER BY count DESC
                LIMIT 50";
            $stmt = $db->getConnection()->query($sql);
        }

        $genres = $stmt->fetchAll();

        // Добавляем читаемые названия жанров
        foreach ($genres as &$genre) {
            $genre['readable_name'] = $db->getReadableGenre($genre['genre']);
        }
    } catch (Exception $e) {
        error_log('Error loading genres in stats.php: '.$e->getMessage());
        $genres = [];
    }

    // ========== ТОП-10 КНИГ ПО РЕЙТИНГУ ==========
    if ('mysql' === $dbType) {
        $sql = 'SELECT b.id, b.title, b.author,
                       COALESCE(r.avg_rating, 0) as avg_rating,
                       COALESCE(r.votes_count, 0) as votes
                FROM books b
                STRAIGHT_JOIN (
                    SELECT book_id, AVG(rating) as avg_rating, COUNT(*) as votes_count
                    FROM book_ratings
                    GROUP BY book_id
                    HAVING votes_count >= 1
                    ORDER BY avg_rating DESC, votes_count DESC
                    LIMIT 10
                ) r ON b.id = r.book_id
                ORDER BY r.avg_rating DESC, r.votes_count DESC';
    } else {
        $sql = 'SELECT b.id, b.title, b.author,
                       IFNULL(r.avg_rating, 0) as avg_rating,
                       IFNULL(r.votes_count, 0) as votes
                FROM books b
                INNER JOIN (
                    SELECT book_id, AVG(rating) as avg_rating, COUNT(*) as votes_count
                    FROM book_ratings
                    GROUP BY book_id
                    HAVING votes_count >= 1
                    ORDER BY avg_rating DESC, votes_count DESC
                    LIMIT 10
                ) r ON b.id = r.book_id
                ORDER BY r.avg_rating DESC, r.votes_count DESC';
    }
    $topRatedBooks = $db->getConnection()->query($sql)->fetchAll();

    // ========== ТОП-10 ПОПУЛЯРНЫХ В ИЗБРАННОМ ==========
    if ('mysql' === $dbType) {
        $sql = 'SELECT b.id, b.title, b.author, COUNT(f.id) as favorites_count
                FROM books b
                STRAIGHT_JOIN book_favorites f ON b.id = f.book_id
                GROUP BY b.id
                ORDER BY favorites_count DESC
                LIMIT 10';
    } else {
        $sql = 'SELECT b.id, b.title, b.author, COUNT(f.id) as favorites_count
                FROM books b
                JOIN book_favorites f ON b.id = f.book_id
                GROUP BY b.id
                ORDER BY favorites_count DESC
                LIMIT 10';
    }
    $popularFavorites = $db->getConnection()->query($sql)->fetchAll();

    // ========== СОХРАНЯЕМ ВСЁ В КЭШ НА 1 ЧАС ==========
    $cachedData = [
        'stats' => $stats,
        'topAuthors' => $topAuthors,
        'genres' => $genres,
        'ratingStats' => $ratingStats,
        'favoritesStats' => $favoritesStats,
        'topRatedBooks' => $topRatedBooks,
        'popularFavorites' => $popularFavorites,
        'fileTypes' => $fileTypes,
    ];
    Cache::set($cacheKey, $cachedData, 'statistics', 3600);
}

// Системная информация (не кэшируется)
$executionTime = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
$systemInfo = [
    'memory_usage' => memory_get_peak_usage(true),
    'memory_limit' => ini_get('memory_limit'),
    'php_version' => PHP_VERSION,
    'db_type' => $dbType,
    'apcu_enabled' => extension_loaded('apcu') && apcu_enabled(),
    'execution_time' => $executionTime,
    'query_count' => $db->getQueryCount(),
];

require 'templates/header.php';
?>

<div class="container-fluid">
    <h1 class="mb-4">📊 <?php echo __('stats_title'); ?></h1>

    <!-- Основные метрики (4 карточки) -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                📚 <?php echo __('stats_total_books'); ?>
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_books'], 0, '', ' '); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-book fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                ✍️ <?php echo __('stats_total_authors'); ?>
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_authors'], 0, '', ' '); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-user-edit fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                ⭐ <?php echo __('stats_total_ratings'); ?>
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($ratingStats['total_ratings'], 0, '', ' '); ?>
                            </div>
                            <div class="mt-1">
                                <small class="text-muted">
                                    <?php echo number_format($ratingStats['rated_books'], 0, '', ' '); ?> 
                                    <?php echo __('stats_books_rated'); ?>
                                </small>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-star fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                ❤️ <?php echo __('stats_total_favorites'); ?>
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($favoritesStats['total_favorites'], 0, '', ' '); ?>
                            </div>
                            <div class="mt-1">
                                <small class="text-muted">
                                    <?php echo number_format($favoritesStats['favorited_books'], 0, '', ' '); ?> 
                                    <?php echo __('stats_books_favorited'); ?>
                                </small>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-heart fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Левая колонка: общая статистика -->
        <div class="col-lg-8">
            <!-- Статистика рейтингов -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-warning">
                        ⭐ <?php echo __('stats_ratings_title'); ?>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 text-center mb-3">
                            <div class="h2 text-warning mb-1">
                                <?php echo number_format($ratingStats['avg_rating'], 1); ?>
                            </div>
                            <div class="text-muted"><?php echo __('stats_avg_rating'); ?></div>
                            <div class="mt-2">
                                <?php
                                $avgRounded = round($ratingStats['avg_rating'] * 2) / 2;
for ($i = 0; $i < floor($avgRounded); ++$i) {
    echo '<i class="fas fa-star text-warning"></i>';
}
if ($avgRounded - floor($avgRounded) >= 0.5) {
    echo '<i class="fas fa-star-half-alt text-warning"></i>';
}
?>
                            </div>
                        </div>
                        <div class="col-md-4 text-center mb-3">
                            <div class="h2 text-primary mb-1">
                                <?php echo number_format($ratingStats['rated_books'], 0, '', ' '); ?>
                            </div>
                            <div class="text-muted"><?php echo __('stats_rated_books'); ?></div>
                            <div class="mt-2">
                                <small>
                                    <?php echo round($ratingStats['rated_books'] / max(1, $stats['total_books']) * 100, 1); ?>% 
                                    <?php echo __('stats_percent_of_all'); ?>
                                </small>
                            </div>
                        </div>
                        <div class="col-md-4 text-center mb-3">
                            <div class="h2 text-success mb-1">
                                <?php echo number_format($ratingStats['unique_voters'], 0, '', ' '); ?>
                            </div>
                            <div class="text-muted"><?php echo __('stats_unique_voters'); ?></div>
                            <div class="mt-2">
                                <small><?php echo __('stats_by_ip'); ?></small>
                            </div>
                        </div>
                    </div>

                    <!-- Топ книг по рейтингу -->
                    <?php if (!empty($topRatedBooks)) { ?>
                    <div class="mt-4">
                        <h6 class="font-weight-bold">🏆 <?php echo __('stats_top_rated_books'); ?></h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo __('book_title'); ?></th>
                                        <th><?php echo __('book_author'); ?></th>
                                        <th><?php echo __('rating'); ?></th>
                                        <th><?php echo __('votes'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topRatedBooks as $book) { ?>
                                    <tr>
                                        <td>
                                            <a href="book_detail.php?id=<?php echo $book['id']; ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars(mb_substr($book['title'], 0, 30)).(mb_strlen($book['title']) > 30 ? '...' : ''); ?>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars(mb_substr($book['author'], 0, 20)); ?></td>
                                        <td>
                                            <span class="text-warning"><?php echo number_format($book['avg_rating'], 1); ?></span>
                                            <small class="text-muted">
                                                <?php
                $fullStars = floor($book['avg_rating']);
                                        $halfStar = $book['avg_rating'] - $fullStars >= 0.5;
                                        for ($i = 0; $i < $fullStars; ++$i) {
                                            echo '<i class="fas fa-star text-warning" style="font-size:0.8em;"></i>';
                                        }
                                        if ($halfStar) {
                                            echo '<i class="fas fa-star-half-alt text-warning" style="font-size:0.8em;"></i>';
                                        }
                                        ?>
                                            </small>
                                        </td>
                                        <td><?php echo $book['votes']; ?></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </div>

            <!-- Статистика избранного -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-danger">
                        ❤️ <?php echo __('stats_favorites_title'); ?>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 text-center mb-3">
                            <div class="h2 text-danger mb-1">
                                <?php echo number_format($favoritesStats['total_favorites'], 0, '', ' '); ?>
                            </div>
                            <div class="text-muted"><?php echo __('stats_total_adds'); ?></div>
                        </div>
                        <div class="col-md-4 text-center mb-3">
                            <div class="h2 text-info mb-1">
                                <?php echo number_format($favoritesStats['favorited_books'], 0, '', ' '); ?>
                            </div>
                            <div class="text-muted"><?php echo __('stats_unique_books'); ?></div>
                            <div class="mt-2">
                                <small>
                                    <?php echo round($favoritesStats['favorited_books'] / max(1, $stats['total_books']) * 100, 1); ?>% 
                                    <?php echo __('stats_percent_of_all'); ?>
                                </small>
                            </div>
                        </div>
                        <div class="col-md-4 text-center mb-3">
                            <div class="h2 text-success mb-1">
                                <?php echo number_format($favoritesStats['users_with_favorites'], 0, '', ' '); ?>
                            </div>
                            <div class="text-muted"><?php echo __('stats_users_with_fav'); ?></div>
                            <div class="mt-2">
                                <small><?php echo __('stats_use_favorites'); ?></small>
                            </div>
                        </div>
                    </div>

                    <!-- Популярные книги в избранном -->
                    <?php if (!empty($popularFavorites)) { ?>
                    <div class="mt-4">
                        <h6 class="font-weight-bold">🔥 <?php echo __('stats_popular_fav'); ?></h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo __('book_title'); ?></th>
                                        <th><?php echo __('book_author'); ?></th>
                                        <th><?php echo __('favorites'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($popularFavorites as $book) { ?>
                                    <tr>
                                        <td>
                                            <a href="book_detail.php?id=<?php echo $book['id']; ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars(mb_substr($book['title'], 0, 30)).(mb_strlen($book['title']) > 30 ? '...' : ''); ?>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars(mb_substr($book['author'], 0, 20)); ?></td>
                                        <td>
                                            <span class="text-danger">
                                                <i class="fas fa-heart"></i> <?php echo $book['favorites_count']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </div>

            <!-- Распределение по форматам -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        📁 <?php echo __('stats_formats_title'); ?>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><?php echo __('stats_format'); ?></th>
                                    <th><?php echo __('stats_count'); ?></th>
                                    <th><?php echo __('stats_percent'); ?></th>
                                    <th style="width:40%;"><?php echo __('stats_progress'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $totalBooks = $stats['total_books'];
foreach ($fileTypes as $fileType) {
    $percentage = $totalBooks > 0 ? round(($fileType['count'] / $totalBooks) * 100, 1) : 0;
    $progressWidth = min($percentage, 100);
    $progressClass = $percentage > 50 ? 'bg-success' : ($percentage > 20 ? 'bg-info' : 'bg-warning');
    ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo strtoupper($fileType['file_type']); ?>
                                        </span>
                                    </td>
                                    <td class="font-weight-bold">
                                        <?php echo number_format($fileType['count'], 0, '', ' '); ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $progressClass; ?>">
                                            <?php echo $percentage; ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <div class="progress" style="height:20px;">
                                            <div class="progress-bar <?php echo $progressClass; ?>" 
                                                 style="width:<?php echo $progressWidth; ?>%">
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Топ-20 авторов -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        👑 <?php echo __('stats_top_authors'); ?>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($topAuthors as $index => $author) { ?>
                        <div class="col-md-6 mb-3">
                            <div class="card border-left-primary h-100">
                                <div class="card-body py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge bg-primary me-2">#<?php echo $index + 1; ?></span>
                                            <a href="index.php?field=author&q=<?php echo urlencode($author['author']); ?>" 
                                               class="text-decoration-none text-dark font-weight-bold">
                                                <?php echo htmlspecialchars($author['author']); ?>
                                            </a>
                                        </div>
                                        <div>
                                            <span class="badge bg-secondary"><?php echo $author['count']; ?></span>
                                            <small class="text-muted ms-1"><?php echo __('stats_books_count'); ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <!-- Топ-50 жанров -->
            <?php if (!empty($genres)) { ?>
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-tags me-2"></i><?php echo __('stats_genres_title'); ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php
    $totalGenres = count($genres);
                $columns = 3;
                $genresPerColumn = ceil($totalGenres / $columns);
                for ($col = 0; $col < $columns; ++$col) {
                    $start = $col * $genresPerColumn;
                    $end = min($start + $genresPerColumn, $totalGenres);
                    $columnGenres = array_slice($genres, $start, $end - $start);
                    ?>
                                <div class="col-md-4">
                                    <?php foreach ($columnGenres as $genre) { ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2 p-2 border-bottom">
                                        <div>
                                            <a href="index.php?field=genre&q=<?php echo urlencode($genre['genre']); ?>" 
                                               class="text-decoration-none">
                                                <?php echo htmlspecialchars($genre['readable_name'] ?? $genre['genre']); ?>
                                            </a>
                                        </div>
                                        <div>
                                            <span class="badge bg-primary"><?php echo $genre['count']; ?></span>
                                            <small class="text-muted ms-1">
                                                (<?php echo $totalBooks > 0 ? round(($genre['count'] / $totalBooks) * 100, 1) : 0; ?>%)
                                            </small>
                                        </div>
                                    </div>
                                    <?php } ?>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div>

        <!-- Правая колонка: системная информация -->
        <div class="col-lg-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        ⚙️ <?php echo __('stats_system_title'); ?>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <h6 class="font-weight-bold text-dark mb-2">
                            📊 <?php echo __('stats_performance'); ?>
                        </h6>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small><?php echo __('stats_memory_used'); ?></small>
                                <span class="badge bg-info">
                                    <?php echo round($systemInfo['memory_usage'] / 1024 / 1024, 2); ?>MB
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small><?php echo __('stats_execution_time'); ?></small>
                                <span class="badge bg-secondary">
                                    <?php echo round($systemInfo['execution_time'], 3); ?>s
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small><?php echo __('stats_db_queries'); ?></small>
                                <span class="badge bg-dark"><?php echo $systemInfo['query_count']; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <h6 class="font-weight-bold text-dark mb-2">
                            🔧 <?php echo __('stats_config'); ?>
                        </h6>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small><?php echo __('stats_php_version'); ?></small>
                                <span class="badge bg-secondary"><?php echo $systemInfo['php_version']; ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small><?php echo __('stats_db_type'); ?></small>
                                <span class="badge bg-info"><?php echo strtoupper($systemInfo['db_type']); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small><?php echo __('stats_caching'); ?></small>
                                <span class="badge <?php echo Config::ENABLE_CACHE ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo Config::ENABLE_CACHE ? __('stats_enabled') : __('stats_disabled'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <h6 class="font-weight-bold text-dark mb-2">
                            🚀 <?php echo __('stats_quick_actions'); ?>
                        </h6>
                        <div class="d-grid gap-2">
                            <a href="favorites.php" class="btn btn-outline-danger">
                                <i class="fas fa-heart me-2"></i><?php echo __('my_favorites'); ?>
                            </a>
                            <a href="top_rated.php" class="btn btn-outline-warning">
                                <i class="fas fa-star me-2"></i><?php echo __('top_rated'); ?>
                            </a>
                            <?php if (Config::ENABLE_CACHE) { ?>
                            <a href="cache_stats.php" class="btn btn-outline-info">
                                <i class="fas fa-bolt me-2"></i><?php echo __('cache'); ?>
                            </a>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Время генерации (информация внизу) -->
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert alert-light border text-center small">
                <i class="fas fa-clock me-1"></i> 
                <?php echo sprintf(__('stats_generated_in'), round($systemInfo['execution_time'], 3)); ?> |
                <i class="fas fa-database me-1"></i> 
                <?php echo __('stats_db_queries'); ?>: <?php echo $systemInfo['query_count']; ?> |
                <i class="fas fa-bolt me-1"></i> 
                <?php echo __('stats_cache'); ?>: <?php echo $cachedData ? 'HIT' : 'MISS'; ?> |
                <i class="fas fa-memory me-1"></i> 
                <?php echo __('stats_memory_used'); ?>: <?php echo round($systemInfo['memory_usage'] / 1024 / 1024, 2); ?> MB
            </div>
        </div>
    </div>
</div>

<?php
PageCache::save();
require 'templates/footer.php';
?>