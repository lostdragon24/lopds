<?php
// index.php - с умным скрытием блоков поиска

define('LOPDS_ROOT', __DIR__);

require_once __DIR__ . '/init.php';
require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/BookHelper.php';
require_once 'lib/PageCache.php';
require_once 'lib/DatabaseChecker.php';
require_once 'lib/Translator.php';

// ============================================
// ПРОВЕРКА БАЗЫ ДАННЫХ
// ============================================
$cacheKey = 'db_status_check_v2';
$status = Cache::get($cacheKey, 'statistics');

if ($status === null) {
    $checker = DatabaseChecker::getInstance();
    $status = $checker->getDetailedStatus();
    Cache::set($cacheKey, $status, 'statistics', 600);
}

if (!$status['database_available']) {
    $error = $status['error'] ?? __('error_database');
    require 'templates/maintenance.php';
    exit;
}

if (!$status['tables_exist']) {
    if (strpos($_SERVER['SCRIPT_NAME'], '/admin/') === false) {
        $error = __('error_init');
        require 'templates/maintenance.php';
        exit;
    } else {
        header('Location: install/index.php');
        exit;
    }
}

// ============================================
// ИНИЦИАЛИЗАЦИЯ
// ============================================
$db = Database::getInstance();
if (!$db->isAvailable()) {
    $error = 'Database instance not available';
    require 'templates/maintenance.php';
    exit;
}

// Отключаем кэширование страниц для бесконечного скролла
// PageCache::start();

$fingerprint = $_COOKIE['device_fp'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$searchQuery = $_GET['q'] ?? '';
$searchField = $_GET['field'] ?? 'all';
$itemsPerPage = Config::getItemsPerPage();

// ============================================
// ФИЛЬТРЫ
// ============================================
$filters = [
    'lang' => $_GET['lang'] ?? null,
    'format' => $_GET['format'] ?? null,
    'sort' => $_GET['sort'] ?? 'new',
    'genre' => $_GET['genre'] ?? null,
    'author' => $_GET['author'] ?? null,
    'year' => $_GET['year'] ?? null,
];

// ============================================
// ПОЛУЧАЕМ ПЕРВУЮ СТРАНИЦУ КНИГ
// ============================================
// Определяем, что у нас активен фильтр по жанру
$currentGenre = $_GET['genre'] ?? '';

// Если есть фильтр по жанру, используем его как основной критерий
if (!empty($currentGenre)) {
    // Получаем книги по жанру
    $books = $db->getBooksByGenre($currentGenre, 1, $itemsPerPage, $filters);
    $totalBooks = $db->getBooksCountByGenre($currentGenre, $filters);
    $isSearch = true; // Помечаем как поиск, чтобы показать блоки поиска
    $isGenreFilter = true;
} elseif (!empty($searchQuery)) {
    $books = $db->searchBooks($searchQuery, $searchField, 1, $itemsPerPage, $filters);
    $totalBooks = $db->getSearchCount($searchQuery, $searchField, $filters);
    $isSearch = true;
    $isGenreFilter = false;
} else {
    $books = $db->getRecentBooks($itemsPerPage, 0, $filters);
    $totalBooks = $db->getTotalBooksCount();
    $isSearch = false;
    $isGenreFilter = false;
}

$totalPages = ceil($totalBooks / $itemsPerPage);



// ============================================
// ЗАГРУЗКА ДОПОЛНИТЕЛЬНЫХ ДАННЫХ
// ============================================
$bookIds = array_column($books, 'id');

$ratings = [];
if (!empty($bookIds)) {
    $ratings = $db->getRatingsForBooks($bookIds);
}

$userFavorites = [];
if (!empty($bookIds)) {
    $userFavorites = $db->getFavoritesForBooks($bookIds, $fingerprint ?: $_SERVER['REMOTE_ADDR']);
}

// ============================================
// ПОЛУЧАЕМ СТАТИСТИКУ
// ============================================
$stats = Cache::get('collection_stats_sidebar', 'statistics');
if ($stats === null) {
    $stats = $db->getCollectionStats();
    Cache::set('collection_stats_sidebar', $stats, 'statistics', 3600);
}

// ============================================
// ПОЛУЧАЕМ КНИГИ ДЛЯ ПРОДОЛЖЕНИЯ ЧТЕНИЯ
// ============================================
$continueBooks = [];
if ($fingerprint) {
    $continueBooks = $db->getContinueReading($fingerprint, 4);
}

// ============================================
// ПОЛУЧАЕМ СЛУЧАЙНЫЕ КНИГИ ДЛЯ HERO-БЛОКА
// ============================================
$randomBooks = [];
$randomKey = 'random_books_v2';
$randomBooks = Cache::get($randomKey, 'statistics');

if ($randomBooks === null || count($randomBooks) < 5) {
    try {
        $stmt = $db->getConnection()->query("
            SELECT id, title, author, file_type
            FROM books
            ORDER BY RANDOM()
            LIMIT 6
        ");
        $randomBooks = $stmt->fetchAll();
        Cache::set($randomKey, $randomBooks, 'statistics', 3600);
    } catch (Exception $e) {
        $randomBooks = [];
    }
}

// ============================================
// Заголовок
// ============================================
require 'templates/header.php';
?>

<!-- ============================================
     СТИЛИ ДЛЯ СТРАНИЦЫ
     ============================================ -->
<style>
/* HERO-СЕКЦИЯ С КАРУСЕЛЬЮ ОБЛОЖЕК */
.hero-section {
    background: linear-gradient(135deg, #477ac4 10%, #433a4b 100%);
    padding: 30px 0;
    margin-bottom: 30px;
    border-radius: 16px;
    color: white;
    position: relative;
    overflow: hidden;
    min-height: 200px;
}

.hero-section::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 400px;
    height: 400px;
    background: rgba(255,255,255,0.05);
    border-radius: 50%;
}

.hero-section .display-4 {
    font-weight: 700;
    font-size: 2.2rem;
    position: relative;
    z-index: 1;
}

.hero-section .lead {
    font-size: 1rem;
    opacity: 0.9;
    position: relative;
    z-index: 1;
}

.hero-section .search-box {
    max-width: 450px;
    margin: 0 auto;
    position: relative;
    z-index: 1;
}

.hero-section .search-box .form-control {
    border-radius: 30px 0 0 30px;
    border: none;
    padding: 10px 18px;
    font-size: 0.95rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.hero-section .search-box .btn {
    border-radius: 0 30px 30px 0;
    padding: 10px 20px;
    background: white;
    color: #667eea;
    font-weight: 600;
}

.hero-section .search-box .btn:hover {
    background: #f8f9fa;
    color: #764ba2;
}

/* Мини-карусель обложек */
.random-covers {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    perspective: 1000px;
    padding: 10px 0;
}

.random-cover-link {
    display: block;
    width: 70px;
    height: 98px;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    animation: floatBooks 3s ease-in-out infinite;
    flex-shrink: 0;
}

.random-cover-link:hover {
    transform: translateY(-10px) scale(1.05);
    box-shadow: 0 15px 40px rgba(0,0,0,0.4);
    z-index: 10;
}

.random-cover-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

@keyframes floatBooks {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-8px); }
}

.random-cover-link:nth-child(1) { animation-delay: 0s; }
.random-cover-link:nth-child(2) { animation-delay: 0.5s; }
.random-cover-link:nth-child(3) { animation-delay: 1s; }
.random-cover-link:nth-child(4) { animation-delay: 1.5s; }
.random-cover-link:nth-child(5) { animation-delay: 2s; }
.random-cover-link:nth-child(6) { animation-delay: 2.5s; }

/* Блоки поиска - скрыты по умолчанию, показываются только при поиске */
.search-blocks {
    display: none;
}

.search-blocks.visible {
    display: block;
}

/* Адаптивность */
@media (max-width: 992px) {
    .hero-section {
        padding: 25px 0;
    }

    .random-covers {
        gap: 6px;
    }

    .random-cover-link {
        width: 55px;
        height: 77px;
    }
}

@media (max-width: 768px) {
    .hero-section {
        padding: 20px 0;
    }

    .hero-section .display-4 {
        font-size: 1.6rem;
    }

    .hero-section .lead {
        font-size: 0.9rem;
    }

    .random-covers {
        gap: 4px;
        padding: 5px 0;
    }

    .random-cover-link {
        width: 45px;
        height: 63px;
    }
}

@media (max-width: 576px) {
    .random-cover-link {
        width: 38px;
        height: 53px;
    }

    .random-cover-link:nth-child(n+4) {
        display: none;
    }
}


/* Карточки книг */
.book-card {
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                box-shadow 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: none;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
    height: 100%;
}

.book-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 15px 40px rgba(0,0,0,0.12) !important;
}

