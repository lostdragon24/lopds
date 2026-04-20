<?php

// lib/GenreManager.php

require_once __DIR__.'/Translator.php';

class GenreManager
{
    private static $genres = [];
    private static $genresByLanguage = [];
    private static $currentLanguage;

    /**
     * Загрузить жанры для текущего языка.
     */
    private static function loadGenres()
    {
        $lang = Translator::getInstance()->getCurrentLanguage();

        if (self::$currentLanguage === $lang && !empty(self::$genres)) {
            return;
        }

        if (isset(self::$genresByLanguage[$lang])) {
            self::$genres = self::$genresByLanguage[$lang];
            self::$currentLanguage = $lang;
            error_log("GenreManager: Using cached genres for {$lang}");

            return;
        }

        $genresFile = __DIR__."/../lang/genres/{$lang}.php";
        if (file_exists($genresFile)) {
            self::$genres = include $genresFile;
        } else {
            $fallback = __DIR__.'/../lang/genres/ru.php';
            if (file_exists($fallback)) {
                self::$genres = include $fallback;
            }
        }

        self::$genresByLanguage[$lang] = self::$genres;
        self::$currentLanguage = $lang;
        error_log('GenreManager: Loaded '.count(self::$genres).' genres');
    }

    /**
     * Получить читаемое название жанра по его коду.
     *
     * @param string|null $genreCode Код жанра (может быть null)
     *
     * @return string|null Читаемое название или null
     */
    public static function getReadableName($genreCode): ?string
    {
        // Обрабатываем null и пустые значения
        if (empty($genreCode)) {
            return null;
        }

        // Приводим к строке для безопасности
        $genreCode = (string) $genreCode;

        if (empty(self::$genres)) {
            self::loadGenres();
        }

        return self::$genres[$genreCode] ?? self::formatUnknownGenre($genreCode);
    }

    /**
     * Получить все жанры (код => название) для текущего языка.
     */
    public static function getAllGenres(): array
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }

        return self::$genres;
    }

    /**
     * Получить все коды жанров.
     */
    public static function getAllCodes(): array
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }

        return array_keys(self::$genres);
    }

    /**
     * Получить все названия жанров для текущего языка.
     */
    public static function getAllNames(): array
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }

        return array_values(self::$genres);
    }

    /**
     * Получить группы жанров по категориям (с учетом текущего языка).
     */
    public static function getGenresByCategory(): array
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }

        $categories = [
            'Фантастика' => ['sf', 'sf_', 'fantasy', 'фантастика', 'hronoopera', 'popadancy', 'popadanec'],
            'Детективы и триллеры' => ['det', 'thriller', 'детектив'],
            'Проза' => ['prose', 'roman', 'novel', 'story', 'проза'],
            'Любовные романы' => ['love', 'romance', 'эротика', 'любовные'],
            'Приключения' => ['adv', 'adventure', 'путешествия', 'вестерн', 'приключения'],
            'Детская литература' => ['child', 'children', 'сказки', 'детск'],
            'Поэзия и драматургия' => ['poetry', 'dramaturgy', 'поэзия', 'стихи', 'драма'],
            'Научная литература' => ['sci', 'science', 'науч', 'учебник', 'education'],
            'Компьютеры и техника' => ['comp', 'computers', 'программирование', 'техника'],
            'Религия и эзотерика' => ['religion', 'религия', 'эзотерика', 'мистика'],
            'Юмор' => ['humor', 'юмор', 'сатира', 'комедия'],
            'Дом и семья' => ['home', 'дом', 'кулинария', 'сад', 'здоровье', 'спорт'],
            'Справочная литература' => ['ref', 'справочник', 'словарь', 'энциклопедия'],
            'Бизнес и экономика' => ['business', 'экономика', 'финансы', 'маркетинг'],
            'Искусство и культура' => ['art', 'culture', 'искусство', 'музыка', 'кино'],
            'Другое' => [],
        ];

        $result = [];
        foreach ($categories as $category => $patterns) {
            $result[$category] = [];
        }

        foreach (self::$genres as $code => $name) {
            $codeLower = strtolower($code);
            $nameLower = strtolower($name);
            $assigned = false;

            foreach ($categories as $category => $patterns) {
                foreach ($patterns as $pattern) {
                    if (false !== strpos($codeLower, $pattern) || false !== strpos($nameLower, $pattern)) {
                        $result[$category][$code] = $name;
                        $assigned = true;
                        break 2;
                    }
                }
            }

            if (!$assigned) {
                $result['Другое'][$code] = $name;
            }
        }

        // Сортируем каждую категорию по названию
        foreach ($result as &$genres) {
            asort($genres);
        }

        return $result;
    }

    /**
     * Проверить, существует ли жанр
     */
    public static function genreExists(string $genreCode): bool
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }

        return isset(self::$genres[$genreCode]);
    }

    /**
     * Получить статистику по жанрам (для админки).
     */
    public static function getGenreStats(): array
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }

        return [
            'total_genres' => count(self::$genres),
            'categories' => array_keys(self::getGenresByCategory()),
            'sample' => array_slice(self::$genres, 0, 10, true),
        ];
    }

    /**
     * Отформатировать неизвестный код жанра.
     */
    private static function formatUnknownGenre(string $genreCode): string
    {
        // Заменяем подчеркивания на пробелы и делаем заглавными первые буквы
        $formatted = str_replace('_', ' ', $genreCode);
        $formatted = ucwords($formatted);

        return $formatted;
    }

    /**
     * Поиск жанров по названию или коду.
     */
    public static function searchGenres(string $query): array
    {
        if (empty(self::$genres)) {
            self::loadGenres();
        }

        $query = mb_strtolower($query);
        $results = [];

        foreach (self::$genres as $code => $name) {
            if (false !== mb_strpos(mb_strtolower($code), $query)
                || false !== mb_strpos(mb_strtolower($name), $query)) {
                $results[$code] = $name;
            }
        }

        return $results;
    }

    /**
     * Принудительно перезагрузить жанры (при смене языка).
     */
    public static function reload()
    {
        self::$genres = [];
        self::loadGenres();
    }
}
