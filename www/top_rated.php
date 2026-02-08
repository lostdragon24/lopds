<?php
require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/PageCache.php';

PageCache::start('top_rated');

$db = Database::getInstance();
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;

// Получаем топ книги с минимум 1 оценкой
$topBooks = $db->getTopRatedBooks(100, 1); // 100 книг, минимум 1 оценка

// Пагинация
$totalBooks = count($topBooks);
$totalPages = ceil($totalBooks / $perPage);
$offset = ($page - 1) * $perPage;
$currentBooks = array_slice($topBooks, $offset, $perPage);

require 'templates/header.php';
?>

<div class="container mt-4">
    <h1 class="mb-4">
        <i class="fas fa-star text-warning me-2"></i>
        Лучшие книги по оценкам читателей
    </h1>
    
    <div class="alert alert-info">
        <div class="d-flex align-items-center">
            <div class="me-3">
                <i class="fas fa-info-circle fa-2x"></i>
            </div>
            <div>
                <p class="mb-0">
                    Здесь представлены книги с наивысшим рейтингом от пользователей библиотеки.
                    Для попадания в рейтинг книга должна иметь минимум <strong>1 оценку</strong>.
                    Чем больше оценок, тем точнее рейтинг.
                </p>
            </div>
        </div>
    </div>
    
    <?php if (empty($topBooks)): ?>
        <div class="alert alert-warning">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <i class="fas fa-exclamation-triangle fa-2x"></i>
                </div>
                <div>
                    <h5 class="alert-heading">Пока нет оценок</h5>
                    <p class="mb-0">
                        Ни одна книга еще не получила оценок.
                        <a href="index.php" class="alert-link">Перейдите в каталог</a> и оцените понравившиеся книги,
                        чтобы помочь другим читателям!
                    </p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Статистика рейтингов -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title text-muted">Всего оценено книг</h5>
                        <div class="display-4 text-primary"><?php echo count($topBooks); ?></div>
                        <small class="text-muted">
                            <?php 
                            $totalAllBooks = $db->getTotalBooksCount();
                            echo round(count($topBooks) / max(1, $totalAllBooks) * 100, 1); ?>% от всех книг
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title text-muted">Всего оценок</h5>
                        <?php
                        try {
                            $totalRatings = $db->getConnection()->query("SELECT COUNT(*) as count FROM book_ratings")->fetch()['count'];
                        } catch (Exception $e) {
                            $totalRatings = 0;
                        }
                        ?>
                        <div class="display-4 text-success"><?php echo $totalRatings; ?></div>
                        <small class="text-muted">от пользователей</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title text-muted">Средний рейтинг</h5>
                        <?php
                        try {
                            $avgRating = $db->getConnection()->query("SELECT AVG(rating) as avg FROM book_ratings")->fetch()['avg'];
                            $avgRating = $avgRating ? round($avgRating, 2) : 0;
                        } catch (Exception $e) {
                            $avgRating = 0;
                        }
                        ?>
                        <div class="display-4 text-warning"><?php echo $avgRating; ?></div>
                        <small class="text-muted">из 5 возможных</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Таблица рейтинга -->
        <div class="card shadow">
            <div class="card-header py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-trophy me-2"></i>
                        Рейтинг книг
                    </h6>
                    <div class="text-muted">
                        Страница <?php echo $page; ?> из <?php echo $totalPages; ?>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th width="60" class="text-center">#</th>
                                <th>Книга</th>
                                <th width="150">Автор</th>
                                <th width="120">Рейтинг</th>
                                <th width="100" class="text-center">Оценок</th>
                                <th width="150" class="text-center">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($currentBooks as $index => $book): 
                                $globalIndex = $offset + $index + 1;
                                $rating = $db->getBookRating($book['id']);
                                $avgRating = $rating['average'];
                                $votes = $rating['votes'];
                            ?>
                                <tr>
                                    <td class="text-center">
                                        <?php if ($globalIndex <= 3): ?>
                                            <span class="badge bg-warning text-dark fs-6 p-2"><?php echo $globalIndex; ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary fs-6"><?php echo $globalIndex; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <?php
                                                require_once 'lib/BookHelper.php';
                                                $hasCover = BookHelper::hasCover($book);
                                                ?>
                                                <?php if ($hasCover): ?>
                                                    <img src="./api/cover_direct.php?id=<?php echo $book['id']; ?>&thumb=1" 
                                                         class="rounded" 
                                                         style="width: 50px; height: 75px; object-fit: cover;"
                                                         alt="Обложка"
                                                         onerror="this.style.display='none'">
                                                <?php endif; ?>
                                                <?php if (!$hasCover): ?>
                                                    <div class="bg-light d-flex align-items-center justify-content-center rounded" 
                                                         style="width: 50px; height: 75px;">
                                                        <i class="fas fa-book text-muted"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <strong>
                                                    <a href="book_detail.php?id=<?php echo $book['id']; ?>" 
                                                       class="text-decoration-none">
                                                        <?php echo htmlspecialchars($book['title'] ?: 'Без названия'); ?>
                                                    </a>
                                                </strong>
                                                <?php if (!empty($book['series'])): ?>
                                                    <div class="text-muted">
                                                        <small>
                                                            <i class="fas fa-bookmark me-1"></i>
                                                            <?php echo htmlspecialchars($book['series']); ?>
                                                            <?php if (!empty($book['series_number'])): ?>
                                                                <span class="badge bg-light text-dark border ms-1">
                                                                    #<?php echo $book['series_number']; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($book['genre'])): ?>
                                                    <div>
                                                        <small class="badge bg-light text-dark border">
                                                            <?php echo htmlspecialchars($db->getReadableGenre($book['genre']) ?: $book['genre']); ?>
                                                        </small>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($book['author'])): ?>
                                            <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" 
                                               class="text-decoration-none">
                                                <?php echo htmlspecialchars($book['author']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">Неизвестен</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="me-2">
                                                <span class="h5 mb-0 text-warning"><?php echo number_format($avgRating, 1); ?></span>
                                            </div>
                                            <div>
                                                <div class="star-rating-small">
                                                    <?php
                                                    $fullStars = floor($rating['average_rounded']);
                                                    $halfStar = $rating['average_rounded'] - $fullStars >= 0.5;
                                                    
                                                    for ($i = 0; $i < $fullStars; $i++): ?>
                                                        <i class="fas fa-star text-warning" style="font-size: 0.9em;"></i>
                                                    <?php endfor; ?>
                                                    
                                                    <?php if ($halfStar): ?>
                                                        <i class="fas fa-star-half-alt text-warning" style="font-size: 0.9em;"></i>
                                                    <?php endif; ?>
                                                    
                                                    <?php for ($i = 0; $i < (5 - $fullStars - ($halfStar ? 1 : 0)); $i++): ?>
                                                        <i class="far fa-star text-warning" style="font-size: 0.9em;"></i>
                                                    <?php endfor; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="h5"><?php echo $votes; ?></span>
                                        <div><small class="text-muted">оценок</small></div>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="book_detail.php?id=<?php echo $book['id']; ?>" 
                                               class="btn btn-outline-primary" title="Подробнее">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="./api/download.php?id=<?php echo $book['id']; ?>" 
                                               class="btn btn-outline-success" title="Скачать">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <?php
                                            $isFavorite = $db->isBookInFavorites($book['id'], $_SERVER['REMOTE_ADDR']);
                                            ?>
                                            <button type="button" 
                                                    class="btn <?php echo $isFavorite ? 'btn-danger' : 'btn-outline-danger'; ?> favorite-toggle"
                                                    data-book-id="<?php echo $book['id']; ?>"
                                                    title="<?php echo $isFavorite ? 'Удалить из избранного' : 'Добавить в избранное'; ?>">
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
            </div>
        </div>
    <?php endif; ?>
    
    <div class="mt-4 text-center">
        <a href="index.php" class="btn btn-primary">
            <i class="fas fa-search me-2"></i>Найти больше книг
        </a>
        <a href="favorites.php" class="btn btn-outline-danger ms-2">
            <i class="fas fa-heart me-2"></i>Мои избранные
        </a>
        <a href="stats.php" class="btn btn-outline-info ms-2">
            <i class="fas fa-chart-bar me-2"></i>Статистика
        </a>
    </div>
</div>

<!-- JavaScript для избранного -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Обработчики для кнопок избранного
    document.querySelectorAll('.favorite-toggle').forEach(button => {
        button.addEventListener('click', function() {
            const bookId = this.dataset.bookId;
            if (!bookId) return;
            
            toggleFavorite(bookId, this);
        });
    });
});

