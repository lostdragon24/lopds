<?php
require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/BookHelper.php';
require_once 'lib/Cache.php';
require_once 'lib/PageCache.php';

$db = Database::getInstance();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.0 400 Bad Request');
    die('Неверный ID книги');
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book) {
    header('HTTP/1.0 404 Not Found');
    die('Книга не найдена');
}

// Получаем данные для отображения
$readableGenre = $db->getReadableGenre($book['genre']);
$hasCover = BookHelper::hasCover($book);
$description = $book['description'] ?? BookHelper::extractDescription($book);

// Получаем рейтинг и статус избранного
$rating = $db->getBookRating($bookId);
$userRating = $db->getUserRating($bookId, $_SERVER['REMOTE_ADDR']);
$isFavorite = $db->isBookInFavorites($bookId, $_SERVER['REMOTE_ADDR']);

require 'templates/header.php';
?>

<div class="container py-4">
    <!-- Хлебные крошки -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">🏠 Главная</a></li>
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">📚 Все книги</a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars(mb_substr($book['title'] ?: 'Без названия', 0, 40)); ?></li>
        </ol>
    </nav>

    <div class="row">
        <!-- Левая колонка - Обложка и действия -->
        <div class="col-lg-4 mb-4">
            <!-- Обложка -->
            <div class="card shadow-sm mb-4">
                <div class="card-body p-3 text-center">
                    <div class="cover-container mb-3">
                        <?php if ($hasCover): ?>
                            <img src="./api/cover_direct.php?id=<?php echo $book['id']; ?>" 
                                 class="img-fluid rounded shadow" 
                                 alt="Обложка книги <?php echo htmlspecialchars($book['title']); ?>"
                                 style="max-height: 400px; width: auto;"
                                 loading="eager">
                            
                            <div class="mt-2">
                                <span class="badge bg-primary">
                                    <?php echo strtoupper($book['file_type']); ?>
                                </span>
                                <?php if ($book['archive_path']): ?>
                                <span class="badge bg-secondary ms-1">
                                    📦 В архиве
                                </span>
                                <?php endif; ?>
                            </div>
                            
                        <?php else: ?>
                            <div class="bg-light d-flex align-items-center justify-content-center rounded" 
                                 style="height: 300px;">
                                <div class="text-center">
                                    <i class="fas fa-book text-muted mb-3" style="font-size: 4rem;"></i>
                                    <p class="text-muted mb-0">Нет обложки</p>
                                    <p class="text-muted mb-0">
                                        <small><?php echo strtoupper($book['file_type']); ?></small>
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Действия -->
                    <div class="d-grid gap-2">
                        <a href="./api/download.php?id=<?php echo $book['id']; ?>" 
                           class="btn btn-lg btn-success">
                            <i class="fas fa-download me-2"></i>Скачать книгу
                        </a>

<?php if (in_array(strtolower($book['file_type']), ['fb2', 'epub', 'pdf'])): ?>
    <a href="reader.php?id=<?php echo $book['id']; ?>" 
       class="btn btn-lg btn-primary mt-2">
        <i class="fas fa-book-open me-2"></i>Читать онлайн
    </a>
    <?php endif; ?>


                    </div>
                </div>
            </div>
            
            <!-- Техническая информация -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <h6 class="card-title border-bottom pb-2 mb-3">
                        <i class="fas fa-info-circle me-2"></i>Информация
                    </h6>
                    
                    <div class="row">
                        <div class="col-6 mb-3">
                            <small class="text-muted d-block">Формат</small>
                            <span class="badge bg-primary"><?php echo strtoupper($book['file_type']); ?></span>
                        </div>
                        
                        <div class="col-6 mb-3">
                            <small class="text-muted d-block">Добавлено</small>
                            <strong><?php echo date('d.m.Y H:i', strtotime($book['added_date'])); ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Правая колонка - Основная информация -->
        <div class="col-lg-8">
            <!-- Заголовок и автор -->
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h1 class="h2 mb-3"><?php echo htmlspecialchars($book['title'] ?: 'Без названия'); ?></h1>
                    
                    <?php if (!empty($book['author'])): ?>
                    <div class="mb-4">
                        <h5 class="text-muted mb-2">Автор</h5>
                        <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" 
                           class="h4 text-decoration-none">
                            <?php echo htmlspecialchars($book['author']); ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>


 <!-- РЕЙТИНГ И ИЗБРАННОЕ -->
