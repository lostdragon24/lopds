<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/BookHelper.php';
require_once __DIR__ . '/../lib/Cache.php';

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

// Извлекаем описание с помощью BookHelper
$description = BookHelper::extractDescription($book);

if (!empty($description)) {
    // Форматируем для отображения
    $formattedDescription = formatDescription($description);
    
    echo json_encode([
        'success' => true,
        'description' => $formattedDescription,
        'raw_description' => $description,
        'message' => 'Описание успешно извлечено'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Не удалось извлечь описание из файла'
    ]);
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