.book-cover-wrapper {
    position: relative;
    overflow: hidden;
    background: #f8f9fa;
    aspect-ratio: 2/3;
}

.book-cover-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
}

.book-card:hover .book-cover-img {
    transform: scale(1.03);
}

.book-cover-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #f5f5f5 0%, #e8e8e8 100%);
}

/* Кнопки на обложке */
.cover-actions {
    position: absolute;
    bottom: 12px;
    left: 50%;
    transform: translateX(-50%) translateY(10px);
    display: flex;
    gap: 6px;
    opacity: 0;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    background: rgba(0,0,0,0.7);
    padding: 6px 10px;
    border-radius: 30px;
    backdrop-filter: blur(8px);
    z-index: 5;
}

.book-cover-wrapper:hover .cover-actions {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}

.cover-btn {
    width: 32px;
    height: 32px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    font-size: 12px;
    border: none;
    transition: all 0.2s ease;
}

.cover-btn:hover {
    transform: scale(1.1);
}

/* Бейджи */
.format-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    font-size: 9px;
    padding: 2px 8px;
    background: rgba(0,0,0,0.75) !important;
    border-radius: 12px;
    letter-spacing: 0.5px;
    z-index: 2;
}

.archive-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    font-size: 9px;
    padding: 2px 6px;
    background: rgba(0,0,0,0.5) !important;
    border-radius: 12px;
    z-index: 2;
}

/* Виджет "Продолжить чтение" */
.continue-reading {
    background: #f8f9fa;
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 30px;
    border: 1px solid #e9ecef;
}

.continue-reading .section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
}

.continue-card {
    border: none;
    border-radius: 10px;
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    background: white;
    text-decoration: none;
    color: inherit;
    display: block;
}

.continue-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    text-decoration: none;
    color: inherit;
}

.continue-card .card-img-top {
    height: 140px;
    object-fit: cover;
}

.continue-card .progress {
    height: 4px;
    border-radius: 2px;
}

/* Вид "Список" */
#booksGrid.list-view .book-item {
    flex: 0 0 100%;
    max-width: 100%;
    margin-bottom: 6px;
}

