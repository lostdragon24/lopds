<?php
// book_detail.php

define('LOPDS_ROOT', __DIR__);

require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/BookHelper.php';
require_once 'lib/Cache.php';
require_once 'lib/PageCache.php';
require_once 'init.php';

$db = Database::getInstance();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.0 400 Bad Request');
    die(__('book_invalid_id'));
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book) {
    header('HTTP/1.0 404 Not Found');
    die(__('book_not_found'));
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
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">🏠 <?php echo __('home'); ?></a></li>
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">📚 <?php echo __('all_books'); ?></a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars(mb_substr($book['title'] ?: __('book_untitled'), 0, 40)); ?></li>
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
                            <img src="./api/cover.php?id=<?php echo $book['id']; ?>" 
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
                            <i class="fas fa-download me-2"></i><?php echo __('download_book'); ?>
                        </a>

                        <?php if (in_array(strtolower($book['file_type']), ['fb2', 'epub', 'pdf'])): ?>
                            <a href="reader.php?id=<?php echo $book['id']; ?>" 
                               class="btn btn-lg btn-primary mt-2">
                                <i class="fas fa-book-open me-2"></i><?php echo __('read_online'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Техническая информация -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <h6 class="card-title border-bottom pb-2 mb-3">
                        <i class="fas fa-info-circle me-2"></i><?php echo __('info'); ?>
                    </h6>
                    
                    <div class="row">
                        <div class="col-6 mb-3">
                            <small class="text-muted d-block"><?php echo __('book_format'); ?></small>
                            <span class="badge bg-primary"><?php echo strtoupper($book['file_type']); ?></span>
                        </div>
                        
                        <div class="col-6 mb-3">
                            <small class="text-muted d-block"><?php echo __('book_added'); ?></small>
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
                    <h1 class="h2 mb-3"><?php echo htmlspecialchars($book['title'] ?: __('book_untitled')); ?></h1>
                    
                    <?php if (!empty($book['author'])): ?>
                    <div class="mb-4">
                        <h5 class="text-muted mb-2"><?php echo __('book_author'); ?></h5>
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
                                <?php echo __('rating_title'); ?>
                            </h5>
                            <div id="rating-section" data-book-id="<?php echo $bookId; ?>">
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

for ($i = 0; $i < $fullStars; $i++) {
    echo '<i class="fas fa-star text-warning fa-2x"></i>';
}
if ($halfStar) {
    echo '<i class="fas fa-star-half-alt text-warning fa-2x"></i>';
}
for ($i = 0; $i < $emptyStars; $i++) {
    echo '<i class="far fa-star text-warning fa-2x"></i>';
}
?>
                                            </div>
                                            <div>
                                                <small class="text-muted" id="votes-count">
                                                    <?php echo $rating['votes'] . ' ' . ($rating['votes'] == 1 ? __('rating_vote_1') : ($rating['votes'] < 5 ? __('rating_vote_2') : __('rating_vote_5'))); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <h6 class="mb-2"><?php echo __('rating_your'); ?></h6>
                                        <div class="star-rating-select mb-3" id="user-rating-stars">
                                            <div class="d-flex justify-content-center">
                                                <?php for ($star = 1; $star <= 5; $star++): ?>
                                                    <button type="button"
                                                            class="btn btn-link p-0 me-2 rating-star"
                                                            data-rating="<?php echo $star; ?>"
                                                            data-book-id="<?php echo $bookId; ?>">
                                                        <i class="<?php echo $userRating >= $star ? 'fas' : 'far'; ?> fa-star fa-2x <?php echo $userRating >= $star ? 'text-warning' : 'text-muted'; ?>"></i>
                                                    </button>
                                                <?php endfor; ?>
                                            </div>
                                            <div class="text-center mt-2">
                                                <small class="text-muted" id="user-rating-text">
                                                    <?php
    if ($userRating > 0) {
        echo sprintf(__('rating_your_value'), $userRating, $userRating == 1 ? __('rating_star_1') : ($userRating < 5 ? __('rating_star_2') : __('rating_star_5')));
    } else {
        echo __('rating_click_to_rate');
    }
?>
                                                </small>
                                            </div>
                                        </div>

                                        <!-- Распределение оценок -->
                                        <?php if ($rating['votes'] > 0): ?>
                                        <div class="mt-3">
                                            <h6 class="mb-2"><?php echo __('rating_distribution'); ?></h6>
                                            <div id="rating-distribution">
                                                <?php
                                                $distribution = $rating['distribution'] ?? [0, 0, 0, 0, 0];
                                            for ($star = 5; $star >= 1; $star--):
                                                $index = 5 - $star;
                                                $count = $distribution[$index] ?? 0;
                                                $percent = $rating['votes'] > 0 ? ($count / $rating['votes'] * 100) : 0;
                                                $color = '';

                                                switch ($star) {
                                                    case 5: $color = 'bg-success';
                                                        break;
                                                    case 4: $color = 'bg-info';
                                                        break;
                                                    case 3: $color = 'bg-primary';
                                                        break;
                                                    case 2: $color = 'bg-warning';
                                                        break;
                                                    case 1: $color = 'bg-danger';
                                                        break;
                                                    default: $color = 'bg-secondary';
                                                }
                                                ?>
                                                <div class="d-flex align-items-center mb-1">
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
                                <?php echo __('favorites'); ?>
                            </h5>
                            <div class="text-center">
                            
                                <button id="favorite-btn"
                                        class="btn <?php echo $isFavorite ? 'btn-danger' : 'btn-outline-danger'; ?>"
                                        data-book-id="<?php echo $bookId; ?>"
                                        style="min-width: 150px;">
                                    <i class="<?php echo $isFavorite ? 'fas' : 'far'; ?> fa-heart me-2"></i>
                                    <span><?php echo $isFavorite ? __('favorites_in') : __('favorites_add'); ?></span>
                                </button>
                                
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <?php echo __('favorites_for_quick'); ?>
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
                            <h6 class="card-title text-muted mb-2"><?php echo __('book_genre'); ?></h6>
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
                            <h6 class="card-title text-muted mb-2"><?php echo __('book_series'); ?></h6>
                            <a href="index.php?field=series&q=<?php echo urlencode($book['series']); ?>" 
                               class="text-decoration-none">
                                <span class="h5"><?php echo htmlspecialchars($book['series']); ?></span>
                            </a>
                            <?php if (!empty($book['series_number'])): ?>
                            <span class="badge bg-secondary"><?php echo sprintf(__('book_number'), $book['series_number']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($book['year'])): ?>
                <div class="col-md-4">
                    <div class="card h-100 border-0 bg-light">
                        <div class="card-body text-center">
                            <h6 class="card-title text-muted mb-2"><?php echo __('book_year'); ?></h6>
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
                        <i class="fas fa-file-alt me-2"></i><?php echo __('book_description'); ?>
                    </h5>
                    <div class="book-description">
                        <?php if (!empty($description)): ?>
                            <p><?php echo nl2br(htmlspecialchars($description)); ?></p>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                <?php echo __('book_description_missing'); ?>
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
                            <i class="fas fa-arrow-left me-2"></i><?php echo __('back_to_list'); ?>
                        </a>
                        <?php if (!empty($book['author'])): ?>
                        <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" 
                           class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-user me-2"></i><?php echo __('all_books_by_author'); ?>
                        </a>
                        <?php endif; ?>
                        <a href="favorites.php" class="btn btn-outline-danger ms-2">
                            <i class="fas fa-heart me-2"></i><?php echo __('my_favorites'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


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



<!-- JavaScript для страницы книги -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Book detail page loaded');
    
    // Загружаем рейтинг
    const ratingSection = document.getElementById('rating-section');
    if (ratingSection && window.loadBookRating) {
        window.loadBookRating(<?php echo $bookId; ?>, ratingSection);
    }
    
    // Инициализация кнопки избранного
    const favBtn = document.getElementById('favorite-btn');
    if (favBtn) {
        const newBtn = favBtn.cloneNode(true);
        favBtn.parentNode.replaceChild(newBtn, favBtn);
        
        newBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (this.disabled) return;
            
            const bookId = this.getAttribute('data-book-id');
            if (bookId && window.toggleFavorite) {
                window.toggleFavorite(bookId, this);
            }
        });
    }
    
    // Инициализация звезд
    const stars = document.querySelectorAll('.rating-star');
    stars.forEach(star => {
        const newStar = star.cloneNode(true);
        star.parentNode.replaceChild(newStar, star);
        
        newStar.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const rating = this.getAttribute('data-rating');
            const bookId = this.getAttribute('data-book-id');
            if (window.rateBook) {
                window.rateBook(bookId, rating, this);
            }
        });
        
        newStar.addEventListener('mouseenter', function() {
            const rating = this.getAttribute('data-rating');
            if (window.highlightStars) {
                window.highlightStars(rating);
            }
        });
        
        newStar.addEventListener('mouseleave', function() {
            if (window.resetStars) {
                window.resetStars();
            }
        });
    });
});

