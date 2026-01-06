<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';

class CoverExtractor {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function serveCover($bookId, $isThumb = false) {
        $book = $this->db->getBook($bookId);
        if (!$book) {
            $this->serveDefaultCover($isThumb);
            return;
        }

        $coverPath = Config::COVER_CACHE_DIR . '/' . $bookId . ($isThumb ? '_thumb.jpg' : '.jpg');

        // Если обложка уже есть в кэше - отдаем ее
        if (file_exists($coverPath)) {
            $this->serveCachedCover($coverPath);
            return;
        }

        // Пытаемся извлечь обложку
        if ($this->extractCover($book, $bookId, $isThumb)) {
            $this->serveCachedCover($coverPath);
        } else {
            $this->serveDefaultCover($isThumb);
        }
    }
    
    private function extractCover($book, $bookId, $isThumb) {
        $filePath = $book['file_path'];
        $internalPath = $book['archive_internal_path'];
        $fileType = strtolower($book['file_type']);
        
        // Для FB2 файлов используем упрощенный метод из примера
        if ($fileType === 'fb2') {
            return $this->extractFb2CoverSimple($book, $bookId, $isThumb);
        }
        
        if ($internalPath) {
            return $this->extractCoverFromArchive($book, $bookId, $isThumb);
        } else {
            return $this->extractCoverFromFile($book, $bookId, $isThumb);
        }
    }
    
    /**
     * Упрощенный метод извлечения обложки из FB2 (как в примере)
     */
    private function extractFb2CoverSimple($book, $bookId, $isThumb) {
        $content = $this->getBookContent($book);
        if ($content === false) {
            return false;
        }

        // Упрощенный поиск обложки как в примере
        if (preg_match('/<coverpage>.*?<image[^>]*l:href="#([^"]+)".*?>/s', $content, $cover_match)) {
            $cover_id = $cover_match[1];
            
            // Ищем <binary> с этим ID
            if (preg_match('/<binary[^>]*id="' . preg_quote($cover_id, '/') . '"[^>]*content-type="([^"]+)"[^>]*>([^<]*)<\/binary>/s', $content, $binary_match)) {
                $mime_type = $binary_match[1];
                $image_data = base64_decode($binary_match[2]);

                if ($image_data !== false) {
                    return $this->saveImageData($image_data, $bookId, $isThumb, $mime_type);
                }
            }
            
            // Альтернативный паттерн для binary
            if (preg_match('/<binary[^>]*id="' . preg_quote($cover_id, '/') . '"[^>]*>([^<]*)<\/binary>/s', $content, $binary_match)) {
                $image_data = base64_decode($binary_match[1]);
                if ($image_data !== false) {
                    return $this->saveImageData($image_data, $bookId, $isThumb);
                }
            }
        }
        
        return false;
    }
    
    /**
     * Получить содержимое книги (из архива или файла)
     */
    private function getBookContent($book) {
        if ($book['archive_path'] && $book['archive_internal_path']) {
            // Файл находится внутри архива
            $zip = new ZipArchive();
            if ($zip->open($book['archive_path']) === TRUE) {
                $content = $zip->getFromName($book['archive_internal_path']);
                $zip->close();
                return $content;
            }
        } else {
            // Файл находится на диске
            return file_get_contents($book['file_path']);
        }
        
        return false;
    }
    
    private function extractCoverFromFile($book, $bookId, $isThumb) {
        $filePath = $book['file_path'];
        $extension = strtolower($book['file_type']);
        
        switch ($extension) {
            case 'fb2':
                // Уже обработано в extractFb2CoverSimple
                return false;
            case 'epub':
                return $this->extractEpubCover($filePath, $bookId, $isThumb);
            case 'pdf':
                return $this->extractPdfCover($filePath, $bookId, $isThumb);
            case 'mobi':
                return $this->extractMobiCover($filePath, $bookId, $isThumb);
            default:
                return false;
        }
    }
    
