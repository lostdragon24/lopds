<?php
// favorites.php

define('LOPDS_ROOT', __DIR__);

// Если это AJAX запрос, не используем кэш страниц
if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && 'xmlhttprequest' == strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    require_once 'config/config.php';
    require_once 'lib/Database.php';
} else {
    require_once 'config/config.php';
    require_once 'lib/Database.php';
    require_once 'lib/PageCache.php';
    require_once 'init.php';  // ← ДОБАВИТЬ
}

$db = Database::getInstance();
$userIp = $_SERVER['REMOTE_ADDR'];
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

$perPage = Config::getItemsPerPage();

// Кеширование только для обычных запросов
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $cacheKey = 'favorites_'.md5($userIp.'_'.date('Ymd').'_page_'.$page);
    PageCache::start($cacheKey);
}

$favorites = $db->getUserFavorites($userIp, $page, $perPage);
$totalFavorites = $db->getUserFavoritesCount($userIp);
$totalPages = ceil($totalFavorites / $perPage);

require 'templates/header.php';
?>

<script>
    window.CSRF_TOKEN = '<?php echo Config::getCsrfToken(); ?>';
    console.log('CSRF Token loaded in favorites:', window.CSRF_TOKEN ? 'yes' : 'no');
</script>

<div class="container mt-4">
    <h1 class="mb-4">
        <i class="fas fa-heart text-danger me-2"></i>
        <?php echo __('favorites_title'); ?>
    </h1>

    <?php if (empty($favorites)) { ?>
        <div class="alert alert-info">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <i class="fas fa-heart-broken fa-2x"></i>
                </div>
                <div>
                    <h5 class="alert-heading"><?php echo __('favorites_empty'); ?></h5>
                    <p class="mb-0">
                        <?php echo __('favorites_empty_desc'); ?>
                    </p>
                    <a href="index.php" class="btn btn-primary mt-3">
                        <i class="fas fa-search me-2"></i><?php echo __('favorites_find_books'); ?>
                    </a>
                </div>
            </div>
        </div>
    <?php } else { ?>
        <div class="mb-4">
            <p class="text-muted">
                <i class="fas fa-bookmark me-1"></i>
                <?php echo __('favorites_total'); ?> 
                <strong id="favorites-count"><?php echo $totalFavorites; ?></strong>
            </p>
        </div>

        <!-- Таблица книг -->
        <div class="card shadow">
            <div class="card-header py-3 bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-heart text-danger me-2"></i>
                        <?php echo __('favorites_list_title'); ?>
                    </h6>
                    <div class="text-muted small">
                        <?php echo __('page'); ?> <?php echo $page; ?> <?php echo __('of'); ?> <?php echo $totalPages; ?>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php echo __('book_title'); ?></th>
                                <th><?php echo __('book_author'); ?></th>
                                <th><?php echo __('book_genre'); ?></th>
                                <th><?php echo __('book_series'); ?></th>
                                <th><?php echo __('book_format'); ?></th>
                                <th><?php echo __('book_added'); ?></th>
                                <th><?php echo __('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($favorites as $book) { ?>
                                <tr id="book-<?php echo $book['id']; ?>">
                                    <td>
                                        <a href="book_detail.php?id=<?php echo $book['id']; ?>" class="text-decoration-none fw-bold">
                                            <?php echo htmlspecialchars(mb_substr($book['title'] ?: __('book_untitled'), 0, 60)).(mb_strlen($book['title'] ?? '') > 60 ? '…' : ''); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if (!empty($book['author'])) { ?>
                                            <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars(mb_substr($book['author'], 0, 30)); ?>
                                            </a>
                                        <?php } else { ?>
                                            <span class="text-muted"><?php echo __('book_unknown_author'); ?></span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <?php
                                        if (!empty($book['genre'])) {
                                            $readableGenre = $db->getReadableGenre($book['genre']);
                                            echo htmlspecialchars($readableGenre ?: $book['genre']);
                                        } else {
                                            echo '<span class="text-muted">—</span>';
                                        }
                                ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($book['series'])) { ?>
                                            <?php echo htmlspecialchars(mb_substr($book['series'], 0, 30)); ?>
                                            <?php if (!empty($book['series_number'])) { ?>
                                                <span class="badge bg-light text-dark border ms-1">#<?php echo $book['series_number']; ?></span>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <span class="text-muted">—</span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo strtoupper($book['file_type']); ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo date('d.m.Y', strtotime($book['favorited_at'])); ?></small>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="book_detail.php?id=<?php echo $book['id']; ?>" 
                                               class="btn btn-outline-primary" 
                                               title="<?php echo __('details'); ?>"
                                               data-bs-toggle="tooltip">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="./api/download.php?id=<?php echo $book['id']; ?>" 
                                               class="btn btn-outline-success" 
                                               title="<?php echo __('download'); ?>"
                                               data-bs-toggle="tooltip">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <button class="btn btn-outline-danger remove-favorite"
                                                    data-book-id="<?php echo $book['id']; ?>"
                                                    title="<?php echo __('favorites_remove'); ?>">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <!-- Пагинация -->
                <?php if ($totalPages > 1) { ?>
                <div class="card-footer bg-white">
                    <nav aria-label="<?php echo __('pagination'); ?>">
                        <ul class="pagination justify-content-center mb-0">
                            <?php if ($page > 1) { ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="<?php echo __('previous'); ?>">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php } ?>

                            <?php
                            $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);

                    if ($startPage > 1) {
                        echo '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>';
                        if ($startPage > 2) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                    }

                    for ($i = $startPage; $i <= $endPage; ++$i) { ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php } ?>

                            <?php if ($endPage < $totalPages) { ?>
                                <?php if ($endPage < $totalPages - 1) { ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php } ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a>
                                </li>
                            <?php } ?>

                            <?php if ($page < $totalPages) { ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="<?php echo __('next'); ?>">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            <?php } ?>
                        </ul>
                    </nav>
                </div>
                <?php } ?>
            </div>
        </div>
    <?php } ?>

    <div class="mt-4 text-center">
        <a href="index.php" class="btn btn-primary">
            <i class="fas fa-search me-2"></i><?php echo __('favorites_find_books'); ?>
        </a>
        <a href="top_rated.php" class="btn btn-outline-warning ms-2">
            <i class="fas fa-star me-2"></i><?php echo __('top_rated'); ?>
        </a>
        <a href="stats.php" class="btn btn-outline-info ms-2">
            <i class="fas fa-chart-bar me-2"></i><?php echo __('stats'); ?>
        </a>
    </div>
</div>

<!-- JavaScript для удаления из избранного -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Favorites page loaded');

    // Обработчик для кнопок удаления из избранного
    document.querySelectorAll('.remove-favorite').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();

            if (!window.CSRF_TOKEN) {
                alert('<?php echo __('error_csrf'); ?>');
                return;
            }

            const bookId = this.getAttribute('data-book-id');
            const bookRow = document.getElementById('book-' + bookId);
            const currentButton = this;

            if (!confirm('<?php echo __('favorites_confirm_remove'); ?>')) {
                return;
            }

            // Блокируем кнопку
            currentButton.disabled = true;
            const originalHtml = currentButton.innerHTML;
            currentButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            fetch('./api/rating.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'toggle_favorite',
                    book_id: parseInt(bookId),
                    csrf_token: window.CSRF_TOKEN
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.result === 'removed') {
                    // Удаляем строку таблицы
                    if (bookRow) {
                        bookRow.remove();
                    }

                    // Обновляем счётчик
                    const countElement = document.getElementById('favorites-count');
                    if (countElement) {
                        const currentCount = parseInt(countElement.textContent);
                        countElement.textContent = currentCount - 1;
                    }

                    // Если не осталось книг, перезагружаем страницу
                    if (document.querySelectorAll('[id^="book-"]').length === 0) {
                        location.reload();
                    }
                    
                    // Показываем уведомление (если есть функция)
                    if (typeof showNotification === 'function') {
                        showNotification('<?php echo __('favorites_removed'); ?>', 'success');
                    }
                } else {
                    throw new Error(data.message || '<?php echo __('favorites_error_remove'); ?>');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('<?php echo __('error_occurred'); ?>: ' + error.message);
                currentButton.disabled = false;
                currentButton.innerHTML = originalHtml;
            });
        });
    });
});
</script>

<?php
PageCache::save();
require 'templates/footer.php';
?>
