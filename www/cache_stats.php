<?php
require_once 'config/config.php';
require_once 'lib/Cache.php';
require_once 'lib/Database.php';

$db = Database::getInstance();
$cacheStats = Cache::getStats();
$dbStats = $db->getCollectionStats();
$dbCacheStats = $db->getCacheStats();

require 'templates/header.php';
?>

<div class="container">
    <h1>Статистика кэширования</h1>
    
    <div class="row">
        <!-- Статистика APCu -->
        <?php if (isset($cacheStats['apcu'])): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">APCu Cache</h5>
                </div>
                <div class="card-body">
                    <?php $s = $cacheStats['apcu']; ?>
                    <p>Попадания: <strong><?php echo number_format($s['hits']); ?></strong></p>
                    <p>Промахи: <strong><?php echo number_format($s['misses']); ?></strong></p>
                    <p>Эффективность: <strong><?php echo $s['effectiveness']; ?>%</strong></p>
                    <p>Записей: <strong><?php echo $s['entries']; ?></strong></p>
                    <p>Память: <strong><?php echo round($s['memory_usage'] / 1024 / 1024, 2); ?> MB</strong></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Статистика кэша БД -->
        <?php if (!empty($dbCacheStats)): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Database Cache</h5>
                </div>
                <div class="card-body">
                    <?php $s = $dbCacheStats; ?>
                    <p>Попадания: <strong><?php echo number_format($s['hits']); ?></strong></p>
                    <p>Промахи: <strong><?php echo number_format($s['misses']); ?></strong></p>
                    <p>Эффективность: <strong><?php echo $s['effectiveness']; ?>%</strong></p>
                    <p>Запросов к БД: <strong><?php echo $db->getQueryCount(); ?></strong></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Информация о системе -->
    <div class="row mt-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Информация о системе</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <p><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></p>
                            <p><strong>APCu Enabled:</strong> <?php echo extension_loaded('apcu') && apcu_enabled() ? '✅ Да' : '❌ Нет'; ?></p>
                        </div>
                        <div class="col-md-4">
                            <p><strong>Memory Limit:</strong> <?php echo ini_get('memory_limit'); ?></p>
                            <p><strong>Max Execution Time:</strong> <?php echo ini_get('max_execution_time'); ?>s</p>
                        </div>
                        <div class="col-md-4">
                            <p><strong>Кэширование:</strong> <?php echo Config::ENABLE_CACHE ? '✅ Включено' : '❌ Выключено'; ?></p>
                            <p><strong>Кэш страниц:</strong> <?php echo Config::PERFORMANCE['enable_page_cache'] ? '✅ Включено' : '❌ Выключено'; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="mt-3">
        <a href="?action=clear_cache" class="btn btn-warning" onclick="return confirm('Очистить весь кэш? Это может замедлить работу сайта на время перестроения кэша.')">Очистить кэш</a>
        <a href="index.php" class="btn btn-secondary">На главную</a>
        <a href="stats.php" class="btn btn-info">Общая статистика</a>
    </div>
    
    <?php
    if (isset($_GET['action']) && $_GET['action'] === 'clear_cache') {
        Cache::clear();
        if (method_exists($db, 'clearCache')) {
            $db->clearCache();
        }
        echo '<div class="alert alert-success mt-3">Кэш очищен! Страница будет перезагружена...</div>';
        echo '<script>setTimeout(function() { location.href = "cache_stats.php"; }, 1000);</script>';
    }
    ?>
</div>

<?php require 'templates/footer.php'; ?>