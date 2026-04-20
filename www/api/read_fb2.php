<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../lib/Database.php';
require_once __DIR__.'/../lib/SecurityHelper.php';
require_once __DIR__.'/../init.php';

$db = Database::getInstance();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    exit('Invalid book ID');
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book) {
    http_response_code(404);
    exit('Book not found');
}

$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = rtrim(dirname(dirname($scriptPath)), '/');

// Получаем содержимое книги
$content = getBookContent($book);
if (!$content) {
    http_response_code(500);
    exit('Cannot read book file');
}

// Получаем выбранную кодировку из GET или cookie
$selectedEncoding = $_GET['encoding'] ?? $_COOKIE['reader_encoding'] ?? 'auto';
$availableEncodings = [
    'auto' => 'Автоопределение',
    'utf-8' => 'UTF-8',
    'windows-1251' => 'Windows-1251',
    'koi8-r' => 'KOI8-R',
    'cp866' => 'CP866',
    'iso-8859-5' => 'ISO-8859-5',
];

// Применяем выбранную кодировку
if ('auto' !== $selectedEncoding) {
    $converted = @iconv($selectedEncoding, 'UTF-8//IGNORE', $content);
    if ($converted) {
        $content = $converted;
    }
} else {
    // Автоопределение
    $detected = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'KOI8-R', 'CP866', 'ISO-8859-5'], true);
    if ($detected && 'UTF-8' !== $detected) {
        $content = mb_convert_encoding($content, 'UTF-8', $detected);
    }
}

// Получаем размер шрифта
$fontSize = isset($_COOKIE['reader_font_size']) ? intval($_COOKIE['reader_font_size']) : 100;
$fontSize = max(70, min(200, $fontSize));

// Получаем номер страницы
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Разбиваем на страницы
$pages = splitIntoPages($content);
$totalPages = count($pages);

if ($page > $totalPages) {
    $page = $totalPages;
}

