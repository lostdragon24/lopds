<?php

require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/BookHelper.php'; // Добавляем
require_once 'lib/PageCache.php';

// Начинаем кэширование страницы
PageCache::start();

$db = Database::getInstance();
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$searchQuery = $_GET['q'] ?? '';
$searchField = $_GET['field'] ?? 'all';

if (!empty($searchQuery)) {
    $books = $db->searchBooks($searchQuery, $searchField, $page);
    $totalBooks = $db->getSearchCount($searchQuery, $searchField);
} else {
    $books = $db->getRecentBooks(Config::ITEMS_PER_PAGE);
    $totalBooks = 0;
}

$totalPages = ceil($totalBooks / Config::ITEMS_PER_PAGE);

require 'templates/header.php';
?>

<div class="row">
    <div class="col-md-3">
        <!-- Форма поиска -->
        <div class="card search-form">
            <div class="card-body">
                <h5 class="card-title">Поиск книг</h5>
                <form method="get" action="index.php">
                    <div class="mb-3">
                        <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>" 
                               placeholder="Введите запрос...">
                    </div>
                    <div class="mb-3">
                        <select class="form-select" name="field">
                            <option value="all" <?php echo $searchField === 'all' ? 'selected' : ''; ?>>Везде</option>
                            <option value="title" <?php echo $searchField === 'title' ? 'selected' : ''; ?>>Название</option>
                            <option value="author" <?php echo $searchField === 'author' ? 'selected' : ''; ?>>Автор</option>
                            <option value="genre" <?php echo $searchField === 'genre' ? 'selected' : ''; ?>>Жанр</option>
                            <option value="series" <?php echo $searchField === 'series' ? 'selected' : ''; ?>>Серия</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Найти</button>
                </form>
            </div>
        </div>

        <!-- Быстрые ссылки -->
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Быстрый поиск</h5>
                <ul class="list-unstyled">
                    <li><a href="index.php?field=author&q=">По авторам</a></li>
                    <li><a href="index.php?field=genre&q=">По жанрам</a></li>
                    <li><a href="index.php?field=series&q=">По сериям</a></li>
                </ul>
            </div>
        </div>

        <!-- Статистика -->
        <div class="card mt-3">
            <div class="card-body">
                <h5 class="card-title">Статистика</h5>
                <?php
                try {
                    $stats = $db->getCollectionStats();
                    ?>
                    <small class="text-muted">
                        Книг: <?php echo number_format($stats['total_books'], 0, '', ' '); ?><br>
                        Авторов: <?php echo number_format($stats['total_authors'], 0, '', ' '); ?><br>
                        Жанров: <?php echo number_format($stats['total_genres'], 0, '', ' '); ?>
                    </small>
                    <?php
                } catch (Exception $e) {
                    echo '<small class="text-muted">Статистика недоступна</small>';
                }
                ?>
            </div>
        </div>
    </div>

    <div class="col-md-9">
        <h2>
            <?php if (!empty($searchQuery)): ?>
                Результаты поиска: "<?php echo htmlspecialchars($searchQuery); ?>"
                <small class="text-muted">(найдено: <?php echo $totalBooks; ?>)</small>
            <?php else: ?>
                Последние добавленные книги
            <?php endif; ?>
        </h2>

        <?php if (empty($books)): ?>
            <div class="alert alert-info">
                <?php if (!empty($searchQuery)): ?>
                    Книги по запросу "<?php echo htmlspecialchars($searchQuery); ?>" не найдены.
                <?php else: ?>
                    В каталоге пока нет книг.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($books as $book): ?>
                    <div class="col-md-6 book-card">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-3">
                                        <?php
                                        // Используем новый BookHelper для проверки обложки
                                        $hasCover = BookHelper::hasCover($book);
                                        $coverUrl = "./api/cover_direct.php?id=" . $book['id'] . "&thumb=1";
                                        ?>
                                        
                                        <?php if ($hasCover): ?>
                                            <img src="<?php echo $coverUrl; ?>" 
                                                 class="book-cover img-fluid" 
                                                 alt="Обложка книги <?php echo htmlspecialchars($book['title']); ?>"
                                                 style="max-width: 100px; height: auto;"
                                                 onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                                 loading="lazy">
                                            <div class="book-cover-placeholder bg-light d-flex align-items-center justify-content-center" 
                                                 style="display:none; width:100px; height:150px;">
                                                <small class="text-muted">Ошибка загрузки</small>
                                            </div>
                                        <?php else: ?>
                                            <div class="book-cover-placeholder bg-light d-flex align-items-center justify-content-center" 
                                                 style="width:100px; height:150px;">
                                                <small class="text-muted">Нет обложки</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-9">
                                        <h6 class="card-title">
                                            <a href="book_detail.php?id=<?php echo $book['id']; ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($book['title'] ?: 'Без названия'); ?>
                                            </a>
                                        </h6>
                                        
                                        <?php if ($book['author']): ?>
                                            <p class="card-text mb-1">
                                                <small class="text-muted">
                                                    <strong>Автор:</strong> 
                                                    <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($book['author']); ?>
                                                    </a>
                                                </small>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if ($book['series']): ?>
                                            <p class="card-text mb-1">
                                                <small>
                                                    <strong>Серия:</strong> 
                                                    <a href="index.php?field=series&q=<?php echo urlencode($book['series']); ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($book['series']); ?>
                                                    </a>
                                                    <?php if ($book['series_number']): ?>
                                                        <span class="badge bg-secondary ms-1">#<?php echo $book['series_number']; ?></span>
                                                    <?php endif; ?>
                                                </small>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($book['genre']): ?>
                                            <p class="card-text mb-1">
                                                <small>
                                                    <strong>Жанр:</strong> 
                                                    <?php 
                                                    $readableGenre = $db->getReadableGenre($book['genre']);
                                                    echo htmlspecialchars($readableGenre ?: $book['genre']); 
                                                    ?>
                                                </small>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($book['year']): ?>
                                            <p class="card-text mb-1">
                                                <small><strong>Год:</strong> <?php echo $book['year']; ?></small>
                                            </p>
                                        <?php endif; ?>

                                        <div class="mt-2">
                                            <a href="book_detail.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-outline-primary">Подробнее</a>
                                            <a href="./api/download.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-success">Скачать</a>
                                            
                                            <?php if ($hasCover): ?>
                                                <small class="text-muted ms-2">✓ Есть обложка</small>
                                            <?php endif; ?>
                                            
                                            <small class="text-muted ms-2">
                                                <?php echo strtoupper($book['file_type']); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-transparent">
                                <small class="text-muted">
                                    Добавлено: <?php echo date('d.m.Y', strtotime($book['added_date'])); ?>
                                    <?php if ($book['archive_path']): ?>
                                        • В архиве
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Пагинация -->
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?q=<?php echo urlencode($searchQuery); ?>&field=<?php echo $searchField; ?>&page=<?php echo $page - 1; ?>">
                                    Назад
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php
                        // Показываем ограниченное количество страниц
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?q=<?php echo urlencode($searchQuery); ?>&field=<?php echo $searchField; ?>&page=<?php echo $i; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?q=<?php echo urlencode($searchQuery); ?>&field=<?php echo $searchField; ?>&page=<?php echo $page + 1; ?>">
                                    Вперед
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                
                <div class="text-center text-muted">
                    <small>Страница <?php echo $page; ?> из <?php echo $totalPages; ?></small>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Сохраняем страницу в кэш
PageCache::save();

require 'templates/footer.php';
?>