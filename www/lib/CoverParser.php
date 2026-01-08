<?php
// ./lib/CoverParser.php

class CoverParser {
    
    /**
     * Проверить наличие обложки в книге
     */
    public static function hasCover($book) {
        // Проверяем кэш в первую очередь
        $cacheKey = 'has_cover_' . $book['id'];
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        $hasCover = false;
        $fileType = strtolower($book['file_type'] ?? '');
        
        if ($fileType === 'fb2') {
            require_once 'Fb2CoverParser.php';
            $content = self::getBookContent($book);
            if ($content) {
                $hasCover = Fb2CoverParser::findCover($content) !== false;
            }
        } elseif ($fileType === 'epub') {
            require_once 'EpubCoverParser.php';
            $filePath = self::getBookFilePath($book);
            if ($filePath) {
                $hasCover = EpubCoverParser::findCover($filePath) !== false;
                // Очищаем временный файл если создавали
                if ($book['archive_path'] && $book['archive_internal_path'] && $filePath !== $book['file_path']) {
                    @unlink($filePath);
                }
            }
        }
        
        // Кэшируем результат на 1 час
        Cache::set($cacheKey, $hasCover, 3600);
        return $hasCover;
    }
    
    /**
     * Найти обложку в книге
     */
    public static function findCover($book) {
        $fileType = strtolower($book['file_type'] ?? '');
        
        if ($fileType === 'fb2') {
            require_once 'Fb2CoverParser.php';
            $content = self::getBookContent($book);
            return $content ? Fb2CoverParser::findCover($content) : false;
        } elseif ($fileType === 'epub') {
            require_once 'EpubCoverParser.php';
            $filePath = self::getBookFilePath($book);
            if (!$filePath) {
                return false;
            }
            
            $coverData = EpubCoverParser::findCover($filePath);
            
            // Очищаем временный файл если создавали
            if ($book['archive_path'] && $book['archive_internal_path'] && $filePath !== $book['file_path']) {
                @unlink($filePath);
            }
            
            return $coverData;
        }
        
        return false;
    }
    
    /**
     * Извлечь описание из книги
     */
    public static function extractDescription($book) {
        $fileType = strtolower($book['file_type'] ?? '');
        
        if ($fileType === 'epub') {
            require_once 'EpubCoverParser.php';
            $filePath = self::getBookFilePath($book);
            if (!$filePath) {
                return '';
            }
            
            $description = EpubCoverParser::extractDescription($filePath);
            
            // Очищаем временный файл если создавали
            if ($book['archive_path'] && $book['archive_internal_path'] && $filePath !== $book['file_path']) {
                @unlink($filePath);
            }
            
            return $description;
        }
        
        return '';
    }
    
    /**
     * Получить путь к файлу книги (с извлечением из архива если нужно)
     */
    private static function getBookFilePath($book) {
        if ($book['archive_path'] && $book['archive_internal_path']) {
            // Извлекаем книгу из архива во временный файл
            $archivePath = $book['archive_path'];
            $internalPath = $book['archive_internal_path'];
            
            if (!file_exists($archivePath)) {
                error_log("Архив не существует: $archivePath");
                return false;
            }
            
            $tempDir = sys_get_temp_dir() . '/book_covers';
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            $tempFile = $tempDir . '/' . uniqid('epub_', true) . '.epub';
            
            $archiveType = strtolower(pathinfo($archivePath, PATHINFO_EXTENSION));
            
            if ($archiveType === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($archivePath) === TRUE) {
                    $content = $zip->getFromName($internalPath);
                    if ($content !== false) {
                        file_put_contents($tempFile, $content);
                        $zip->close();
                        return $tempFile;
                    }
                    $zip->close();
                }
            }
            
            error_log("Не удалось извлечь EPUB из архива: $archivePath -> $internalPath");
            return false;
        }
        
        return $book['file_path'];
    }
    
    /**
     * Получить содержимое книги (для FB2)
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
}
?>