function toggleFavorite(bookId, button) {
    const originalHTML = button.innerHTML;
    const originalClass = button.className;
    
    // Показываем загрузку
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.disabled = true;
    
    fetch('./api/rating.php', {
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
        if (!response.ok) throw new Error('Network error');
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Обновляем кнопку
            if (data.is_favorite) {
                button.innerHTML = '<i class="fas fa-heart"></i>';
                button.className = originalClass.replace('btn-outline-danger', 'btn-danger');
                button.title = 'Удалить из избранного';
                showNotification('Добавлено в избранное', 'success');
            } else {
                button.innerHTML = '<i class="far fa-heart"></i>';
                button.className = originalClass.replace('btn-danger', 'btn-outline-danger');
                button.title = 'Добавить в избранное';
                showNotification('Удалено из избранного', 'info');
            }
        } else {
            button.innerHTML = originalHTML;
            showNotification(data.message || 'Ошибка', 'error');
        }
        button.disabled = false;
    })
    .catch(error => {
        console.error('Error:', error);
        button.innerHTML = originalHTML;
        button.disabled = false;
        showNotification('Ошибка сети', 'error');
    });
}

function showNotification(message, type) {
    // Создаем уведомление
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    alert.style.cssText = 'top: 20px; right: 20px; z-index: 1050; max-width: 300px;';
    alert.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
    `;
    
    document.body.appendChild(alert);
    
    setTimeout(() => {
        if (alert.parentNode) {
            alert.remove();
        }
    }, 3000);
}
</script>

<style>
.star-rating-small i {
    margin: 0 1px;
}

.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,0.02);
}

.badge {
    font-weight: 600;
}

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
}
</style>

<?php
PageCache::save();
require 'templates/footer.php';
?>