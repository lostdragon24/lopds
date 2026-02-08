<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::getInstance();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    die('Неверный ID книги');
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book) {
    http_response_code(404);
    die('Книга не найдена');
}

// Определяем путь к файлу
if ($book['archive_path'] && $book['archive_internal_path']) {
    // Книга в архиве - нужно извлечь
    downloadFromArchive($book);
} else {
    // Обычный файл
    downloadRegularFile($book);
}

function downloadRegularFile($book) {
    $filePath = $book['file_path'];
    
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('Файл не найден');
    }
    
    // Генерируем имя файла
    $filename = generateFilename($book);
    $mimeType = getMimeType($book['file_type']);
    
    // Логируем скачивание
    error_log("Download: Book ID {$book['id']} - {$filename} - " . $_SERVER['REMOTE_ADDR']);
    
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Используем X-Sendfile если доступно (для экономии памяти на Raspberry Pi)
    if (function_exists('apache_get_modules') && in_array('mod_xsendfile', apache_get_modules())) {
        header('X-Sendfile: ' . $filePath);
    } else {
        // Читаем файл частями чтобы не перегружать память
        readfile_chunked($filePath);
    }
    exit;
}

function downloadFromArchive($book) {
    $archivePath = $book['archive_path'];
    $internalPath = $book['archive_internal_path'];
    
    if (!file_exists($archivePath)) {
        http_response_code(404);
        die('Архив не найден');
    }
    
    // Генерируем имя файла
    $filename = generateFilename($book);
    $mimeType = getMimeType(pathinfo($internalPath, PATHINFO_EXTENSION));
    
    // Логируем скачивание
    error_log("Download from archive: Book ID {$book['id']} - {$filename} - " . $_SERVER['REMOTE_ADDR']);
    
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Используем системные команды для извлечения из архива
    $archiveType = strtolower(pathinfo($archivePath, PATHINFO_EXTENSION));
    
    switch ($archiveType) {
        case 'zip':
            if (class_exists('ZipArchive')) {
                // Используем ZipArchive для лучшей производительности
                $zip = new ZipArchive();
                if ($zip->open($archivePath) === TRUE) {
                    $content = $zip->getFromName($internalPath);
                    if ($content !== false) {
                        header('Content-Length: ' . strlen($content));
                        echo $content;
                        $zip->close();
                        exit;
                    }
                    $zip->close();
                }
            }
            // Fallback на системную команду
            $command = 'unzip -p ' . escapeshellarg($archivePath) . ' ' . escapeshellarg($internalPath);
            passthru($command);
            break;
            
        case 'rar':
            $command = 'unrar p -inul ' . escapeshellarg($archivePath) . ' ' . escapeshellarg($internalPath);
            passthru($command);
            break;
            
        case '7z':
            $command = '7z e -so ' . escapeshellarg($archivePath) . ' ' . escapeshellarg($internalPath) . ' 2>/dev/null';
            passthru($command);
            break;
            
        default:
            http_response_code(500);
            die('Неподдерживаемый формат архива');
    }
    exit;
}

/**
 * Генерация имени файла для скачивания
 */
function generateFilename($book) {
    $extension = strtolower($book['file_type']);
    $title = $book['title'] ?: 'book';
    $author = $book['author'] ?: 'unknown';
    
    // Очищаем имя файла от недопустимых символов
    $title = preg_replace('/[^\p{L}\p{N}\s\-_\.]/u', '', $title);
    $author = preg_replace('/[^\p{L}\p{N}\s\-_\.]/u', '', $author);
    
    // Ограничиваем длину
    $title = mb_substr($title, 0, 100);
    $author = mb_substr($author, 0, 50);
    
    // Формируем имя файла
    $filename = $title;
    if ($author && $author !== 'unknown') {
        $filename = $author . ' - ' . $filename;
    }
    
    // Добавляем расширение
    $filename .= '.' . $extension;
    
    return $filename;
}

/**
 * Получить MIME-тип для формата
 */
function getMimeType($fileType) {
    $mimeTypes = [
        'epub' => 'application/epub+zip',
        'pdf' => 'application/pdf',
        'fb2' => 'application/x-fictionbook+xml',
        'mobi' => 'application/x-mobipocket-ebook',
        'txt' => 'text/plain; charset=utf-8',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'rtf' => 'application/rtf',
        'html' => 'text/html',
        'htm' => 'text/html',
        'djvu' => 'image/vnd.djvu',
        'djv' => 'image/vnd.djvu',
        'chm' => 'application/vnd.ms-htmlhelp'
    ];
    
    return $mimeTypes[strtolower($fileType)] ?? 'application/octet-stream';
}

/**
 * Чтение файла частями для экономии памяти
 */
function readfile_chunked($filename, $retbytes = true) {
    $chunksize = 1 * (1024 * 1024); // 1MB chunks
    $buffer = '';
    $cnt = 0;
    $handle = fopen($filename, 'rb');
    
    if ($handle === false) {
        return false;
    }
    
    while (!feof($handle)) {
        $buffer = fread($handle, $chunksize);
        echo $buffer;
        ob_flush();
        flush();
        
        if ($retbytes) {
            $cnt += strlen($buffer);
        }
    }
    
    $status = fclose($handle);
    
    if ($retbytes && $status) {
        return $cnt; // return num. bytes delivered like readfile() does.
    }
    
    return $status;
}
?>