// Получаем содержимое текущей страницы
$pageContent = $pages[$page - 1] ?? '';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($book['title'] ?: "<?php echo __('book_untitled'); ?>", ENT_QUOTES, 'UTF-8'); ?> - "<?php echo __('book_read'); ?>"</title>
    <style>


        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: "Georgia", "Times New Roman", serif;
            line-height: 1.8;
            color: #2c3e50;
            background: #fff;
            padding: 30px 20px;
            font-size: <?php echo $fontSize; ?>%;
        }
        
        .reader-content {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .book-header {
            text-align: center;
            margin-bottom: 2em;
            padding-bottom: 1.5em;
            border-bottom: 2px solid #e9ecef;
        }
        
        .book-title {
            font-size: 2.2em;
            font-weight: bold;
            margin-bottom: 0.3em;
            color: #1a2b3c;
        }
        
        .book-author {
            font-size: 1.2em;
            color: #6c757d;
        }
        
        .fb2-body p {
            margin: 1.2em 0;
            text-align: justify;
            text-indent: 1.5em;
        }
        
        .fb2-body h1, .fb2-body h2, .fb2-body h3 {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 1.5em 0 0.8em 0;
            font-weight: 600;
            color: #1a2b3c;
        }
        
        .fb2-body h1 { font-size: 1.8em; }
        .fb2-body h2 { font-size: 1.5em; }
        .fb2-body h3 { font-size: 1.3em; }
        
        .fb2-body img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 20px auto;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .fb2-body .empty-line {
            height: 1.5em;
        }
        
        /* Пагинация */
        .pagination-info {
            text-align: center;
            margin: 30px 0 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 50px;
            font-size: 0.95em;
        }
        
        .pagination-info span {
            display: inline-block;
            padding: 5px 15px;
            background: #fff;
            border-radius: 30px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        /* Индикатор кодировки */
        .encoding-indicator {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 0.9rem;
            z-index: 1000;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .encoding-indicator:hover {
            background: rgba(0,0,0,0.95);
            transform: scale(1.05);
        }
        
        .encoding-indicator i {
            margin-right: 8px;
            color: #4CAF50;
        }
        
        /* Меню выбора кодировки */
        .encoding-menu {
            position: fixed;
            bottom: 80px;
            left: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.2);
            padding: 15px;
            z-index: 1001;
            display: none;
            min-width: 200px;
        }
        
        .encoding-menu.show {
            display: block;
            animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .encoding-menu h6 {
            margin: 0 0 10px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid #eee;
            color: #333;
        }
        
        .encoding-menu button {
            display: block;
            width: 100%;
            padding: 8px 12px;
            margin: 5px 0;
            border: none;
            background: #f8f9fa;
            border-radius: 6px;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s ease;
            color: #333;
        }
        
        .encoding-menu button:hover {
            background: #e9ecef;
        }
        
        .encoding-menu button.active {
            background: #007bff;
            color: white;
        }
        
        /* Темная тема */
        body.dark-theme {
            background: #1a1a1a;
            color: #e0e0e0;
        }
        
        body.dark-theme .book-title { color: #fff; }
        body.dark-theme .book-author { color: #b0b0b0; }
        body.dark-theme .fb2-body h1,
        body.dark-theme .fb2-body h2,
        body.dark-theme .fb2-body h3 { color: #fff; }
        body.dark-theme .pagination-info { background: #2d2d2d; }
        body.dark-theme .pagination-info span { background: #3d3d3d; color: #e0e0e0; }
        body.dark-theme .encoding-menu { background: #2d2d2d; }
        body.dark-theme .encoding-menu h6 { color: #fff; border-bottom-color: #404040; }
        body.dark-theme .encoding-menu button { background: #3d3d3d; color: #e0e0e0; }
        body.dark-theme .encoding-menu button:hover { background: #4d4d4d; }
    </style>
</head>
<body>
    <div class="reader-content">
        <!-- Заголовок книги -->
        <?php if (1 == $page) { ?>
        <div class="book-header">
            <div class="book-title"><?php echo htmlspecialchars($book['title'] ?: "<?php echo __('book_untitled'); ?>", ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="book-author"><?php echo htmlspecialchars($book['author'] ?: "<?php echo __('book_unknown_author'); ?>", ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php } ?>
        
<!-- Содержимое страницы -->
<div class="fb2-body">
<?php
// Санитизация контента для защиты от XSS
require_once __DIR__.'/../lib/SecurityHelper.php';
$security = SecurityHelper::getInstance();
echo $security->sanitizeBookContent($pages[$page - 1] ?? '');
?>
</div>
        
        <!-- Информация о пагинации -->
        <div class="pagination-info">
            <span>Страница <?php echo $page; ?> из <?php echo $totalPages; ?></span>
        </div>
    </div>
    
    <!-- Индикатор кодировки -->
    <div class="encoding-indicator" onclick="toggleEncodingMenu()">
        <i class="fas fa-language"></i>
        <?php echo $availableEncodings[$selectedEncoding] ?? $selectedEncoding; ?>
    </div>
    
    <!-- Меню выбора кодировки -->
    <div class="encoding-menu" id="encodingMenu">
        <h6>Выберите кодировку:</h6>
        <?php foreach ($availableEncodings as $enc => $name) { ?>
        <button onclick="changeEncoding('<?php echo $enc; ?>')" 
                class="<?php echo $enc === $selectedEncoding ? 'active' : ''; ?>">
            <?php echo $name; ?>
        </button>
        <?php } ?>
    </div>
    
    <script>
    // Сообщаем родительскому окну о пагинации
    window.parent.postMessage({
        type: 'pagination',
        currentPage: <?php echo $page; ?>,
        totalPages: <?php echo $totalPages; ?>
    }, '*');
    
    // Переключение меню кодировки
    function toggleEncodingMenu() {
        const menu = document.getElementById('encodingMenu');
        menu.classList.toggle('show');
    }
    
    // Закрыть меню при клике вне его
    document.addEventListener('click', function(event) {
        const menu = document.getElementById('encodingMenu');
        const indicator = document.querySelector('.encoding-indicator');
        
        if (!menu.contains(event.target) && !indicator.contains(event.target)) {
            menu.classList.remove('show');
        }
    });
    
    // Смена кодировки
    function changeEncoding(encoding) {
        // Сохраняем в cookie
        document.cookie = 'reader_encoding=' + encoding + '; path=/; max-age=31536000';
        
        // Перезагружаем страницу с новой кодировкой
        window.location.href = '?id=<?php echo $bookId; ?>&page=<?php echo $page; ?>&encoding=' + encoding;
    }
    
    // Слушаем команды от родительского окна
    window.addEventListener('message', function(event) {
        if (event.data.type === 'navigate') {
            if (event.data.direction === 'next' && <?php echo $page; ?> < <?php echo $totalPages; ?>) {
                window.location.href = '?id=<?php echo $bookId; ?>&page=' + (<?php echo $page; ?> + 1) + '&encoding=<?php echo $selectedEncoding; ?>';
            } else if (event.data.direction === 'prev' && <?php echo $page; ?> > 1) {
                window.location.href = '?id=<?php echo $bookId; ?>&page=' + (<?php echo $page; ?> - 1) + '&encoding=<?php echo $selectedEncoding; ?>';
            }
        } else if (event.data.type === 'fontSize') {
            document.cookie = 'reader_font_size=' + event.data.size + '; path=/';
            window.location.reload();
        } else if (event.data.type === 'theme') {
            if (event.data.dark) {
                document.body.classList.add('dark-theme');
            } else {
                document.body.classList.remove('dark-theme');
            }
        }
    });
    
    // Применяем сохраненную тему
    if (window.parent.document.body.classList.contains('dark-theme')) {
        document.body.classList.add('dark-theme');
    }
    </script>
    
    <!-- Font Awesome для иконок -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/all.min.css">

</body>
</html>
<?php
exit;

/**
 * Получить содержимое книги.
 */
function getBookContent($book)
{
    if ($book['archive_path'] && $book['archive_internal_path']) {
        $zip = new ZipArchive();
        if (true === $zip->open($book['archive_path'])) {
            $content = $zip->getFromName($book['archive_internal_path']);
            $zip->close();

            return $content;
        }

        return false;
    }

    return @file_get_contents($book['file_path']);
}

/**
 * Разбить FB2 на страницы.
 */
function splitIntoPages($content)
{
    $pages = [];

    // Извлекаем тело книги
    if (preg_match('/<body>(.*?)<\/body>/is', $content, $matches)) {
        $body = $matches[1];
    } else {
        $body = $content;
    }

    // Удаляем namespace префиксы
    $body = preg_replace('/<(\/?)[^:>]+:([^>]+)>/', '<$1$2>', $body);

    // Конвертируем теги
    $body = preg_replace('/<title>/', '<h2>', $body);
    $body = preg_replace('/<\/title>/', '</h2>', $body);
    $body = preg_replace('/<subtitle>/', '<h3>', $body);
    $body = preg_replace('/<\/subtitle>/', '</h3>', $body);
    $body = preg_replace('/<p>/', '<p>', $body);
    $body = preg_replace('/<\/p>/', '</p>', $body);
    $body = preg_replace('/<empty-line\s*\/>/', '<div class="empty-line"></div>', $body);

    // Разбиваем на абзацы
    $paragraphs = preg_split('/(<h[1-3]>.*?<\/h[1-3]>|<p>.*?<\/p>)/i', $body, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    $currentPage = '';
    $currentLength = 0;
    $targetLength = 3000;

    foreach ($paragraphs as $para) {
        $cleanPara = strip_tags($para);
        $paraLength = mb_strlen($cleanPara, 'UTF-8');

        if ($currentLength + $paraLength > $targetLength && !empty($currentPage)) {
            $pages[] = $currentPage;
            $currentPage = $para;
            $currentLength = $paraLength;
        } else {
            $currentPage .= $para;
            $currentLength += $paraLength;
        }
    }

    if (!empty($currentPage)) {
        $pages[] = $currentPage;
    }

    if (empty($pages)) {
        $pages[] = $body;
    }

    return $pages;
}

?>
