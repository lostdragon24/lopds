<?php
// cache_stats.php

define('LOPDS_ROOT', __DIR__);

require_once 'config/config.php';
require_once 'lib/Cache.php';
require_once 'lib/Database.php';
require_once 'init.php';

$db = Database::getInstance();
$cacheStats = Cache::getStats();
$dbStats = $db->getCollectionStats();
$dbCacheStats = $db->getCacheStats();

require 'templates/header.php';
?>

<div class="container">
    <h1 class="mb-4">
        <i class="fas fa-bolt me-2"></i>
        <?php echo __('cache_title'); ?>
    </h1>
    
    <div class="row">
        <!-- Статистика APCu -->
        <?php if (isset($cacheStats['apcu'])) { ?>
        <div class="col-md-6 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-bolt me-2"></i>
                        <?php echo __('cache_apcu'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php $s = $cacheStats['apcu']; ?>
                    <table class="table table-borderless">
                        <tr>
                            <th><?php echo __('cache_hits'); ?></th>
                            <td class="text-end">
                                <span class="badge bg-success fs-6"><?php echo number_format($s['hits']); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo __('cache_misses'); ?></th>
                            <td class="text-end">
                                <span class="badge bg-warning fs-6"><?php echo number_format($s['misses']); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo __('cache_efficiency'); ?></th>
                            <td class="text-end">
                                <div class="progress" style="height: 25px;">
                                    <div class="progress-bar bg-success" 
                                         role="progressbar" 
                                         style="width: <?php echo $s['effectiveness']; ?>%;"
                                         aria-valuenow="<?php echo $s['effectiveness']; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                        <?php echo $s['effectiveness']; ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo __('cache_entries'); ?></th>
                            <td class="text-end">
                                <span class="badge bg-info fs-6"><?php echo number_format($s['entries']); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo __('cache_memory'); ?></th>
                            <td class="text-end">
                                <span class="badge bg-secondary fs-6">
                                    <?php echo round($s['memory_usage'] / 1024 / 1024, 2); ?> MB
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <?php } ?>
        
        <!-- Статистика кэша БД -->
        <?php if (!empty($dbCacheStats)) { ?>
        <div class="col-md-6 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-database me-2"></i>
                        <?php echo __('cache_db'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php $s = $dbCacheStats; ?>
                    <table class="table table-borderless">
                        <tr>
                            <th><?php echo __('cache_hits'); ?></th>
                            <td class="text-end">
                                <span class="badge bg-success fs-6"><?php echo number_format($s['hits']); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo __('cache_misses'); ?></th>
                            <td class="text-end">
                                <span class="badge bg-warning fs-6"><?php echo number_format($s['misses']); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo __('cache_efficiency'); ?></th>
                            <td class="text-end">
                                <div class="progress" style="height: 25px;">
                                    <div class="progress-bar bg-success" 
                                         role="progressbar" 
                                         style="width: <?php echo $s['effectiveness']; ?>%;"
                                         aria-valuenow="<?php echo $s['effectiveness']; ?>" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                        <?php echo $s['effectiveness']; ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo __('cache_queries'); ?></th>
                            <td class="text-end">
                                <span class="badge bg-info fs-6"><?php echo $db->getQueryCount(); ?></span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <?php } ?>
    </div>
    
    <!-- Информация о системе -->
    <div class="row mt-3">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <?php echo __('cache_system_info'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <table class="table table-sm">
                                <tr>
                                    <th><?php echo __('cache_php_version'); ?></th>
                                    <td><code><?php echo PHP_VERSION; ?></code></td>
                                </tr>
                                <tr>
                                    <th><?php echo __('cache_apcu_enabled'); ?></th>
                                    <td>
                                        <?php if (extension_loaded('apcu') && apcu_enabled()) { ?>
                                            <span class="badge bg-success"><?php echo __('yes'); ?></span>
                                        <?php } else { ?>
                                            <span class="badge bg-danger"><?php echo __('no'); ?></span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-4">
                            <table class="table table-sm">
                                <tr>
                                    <th><?php echo __('cache_memory_limit'); ?></th>
                                    <td><code><?php echo ini_get('memory_limit'); ?></code></td>
                                </tr>
                                <tr>
                                    <th><?php echo __('cache_max_exec_time'); ?></th>
                                    <td><code><?php echo ini_get('max_execution_time'); ?>s</code></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-4">
                            <table class="table table-sm">
                                <tr>
                                    <th><?php echo __('cache_enabled'); ?></th>
                                    <td>
                                        <?php if (Config::ENABLE_CACHE) { ?>
                                            <span class="badge bg-success"><?php echo __('yes'); ?></span>
                                        <?php } else { ?>
                                            <span class="badge bg-danger"><?php echo __('no'); ?></span>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php echo __('cache_page_cache'); ?></th>
                                    <td>
                                        <?php if (Config::PERFORMANCE['enable_page_cache']) { ?>
                                            <span class="badge bg-success"><?php echo __('yes'); ?></span>
                                        <?php } else { ?>
                                            <span class="badge bg-danger"><?php echo __('no'); ?></span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Кнопки действий -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <a href="?action=clear_cache" 
                               class="btn btn-warning" 
                               onclick="return confirm('<?php echo __('cache_clear_confirm'); ?>')">
                                <i class="fas fa-trash-alt me-2"></i>
                                <?php echo __('cache_clear_btn'); ?>
                            </a>
                            <a href="index.php" class="btn btn-secondary ms-2">
                                <i class="fas fa-home me-2"></i>
                                <?php echo __('home'); ?>
                            </a>
                            <a href="stats.php" class="btn btn-info ms-2">
                                <i class="fas fa-chart-bar me-2"></i>
                                <?php echo __('stats'); ?>
                            </a>
                        </div>
                        <div>
                            <button class="btn btn-outline-secondary" onclick="location.reload()">
                                <i class="fas fa-sync-alt me-2"></i>
                                <?php echo __('refresh'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    if (isset($_GET['action']) && 'clear_cache' === $_GET['action']) {
        Cache::clear();
        if (method_exists($db, 'clearCache')) {
            $db->clearCache();
        }
        echo '<div class="alert alert-success mt-3">';
        echo '<i class="fas fa-check-circle me-2"></i>';
        echo __('cache_cleared');
        echo '</div>';
        echo '<script>setTimeout(function() { location.href = "cache_stats.php"; }, 1000);</script>';
    }
?>
</div>

<!-- Дополнительная информация о кэше -->
<?php if (isset($_GET['debug'])) { ?>
<div class="container mt-4">
    <div class="card">
        <div class="card-header bg-warning">
            <h5 class="mb-0">
                <i class="fas fa-bug me-2"></i>
                <?php echo __('debug_info'); ?>
            </h5>
        </div>
        <div class="card-body">
            <pre class="bg-light p-3 rounded" style="max-height: 400px; overflow: auto;"><?php
            echo "=== CACHE STATS ===\n";
    print_r($cacheStats);
    echo "\n=== DB CACHE STATS ===\n";
    print_r($dbCacheStats);
    echo "\n=== DB STATS ===\n";
    print_r($dbStats);
    echo "\n=== CONFIG ===\n";
    echo 'ENABLE_CACHE: '.(Config::ENABLE_CACHE ? 'true' : 'false')."\n";
    echo 'USE_APCU: '.(Config::USE_APCU ? 'true' : 'false')."\n";
    echo 'CACHE_TTL: '.Config::CACHE_TTL."\n";
    echo 'PAGE_CACHE_ENABLED: '.(Config::PERFORMANCE['enable_page_cache'] ? 'true' : 'false')."\n";
    ?></pre>
        </div>
    </div>
</div>
<?php } ?>

<style>
.table th {
    width: 60%;
    font-weight: 500;
    color: #495057;
}
.table td {
    width: 40%;
}
.progress {
    border-radius: 12px;
    overflow: hidden;
}
.progress-bar {
    transition: width 0.6s ease;
    font-weight: 600;
}
.badge {
    font-size: 0.9rem;
    padding: 0.5rem 0.75rem;
}
.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important;
}
@media (max-width: 768px) {
    .table th {
        width: 50%;
    }
    .table td {
        width: 50%;
    }
    .btn {
        width: 100%;
        margin: 5px 0 !important;
    }
    .d-flex {
        flex-direction: column;
    }
}
</style>

<?php require 'templates/footer.php'; ?>
