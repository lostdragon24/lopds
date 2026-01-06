    </div> <!-- Закрытие контейнера из header.php -->
    
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
                            <h5 class="mb-2"><?php echo htmlspecialchars(Config::SITE_TITLE); ?></h5>
                            <p class="text-light mb-3" style="opacity: 0.8;">
                                Ваша персональная электронная библиотека
                            </p>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="./api/opds.php" class="btn btn-sm btn-outline-light" target="_blank">
                                    <i class="fas fa-rss me-1"></i>OPDS
                                </a>
                                <a href="stats.php" class="btn btn-sm btn-outline-light">
                                    <i class="fas fa-chart-bar me-1"></i>Статистика
                                </a>
                                <?php if (Config::ENABLE_CACHE): ?>
                                <a href="cache_stats.php" class="btn btn-sm btn-outline-warning">
                                    <i class="fas fa-bolt me-1"></i>Кэш
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
                        $db = Database::getInstance();
                        $stats = $db->getCollectionStats();
                        
                        // Производительность
                        $memory_usage = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
                        $execution_time = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3);
                        
                    } catch (Exception $e) {
                        $stats = ['total_books' => 0];
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
                                    <div class="h5 mb-0"><?php echo number_format($stats['total_books']); ?></div>
                                    <small class="text-light" style="opacity: 0.7;">Книг</small>
                                </div>
                                <div class="col-4">
                                    <div class="mb-2">
                                        <i class="fas fa-memory fa-lg text-info"></i>
                                    </div>
                                    <div class="h5 mb-0"><?php echo $memory_usage; ?>MB</div>
                                    <small class="text-light" style="opacity: 0.7;">Памяти</small>
                                </div>
                                <div class="col-4">
                                    <div class="mb-2">
                                        <i class="fas fa-stopwatch fa-lg text-success"></i>
                                    </div>
                                    <div class="h5 mb-0"><?php echo $execution_time; ?>s</div>
                                    <small class="text-light" style="opacity: 0.7;">Загрузка</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Нижняя часть -->
            <div class="row mt-4 pt-3 border-top border-secondary">
                <div class="col-md-8">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <small class="text-light" style="opacity: 0.7;">
                            <i class="fas fa-code me-1"></i>
                            PHP <?php echo PHP_VERSION; ?> 
                            <?php if (Config::ENABLE_CACHE && extension_loaded('apcu')): ?>
                            | <i class="fas fa-bolt me-1"></i>APCu
                            <?php endif; ?>
                            | <i class="fas fa-database me-1"></i><?php echo strtoupper(Config::DB_TYPE); ?>
                        </small>
                        
                        <?php if (Config::ENABLE_CACHE): ?>
                        <span class="badge bg-warning text-dark">
                            <i class="fas fa-bolt me-1"></i>Кэширование
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <small class="text-light" style="opacity: 0.7;">
                        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(Config::SITE_TITLE); ?>
                    </small>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Кнопка "Наверх" -->
    <button type="button" class="btn btn-primary btn-floating" id="btn-back-to-top" 
            onclick="window.scrollTo({top: 0, behavior: 'smooth'})">
        <i class="fas fa-arrow-up"></i>
    </button>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Показывать/скрывать кнопку "Наверх"
    window.onscroll = function() {
        var btn = document.getElementById("btn-back-to-top");
        if (document.body.scrollTop > 100 || document.documentElement.scrollTop > 100) {
            btn.style.display = "block";
        } else {
            btn.style.display = "none";
        }
    };
    
    // Инициализация при загрузке страницы
    document.addEventListener("DOMContentLoaded", function() {
        // Ленивая загрузка изображений
        var lazyImages = [].slice.call(document.querySelectorAll("img.lazy"));
        if ("IntersectionObserver" in window) {
            let lazyImageObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        let lazyImage = entry.target;
                        lazyImage.src = lazyImage.dataset.src;
                        lazyImage.classList.remove("lazy");
                        lazyImageObserver.unobserve(lazyImage);
                    }
                });
            });
            lazyImages.forEach(function(lazyImage) {
                lazyImageObserver.observe(lazyImage);
            });
        }
    });
    </script>
    
    <!-- Стили -->
    <style>
    .footer {
        background: #2c3e50;
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
    }
    
    .footer a {
        transition: opacity 0.3s ease;
    }
    
    .footer a:hover {
        opacity: 1 !important;
    }
    
    .border-light {
        border-color: rgba(255,255,255,0.1) !important;
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
    }
    </style>
</body>
</html>