<?php
// api/download.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/SecurityHelper.php';
require_once __DIR__ . '/../init.php';

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
    'Calibre'
];

foreach ($opdsClients as $client) {
    if (stripos($userAgent, $client) !== false) {
        $isOpdsRequest = true;
        break;
    }
}

// Проверка по Accept header
if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/atom+xml') !== false) {
    $isOpdsRequest = true;
}

// Проверка по параметру в URL
if (isset($_GET['opds'])) {
    $isOpdsRequest = true;
}

error_log("Download request - ID: {$_GET['id']}, Method: {$_SERVER['REQUEST_METHOD']}, UA: $userAgent, isOPDS: " . ($isOpdsRequest ? 'yes' : 'no'));

// Проверяем referer ТОЛЬКО для обычных веб-запросов, НЕ для OPDS
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$isOpdsRequest) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    if (empty($referer)) {
        http_response_code(403);
        die(__('error_access_denied_direct'));
    }

    $refererHost = parse_url($referer, PHP_URL_HOST);
    if ($refererHost === null || $refererHost === false) {
        http_response_code(403);
        die(__('error_access_denied_referer'));
    }

    $currentHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if (empty($currentHost) || !in_array($refererHost, [$currentHost], true)) {
        http_response_code(403);
        die(__('error_access_denied_referer'));
    }

    if (empty($referer)) {
        error_log("Download blocked - empty referer for non-OPDS request");
        http_response_code(403);
        die(__('error_access_denied_direct'));
    }
}

$db = Database::getInstance();
$security = SecurityHelper::getInstance();

$rawId = $_GET['id'] ?? '';

if (!ctype_digit($rawId)) {
    http_response_code(400);
    die(__('error_invalid_id'));
}

$bookId = (int)$rawId;
$book = $db->getBook($bookId);

if (!$book) {
    http_response_code(404);
    die(__('book_not_found'));
}

// Rate limiting
$userIp = $security->sanitizeIp($_SERVER['REMOTE_ADDR']);
$rateKey = "download:{$userIp}";
if (!$security->checkRateLimit($rateKey, 10, 3600)) {
    http_response_code(429);
    die(__('error_rate_limit'));
}

// Определяем путь к файлу
if ($book['archive_path'] && $book['archive_internal_path']) {
    downloadFromArchive($book, $security);
} else {
    downloadRegularFile($book, $security);
}

/**
 * Скачивание обычного файла
 */
function downloadRegularFile($book, $security)
{
    $filePath = $book['file_path'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        die(__('error_file_not_found'));
    }

    // Проверка, что файл находится в разрешенной директории
    $realPath = realpath($filePath);
    $booksDir = realpath(Config::getBooksDir());
    if ($realPath === false || $booksDir === false || strpos($realPath, $booksDir) !== 0) {
        http_response_code(403);
        die(__('error_access_denied_path'));
    }

    // Генерируем безопасное имя файла
    $filename = generateSafeFilename($book, $security);
    $mimeType = Config::getMimeType($book['file_type']);
    $fileSize = filesize($filePath);

    // Отправляем заголовки с правильно закодированным именем
    sendDownloadHeaders($filename, $mimeType, $fileSize);

    // Читаем файл частями
    readfile_chunked($filePath);
    exit;
}

/**
 * Скачивание файла из архива
 */
function downloadFromArchive($book, $security)
{
    $archivePath = $book['archive_path'];
    $internalPath = $book['archive_internal_path'];

    // Проверка путей
    $realArchivePath = realpath($archivePath);
    $booksDir = realpath(Config::getBooksDir());
    if ($realArchivePath === false || $booksDir === false || strpos($realArchivePath, $booksDir) !== 0) {
        http_response_code(403);
        die(__('error_access_denied_archive'));
    }

    // Проверка внутреннего пути на path traversal
    if (strpos($internalPath, '..') !== false || strpos($internalPath, '/') === 0) {
        http_response_code(403);
        die(__('error_invalid_path'));
    }

    if (!file_exists($archivePath)) {
        http_response_code(404);
        die(__('error_archive_not_found'));
    }

    // Генерируем безопасное имя файла
    $filename = generateSafeFilename($book, $security);
    $extension = pathinfo($internalPath, PATHINFO_EXTENSION);
    $mimeType = Config::getMimeType($extension);

    // Используем ZipArchive для извлечения
    $zip = new ZipArchive();
    if ($zip->open($archivePath) === true) {
        $content = $zip->getFromName($internalPath);
        $zip->close();

        if ($content !== false) {
            // Отправляем заголовки
            sendDownloadHeaders($filename, $mimeType, strlen($content));
            echo $content;
            exit;
        }
    }

    http_response_code(500);
    die(__('error_extract_failed'));
}

