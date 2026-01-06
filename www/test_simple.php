<?php
// test_simple.php - минимальный тест
require_once 'config/config.php';
require_once 'lib/Database.php';

$db = Database::getInstance();
$books = $db->getRecentBooks(3);

foreach ($books as $book) {
    echo "<h3>{$book['title']}</h3>";
    
    // Проверяем API
    $apiUrl = "./api/cover_simple.php?id={$book['id']}&thumb=1";
    echo "<p>API URL: <a href='{$apiUrl}' target='_blank'>{$apiUrl}</a></p>";
    
    // Прямой вывод
    echo "<img src='{$apiUrl}' style='width:100px;'><br><br>";
}
?>