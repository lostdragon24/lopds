<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Cache.php';
require_once __DIR__ . '/../lib/EncodingHelper.php'; // Добавляем

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Неверный ID книги']);
    exit;
}

$bookId = intval($_GET['id']);
$db = Database::getInstance();
$book = $db->getBook($bookId);

if (!$book) {
    echo json_encode(['success' => false, 'message' => 'Книга не найдена']);
    exit;
}

// Извлекаем описание
$description = '';
$detectedEncoding = 'UTF-8';

if (strtolower($book['file_type']) === 'fb2') {
    $content = getBookContent($book);
    
    if ($content) {
        // Используем новый EncodingHelper для определения реальной кодировки
        $detectedEncoding = EncodingHelper::detectRealEncoding($content);
        
        // Извлекаем описание с правильной обработкой кодировки
        $description = EncodingHelper::extractDescriptionFromFB2($content);
    }
}

if (!empty($description)) {
    // Сохраняем в кэш
    Cache::set('book_desc_' . $bookId, $description, 86400);
    Cache::set('book_enc_' . $bookId, $detectedEncoding, 86400);
    
    // Форматируем для отображения
    $formattedDescription = formatDescription($description);
    
    echo json_encode([
        'success' => true,
        'description' => $formattedDescription,
        'raw_description' => $description,
        'encoding' => $detectedEncoding,
        'message' => 'Описание успешно извлечено'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Не удалось извлечь описание из файла',
        'encoding' => $detectedEncoding
    ]);
}

/**
 * Получить содержимое книги
 */
function getBookContent($book) {
    if ($book['archive_path'] && $book['archive_internal_path']) {
        $zip = new ZipArchive();
        if ($zip->open($book['archive_path']) === TRUE) {
            $content = $zip->getFromName($book['archive_internal_path']);
            $zip->close();
            return $content;
        }
    } else {
        return @file_get_contents($book['file_path']);
    }
    return false;
}

/**
 * Форматировать описание
 */
function formatDescription($description) {
    if (empty($description)) {
        return '';
    }
    
    $description = htmlspecialchars($description);
    $description = nl2br($description);
    $description = preg_replace('/(<br\s*\/?>\s*){3,}/i', '<br><br>', $description);
    
    return $description;
}
?>