#booksGrid.list-view .book-card {
    flex-direction: row;
    align-items: stretch;
    min-height: 70px;
    max-height: 90px;
    border: 1px solid #e9ecef;
}

#booksGrid.list-view .book-cover-wrapper {
    width: 65px;
    min-height: 70px;
    max-height: 90px;
    flex-shrink: 0;
}

#booksGrid.list-view .book-cover-img {
    width: 65px;
    height: 100%;
    object-fit: cover;
}

#booksGrid.list-view .book-cover-placeholder {
    width: 65px;
    height: 100%;
}

#booksGrid.list-view .cover-actions,
#booksGrid.list-view .format-badge,
#booksGrid.list-view .archive-badge {
    display: none;
}

#booksGrid.list-view .card-body {
    padding: 6px 12px;
    display: flex;
    flex-direction: row;
    align-items: center;
    min-height: 70px;
    max-height: 90px;
    gap: 10px;
}

#booksGrid.list-view .book-info {
    flex: 1;
    min-width: 0;
}

#booksGrid.list-view .card-body .card-title {
    font-size: 0.9rem;
    margin-bottom: 1px;
    font-weight: 600;
}

#booksGrid.list-view .card-body .card-text {
    font-size: 0.75rem;
    margin-bottom: 1px;
}

#booksGrid.list-view .book-rating-mini {
    font-size: 0.65rem;
}

#booksGrid.list-view .list-actions {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
    padding-left: 8px;
    border-left: 1px solid #e9ecef;
}

#booksGrid.list-view .list-actions .btn {
    padding: 4px 8px;
    font-size: 0.7rem;
    border-radius: 6px;
    border: 1px solid transparent;
    transition: all 0.2s ease;
}

#booksGrid.list-view .list-actions .btn-download {
    color: #28a745;
    border-color: #28a745;
}

#booksGrid.list-view .list-actions .btn-download:hover {
    background: #28a745;
    color: #fff;
}

#booksGrid.list-view .list-actions .btn-read {
    color: #007bff;
    border-color: #007bff;
}

#booksGrid.list-view .list-actions .btn-read:hover {
    background: #007bff;
    color: #fff;
}

#booksGrid.list-view .list-actions .btn-favorite {
    color: #dc3545;
    border-color: #dc3545;
}

#booksGrid.list-view .list-actions .btn-favorite:hover,
#booksGrid.list-view .list-actions .btn-favorite.active {
    background: #dc3545;
    color: #fff;
}

#booksGrid.list-view .list-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.65rem;
    color: #adb5bd;
    flex-shrink: 0;
}

#booksGrid.list-view .list-meta .badge {
    font-size: 0.55rem;
    padding: 1px 6px;
}

/* Анимация появления карточек */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.book-item {
    animation: fadeInUp 0.4s ease forwards;
    opacity: 0;
}

.book-item:nth-child(1) { animation-delay: 0.05s; }
.book-item:nth-child(2) { animation-delay: 0.10s; }
.book-item:nth-child(3) { animation-delay: 0.15s; }
.book-item:nth-child(4) { animation-delay: 0.20s; }
.book-item:nth-child(5) { animation-delay: 0.25s; }
.book-item:nth-child(6) { animation-delay: 0.30s; }
.book-item:nth-child(7) { animation-delay: 0.35s; }
.book-item:nth-child(8) { animation-delay: 0.40s; }
.book-item:nth-child(9) { animation-delay: 0.45s; }
.book-item:nth-child(10) { animation-delay: 0.50s; }
.book-item:nth-child(11) { animation-delay: 0.55s; }
.book-item:nth-child(12) { animation-delay: 0.60s; }

/* Индикатор загрузки */
#loadingIndicator .spinner-border {
    width: 40px;
    height: 40px;
}

#loadMoreBtn {
    transition: all 0.3s ease;
}

#loadMoreBtn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

#endMessage {
    opacity: 0.7;
    font-size: 0.9rem;
}

/* Статистика в боковой панели */
.stats-card .stat-item {
    padding: 6px 0;
    border-bottom: 1px solid #f0f0f0;
}

.stats-card .stat-item:last-child {
    border-bottom: none;
}

.stats-card .stat-number {
    font-weight: 700;
    color: #2c3e50;
}

.stats-card .stat-label {
    color: #6c757d;
    font-size: 0.85rem;
}

/* Темная тема */
body.dark-theme .book-card {
    background: #2d2d2d;
}

body.dark-theme .book-card .card-title a {
    color: #e0e0e0;
}

body.dark-theme .book-card .card-title a:hover {
    color: #66b0ff;
}

body.dark-theme .book-card .card-text a {
    color: #b0b0b0;
}

body.dark-theme .book-card .card-text a:hover {
    color: #66b0ff;
}

body.dark-theme .continue-reading {
    background: #2d2d2d;
    border-color: #404040;
}

body.dark-theme .continue-reading .section-title {
    color: #e0e0e0;
}

body.dark-theme .continue-card {
    background: #3d3d3d;
}

body.dark-theme .continue-card .text-muted {
    color: #b0b0b0 !important;
}

body.dark-theme #booksGrid.list-view .book-card {
    background: #2d2d2d;
    border-color: #404040;
}

body.dark-theme #booksGrid.list-view .list-actions {
    border-left-color: #404040;
}

body.dark-theme .stats-card .stat-number {
    color: #e0e0e0;
}