/**
 * Отправить корректные заголовки для скачивания файла
 * Поддерживает русские имена файлов во всех браузерах
 */
function sendDownloadHeaders($filename, $mimeType, $fileSize)
{
    // Очищаем имя файла от недопустимых символов
    $filename = cleanFilename($filename);

    // Для совместимости с разными браузерами используем RFC 5987 (HTTP Content-Disposition)
    // Современный подход: filename* (RFC 5987) + filename (fallback)
    $filenameUtf8 = $filename;
    $filenameAscii = transliterateFilename($filename); // Транслитерация для старых браузеров

    header('Content-Type: ' . $mimeType);

    // RFC 5987 для современных браузеров (поддержка UTF-8)
    // filename*: поддерживает русские символы
    header("Content-Disposition: attachment; filename=\"$filenameAscii\"; filename*=UTF-8''" . rawurlencode($filenameUtf8));

    header('Content-Length: ' . $fileSize);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');

    // Дополнительные заголовки для совместимости с IE/Edge
    header('X-Download-Options: noopen');
}

/**
 * Очистка имени файла от опасных символов
 */
function cleanFilename($filename)
{
    // Удаляем управляющие символы
    $filename = preg_replace('/[\x00-\x1f\x7f]/', '', $filename);

    // Заменяем слэши на дефисы (чтобы не было path traversal)
    $filename = str_replace(['/', '\\'], '-', $filename);

    // Удаляем другие потенциально опасные символы
    $filename = preg_replace('/[<>:"|?*]/', '', $filename);

    // Ограничиваем длину (255 символов для файловых систем)
    if (strlen($filename) > 255) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = mb_substr($name, 0, 255 - strlen($ext) - 1);
        $filename = $name . '.' . $ext;
    }

    return $filename;
}

/**
 * Транслитерация имени файла для старых браузеров (ASCII fallback)
 */
function transliterateFilename($filename)
{
    $translit = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya', ' ' => '-',
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
        'Е' => 'E', 'Ё' => 'E', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
        'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
        'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
        'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'C', 'Ч' => 'Ch',
        'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
        'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya'
    ];

    $filename = strtr($filename, $translit);

    // Удаляем все кроме букв, цифр, точек и дефисов
    $filename = preg_replace('/[^a-zA-Z0-9.\-]/', '', $filename);

    // Убираем множественные дефисы
    $filename = preg_replace('/-+/', '-', $filename);

    // Убираем дефисы в начале и конце
    $filename = trim($filename, '-');

    return $filename;
}

/**
 * Генерация безопасного имени файла
 */
function generateSafeFilename($book, $security)
{
    $extension = strtolower($book['file_type']);
    $title = $book['title'] ?: __('book_untitled');
    $author = $book['author'] ?: __('book_unknown_author');

    // Удаляем переводы строк
    $title = preg_replace('/[\r\n]+/', '', $title);
    $author = preg_replace('/[\r\n]+/', '', $author);

    // Санитизация
    $title = $security->sanitizeFilename($title);
    $author = $security->sanitizeFilename($author);

    // Ограничиваем длину
    $title = mb_substr($title, 0, 50);
    $author = mb_substr($author, 0, 30);

    // Формируем имя файла
    if ($author && $author !== __('book_unknown_author')) {
        $filename = $author . ' - ' . $title;
    } else {
        $filename = $title;
    }

    $filename .= '.' . $extension;

    // Финальная очистка
    $filename = cleanFilename($filename);

    return $filename;
}

/**
 * Безопасное чтение файла частями
 */
function readfile_chunked($filename, $retbytes = true)
{
    $chunksize = 1 * (1024 * 1024); // 1MB chunks
    $cnt = 0;
    $handle = fopen($filename, 'rb');

    if ($handle === false) {
        return false;
    }

    while (!feof($handle)) {
        $buffer = fread($handle, $chunksize);
        echo $buffer;

        if ($retbytes) {
            $cnt += strlen($buffer);
        }

        // Очищаем буфер вывода для больших файлов
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    $status = fclose($handle);

    if ($retbytes && $status) {
        return $cnt;
    }

    return $status;
}
