<?php

require_once __DIR__ . '/BaseParser.php';

class Fb2CoverParser extends BaseCoverParser
{
    public function hasCover($book): bool
    {
        $cacheKey = 'has_cover_' . $book['id'];
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $content = $this->getBookContent($book);
        $hasCover = $this->findCoverInContent($content) !== false;

        Cache::set($cacheKey, $hasCover, 300);

        return $hasCover;
    }

    public function getCover($book, $thumb = false)
    {
        // Проверяем кэш
        $cached = $this->loadFromCache($book['id'], $thumb);
        if ($cached) {
            return $cached;
        }

        $content = $this->getBookContent($book);
        $coverData = $this->findCoverInContent($content);

        if ($coverData) {
            $this->saveToCache($book['id'], $coverData, $thumb);
            return $thumb ? $this->createThumbnail($coverData) : $coverData;
        }

        return null;
    }

    public function getCoverInfo($book): ?array
    {
        $content = $this->getBookContent($book);
        $coverData = $this->findCoverInContent($content);

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

    private function findCoverInContent($content)
    {
        if (empty($content)) {
            return false;
        }

        // Метод 1: coverpage с l:href
        if (preg_match('/<coverpage>.*?<image[^>]*l:href[[:space:]]*=[[:space:]]*["\']#([^"\']+)["\'][^>]*>.*?<\/coverpage>/is', $content, $matches)) {
            $coverId = $matches[1];
            $imageData = $this->extractBinaryData($content, $coverId);
            if ($imageData && $this->isValidImage($imageData)) {
                return $imageData;
            }
        }

        // Метод 2: coverpage с xlink:href
        if (preg_match('/<coverpage>.*?<image[^>]*xlink:href[[:space:]]*=[[:space:]]*["\']#([^"\']+)["\'][^>]*>.*?<\/coverpage>/is', $content, $matches)) {
            $coverId = $matches[1];
            $imageData = $this->extractBinaryData($content, $coverId);
            if ($imageData && $this->isValidImage($imageData)) {
                return $imageData;
            }
        }

        // Метод 3: Любые бинарные данные с изображениями
        return $this->findAnyImageBinary($content);
    }

    private function extractBinaryData($content, $binaryId)
    {
        $patterns = [
            '/<binary[^>]*id[[:space:]]*=[[:space:]]*["\']' . preg_quote($binaryId, '/') . '["\'][^>]*content-type[[:space:]]*=[[:space:]]*["\']([^"\']+)["\'][^>]*>([^<]*)<\/binary>/is',
            '/<binary[^>]*id[[:space:]]*=[[:space:]]*["\']' . preg_quote($binaryId, '/') . '["\'][^>]*>([^<]*)<\/binary>/is'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $data = base64_decode(trim($matches[count($matches) == 3 ? 2 : 1]));
                if ($data && strlen($data) > 100) {
                    return $data;
                }
            }
        }

        return false;
    }

    private function findAnyImageBinary($content)
    {
        if (preg_match_all('/<binary[^>]*>([^<]*)<\/binary>/is', $content, $binaries)) {
            foreach ($binaries[1] as $binary) {
                $data = base64_decode(trim($binary));
                if ($this->isValidImage($data)) {
                    return $data;
                }
            }
        }

        return false;
    }
}