/* Адаптивность */
@media (max-width: 768px) {
    .hero-section {
        padding: 25px 0;
    }

    .hero-section .display-4 {
        font-size: 1.8rem;
    }

    .hero-section .lead {
        font-size: 0.95rem;
    }

    .continue-card .card-img-top {
        height: 100px;
    }

    #booksGrid.list-view .book-card {
        max-height: 80px;
        min-height: 65px;
    }

    #booksGrid.list-view .book-cover-wrapper {
        width: 55px;
        min-height: 65px;
        max-height: 80px;
    }

    #booksGrid.list-view .book-cover-img {
        width: 55px;
    }

    #booksGrid.list-view .card-body {
        padding: 4px 10px;
        min-height: 65px;
        max-height: 80px;
        gap: 6px;
    }

    #booksGrid.list-view .card-body .card-title {
        font-size: 0.8rem;
    }

    #booksGrid.list-view .list-meta {
        display: none;
    }

    #booksGrid.list-view .list-actions .btn {
        padding: 2px 6px;
        font-size: 0.6rem;
    }
}

@media (max-width: 576px) {
    #booksGrid.list-view .book-card {
        max-height: 70px;
        min-height: 55px;
    }

    #booksGrid.list-view .book-cover-wrapper {
        width: 45px;
        min-height: 55px;
        max-height: 70px;
    }

    #booksGrid.list-view .book-cover-img {
        width: 45px;
    }

    #booksGrid.list-view .card-body {
        padding: 3px 8px;
        min-height: 55px;
        max-height: 70px;
        gap: 4px;
    }

    #booksGrid.list-view .card-body .card-title {
        font-size: 0.7rem;
    }

    #booksGrid.list-view .card-body .card-text {
        font-size: 0.6rem;
    }

    #booksGrid.list-view .book-rating-mini {
        font-size: 0.55rem;
    }

    #booksGrid.list-view .list-actions {
        padding-left: 4px;
        gap: 2px;
    }

    #booksGrid.list-view .list-actions .btn {
        padding: 1px 4px;
        font-size: 0.55rem;
    }
}

/* Мини-карусель в hero-блоке */
.random-covers {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    perspective: 1000px;
    padding: 20px 0;
}

.random-cover-link {
    display: block;
    width: 80px;
    height: 112px;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    animation: floatBooks 3s ease-in-out infinite;
    flex-shrink: 0;
}

.random-cover-link:hover {
    transform: translateY(-10px) scale(1.05);
    box-shadow: 0 15px 40px rgba(0,0,0,0.4);
    z-index: 10;
}

.random-cover-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

@keyframes floatBooks {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-8px); }
}

.random-cover-link:nth-child(2) { animation-delay: 0.5s; }
.random-cover-link:nth-child(3) { animation-delay: 1s; }
.random-cover-link:nth-child(4) { animation-delay: 1.5s; }
.random-cover-link:nth-child(5) { animation-delay: 2s; }

</style>

<!-- ============================================
     HERO-СЕКЦИЯ С ПОИСКОМ И КАРУСЕЛЬЮ
     ============================================ -->
<div class="hero-section text-center">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <h1 class="display-4 mb-2">
                    <i class="fas fa-book-open me-3"></i>
                    <?= htmlspecialchars(Config::getSiteTitle()) ?>
                </h1>
                <p class="lead mb-3"><?= __('footer_tagline') ?></p>

                <!-- Строка поиска в HERO (всегда видна) -->
                <div class="search-box mx-auto">
                    <form method="get" action="index.php" class="d-flex">
                        <input type="text" class="form-control" name="q"
                               value="<?= htmlspecialchars($searchQuery) ?>"
                               placeholder="<?= __('search_placeholder') ?>"
                               aria-label="Поиск книг">
                        <button class="btn" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>
            </div>
            <div class="col-lg-5 d-none d-lg-block">
                <!-- Мини-карусель случайных обложек -->
                <?php if (!empty($randomBooks)): ?>
                <div class="random-covers">
                    <?php
                    $coverBooks = array_slice($randomBooks, 0, 5);
                    foreach ($coverBooks as $index => $book):
                        ?>
                    <a href="book_detail.php?id=<?= $book['id'] ?>"
                       class="random-cover-link"
                       title="<?= htmlspecialchars($book['title']) ?>">
                        <img src="./api/cover.php?id=<?= $book['id'] ?>&thumb=1"
                             alt="<?= htmlspecialchars($book['title']) ?>"
                             class="random-cover-img"
                             loading="lazy"
                             onerror="this.style.display='none'">
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================
     ОСНОВНАЯ ОБЛАСТЬ
     ============================================ -->
