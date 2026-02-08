<?php
require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/Cache.php';
require_once 'lib/PageCache.php';

PageCache::start('stats_page');

$db = Database::getInstance();

// Получаем статистику
$stats = $db->getCollectionStats();
$genres = $db->getGenresWithCount();
$topAuthors = $db->getTopAuthors(20);
$randomBooks = $db->getRandomBooks(5);

// Получаем статистику рейтингов и избранного
try {
    // Статистика рейтингов
    $ratingStats = $db->getConnection()->query("
        SELECT
            COUNT(DISTINCT book_id) as rated_books,
            COUNT(*) as total_ratings,
            AVG(rating) as avg_rating,
            COUNT(DISTINCT user_ip) as unique_voters
        FROM book_ratings
    ")->fetch();

    // Статистика избранного
    $favoritesStats = $db->getConnection()->query("
        SELECT
            COUNT(DISTINCT book_id) as favorited_books,
            COUNT(*) as total_favorites,
            COUNT(DISTINCT user_ip) as users_with_favorites
        FROM book_favorites
    ")->fetch();

    // Топ книг по рейтингу
    $topRatedBooks = $db->getConnection()->query("
        SELECT b.id, b.title, b.author,
               AVG(r.rating) as avg_rating,
               COUNT(r.id) as votes
        FROM books b
        JOIN book_ratings r ON b.id = r.book_id
        GROUP BY b.id
        HAVING votes >= 3
        ORDER BY avg_rating DESC, votes DESC
        LIMIT 10
    ")->fetchAll();

} catch (Exception $e) {
    $ratingStats = ['rated_books' => 0, 'total_ratings' => 0, 'avg_rating' => 0, 'unique_voters' => 0];
    $favoritesStats = ['favorited_books' => 0, 'total_favorites' => 0, 'users_with_favorites' => 0];
    $topRatedBooks = [];
}

// Системная информация
$systemInfo = [
    'memory_usage' => memory_get_peak_usage(true),
    'memory_limit' => ini_get('memory_limit'),
    'php_version' => PHP_VERSION,
    'db_type' => Config::DB_TYPE,
    'apcu_enabled' => extension_loaded('apcu') && apcu_enabled(),
    'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
    'query_count' => $db->getQueryCount()
];

// Получаем статистику кэширования
$cacheStats = Cache::getStats();
$dbCacheStats = $db->getCacheStats();

require 'templates/header.php';
?>

<div class="container-fluid">
    <h1 class="mb-4">📊 Детальная статистика системы</h1>

    <!-- Основные метрики -->
    <div class="row mb-4">
        <!-- Книги -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                📚 Всего книг</div>
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

        <!-- Авторы -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                ✍️ Авторов</div>
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

        <!-- Рейтинги -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                ⭐ Оценок</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($ratingStats['total_ratings'], 0, '', ' '); ?>
                            </div>
                            <div class="mt-1">
                                <small class="text-muted">
                                    <?php echo number_format($ratingStats['rated_books'], 0, '', ' '); ?> книг оценено
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

        <!-- Избранное -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                ❤️ В избранном</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($favoritesStats['total_favorites'], 0, '', ' '); ?>
                            </div>
                            <div class="mt-1">
                                <small class="text-muted">
                                    <?php echo number_format($favoritesStats['favorited_books'], 0, '', ' '); ?> книг
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
        <!-- Левая колонка - Общая статистика -->
        <div class="col-lg-8">
            <!-- Статистика рейтингов -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-warning">⭐ Статистика рейтингов</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 text-center mb-3">
                            <div class="h2 text-warning mb-1"><?php echo number_format($ratingStats['avg_rating'], 1); ?></div>
                            <div class="text-muted">Средний рейтинг</div>
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
                        <div class="col-md-4 text-center mb-3">
                            <div class="h2 text-primary mb-1"><?php echo number_format($ratingStats['rated_books'], 0, '', ' '); ?></div>
                            <div class="text-muted">Оцененных книг</div>
                            <div class="mt-2">
                                <small><?php echo round($ratingStats['rated_books'] / max(1, $stats['total_books']) * 100, 1); ?>% от всех книг</small>
                            </div>
                        </div>
                        <div class="col-md-4 text-center mb-3">
                            <div class="h2 text-success mb-1"><?php echo number_format($ratingStats['unique_voters'], 0, '', ' '); ?></div>
                            <div class="text-muted">Уникальных оценщиков</div>
                            <div class="mt-2">
                                <small>по IP-адресам</small>
                            </div>
                        </div>
                    </div>

                    <!-- Топ книг по рейтингу -->
                    <?php if (!empty($topRatedBooks)): ?>
                    <div class="mt-4">
                        <h6 class="font-weight-bold">🏆 Топ книг по рейтингу:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Книга</th>
                                        <th>Автор</th>
                                        <th>Рейтинг</th>
                                        <th>Оценок</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topRatedBooks as $index => $book): ?>
                                    <tr>
                                        <td>
                                            <a href="book_detail.php?id=<?php echo $book['id']; ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars(mb_substr($book['title'], 0, 30)) . (mb_strlen($book['title']) > 30 ? '...' : ''); ?>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars(mb_substr($book['author'], 0, 20)); ?></td>
                                        <td>
                                            <span class="text-warning">
                                                <?php echo number_format($book['avg_rating'], 1); ?>
                                            </span>
                                            <small class="text-muted">
                                                <?php
                                                $fullStars = floor($book['avg_rating']);
                                                $halfStar = $book['avg_rating'] - $fullStars >= 0.5;
                                                for ($i = 0; $i < $fullStars; $i++): ?>
                                                    <i class="fas fa-star text-warning" style="font-size: 0.8em;"></i>
                                                <?php endfor; ?>
                                                <?php if ($halfStar): ?>
                                                    <i class="fas fa-star-half-alt text-warning" style="font-size: 0.8em;"></i>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td><?php echo $book['votes']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Статистика избранного -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-danger">❤️ Статистика избранного</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 text-center mb-3">
                            <div class="h2 text-danger mb-1"><?php echo number_format($favoritesStats['total_favorites'], 0, '', ' '); ?></div>
                            <div class="text-muted">Всего добавлений</div>
                        </div>
                        <div class="col-md-4 text-center mb-3">
                            <div class="h2 text-info mb-1"><?php echo number_format($favoritesStats['favorited_books'], 0, '', ' '); ?></div>
                            <div class="text-muted">Уникальных книг</div>
                            <div class="mt-2">
                                <small><?php echo round($favoritesStats['favorited_books'] / max(1, $stats['total_books']) * 100, 1); ?>% от всех книг</small>
                            </div>
                        </div>
                        <div class="col-md-4 text-center mb-3">
                            <div class="h2 text-success mb-1"><?php echo number_format($favoritesStats['users_with_favorites'], 0, '', ' '); ?></div>
                            <div class="text-muted">Пользователей</div>
                            <div class="mt-2">
                                <small>используют избранное</small>
                            </div>
                        </div>
                    </div>

                    <!-- Популярные книги в избранном -->
                    <?php
                    try {
                        $popularFavorites = $db->getConnection()->query("
                            SELECT b.id, b.title, b.author, COUNT(f.id) as favorites_count
                            FROM books b
                            JOIN book_favorites f ON b.id = f.book_id
                            GROUP BY b.id
                            ORDER BY favorites_count DESC
                            LIMIT 10
                        ")->fetchAll();
                    } catch (Exception $e) {
                        $popularFavorites = [];
                    }
                    ?>

                    <?php if (!empty($popularFavorites)): ?>
                    <div class="mt-4">
                        <h6 class="font-weight-bold">🔥 Самые популярные в избранном:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Книга</th>
                                        <th>Автор</th>
                                        <th>В избранном</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($popularFavorites as $index => $book): ?>
                                    <tr>
                                        <td>
                                            <a href="book_detail.php?id=<?php echo $book['id']; ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars(mb_substr($book['title'], 0, 30)) . (mb_strlen($book['title']) > 30 ? '...' : ''); ?>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars(mb_substr($book['author'], 0, 20)); ?></td>
                                        <td>
                                            <span class="text-danger">
                                                <i class="fas fa-heart"></i> <?php echo $book['favorites_count']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Распределение по форматам -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">📁 Распределение по форматам</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="thead-light">
                                <tr>
                                    <th>Формат</th>
                                    <th>Количество</th>
                                    <th>Процент</th>
                                    <th style="width: 40%;">Прогресс</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $totalBooks = $stats['total_books'];
                                foreach ($stats['file_types'] as $fileType):
                                    $percentage = $totalBooks > 0 ? round(($fileType['count'] / $totalBooks) * 100, 1) : 0;
                                    $progressWidth = min($percentage, 100);
                                    $progressClass = $percentage > 50 ? 'bg-success' : ($percentage > 20 ? 'bg-info' : 'bg-warning');
                                ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo strtoupper($fileType['file_type']); ?></span>
                                    </td>
                                    <td class="font-weight-bold"><?php echo number_format($fileType['count'], 0, '', ' '); ?></td>
                                    <td>
                                        <span class="badge <?php echo $progressClass; ?>"><?php echo $percentage; ?>%</span>
                                    </td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar <?php echo $progressClass; ?>"
                                                 role="progressbar"
                                                 style="width: <?php echo $progressWidth; ?>%"
                                                 aria-valuenow="<?php echo $progressWidth; ?>"
                                                 aria-valuemin="0"
                                                 aria-valuemax="100">
                                                <?php if ($progressWidth > 20): ?>
                                                    <?php echo $percentage; ?>%
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Статистика по жанрам -->
           <div class="card shadow mb-4">

                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-tags me-2"></i>
                        Распределение по жанрам (Топ-20)
                    </h6>
                </div>

                <div class="card-body">
                    <div class="row">
                        <?php foreach ($topAuthors as $index => $author): ?>
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
                                            <small class="text-muted ms-1">книг</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>


    <!-- Распределение по жанрам -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">👑 Топ-20 авторов</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php
                        $counter = 0;
                        $totalGenres = count($genres);
                        $columns = 3;
                        $genresPerColumn = ceil($totalGenres / $columns);

                        for ($col = 0; $col < $columns; $col++):
                            $start = $col * $genresPerColumn;
                            $end = min($start + $genresPerColumn, $totalGenres);
                            $columnGenres = array_slice($genres, $start, $end - $start);
                        ?>
                        <div class="col-md-4">
                            <?php foreach ($columnGenres as $genre): ?>
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
                            <?php endforeach; ?>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>


        </div>

        <!-- Правая колонка - Дополнительная информация -->
        <div class="col-lg-4">
            <!-- Системная информация -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">⚙️ Системная информация</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <h6 class="font-weight-bold text-dark mb-2">📊 Производительность</h6>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small>Использовано памяти</small>
                                <span class="badge bg-info">
                                    <?php echo round($systemInfo['memory_usage'] / 1024 / 1024, 2); ?>MB
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small>Время выполнения</small>
                                <span class="badge bg-secondary">
                                    <?php echo round($systemInfo['execution_time'], 3); ?>s
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small>Запросов к БД</small>
                                <span class="badge bg-dark"><?php echo $systemInfo['query_count']; ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <h6 class="font-weight-bold text-dark mb-2">🔧 Конфигурация</h6>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small>PHP версия</small>
                                <span class="badge bg-secondary"><?php echo $systemInfo['php_version']; ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small>Тип БД</small>
                                <span class="badge bg-info"><?php echo strtoupper($systemInfo['db_type']); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small>Кэширование</small>
                                <span class="badge <?php echo Config::ENABLE_CACHE ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo Config::ENABLE_CACHE ? '✅ Вкл' : '❌ Выкл'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Быстрые ссылки -->
                    <div class="mt-4">
                        <h6 class="font-weight-bold text-dark mb-2">🚀 Быстрые действия</h6>
                        <div class="d-grid gap-2">
                            <a href="favorites.php" class="btn btn-outline-danger">
                                <i class="fas fa-heart me-2"></i>Мои избранные
                            </a>
                            <a href="top_rated.php" class="btn btn-outline-warning">
                                <i class="fas fa-star me-2"></i>Лучшие книги
                            </a>
                            <?php if (Config::ENABLE_CACHE): ?>
                            <a href="cache_stats.php" class="btn btn-outline-info">
                                <i class="fas fa-bolt me-2"></i>Статистика кэша
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Случайные книги -->
            <?php if (!empty($randomBooks)): ?>
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">🎲 Случайные книги</h6>
                </div>
                <div class="card-body">
                    <?php foreach ($randomBooks as $book): ?>
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <?php
                                require_once 'lib/BookHelper.php';
                                $hasCover = BookHelper::hasCover($book);
                                ?>
                                <?php if ($hasCover): ?>
                                    <img src="./api/cover_direct.php?id=<?php echo $book['id']; ?>&thumb=1"
                                         class="rounded"
                                         style="width: 50px; height: 75px; object-fit: cover;"
                                         alt="<?php echo htmlspecialchars($book['title']); ?>">
                                <?php else: ?>
                                    <div class="bg-light d-flex align-items-center justify-content-center rounded"
                                         style="width: 50px; height: 75px;">
                                        <i class="fas fa-book text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <a href="book_detail.php?id=<?php echo $book['id']; ?>"
                                   class="text-decoration-none">
                                    <small class="d-block font-weight-bold text-dark mb-1">
                                        <?php echo htmlspecialchars(mb_substr($book['title'] ?: 'Без названия', 0, 30)); ?>
                                    </small>
                                </a>
                                <?php if (!empty($book['author'])): ?>
                                <small class="text-muted d-block">
                                    <?php echo htmlspecialchars(mb_substr($book['author'], 0, 25)); ?>
                                </small>
                                <?php endif; ?>
                                <div class="mt-1">
                                    <a href="book_detail.php?id=<?php echo $book['id']; ?>"
                                       class="btn btn-sm btn-outline-primary btn-sm">
                                        <i class="fas fa-eye me-1"></i>Смотреть
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Время генерации -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="alert alert-light border text-center">
                <small class="text-muted">
                    <i class="fas fa-clock me-1"></i>
                    Страница сгенерирована за <?php echo round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3); ?> сек.
                    | Запросов к БД: <?php echo $db->getQueryCount(); ?>
                    <?php if (Config::PERFORMANCE['enable_page_cache']): ?>
                    | Кэширование страниц: ✅ Включено
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
</div>

<style>
.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    border-radius: 10px;
}

.card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.1) !important;
}

.border-left-primary { border-left: 0.25rem solid #4e73df !important; }
.border-left-success { border-left: 0.25rem solid #1cc88a !important; }
.border-left-warning { border-left: 0.25rem solid #f6c23e !important; }
.border-left-danger { border-left: 0.25rem solid #dc3545 !important; }
.border-left-info { border-left: 0.25rem solid #36b9cc !important; }

.progress {
    border-radius: 10px;
}

.progress-bar {
    border-radius: 10px;
    transition: width 0.6s ease;
}

.badge {
    font-size: 0.75em;
    font-weight: 500;
}

.list-group-item {
    background: transparent;
}

.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,0.02);
}

.text-xs {
    font-size: 0.7rem;
}

.font-weight-bold {
    font-weight: 600 !important;
}
</style>

<?php
PageCache::save();
require 'templates/footer.php';
?>
