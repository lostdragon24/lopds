<?php

class EpubParser {
    
    /**
     * Извлечь обложку из EPUB файла (поддержка архивов)
     */
    public static function findCover($book) {
        // Получаем реальный путь к файлу/архиву
        $filePath = self::getActualFilePath($book);
        if (!$filePath) {
            return false;
        }
        
        // Проверяем, находится ли EPUB в архиве
        if (self::isEpubInArchive($book)) {
            return self::extractCoverFromArchive($book, $filePath);
        }
        
        // Обычный EPUB файл
        return self::extractCoverFromFile($filePath);
    }
    
    /**
     * Извлечь метаданные из EPUB (поддержка архивов)
     */
    public static function extractMetadata($book) {
        // Получаем реальный путь к файлу/архиву
        $filePath = self::getActualFilePath($book);
        if (!$filePath) {
            return [];
        }
        
        // Проверяем, находится ли EPUB в архиве
        if (self::isEpubInArchive($book)) {
            return self::extractMetadataFromArchive($book, $filePath);
        }
        
        // Обычный EPUB файл
        return self::extractMetadataFromFile($filePath);
    }
    
    /**
     * Проверить, является ли файл валидным EPUB (с поддержкой архивов)
     */
    public static function isValidEpub($book) {
        $filePath = self::getActualFilePath($book);
        if (!$filePath) {
            return false;
        }
        
        // Если EPUB в архиве
        if (self::isEpubInArchive($book)) {
            $zip = new ZipArchive();
            if ($zip->open($filePath) !== TRUE) {
                return false;
            }
            
            // Ищем EPUB файл в архиве
            $found = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'epub') {
                    // Проверяем сигнатуру EPUB внутри архива
                    $epubContent = $zip->getFromIndex($i);
                    $tempFile = tempnam(sys_get_temp_dir(), 'epub_');
                    file_put_contents($tempFile, $epubContent);
                    $isValid = self::checkEpubSignature($tempFile);
                    unlink($tempFile);
                    
                    if ($isValid) {
                        $found = true;
                        break;
                    }
                }
            }
            
