<?php

require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/Fb2CoverParser.php';
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

// Проверяем наличие обложки
$hasCover = hasBookCover($book);
$coverCachePath = Config::COVER_CACHE_DIR . '/' . $bookId . '.jpg';
$coverExistsInCache = file_exists($coverCachePath);

// Извлекаем описание из файла, если его нет в базе
$description = $book['description'] ?? '';
if (empty($description) && strtolower($book['file_type']) === 'fb2') {
    $description = extractBookDescription($book);
    // Кэшируем описание
    if (!empty($description)) {
        Cache::set('book_desc_' . $bookId, $description, 86400);
    }
}

// Проверяем кэшированное описание
if (empty($description)) {
    $cachedDesc = Cache::get('book_desc_' . $bookId);
    if ($cachedDesc) {
        $description = $cachedDesc;
    }
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
                            
                            <!-- Индикатор кэша -->
                            <?php if ($coverExistsInCache): ?>
                            <div class="mt-2">
                                <span class="badge bg-success bg-opacity-25 text-success">
                                    <i class="fas fa-bolt me-1"></i>Из кэша
                                </span>
                            </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <!-- Заглушка если обложки нет -->
                            <div class="bg-light d-flex align-items-center justify-content-center rounded" 
                                 style="height: 300px;">
                                <div class="text-center">
                                    <i class="fas fa-book text-muted mb-3" style="font-size: 4rem;"></i>
                                    <p class="text-muted mb-0">Нет обложки</p>
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
                        
                        <?php if ($fileEncoding): ?>
                        <div class="col-6 mb-3">
                            <small class="text-muted d-block">Кодировка</small>
                            <span class="badge bg-info"><?php echo $fileEncoding; ?></span>
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
                            
                            <!-- Информация о кодировке -->
                            <?php if ($fileEncoding && $fileEncoding !== 'UTF-8'): ?>
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
                                <?php endif; ?>
                            </div>
                            
                            <!-- Кнопка для извлечения описания -->
                            <?php if (strtolower($book['file_type']) === 'fb2'): ?>
                            <div class="mt-3 text-center">
                                <button type="button" class="btn btn-outline-primary" onclick="extractDescription(<?php echo $bookId; ?>)">
                                    <i class="fas fa-sync me-2"></i>Извлечь описание из файла
                                </button>
                                <small class="text-muted d-block mt-2">
                                    Будет выполнена попытка извлечения и конвертации описания
                                </small>
                            </div>
                            <?php endif; ?>
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
                                        <?php if (hasBookCover($relatedBook)): ?>
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
                                        <?php echo htmlspecialchars($book['file_path']); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <small class="text-muted d-block mb-1">Обложка:</small>
                                <div class="bg-light p-2 rounded">
                                    <?php if ($hasCover): ?>
                                        <span class="text-success">
                                            <i class="fas fa-check me-1"></i>Есть в файле
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">
                                            <i class="fas fa-times me-1"></i>Отсутствует
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

<!-- Стили и скрипты остаются без изменений -->
...
<!-- Стили -->
<style>
.card {
    border-radius: 12px;
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
}

.cover-container {
    border-radius: 12px 12px 0 0;
    overflow: hidden;
}

.author-link, .series-link, .genre-badge {
    transition: all 0.3s ease;
}

.author-link:hover {
    color: #0d6efd !important;
    text-decoration: underline !important;
}

.series-link:hover {
    color: #0dcaf0 !important;
}

.genre-badge:hover {
    opacity: 0.9;
    transform: scale(1.05);
}

.file-path {
    word-break: break-all;
    font-family: 'Courier New', monospace;
}

.book-description {
    line-height: 1.8;
    text-align: justify;
    font-size: 1.05rem;
}

.hover-shadow:hover {
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.cursor-pointer {
    cursor: pointer;
}

.year-badge {
    font-size: 2rem;
    font-weight: bold;
    color: #0d6efd;
    text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
}

.breadcrumb {
    border-radius: 10px;
}

.progress-bar {
    transition: width 1s ease-in-out;
}
</style>





<?php
/**
 * Вспомогательные функции
 */

/**
 * Определить кодировку файла
 */
function detectFileEncoding($book) {
    $cacheKey = 'file_encoding_' . $book['id'];
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    
    $encoding = 'UTF-8'; // По умолчанию
    
    $content = getBookContent($book, 5000); // Читаем первые 5000 байт для определения
    if ($content) {
        // Определяем кодировку
        $detected = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-5', 'CP1251'], true);
        
        if ($detected) {
            $encoding = $detected;
        } else {
            // Если не удалось определить, пробуем другие методы
            if (preg_match('/encoding=["\']windows-1251["\']/i', substr($content, 0, 500))) {
                $encoding = 'Windows-1251';
            } elseif (preg_match('/encoding=["\']koi8-r["\']/i', substr($content, 0, 500))) {
                $encoding = 'KOI8-R';
            } elseif (preg_match('/encoding=["\']cp1251["\']/i', substr($content, 0, 500))) {
                $encoding = 'CP1251';
            }
        }
    }
    
    Cache::set($cacheKey, $encoding, 86400);
    return $encoding;
}

/**
 * Извлечь описание из файла книги с учетом кодировки
 */
function extractBookDescription($book) {
    // Проверяем кэш
    $cacheKey = 'book_desc_' . $book['id'];
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    
    $description = '';
    $content = getBookContent($book);
    
    if ($content && strtolower($book['file_type']) === 'fb2') {
        // Определяем кодировку
        $encoding = detectFileEncoding($book);
        
        // Конвертируем в UTF-8 если нужно
        if ($encoding && $encoding !== 'UTF-8') {
            $content = iconv($encoding, 'UTF-8//IGNORE', $content);
            if ($content === false) {
                // Если iconv не сработал, пробуем mb_convert_encoding
                $content = getBookContent($book); // Читаем заново
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            }
        }
        
        // Пробуем разные паттерны для извлечения описания из FB2
        $patterns = [
            // Стандартный паттерн для description
            '/<description>.*?<title-info>.*?<annotation>(.*?)<\/annotation>.*?<\/title-info>.*?<\/description>/is',
            // Альтернативный паттерн
            '/<annotation>(.*?)<\/annotation>/is',
            // Паттерн для текста внутри annotation
            '/<annotation>.*?<p>(.*?)<\/p>.*?<\/annotation>/is',
            // Паттерн для тега <p> внутри annotation
            '/<annotation>(.*?)<\/annotation>/is',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $description = trim(strip_tags($matches[1]));
                $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $description = preg_replace('/\s+/', ' ', $description);
                $description = cleanText($description);
                
                if (!empty($description)) {
                    // Сохраняем в кэш
                    Cache::set($cacheKey, $description, 86400);
                    return $description;
                }
            }
        }
    }
    
    return '';
}

/**
 * Очистка текста от мусора
 */
function cleanText($text) {
    // Убираем лишние пробелы
    $text = preg_replace('/\s+/', ' ', $text);
    
    // Убираем спецсимволы
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    
    // Убираем повторяющиеся точки
    $text = preg_replace('/\.{3,}/', '...', $text);
    
    // Обрезаем слишком длинный текст
    if (mb_strlen($text) > 5000) {
        $text = mb_substr($text, 0, 5000) . '...';
    }
    
    return trim($text);
}

/**
 * Получить содержимое книги (с ограничением по размеру)
 */
function getBookContent($book, $maxSize = null) {
    $cacheKey = 'book_content_' . $book['id'] . ($maxSize ? '_' . $maxSize : '');
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    
    $content = false;
    if ($book['archive_path'] && $book['archive_internal_path']) {
        $zip = new ZipArchive();
        if ($zip->open($book['archive_path']) === TRUE) {
            $content = $zip->getFromName($book['archive_internal_path'], $maxSize ?: 0);
            $zip->close();
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
    
    if ($content) {
        Cache::set($cacheKey, $content, 3600);
    }
    
    return $content;
}

function hasBookCover($book) {
    // Кэшируем проверку на 5 минут
    $cacheKey = 'has_cover_' . $book['id'];
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    
    $hasCover = false;
    if (strtolower($book['file_type']) === 'fb2') {
        $content = getBookContent($book);
        if ($content) {
            $hasCover = Fb2CoverParser::findCover($content) !== false;
        }
    }
    
    Cache::set($cacheKey, $hasCover, 300);
    return $hasCover;
}

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
    
    // Разбиваем на абзацы для длинного текста
    $lines = explode('<br>', $description);
    if (count($lines) > 3) {
        $description = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $description .= '<p>' . $line . '</p>';
            }
        }
    } else {
        $description = '<p>' . $description . '</p>';
    }
    
    return $description;
}

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

// Сохраняем страницу в кэш
PageCache::save();
require 'templates/footer.php';
?>