// Локальная функция для обработки ошибок загрузки обложек (на случай, если глобальной нет)
if (typeof handleCoverError !== 'function') {
    window.handleCoverError = function(img, height = 400) {
        img.style.display = 'none';
        const parent = img.parentNode;
        const noCoverText = '<?php echo __('book_no_cover'); ?>';
        const fileType = '<?php echo strtoupper($book['file_type']); ?>';
        
        if (parent.querySelector('.cover-placeholder')) {
            return;
        }
        
        const placeholder = document.createElement('div');
        placeholder.className = 'bg-light d-flex align-items-center justify-content-center rounded cover-placeholder';
        placeholder.style.cssText = `width:100%; height:${height}px;`;
        
        if (height >= 300) {
            placeholder.innerHTML = `
                <div class="text-center">
                    <i class="fas fa-book text-muted mb-3" style="font-size: 4rem;"></i>
                    <p class="text-muted mb-0">${noCoverText}</p>
                    <p class="text-muted mb-0">
                        <small>${fileType}</small>
                    </p>
                </div>
            `;
        } else {
            placeholder.innerHTML = '<small class="text-muted">' + noCoverText + '</small>';
        }
        
        parent.innerHTML = '';
        parent.appendChild(placeholder);
    };
}
</script>

<?php require 'templates/footer.php'; ?>
