<?php

require_once __DIR__ . '/Interface.php';
require_once __DIR__ . '/../Cache.php';

abstract class BaseCoverParser implements CoverParserInterface
{
    protected $config = [];

    public function __construct()
    {
        $this->config = Config::COVER_PROCESSING;
    }

    /**
     * Получить путь к файлу книги (с поддержкой архивов)
     */
    protected function getBookContent($book)
    {
        // Если книга в архиве
        if (!empty($book['archive_path']) && !empty($book['archive_internal_path'])) {
            // Проверяем существование архива
            if (!file_exists($book['archive_path'])) {
                error_log("Archive not found: " . $book['archive_path']);
                return false;
            }

            $zip = new ZipArchive();
            if ($zip->open($book['archive_path']) === true) {
                $content = $zip->getFromName($book['archive_internal_path']);
                $zip->close();
                return $content;
            }
            return false;
        }

        // Если книга не в архиве (обычный файл)
        if (!empty($book['file_path'])) {
            if (!file_exists($book['file_path'])) {
                error_log("File not found: " . $book['file_path']);
                return false;
            }
            return @file_get_contents($book['file_path']);
        }

        return false;
    }

    /**
     * Сохранить в кэш
     */
    protected function saveToCache($bookId, $data, $thumb = false)
    {
        $cacheDir = Config::getCoverCacheDir();
        if (!file_exists($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $filename = $cacheDir . '/' . $bookId . ($thumb ? '_thumb.jpg' : '.jpg');

        if ($thumb && $data) {
            $data = $this->createThumbnail($data);
        }

        if ($data) {
            file_put_contents($filename, $data);

            if ($this->config['enable_apcu_cache']) {
                Cache::set('cover_' . $bookId . ($thumb ? '_thumb' : ''), $data, $this->config['apcu_ttl']);
            }

            return true;
        }

        return false;
    }

    /**
     * Загрузить из кэша
     */
    protected function loadFromCache($bookId, $thumb = false)
    {
        // Проверяем APCu
        if ($this->config['enable_apcu_cache']) {
            $cached = Cache::get('cover_' . $bookId . ($thumb ? '_thumb' : ''));
            if ($cached !== null) {
                return $cached;
            }
        }

        // Проверяем файловый кэш
        if ($this->config['enable_file_cache']) {
            $filename = Config::getCoverCacheDir() . '/' . $bookId . ($thumb ? '_thumb.jpg' : '.jpg');
            if (file_exists($filename)) {
                $data = file_get_contents($filename);

                // Кэшируем в APCu для будущих запросов
                if ($this->config['enable_apcu_cache']) {
                    Cache::set('cover_' . $bookId . ($thumb ? '_thumb' : ''), $data, $this->config['apcu_ttl']);
                }

                return $data;
            }
        }

        return null;
    }

    /**
     * Проверить наличие в кэше
     */
    protected function hasCache($bookId, $thumb = false): bool
    {
        if ($this->config['enable_apcu_cache']) {
            if (Cache::exists('cover_' . $bookId . ($thumb ? '_thumb' : ''))) {
                return true;
            }
        }

        if ($this->config['enable_file_cache']) {
            $filename = Config::getCoverCacheDir() . '/' . $bookId . ($thumb ? '_thumb.jpg' : '.jpg');
            if (file_exists($filename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Создать миниатюру
     */
    protected function createThumbnail($imageData)
    {
        if (!extension_loaded('gd')) {
            return $imageData;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'cover_');
        file_put_contents($tempFile, $imageData);

        $imageInfo = getimagesize($tempFile);
        if (!$imageInfo) {
            unlink($tempFile);
            return false;
        }

        list($width, $height, $type) = $imageInfo;

        $source = $this->createImageFromType($tempFile, $type);
        if (!$source) {
            unlink($tempFile);
            return false;
        }

        $maxWidth = $this->config['max_width'] ?? 200;
        $maxHeight = $this->config['max_height'] ?? 300;

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

        ob_start();
        imagejpeg($thumb, null, $this->config['quality'] ?? 85);
        $thumbData = ob_get_clean();

        imagedestroy($source);
        imagedestroy($thumb);
        unlink($tempFile);

        return $thumbData;
    }

    /**
     * Создать ресурс изображения из файла
     */
    private function createImageFromType($filename, $type)
    {
        switch ($type) {
            case IMAGETYPE_JPEG: return imagecreatefromjpeg($filename);
            case IMAGETYPE_PNG: return imagecreatefrompng($filename);
            case IMAGETYPE_GIF: return imagecreatefromgif($filename);
            case IMAGETYPE_WEBP: return imagecreatefromwebp($filename);
            default: return false;
        }
    }

    /**
     * Проверить, является ли файл изображением
     */
    protected function isValidImage($data)
    {
        if (empty($data) || strlen($data) < 100) {
            return false;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'check_');
        file_put_contents($tempFile, $data);
        $info = getimagesize($tempFile);
        unlink($tempFile);

        return $info !== false;
    }

    /**
     * Очистить кэш для книги
     */
    public function clearCache($bookId): bool
    {
        if ($this->config['enable_apcu_cache']) {
            Cache::delete('cover_' . $bookId);
            Cache::delete('cover_' . $bookId . '_thumb');
            Cache::delete('has_cover_' . $bookId);
        }

        if ($this->config['enable_file_cache']) {
            $files = [
                Config::getCoverCacheDir() . '/' . $bookId . '.jpg',
                Config::getCoverCacheDir() . '/' . $bookId . '_thumb.jpg'
            ];

            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        return true;
    }
}