<div class="row mb-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="fas fa-star text-warning me-2"></i>
                    Рейтинг книги
                </h5>
                <div id="rating-section">
                    <div class="row align-items-center">


<div class="col-md-6">
    <div class="text-center mb-3 mb-md-0">
        <div class="h1 mb-0 text-warning" id="average-rating">
            <?php echo number_format($rating['average'], 1); ?>
        </div>
        <div class="star-rating-large mb-2" id="average-stars">
            <?php
            $fullStars = floor($rating['average_rounded']);
            $halfStar = ($rating['average_rounded'] - $fullStars) >= 0.5;
            $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

            // Полные звезды
            for ($i = 0; $i < $fullStars; $i++) {
                echo '<i class="fas fa-star text-warning fa-2x"></i>';
            }

            // Половина звезды
            if ($halfStar) {
                echo '<i class="fas fa-star-half-alt text-warning fa-2x"></i>';
            }

            // Пустые звезды
            for ($i = 0; $i < $emptyStars; $i++) {
                echo '<i class="far fa-star text-warning fa-2x"></i>';
            }
            ?>
        </div>
        <div>
            <small class="text-muted" id="votes-count">
                <?php echo $rating['votes']; ?>
                <?php
                $votes = $rating['votes'];
                if ($votes % 10 === 1 && $votes % 100 !== 11) {
                    echo 'оценка';
                } elseif (in_array($votes % 10, [2,3,4]) && !in_array($votes % 100, [12,13,14])) {
                    echo 'оценки';
                } else {
                    echo 'оценок';
                }
                ?>
            </small>
        </div>
    </div>
