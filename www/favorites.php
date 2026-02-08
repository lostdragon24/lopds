<?php
require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/PageCache.php';

PageCache::start('favorites_' . $_SERVER['REMOTE_ADDR'] . '_' . date('Ymd'));

$db = Database::getInstance();
$userIp = $_SERVER['REMOTE_ADDR'];
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = Config::ITEMS_PER_PAGE;

$favorites = $db->getUserFavorites($userIp, $page, $perPage);
$totalFavorites = $db->getUserFavoritesCount($userIp);
$totalPages = ceil($totalFavorites / $perPage);

require 'templates/header.php';
?>

<div class="container mt-4">
    <h1 class="mb-4">
        <i class="fas fa-heart text-danger me-2"></i>
        Мои избранные книги
    </h1>
    
    <?php if (empty($favorites)): ?>
        <div class="alert alert-info">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <i class="fas fa-heart-broken fa-2x"></i>
                </div>
                <div>
                    <h5 class="alert-heading">Нет избранных книг</h5>
                    <p class="mb-0">
                        Добавляйте книги в избранное, нажав на значок ❤️ на странице книги.
                        Они появятся здесь для быстрого доступа.
                    </p>
                    <a href="index.php" class="btn btn-primary mt-3">Найти книги</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="mb-4">
            <p class="text-muted">
                <i class="fas fa-bookmark me-1"></i>
                Всего избранных книг: <strong id="favorites-count"><?php echo $totalFavorites; ?></strong>
            </p>
        </div>
        
        <div class="row">
            <?php foreach ($favorites as $book): ?>
                <div class="col-md-6 mb-4" id="book-<?php echo $book['id']; ?>">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-3">
                                    <?php
                                    require_once 'lib/BookHelper.php';
                                    $hasCover = BookHelper::hasCover($book);
                                    ?>
                                    
                                    <?php if ($hasCover): ?>
                                        <img src="./api/cover_direct.php?id=<?php echo $book['id']; ?>&thumb=1" 
                                             class="img-fluid rounded" 
                                             style="max-width: 100px; height: auto;"
                                             alt="Обложка"
                                             onerror="this.style.display='none'"
                                             loading="lazy">
                                    <?php endif; ?>
                                    
                                    <?php if (!$hasCover): ?>
                                        <div class="bg-light d-flex align-items-center justify-content-center rounded" 
                                             style="width: 100px; height: 150px;">
                                            <i class="fas fa-book text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-9">
                                    <h6 class="card-title">
                                        <a href="book_detail.php?id=<?php echo $book['id']; ?>" 
                                           class="text-decoration-none">
                                            <?php echo htmlspecialchars($book['title'] ?: 'Без названия'); ?>
                                        </a>
                                    </h6>
                                    
                                    <?php if ($book['author']): ?>
                                        <p class="card-text mb-1">
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($book['author']); ?>
                                            </small>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <?php if ($book['genre']): ?>
                                        <p class="card-text mb-1">
                                            <small>
                                                <?php 
                                                $readableGenre = $db->getReadableGenre($book['genre']);
                                                echo htmlspecialchars($readableGenre ?: $book['genre']); 
                                                ?>
                                            </small>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <div class="mt-3">
                                        <a href="book_detail.php?id=<?php echo $book['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye me-1"></i>Смотреть
                                        </a>
                                        <a href="./api/download.php?id=<?php echo $book['id']; ?>" 
                                           class="btn btn-sm btn-success">
                                            <i class="fas fa-download me-1"></i>Скачать
                                        </a>
                                    </div>
                                    
                                    <div class="mt-2">
                                        <small class="text-muted">
                                            <i class="far fa-clock me-1"></i>
                                            Добавлено: <?php echo date('d.m.Y', strtotime($book['favorited_at'])); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <button class="btn btn-sm btn-outline-danger remove-favorite" 
                                    data-book-id="<?php echo $book['id']; ?>">
                                <i class="fas fa-trash-alt me-1"></i>Удалить из избранного
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Пагинация -->
        <?php if ($totalPages > 1): ?>
            <nav aria-label="Пагинация" class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>">
                                <i class="fas fa-chevron-left me-1"></i>Назад
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>">
                                Вперед<i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Обработчик для кнопок удаления из избранного
    const removeButtons = document.querySelectorAll('.remove-favorite');
    
    removeButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            const bookId = this.getAttribute('data-book-id');
            const bookCard = document.getElementById('book-' + bookId);
            const button = this;
            
            // Показываем подтверждение
            if (!confirm('Вы уверены, что хотите удалить эту книгу из избранного?')) {
                return;
            }
            
            // Блокируем кнопку на время запроса
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Удаление...';
            
            // Отправляем AJAX запрос ТАК ЖЕ КАК В top_rated.php
            fetch('./api/rating.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'toggle_favorite',
                    book_id: parseInt(bookId)
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                console.log('Response:', data); // Для отладки
                if (data.success) {
                    // Показываем уведомление об успехе
                    showNotification('✅ Книга удалена из избранного', 'success');
                    
                    // Анимируем удаление карточки
                    if (bookCard) {
                        bookCard.style.transition = 'all 0.3s ease';
                        bookCard.style.opacity = '0';
                        bookCard.style.transform = 'translateX(-100px)';
                        
                        setTimeout(() => {
                            bookCard.remove();
                            
                            // Обновляем счетчик избранных книг
                            updateFavoritesCount();
                            
                            // Если карточек не осталось, показываем сообщение
                            const remainingCards = document.querySelectorAll('.col-md-6[id^="book-"]');
                            if (remainingCards.length === 0) {
                                setTimeout(() => {
                                    location.reload();
                                }, 500);
                            }
                        }, 300);
                    }
                } else {
                    showNotification('❌ Ошибка: ' + (data.message || 'Не удалось удалить книгу'), 'danger');
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-trash-alt me-1"></i>Удалить из избранного';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('❌ Ошибка сети: ' + error.message, 'danger');
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-trash-alt me-1"></i>Удалить из избранного';
            });
        });
    });
    
    // Функция для показа уведомлений
    function showNotification(message, type) {
        // Удаляем предыдущие уведомления
        const existingNotifications = document.querySelectorAll('.custom-notification');
        existingNotifications.forEach(notification => notification.remove());
        
        // Создаем новое уведомление
        const notification = document.createElement('div');
        notification.className = `custom-notification alert alert-${type} alert-dismissible fade show`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideInRight 0.3s ease;
        `;
        
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Автоматически скрываем через 3 секунды
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => notification.remove(), 300);
            }
        }, 3000);
    }
    
    // Функция для обновления счетчика избранных книг
    function updateFavoritesCount() {
        const countElement = document.getElementById('favorites-count');
        if (countElement) {
            const currentCount = parseInt(countElement.textContent);
            if (currentCount > 0) {
                countElement.textContent = currentCount - 1;
            }
        }
    }
    
    // Стили для анимаций
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
    `;
    document.head.appendChild(style);
});
</script>

<style>
.custom-notification {
    border-radius: 8px;
    border: none;
}

.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1) !important;
}

.btn-outline-danger:hover {
    background-color: var(--bs-danger);
    color: white;
}

.fa-spinner {
    animation: fa-spin 2s linear infinite;
}
</style>

<?php
PageCache::save();
require 'templates/footer.php';
?>