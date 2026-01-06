<?php
require_once 'config/config.php';
require_once 'lib/Database.php';

$db = Database::getInstance();

echo "<h1>🔍 Тестирование OPDS поиска</h1>";

// Тест базового OPDS
echo "<h3>1. Базовый OPDS каталог:</h3>";
echo "<a href='./api/opds.php' target='_blank'>/api/opds.php</a><br>";

// Тест поиска
$testQueries = ['Пушкин', 'война', 'фантастика', 'детектив'];
foreach ($testQueries as $query) {
    echo "<h3>2. Поиск '$query':</h3>";
    
    // Проверка через базу данных
    $count = $db->getSearchCount($query, 'all');
    echo "<p>В базе найдено: $count книг</p>";
    
    // Ссылка на OPDS поиск
    $opdsUrl = "./api/opds.php?search=" . urlencode($query);
    echo "<p>OPDS URL: <a href='$opdsUrl' target='_blank'>$opdsUrl</a></p>";
    
    // Прямой тест через базу
    $books = $db->searchBooks($query, 'all', 1, 5);
    if (count($books) > 0) {
        echo "<p>Первые 5 результатов:</p><ul>";
        foreach ($books as $book) {
            echo "<li>{$book['title']} - {$book['author']}</li>";
        }
        echo "</ul>";
    }
}

// Тест навигации
echo "<h3>3. Навигация OPDS:</h3>";
echo "<ul>";
echo "<li><a href='./api/opds.php?by=authors' target='_blank'>По авторам</a></li>";
echo "<li><a href='./api/opds.php?by=genres' target='_blank'>По жанрам</a></li>";
echo "<li><a href='./api/opds.php?author=Пушкин%20Александр' target='_blank'>Книги Пушкина</a></li>";
echo "<li><a href='./api/opds.php?genre=sf' target='_blank'>Научная фантастика</a></li>";
echo "</ul>";

// Проверка ошибок
echo "<h3>4. Проверка ошибок:</h3>";
try {
    // Пустой поиск
    $empty = $db->searchBooks('', 'all', 1, 10);
    echo "<p>Пустой поиск: " . count($empty) . " книг (должны быть последние)</p>";
    
    // Несуществующий запрос
    $nonexistent = $db->searchBooks('nonexistentquery12345', 'all', 1, 5);
    echo "<p>Несуществующий запрос: " . count($nonexistent) . " книг</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Ошибка: " . $e->getMessage() . "</p>";
}
?>