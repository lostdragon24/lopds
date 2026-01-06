<?php

require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/Cache.php';
require_once 'lib/PageCache.php';

// Начинаем кэширование страницы на 5 минут
PageCache::start('stats_page');

$db = Database::getInstance();

// Получаем все данные с кэшированием
$stats = $db->getCollectionStats();
$genres = $db->getGenresWithCount();
$topAuthors = $db->getTopAuthors(20);
$topSeries = $db->getTopSeries(20);
$randomBooks = $db->getRandomBooks(5);

// Получаем статистику кэширования
$cacheStats = Cache::getStats();
$dbCacheStats = $db->getCacheStats();

// Системная информация
$systemInfo = [
    'load' => sys_getloadavg(),
    'memory_usage' => memory_get_peak_usage(true),
    'memory_limit' => ini_get('memory_limit'),
    'php_version' => PHP_VERSION,
    'db_type' => Config::DB_TYPE,
    'apcu_enabled' => extension_loaded('apcu') && apcu_enabled(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
    'query_count' => $db->getQueryCount()
];

// Обработка действий
$action = $_GET['action'] ?? '';
$message = '';

if ($action === 'clear_cache' && Config::ENABLE_CACHE) {
    try {
        Cache::clear();
        if (method_exists($db, 'clearCache')) {
            $db->clearCache();
        }
        
        // Создаем новый ключ для страницы, чтобы избежать показа кэшированной версии
        $message = 'success:Кэш успешно очищен! Страница будет перезагружена...';
        
        // Перенаправляем с задержкой
        echo '<script>
            setTimeout(function() {
                window.location.href = "stats.php?message=' . urlencode($message) . '";
            }, 1000);
        </script>';
        exit;
        
    } catch (Exception $e) {
        $message = 'danger:Ошибка при очистке кэша: ' . $e->getMessage();
    }
}

if ($action === 'optimize_db') {
    try {
        // Здесь можно добавить оптимизацию БД если нужно
        $message = 'success:Операция выполнена!';
    } catch (Exception $e) {
        $message = 'danger:Ошибка: ' . $e->getMessage();
    }
}

// Проверяем сообщение из GET параметра
if (isset($_GET['message'])) {
    $message = $_GET['message'];
}

require 'templates/header.php';
?>

<div class="container-fluid">
    <h1 class="mb-4">📊 Детальная статистика системы</h1>
    
    <!-- Уведомления -->
    <?php if ($message): ?>
    <div class="row mb-4">
        <div class="col-12">
            <?php 
            list($type, $text) = explode(':', $message, 2);
            $alertClass = [
                'success' => 'alert-success',
                'danger' => 'alert-danger',
                'warning' => 'alert-warning',
                'info' => 'alert-info'
            ][$type] ?? 'alert-info';
            ?>
            <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($text); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Панель управления -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="card-title mb-0">⚙️ Управление системой</h5>
                            <p class="card-text text-muted mb-0">Мониторинг и обслуживание библиотеки</p>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="btn-group" role="group">
                                <?php if (Config::ENABLE_CACHE): ?>
                                <a href="?action=clear_cache" class="btn btn-warning" 
                                   onclick="return confirm('Очистить весь кэш? Это может временно замедлить работу.')">
                                    🗑️ Очистить кэш
                                </a>
                                <?php endif; ?>
                                <a href="cache_stats.php" class="btn btn-outline-primary">
                                    📈 Статистика кэша
                                </a>
                                <a href="index.php" class="btn btn-outline-secondary">
                                    🏠 На главную
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Основные метрики -->
    <div class="row mb-4">
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

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                🏷️ Жанров</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_genres'], 0, '', ' '); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-tags fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                📖 Серий</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_series'], 0, '', ' '); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-layer-group fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Левая колонка - Статистика -->
        <div class="col-lg-8">
            <!-- Распределение по форматам -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
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

            <!-- Топ авторов -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">👑 Топ-20 авторов</h6>
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
        </div>

        <!-- Правая колонка - Системная информация -->
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
                                <small>Нагрузка системы</small>
                                <span class="badge <?php echo $systemInfo['load'][0] > 2 ? 'bg-danger' : ($systemInfo['load'][0] > 1 ? 'bg-warning' : 'bg-success'); ?>">
                                    <?php echo round($systemInfo['load'][0], 2); ?>
                                </span>
                            </div>
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
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-0">
                                <small>APCu</small>
                                <span class="badge <?php echo $systemInfo['apcu_enabled'] ? 'bg-success' : 'bg-danger'; ?>">
                                    <?php echo $systemInfo['apcu_enabled'] ? '✅ Доступен' : '❌ Недоступен'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <?php if ($stats['books_in_archives'] > 0): ?>
                    <div class="mb-3">
                        <h6 class="font-weight-bold text-dark mb-2">📦 Архивы</h6>
                        <div class="alert alert-info mb-0">
                            <small>
                                <i class="fas fa-archive me-1"></i>
                                Книг в архивах: <strong><?php echo number_format($stats['books_in_archives'], 0, '', ' '); ?></strong>
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($stats['last_update']): ?>
                    <div>
                        <h6 class="font-weight-bold text-dark mb-2">🕒 Обновление</h6>
                        <div class="alert alert-light border mb-0">
                            <small>
                                <i class="fas fa-clock me-1"></i>
                                Последнее обновление: 
                                <strong><?php echo date('d.m.Y H:i', strtotime($stats['last_update'])); ?></strong>
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Статистика кэширования -->
            <?php if (Config::ENABLE_CACHE && !empty($cacheStats)): ?>
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">📈 Кэширование</h6>
                </div>
                <div class="card-body">
                    <?php if (isset($cacheStats['apcu'])): ?>
                    <div class="mb-3">
                        <h6 class="font-weight-bold text-dark mb-2">APCu Cache</h6>
                        <div class="row">
                            <div class="col-6 mb-2">
                                <small class="text-muted d-block">Попадания</small>
                                <span class="h6"><?php echo number_format($cacheStats['apcu']['hits']); ?></span>
                            </div>
                            <div class="col-6 mb-2">
                                <small class="text-muted d-block">Промахи</small>
                                <span class="h6"><?php echo number_format($cacheStats['apcu']['misses']); ?></span>
                            </div>
                            <div class="col-6 mb-2">
                                <small class="text-muted d-block">Эффективность</small>
                                <span class="h6 text-<?php echo $cacheStats['apcu']['effectiveness'] > 70 ? 'success' : ($cacheStats['apcu']['effectiveness'] > 40 ? 'warning' : 'danger'); ?>">
                                    <?php echo $cacheStats['apcu']['effectiveness']; ?>%
                                </span>
                            </div>
                            <div class="col-6 mb-2">
                                <small class="text-muted d-block">Записей</small>
                                <span class="h6"><?php echo number_format($cacheStats['apcu']['entries']); ?></span>
                            </div>
                            <div class="col-12">
                                <small class="text-muted d-block">Память</small>
                                <div class="progress" style="height: 10px;">
                                    <?php 
                                    $memoryPercent = $cacheStats['apcu']['memory_usage'] > 0 ? 
                                        min(100, ($cacheStats['apcu']['memory_usage'] / (128 * 1024 * 1024)) * 100) : 0;
                                    $memoryClass = $memoryPercent > 80 ? 'bg-danger' : ($memoryPercent > 60 ? 'bg-warning' : 'bg-success');
                                    ?>
                                    <div class="progress-bar <?php echo $memoryClass; ?>" 
                                         style="width: <?php echo $memoryPercent; ?>%">
                                    </div>
                                </div>
                                <small class="text-muted">
                                    <?php echo round($cacheStats['apcu']['memory_usage'] / 1024 / 1024, 2); ?>MB / 128MB
                                </small>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($dbCacheStats)): ?>
                    <div>
                        <h6 class="font-weight-bold text-dark mb-2">Database Cache</h6>
                        <div class="row">
                            <div class="col-6">
                                <small class="text-muted d-block">Попадания</small>
                                <span class="h6"><?php echo number_format($dbCacheStats['hits']); ?></span>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block">Промахи</small>
                                <span class="h6"><?php echo number_format($dbCacheStats['misses']); ?></span>
                            </div>
                            <div class="col-12 mt-2">
                                <small class="text-muted d-block">Эффективность</small>
                                <span class="h6 text-<?php echo $dbCacheStats['effectiveness'] > 70 ? 'success' : ($dbCacheStats['effectiveness'] > 40 ? 'warning' : 'danger'); ?>">
                                    <?php echo $dbCacheStats['effectiveness']; ?>%
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

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
                                <img src="./api/cover_direct.php?id=<?php echo $book['id']; ?>&thumb=1" 
                                     class="rounded" 
                                     style="width: 50px; height: 75px; object-fit: cover;"
                                     onerror="this.style.display='none'"
                                     alt="<?php echo htmlspecialchars($book['title']); ?>">
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <a href="book_detail.php?id=<?php echo $book['id']; ?>" 
                                   class="text-decoration-none">
                                    <small class="d-block font-weight-bold text-dark mb-1">
                                        <?php echo htmlspecialchars($book['title'] ?: 'Без названия'); ?>
                                    </small>
                                </a>
                                <?php if (!empty($book['author'])): ?>
                                <small class="text-muted d-block">
                                    <?php echo htmlspecialchars($book['author']); ?>
                                </small>
                                <?php endif; ?>
                                <?php if (!empty($book['series'])): ?>
                                <small class="text-muted d-block">
                                    <i class="fas fa-bookmark me-1"></i>
                                    <?php echo htmlspecialchars($book['series']); ?>
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Распределение по жанрам -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">🏷️ Распределение по жанрам (Топ-100)</h6>
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