<div class="row">
    <!-- ============================================
         БОКОВАЯ ПАНЕЛЬ
         ============================================ -->
    <div class="col-lg-3 order-lg-1 mb-4">

        <!-- БЛОКИ ПОИСКА (показываются только при поиске) -->
        <div class="search-blocks <?= $isSearch ? 'visible' : '' ?>">
            <!-- Поиск -->
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-search me-2 text-primary"></i>
                        <?= __('search') ?>
                    </h5>
                    <form method="get" action="index.php">
                        <div class="mb-2">
                            <input type="text" class="form-control" name="q"
                                   value="<?= htmlspecialchars($searchQuery) ?>"
                                   placeholder="<?= __('search_placeholder') ?>">
                        </div>
                        <div class="mb-2">
                            <select class="form-select form-select-sm" name="field">
                                <option value="all" <?= $searchField === 'all' ? 'selected' : '' ?>>
                                    <?= __('search_all') ?>
                                </option>
                                <option value="title" <?= $searchField === 'title' ? 'selected' : '' ?>>
                                    <?= __('search_title') ?>
                                </option>
                                <option value="author" <?= $searchField === 'author' ? 'selected' : '' ?>>
                                    <?= __('search_author') ?>
                                </option>
                                <option value="genre" <?= $searchField === 'genre' ? 'selected' : '' ?>>
                                    <?= __('search_genre') ?>
                                </option>
                                <option value="series" <?= $searchField === 'series' ? 'selected' : '' ?>>
                                    <?= __('search_series') ?>
                                </option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-search me-1"></i><?= __('search_button') ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Быстрый поиск -->
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-bolt me-2 text-warning"></i>
                        <?= __('quick_search') ?>
                    </h5>
                    <div class="d-flex flex-wrap gap-1">
                        <a href="?field=author&q=" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-user me-1"></i><?= __('by_authors') ?>
                        </a>
                        <a href="?field=genre&q=" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-tag me-1"></i><?= __('by_genres') ?>
                        </a>
                        <a href="?field=series&q=" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-layer-group me-1"></i><?= __('by_series') ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- СТАТИСТИКА (всегда видна) -->
        <div class="card shadow-sm stats-card">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="fas fa-chart-bar me-2 text-success"></i>
                    <?= __('stats') ?>
                </h5>
                <div class="stat-item d-flex justify-content-between">
                    <span class="stat-label"><?= __('stats_total_books') ?></span>
                    <span class="stat-number"><?= number_format($stats['total_books'] ?? 0, 0, '', ' ') ?></span>
                </div>
                <div class="stat-item d-flex justify-content-between">
                    <span class="stat-label"><?= __('stats_total_authors') ?></span>
                    <span class="stat-number"><?= number_format($stats['total_authors'] ?? 0, 0, '', ' ') ?></span>
                </div>
                <div class="stat-item d-flex justify-content-between">
                    <span class="stat-label"><?= __('stats_total_genres') ?></span>
                    <span class="stat-number"><?= number_format($stats['total_genres'] ?? 0, 0, '', ' ') ?></span>
                </div>
                <div class="stat-item d-flex justify-content-between">
                    <span class="stat-label"><?= __('stats_total_ratings') ?></span>
                    <span class="stat-number"><?= number_format($stats['total_ratings'] ?? 0, 0, '', ' ') ?></span>
                </div>
                <hr class="my-2">
                <div class="text-center">
                    <small class="text-muted">
                        <i class="far fa-calendar-alt me-1"></i>
                        <?= __('last_update') ?> <?= date('d.m.Y', strtotime($stats['last_update'] ?? date('Y-m-d'))) ?>
                    </small>
                </div>
            </div>
        </div>





<!-- ============================================
     ФИЛЬТР ПО ЖАНРАМ
     ============================================ -->
<?php
// Получаем список жанров с количеством книг
$genresList = Cache::get('genres_list_sidebar', 'statistics');
if ($genresList === null) {
    try {
        $stmt = $db->getConnection()->query("
            SELECT genre, COUNT(*) as count
            FROM books
            WHERE genre IS NOT NULL AND genre != ''
            GROUP BY genre
            ORDER BY count DESC
            LIMIT 40
        ");
        $genresList = $stmt->fetchAll();
        Cache::set('genres_list_sidebar', $genresList, 'statistics', 3600);
    } catch (Exception $e) {
        $genresList = [];
    }
}

// Текущий выбранный жанр
$currentGenre = $_GET['genre'] ?? '';
?>

<div class="card shadow-sm mb-4 genres-filter">
    <div class="card-body">
        <h5 class="card-title d-flex justify-content-between align-items-center">
            <span>
                <i class="fas fa-tags me-2 text-primary"></i>
                <?= __('book_genre') ?>
            </span>
            <?php if ($currentGenre): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['genre' => null])) ?>" 
               class="btn btn-sm btn-outline-secondary" 
               title="Сбросить фильтр">
                <i class="fas fa-times"></i>
            </a>
            <?php endif; ?>
        </h5>
        
        <div class="genre-cloud">
            <?php if (empty($genresList)): ?>
                <span class="text-muted small">Нет жанров</span>
            <?php else: ?>
                <?php foreach ($genresList as $genre):
                    $genreCode = $genre['genre'];
                    $genreName = $db->getReadableGenre($genreCode) ?: $genreCode;
                    $count = $genre['count'];
                    $isActive = ($currentGenre === $genreCode);

                    // Вычисляем размер шрифта в зависимости от количества книг
                    $maxCount = $genresList[0]['count'] ?? 1;
                    $size = 0.7 + ($count / $maxCount) * 0.2; // от 0.7 до 1.2
                    $size = min(1.2, max(0.7, $size));
                    ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['genre' => $genreCode, 'page' => null])) ?>" 
                   class="genre-tag <?= $isActive ? 'active' : '' ?>"
                   style="font-size: <?= $size ?>rem;"
                   title="<?= htmlspecialchars($genreName) ?> (<?= $count ?> книг)">
                    <?= htmlspecialchars(mb_substr($genreName, 0, 20)) ?>
                    <span class="genre-count"><?= $count ?></span>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if ($currentGenre): ?>
        <div class="mt-2 text-center">
            <small class="text-muted">
                <i class="fas fa-filter me-1"></i>
                Фильтр: <strong><?= htmlspecialchars($db->getReadableGenre($currentGenre) ?: $currentGenre) ?></strong>
                <a href="?<?= http_build_query(array_merge($_GET, ['genre' => null])) ?>" 
                   class="text-decoration-none ms-1">
                    <i class="fas fa-times-circle text-danger"></i>
                </a>
            </small>
        </div>
        <?php endif; ?>
    </div>
