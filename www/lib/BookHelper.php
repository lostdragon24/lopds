<?php

require_once __DIR__ . '/Fb2CoverParser.php';
require_once __DIR__ . '/EpubParser.php';
require_once __DIR__ . '/Cache.php';

class BookHelper {
    
    /**
     * Проверить наличие обложки в книге (универсальный метод)
     */

public static function hasCover($book) {
        $cacheKey = 'has_cover_' . $book['id'];
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        $fileType = strtolower($book['file_type']);
        $hasCover = false;
        
        switch ($fileType) {
            case 'fb2':
                $content = self::getBookContent($book);
                if ($content) {
                    $hasCover = Fb2CoverParser::findCover($content) !== false;
                }
                break;
                
            case 'epub':
                if (class_exists('EpubParser')) {
                    try {
                        $coverData = EpubParser::findCover($book);
                        // Для EPUB проверяем не только наличие данных, но и что это валидное изображение
                        $hasCover = ($coverData !== false && strlen($coverData) > 1000); // Минимум 1KB
                        
                        // Дополнительная проверка: это должно быть изображение
                        if ($hasCover) {
                            $tempFile = tempnam(sys_get_temp_dir(), 'epub_check_');
                            file_put_contents($tempFile, $coverData);
                            $imageInfo = @getimagesize($tempFile);
                            unlink($tempFile);
                            
                            $hasCover = ($imageInfo !== false);
                        }
                    } catch (Exception $e) {
                        error_log("EPUB cover check error for book {$book['id']}: " . $e->getMessage());
                        $hasCover = false;
                    }
                }
                break;
        }
        
        Cache::set($cacheKey, $hasCover, 300); // Кэшируем на 5 минут
        return $hasCover;
    }

    /**
     * Извлечь описание из книги (универсальный метод)
     */
    public static function extractDescription($book) {
        $cacheKey = 'book_desc_' . $book['id'];
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        $description = '';
        $fileType = strtolower($book['file_type']);
        
        switch ($fileType) {
            case 'fb2':
                $content = self::getBookContent($book);
                if ($content) {
                    $encoding = self::detectFileEncoding($content);
                    
                    // Конвертируем в UTF-8 если нужно
                    if ($encoding && $encoding !== 'UTF-8') {
                        $content = iconv($encoding, 'UTF-8//IGNORE', $content);
                    }
                    
                    // Извлекаем описание из FB2
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
                            $description = self::cleanText($description);
                            break;
                        }
                    }
                }
                break;
                
            case 'epub':
                if (class_exists('EpubParser')) {
                    $metadata = EpubParser::extractMetadata($book);
                    if (!empty($metadata['description'])) {
                        $description = $metadata['description'];
                    }
                }
                break;
        }
        
        if (!empty($description)) {
            Cache::set($cacheKey, $description, 86400); // Кэшируем на 24 часа
        }
        
        return $description;
    }
    
    /**
     * Получить содержимое книги
     */
    private static function getBookContent($book) {
        if ($book['archive_path'] && $book['archive_internal_path']) {
            $zip = new ZipArchive();
            if ($zip->open($book['archive_path']) === TRUE) {
                $content = $zip->getFromName($book['archive_internal_path']);
                $zip->close();
                return $content;
            }
        } else {
            return @file_get_contents($book['file_path']);
        }
        return false;
    }
    
    /**
     * Определить кодировку файла
     */
    private static function detectFileEncoding($content) {
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
        }
        
        return 'UTF-8';
    }
    
    /**
     * Очистка текста
     */
    private static function cleanText($text) {
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = preg_replace('/\.{3,}/', '...', $text);
        
        if (mb_strlen($text) > 5000) {
            $text = mb_substr($text, 0, 5000) . '...';
        }
        
        return trim($text);
    }
    
    /**
     * Получить обложку для отображения (с кэшированием)
     */
    public static function getCoverForDisplay($book, $thumb = true) {
        $cacheKey = 'cover_display_' . $book['id'] . ($thumb ? '_thumb' : '');
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        $coverData = null;
        $fileType = strtolower($book['file_type']);
        
        switch ($fileType) {
            case 'fb2':
                $content = self::getBookContent($book);
                if ($content) {
                    $coverData = Fb2CoverParser::findCover($content);
                }
                break;
                
            case 'epub':
                if (class_exists('EpubParser')) {
                    $coverData = EpubParser::findCover($book);
                }
                break;
        }
        
        Cache::set($cacheKey, $coverData, 3600); // Кэшируем на 1 час
        return $coverData;
    }
}
?>