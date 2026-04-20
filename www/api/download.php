<?php

// api/download.php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../lib/Database.php';
require_once __DIR__.'/../lib/SecurityHelper.php';
require_once __DIR__.'/../init.php';

while (ob_get_level()) {
    ob_end_clean();
}

$isOpdsRequest = false;

// Проверка по User-Agent (распространенные OPDS-клиенты)
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$opdsClients = [
    'FBReader',
    'CoolReader',
    'Aldiko',
    'Moon+',
    'PocketBook',
    'OPDS',
    'Librera',
    'Eboox',
    'KyBook',
    'Foliate',
    'Calibre',
];

foreach ($opdsClients as $client) {
    if (false !== stripos($userAgent, $client)) {
        $isOpdsRequest = true;
        break;
    }
}

// Проверка по Accept header
if (false !== strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/atom+xml')) {
    $isOpdsRequest = true;
}

// Проверка по параметру в URL
if (isset($_GET['opds'])) {
    $isOpdsRequest = true;
}

error_log("Download request - ID: {$_GET['id']}, Method: {$_SERVER['REQUEST_METHOD']}, UA: $userAgent, isOPDS: ".($isOpdsRequest ? 'yes' : 'no'));

// Проверяем referer ТОЛЬКО для обычных веб-запросов, НЕ для OPDS
if ('GET' === $_SERVER['REQUEST_METHOD'] && !$isOpdsRequest) {
    //    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    //    $allowedDomains = [$_SERVER['HTTP_HOST']];

    // === БЕЗОПАСНАЯ ПРОВЕРКА REFERER ===
    if ('GET' === $_SERVER['REQUEST_METHOD'] && !$isOpdsRequest) {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        if (empty($referer)) {
            http_response_code(403);
            exit(__('error_access_denied_direct'));
        }

        $refererHost = parse_url($referer, PHP_URL_HOST);
        // parse_url возвращает null/false при malformed URL
        if (null === $refererHost || false === $refererHost) {
            http_response_code(403);
            exit(__('error_access_denied_referer'));
        }

        // Нормализуем текущий хост (убираем порт если есть)
        $currentHost = parse_url('http://'.($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if (empty($currentHost) || !in_array($refererHost, [$currentHost], true)) {
            http_response_code(403);
            exit(__('error_access_denied_referer'));
        }
    }

    // Если referer пустой - тоже блокируем для обычных запросов
    if (empty($referer)) {
        error_log('Download blocked - empty referer for non-OPDS request');
        http_response_code(403);
        exit(__('error_access_denied_direct'));
    }

    //    $refererHost = parse_url($referer, PHP_URL_HOST);
    //    if (!in_array($refererHost, $allowedDomains)) {
    //        error_log("Download blocked - invalid referer: $refererHost");
    //        http_response_code(403);
    //        die(__('error_access_denied_referer'));
    //    }
}

$db = Database::getInstance();
$security = SecurityHelper::getInstance();

$rawId = $_GET['id'] ?? '';

if (!ctype_digit($rawId)) {
    http_response_code(400);
    exit(__('error_invalid_id'));
}

$bookId = (int) $rawId;
$book = $db->getBook($bookId);

if (!$book) {
    http_response_code(404);
    exit(__('book_not_found'));
}

// Rate limiting
$userIp = $security->sanitizeIp($_SERVER['REMOTE_ADDR']);
$rateKey = "download:{$userIp}";
if (!$security->checkRateLimit($rateKey, 10, 3600)) {
    http_response_code(429);
    exit(__('error_rate_limit'));
}

// Определяем путь к файлу
if ($book['archive_path'] && $book['archive_internal_path']) {
    downloadFromArchive($book, $security);
} else {
    downloadRegularFile($book, $security);
}

function downloadRegularFile($book, $security)
{
    $filePath = $book['file_path'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        exit(__('error_file_not_found'));
    }

    // Проверка, что файл находится в разрешенной директории
    $realPath = realpath($filePath);
    $booksDir = realpath(Config::getBooksDir());
    if (0 !== strpos($realPath, $booksDir)) {
        http_response_code(403);
        exit(__('error_access_denied_path'));
    }

    // Генерируем безопасное имя файла
    $filename = generateSafeFilename($book, $security);
    $mimeType = Config::getMimeType($book['file_type']);

    header('Content-Type: '.$mimeType);
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');

    // Читаем файл частями
    readfile_chunked($filePath);
    exit;
}

function downloadFromArchive($book, $security)
{
    $archivePath = $book['archive_path'];
    $internalPath = $book['archive_internal_path'];

    // Проверка путей
    $realArchivePath = realpath($archivePath);
    $booksDir = realpath(Config::getBooksDir());
    if (0 !== strpos($realArchivePath, $booksDir)) {
        http_response_code(403);
        exit(__('error_access_denied_archive'));
    }

    // Проверка внутреннего пути на path traversal
    if (false !== strpos($internalPath, '..') || 0 === strpos($internalPath, '/')) {
        http_response_code(403);
        exit(__('error_invalid_path'));
    }

    if (!file_exists($archivePath)) {
        http_response_code(404);
        exit(__('error_archive_not_found'));
    }

    // Генерируем безопасное имя файла
    $filename = generateSafeFilename($book, $security);
    $mimeType = Config::getMimeType(pathinfo($internalPath, PATHINFO_EXTENSION));

    header('Content-Type: '.$mimeType);
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');

    // Используем ZipArchive для извлечения
    $zip = new ZipArchive();
    if (true === $zip->open($archivePath)) {
        $content = $zip->getFromName($internalPath);
        if (false !== $content) {
            header('Content-Length: '.strlen($content));
            echo $content;
            $zip->close();
            exit;
        }
        $zip->close();
    }

    http_response_code(500);
    exit(__('error_extract_failed'));
}

/**
 * Генерация безопасного имени файла.
 */
function generateSafeFilename($book, $security)
{
    $extension = strtolower($book['file_type']);
    $title = $book['title'] ?: __('book_untitled');
    $author = $book['author'] ?: __('book_unknown_author');

    $title = $security->sanitizeFilename($title);
    $author = $security->sanitizeFilename($author);

    $title = mb_substr($title, 0, 50);
    $author = mb_substr($author, 0, 30);

    $filename = $title;
    if ($author && $author !== __('book_unknown_author')) {
        $filename = $author.' - '.$filename;
    }

    $filename .= '.'.$extension;

    return $filename;
}

/**
 * Безопасное чтение файла частями.
 */
function readfile_chunked($filename, $retbytes = true)
{
    $chunksize = 1 * (1024 * 1024); // 1MB chunks
    $buffer = '';
    $cnt = 0;
    $handle = fopen($filename, 'rb');

    if (false === $handle) {
        return false;
    }

    while (!feof($handle)) {
        $buffer = fread($handle, $chunksize);
        echo $buffer;

        if ($retbytes) {
            $cnt += strlen($buffer);
        }
    }

    $status = fclose($handle);

    if ($retbytes && $status) {
        return $cnt;
    }

    return $status;
}
