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

    // ---- ТЕКСТУРА КОЖИ ----
    $patternPath = realpath(__DIR__ . '/leather_pattern2.png');

    if ($patternPath && file_exists($patternPath)) {
        $pattern = imagecreatefrompng($patternPath);
        imagesettile($image, $pattern);
        imagefilledrectangle($image, 0, 0, $width, $height, IMG_COLOR_TILED);
        imagedestroy($pattern);
        imagefilter($image, IMG_FILTER_COLORIZE, 90, 50, 25);
    } else {
        $bgColor = imagecolorallocate($image, 187, 179, 179);
        imagefill($image, 0, 0, $bgColor);
    }

    $textColor = imagecolorallocate($image, 245, 240, 235);
    $borderColor = imagecolorallocate($image, 40, 40, 40);
    imagerectangle($image, 0, 0, $width - 1, $height - 1, $borderColor);

    $text = __('book_no_cover');

    // Конвертируем текст в UTF-8 на случай, если локализация выдает CP1251
    $text = mb_convert_encoding($text, 'UTF-8', mb_detect_encoding($text));

    // Находим точный абсолютный путь к шрифту
    $fontPath = realpath(__DIR__ . '/Roboto-Bold.ttf');

    // Если realpath не сработал, пробуем просто дописать ./ перед именем
    if (!$fontPath) {
        $fontPath = './Roboto-Bold.ttf';
    }

    // Включаем сглаживание шрифтов (antialiasing)
    imagealphablending($image, true);

    $fontSize = $thumb ? 16 : 28;

    // Рассчитываем координаты через встроенный массив углов
    $bbox = imagettfbbox($fontSize, 0, $fontPath, $text);

    if ($bbox) {
        // Успешный расчет размеров шрифта
        $textWidth = $bbox[2] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7]; // Изменили формулу высоты для точности

        $x = ($width - $textWidth) / 2;
        $y = ($height - $textHeight) / 2 + $textHeight;

        // Рисуем текст
        // imagettftext($image, $fontSize, 0, $x, $y, $textColor, $fontPath, $text);

        // 1. Цвета для эффекта
        $shadowColor = imagecolorallocate($image, 30, 20, 15);   // Глубокая темная тень (почти черная)
        $baseColor   = imagecolorallocate($image, 250, 250, 250);   // Основной цвет внутри букв (чуть темнее самой кожи)
        $highlightColor = imagecolorallocate($image, 140, 90, 60); // Блик света (светлее кожи, создает объем)

        // 2. Рисуем ТЕНЬ (сдвиг вверх и влево на 1 пиксель)
        imagettftext($image, $fontSize, 0, $x - 1, $y - 1, $shadowColor, $fontPath, $text);

        // 3. Рисуем БЛИК (сдвиг вниз и вправо на 1 пиксель)
        imagettftext($image, $fontSize, 0, $x + 1, $y + 1, $highlightColor, $fontPath, $text);

        // 4. Рисуем ОСНОВНОЙ ТЕКСТ по центру
        imagettftext($image, $fontSize, 0, $x, $y, $baseColor, $fontPath, $text);

    } else {
        // Жесткий фолбек, если шрифт вообще отказался грузиться сервером
        // Переводим обратно в транслит или ставим английский, чтобы не было кракозябр
        $text = 'No Cover';
        $fontSize = $thumb ? 3 : 5;
        $textWidth = imagefontwidth($fontSize) * strlen($text);
        $textHeight = imagefontheight($fontSize);
        $x = ($width - $textWidth) / 2;
        $y = ($height - $textHeight) / 2;
        imagestring($image, $fontSize, $x, $y, $text, $textColor);

    }

    // Отдача в браузер
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=3600');
    header('X-Cache: MISS (default)');
    imagejpeg($image, null, 85);
    imagedestroy($image);
    exit;
}
