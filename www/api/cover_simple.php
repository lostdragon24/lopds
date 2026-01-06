<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Fb2CoverParser.php';

$id = $_GET['id'] ?? '';
$thumb = isset($_GET['thumb']);

if (!$id || !is_numeric($id)) {
    serveDefaultCover($thumb);
    exit;
}

$db = Database::getInstance();
$book = $db->getBook(intval($id));

if (!$book) {
    serveDefaultCover($thumb);
    exit;
}

// Проверяем кэш
$coverPath = Config::COVER_CACHE_DIR . '/' . $id . ($thumb ? '_thumb.jpg' : '.jpg');
if (file_exists($coverPath)) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    readfile($coverPath);
    exit;
}

// Извлекаем обложку
$imageData = extractBookCover($book);
if ($imageData === false) {
    serveDefaultCover($thumb);
    exit;
}

// Сохраняем в кэш и отдаем
if (saveCoverToCache($imageData, $id, $thumb)) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    
    if ($thumb) {
        // Для миниатюры создаем уменьшенную версию
        echo createThumbnailFromData($imageData, 200, 300);
    } else {
        echo $imageData;
    }
} else {
    serveDefaultCover($thumb);
}

function extractBookCover($book) {
    $content = getBookContent($book);
    if ($content === false) {
        return false;
    }
    
    // Для FB2 файлов используем улучшенный парсер
    if (strtolower($book['file_type']) === 'fb2') {
        return Fb2CoverParser::findCover($content);
    }
    
    // Для других форматов можно добавить парсеры
    return false;
}

function getBookContent($book) {
    if ($book['archive_path'] && $book['archive_internal_path']) {
        $zip = new ZipArchive();
        if ($zip->open($book['archive_path']) === TRUE) {
            $content = $zip->getFromName($book['archive_internal_path']);
            $zip->close();
            return $content;
        }
    } else {
        return file_get_contents($book['file_path']);
    }
    return false;
}

function saveCoverToCache($imageData, $id, $thumb) {
    if (!file_exists(Config::COVER_CACHE_DIR)) {
        mkdir(Config::COVER_CACHE_DIR, 0755, true);
    }
    
    $path = Config::COVER_CACHE_DIR . '/' . $id . ($thumb ? '_thumb.jpg' : '.jpg');
    
    if ($thumb) {
        $thumbData = createThumbnailFromData($imageData, 200, 300);
        return $thumbData ? file_put_contents($path, $thumbData) !== false : false;
    } else {
        return file_put_contents($path, $imageData) !== false;
    }
}

function createThumbnailFromData($imageData, $maxWidth, $maxHeight) {
    $tempFile = tempnam(sys_get_temp_dir(), 'cover_');
    file_put_contents($tempFile, $imageData);
    
    $imageInfo = getimagesize($tempFile);
    if (!$imageInfo) {
        unlink($tempFile);
        return false;
    }
    
    list($width, $height, $type) = $imageInfo;
    
    switch ($type) {
        case IMAGETYPE_JPEG: $source = imagecreatefromjpeg($tempFile); break;
        case IMAGETYPE_PNG: $source = imagecreatefrompng($tempFile); break;
        case IMAGETYPE_GIF: $source = imagecreatefromgif($tempFile); break;
        default: unlink($tempFile); return false;
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
    
    // Сохраняем в память
    ob_start();
    imagejpeg($thumb, null, 85);
    $thumbData = ob_get_clean();
    
    imagedestroy($source);
    imagedestroy($thumb);
    unlink($tempFile);
    
    return $thumbData;
}

function serveDefaultCover($thumb) {
    $width = $thumb ? 200 : 600;
    $height = $thumb ? 300 : 800;
    
    $image = imagecreatetruecolor($width, $height);
    $bgColor = imagecolorallocate($image, 240, 240, 240);
    $textColor = imagecolorallocate($image, 150, 150, 150);
    $borderColor = imagecolorallocate($image, 200, 200, 200);
    
    imagefill($image, 0, 0, $bgColor);
    imagerectangle($image, 0, 0, $width-1, $height-1, $borderColor);
    
    $text = 'No Cover';
    $fontSize = $thumb ? 3 : 5;
    $textWidth = imagefontwidth($fontSize) * strlen($text);
    $textHeight = imagefontheight($fontSize);
    $x = ($width - $textWidth) / 2;
    $y = ($height - $textHeight) / 2;
    
    imagestring($image, $fontSize, $x, $y, $text, $textColor);
    
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=3600');
    imagejpeg($image);
    imagedestroy($image);
    exit;
}
?>