</div>

    </div>

    <!-- ============================================
         ОСНОВНАЯ ОБЛАСТЬ
         ============================================ -->
    <div class="col-lg-9 order-lg-2">

        <!-- ВИДЖЕТ: ПРОДОЛЖИТЬ ЧТЕНИЕ -->
        <?php if (!empty($continueBooks) && !$isSearch): ?>
        <div class="continue-reading">
            <div class="d-flex align-items-center mb-3">
                <i class="fas fa-clock text-primary me-2 fa-lg"></i>
                <h5 class="section-title mb-0">📖 <?= __('continue_reading') ?></h5>
                <span class="badge bg-primary ms-2"><?= count($continueBooks) ?></span>
                <div class="ms-auto">
                    <a href="bookmarks.php" class="btn btn-sm btn-outline-secondary">
                        <?= __('bookmarks') ?> <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
            <div class="row g-2">
                <?php foreach ($continueBooks as $book): ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <a href="reader.php?id=<?= $book['id'] ?>&page=<?= max(1, $book['page_number'] ?? 1) ?>"
                       class="continue-card">
                        <img src="./api/cover.php?id=<?= $book['id'] ?>&thumb=1"
                             class="card-img-top"
                             alt="<?= htmlspecialchars($book['title']) ?>"
                             loading="lazy"
                             onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22140%22%3E%3Crect width=%22200%22 height=%22140%22 fill=%22%23f0f0f0%22/%3E%3Ctext x=%2250%%22 y=%2250%%22 text-anchor=%22middle%22 dominant-baseline=%22middle%22 fill=%22%23999%22 font-size=%2214%22%3EНет обложки%3C/text%3E%3C/svg%3E'">
                        <div class="p-2">
                            <div class="small text-truncate" title="<?= htmlspecialchars($book['title']) ?>">
                                <strong><?= htmlspecialchars(mb_substr($book['title'] ?: "<?= __('book_untitled') ?>", 0, 25)) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted text-truncate">
                                    <?= htmlspecialchars(mb_substr($book['author'] ?: '', 0, 20)) ?>
                                </small>
                                <span class="badge bg-primary small">
                                    <?= round($book['percentage'] ?? 0) ?>%
                                </span>
                            </div>
                            <div class="progress mt-1">
                                <div class="progress-bar" style="width: <?= min(100, $book['percentage'] ?? 0) ?>%"></div>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ЗАГОЛОВОК И УПРАВЛЕНИЕ -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
        <?php if (!empty($currentGenre)):
            $genreName = $db->getReadableGenre($currentGenre) ?: $currentGenre;
            ?>
            <h4 class="mb-0">
                <i class="fas fa-tag text-primary me-2"></i>
                <?= htmlspecialchars($genreName) ?>
                <small class="text-muted ms-2" id="booksCount">
                    (<?= number_format($totalBooks, 0, '', ' ') ?> <?= __('books') ?>)
                </small>
                <a href="?<?= http_build_query(array_merge($_GET, ['genre' => null])) ?>" 
                   class="btn btn-sm btn-outline-secondary ms-2" 
                   title="<?= __('filter_reset') ?>">
                    <i class="fas fa-times me-1"></i><?= __('filter_reset') ?>
                </a>
            </h4>
        <?php elseif ($isSearch && !empty($searchQuery)): ?>
            <h4 class="mb-0">
                <i class="fas fa-search text-primary me-2"></i>
                <?= sprintf(__('search_results_for'), htmlspecialchars($searchQuery)) ?>
                <small class="text-muted ms-2" id="booksCount">
                    (<?= number_format($totalBooks, 0, '', ' ') ?>)
                </small>
            </h4>
        <?php else: ?>
            <h4 class="mb-0">
                <i class="fas fa-clock text-primary me-2"></i>
                <?= __('recent_books') ?>
                <small class="text-muted ms-2" id="booksCount">
                    (<?= number_format($totalBooks, 0, '', ' ') ?>)
                </small>
            </h4>
        <?php endif; ?>
    </div>


            <div class="d-flex gap-2 align-items-center">
                <!-- Переключатель вида -->
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary active" id="gridViewBtn"
                            title="<?= __('grid_view') ?>">
                        <i class="fas fa-th"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="listViewBtn"
                            title="<?= __('list_view') ?>">
                        <i class="fas fa-list"></i>
                    </button>
                </div>

                <!-- Сортировка -->
                <select class="form-select form-select-sm" id="sortSelect" style="width: auto;">
                    <option value="new" <?= ($filters['sort'] ?? 'new') === 'new' ? 'selected' : '' ?>>
                        <?= __('sort_newest') ?>
                    </option>
                    <option value="title" <?= ($filters['sort'] ?? 'new') === 'title' ? 'selected' : '' ?>>
                        <?= __('sort_by_title') ?>
                    </option>
                    <option value="author" <?= ($filters['sort'] ?? 'new') === 'author' ? 'selected' : '' ?>>
                        <?= __('sort_by_author') ?>
                    </option>
                </select>
            </div>
        </div>

        <!-- СЕТКА КНИГ С БЕСКОНЕЧНЫМ СКРОЛЛОМ -->
        <?php if (empty($books)): ?>
            <div class="text-center py-5">
                <i class="fas fa-book-open fa-4x text-muted mb-3"></i>
                <h4 class="text-muted">
                    <?php if ($isSearch): ?>
                        <?= sprintf(__('search_no_results'), htmlspecialchars($searchQuery)) ?>
                    <?php else: ?>
                        <?= __('catalog_empty') ?>
                    <?php endif; ?>
                </h4>
                <?php if ($isSearch): ?>
                    <a href="index.php" class="btn btn-primary mt-3">
                        <i class="fas fa-arrow-left me-2"></i>
                        <?= __('back_to_list') ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="row g-3" id="booksGrid">
                <?php foreach ($books as $book):
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


                    $title = htmlspecialchars($book['title'] ?: "<?= __('book_untitled') ?>", ENT_QUOTES, 'UTF-8');
                    $author = htmlspecialchars($book['author'] ?: '', ENT_QUOTES, 'UTF-8');
                    $genre = htmlspecialchars($readableGenre ?: '', ENT_QUOTES, 'UTF-8');
                    $authorUrl = urlencode($book['author'] ?? '');
                    $fileType = strtoupper(htmlspecialchars($book['file_type'] ?: '?', ENT_QUOTES, 'UTF-8'));
                    $addedDate = date('d.m.Y', strtotime($book['added_date']));
                    $series = htmlspecialchars(mb_substr($book['series'] ?? '', 0, 20), ENT_QUOTES, 'UTF-8');
                    $hasArchive = !empty($book['archive_path']);

                    // Рейтинг HTML
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

                    $favBtnClass = $isFavorite ? 'btn-danger' : 'btn-outline-danger';
                    $favIconClass = $isFavorite ? 'fas' : 'far';
                    $favTitle = $isFavorite ? __('favorites_remove') : __('favorites_add');
                    $listFavClass = $isFavorite ? 'active' : '';
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
                                       title=<?= __('download_book') ?>>
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <a href="reader.php?id=<?= $bookId ?>"
                                       class="btn btn-sm btn-primary cover-btn"
                                       title=<?= __('read_online') ?>>
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
                                       title=<?= __('download') ?>>
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <a href="reader.php?id=<?= $bookId ?>"
                                       class="btn btn-read"
                                       title=<?= __('read_online') ?>>
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
                <?php endforeach; ?>
            </div>

            <!-- ИНДИКАТОР ЗАГРУЗКИ -->
            <div id="loadingIndicator" class="text-center py-4" style="display: none;">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden"><?= __('reader_loading') ?></span>
                </div>
                <p class="text-muted mt-2"><?= __('reader_loading') ?></p>
            </div>

            <!-- КНОПКА "ПОКАЗАТЬ ЕЩЁ" -->
            <div class="text-center mt-3" id="loadMoreContainer">
                <button class="btn btn-outline-primary" id="loadMoreBtn" style="display: none;">
                    <i class="fas fa-chevron-down me-2"></i>
                    <?= __('show_more') ?>

                </button>
            </div>

            <!-- СООБЩЕНИЕ ОБ ОКОНЧАНИИ -->
            <div id="endMessage" class="text-center text-muted py-3" style="display: none;">
                <i class="fas fa-check-circle me-2"></i>
                <?= __('all_books_loaded') ?>
            </div>

            <!-- Скрытые данные для JavaScript -->
