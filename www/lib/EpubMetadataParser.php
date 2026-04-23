<?php

// lib/EpubMetadataParser.php

class EpubMetadataParser
{
    /**
     * Извлечь метаданные из EPUB файла
     */
    public static function extractMetadata($book)
    {
        $metadata = [
            'title' => '',
            'author' => '',
            'description' => '',
            'publisher' => '',
            'language' => '',
            'date' => '',
            'identifier' => ''
        ];

        // Получаем содержимое EPUB
        $content = self::getEpubContent($book);
        if (!$content) {
            return $metadata;
        }

        // Сохраняем во временный файл
        $tempFile = tempnam(sys_get_temp_dir(), 'epub_') . '.epub';
        file_put_contents($tempFile, $content);

        try {
            $zip = new ZipArchive();
            if ($zip->open($tempFile) === true) {
                // Читаем container.xml
                $container = $zip->getFromName('META-INF/container.xml');
                if ($container) {
                    // Находим путь к OPF файлу
                    if (preg_match('/full-path="([^"]+)"/', $container, $matches)) {
                        $opfPath = $matches[1];
                        $opfContent = $zip->getFromName($opfPath);

                        if ($opfContent) {
                            $opfXml = simplexml_load_string($opfContent);
                            $namespaces = $opfXml->getNamespaces(true);
                            $dc = $namespaces['dc'] ?? 'http://purl.org/dc/elements/1.1/';

                            // Извлекаем метаданные
                            $metadata['title'] = self::extractDcElement($opfXml, $dc, 'title');
                            $metadata['author'] = self::extractDcElement($opfXml, $dc, 'creator');
                            $metadata['description'] = self::extractDcElement($opfXml, $dc, 'description');
                            $metadata['publisher'] = self::extractDcElement($opfXml, $dc, 'publisher');
                            $metadata['language'] = self::extractDcElement($opfXml, $dc, 'language');
                            $metadata['date'] = self::extractDcElement($opfXml, $dc, 'date');
                            $metadata['identifier'] = self::extractDcElement($opfXml, $dc, 'identifier');

                            // Если описание пустое, ищем в другом месте
                            if (empty($metadata['description'])) {
                                $metadata['description'] = self::extractDescriptionFromOpf($opfXml);
                            }
                        }
                    }
                }
                $zip->close();
            }
        } catch (Exception $e) {
            error_log("EpubMetadataParser error: " . $e->getMessage());
        }

        // Удаляем временный файл
        @unlink($tempFile);

        return $metadata;
    }

    /**
     * Получить содержимое EPUB файла
     */
    private static function getEpubContent($book)
    {
        if (!empty($book['archive_path']) && !empty($book['archive_internal_path'])) {
            $zip = new ZipArchive();
            if ($zip->open($book['archive_path']) === true) {
                $content = $zip->getFromName($book['archive_internal_path']);
                $zip->close();
                return $content;
            }
        } elseif (!empty($book['file_path']) && file_exists($book['file_path'])) {
            return file_get_contents($book['file_path']);
        }
        return null;
    }

    /**
     * Извлечь элемент DC
     */
    private static function extractDcElement($opfXml, $dc, $element)
    {
        $value = '';

        if (isset($opfXml->metadata->children($dc)->$element)) {
            $value = (string)$opfXml->metadata->children($dc)->$element;
        }

        // Если есть несколько авторов, объединяем через запятую
        if ($element === 'creator') {
            $creators = [];
            foreach ($opfXml->metadata->children($dc)->creator as $creator) {
                $creators[] = (string)$creator;
            }
            if (count($creators) > 1) {
                $value = implode(', ', $creators);
            }
        }

        return trim($value);
    }

    /**
     * Альтернативное извлечение описания из OPF
     */
    private static function extractDescriptionFromOpf($opfXml)
    {
        // Ищем description в разных местах
        $description = '';

        // В мета-тегах
        foreach ($opfXml->metadata->meta as $meta) {
            $name = (string)$meta['name'];
            if ($name === 'description') {
                $description = (string)$meta;
                break;
            }
        }

        return trim($description);
    }
}
