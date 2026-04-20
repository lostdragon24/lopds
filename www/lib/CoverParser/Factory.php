<?php

require_once __DIR__.'/Fb2Parser.php';
require_once __DIR__.'/EpubParser.php';

class CoverParserFactory
{
    private static $instances = [];

    /**
     * Получить парсер для типа файла.
     */
    public static function getParser($fileType)
    {
        $fileType = strtolower($fileType);

        if (!isset(self::$instances[$fileType])) {
            switch ($fileType) {
                case 'fb2':
                    self::$instances[$fileType] = new Fb2CoverParser();
                    break;

                case 'epub':
                    self::$instances[$fileType] = new EpubCoverParser();
                    break;

                default:
                    return null;
            }
        }

        return self::$instances[$fileType];
    }

    /**
     * Получить парсер для книги.
     */
    public static function getParserForBook($book)
    {
        return self::getParser($book['file_type'] ?? '');
    }

    /**
     * Универсальный метод получения обложки.
     */
    public static function getCover($book, $thumb = false)
    {
        $parser = self::getParserForBook($book);

        if ($parser) {
            return $parser->getCover($book, $thumb);
        }

        return null;
    }

    /**
     * Универсальная проверка наличия обложки.
     */
    public static function hasCover($book)
    {
        $parser = self::getParserForBook($book);

        if ($parser) {
            return $parser->hasCover($book);
        }

        return false;
    }

    /**
     * Очистить кэш для книги.
     */
    public static function clearCache($bookId, $fileType = null)
    {
        if ($fileType) {
            $parser = self::getParser($fileType);
            if ($parser) {
                return $parser->clearCache($bookId);
            }
        } else {
            // Пробуем все парсеры
            foreach (['fb2', 'epub'] as $type) {
                $parser = self::getParser($type);
                if ($parser) {
                    $parser->clearCache($bookId);
                }
            }
        }

        return true;
    }
}
