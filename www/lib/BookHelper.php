<?php

// lib/BookHelper.php

require_once __DIR__.'/CoverParser/Factory.php';
require_once __DIR__.'/../init.php';

class BookHelper
{
    /**
     * Проверить наличие обложки.
     */
    public static function hasCover($book)
    {
        // Сначала проверяем кэш
        $cacheFile = Config::getCoverCacheDir().'/'.$book['id'].'.jpg';
        if (file_exists($cacheFile)) {
            return true;
        }

        // Для PDF всегда пробуем извлечь обложку
        if ('pdf' === strtolower($book['file_type'])) {
            // Не говорим, что обложки нет, пока не проверим
            // Возвращаем true, чтобы попробовать загрузить
            return true;
        }

        // Для остальных форматов используем стандартную проверку
        return CoverParserFactory::hasCover($book);
    }

    /**
     * Получить обложку.
     */
    public static function getCover($book, $thumb = false)
    {
        return CoverParserFactory::getCover($book, $thumb);
    }

    /**
     * Извлечь описание из книги (универсальный метод).
     */
    public static function extractDescription($book)
    {
        $cacheKey = 'book_desc_'.$book['id'];
        $cached = Cache::get($cacheKey);
        if (null !== $cached) {
            return $cached;
        }

        $description = '';
        $fileType = strtolower($book['file_type']);

        switch ($fileType) {
            case 'fb2':
                $description = self::extractFromFb2($book);
                break;

            case 'epub':
                require_once __DIR__.'/EpubMetadataParser.php';
                $metadata = EpubMetadataParser::extractMetadata($book);
                if (!empty($metadata['description'])) {
                    $description = $metadata['description'];
                }
                break;

            case 'pdf':
                $description = self::extractFromPdf($book);
                break;
        }

        if (!empty($description)) {
            $description = self::cleanText($description);
            Cache::set($cacheKey, $description, 86400);
        }

        return $description;
    }

    /**
     * Извлечь описание из FB2.
     */
    private static function extractFromFb2($book)
    {
        $content = self::getBookContent($book);
        if (!$content) {
            return '';
        }

        // Конвертируем в UTF-8 если нужно
        $encoding = self::detectFileEncoding($content);
        if ($encoding && 'UTF-8' !== $encoding) {
            $content = iconv($encoding, 'UTF-8//IGNORE', $content);
        }

        $patterns = [
            '/<description>.*?<title-info>.*?<annotation>(.*?)<\/annotation>.*?<\/title-info>.*?<\/description>/is',
            '/<annotation>(.*?)<\/annotation>/is',
            '/<annotation>.*?<p>(.*?)<\/p>.*?<\/annotation>/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $description = trim(strip_tags($matches[1]));
                $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $description = preg_replace('/\s+/', ' ', $description);

                return $description;
            }
        }