    private function extractCoverFromArchive($book, $bookId, $isThumb) {
        $archivePath = $book['archive_path'];
        $internalPath = $book['archive_internal_path'];
        
        if (!file_exists($archivePath)) {
            return false;
        }
        
        // Для FB2 в архиве используем упрощенный метод
        if (strtolower(pathinfo($internalPath, PATHINFO_EXTENSION)) === 'fb2') {
            return $this->extractFb2CoverSimple($book, $bookId, $isThumb);
        }
        
        // Создаем временный файл для других форматов
        $tempDir = sys_get_temp_dir() . '/book_covers';
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $tempFile = $tempDir . '/' . $bookId . '_temp.' . pathinfo($internalPath, PATHINFO_EXTENSION);
        
        // Извлекаем файл из архива
        if (!$this->extractFileFromArchive($archivePath, $internalPath, $tempFile)) {
            return false;
        }
        
        // Извлекаем обложку из временного файла
        $result = $this->extractCoverFromFile([
            'file_path' => $tempFile, 
            'file_type' => pathinfo($internalPath, PATHINFO_EXTENSION),
            'archive_path' => null,
            'archive_internal_path' => null
        ], $bookId, $isThumb);
        
        // Удаляем временный файл
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
        
        return $result;
    }
    
    private function extractFileFromArchive($archivePath, $internalPath, $outputFile) {
        $archiveType = strtolower(pathinfo($archivePath, PATHINFO_EXTENSION));
        
        switch ($archiveType) {
            case 'zip':
                return $this->extractFromZip($archivePath, $internalPath, $outputFile);
            case 'rar':
                return $this->extractFromRar($archivePath, $internalPath, $outputFile);
            case '7z':
                return $this->extractFrom7z($archivePath, $internalPath, $outputFile);
            default:
                return false;
        }
    }
    
