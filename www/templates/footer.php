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
                                <a href="top_rated.php" class="btn btn-sm btn-outline-light">
                                    <i class="fas fa-chart-bar me-1"></i>Рейтинг
                                </a>
                                <a href="favorites.php" class="btn btn-sm btn-outline-light">
                                    <i class="fas fa-chart-bar me-1"></i>Избранное
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

    <!-- Общие JavaScript скрипты -->
    <script>
    // Общие функции для рейтингов и избранного
    const API_URL = './api/rating.php';


    /**
     * Переключить избранное
     */
    function toggleFavorite(bookId, button = null) {
        // Если кнопка передана, используем её, иначе находим по ID
        const btn = button || document.querySelector(`[data-book-id="${bookId}"]`);

        if (!btn) {
            console.error('Button not found for book:', bookId);
            return;
        }

        // Визуальная обратная связь
        const originalHtml = btn.innerHTML;
        const originalClass = btn.className;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        btn.disabled = true;

        fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'toggle_favorite',
                book_id: bookId
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Ошибка сети');
            }
            return response.json();
        })
        .then(data => {
            console.log('Favorite response:', data);
            if (data.success) {
                // Обновляем кнопку
                if (data.is_favorite) {
                    btn.classList.remove('btn-outline-danger', 'btn-outline-secondary');
                    btn.classList.add('btn-danger');
                    btn.innerHTML = '<i class="fas fa-heart"></i>';
                    showNotification('✅ Книга добавлена в избранное', 'success');
                } else {
                    btn.classList.remove('btn-danger');
                    btn.classList.add('btn-outline-danger', 'btn-outline-secondary');
                    btn.innerHTML = '<i class="far fa-heart"></i>';
                    showNotification('ℹ️ Книга удалена из избранного', 'info');
                }

                // Обновляем счетчик избранного если есть
                const countElement = document.getElementById('favorites-count');
                if (countElement) {
                    const currentCount = parseInt(countElement.textContent) || 0;
                    countElement.textContent = data.is_favorite ? currentCount + 1 : Math.max(0, currentCount - 1);
                }
            } else {
                btn.innerHTML = originalHtml;
                showNotification('❌ Ошибка: ' + (data.message || 'Не удалось изменить избранное'), 'error');
            }
            btn.disabled = false;
        })
        .catch(error => {
            console.error('Error toggling favorite:', error);
            btn.innerHTML = originalHtml;
            btn.className = originalClass;
            btn.disabled = false;
            showNotification('❌ Ошибка сети: ' + error.message, 'error');
        });
    }

    /**
     * Показать уведомление
     */
    function showNotification(message, type = 'info') {
        const types = {
            'success': { class: 'alert-success', icon: 'fa-check-circle' },
            'error': { class: 'alert-danger', icon: 'fa-exclamation-circle' },
            'info': { class: 'alert-info', icon: 'fa-info-circle' },
            'warning': { class: 'alert-warning', icon: 'fa-exclamation-triangle' }
        };

        const config = types[type] || types.info;

        // Удаляем старые уведомления
        const existingNotifications = document.querySelectorAll('.notification-alert');
        existingNotifications.forEach(notification => notification.remove());

        // Создаем уведомление
        const alert = document.createElement('div');
        alert.className = `alert ${config.class} alert-dismissible fade show notification-alert position-fixed`;
        alert.style.cssText = 'top: 20px; right: 20px; z-index: 1050; max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
        alert.innerHTML = `
            <i class="fas ${config.icon} me-2"></i>
            ${message}
            <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
        `;

        document.body.appendChild(alert);

        // Автоматически скрываем через 3 секунды
        setTimeout(() => {
            if (alert.parentNode) {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 300);
            }
        }, 3000);
    }

    /**
     * Загрузить рейтинг книги (для мини-рейтингов на главной)
     */
    function loadBookRating(bookId, element) {
        fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'get_rating',
                book_id: bookId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.rating && data.rating.votes > 0) {
                const rating = data.rating.average_rounded;
                let starsHtml = '';

                for (let i = 1; i <= 5; i++) {
                    if (i <= Math.floor(rating)) {
                        starsHtml += '<i class="fas fa-star text-warning" style="font-size: 0.8em;"></i>';
                    } else if (i - 0.5 <= rating) {
                        starsHtml += '<i class="fas fa-star-half-alt text-warning" style="font-size: 0.8em;"></i>';
                    } else {
                        starsHtml += '<i class="far fa-star text-warning" style="font-size: 0.8em;"></i>';
                    }
                }

                element.innerHTML = starsHtml + ` <small class="text-muted">${data.rating.average.toFixed(1)}</small>`;
            } else {
                element.innerHTML = '<small class="text-muted">Нет оценок</small>';
            }
        })
        .catch(error => {
            console.error('Error loading rating:', error);
            element.innerHTML = '<small class="text-muted">Ошибка</small>';
        });
    }

    // Инициализация после загрузки DOM
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Library system initialized');

        // Показывать/скрывать кнопку "Наверх"
        window.onscroll = function() {
            var btn = document.getElementById("btn-back-to-top");
            if (document.body.scrollTop > 100 || document.documentElement.scrollTop > 100) {
                btn.style.display = "block";
            } else {
                btn.style.display = "none";
            }
        };

        // Инициализация ленивой загрузки изображений
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

        // Загружаем рейтинги для мини-карточек на главной
        document.querySelectorAll('.book-rating-mini').forEach(element => {
            const bookId = element.dataset.bookId;
            if (bookId) {
                loadBookRating(bookId, element);
            }
        });

        // Инициализация кнопок избранного на главной
        document.querySelectorAll('.favorite-btn-mini').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const bookId = this.dataset.bookId;
                if (bookId) {
                    toggleFavorite(parseInt(bookId), this);
                }
            });
        });
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
    }
    </style>

</body>
</html>
