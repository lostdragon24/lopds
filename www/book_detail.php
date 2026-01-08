<?php

require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/BookHelper.php'; // Добавляем
require_once 'lib/Cache.php';
require_once 'lib/PageCache.php';

$db = Database::getInstance();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.0 400 Bad Request');
    die('Неверный ID книги');
}

$bookId = intval($_GET['id']);

// Начинаем кэширование страницы на 10 минут
PageCache::start('book_detail_' . $bookId . '_' . date('YmdH'));

$book = $db->getBook($bookId);

if (!$book) {
    header('HTTP/1.0 404 Not Found');
    die('Книга не найдена');
}

// Получаем читаемое название жанра
$readableGenre = $db->getReadableGenre($book['genre']);

// Проверяем наличие обложки с помощью BookHelper
$hasCover = BookHelper::hasCover($book);

// Извлекаем описание с помощью BookHelper
$description = $book['description'] ?? '';
if (empty($description)) {
    $description = BookHelper::extractDescription($book);
}

// Получаем связанные книги
$relatedBooks = getRelatedBooks($book, $db);

// Информация о файле
$fileExists = checkFileExists($book);
$fileSize = !empty($book['file_size']) ? formatFileSize($book['file_size']) : null;

// Определяем кодировку файла для информации
$fileEncoding = detectFileEncoding($book);

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
                                 id="mainCover"
                                 onerror="handleCoverError(this)"
                                 style="max-height: 400px; width: auto;"
                                 loading="eager">
                            
                            <!-- Индикатор формата -->
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
                            <!-- Заглушка если обложки нет -->
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
                           class="btn btn-lg btn-success" id="downloadBtn">
                            <i class="fas fa-download me-2"></i>Скачать книгу
                        </a>
                        
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-outline-primary" onclick="shareBook()">
                                <i class="fas fa-share-alt me-2"></i>Поделиться
                            </button>
                            <a href="./api/opds.php" class="btn btn-outline-secondary" target="_blank">
                                <i class="fas fa-rss me-2"></i>OPDS
                            </a>
                        </div>
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
                        
                        <?php if ($fileSize): ?>
                        <div class="col-6 mb-3">
                            <small class="text-muted d-block">Размер</small>
                            <strong><?php echo $fileSize; ?></strong>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-6 mb-3">
                            <small class="text-muted d-block">Статус файла</small>
                            <?php if ($fileExists): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-check me-1"></i>Доступен
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger">
                                    <i class="fas fa-times me-1"></i>Не найден
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-6 mb-3">
                            <small class="text-muted d-block">Обложка</small>
                            <?php if ($hasCover): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-check me-1"></i>Есть
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-times me-1"></i>Нет
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-12">
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
                           class="h4 text-decoration-none author-link">
                            <?php echo htmlspecialchars($book['author']); ?>
                        </a>
                    </div>
                    <?php endif; ?>
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
                
                <?php if (!empty($book['language'])): ?>
                <div class="col-md-4">
                    <div class="card h-100 border-0 bg-light">
                        <div class="card-body text-center">
                            <h6 class="card-title text-muted mb-2">Язык</h6>
                            <span class="h5"><?php echo getLanguageName($book['language']); ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($book['publisher'])): ?>
                <div class="col-md-4">
                    <div class="card h-100 border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-title text-muted mb-2">Издательство</h6>
                            <span class="h6"><?php echo htmlspecialchars($book['publisher']); ?></span>
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
                            <?php echo formatDescription($description); ?>
                            
                            <!-- Информация о кодировке для FB2 -->
                            <?php if (strtolower($book['file_type']) === 'fb2' && $fileEncoding && $fileEncoding !== 'UTF-8'): ?>
                            <div class="alert alert-info mt-3 mb-0 small">
                                <i class="fas fa-info-circle me-2"></i>
                                Описание конвертировано из <?php echo $fileEncoding; ?> в UTF-8
                            </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                Описание книги отсутствует. 
                                <?php if (strtolower($book['file_type']) === 'fb2'): ?>
                                Для FB2 файлов описание можно извлечь из файла книги.
                                <?php elseif (strtolower($book['file_type']) === 'epub'): ?>
                                Для EPUB файлов описание можно извлечь из метаданных.
                                <?php endif; ?>
                            </div>
                            
                            <!-- Кнопка для извлечения описания -->
                            <div class="mt-3 text-center">
                                <button type="button" class="btn btn-outline-primary" onclick="extractDescription(<?php echo $bookId; ?>)">
                                    <i class="fas fa-sync me-2"></i>Извлечь описание из файла
                                </button>
                                <small class="text-muted d-block mt-2">
                                    Будет выполнена попытка извлечения описания
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Связанные книги -->
            <?php if (!empty($relatedBooks)): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 border-bottom pb-2">
                        <i class="fas fa-books me-2"></i>Похожие книги
                    </h5>
                    <div class="row g-3">
                        <?php foreach (array_slice($relatedBooks, 0, 6) as $relatedBook): ?>
                        <div class="col-md-6">
                            <div class="card border h-100">
                                <div class="row g-0 h-100">
                                    <div class="col-4">
                                        <?php if (BookHelper::hasCover($relatedBook)): ?>
                                        <img src="./api/cover_direct.php?id=<?php echo $relatedBook['id']; ?>&thumb=1" 
                                             class="img-fluid rounded-start h-100" 
                                             style="object-fit: cover;"
                                             alt="Обложка"
                                             onerror="this.style.display='none'">
                                        <?php else: ?>
                                        <div class="bg-light d-flex align-items-center justify-content-center h-100 rounded-start">
                                            <i class="fas fa-book text-muted"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-8">
                                        <div class="card-body p-3">
                                            <a href="book_detail.php?id=<?php echo $relatedBook['id']; ?>" 
                                               class="text-decoration-none">
                                                <small class="d-block fw-bold text-dark mb-1">
                                                    <?php echo htmlspecialchars(mb_substr($relatedBook['title'] ?: 'Без названия', 0, 30)); ?>
                                                </small>
                                            </a>
                                            <?php if (!empty($relatedBook['author'])): ?>
                                            <small class="text-muted d-block">
                                                <?php echo htmlspecialchars(mb_substr($relatedBook['author'], 0, 25)); ?>
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Дополнительная информация -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title mb-3 border-bottom pb-2">
                        <i class="fas fa-ellipsis-h me-2"></i>Дополнительно
                    </h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <small class="text-muted d-block mb-1">Путь к файлу:</small>
                                <div class="bg-light p-2 rounded">
                                    <small class="text-muted file-path">
                                        <?php 
                                        if ($book['archive_path']) {
                                            echo htmlspecialchars($book['archive_path']) . 
                                                 ' → ' . htmlspecialchars($book['archive_internal_path']);
                                        } else {
                                            echo htmlspecialchars($book['file_path']);
                                        }
                                        ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <small class="text-muted d-block mb-1">Формат:</small>
                                <div class="bg-light p-2 rounded">
                                    <span class="badge bg-primary">
                                        <?php echo strtoupper($book['file_type']); ?>
                                    </span>
                                    <?php if ($book['archive_path']): ?>
                                    <span class="badge bg-secondary ms-1">
                                        В архиве <?php echo strtoupper(pathinfo($book['archive_path'], PATHINFO_EXTENSION)); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Навигация -->
    <div class="row mt-4">
        <div class="col-12">
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
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Функция для извлечения описания через AJAX
function extractDescription(bookId) {
    const btn = event.target;
    const originalText = btn.innerHTML;
    
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Извлечение...';
    btn.disabled = true;
    
    fetch(`./api/extract_description.php?id=${bookId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Обновляем описание на странице
                document.querySelector('.book-description').innerHTML = 
                    `<p>${data.description}</p>` +
                    `<div class="alert alert-success mt-3">✅ Описание успешно извлечено</div>`;
            } else {
                alert('Ошибка: ' + data.message);
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        })
        .catch(error => {
            alert('Ошибка сети: ' + error.message);
            btn.innerHTML = originalText;
            btn.disabled = false;
        });
}

// Функция для обработки ошибок загрузки обложки
function handleCoverError(img) {
    img.style.display = 'none';
    const placeholder = img.nextElementSibling;
    if (placeholder && placeholder.classList.contains('book-cover-placeholder')) {
        placeholder.style.display = 'flex';
    }
}

// Поделиться книгой
function shareBook() {
    const url = window.location.href;
    const title = document.querySelector('h1').textContent;
    
    if (navigator.share) {
        navigator.share({
            title: title,
            text: 'Посмотрите эту книгу в библиотеке:',
            url: url
        });
    } else {
        // Копируем ссылку в буфер обмена
        navigator.clipboard.writeText(url).then(() => {
            alert('Ссылка скопирована в буфер обмена!');
        });
    }
}
</script>

<!-- Стили -->
<style>
.book-description p {
    line-height: 1.8;
    text-align: justify;
    font-size: 1.05rem;
    margin-bottom: 1rem;
}

.book-description ul, .book-description ol {
    padding-left: 2rem;
    margin-bottom: 1rem;
}

.book-description li {
    margin-bottom: 0.5rem;
}

.file-path {
    word-break: break-all;
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
}

.card {
    border-radius: 12px;
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
}

.breadcrumb {
    border-radius: 10px;
    background: #f8f9fa;
}

.badge {
    font-size: 0.85em;
    font-weight: 500;
}

.author-link:hover {
    color: #0d6efd !important;
    text-decoration: underline !important;
}

.cover-container img {
    max-height: 400px;
    width: auto;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
</style>

<?php
/**
 * Вспомогательные функции
 */

/**
 * Получить название языка
 */
function getLanguageName($code) {
    $languages = [
        'ru' => 'Русский',
        'en' => 'Английский',
        'de' => 'Немецкий',
        'fr' => 'Французский',
        'es' => 'Испанский',
        'pl' => 'Польский',
        'uk' => 'Украинский',
        'be' => 'Белорусский'
    ];
    
    return $languages[strtolower($code)] ?? strtoupper($code);
}

/**
 * Форматировать размер файла
 */
function formatFileSize($bytes) {
    if ($bytes == 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB'];
    $base = 1024;
    $exp = floor(log($bytes, $base));
    
    return round($bytes / pow($base, $exp), 2) . ' ' . $units[$exp];
}

/**
 * Проверить существование файла
 */
function checkFileExists($book) {
    if (!empty($book['archive_path']) && !empty($book['archive_internal_path'])) {
        return file_exists($book['archive_path']);
    } else if (!empty($book['file_path'])) {
        return file_exists($book['file_path']);
    }
    return false;
}

/**
 * Определить кодировку файла
 */
function detectFileEncoding($book) {
    if (strtolower($book['file_type']) !== 'fb2') {
        return 'UTF-8';
    }
    
    $cacheKey = 'file_encoding_' . $book['id'];
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    
    $encoding = 'UTF-8';
    $content = getBookContent($book, 5000);
    
    if ($content) {
        $detected = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-5', 'CP1251'], true);
        
        if ($detected) {
            $encoding = $detected;
        } elseif (preg_match('/encoding=["\']windows-1251["\']/i', substr($content, 0, 500))) {
            $encoding = 'Windows-1251';
        } elseif (preg_match('/encoding=["\']koi8-r["\']/i', substr($content, 0, 500))) {
            $encoding = 'KOI8-R';
        }
    }
    
    Cache::set($cacheKey, $encoding, 86400);
    return $encoding;
}

/**
 * Получить содержимое книги
 */
function getBookContent($book, $maxSize = null) {
    if ($book['archive_path'] && $book['archive_internal_path']) {
        $zip = new ZipArchive();
        if ($zip->open($book['archive_path']) === TRUE) {
            $content = $zip->getFromName($book['archive_internal_path'], $maxSize ?: 0);
            $zip->close();
            return $content;
        }
    } else {
        if ($maxSize) {
            $handle = fopen($book['file_path'], 'r');
            $content = fread($handle, $maxSize);
            fclose($handle);
        } else {
            $content = @file_get_contents($book['file_path']);
        }
    }
    return $content;
}

/**
 * Получить связанные книги
 */
function getRelatedBooks($book, $db) {
    $related = [];
    
    try {
        // Книги того же автора
        if (!empty($book['author'])) {
            $authorBooks = $db->getBooksByAuthor($book['author'], 1, 8);
            foreach ($authorBooks as $authorBook) {
                if ($authorBook['id'] != $book['id']) {
                    $related[] = $authorBook;
                }
            }
        }
        
        // Книги из той же серии
        if (!empty($book['series'])) {
            $seriesBooks = $db->getBooksBySeries($book['series'], 1, 8);
            foreach ($seriesBooks as $seriesBook) {
                if ($seriesBook['id'] != $book['id']) {
                    $related[] = $seriesBook;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error getting related books: " . $e->getMessage());
    }
    
    // Убираем дубликаты
    $uniqueRelated = [];
    $seenIds = [$book['id']];
    
    foreach ($related as $relatedBook) {
        if (!in_array($relatedBook['id'], $seenIds)) {
            $uniqueRelated[] = $relatedBook;
            $seenIds[] = $relatedBook['id'];
        }
    }
    
    return array_slice($uniqueRelated, 0, 6);
}

/**
 * Форматировать описание
 */
function formatDescription($description) {
    if (empty($description)) {
        return '<p class="text-muted">Описание отсутствует</p>';
    }
    
    // Очищаем текст
    $description = htmlspecialchars($description);
    
    // Заменяем переносы строк на <br> но сохраняем структуру
    $description = nl2br($description);
    
    // Убираем лишние переносы
    $description = preg_replace('/(<br\s*\/?>\s*){3,}/i', '<br><br>', $description);
    
    return '<p>' . $description . '</p>';
}

// Сохраняем страницу в кэш
PageCache::save();
require 'templates/footer.php';
?>