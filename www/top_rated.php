<?php
// top_rated.php - исправленная версия

define('LOPDS_ROOT', __DIR__);

// Если это AJAX запрос, не используем кэш страниц
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    require_once 'config/config.php';
    require_once 'lib/Database.php';
    require_once 'lib/BookHelper.php';
    require_once 'lib/Cache.php';
} else {
    require_once 'config/config.php';
    require_once 'lib/Database.php';
    require_once 'lib/BookHelper.php';
    require_once 'lib/Cache.php';
    require_once 'lib/PageCache.php';
    require_once 'init.php';

    // Начинаем кэширование страницы
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageCacheKey = 'top_rated_page_' . $page;
    PageCache::start($pageCacheKey);
}

$db = Database::getInstance();
$userIp = $_SERVER['REMOTE_ADDR'];
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

//количество на странице для топа
$perPage = Config::getItemsPerPage();

// ========== ПОЛУЧАЕМ ДАННЫЕ ИЗ КЭША ==========
// Используем актуальный ключ
$cacheKey = 'top_rated_data_v3'; // <-- Убедитесь, что это v3, а не v2
$allBooks = Cache::get($cacheKey, 'statistics');

// Добавляем отладку
error_log("=== TOP RATED CACHE CHECK ===");
error_log("Cache key: " . $cacheKey);
error_log("Cache type: statistics");
error_log("Cache hit: " . ($allBooks !== null ? "YES" : "NO"));

if ($allBooks === null) {
    error_log("Cache MISS - executing query");

    // ОПТИМИЗИРОВАННЫЙ ЗАПРОС С ИСПОЛЬЗОВАНИЕМ ИНДЕКСОВ
    $startTime = microtime(true);

    if (Config::getDbType() === 'mysql') {
        // MySQL версия с подзапросом
        $sql = "SELECT
                    b.id,
                    b.title,
                    b.author,
                    b.series,
                    b.series_number,
                    b.genre,
                    b.file_type,
                    b.added_date,
                    COALESCE(r_stats.avg_rating, 0) as avg_rating,
                    COALESCE(r_stats.votes_count, 0) as votes_count
                FROM books b
                LEFT JOIN (
                    SELECT
                        book_id,
                        AVG(rating) as avg_rating,
                        COUNT(*) as votes_count
                    FROM book_ratings
                    GROUP BY book_id
                ) r_stats ON b.id = r_stats.book_id
                WHERE r_stats.votes_count >= 1
                ORDER BY r_stats.avg_rating DESC, r_stats.votes_count DESC, b.title
                LIMIT 100";
    } else {
        // SQLite версия
        $sql = "SELECT
                    b.id,
                    b.title,
                    b.author,
                    b.series,
                    b.series_number,
                    b.genre,
                    b.file_type,
                    b.added_date,
                    IFNULL(r_stats.avg_rating, 0) as avg_rating,
                    IFNULL(r_stats.votes_count, 0) as votes_count
                FROM books b
                LEFT JOIN (
                    SELECT
                        book_id,
                        AVG(rating) as avg_rating,
                        COUNT(*) as votes_count
                    FROM book_ratings
                    GROUP BY book_id
                ) r_stats ON b.id = r_stats.book_id
                WHERE r_stats.votes_count >= 1
                ORDER BY r_stats.avg_rating DESC, r_stats.votes_count DESC, b.title
                LIMIT 100";
    }

    $stmt = $db->getConnection()->query($sql);
    $allBooks = $stmt->fetchAll();
    $queryTime = microtime(true) - $startTime;
    error_log("Top rated query time: " . round($queryTime, 2) . " sec, found " . count($allBooks) . " books");

    // Кэшируем на 1 час
    Cache::set($cacheKey, $allBooks, 'statistics', 3600);
    error_log("Cache SET: {$cacheKey}");
} else {
    error_log("Cache HIT - using cached data, books count: " . count($allBooks));
}

