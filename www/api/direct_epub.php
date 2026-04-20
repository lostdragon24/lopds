<?php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../lib/Database.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    exit('Invalid ID');
}

$db = Database::getInstance();
$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book || 'epub' !== strtolower($book['file_type'])) {
    http_response_code(404);
    exit('Book not found');
}

if ($book['archive_path'] && $book['archive_internal_path']) {
    // Извлекаем из архива
    $zip = new ZipArchive();
    if (true === $zip->open($book['archive_path'])) {
        $content = $zip->getFromName($book['archive_internal_path']);
        $zip->close();

        if ($content) {
            header('Content-Type: application/epub+zip');
            header('Content-Length: '.strlen($content));
            header('Content-Disposition: inline; filename="book.epub"');
            echo $content;
            exit;
        }
    }
    http_response_code(500);
    exit('Failed to extract EPUB');
}
// Прямой доступ к файлу
if (file_exists($book['file_path']) && is_readable($book['file_path'])) {
    header('Content-Type: application/epub+zip');
    header('Content-Length: '.filesize($book['file_path']));
    header('Content-Disposition: inline; filename="book.epub"');
    readfile($book['file_path']);
    exit;
}

http_response_code(404);
exit('File not found');