<div id="scrollData" 
     data-page="1" 
     data-total-pages="<?= $totalPages ?>"
     data-total-books="<?= $totalBooks ?>"
     data-per-page="<?= $itemsPerPage ?>"
     data-search="<?= htmlspecialchars($searchQuery) ?>"
     data-field="<?= $searchField ?>"
     data-sort="<?= $filters['sort'] ?? 'new' ?>"
     data-lang="<?= $filters['lang'] ?? '' ?>"
     data-format="<?= $filters['format'] ?? '' ?>"
     data-genre="<?= htmlspecialchars($currentGenre) ?>"
     data-author="<?= $filters['author'] ?? '' ?>"
     data-year="<?= $filters['year'] ?? '' ?>"
     style="display: none;">
</div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================
     JAVASCRIPT
     ============================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== ПЕРЕКЛЮЧЕНИЕ ВИДА =====
    const gridViewBtn = document.getElementById('gridViewBtn');
    const listViewBtn = document.getElementById('listViewBtn');
    const booksGrid = document.getElementById('booksGrid');

    if (gridViewBtn && listViewBtn && booksGrid) {
        const savedView = localStorage.getItem('booksView') || 'grid';

        function setView(view) {
            booksGrid.classList.remove('grid-view', 'list-view');

            if (view === 'list') {
                booksGrid.classList.add('list-view');
                gridViewBtn.classList.remove('active');
                listViewBtn.classList.add('active');

                document.querySelectorAll('.book-item').forEach(item => {
                    item.style.flex = '0 0 100%';
                    item.style.maxWidth = '100%';
                });
            } else {
                booksGrid.classList.add('grid-view');
                gridViewBtn.classList.add('active');
                listViewBtn.classList.remove('active');

                document.querySelectorAll('.book-item').forEach(item => {
                    item.style.flex = '';
                    item.style.maxWidth = '';
                });
            }

            localStorage.setItem('booksView', view);
        }

        setView(savedView);

        gridViewBtn.addEventListener('click', function(e) {
            e.preventDefault();
            setView('grid');
        });

        listViewBtn.addEventListener('click', function(e) {
            e.preventDefault();
            setView('list');
        });
    }

    // ===== СОРТИРОВКА =====
    const sortSelect = document.getElementById('sortSelect');
    if (sortSelect) {
        sortSelect.addEventListener('change', function() {
            const url = new URL(window.location.href);
            url.searchParams.set('sort', this.value);
            window.location.href = url.toString();
        });
    }

    // ============================================
    // БЕСКОНЕЧНЫЙ СКРОЛЛ
    // ============================================
    const scrollData = document.getElementById('scrollData');
    if (!scrollData) return;

    let currentPage = parseInt(scrollData.dataset.page);
    const totalPages = parseInt(scrollData.dataset.totalPages);
    const perPage = parseInt(scrollData.dataset.perPage);
    const searchQuery = scrollData.dataset.search;
    const searchField = scrollData.dataset.field;
    const sort = scrollData.dataset.sort;
    const lang = scrollData.dataset.lang;
    const format = scrollData.dataset.format;
    const genre = scrollData.dataset.genre;
    const author = scrollData.dataset.author;
    const year = scrollData.dataset.year;

    let isLoading = false;
    let hasMore = currentPage < totalPages;

    const loadingIndicator = document.getElementById('loadingIndicator');
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    const endMessage = document.getElementById('endMessage');
    const booksCount = document.getElementById('booksCount');

    if (hasMore && totalPages > 1) {
        loadMoreBtn.style.display = 'inline-block';
    }