// ========== ПОЛУЧАЕМ СТАТУС ИЗБРАННОГО ОДНИМ ЗАПРОСОМ ==========
$favoritesMap = [];
$bookIds = array_column($allBooks, 'id');
if (!empty($bookIds)) {
    $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
    $params = array_merge($bookIds, [$userIp]);

    $stmt = $db->getConnection()->prepare("
        SELECT book_id
        FROM book_favorites
        WHERE book_id IN ($placeholders) AND user_ip = ?
    ");
    $stmt->execute($params);

    while ($row = $stmt->fetch()) {
        $favoritesMap[$row['book_id']] = true;
    }
}

// ========== ПАГИНАЦИЯ ==========
$totalBooks = count($allBooks);
$totalPages = ceil($totalBooks / $perPage);
$offset = ($page - 1) * $perPage;
$currentBooks = array_slice($allBooks, $offset, $perPage);

// ========== ОБЩАЯ СТАТИСТИКА РЕЙТИНГОВ ==========
$statsCacheKey = 'rating_stats_global_v2';
$ratingStats = Cache::get($statsCacheKey, 'statistics');

if ($ratingStats === null) {
    $stmt = $db->getConnection()->query("
        SELECT
            COUNT(DISTINCT book_id) as rated_books,
            COUNT(*) as total_ratings,
            COALESCE(AVG(rating), 0) as avg_rating
        FROM book_ratings
    ");
    $ratingStats = $stmt->fetch();
    Cache::set($statsCacheKey, $ratingStats, 'statistics', 3600);
}

require 'templates/header.php';
?>

<div class="container mt-4">
    <h1 class="mb-4">
        <i class="fas fa-star text-warning me-2"></i>
        <?php echo __('top_rated_title'); ?>
    </h1>
    
    <div class="alert alert-info">
        <div class="d-flex align-items-center">
            <div class="me-3">
                <i class="fas fa-info-circle fa-2x"></i>
            </div>
            <div>
                <p class="mb-0">
                    <?php echo __('top_rated_description'); ?>
                </p>
            </div>
        </div>
    </div>
    
    <?php if (empty($allBooks)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo __('top_rated_empty'); ?>
        </div>
    <?php else: ?>
        <!-- Статистика рейтингов -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center shadow-sm">
                    <div class="card-body">
                        <h6 class="card-title text-muted"><?php echo __('top_rated_total_rated'); ?></h6>
                        <div class="display-4 text-primary"><?php echo number_format($ratingStats['rated_books']); ?></div>
                        <small class="text-muted"><?php echo __('of'); ?> <?php echo number_format($totalBooks); ?> <?php echo __('top_rated_in_top'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center shadow-sm">
                    <div class="card-body">
                        <h6 class="card-title text-muted"><?php echo __('top_rated_total_votes'); ?></h6>
                        <div class="display-4 text-success"><?php echo number_format($ratingStats['total_ratings']); ?></div>
                        <small class="text-muted"><?php echo __('top_rated_from_users'); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center shadow-sm">
                    <div class="card-body">
                        <h6 class="card-title text-muted"><?php echo __('stats_avg_rating'); ?></h6>
                        <div class="display-4 text-warning"><?php echo number_format($ratingStats['avg_rating'], 2); ?></div>
                        <div class="mt-2">
                            <?php
                            $avgRounded = round($ratingStats['avg_rating'] * 2) / 2;
        $fullStars = floor($avgRounded);
        $halfStar = $avgRounded - $fullStars >= 0.5;
        for ($i = 0; $i < $fullStars; $i++): ?>
                                <i class="fas fa-star text-warning"></i>
                            <?php endfor; ?>
                            <?php if ($halfStar): ?>
                                <i class="fas fa-star-half-alt text-warning"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Таблица рейтинга -->
        <div class="card shadow">
            <div class="card-header py-3 bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-trophy me-2"></i>
                        <?php echo __('top_rated_books'); ?>
                    </h6>
                    <div class="text-muted small">
                        <?php echo __('page'); ?> <?php echo $page; ?> <?php echo __('of'); ?> <?php echo $totalPages; ?>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="60" class="text-center">#</th>
                                <th><?php echo __('book_title'); ?></th>
                                <th width="200"><?php echo __('book_author'); ?></th>
                                <th width="150"><?php echo __('rating'); ?></th>
                                <th width="80" class="text-center"><?php echo __('votes'); ?></th>
                                <th width="100" class="text-center"><?php echo __('book_format'); ?></th>
                                <th width="150" class="text-center"><?php echo __('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($currentBooks as $index => $book):
                                $globalIndex = $offset + $index + 1;
                                $avgRating = (float)$book['avg_rating'];
                                $votes = (int)$book['votes_count'];
                                $isFavorite = isset($favoritesMap[$book['id']]);
                                ?>
                                <tr>
                                    <td class="text-center align-middle">
                                        <?php if ($globalIndex <= 3): ?>
                                            <span class="badge bg-warning text-dark rounded-circle p-2" style="width: 32px;">
                                                <?php echo $globalIndex; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-circle p-2" style="width: 32px;">
                                                <?php echo $globalIndex; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle">
                                        <div>
                                            <a href="book_detail.php?id=<?php echo $book['id']; ?>" 
                                               class="text-decoration-none fw-bold">
                                                <?php echo htmlspecialchars(mb_substr($book['title'] ?: __('book_untitled'), 0, 60)) . (mb_strlen($book['title'] ?? '') > 60 ? '…' : ''); ?>
                                            </a>
                                            <?php if (!empty($book['series'])): ?>
                                                <div class="small text-muted">
                                                    <i class="fas fa-bookmark me-1"></i>
                                                    <?php echo htmlspecialchars(mb_substr($book['series'], 0, 40)); ?>
                                                    <?php if (!empty($book['series_number'])): ?>
                                                        <span class="badge bg-light text-dark border ms-1">#<?php echo $book['series_number']; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="align-middle">
                                        <?php if (!empty($book['author'])): ?>
                                            <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" 
                                               class="text-decoration-none">
                                                <?php echo htmlspecialchars(mb_substr($book['author'], 0, 30)); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted"><?php echo __('book_unknown_author'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle">
                                        <div class="d-flex align-items-center">
                                            <span class="h5 mb-0 text-warning me-2"><?php echo number_format($avgRating, 1); ?></span>
                                            <div class="star-rating">
                                                <?php
                                                    $fullStars = floor($avgRating);
                                $halfStar = $avgRating - $fullStars >= 0.5;
                                for ($i = 0; $i < $fullStars; $i++): ?>
                                                    <i class="fas fa-star text-warning" style="font-size: 0.8em;"></i>
                                                <?php endfor; ?>
                                                <?php if ($halfStar): ?>
                                                    <i class="fas fa-star-half-alt text-warning" style="font-size: 0.8em;"></i>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center align-middle">
                                        <span class="badge bg-primary rounded-pill"><?php echo $votes; ?></span>
                                    </td>
                                    <td class="text-center align-middle">
                                        <span class="badge bg-secondary"><?php echo strtoupper($book['file_type']); ?></span>
                                    </td>
                                    <td class="text-center align-middle">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="book_detail.php?id=<?php echo $book['id']; ?>" 
                                               class="btn btn-outline-primary" 
                                               title="<?php echo __('details'); ?>"
                                               data-bs-toggle="tooltip">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="./api/download.php?id=<?php echo $book['id']; ?>" 
                                               class="btn btn-outline-success" 
                                               title="<?php echo __('download'); ?>"
                                               data-bs-toggle="tooltip">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <button class="btn <?php echo $isFavorite ? 'btn-danger' : 'btn-outline-danger'; ?> favorite-btn"
                                                    onclick="toggleFavorite(<?php echo $book['id']; ?>, this)"
                                                    data-book-id="<?php echo $book['id']; ?>"
                                                    title="<?php echo $isFavorite ? __('favorites_remove') : __('favorites_add'); ?>">
                                                <i class="<?php echo $isFavorite ? 'fas' : 'far'; ?> fa-heart"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Пагинация -->
                <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white">
                    <nav aria-label="<?php echo __('pagination'); ?>">
                        <ul class="pagination justify-content-center mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="<?php echo __('previous'); ?>">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php
                            $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);

                    if ($startPage > 1) {
                        echo '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>';
                        if ($startPage > 2) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                    }

                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a>
                                </li>
                            <?php endif; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="<?php echo __('next'); ?>">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="mt-4 text-center">
        <a href="index.php" class="btn btn-primary">
            <i class="fas fa-search me-2"></i><?php echo __('favorites_find_books'); ?>
        </a>
        <a href="favorites.php" class="btn btn-outline-danger ms-2">
            <i class="fas fa-heart me-2"></i><?php echo __('my_favorites'); ?>
        </a>
        <a href="stats.php" class="btn btn-outline-info ms-2">
            <i class="fas fa-chart-bar me-2"></i><?php echo __('stats_full'); ?>
        </a>
    </div>
</div>

<!-- Информация о времени генерации -->
<div class="container mt-2">
    <div class="text-center text-muted small">
        <?php
        $genTime = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3);
?>
        <i class="fas fa-clock me-1"></i>
        <?php echo sprintf(__('stats_generated_in'), $genTime); ?> | 
        <i class="fas fa-database me-1"></i>
        <?php echo __('stats_queries'); ?>: <?php echo $db->getQueryCount(); ?> | 
        <i class="fas fa-bolt me-1"></i>
        <?php echo __('stats_cache'); ?>: <?php echo $allBooks ? 'HIT' : 'MISS'; ?>
    </div>
</div>

<style>
.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,0.02);
}
.star-rating {
    display: inline-block;
    white-space: nowrap;
}
.badge.rounded-circle {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
}
.fa-spinner {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<?php


require 'templates/footer.php';
PageCache::save();
?>