</div>


                        <div class="col-md-6">
                            <h6 class="mb-2">Ваша оценка:</h6>
                            <div class="star-rating-select mb-3" id="user-rating-stars">
                                <div class="d-flex justify-content-center">
                                    <?php for ($star = 1; $star <= 5; $star++): ?>
                                        <button type="button"
                                                class="btn btn-link p-0 me-1 rating-star"
                                                data-rating="<?php echo $star; ?>"
                                                onclick="rateBook(<?php echo $bookId; ?>, <?php echo $star; ?>)">
                                            <i class="<?php echo $userRating >= $star ? 'fas' : 'far'; ?> fa-star fa-2x <?php echo $userRating >= $star ? 'text-warning' : 'text-muted'; ?>"></i>
                                        </button>
                                    <?php endfor; ?>
                                </div>
                                <div class="text-center mt-2">
                                    <small class="text-muted" id="user-rating-text">
                                        <?php
                                        if ($userRating > 0) {
                                            echo "Вы оценили на $userRating звезд";
                                        } else {
                                            echo 'Нажмите на звезду для оценки';
                                        }
                                        ?>
                                    </small>
                                </div>
                            </div>

                            <!-- Распределение оценок -->
                            <?php if ($rating['votes'] > 0): ?>
                            <div class="mt-3">
                                <h6 class="mb-2">Распределение оценок:</h6>
                                <div id="rating-distribution">
                                    <?php
                                    $distribution = $rating['distribution'] ?? [0, 0, 0, 0, 0];
                                    for ($star = 5; $star >= 1; $star--):
                                        $index = 5 - $star;
                                        $count = $distribution[$index] ?? 0;
                                        $percent = $rating['votes'] > 0 ? ($count / $rating['votes'] * 100) : 0;
                                        $color = '';

                                        switch($star) {
                                            case 5: $color = 'bg-success'; break;
                                            case 4: $color = 'bg-info'; break;
                                            case 3: $color = 'bg-primary'; break;
                                            case 2: $color = 'bg-warning'; break;
                                            case 1: $color = 'bg-danger'; break;
                                            default: $color = 'bg-secondary';
                                        }
                                    ?>
                                    <div class="d-flex align-items-center mb-1" data-star="<?php echo $star; ?>">
                                        <div class="me-2" style="width: 20px;">
                                            <small><?php echo $star; ?></small>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="progress" style="height: 10px;">
                                                <div class="progress-bar <?php echo $color; ?>"
                                                     role="progressbar"
                                                     style="width: <?php echo $percent; ?>%"
                                                     aria-valuenow="<?php echo $percent; ?>"
                                                     aria-valuemin="0"
                                                     aria-valuemax="100">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="ms-2" style="width: 40px; text-align: right;">
                                            <small class="text-muted star-count"><?php echo $count; ?></small>
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body d-flex flex-column justify-content-center">
                <h5 class="card-title">
                    <i class="fas fa-heart text-danger me-2"></i>
                    Избранное
                </h5>
                <div class="text-center">
                    <button id="favorite-btn"
                            class="btn btn-lg <?php echo $isFavorite ? 'btn-danger' : 'btn-outline-danger'; ?>"
                            data-book-id="<?php echo $bookId; ?>"
                            onclick="toggleFavorite(<?php echo $bookId; ?>)"
                            style="min-width: 180px;">
                        <i class="<?php echo $isFavorite ? 'fas' : 'far'; ?> fa-heart"></i>
                        <span id="favorite-text">
                            <?php echo $isFavorite ? ' В избранном' : ' В избранное'; ?>
                        </span>
                    </button>
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Для быстрого доступа
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>




            <!-- Метаданные -->
            <div class="row g-3 mb-4">
                <?php if ($readableGenre): ?>
                <div class="col-md-6">
                    <div class="card h-100 border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-title text-muted mb-2">Жанр</h6>
                            <a href="index.php?field=genre&q=<?php echo urlencode($book['genre']); ?>" 
                               class="text-decoration-none">
                                <span class="h5"><?php echo htmlspecialchars($readableGenre); ?></span>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($book['series'])): ?>
                <div class="col-md-6">
                    <div class="card h-100 border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-title text-muted mb-2">Серия</h6>
                            <a href="index.php?field=series&q=<?php echo urlencode($book['series']); ?>" 
                               class="text-decoration-none">
                                <span class="h5"><?php echo htmlspecialchars($book['series']); ?></span>
                            </a>
                            <?php if (!empty($book['series_number'])): ?>
                            <span class="badge bg-secondary">Книга <?php echo $book['series_number']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($book['year'])): ?>
                <div class="col-md-4">
                    <div class="card h-100 border-0 bg-light">
                        <div class="card-body text-center">
                            <h6 class="card-title text-muted mb-2">Год</h6>
                            <span class="h3 text-primary"><?php echo $book['year']; ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- ОПИСАНИЕ КНИГИ -->
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 border-bottom pb-2">
                        <i class="fas fa-file-alt me-2"></i>Описание книги
                    </h5>
                    <div class="book-description">
                        <?php if (!empty($description)): ?>
                            <p><?php echo nl2br(htmlspecialchars($description)); ?></p>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                Описание книги отсутствует
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Навигация -->
            <div class="card shadow-sm">
                <div class="card-body text-center">
                    <div class="btn-group" role="group">
                        <a href="index.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left me-2"></i>Назад к списку
                        </a>
                        <?php if (!empty($book['author'])): ?>
                        <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" 
                           class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-user me-2"></i>Все книги автора
                        </a>
                        <?php endif; ?>
                        <a href="favorites.php" class="btn btn-outline-danger ms-2">
                            <i class="fas fa-heart me-2"></i>Мои избранные
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Простой JavaScript для звёзд -->
<script>
// Простая функция оценки книги
function rateBook(bookId, rating) {
    console.log('Оцениваем книгу', bookId, 'на', rating, 'звёзд');

    // Визуальная обратная связь
    const stars = document.querySelectorAll('.rating-star');
    stars.forEach(star => {
        const starRating = parseInt(star.getAttribute('data-rating'));
        const icon = star.querySelector('i');

        if (starRating <= rating) {
            icon.className = 'fas fa-star fa-2x text-warning';
        } else {
            icon.className = 'far fa-star fa-2x text-muted';
        }
    });

    // Обновляем текст
    const ratingText = document.getElementById('user-rating-text');
    if (ratingText) {
        ratingText.textContent = 'Вы оценили на ' + rating + ' звезд';
    }

    // Отправляем на сервер
    fetch('./api/rating.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'rate',
            book_id: bookId,
            rating: rating
        })
    })
    .then(response => response.json())
    .then(data => {
        console.log('Ответ сервера:', data);
        if (data.success) {
            // Обновляем средний рейтинг
            const avgRating = document.getElementById('average-rating');
            if (avgRating && data.rating && data.rating.average) {
                avgRating.textContent = data.rating.average.toFixed(1);
            }

            // Обновляем количество оценок
            const votesCount = document.getElementById('votes-count');
            if (votesCount && data.rating && data.rating.votes) {
                votesCount.textContent = data.rating.votes + ' оценок';
            }

            alert('Ваша оценка сохранена!');
        } else {
            alert('Ошибка: ' + (data.message || 'Не удалось сохранить оценку'));
        }
    })
    .catch(error => {
        console.error('Ошибка:', error);
        alert('Ошибка сети');
    });
}