            $zip->close();
            return $found;
        }
        
        // Обычный EPUB файл
        return self::checkEpubSignature($filePath);
    }
    
    /**
     * Проверить сигнатуру EPUB файла
     */
    private static function checkEpubSignature($filePath) {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== TRUE) {
            return false;
        }
        
        // Проверяем наличие mimetype файла
        $mimetype = $zip->getFromName('mimetype');
        $zip->close();
        
        return $mimetype === 'application/epub+zip';
    }
    
    /**
     * Получить реальный путь к файлу
     */
    private static function getActualFilePath($book) {
        if ($book['archive_path'] && $book['archive_internal_path']) {
            return $book['archive_path']; // Путь к архиву
        } else {
            return $book['file_path']; // Путь к файлу
        }
    }
    
    /**
     * Проверить, находится ли EPUB в архиве
     */
    private static function isEpubInArchive($book) {
        return !empty($book['archive_path']) && !empty($book['archive_internal_path']);
    }
    
    /**
     * Извлечь обложку из EPUB в архиве
     */
    private static function extractCoverFromArchive($book, $archivePath) {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== TRUE) {
            return false;
        }
        
        // Получаем содержимое EPUB из архива
        $epubContent = $zip->getFromName($book['archive_internal_path']);
        if ($epubContent === false) {
            $zip->close();
            return false;
        }
        
        // Сохраняем EPUB во временный файл
        $tempEpub = tempnam(sys_get_temp_dir(), 'epub_');
        file_put_contents($tempEpub, $epubContent);
        $zip->close();
        
        // Извлекаем обложку из временного файла
        $coverData = self::extractCoverFromFile($tempEpub);
        
        // Удаляем временный файл
        unlink($tempEpub);
        
        return $coverData;
    }
    
    /**
     * Извлечь метаданные из EPUB в архиве
     */
    private static function extractMetadataFromArchive($book, $archivePath) {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== TRUE) {
            return [];
        }
        
        // Получаем содержимое EPUB из архива
        $epubContent = $zip->getFromName($book['archive_internal_path']);
        if ($epubContent === false) {
            $zip->close();
            return [];
        }
        
        // Сохраняем EPUB во временный файл
        $tempEpub = tempnam(sys_get_temp_dir(), 'epub_');
        file_put_contents($tempEpub, $epubContent);
        $zip->close();
        
        // Извлекаем метаданные из временного файла
        $metadata = self::extractMetadataFromFile($tempEpub);
        
        // Удаляем временный файл
        unlink($tempEpub);
        
        return $metadata;
    }
    
    /**
     * Извлечь обложку из обычного EPUB файла
     */
    private static function extractCoverFromFile($filePath) {
        if (!file_exists($filePath)) {
            return false;
        }
        
        $tempDir = sys_get_temp_dir() . '/epub_extract_' . md5($filePath);
        
        try {
            // Создаем временную директорию
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            // Распаковываем EPUB (это zip архив)
            $zip = new ZipArchive();
            if ($zip->open($filePath) !== TRUE) {
                self::cleanupTempDir($tempDir);
                return false;
            }
            
            $zip->extractTo($tempDir);
            $zip->close();
            
            // Ищем файл контейнера
            $containerPath = $tempDir . '/META-INF/container.xml';
            if (!file_exists($containerPath)) {
                self::cleanupTempDir($tempDir);
                return false;
            }
            
            $containerXml = simplexml_load_file($containerPath);
            $rootfile = $containerXml->rootfiles->rootfile['full-path'];
            
            // Загружаем OPF файл
            $opfPath = $tempDir . '/' . $rootfile;
            if (!file_exists($opfPath)) {
                self::cleanupTempDir($tempDir);
                return false;
            }
            
            $opfXml = simplexml_load_file($opfPath);
            
            // Ищем обложку несколькими способами
            $coverImage = self::findCoverInOpf($opfXml, $tempDir, dirname($rootfile));
            
            self::cleanupTempDir($tempDir);
            
            return $coverImage;
            
        } catch (Exception $e) {
            if (file_exists($tempDir)) {
                self::cleanupTempDir($tempDir);
            }
            error_log("EPUB parsing error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Извлечь метаданные из обычного EPUB файла
     */
    private static function extractMetadataFromFile($filePath) {
        if (!file_exists($filePath)) {
            return [];
        }
        
        $metadata = [
            'title' => '',
            'author' => '',
            'description' => '',
            'language' => '',
            'publisher' => '',
            'year' => '',
            'isbn' => ''
        ];
        
        $tempDir = sys_get_temp_dir() . '/epub_meta_' . md5($filePath);
        
        try {
            // Распаковываем EPUB
            $zip = new ZipArchive();
            if ($zip->open($filePath) !== TRUE) {
                return $metadata;
            }
            
            $zip->extractTo($tempDir);
            $zip->close();
            
            // Находим OPF файл
            $containerPath = $tempDir . '/META-INF/container.xml';
            if (!file_exists($containerPath)) {
                self::cleanupTempDir($tempDir);
                return $metadata;
            }
            
            $containerXml = simplexml_load_file($containerPath);
            $rootfile = $containerXml->rootfiles->rootfile['full-path'];
            $opfPath = $tempDir . '/' . $rootfile;
            
            if (!file_exists($opfPath)) {
                self::cleanupTempDir($tempDir);
                return $metadata;
            }
            
            $opfXml = simplexml_load_file($opfPath);
            $namespaces = $opfXml->getNamespaces(true);
            
            // Извлекаем метаданные
            if (isset($namespaces['dc'])) {
                $dc = $opfXml->metadata->children($namespaces['dc']);
                
                // Заголовок
                if (isset($dc->title)) {
                    $metadata['title'] = (string)$dc->title;
                }
                
                // Автор
                if (isset($dc->creator)) {
                    $metadata['author'] = (string)$dc->creator;
                }
                
                // Описание
                if (isset($dc->description)) {
                    $metadata['description'] = (string)$dc->description;
                }
                
                // Язык
                if (isset($dc->language)) {
                    $metadata['language'] = (string)$dc->language;
                }
                
                // Издатель
                if (isset($dc->publisher)) {
                    $metadata['publisher'] = (string)$dc->publisher;
                }
                
                // Дата
                if (isset($dc->date)) {
                    $date = (string)$dc->date;
                    if (preg_match('/\d{4}/', $date, $matches)) {
                        $metadata['year'] = $matches[0];
                    }
                }
                
                // ISBN
                if (isset($dc->identifier)) {
                    foreach ($dc->identifier as $identifier) {
                        $id = (string)$identifier;
                        if (stripos($id, 'isbn') !== false || preg_match('/\d{10,13}/', $id)) {
                            $metadata['isbn'] = $id;
                            break;
                        }
                    }
                }
            }
            
            // Дополнительные метаданные из meta тегов
            foreach ($opfXml->metadata->children($namespaces['dc']) as $meta) {
                if ($meta->getName() == 'meta') {
                    $name = isset($meta['name']) ? (string)$meta['name'] : '';
                    $content = isset($meta['content']) ? (string)$meta['content'] : '';
                    
                    if ($name && $content) {
                        switch (strtolower($name)) {
                            case 'description':
                                if (empty($metadata['description'])) {
                                    $metadata['description'] = $content;
                                }
                                break;
                            case 'author':
                                if (empty($metadata['author'])) {
                                    $metadata['author'] = $content;
                                }
                                break;
                        }
                    }
                }
            }
            
            self::cleanupTempDir($tempDir);
            
        } catch (Exception $e) {
            error_log("EPUB metadata error: " . $e->getMessage());
        }
        
        if (file_exists($tempDir)) {
            self::cleanupTempDir($tempDir);
        }
        
        return $metadata;
    }
    
    /**
     * Поиск обложки в OPF файле
     */
    private static function findCoverInOpf($opfXml, $baseDir, $relativePath) {
        $namespaces = $opfXml->getNamespaces(true);
        
        // Метод 1: Ищем по meta name="cover"
        foreach ($opfXml->metadata->children($namespaces['dc']) as $meta) {
            if ($meta->getName() == 'meta' && isset($meta['name']) && $meta['name'] == 'cover') {
                $coverId = (string)$meta['content'];
                return self::getImageById($opfXml, $coverId, $baseDir, $relativePath);
            }
        }
        
        // Метод 2: Ищем item с properties="cover-image"
        foreach ($opfXml->manifest->item as $item) {
            if (isset($item['properties']) && (string)$item['properties'] == 'cover-image') {
                return self::getImageByItem($item, $baseDir, $relativePath);
            }
        }
        
        // Метод 3: Ищем item с id="cover" или id содержащий "cover"
        foreach ($opfXml->manifest->item as $item) {
            $id = (string)$item['id'];
            $href = (string)$item['href'];
            $mediaType = (string)$item['media-type'];
            
            if (stripos($id, 'cover') !== false || 
                stripos($href, 'cover') !== false ||
                stripos($href, 'title') !== false) {
                
                if (strpos($mediaType, 'image/') === 0) {
                    $imagePath = $baseDir . '/' . $relativePath . '/' . $href;
                    if (file_exists($imagePath)) {
                        return file_get_contents($imagePath);
                    }
                }
            }
        }
        
        // Метод 4: Ищем первую подходящую картинку
        foreach ($opfXml->manifest->item as $item) {
            $mediaType = (string)$item['media-type'];
            $href = (string)$item['href'];
            
            if (strpos($mediaType, 'image/') === 0) {
                $imagePath = $baseDir . '/' . $relativePath . '/' . $href;
                if (file_exists($imagePath)) {
                    // Проверяем размер (чтобы не брать маленькие иконки)
                    $imageData = file_get_contents($imagePath);
                    if (strlen($imageData) > 5000) { // Минимум 5KB
                        return $imageData;
                    }
                }
            }
        }
        
        return false;
    }
    
    private static function getImageById($opfXml, $coverId, $baseDir, $relativePath) {
        foreach ($opfXml->manifest->item as $item) {
            if ((string)$item['id'] == $coverId) {
                return self::getImageByItem($item, $baseDir, $relativePath);
            }
        }
        return false;
    }
    
    private static function getImageByItem($item, $baseDir, $relativePath) {
        $href = (string)$item['href'];
        $mediaType = (string)$item['media-type'];
        
        if (strpos($mediaType, 'image/') === 0) {
            $imagePath = $baseDir . '/' . $relativePath . '/' . $href;
            if (file_exists($imagePath)) {
                return file_get_contents($imagePath);
            }
        }
        
        return false;
    }
    
    /**
     * Очистка временной директории
     */
    private static function cleanupTempDir($dir) {
        if (!file_exists($dir)) {
            return;
        }
        
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                self::cleanupTempDir($file);
            } else {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
?>