    private function extractFromZip($archivePath, $internalPath, $outputFile) {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) === TRUE) {
            $content = $zip->getFromName($internalPath);
            if ($content !== false) {
                file_put_contents($outputFile, $content);
                $zip->close();
                return true;
            }
            $zip->close();
        }
        return false;
    }
    
    private function extractFromRar($archivePath, $internalPath, $outputFile) {
        if (!class_exists('RarArchive')) {
            // Используем командную строку
            $command = 'unrar p -inul ' . escapeshellarg($archivePath) . ' ' . 
                      escapeshellarg($internalPath) . ' > ' . escapeshellarg($outputFile) . ' 2>/dev/null';
            exec($command, $output, $returnCode);
            return $returnCode === 0 && file_exists($outputFile) && filesize($outputFile) > 0;
        }
        
        // Используем расширение Rar
        $rar = RarArchive::open($archivePath);
        if ($rar === false) return false;
        
        $entries = $rar->getEntries();
        foreach ($entries as $entry) {
            if ($entry->getName() === $internalPath) {
                $stream = $entry->getStream();
                if ($stream) {
                    file_put_contents($outputFile, stream_get_contents($stream));
                    fclose($stream);
                    $rar->close();
                    return true;
                }
            }
        }
        
        $rar->close();
        return false;
    }
    
    private function extractFrom7z($archivePath, $internalPath, $outputFile) {
        $command = '7z e -so ' . escapeshellarg($archivePath) . ' ' . 
                  escapeshellarg($internalPath) . ' 2>/dev/null > ' . escapeshellarg($outputFile);
        exec($command, $output, $returnCode);
        return $returnCode === 0 && file_exists($outputFile) && filesize($outputFile) > 0;
    }
    
    /**
     * Сохранить данные изображения в файл
     */
    private function saveImageData($imageData, $bookId, $isThumb, $mimeType = null) {
        $outputPath = Config::COVER_CACHE_DIR . '/' . $bookId . ($isThumb ? '_thumb.jpg' : '.jpg');
        
        // Создаем директорию если не существует
        if (!file_exists(Config::COVER_CACHE_DIR)) {
            mkdir(Config::COVER_CACHE_DIR, 0755, true);
        }
        
        // Если это миниатюра, создаем уменьшенную версию
        if ($isThumb) {
            return $this->createThumbnailFromData($imageData, $outputPath, 200, 300);
        } else {
            // Для полноразмерной обложки сохраняем как есть
            return file_put_contents($outputPath, $imageData) !== false;
        }
    }
    
    private function createThumbnailFromData($imageData, $destPath, $maxWidth, $maxHeight) {
        // Создаем временный файл
        $tempFile = tempnam(sys_get_temp_dir(), 'cover_');
        file_put_contents($tempFile, $imageData);
        
        $result = $this->createThumbnail($tempFile, $destPath, $maxWidth, $maxHeight);
        
        unlink($tempFile);
        return $result;
    }
    
    private function createThumbnail($sourcePath, $destPath, $maxWidth, $maxHeight) {
        if (!extension_loaded('gd')) {
            // Используем ImageMagick если GD не доступен
            $command = 'convert ' . escapeshellarg($sourcePath) . 
                      ' -resize ' . $maxWidth . 'x' . $maxHeight . 
                      ' -quality 85 ' . escapeshellarg($destPath) . ' 2>/dev/null';
            exec($command, $output, $returnCode);
            return $returnCode === 0;
        }
        
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) return false;
        
        list($width, $height, $type) = $imageInfo;
        
        switch ($type) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($sourcePath);
                break;
            default:
                return false;
        }
        
        if (!$source) return false;
        
        // Вычисляем новые размеры
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);
        
        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        
        // Сохраняем прозрачность для PNG и GIF
        if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
            imagecolortransparent($thumb, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }
        
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        $result = imagejpeg($thumb, $destPath, 85);
        imagedestroy($source);
        imagedestroy($thumb);
        
        return $result;
    }
    
    // Методы для других форматов (EPUB, PDF, MOBI) остаются без изменений
    private function extractEpubCover($filePath, $bookId, $isThumb) {
        // ... существующий код для EPUB ...
        return false;
    }
    
    private function extractPdfCover($filePath, $bookId, $isThumb) {
        // ... существующий код для PDF ...
        return false;
    }
    
    private function extractMobiCover($filePath, $bookId, $isThumb) {
        // ... существующий код для MOBI ...
        return false;
    }
    
    private function serveCachedCover($coverPath) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
        readfile($coverPath);
    }
    
    private function serveDefaultCover($isThumb) {
        $width = $isThumb ? 200 : 600;
        $height = $isThumb ? 300 : 800;
        
        $image = imagecreatetruecolor($width, $height);
        $bgColor = imagecolorallocate($image, 240, 240, 240);
        $textColor = imagecolorallocate($image, 150, 150, 150);
        $borderColor = imagecolorallocate($image, 200, 200, 200);
        
        imagefill($image, 0, 0, $bgColor);
        imagerectangle($image, 0, 0, $width-1, $height-1, $borderColor);
        
        $text = 'No Cover';
        $fontSize = $isThumb ? 3 : 5;
        $textWidth = imagefontwidth($fontSize) * strlen($text);
        $textHeight = imagefontheight($fontSize);
        $x = ($width - $textWidth) / 2;
        $y = ($height - $textHeight) / 2;
        
        imagestring($image, $fontSize, $x, $y, $text, $textColor);
        
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=3600');
        imagejpeg($image);
        imagedestroy($image);
    }
}

// Основной код
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('Invalid book ID');
}

$bookId = intval($_GET['id']);
$isThumb = isset($_GET['thumb']);

try {
    $extractor = new CoverExtractor();
    $extractor->serveCover($bookId, $isThumb);
} catch (Exception $e) {
    error_log("Cover extraction error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    exit('Error extracting cover');
}
?>