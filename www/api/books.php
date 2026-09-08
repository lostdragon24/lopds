<?php
// api/books.php - Исправленная версия с отображением жанра

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/BookHelper.php';
require_once __DIR__ . '/../lib/Cache.php';
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();

    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $searchQuery = $_GET['q'] ?? '';
    $searchField = $_GET['field'] ?? 'all';
    $perPage = isset($_GET['per_page']) ? min(50, intval($_GET['per_page'])) : Config::getItemsPerPage();

    // Фильтры
    $filters = [
        'lang' => $_GET['lang'] ?? null,
        'format' => $_GET['format'] ?? null,
        'sort' => $_GET['sort'] ?? 'new',
        'genre' => $_GET['genre'] ?? null,
        'author' => $_GET['author'] ?? null,
        'year' => $_GET['year'] ?? null,
    ];

    // Логируем для отладки
    error_log("API Request - Page: $page, Genre: " . ($filters['genre'] ?? 'none') . ", Search: " . ($searchQuery ?: 'none'));

    // Получаем книги с учётом фильтра по жанру
    if (!empty($filters['genre'])) {
        // Если есть фильтр по жанру, используем его
        $books = $db->getBooksByGenre($filters['genre'], $page, $perPage, $filters);
        $totalBooks = $db->getBooksCountByGenre($filters['genre'], $filters);
    } elseif (!empty($searchQuery)) {
        $books = $db->searchBooks($searchQuery, $searchField, $page, $perPage, $filters);
        $totalBooks = $db->getSearchCount($searchQuery, $searchField, $filters);
    } else {
        $books = $db->getRecentBooks($perPage, ($page - 1) * $perPage, $filters);
        $totalBooks = $db->getTotalBooksCount();
    }


    // Загружаем рейтинги и избранное
    $bookIds = array_column($books, 'id');
    $ratings = [];
    $userFavorites = [];

    if (!empty($bookIds)) {
        $ratings = $db->getRatingsForBooks($bookIds);

        $fingerprint = $_COOKIE['device_fp'] ?? $_SERVER['REMOTE_ADDR'];
        $userFavorites = $db->getFavoritesForBooks($bookIds, $fingerprint);
    }

    // Формируем HTML для карточек
    $html = '';
    foreach ($books as $book) {
        $bookId = $book['id'];
        $rating = $ratings[$bookId] ?? ['votes' => 0, 'average' => 0, 'average_rounded' => 0];
        $isFavorite = isset($userFavorites[$bookId]);

        // Получаем читаемое название жанра
        $readableGenre = '';
        if (!empty($book['genre'])) {
            $readableGenre = $db->getReadableGenre($book['genre']);
            if (empty($readableGenre)) {
                $readableGenre = $book['genre'];
            }
        }

        $html .= renderBookCard($book, $rating, $isFavorite, $readableGenre);
    }

    echo json_encode([
        'success' => true,
        'html' => $html,
        'has_more' => $page < ceil($totalBooks / $perPage),
        'page' => $page,
        'total_pages' => ceil($totalBooks / $perPage),
        'total_books' => $totalBooks
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Рендеринг карточки книги с использованием буферизации
 */
function renderBookCard($book, $rating, $isFavorite, $readableGenre = '')
{
    // Безопасное экранирование данных
    $bookId = (int)$book['id'];
    $title = htmlspecialchars($book['title'] ?: 'Без названия', ENT_QUOTES, 'UTF-8');
    $author = htmlspecialchars($book['author'] ?: '', ENT_QUOTES, 'UTF-8');
    $genre = htmlspecialchars($readableGenre ?: '', ENT_QUOTES, 'UTF-8');
    $authorUrl = urlencode($book['author'] ?? '');
    $fileType = strtoupper(htmlspecialchars($book['file_type'] ?: '?', ENT_QUOTES, 'UTF-8'));
    $addedDate = date('d.m.Y', strtotime($book['added_date']));
    $series = htmlspecialchars(mb_substr($book['series'] ?? '', 0, 20), ENT_QUOTES, 'UTF-8');
    $hasArchive = !empty($book['archive_path']);

    // Рейтинг
    $ratingHtml = '';
    if ($rating['votes'] > 0) {
        $avgRounded = (float)$rating['average_rounded'];
        for ($i = 1; $i <= 5; $i++) {
            if ($i <= floor($avgRounded)) {
                $ratingHtml .= '<i class="fas fa-star text-warning" style="font-size: 0.65em;"></i>';
            } elseif ($i - 0.5 <= $avgRounded) {
                $ratingHtml .= '<i class="fas fa-star-half-alt text-warning" style="font-size: 0.65em;"></i>';
            } else {
                $ratingHtml .= '<i class="far fa-star text-warning" style="font-size: 0.65em;"></i>';
            }
        }
        $ratingHtml .= ' <small class="text-muted ms-1">' . number_format((float)$rating['average'], 1) . '</small>';
    } else {
        $ratingHtml = '<small class="text-muted">—</small>';
    }

    // Классы для кнопок избранного
    $favBtnClass = $isFavorite ? 'btn-danger' : 'btn-outline-danger';
    $favIconClass = $isFavorite ? 'fas' : 'far';
    $favTitle = $isFavorite ? __('favorites_remove') : __('favorites_add');
    $listFavClass = $isFavorite ? 'active' : '';

    // Формируем HTML с использованием heredoc
    ob_start();
    ?>
    <div class="col-6 col-md-4 col-lg-3 book-item" data-id="<?= $bookId ?>">
        <div class="card book-card shadow-sm">
            <div class="book-cover-wrapper">
                <a href="book_detail.php?id=<?= $bookId ?>" class="text-decoration-none">
                    <img src="./api/cover.php?id=<?= $bookId ?>&thumb=1"
                         class="book-cover-img"
                         alt="<?= $title ?>"
                         loading="lazy"
                         onerror="this.style.display='none'; this.parentElement.querySelector('.book-cover-placeholder').style.display='flex'">
                    <div class="book-cover-placeholder" style="display: none;">
                        <div class="text-center text-muted">
                            <i class="fas fa-book fa-3x"></i>
                            <p class="small mb-0">No Cover</p>
                        </div>
                    </div>
                </a>

                <div class="cover-actions">
                    <a href="./api/download.php?id=<?= $bookId ?>"
                       class="btn btn-sm btn-success cover-btn"
                       title="Скачать">
                        <i class="fas fa-download"></i>
                    </a>
                    <a href="reader.php?id=<?= $bookId ?>"
                       class="btn btn-sm btn-primary cover-btn"
                       title="Читать онлайн">
                        <i class="fas fa-book-open"></i>
                    </a>
                    <button class="btn btn-sm <?= $favBtnClass ?> cover-btn favorite-btn"
                            data-book-id="<?= $bookId ?>"
                            title="<?= $favTitle ?>">
                        <i class="<?= $favIconClass ?> fa-heart"></i>
                    </button>
                </div>

                <span class="format-badge badge bg-dark"><?= $fileType ?></span>
                <?php if ($hasArchive): ?>
                <span class="archive-badge badge bg-secondary">
                    <i class="fas fa-archive"></i>
                </span>
                <?php endif; ?>
            </div>

            <div class="card-body p-2">
                <div class="book-info">
                    <h6 class="card-title mb-1 text-truncate">
                        <a href="book_detail.php?id=<?= $bookId ?>" class="text-decoration-none">
                            <?= $title ?>
                        </a>
                    </h6>
                    <p class="card-text small text-muted text-truncate mb-1">
                        <?php if ($author): ?>
                            <a href="index.php?field=author&q=<?= $authorUrl ?>" class="text-decoration-none">
                                <?= $author ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted"><?= __('book_unknown_author') ?></span>
                        <?php endif; ?>
                    </p>

                    <!-- ЖАНР -->
                    <?php if ($genre): ?>
                    <p class="card-text small text-muted text-truncate mb-1">
                        <i class="fas fa-tag me-1" style="font-size: 0.65em;"></i>
                        <?= $genre ?>
                    </p>
                    <?php endif; ?>

                    <div class="book-rating-mini" id="rating-<?= $bookId ?>" data-book-id="<?= $bookId ?>">
                        <?= $ratingHtml ?>
                    </div>
                </div>

                <div class="list-meta d-none">
                    <span class="badge bg-light text-dark border"><?= $fileType ?></span>
                    <span><i class="far fa-calendar-alt me-1"></i><?= $addedDate ?></span>
                    <?php if ($series): ?>
                    <span class="text-truncate" style="max-width:120px;">
                        <i class="fas fa-bookmark me-1"></i><?= $series ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($genre): ?>
                    <span class="text-truncate" style="max-width:100px;">
                        <i class="fas fa-tag me-1"></i><?= $genre ?>
                    </span>
                    <?php endif; ?>
                </div>

                <div class="list-actions">
                    <a href="./api/download.php?id=<?= $bookId ?>"
                       class="btn btn-download"
                       title="Скачать">
                        <i class="fas fa-download"></i>
                    </a>
                    <a href="reader.php?id=<?= $bookId ?>"
                       class="btn btn-read"
                       title="Читать онлайн">
                        <i class="fas fa-book-open"></i>
                    </a>
                    <button class="btn btn-favorite <?= $listFavClass ?> favorite-btn"
                            data-book-id="<?= $bookId ?>"
                            title="<?= $favTitle ?>">
                        <i class="<?= $favIconClass ?> fa-heart"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
