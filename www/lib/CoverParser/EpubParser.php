<?php

require_once __DIR__ . '/BaseParser.php';

class EpubCoverParser extends BaseCoverParser
{
    public function hasCover($book): bool
    {
        $cacheKey = 'has_cover_epub_' . $book['id'];
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $coverData = $this->extractCoverFromEpub($book);
        $hasCover = $coverData !== false && strlen($coverData) > 1000;

        Cache::set($cacheKey, $hasCover, 300);

        return $hasCover;
    }

    public function getCover($book, $thumb = false)
    {
        $cached = $this->loadFromCache($book['id'], $thumb);
        if ($cached) {
            return $cached;
        }

        $coverData = $this->extractCoverFromEpub($book);

        if ($coverData) {
            $this->saveToCache($book['id'], $coverData, $thumb);
            return $thumb ? $this->createThumbnail($coverData) : $coverData;
        }

        return null;
    }

    public function getCoverInfo($book): ?array
    {
        $coverData = $this->extractCoverFromEpub($book);

        if (!$coverData) {
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'info_');
        file_put_contents($tempFile, $coverData);
        $info = getimagesize($tempFile);
        unlink($tempFile);

        if (!$info) {
            return null;
        }

        return [
            'width' => $info[0],
            'height' => $info[1],
            'type' => $info[2],
            'mime' => $info['mime'],
            'size' => strlen($coverData)
        ];
    }

    private function extractCoverFromEpub($book)
    {
        $filePath = $this->getEpubFilePath($book);
        if (!$filePath) {
            return false;
        }

        $tempDir = sys_get_temp_dir() . '/epub_' . uniqid();

        try {
            $zip = new ZipArchive();
            if ($zip->open($filePath) !== true) {
                return false;
            }

            $zip->extractTo($tempDir);
            $zip->close();

            // Ищем container.xml
            $containerPath = $tempDir . '/META-INF/container.xml';
            if (!file_exists($containerPath)) {
                $this->cleanup($tempDir);
                return false;
            }

            $containerXml = simplexml_load_file($containerPath);
            $rootfile = (string)$containerXml->rootfiles->rootfile['full-path'];

            $opfPath = $tempDir . '/' . $rootfile;
            if (!file_exists($opfPath)) {
                $this->cleanup($tempDir);
                return false;
            }

            $opfXml = simplexml_load_file($opfPath);
            $coverData = $this->findCoverInOpf($opfXml, $tempDir, dirname($rootfile));

            $this->cleanup($tempDir);

            // Если файл был временным (из архива), удаляем его
            if ($filePath !== $book['file_path'] && file_exists($filePath)) {
                unlink($filePath);
            }

            return $coverData;

        } catch (Exception $e) {
            $this->cleanup($tempDir);
            if ($filePath !== $book['file_path'] && file_exists($filePath)) {
                unlink($filePath);
            }
            return false;
        }
    }

    private function getEpubFilePath($book)
    {
        if ($book['archive_path'] && $book['archive_internal_path']) {
            $zip = new ZipArchive();
            if ($zip->open($book['archive_path']) === true) {
                $content = $zip->getFromName($book['archive_internal_path']);
                $zip->close();

                if ($content) {
                    $tempFile = tempnam(sys_get_temp_dir(), 'epub_') . '.epub';
                    file_put_contents($tempFile, $content);
                    return $tempFile;
                }
            }
            return false;
        }

        return $book['file_path'];
    }

    private function findCoverInOpf($opfXml, $baseDir, $relativePath)
    {
        $namespaces = $opfXml->getNamespaces(true);

        // Метод 1: meta name="cover"
        if (!empty($namespaces)) {
            $dc = $opfXml->metadata->children($namespaces['dc'] ?? '');
            foreach ($dc as $meta) {
                if ($meta->getName() == 'meta' && isset($meta['name']) && $meta['name'] == 'cover') {
                    $coverId = (string)$meta['content'];
                    return $this->getImageById($opfXml, $coverId, $baseDir, $relativePath);
                }
            }
        }

        // Метод 2: properties="cover-image"
        foreach ($opfXml->manifest->item as $item) {
            if (isset($item['properties']) && (string)$item['properties'] == 'cover-image') {
                return $this->getImageByItem($item, $baseDir, $relativePath);
            }
        }

        // Метод 3: id или href содержит "cover"
        foreach ($opfXml->manifest->item as $item) {
            $id = (string)$item['id'];
            $href = (string)$item['href'];
            $mediaType = (string)$item['media-type'];

            if (stripos($id, 'cover') !== false || stripos($href, 'cover') !== false) {
                if (strpos($mediaType, 'image/') === 0) {
                    return $this->getImageByItem($item, $baseDir, $relativePath);
                }
            }
        }

        return false;
    }

    private function getImageById($opfXml, $coverId, $baseDir, $relativePath)
    {
        foreach ($opfXml->manifest->item as $item) {
            if ((string)$item['id'] == $coverId) {
                return $this->getImageByItem($item, $baseDir, $relativePath);
            }
        }
        return false;
    }

    private function getImageByItem($item, $baseDir, $relativePath)
    {
        $href = (string)$item['href'];
        $mediaType = (string)$item['media-type'];

        if (strpos($mediaType, 'image/') === 0) {
            $imagePath = $baseDir . '/' . $relativePath . '/' . $href;
            if (file_exists($imagePath) && filesize($imagePath) > 1000) {
                return file_get_contents($imagePath);
            }
        }

        return false;
    }

    private function cleanup($dir)
    {
        if (!file_exists($dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