async function loadMoreBooks() {
    if (isLoading || !hasMore) return;
    
    isLoading = true;
    const nextPage = currentPage + 1;
    
    loadingIndicator.style.display = 'block';
    loadMoreBtn.disabled = true;
    loadMoreBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?= __('reader_loading') ?>';
    
    try {
        // Строим URL для API с учётом ВСЕХ параметров
        let url = `./api/books.php?page=${nextPage}&per_page=${perPage}&sort=${sort}`;
        
        // Добавляем все параметры, которые есть
        if (searchQuery) {
            url += `&q=${encodeURIComponent(searchQuery)}&field=${searchField}`;
        }
        if (lang) url += `&lang=${lang}`;
        if (format) url += `&format=${format}`;
        if (genre) url += `&genre=${encodeURIComponent(genre)}`;
        if (author) url += `&author=${encodeURIComponent(author)}`;
        if (year) url += `&year=${year}`;
        
        console.log('Loading more books with URL:', url); // Для отладки
        
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success && data.html) {
            // Добавляем новые карточки
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = data.html;
            
            while (tempDiv.firstChild) {
                booksGrid.appendChild(tempDiv.firstChild);
            }
            
            // Обновляем состояние
            currentPage = data.page;
            hasMore = data.has_more;
            
            // Обновляем счётчик
            if (booksCount && data.total_books) {
                const count = parseInt(data.total_books);
                booksCount.textContent = `(${count.toLocaleString()})`;
            }
            
            // Обновляем кнопку
            if (hasMore) {
                loadMoreBtn.style.display = 'inline-block';
                loadMoreBtn.innerHTML = '<i class="fas fa-chevron-down me-2"></i><?= __('show_more') ?>';
                loadMoreBtn.disabled = false;
            } else {
                loadMoreBtn.style.display = 'none';
                endMessage.style.display = 'block';
            }
            
            // Инициализируем новые элементы
            initializeFavoriteButtons();
            initializeRatings();
            
        } else {
            console.error('Failed to load books:', data.message);
            loadMoreBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i><?= __('error_loading') ?>';
        }
        
    } catch (error) {
        console.error('Error loading books:', error);
        loadMoreBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i><?= __('error_loading') ?>';
    } finally {
        isLoading = false;
        loadingIndicator.style.display = 'none';
    }
}

    function initializeFavoriteButtons() {
        document.querySelectorAll('.favorite-btn:not([data-event-bound])').forEach(btn => {
            btn.dataset.eventBound = 'true';
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (this.disabled) return;
                const bookId = this.dataset.bookId;
                if (bookId && window.toggleFavorite) {
                    window.toggleFavorite(bookId, this);
                }
            });
        });
    }

    function initializeRatings() {
        document.querySelectorAll('.book-rating-mini:not([data-initialized])').forEach(el => {
            el.dataset.initialized = 'true';
            const bookId = el.dataset.bookId;
            if (bookId && window.loadBookRating) {
                window.loadBookRating(bookId, el);
            }
        });
    }

    loadMoreBtn.addEventListener('click', loadMoreBooks);

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && hasMore && !isLoading) {
                    loadMoreBooks();
                }
            });
        }, {
            rootMargin: '200px',
            threshold: 0.1
        });

        function observeLastItem() {
            const items = booksGrid.querySelectorAll('.book-item');
            if (items.length > 0) {
                const lastItem = items[items.length - 1];
                observer.observe(lastItem);
            }
        }

        const mutationObserver = new MutationObserver(() => {
            observeLastItem();
        });

        mutationObserver.observe(booksGrid, {
            childList: true,
            subtree: false
        });

        observeLastItem();
    } else {
        let scrollTimeout;
        window.addEventListener('scroll', function() {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                const scrollPosition = window.scrollY + window.innerHeight;
                const documentHeight = document.documentElement.scrollHeight;

                if (scrollPosition >= documentHeight - 300 && hasMore && !isLoading) {
                    loadMoreBooks();
                }
            }, 200);
        });
    }

    initializeFavoriteButtons();
    initializeRatings();
});
</script>

<?php
// ============================================
// ПОДКЛЮЧАЕМ ФУТЕР
// ============================================
require 'templates/footer.php';
?>
