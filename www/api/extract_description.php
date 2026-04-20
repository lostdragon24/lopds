<?php

// api/extract_description.php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../lib/Database.php';
require_once __DIR__.'/../lib/BookHelper.php';
require_once __DIR__.'/../lib/Cache.php';
require_once __DIR__.'/../init.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode([
        'success' => false,
        'message' => __('error_invalid_id'),
    ]);
    exit;
}

$bookId = intval($_GET['id']);
$db = Database::getInstance();
$book = $db->getBook($bookId);

if (!$book) {
    echo json_encode([
        'success' => false,
        'message' => __('book_not_found'),
    ]);
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
        'message' => __('description_extracted'),
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => __('description_extract_failed'),
    ]);
}

/**
 * Форматировать описание.
 */
function formatDescription($description)
{
    if (empty($description)) {
        return '';
    }

    $description = htmlspecialchars($description);
    $description = nl2br($description);
    $description = preg_replace('/(<br\s*\/?>\s*){3,}/i', '<br><br>', $description);

    return $description;
}
