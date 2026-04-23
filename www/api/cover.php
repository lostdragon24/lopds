<?php

// api/cover.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/CoverParser/Factory.php';
require_once __DIR__ . '/../lib/BookHelper.php';
require_once __DIR__ . '/../init.php';

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

// ============================================
// 1. СНАЧАЛА ПРОВЕРЯЕМ КЭШ НА ДИСКЕ
// ============================================
//$cacheDir = Config::getCoverCacheDir();
//$cacheFile = $cacheDir . '/' . $book['id'] . ($thumb ? '_thumb.jpg' : '.jpg');


// === БЕЗОПАСНАЯ РАБОТА С ПУТЯМИ К КЭШУ ===
$cacheDir = realpath(Config::getCoverCacheDir());
if ($cacheDir === false) {
    serveDefaultCover($thumb);
    exit;
}

// Принудительно приводим к int, исключая любые символы
$safeBookId = (int)$book['id'];
$cacheFile = $cacheDir . '/' . $safeBookId . ($thumb ? '_thumb.jpg' : '.jpg');
$realCacheDir = realpath($cacheDir);
$realCacheFile = realpath($cacheFile);

if ($realCacheFile !== false && strpos($realCacheFile, $realCacheDir . DIRECTORY_SEPARATOR . $safeBookId) === 0) {
    // Только теперь можно безопасно читать файл
    readfile($realCacheFile);
    exit;
}

if (file_exists($cacheFile)) {
    // Дополнительная проверка: resolved path должен начинаться с cacheDir
    $realCacheFile = realpath($cacheFile);
    
    if ($realCacheFile === false || strpos($realCacheFile, $cacheDir) !== 0) {
        serveDefaultCover($thumb);
        exit;
    }

    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    header('X-Cache: HIT');
    readfile($cacheFile);
    exit;
}

// ============================================
// 2. ДЛЯ PDF - ИЗВЛЕКАЕМ ОБЛОЖКУ
// ============================================
$coverData = null;

if (strtolower($book['file_type']) === 'pdf') {
    // Пробуем извлечь обложку из PDF
    $coverData = BookHelper::extractPdfCover($book, $thumb);

    if ($coverData) {
        // Сохраняем в кэш
        //    file_put_contents($cacheFile, $coverData);
        //    chmod($cacheFile, 0644);

        // Перед записью снова проверяем, что путь безопасен
        if (strpos(realpath($cacheDir) . '/' . $safeBookId, $cacheDir) !== 0) {
            serveDefaultCover($thumb);
            exit;
        }
        file_put_contents($cacheFile, $coverData);
        chmod($cacheFile, 0644);



        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        header('X-Cache: MISS (PDF extracted)');
        echo $coverData;
        exit;
    }
}

// ============================================
// 3. ДЛЯ FB2/EPUB - ИСПОЛЬЗУЕМ СТАНДАРТНЫЙ ПАРСЕР
// ============================================
if (!$coverData) {
    $coverData = CoverParserFactory::getCover($book, $thumb);

    if ($coverData) {
        // Сохраняем в кэш
        file_put_contents($cacheFile, $coverData);
        chmod($cacheFile, 0644);

        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        header('X-Cache: MISS (FB2/EPUB extracted)');
        echo $coverData;
        exit;
    }
}

// ============================================
// 4. НЕТ ОБЛОЖКИ - ПОКАЗЫВАЕМ ЗАГЛУШКУ
// ============================================
serveDefaultCover($thumb);

function serveDefaultCover($thumb)
{
    $width = $thumb ? 200 : 600;
    $height = $thumb ? 300 : 800;

    $image = imagecreatetruecolor($width, $height);
    $bgColor = imagecolorallocate($image, 240, 240, 240);
    $textColor = imagecolorallocate($image, 150, 150, 150);
    $borderColor = imagecolorallocate($image, 200, 200, 200);

    imagefill($image, 0, 0, $bgColor);
    imagerectangle($image, 0, 0, $width - 1, $height - 1, $borderColor);

    $text = __('book_no_cover');
    $fontSize = $thumb ? 3 : 5;
    $textWidth = imagefontwidth($fontSize) * strlen($text);
    $textHeight = imagefontheight($fontSize);
    $x = ($width - $textWidth) / 2;
    $y = ($height - $textHeight) / 2;

    imagestring($image, $fontSize, $x, $y, $text, $textColor);

    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=3600');
    header('X-Cache: MISS (default)');
    imagejpeg($image);
    imagedestroy($image);
    exit;
}
