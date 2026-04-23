<?php
// templates/footer.php
?>

    </div> <!-- Закрытие контейнера из header.php -->
</div> <!-- Закрытие основного контента (открыт в header.php после nav) -->

<!-- Футер -->
<footer class="footer mt-auto py-4 bg-dark text-white">
    <div class="container">
        <div class="row">
            <!-- Левая колонка - Информация о сайте -->
            <div class="col-lg-6 mb-4 mb-lg-0">
                <div class="d-flex align-items-start">
                    <div class="me-3">
                        <i class="fas fa-book fa-2x text-primary"></i>
                    </div>
                    <div>
                        <h5 class="mb-2">

<?php echo htmlspecialchars(Config::getSiteTitle()); ?>


</h5>
                        <p class="text-light mb-3" style="opacity: 0.8;">
                            <?php echo __('footer_tagline'); ?>
                        </p>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="./api/opds.php" class="btn btn-sm btn-outline-light" target="_blank">
                                <i class="fas fa-rss me-1"></i>OPDS
                            </a>
                            <a href="stats.php" class="btn btn-sm btn-outline-light">
                                <i class="fas fa-chart-bar me-1"></i><?php echo __('stats'); ?>
                            </a>
                            <a href="top_rated.php" class="btn btn-sm btn-outline-light">
                                <i class="fas fa-star me-1"></i><?php echo __('top_rated'); ?>
                            </a>
                            <a href="favorites.php" class="btn btn-sm btn-outline-light">
                                <i class="far fa-heart me-1"></i><?php echo __('favorites'); ?>
                            </a>
                            <?php if (Config::isCacheEnabled()): ?>
                            <a href="cache_stats.php" class="btn btn-sm btn-outline-warning">
                                <i class="fas fa-bolt me-1"></i><?php echo __('cache'); ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Правая колонка - Статистика -->
            <div class="col-lg-6">
                <?php
                try {
                    // Используем кэшированное значение
                    $cacheKey = 'footer_stats';
                    $cached = Cache::get($cacheKey);

                    if ($cached !== null) {
                        $totalBooks = $cached;
                    } else {
                        $stats = $db->getCollectionStats();
                        $totalBooks = $stats['total_books'] ?? 0;
                        Cache::set($cacheKey, $totalBooks, 'statistics', 3600);
                    }

                    // Производительность
                    $memory_usage = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
                    $execution_time = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3);

                } catch (Exception $e) {
                    $totalBooks = 0;
                    $memory_usage = 0;
                    $execution_time = 0;
                }
?>

                <div class="card bg-dark bg-opacity-25 border-light">
                    <div class="card-body p-3">
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="mb-2">
                                    <i class="fas fa-book fa-lg text-primary"></i>
                                </div>
                                <div class="h5 mb-0 text-white"><?php echo number_format($totalBooks, 0, '', ' '); ?></div>
                                <small class="text-white-50"><?php echo __('footer_books'); ?></small>
                            </div>
                            <div class="col-4">
                                <div class="mb-2">
                                    <i class="fas fa-memory fa-lg text-info"></i>
                                </div>
                                <div class="h5 mb-0 text-white"><?php echo $memory_usage; ?>MB</div>
                                <small class="text-white-50"><?php echo __('footer_memory'); ?></small>
                            </div>
                            <div class="col-4">
                                <div class="mb-2">
                                    <i class="fas fa-stopwatch fa-lg text-success"></i>
                                </div>
                                <div class="h5 mb-0 text-white"><?php echo $execution_time; ?>s</div>
                                <small class="text-white-50"><?php echo __('footer_load_time'); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Нижняя часть с технической информацией -->
        <div class="row mt-4 pt-3 border-top border-secondary">
            <div class="col-md-8">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <small class="text-light" style="opacity: 0.7;">
                        <i class="fas fa-code me-1"></i>
                        PHP <?php echo PHP_VERSION; ?>
                        <?php if (Config::isCacheEnabled() && extension_loaded('apcu')): ?>
                        | <i class="fas fa-bolt me-1"></i>APCu
                        <?php endif; ?>
                        | <i class="fas fa-database me-1"></i><?php echo Config::getDbType(); ?>
                    </small>

                    <?php if (Config::isCacheEnabled()): ?>
                    <span class="badge bg-warning text-dark">
                        <i class="fas fa-bolt me-1"></i><?php echo __('caching'); ?>
                    </span>
                    <?php endif; ?>
                    
                    <!-- Индикатор текущего языка -->
                    <span class="badge bg-info">
                        <?php
        $detector = LanguageDetector::getInstance();
echo $detector->getLanguageFlag() . ' ' . $detector->getLanguageName();
?>
                    </span>
                </div>
            </div>

            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <small class="text-light" style="opacity: 0.7;">
                    &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(Config::getSiteTitle()); ?>
                </small>
            </div>
        </div>
    </div>
</footer>

<!-- Кнопка "Наверх" -->
<button type="button" class="btn btn-primary btn-floating" id="btn-back-to-top"
        onclick="window.scrollTo({top: 0, behavior: 'smooth'})"
        title="<?php echo __('back_to_top'); ?>">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- Стили для футера и кнопки наверх -->
<style>
.footer {
    background: #2c3e50;
    margin-top: 3rem;
}

.btn-floating {
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: 45px;
    height: 45px;
    border-radius: 50%;
    display: none;
    z-index: 1000;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    transition: all 0.3s ease;
}

.btn-floating:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.3);
}

.footer a {
    transition: opacity 0.3s ease;
}

.footer a:hover {
    opacity: 1 !important;
    transform: translateY(-2px);
}

.border-light {
    border-color: rgba(255,255,255,0.1) !important;
}

.notification-alert {
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes fa-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .footer {
        text-align: center;
    }

    .btn-floating {
        width: 40px;
        height: 40px;
        bottom: 15px;
        right: 15px;
    }
    
    .footer .d-flex {
        justify-content: center;
    }
}
</style>

<!-- JavaScript для кнопки "Наверх" -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const backToTop = document.getElementById('btn-back-to-top');
    
    if (backToTop) {
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 300) {
                backToTop.style.display = 'block';
            } else {
                backToTop.style.display = 'none';
            }
        });
    }
});
</script>

</body>
</html>