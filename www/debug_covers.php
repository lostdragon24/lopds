<?php
require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/Fb2CoverParser.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = Database::getInstance();
$books = $db->getRecentBooks(10);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Диагностика обложек</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .book-info { border: 1px solid #ccc; margin: 10px; padding: 15px; border-radius: 5px; }
        .success { color: green; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .debug-info { background: #f5f5f5; padding: 10px; margin: 10px 0; border-radius: 3px; }
        img { max-width: 200px; border: 2px solid #ccc; margin: 5px; }
        details { margin: 10px 0; }
        summary { cursor: pointer; font-weight: bold; }
        pre { background: #f0f0f0; padding: 10px; overflow: auto; }
        .stats { background: #e9ecef; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body>";

echo "<h1>🔍 Улучшенная диагностика обложек</h1>";

// Статистика
$totalBooks = count($books);
$booksWithCovers = 0;
$booksWithCoversInFile = 0;

foreach ($books as $book) {
    $coverPath = Config::COVER_CACHE_DIR . '/' . $book['id'] . '.jpg';
    $thumbPath = Config::COVER_CACHE_DIR . '/' . $book['id'] . '_thumb.jpg';
    
    if (file_exists($coverPath) || file_exists($thumbPath)) {
        $booksWithCovers++;
    }
}

echo "<div class='stats'>
    <h3>📊 Статистика</h3>
    <p>Всего книг: <strong>{$totalBooks}</strong></p>
    <p>С обложками в кэше: <strong>{$booksWithCovers}</strong></p>
    <p>Директория кэша: <code>" . Config::COVER_CACHE_DIR . "</code> - " . 
    (is_writable(Config::COVER_CACHE_DIR) ? "✅ WRITABLE" : "❌ NOT WRITABLE") . "</p>
</div>";

foreach ($books as $book) {
    echo "<div class='book-info'>";
    echo "<h3>📚 ID: {$book['id']} - " . htmlspecialchars($book['title'] ?: 'Без названия') . "</h3>";
    echo "<p><strong>Автор:</strong> " . htmlspecialchars($book['author'] ?: 'Неизвестен') . "</p>";
    echo "<p><strong>Формат:</strong> " . strtoupper($book['file_type']) . "</p>";
    
    if ($book['archive_path']) {
        echo "<p><strong>Архив:</strong> " . htmlspecialchars(basename($book['archive_path'])) . "</p>";
        echo "<p><strong>Файл в архиве:</strong> " . htmlspecialchars($book['archive_internal_path']) . "</p>";
    } else {
        echo "<p><strong>Файл:</strong> " . htmlspecialchars($book['file_path']) . "</p>";
    }
    
    // Проверяем существование файлов
    $fileExists = file_exists($book['file_path']);
    $archiveExists = $book['archive_path'] ? file_exists($book['archive_path']) : true;
    
    echo "<p><strong>Файл существует:</strong> " . 
         ($fileExists ? "✅ ДА" : "❌ НЕТ") . "</p>";
    echo "<p><strong>Архив существует:</strong> " . 
         ($archiveExists ? "✅ ДА" : "❌ НЕТ") . "</p>";
    
    // Проверяем кэш обложек
    $coverPath = Config::COVER_CACHE_DIR . '/' . $book['id'] . '.jpg';
    $thumbPath = Config::COVER_CACHE_DIR . '/' . $book['id'] . '_thumb.jpg';
    
    echo "<p><strong>Обложка в кэше:</strong> " . 
         (file_exists($coverPath) ? "✅ ДА" : "❌ НЕТ") . "</p>";
    echo "<p><strong>Миниатюра в кэше:</strong> " . 
         (file_exists($thumbPath) ? "✅ ДА" : "❌ НЕТ") . "</p>";
    
    // Пробуем извлечь обложку
    echo "<h4>🖼️ Тест извлечения обложки:</h4>";
    
    $content = getBookContent($book);
    if ($content === false) {
        echo "<p class='error'>❌ Не удалось прочитать содержимое книги</p>";
    } else {
        echo "<p><strong>Размер содержимого:</strong> " . number_format(strlen($content)) . " байт</p>";
        
        // Используем улучшенный парсер
        $imageData = Fb2CoverParser::findCover($content);
        
        if ($imageData) {
            $booksWithCoversInFile++;
            $imageInfo = Fb2CoverParser::getImageInfo($imageData);
            echo "<p class='success'>✅ Обложка найдена в файле!</p>";
            echo "<p><strong>Размер изображения:</strong> " . number_format(strlen($imageData)) . " байт</p>";
            
            if ($imageInfo) {
                echo "<p><strong>Информация об изображении:</strong> " . 
                     "{$imageInfo['mime']}, {$imageInfo['width']}×{$imageInfo['height']} пикселей</p>";
            }
            
            // Сохраняем и показываем
            if (saveTestCover($imageData, $book['id'])) {
                echo "<p class='success'>✅ Обложка успешно сохранена в кэш</p>";
                
                // Показываем обложки
                echo "<div>";
                echo "<img src='./api/cover_simple.php?id={$book['id']}' title='Полная обложка' style='border-color: green;'>";
                echo "<img src='./api/cover_simple.php?id={$book['id']}&thumb=1' title='Миниатюра' style='border-color: blue;'>";
                echo "</div>";
                
                echo "<p><strong>Ссылки:</strong> ";
                echo "<a href='./api/cover_simple.php?id={$book['id']}' target='_blank'>Полная</a> | ";
                echo "<a href='./api/cover_simple.php?id={$book['id']}&thumb=1' target='_blank'>Миниатюра</a>";
                echo "</p>";
            } else {
                echo "<p class='error'>❌ Не удалось сохранить обложку в кэш</p>";
            }
        } else {
            echo "<p class='warning'>⚠️ Обложка не найдена в файле</p>";
            
            // Детальная диагностика
            echo "<details>";
            echo "<summary>🔍 Подробная диагностика FB2 структуры</summary>";
            echo "<div class='debug-info'>";
            
            // Анализируем структуру FB2
            analyzeFb2Structure($content);
            
            echo "</div>";
            echo "</details>";
        }
    }
    
    echo "</div>";
}

// Финальная статистика
echo "<div class='stats'>
    <h3>📈 Итоговая статистика</h3>
    <p>Всего проанализировано книг: <strong>{$totalBooks}</strong></p>
    <p>С обложками в кэше: <strong>{$booksWithCovers}</strong></p>
    <p>С обложками в файлах: <strong>{$booksWithCoversInFile}</strong></p>
    <p>Эффективность поиска: <strong>" . 
        ($totalBooks > 0 ? round(($booksWithCoversInFile / $totalBooks) * 100, 1) : 0) . "%</strong></p>
</div>";

echo "</body></html>";

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
        return false;
    } else {
        return file_get_contents($book['file_path']);
    }
}

/**
 * Сохранить тестовую обложку
 */
function saveTestCover($imageData, $bookId) {
    $coverPath = Config::COVER_CACHE_DIR . '/' . $bookId . '.jpg';
    $thumbPath = Config::COVER_CACHE_DIR . '/' . $bookId . '_thumb.jpg';
    
    // Создаем директорию если не существует
    if (!file_exists(Config::COVER_CACHE_DIR)) {
        mkdir(Config::COVER_CACHE_DIR, 0755, true);
    }
    
    // Сохраняем полноразмерную обложку
    if (file_put_contents($coverPath, $imageData) === false) {
        return false;
    }
    
    // Создаем миниатюру
    return createThumbnailFromData($imageData, $thumbPath, 200, 300);
}

/**
 * Создать миниатюру из данных
 */
function createThumbnailFromData($imageData, $destPath, $maxWidth, $maxHeight) {
    $tempFile = tempnam(sys_get_temp_dir(), 'cover_');
    file_put_contents($tempFile, $imageData);
    
    $imageInfo = getimagesize($tempFile);
    if (!$imageInfo) {
        unlink($tempFile);
        return false;
    }
    
    list($width, $height, $type) = $imageInfo;
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            $source = imagecreatefromjpeg($tempFile);
            break;
        case IMAGETYPE_PNG:
            $source = imagecreatefrompng($tempFile);
            break;
        case IMAGETYPE_GIF:
            $source = imagecreatefromgif($tempFile);
            break;
        default:
            unlink($tempFile);
            return false;
    }
    
    if (!$source) {
        unlink($tempFile);
        return false;
    }
    
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);
    
    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagecolortransparent($thumb, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
    }
    
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    $result = imagejpeg($thumb, $destPath, 85);
    
    imagedestroy($source);
    imagedestroy($thumb);
    unlink($tempFile);
    
    return $result;
}

/**
 * Анализировать структуру FB2 файла
 */
function analyzeFb2Structure($content) {
    echo "<h5>Поиск coverpage:</h5>";
    
    $methods = [
        'l:href' => '/<coverpage>.*?<image[^>]*l:href[[:space:]]*=[[:space:]]*["\']#([^"\']+)["\'][^>]*>.*?<\/coverpage>/is',
        'xlink:href' => '/<coverpage>.*?<image[^>]*xlink:href[[:space:]]*=[[:space:]]*["\']#([^"\']+)["\'][^>]*>.*?<\/coverpage>/is',
        'href' => '/<coverpage>.*?<image[^>]*href[[:space:]]*=[[:space:]]*["\']#([^"\']+)["\'][^>]*>.*?<\/coverpage>/is',
        'простой coverpage' => '/<coverpage>.*?<\/coverpage>/is'
    ];
    
    foreach ($methods as $method => $pattern) {
        if (preg_match($pattern, $content, $matches)) {
            echo "<p>✅ Найден coverpage (<strong>{$method}</strong>)";
            if (isset($matches[1])) {
                echo " - ID: <code>{$matches[1]}</code></p>";
                
                // Проверяем соответствующий binary
                $binaryPattern = '/<binary[^>]*id[[:space:]]*=[[:space:]]*["\']' . preg_quote($matches[1], '/') . '["\'][^>]*>/i';
                if (preg_match($binaryPattern, $content)) {
                    echo "<p>✅ Найден соответствующий binary тег</p>";
                } else {
                    echo "<p>❌ Binary тег не найден</p>";
                }
            } else {
                echo " (без ID)</p>";
            }
        } else {
            echo "<p>❌ Не найден coverpage (<strong>{$method}</strong>)</p>";
        }
    }
    
    echo "<h5>Binary теги:</h5>";
    if (preg_match_all('/<binary[^>]*id[[:space:]]*=[[:space:]]*["\']([^"\']+)["\'][^>]*>/i', $content, $binaries)) {
        echo "<p>✅ Найдены binary теги: " . count($binaries[1]) . "</p>";
        echo "<ul>";
        foreach ($binaries[1] as $binaryId) {
            echo "<li><code>{$binaryId}</code></li>";
        }
        echo "</ul>";
        
        // Проверяем размеры binary данных
        if (preg_match_all('/<binary[^>]*>([^<]*)<\/binary>/is', $content, $allBinaries)) {
            echo "<h5>Размеры binary данных:</h5>";
            foreach ($allBinaries[1] as $index => $binaryData) {
                $decoded = base64_decode(trim($binaryData));
                $size = strlen($decoded);
                echo "<p>Binary #" . ($index + 1) . ": " . number_format($size) . " байт - ";
                
                if (Fb2CoverParser::isValidImage($decoded)) {
                    echo "✅ Валидное изображение</p>";
                } else {
                    echo "❌ Не изображение</p>";
                }
            }
        }
    } else {
        echo "<p>❌ Binary теги не найдены</p>";
    }
    
    echo "<h5>Другие теги с изображениями:</h5>";
    $imageTags = [
        'image' => 'image',
        'img' => 'img',
        'cover' => 'cover'
    ];
    
    foreach ($imageTags as $tag => $name) {
        $pattern = '/<' . $tag . '[^>]*>/i';
        if (preg_match_all($pattern, $content, $matches)) {
            echo "<p>✅ Найдены теги &lt;{$tag}&gt;: " . count($matches[0]) . "</p>";
            foreach ($matches[0] as $tagContent) {
                echo "<pre>" . htmlspecialchars($tagContent) . "</pre>";
            }
        }
    }
}
?>