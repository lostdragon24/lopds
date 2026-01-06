<?php
require_once 'config/config.php';
require_once 'lib/Database.php';

$db = Database::getInstance();
$books = $db->getRecentBooks(5);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Тест отображения обложек</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test-case { border: 2px solid #333; margin: 20px; padding: 15px; }
        .success { color: green; }
        .error { color: red; }
        img { margin: 10px; border: 2px solid #ccc; }
        .url-test { background: #f0f0f0; padding: 10px; margin: 5px; }
    </style>
</head>
<body>
    <h1>🧪 Тест отображения обложек в index.php</h1>";

foreach ($books as $book) {
    echo "<div class='test-case'>";
    echo "<h2>📚 {$book['title']} (ID: {$book['id']})</h2>";
    
    $coverPath = Config::COVER_CACHE_DIR . '/' . $book['id'] . '.jpg';
    $thumbPath = Config::COVER_CACHE_DIR . '/' . $book['id'] . '_thumb.jpg';
    
    echo "<p><strong>Обложка в кэше:</strong> " . (file_exists($coverPath) ? "✅ ДА" : "❌ НЕТ") . "</p>";
    echo "<p><strong>Миниатюра в кэше:</strong> " . (file_exists($thumbPath) ? "✅ ДА" : "❌ НЕТ") . "</p>";
    
    // Тест 1: Прямой доступ к файлу
    echo "<h3>1. Прямой доступ к файлу:</h3>";
    $directUrl = "/cache/covers/{$book['id']}_thumb.jpg";
    echo "<div class='url-test'>URL: <code>{$directUrl}</code></div>";
    echo "<img src='{$directUrl}' alt='Прямой доступ' onerror='this.style.borderColor=\"red\"' style='width:100px;'>";
    
    // Тест 2: Через API
    echo "<h3>2. Через API:</h3>";
    $apiUrl = "./api/cover_simple.php?id={$book['id']}&thumb=1";
    echo "<div class='url-test'>URL: <code>{$apiUrl}</code></div>";
    echo "<img src='{$apiUrl}' alt='API доступ' onerror='this.style.borderColor=\"red\"' style='width:100px;'>";
    
    // Тест 3: Точная копия кода из index.php
    echo "<h3>3. Копия кода из index.php:</h3>";
    echo "<div style='border:1px solid #blue; padding:10px; background:#f9f9f9;'>";
    
    $thumbPath = Config::COVER_CACHE_DIR . '/' . $book['id'] . '_thumb.jpg';
    $coverUrl = "./api/cover_simple.php?id=" . $book['id'] . "&thumb=1";
    $hasCover = file_exists($thumbPath);
    
    if ($hasCover): 
        ?>
        <img src="<?php echo $coverUrl; ?>" 
             class="book-cover img-fluid" 
             alt="Обложка книги <?php echo htmlspecialchars($book['title']); ?>"
             style="max-width: 100px; height: auto;"
             onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';"
             loading="lazy">
        <div class="book-cover-placeholder bg-light d-flex align-items-center justify-content-center" style="display:none; width:100px; height:150px;">
            <small class="text-muted">Ошибка загрузки</small>
        </div>
        <?php
    else:
        ?>
        <div class="book-cover-placeholder bg-light d-flex align-items-center justify-content-center" style="width:100px; height:150px;">
            <small class="text-muted">Нет обложки</small>
        </div>
        <?php
    endif;
    
    echo "</div>";
    echo "</div>";
}

echo "</body></html>";
?>