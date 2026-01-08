<?php

class Fb2CoverParser {
    
    /**
     * Найти обложку в FB2 содержимом
     */
    public static function findCover($content) {
        if (empty($content)) {
            return false;
        }
        
        // Метод 1: Стандартный coverpage с l:href
        if (preg_match('/<coverpage>.*?<image[^>]*l:href[[:space:]]*=[[:space:]]*["\']#([^"\']+)["\'][^>]*>.*?<\/coverpage>/is', $content, $matches)) {
            $coverId = $matches[1];
            $imageData = self::extractBinaryData($content, $coverId);
            if ($imageData) {
                return $imageData;
            }
        }
        
        // Метод 2: Coverpage с xlink:href
        if (preg_match('/<coverpage>.*?<image[^>]*xlink:href[[:space:]]*=[[:space:]]*["\']#([^"\']+)["\'][^>]*>.*?<\/coverpage>/is', $content, $matches)) {
            $coverId = $matches[1];
            $imageData = self::extractBinaryData($content, $coverId);
            if ($imageData) {
                return $imageData;
            }
        }
        
        // Метод 3: Простой coverpage
        if (preg_match('/<coverpage>.*?<image[^>]*href[[:space:]]*=[[:space:]]*["\']#([^"\']+)["\'][^>]*>.*?<\/coverpage>/is', $content, $matches)) {
            $coverId = $matches[1];
            $imageData = self::extractBinaryData($content, $coverId);
            if ($imageData) {
                return $imageData;
            }
        }
        
        // Метод 4: Ищем любые бинарные данные с изображениями
        $imageData = self::findAnyImageBinary($content);
        if ($imageData) {
            return $imageData;
        }
        
        return false;
    }
    
    /**
     * Извлечь бинарные данные по ID
     */
    private static function extractBinaryData($content, $binaryId) {
        // Паттерн 1: с content-type
        $pattern1 = '/<binary[^>]*id[[:space:]]*=[[:space:]]*["\']' . preg_quote($binaryId, '/') . '["\'][^>]*content-type[[:space:]]*=[[:space:]]*["\']([^"\']+)["\'][^>]*>([^<]*)<\/binary>/is';
        
        // Паттерн 2: без content-type
        $pattern2 = '/<binary[^>]*id[[:space:]]*=[[:space:]]*["\']' . preg_quote($binaryId, '/') . '["\'][^>]*>([^<]*)<\/binary>/is';
        
        if (preg_match($pattern1, $content, $matches)) {
            return base64_decode(trim($matches[2]));
        }
        
        if (preg_match($pattern2, $content, $matches)) {
            return base64_decode(trim($matches[1]));
        }
        
        return false;
    }
    
    /**
     * Найти любые бинарные данные с изображениями
     */
    private static function findAnyImageBinary($content) {
        // Ищем все binary теги
        if (preg_match_all('/<binary[^>]*>([^<]*)<\/binary>/is', $content, $allBinaries)) {
            foreach ($allBinaries[0] as $index => $binaryTag) {
                $binaryData = base64_decode(trim($allBinaries[1][$index]));
                
                // Проверяем, что это изображение
                if (self::isValidImage($binaryData)) {
                    return $binaryData;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Проверить, что данные являются валидным изображением
     */
    private static function isValidImage($data) {
        if (empty($data) || strlen($data) < 100) {
            return false;
        }
        
        // Проверяем сигнатуры изображений
        $signatures = [
            'ffd8ff' => 'jpg',      // JPEG
            '89504e47' => 'png',    // PNG
            '47494638' => 'gif',    // GIF
            '424d' => 'bmp',        // BMP
            '52494646' => 'webp',   // WEBP
        ];
        
        $hex = bin2hex(substr($data, 0, 4));
        foreach ($signatures as $signature => $type) {
            if (strpos($hex, $signature) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Получить информацию об изображении
     */
    public static function getImageInfo($imageData) {
        if (!self::isValidImage($imageData)) {
            return false;
        }
        
        $tempFile = tempnam(sys_get_temp_dir(), 'fb2_img_');
        file_put_contents($tempFile, $imageData);
        
        $info = getimagesize($tempFile);
        unlink($tempFile);
        
        if ($info) {
            return [
                'width' => $info[0],
                'height' => $info[1],
                'type' => $info[2],
                'mime' => $info['mime'],
                'size' => strlen($imageData)
            ];
        }
        
        return false;
    }


public static function findCoverUniversal($book) {
        $fileType = strtolower($book['file_type']);
        
        switch ($fileType) {
            case 'fb2':
                $content = self::getBookContent($book);
                return $content ? self::findCover($content) : false;
                
            case 'epub':
                // Подключаем парсер EPUB если нужно
                if (class_exists('EpubParser')) {
                    return EpubParser::findCover($book['file_path']);
                }
                break;
        }
        
        return false;
    }
    
    /**
     * Универсальный метод извлечения метаданных
     */
    public static function extractMetadataUniversal($book) {
        $fileType = strtolower($book['file_type']);
        
        switch ($fileType) {
            case 'fb2':
                // Существующий код для FB2
                $content = self::getBookContent($book);
                if ($content) {
                    return self::extractFb2Metadata($content);
                }
                break;
                
            case 'epub':
                if (class_exists('EpubParser')) {
                    return EpubParser::extractMetadata($book['file_path']);
                }
                break;
        }
        
        return [];
    }
    
    /**
     * Извлечь метаданные из FB2
     */
    private static function extractFb2Metadata($content) {
        $metadata = [
            'title' => '',
            'author' => '',
            'description' => '',
            'language' => '',
            'publisher' => '',
            'year' => ''
        ];
        
        // Извлечение метаданных из FB2
        if (preg_match('/<title-info>.*?<book-title>(.*?)<\/book-title>.*?<\/title-info>/is', $content, $matches)) {
            $metadata['title'] = trim($matches[1]);
        }
        
        if (preg_match('/<title-info>.*?<author>.*?<first-name>(.*?)<\/first-name>.*?<last-name>(.*?)<\/last-name>.*?<\/author>.*?<\/title-info>/is', $content, $matches)) {
            $firstName = trim($matches[1]);
            $lastName = trim($matches[2]);
            $metadata['author'] = $lastName . ($firstName ? ' ' . $firstName : '');
        }
        
        if (preg_match('/<annotation>(.*?)<\/annotation>/is', $content, $matches)) {
            $description = strip_tags($matches[1]);
            $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
            $metadata['description'] = trim($description);
        }
        
        return $metadata;
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


}






?>