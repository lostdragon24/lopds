<?php
// index.php

define('LOPDS_ROOT', __DIR__);

require_once __DIR__ . '/init.php';
require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/BookHelper.php';
require_once 'lib/PageCache.php';
require_once 'lib/DatabaseChecker.php';
require_once 'lib/Translator.php';

// Проверяем состояние базы данных (с кэшированием на 10 минут)
$cacheKey = 'db_status_check_v2';
$status = Cache::get($cacheKey, 'statistics');

if ($status === null) {
    $checker = DatabaseChecker::getInstance();
    $status = $checker->getDetailedStatus();
    Cache::set($cacheKey, $status, 'statistics', 600); // Кэш на 10 минут
}

// Если база данных недоступна - показываем техработы
if (!$status['database_available']) {
    $error = $status['error'] ?? __('error_database');
    require 'templates/maintenance.php';
    exit;
}

// Если база доступна, но нет таблиц - показываем специальное сообщение
if (!$status['tables_exist']) {
    if (strpos($_SERVER['SCRIPT_NAME'], '/admin/') === false) {
        $error = __('error_init');
        require 'templates/maintenance.php';
        exit;
    } else {
        header('Location: install/index.php');
        exit;
    }
}

// Всё хорошо - продолжаем нормальную работу
try {
    $db = Database::getInstance();
    if (!$db->isAvailable()) {
        throw new Exception('Database instance not available');
    }
} catch (Exception $e) {
    $error = $e->getMessage();
    require 'templates/maintenance.php';
    exit;
}

// Начинаем кэширование страницы
PageCache::start();

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$searchQuery = $_GET['q'] ?? '';
$searchField = $_GET['field'] ?? 'all';
$userIp = $_SERVER['REMOTE_ADDR'];

$itemsPerPage = Config::getItemsPerPage();

if (!empty($searchQuery)) {
    $books = $db->searchBooks($searchQuery, $searchField, $page);
    $totalBooks = $db->getSearchCount($searchQuery, $searchField);
} else {
    $books = $db->getRecentBooks($itemsPerPage, ($page - 1) * $itemsPerPage);
    $totalBooks = $db->getTotalBooksCount();
}

$totalPages = ceil($totalBooks / $itemsPerPage);


// Загружаем все данные одним пакетом
$bookIds = array_column($books, 'id');

// Загружаем рейтинги для всех книг одним запросом
$ratings = [];
if (!empty($bookIds)) {
    $ratings = $db->getRatingsForBooks($bookIds);
}

// Загружаем избранное для всех книг одним запросом
$userFavorites = [];
if (!empty($bookIds)) {
    $userFavorites = $db->getFavoritesForBooks($bookIds, $userIp);
}

require 'templates/header.php';
?>

<div class="row">
    <div class="col-md-3">

        <div class="shadow card search-form">
            <div class="card-body">
                <h5 class="card-title"><?php echo __('search'); ?></h5>
                <form method="get" action="index.php">
                    <div class="mb-3">
                        <input type="text" class="form-control" name="q" 
                               value="<?php echo htmlspecialchars($searchQuery); ?>" 
                               placeholder="<?php echo __('search_placeholder'); ?>">
                    </div>
                    <div class="mb-3">
                        <select class="form-select" name="field">
                            <option value="all" <?php echo $searchField === 'all' ? 'selected' : ''; ?>>
                                <?php echo __('search_all'); ?>
                            </option>
                            <option value="title" <?php echo $searchField === 'title' ? 'selected' : ''; ?>>
                                <?php echo __('search_title'); ?>
                            </option>
                            <option value="author" <?php echo $searchField === 'author' ? 'selected' : ''; ?>>
                                <?php echo __('search_author'); ?>
                            </option>
                            <option value="genre" <?php echo $searchField === 'genre' ? 'selected' : ''; ?>>
                                <?php echo __('search_genre'); ?>
                            </option>
                            <option value="series" <?php echo $searchField === 'series' ? 'selected' : ''; ?>>
                                <?php echo __('search_series'); ?>
                            </option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?php echo __('search_button'); ?></button>
                </form>
            </div>
        </div>

        <div class="shadow card mt-3">
            <div class="card-body">
                <h5 class="card-title"><?php echo __('quick_search'); ?></h5>
                <ul class="list-unstyled">
                    <li><a href="index.php?field=author&q="><?php echo __('by_authors'); ?></a></li>
                    <li><a href="index.php?field=genre&q="><?php echo __('by_genres'); ?></a></li>
                    <li><a href="index.php?field=series&q="><?php echo __('by_series'); ?></a></li>
                </ul>
            </div>
        </div>

        <div class="shadow card mt-3">
            <div class="card-body">
                <h5 class="card-title"><?php echo __('stats'); ?></h5>
            
	<?php
        // Кэшируем статистику на 1 час
        $stats = Cache::get('collection_stats_sidebar', 'statistics');