<!-- Стили -->
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

<!-- Скрипты -->
<script>
// Автообновление страницы каждые 5 минут
setTimeout(function() {
    window.location.reload();
}, 300000);

// Подсветка эффективных показателей
document.addEventListener('DOMContentLoaded', function() {
    // Анимация для прогресс-баров
    const progressBars = document.querySelectorAll('.progress-bar');
    progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0';
        setTimeout(() => {
            bar.style.width = width;
        }, 100);
    });
    
    // Плавная прокрутка к якорям
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
});

// Копирование статистики
function copyStats() {
    const statsText = `Статистика библиотеки:
📚 Книг: ${<?php echo $stats['total_books']; ?>}
✍️ Авторов: ${<?php echo $stats['total_authors']; ?>}
🏷️ Жанров: ${<?php echo $stats['total_genres']; ?>}
📖 Серий: ${<?php echo $stats['total_series']; ?>}
🕒 Обновлено: ${'<?php echo date('d.m.Y H:i', strtotime($stats['last_update'])); ?>'}`;
    
    navigator.clipboard.writeText(statsText).then(() => {
        alert('Статистика скопирована в буфер обмена!');
    });
}
</script>

<?php
// Сохраняем страницу в кэш
PageCache::save();
require 'templates/footer.php';
?>