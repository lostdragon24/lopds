<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::getInstance();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    die('Invalid book ID');
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book) {
    http_response_code(404);
    die('Book not found');
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
        die('File not found');
    }
    
    $filename = basename($filePath);
    $mimeType = getMimeType($book['file_type']);
    
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    
    readfile($filePath);
    exit;
}

function downloadFromArchive($book) {
    $archivePath = $book['archive_path'];
    $internalPath = $book['archive_internal_path'];
    
    if (!file_exists($archivePath)) {
        http_response_code(404);
        die('Archive not found');
    }
    
    $extension = pathinfo($internalPath, PATHINFO_EXTENSION);
    $filename = ($book['title'] ?: 'book') . '.' . $extension;
    $mimeType = getMimeType($extension);
    
    // Используем системные команды для извлечения из архива
    $archiveType = pathinfo($archivePath, PATHINFO_EXTENSION);
    
    switch (strtolower($archiveType)) {
        case 'zip':
            $command = 'unzip -p ' . escapeshellarg($archivePath) . ' ' . escapeshellarg($internalPath);
            break;
        case 'rar':
            $command = 'unrar p -inul ' . escapeshellarg($archivePath) . ' ' . escapeshellarg($internalPath);
            break;
        case '7z':
            $command = '7z e -so ' . escapeshellarg($archivePath) . ' ' . escapeshellarg($internalPath) . ' 2>/dev/null';
            break;
        default:
            http_response_code(500);
            die('Unsupported archive format');
    }
    
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    passthru($command);
    exit;
}

function getMimeType($fileType) {
    $mimeTypes = [
        'epub' => 'application/epub+zip',
        'pdf' => 'application/pdf',
        'fb2' => 'application/x-fictionbook+xml',
        'mobi' => 'application/x-mobipocket-ebook',
        'txt' => 'text/plain'
    ];
    
    return $mimeTypes[strtolower($fileType)] ?? 'application/octet-stream';
}
?>