<?php

interface CoverParserInterface
{
    /**
     * Проверить наличие обложки
     */
    public function hasCover($book): bool;

    /**
     * Получить данные обложки
     */
    public function getCover($book, $thumb = false);

    /**
     * Получить метаданные обложки (размер, тип и т.д.)
     */
    public function getCoverInfo($book): ?array;

    /**
     * Очистить кэш для книги
     */
    public function clearCache($bookId): bool;
}