        return '';
    }

    /**
     * Извлечь описание из PDF.
     */
    private static function extractFromPdf($book)
    {
        $filePath = null;
        $tempFile = null;

        if (!empty($book['archive_path']) && !empty($book['archive_internal_path'])) {
            $zip = new ZipArchive();
            if (true === $zip->open($book['archive_path'])) {
                $content = $zip->getFromName($book['archive_internal_path']);
                $zip->close();
                if ($content) {
                    $tempFile = tempnam(sys_get_temp_dir(), 'pdf_').'.pdf';
                    file_put_contents($tempFile, $content);
                    $filePath = $tempFile;
                }
            }
        } else {
            $filePath = $book['file_path'];
        }

        if (!$filePath || !file_exists($filePath)) {
            if ($tempFile) {
                @unlink($tempFile);
            }

            return '';
        }

        $description = '';

        // Пробуем pdftotext
        if (function_exists('exec')) {
            $textFile = tempnam(sys_get_temp_dir(), 'txt_');
            exec('pdftotext -layout '.escapeshellarg($filePath).' '.escapeshellarg($textFile).' 2>/dev/null');

            if (file_exists($textFile) && filesize($textFile) > 0) {
                $text = file_get_contents($textFile);
                // Берем первые 500 символов как описание
                $description = mb_substr(trim($text), 0, 1000);
                @unlink($textFile);
            }
        }

        if ($tempFile) {
            @unlink($tempFile);
        }

        return $description;
    }

    /**
     * Получить содержимое книги.
     */
    private static function getBookContent($book)
    {
        if ($book['archive_path'] && $book['archive_internal_path']) {
            $zip = new ZipArchive();
            if (true === $zip->open($book['archive_path'])) {
                $content = $zip->getFromName($book['archive_internal_path']);
                $zip->close();

                return $content;
            }
            error_log(sprintf(__('book_helper_error_open_archive'), $book['archive_path']));
        } else {
            if (!file_exists($book['file_path'])) {
                error_log(sprintf(__('book_helper_error_file_not_found'), $book['file_path']));

                return false;
            }

            return @file_get_contents($book['file_path']);
        }

        return false;
    }

    /**
     * Определить кодировку файла.
     */
    private static function detectFileEncoding($content)
    {
        if (empty($content)) {
            return 'UTF-8';
        }

        $detected = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-5', 'CP1251'], true);

        if ($detected) {
            return $detected;
        }

        // Если не удалось определить, проверяем XML декларацию
        if (preg_match('/encoding=["\']windows-1251["\']/i', substr($content, 0, 500))) {
            return 'Windows-1251';
        } elseif (preg_match('/encoding=["\']koi8-r["\']/i', substr($content, 0, 500))) {
            return 'KOI8-R';
        } elseif (preg_match('/encoding=["\']utf-8["\']/i', substr($content, 0, 500))) {
            return 'UTF-8';
        }

        return 'UTF-8';
    }

    /**
     * Очистка текста.
     */
    private static function cleanText($text)
    {
        // Удаляем лишние пробелы
        $text = preg_replace('/\s+/', ' ', $text);

        // Удаляем невидимые символы
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Заменяем многоточия
        $text = preg_replace('/\.{3,}/', '...', $text);

        // Обрезаем слишком длинный текст
        if (mb_strlen($text) > 5000) {
            $text = mb_substr($text, 0, 5000).'...';
        }

        return trim($text);
    }

    /**
     * Получить метаданные книги (общий метод).
     */
    public static function getMetadata($book)
    {
        $metadata = [
            'title' => $book['title'] ?? __('book_untitled'),
            'author' => $book['author'] ?? __('book_unknown_author'),
            'genre' => $book['genre'] ?? null,
            'readable_genre' => $book['genre'] ? GenreManager::getReadableName($book['genre']) : null,
            'series' => $book['series'] ?? null,
            'series_number' => $book['series_number'] ?? null,
            'year' => $book['year'] ?? null,
            'language' => $book['language'] ?? null,
            'publisher' => $book['publisher'] ?? null,
            'file_type' => strtoupper($book['file_type'] ?? '?'),
            'file_size' => self::getFileSize($book),
            'has_cover' => self::hasCover($book),
        ];

        return $metadata;
    }

    /**
     * Получить размер файла книги.
     */
    public static function getFileSize($book)
    {
        $size = 0;

        if ($book['archive_path'] && $book['archive_internal_path']) {
            if (file_exists($book['archive_path'])) {
                $size = filesize($book['archive_path']);
            }
        } elseif (!empty($book['file_path'])) {
            if (file_exists($book['file_path'])) {
                $size = filesize($book['file_path']);
            }
        }

        return $size;
    }

    /**
     * Форматировать размер файла для отображения.
     */
    public static function formatFileSize($bytes)
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }

    /**
     * Проверить, можно ли читать книгу онлайн.
     */
    public static function isReadableOnline($book)
    {
        $readableFormats = ['fb2', 'epub', 'pdf'];

        return in_array(strtolower($book['file_type']), $readableFormats);
    }

    /**
     * Получить URL для чтения книги.
     */
    public static function getReadUrl($book)
    {
        $baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

        return $baseUrl.'/reader.php?id='.$book['id'];
    }

    /**
     * Получить URL для скачивания книги.
     */
    public static function getDownloadUrl($book)
    {
        $baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

        return $baseUrl.'/api/download.php?id='.$book['id'];
    }

    /**
     * Получить URL для обложки.
     */
    public static function getCoverUrl($book, $thumb = false)
    {
        $baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

        return $baseUrl.'/api/cover.php?id='.$book['id'].($thumb ? '&thumb=1' : '');
    }

    /**
     * Генерировать безопасное имя файла для скачивания.
     */
    public static function generateSafeFilename($book)
    {
        $extension = strtolower($book['file_type']);
        $title = $book['title'] ?: __('book_untitled');
        $author = $book['author'] ?: __('book_unknown_author');

        // Очищаем от недопустимых символов
        $title = preg_replace('/[\/\\\:*?"<>|]/', '_', $title);
        $author = preg_replace('/[\/\\\:*?"<>|]/', '_', $author);

        // Обрезаем длинные имена
        $title = mb_substr($title, 0, 50);
        $author = mb_substr($author, 0, 30);

        $filename = $title;
        if ($author && $author !== __('book_unknown_author')) {
            $filename = $author.' - '.$filename;
        }

        $filename .= '.'.$extension;

        return $filename;
    }

    public static function extractPdfCover($book, $thumb = false)
    {
        $pdfPath = null;
        $tempFile = null;

        // Получаем путь к PDF
        if (!empty($book['archive_path']) && !empty($book['archive_internal_path'])) {
            // Извлекаем из архива во временный файл
            $zip = new ZipArchive();
            if (true === $zip->open($book['archive_path'])) {
                $content = $zip->getFromName($book['archive_internal_path']);
                $zip->close();
                if ($content) {
                    $tempFile = tempnam(sys_get_temp_dir(), 'pdf_').'.pdf';
                    file_put_contents($tempFile, $content);
                    $pdfPath = $tempFile;
                }
            }
        } else {
            $pdfPath = $book['file_path'];
        }

        if (!$pdfPath || !file_exists($pdfPath)) {
            if ($tempFile) {
                @unlink($tempFile);
            }

            return null;
        }

        // Пробуем разные методы
        $coverData = null;

        // 1. Пробуем Ghostscript (рекомендуется)
        if (function_exists('exec')) {
            $coverData = self::extractWithGhostscript($pdfPath, $thumb);
        }

        // 2. Пробуем Imagick
        if (!$coverData && extension_loaded('imagick')) {
            $coverData = self::extractWithImagick($pdfPath, $thumb);
        }

        // 3. Пробуем PDFtk
        if (!$coverData && function_exists('exec')) {
            $coverData = self::extractWithPdftk($pdfPath, $thumb);
        }

        // Удаляем временный файл
        if ($tempFile && file_exists($tempFile)) {
            @unlink($tempFile);
        }

        return $coverData;
    }

    /**
     * Извлечение обложки через Ghostscript.
     */
    private static function extractWithGhostscript($pdfPath, $thumb)
    {
        // Ищем ghostscript
        $gsPath = '/usr/bin/gs';
        if (!file_exists($gsPath)) {
            $gsPath = trim(shell_exec('which gs 2>/dev/null'));
            if (empty($gsPath)) {
                return null;
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'cover_').'.jpg';
        $resolution = $thumb ? 72 : 150;

        $cmd = sprintf(
            '%s -dSAFER -dBATCH -dNOPAUSE -dFirstPage=1 -dLastPage=1 '
            .'-sDEVICE=jpeg -dJPEGQ=85 -r%d '
            .'-sOutputFile="%s" "%s" 2>&1',
            escapeshellarg($gsPath),
            $resolution,
            escapeshellarg($tempFile),
            escapeshellarg($pdfPath)
        );

        exec($cmd, $output, $returnCode);

        if (0 === $returnCode && file_exists($tempFile) && filesize($tempFile) > 1000) {
            $data = file_get_contents($tempFile);
            @unlink($tempFile);

            return $data;
        }

        @unlink($tempFile);

        return null;
    }

    /**
     * Извлечение обложки через Imagick.
     */
    private static function extractWithImagick($pdfPath, $thumb)
    {
        try {
            $imagick = new Imagick();
            $imagick->setResolution(150, 150);

            // Читаем только первую страницу
            $imagick->readImage($pdfPath.'[0]');

            if ($thumb) {
                // Изменяем размер для миниатюры
                $width = $imagick->getImageWidth();
                $height = $imagick->getImageHeight();
                $newWidth = 200;
                $newHeight = ($newWidth * $height) / $width;
                $imagick->thumbnailImage($newWidth, $newHeight, true);
            }

            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
            $imagick->setImageCompressionQuality(85);

            $data = $imagick->getImageBlob();
            $imagick->clear();

            return $data;
        } catch (Exception $e) {
            error_log('Imagick error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Извлечение обложки через PDFtk.
     */
    private static function extractWithPdftk($pdfPath, $thumb)
    {
        // Проверяем наличие pdftk
        $pdftkPath = trim(shell_exec('which pdftk 2>/dev/null'));
        if (empty($pdftkPath)) {
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'cover_').'.jpg';

        $cmd = sprintf(
            'pdftk "%s" cat 1 output - | convert - -quality 85 -resize 800x "%s" 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg($tempFile)
        );

        exec($cmd, $output, $returnCode);

        if (0 === $returnCode && file_exists($tempFile) && filesize($tempFile) > 1000) {
            $data = file_get_contents($tempFile);
            @unlink($tempFile);

            return $data;
        }

        @unlink($tempFile);

        return null;
    }
}