// Функция для избранного
function toggleFavorite(bookId) {
    const button = document.getElementById('favorite-btn');
    const icon = button.querySelector('i');
    const text = document.getElementById('favorite-text');

    // Визуальная обратная связь
    button.disabled = true;
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Загрузка...';

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
    .then(response => response.json())
    .then(data => {
        console.log('Ответ сервера (избранное):', data);
        if (data.success) {
            if (data.is_favorite) {
                // Добавлено в избранное
                button.className = 'btn btn-lg btn-danger';
                icon.className = 'fas fa-heart';
                text.textContent = ' В избранном';
                alert('Книга добавлена в избранное!');
            } else {
                // Удалено из избранного
                button.className = 'btn btn-lg btn-outline-danger';
                icon.className = 'far fa-heart';
                text.textContent = ' В избранное';
                alert('Книга удалена из избранного!');
            }
        } else {
            alert('Ошибка: ' + (data.message || 'Не удалось изменить избранное'));
        }
        button.disabled = false;
        button.innerHTML = originalText;
    })
    .catch(error => {
        console.error('Ошибка:', error);
        alert('Ошибка сети');
        button.disabled = false;
        button.innerHTML = originalText;
    });
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    console.log('Страница книги загружена');

    // Проверяем, загрузились ли звёзды
    const stars = document.querySelectorAll('.rating-star');
    console.log('Найдено звёзд:', stars.length);

    // Добавляем hover-эффект
    stars.forEach(star => {
        star.addEventListener('mouseenter', function() {
            const rating = this.getAttribute('data-rating');
            highlightStars(rating);
        });

        star.addEventListener('mouseleave', function() {
            resetStars();
        });
    });
});

// Подсветка звёзд при наведении
function highlightStars(rating) {
    const stars = document.querySelectorAll('.rating-star');
    stars.forEach(star => {
        const starRating = star.getAttribute('data-rating');
        if (starRating <= rating) {
            star.style.transform = 'scale(1.1)';
        }
    });
}

// Сброс подсветки
function resetStars() {
    const stars = document.querySelectorAll('.rating-star');
    stars.forEach(star => {
        star.style.transform = 'scale(1)';
    });
}
</script>

<!-- Простые стили -->
<style>
.rating-star {
    cursor: pointer;
    transition: transform 0.2s;
    background: none;
    border: none;
}

.rating-star:hover {
    transform: scale(1.1);
}

.fa-spinner {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<?php require 'templates/footer.php'; ?>