if ($stats === null) {
    try {
        $stats = $db->getCollectionStats();
        Cache::set('collection_stats_sidebar', $stats, 'statistics', 3600);
    } catch (Exception $e) {
        $stats = ['total_books' => 0, 'total_authors' => 0, 'total_genres' => 0];
        echo '<small class="text-muted">' . __('stats_unavailable') . '</small>';
    }
}

if (isset($stats['total_books'])): ?>
            <small class="text-muted">
                <?php echo __('stats_total_books'); ?> <?php echo number_format($stats['total_books'] ?? 0, 0, '', ' '); ?><br>
                <?php echo __('stats_total_authors'); ?> <?php echo number_format($stats['total_authors'] ?? 0, 0, '', ' '); ?><br>
                <?php echo __('stats_total_genres'); ?> <?php echo number_format($stats['total_genres'] ?? 0, 0, '', ' '); ?>
            </small>
        <?php endif; ?>

            </div>
        </div>
    </div>

    <div class="col-md-9">
        <h2>
            <?php if (!empty($searchQuery)): ?>
                <?php echo sprintf(__('search_results_for'), htmlspecialchars($searchQuery)); ?>
                <small class="text-muted">(<?php echo sprintf(__('search_found'), $totalBooks); ?>)</small>
            <?php else: ?>
                <?php echo __('recent_books'); ?>
            <?php endif; ?>
        </h2>

        <?php if (empty($books)): ?>
            <div class="alert alert-info">
                <?php if (!empty($searchQuery)): ?>
                    <?php echo sprintf(__('search_no_results'), htmlspecialchars($searchQuery)); ?>
                <?php else: ?>
                    <?php echo __('catalog_empty'); ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="row" id="books-container">
                <?php foreach ($books as $book):
                    $bookId = $book['id'];
                    $rating = $ratings[$bookId] ?? ['votes' => 0, 'average' => 0, 'average_rounded' => 0];
                    $isFavorite = isset($userFavorites[$bookId]);
                    ?>
                    
		    <div class="col-md-6 book-card mb-3">
                        
			<div class="shadow card h-100">
                            <div class="card-body">
                                <div class="row">
                                
			    <div class="col-4">
				<img src="./api/cover.php?id=<?php echo $bookId; ?>&thumb=1" 
        			    class="book-cover img-fluid" 
        			    alt="<?php echo __('book_cover'); ?>"
        			    style="width:100%; height:auto; max-height:150px; object-fit:contain;"
			             onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22100%25%22%20height%3D%22150%22%20viewBox%3D%220%200%20100%20150%22%3E%3Crect%20width%3D%22100%25%22%20height%3D%22100%25%22%20fill%3D%22%23f8f9fa%22%2F%3E%3Ctext%20x%3D%2250%25%22%20y%3D%2250%25%22%20text-anchor%3D%22middle%22%20dominant-baseline%3D%22middle%22%20fill%3D%22%236c757d%22%20font-size%3D%2212%22%3E<?php echo urlencode(__('book_no_cover')); ?>%3C%2Ftext%3E%3C%2Fsvg%3E';"
			             loading="lazy">
			    </div>                                    
                                    
                                    
                                    <div class="col-8">
                                        <h6 class="card-title">
                                            <a href="book_detail.php?id=<?php echo $bookId; ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($book['title'] ?: __('book_untitled')); ?>
                                            </a>
                                        </h6>
                                        
                                        <?php if ($book['author']): ?>
                                            <p class="card-text mb-1">
                                                <small class="text-muted">
                                                    <strong><?php echo __('book_author'); ?>:</strong>
                                                    <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($book['author']); ?>
                                                    </a>
                                                </small>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if ($book['series']): ?>
                                            <p class="card-text mb-1">
                                                <small>
                                                    <strong><?php echo __('book_series'); ?>:</strong>
                                                    <a href="index.php?field=series&q=<?php echo urlencode($book['series']); ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($book['series']); ?>
                                                    </a>
                                                    <?php if ($book['series_number']): ?>
                                                        <span class="badge bg-secondary ms-1">#<?php echo $book['series_number']; ?></span>
                                                    <?php endif; ?>
                                                </small>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($book['genre']): ?>
                                            <p class="card-text mb-1">
                                                <small>
                                                    <strong><?php echo __('book_genre'); ?>:</strong>
                                                    <?php
                                                        $readableGenre = $db->getReadableGenre($book['genre']);
                                            echo htmlspecialchars($readableGenre ?: $book['genre']);
                                            ?>
                                                </small>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($book['year']): ?>
                                            <p class="card-text mb-1">
                                                <small><strong><?php echo __('book_year'); ?>:</strong> <?php echo $book['year']; ?></small>
                                            </p>
                                        <?php endif; ?>

                                        <div class="d-flex justify-content-between align-items-center mt-2">
                                            <div class="book-rating-mini" id="rating-<?php echo $bookId; ?>" data-book-id="<?php echo $bookId; ?>">
                                                <?php if ($rating['votes'] > 0): ?>
                                                    <?php
                                            $r = $rating['average_rounded'];
                                                    for ($i = 1; $i <= 5; $i++):
                                                        if ($i <= floor($r)): ?>
                                                            <i class="fas fa-star text-warning" style="font-size: 0.8em;"></i>
                                                        <?php elseif ($i - 0.5 <= $r): ?>
                                                            <i class="fas fa-star-half-alt text-warning" style="font-size: 0.8em;"></i>
                                                        <?php else: ?>
                                                            <i class="far fa-star text-warning" style="font-size: 0.8em;"></i>
                                                        <?php endif;
                                                    endfor; ?>
                                                    <small class="text-muted"><?php echo number_format($rating['average'], 1); ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted"><?php echo __('rating_no_votes'); ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <button class="btn btn-sm <?php echo $isFavorite ? 'btn-danger' : 'btn-outline-danger'; ?> favorite-btn" 
                                                    data-book-id="<?php echo $bookId; ?>"
                                                    title="<?php echo $isFavorite ? __('favorites_remove') : __('favorites_add'); ?>">
                                                <i class="<?php echo $isFavorite ? 'fas' : 'far'; ?> fa-heart"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <?php echo __('book_added'); ?>: <?php echo date('d.m.Y', strtotime($book['added_date'])); ?>
                                        <?php if ($book['archive_path']): ?>
                                            • <?php echo __('book_in_archive'); ?>
                                        <?php endif; ?>
                                    </small>
                                    <div>
                                        <a href="book_detail.php?id=<?php echo $bookId; ?>" class="btn btn-sm btn-outline-primary"><?php echo __('details'); ?></a>
                                        <a href="./api/download.php?id=<?php echo $bookId; ?>" class="btn btn-sm btn-success"><?php echo __('download'); ?></a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav aria-label="<?php echo __('pagination'); ?>" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?q=<?php echo urlencode($searchQuery); ?>&field=<?php echo $searchField; ?>&page=<?php echo $page - 1; ?>">
                                    <?php echo __('back'); ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);

                for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?q=<?php echo urlencode($searchQuery); ?>&field=<?php echo $searchField; ?>&page=<?php echo $i; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?q=<?php echo urlencode($searchQuery); ?>&field=<?php echo $searchField; ?>&page=<?php echo $page + 1; ?>">
                                    <?php echo __('forward'); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                
                <div class="text-center text-muted">
                    <small><?php echo sprintf(__('page_of'), $page, $totalPages); ?></small>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
PageCache::save();
require 'templates/footer.php';
?>
