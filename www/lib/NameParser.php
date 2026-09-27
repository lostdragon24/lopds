<?php

class NameParser
{
    // Типичные русские окончания фамилий
    private static $lastNameEndings = [
        'ов', 'ев', 'ёв', 'ин', 'ын', 'ский', 'цкий', 'ской', 'цкой',
        'их', 'ых', 'ко', 'ук', 'юк', 'енко', 'чук',
        'ич', 'вич', 'вна', 'чна', 'нична',
        'ая', 'яя', 'ое', 'ее', 'ые', 'ие',
        'ова', 'ева', 'ёва', 'ина', 'ына',
        'овский', 'евский', 'инский',
        'овская', 'евская', 'инская',
    ];

    // Типичные окончания имён
    private static $firstNameEndings = [
        'ил', 'ий', 'ия', 'ея', 'ая', 'ля', 'на', 'ра', 'са', 'та','ей',
        'ша', 'ха', 'ца', 'ча', 'ща', 'да', 'за', 'ка', 'ма', 'па'
    ];

    // Явные имена (сокращённый список частых)
    private static $commonFirstNames = [
        'александр', 'алексей', 'андрей', 'антон', 'аркадий', 'артём', 'артемий',
        'богдан', 'борис', 'вадим', 'валентин', 'валерий', 'василий', 'виктор',
        'виталий', 'владимир', 'владислав', 'вячеслав', 'геннадий', 'георгий',
        'григорий', 'даниил', 'данила', 'денис', 'дмитрий', 'евгений', 'егор',
        'илья', 'иван', 'игорь', 'кирилл', 'константин', 'лев', 'леонид',
        'максим', 'михаил', 'никита', 'николай', 'олег', 'павел', 'пётр',
        'петр', 'роман', 'руслан', 'сергей', 'станислав', 'степан', 'тимофей',
        'фёдор', 'федор', 'эдуард', 'юрий', 'яков',
        'анна', 'алёна', 'алена', 'алексandra', 'виктория', 'галина', 'дарья',
        'екатерина', 'елена', 'ирина', 'марина', 'мария', 'наталья', 'ольга',
        'светлана', 'татьяна', 'юлия'
    ];

    // Исключения — слова, которые всегда фамилии (короткие, китайские/корейские частицы)
    private static $alwaysLastName = [
        'ли', 'су', 'то', 'но', 'ки', 'ко', 'го', 'до', 'мо', 'со',
        'ха', 'ху', 'цзя', 'сяо', 'мао', 'дэн', 'си', 'цзинь', 'чжу', 'ванг'
    ];

    // Исключения — слова, которые всегда имена
    private static $alwaysFirstName = [
        'илья', 'лев', 'зея', 'зоя', 'лия', 'ней', 'рей', 'сай',
        'тай', 'фай', 'хай', 'цай', 'шай', 'щай', 'ева'
    ];

    // Карта диакритики → базовые символы
    private static $diacriticsMap = [
        'ё' => 'e', 'Ё' => 'E',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ç' => 'c', 'č' => 'c', 'ć' => 'c',
        'ñ' => 'n', 'ń' => 'n',
        'š' => 's', 'ś' => 's',
        'ž' => 'z', 'ź' => 'z',
        'ř' => 'r',
        'đ' => 'd',
        'ł' => 'l',
        'ß' => 'ss',
        'æ' => 'ae', 'œ' => 'oe',
        // Заглавные
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Ā' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E', 'Ė' => 'E', 'Ę' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ī' => 'I', 'Į' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O', 'Ø' => 'O', 'Ō' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ū' => 'U', 'Ů' => 'U',
        'Ý' => 'Y', 'Ÿ' => 'Y',
        'Ç' => 'C', 'Č' => 'C', 'Ć' => 'C',
        'Ñ' => 'N', 'Ń' => 'N',
        'Š' => 'S', 'Ś' => 'S',
        'Ž' => 'Z', 'Ź' => 'Z',
        'Ř' => 'R',
        'Đ' => 'D',
        'Ł' => 'L',
    ];

    /**
     * Разобрать имя автора на составляющие
     */
    public static function parse($fullName)
    {
        $fullName = trim($fullName);
        if (empty($fullName)) {
            return self::emptyResult($fullName);
        }

        $words = preg_split('/\s+/', $fullName);
        $count = count($words);

        $result = self::emptyResult($fullName);

        if ($count === 0) {
            return $result;
        }

        if ($count === 1) {
            $result['lastName'] = $words[0];
            $result['normalizedLastName'] = self::normalizeLastName($words[0]);
            $result['normalizedFull'] = $result['normalizedLastName'];
            $result['phoneticKey'] = self::phoneticKey($result['normalizedLastName']);
            return $result;
        }

        $types = [];
        foreach ($words as $word) {
            $types[] = self::determineWordType($word);
        }

        // Определяем, преобладает ли кириллица
        $isCyrillic = self::isPredominantlyCyrillic($fullName);

        if ($count === 2) {
            $result = self::parseTwoWords($words, $types, $isCyrillic);
        } elseif ($count === 3) {
            $result = self::parseThreeWords($words, $types, $isCyrillic);
        } else {
            $result = self::parseComplex($words, $types, $isCyrillic);
        }

        // Нормализация
        if (!empty($result['lastName'])) {
            $result['normalizedLastName'] = self::normalizeLastName($result['lastName']);
        }

        // Собираем нормализованное полное имя
        $normalizedParts = [];
        if (!empty($result['normalizedLastName'])) {
            $normalizedParts[] = $result['normalizedLastName'];
        }
        if (!empty($result['firstName'])) {
            $normalizedParts[] = self::removeDiacritics(mb_strtolower($result['firstName'], 'UTF-8'));
        }
        $result['normalizedFull'] = implode(' ', $normalizedParts);
        $result['phoneticKey'] = self::phoneticKey($result['normalizedLastName'] ?: $result['normalizedFull']);

        return $result;
    }

    private static function emptyResult($fullName)
    {
        return [
            'lastName' => '',
            'firstName' => '',
            'patronymic' => '',
            'original' => $fullName,
            'normalizedLastName' => '',
            'normalizedFull' => '',
            'phoneticKey' => '',
            'isCyrillic' => false
        ];
    }

    /**
     * Определяет, состоит ли строка преимущественно из кириллицы
     */
    private static function isPredominantlyCyrillic($str)
    {
        $cyrillic = preg_match_all('/[А-Яа-яЁё]/u', $str);
        $latin = preg_match_all('/[A-Za-z]/u', $str);
        return $cyrillic >= $latin;
    }

    /**
     * Убрать диакритику из строки
     */
    public static function removeDiacritics($str)
    {
        return strtr($str, self::$diacriticsMap);
    }

    /**
     * Фонетический ключ для группировки похожих имён
     * Для латиницы — Soundex, для кириллицы — нормализованная фамилия
     */
    private static function phoneticKey($str)
    {
        $str = self::removeDiacritics($str);
        $str = mb_strtolower(trim($str), 'UTF-8');

        if (empty($str)) {
            return '';
        }

        // Если кириллица — используем транслитерированный Soundex
        if (preg_match('/[а-яё]/u', $str)) {
            $latin = self::transliterateCyrillicToLatin($str);
            return self::soundex($latin);
        }

        return self::soundex($str);
    }

    /**
     * Простая реализация Soundex для латиницы
     */
    private static function soundex($str)
    {
        $str = mb_strtoupper($str, 'UTF-8');
        $str = preg_replace('/[^A-Z]/', '', $str);
        if (empty($str)) {
            return '';
        }

        $first = $str[0];
        $str = substr($str, 1);

        $mapping = [
            'BFPV' => '1', 'CGJKQSXZ' => '2', 'DT' => '3',
            'L' => '4', 'MN' => '5', 'R' => '6'
        ];

        $codes = '';
        foreach (str_split($str) as $char) {
            foreach ($mapping as $letters => $code) {
                if (strpos($letters, $char) !== false) {
                    $codes .= $code;
                    break;
                }
            }
        }

        // Убираем дубли
        $codes = preg_replace('/(\d)\1+/', '$1', $codes);
        // Убираем коды, совпадающие с первой буквой
        $firstCode = '';
        foreach ($mapping as $letters => $code) {
            if (strpos($letters, $first) !== false) {
                $firstCode = $code;
                break;
            }
        }
        if ($codes && $codes[0] === $firstCode) {
            $codes = substr($codes, 1);
        }

        $codes = $first . substr($codes, 0, 3);
        return str_pad($codes, 4, '0');
    }

    /**
     * Транслитерация для фонетического ключа
     */
    private static function transliterateCyrillicToLatin($str)
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya'
        ];
        return strtr(mb_strtolower($str, 'UTF-8'), $map);
    }

    /**
     * Определить тип слова
     */
    private static function determineWordType($word)
    {
        $wordLower = mb_strtolower($word, 'UTF-8');
        $len = mb_strlen($wordLower, 'UTF-8');

        if ($len < 2) {
            return 'unknown';
        }

        if (in_array($wordLower, self::$alwaysLastName)) {
            return 'lastName';
        }
        if (in_array($wordLower, self::$alwaysFirstName)) {
            return 'firstName';
        }

        if ($len <= 3) {
            if (in_array($wordLower, self::$commonFirstNames)) {
                return 'firstName';
            }
            if (self::looksLikePatronymic($wordLower)) {
                return 'patronymic';
            }
            return 'unknown';
        }

        if (self::looksLikePatronymic($wordLower)) {
            return 'patronymic';
        }
        if (self::looksLikeLastName($wordLower)) {
            return 'lastName';
        }
        if (self::looksLikeFirstName($wordLower)) {
            return 'firstName';
        }
        if (in_array($wordLower, self::$commonFirstNames)) {
            return 'firstName';
        }

        if (preg_match('/^[А-ЯA-Z]\.?$/u', $word)) {
            return 'firstName';
        }

        return 'unknown';
    }

    private static function looksLikePatronymic($word)
    {
        $len = mb_strlen($word, 'UTF-8');
        if ($len < 3) {
            return false;
        }
        $endings = ['вич', 'вна', 'чна', 'нична', 'вныч', 'ична'];
        foreach ($endings as $ending) {
            $endingLen = mb_strlen($ending, 'UTF-8');
            if (mb_substr($word, -$endingLen, null, 'UTF-8') === $ending) {
                return true;
            }
        }
        return false;
    }

    private static function looksLikeLastName($word)
    {
        $len = mb_strlen($word, 'UTF-8');
        if ($len < 2) {
            return false;
        }
        foreach (self::$lastNameEndings as $ending) {
            $endingLen = mb_strlen($ending, 'UTF-8');
            if (mb_substr($word, -$endingLen, null, 'UTF-8') === $ending) {
                return true;
            }
        }
        return false;
    }

    private static function looksLikeFirstName($word)
    {
        $len = mb_strlen($word, 'UTF-8');
        if ($len < 2) {
            return false;
        }
        foreach (self::$firstNameEndings as $ending) {
            $endingLen = mb_strlen($ending, 'UTF-8');
            if (mb_substr($word, -$endingLen, null, 'UTF-8') === $ending) {
                return true;
            }
        }
        return false;
    }

    /**
     * Два слова: Имя Фамилия
     */
    private static function parseTwoWords($words, $types, $isCyrillic)
    {
        $result = ['lastName' => '', 'firstName' => '', 'patronymic' => ''];
        $word1Lower = mb_strtolower($words[0], 'UTF-8');
        $word2Lower = mb_strtolower($words[1], 'UTF-8');

        $isLastName1 = in_array($word1Lower, self::$alwaysLastName) || self::looksLikeLastName($word1Lower);
        $isLastName2 = in_array($word2Lower, self::$alwaysLastName) || self::looksLikeLastName($word2Lower);
        $isFirstName1 = in_array($word1Lower, self::$commonFirstNames) || in_array($word1Lower, self::$alwaysFirstName);
        $isFirstName2 = in_array($word2Lower, self::$commonFirstNames) || in_array($word2Lower, self::$alwaysFirstName);

        // Если латиница и нет явных русских маркеров — западный порядок: последнее = фамилия
        if (!$isCyrillic) {
            if ($isLastName1 && !$isLastName2 && !$isFirstName2) {
                $result['lastName'] = $words[0];
                $result['firstName'] = $words[1];
            } else {
                $result['lastName'] = $words[1];
                $result['firstName'] = $words[0];
            }
            return $result;
        }

        // Кириллица — русская логика
        if ($isLastName1 && $isFirstName2) {
            $result['lastName'] = $words[0];
            $result['firstName'] = $words[1];
            return $result;
        }
        if ($isFirstName1 && $isLastName2) {
            $result['lastName'] = $words[1];
            $result['firstName'] = $words[0];
            return $result;
        }
        if ($isLastName1 && $isLastName2) {
            $result['lastName'] = $words[1];
            $result['firstName'] = $words[0];
            return $result;
        }
        if ($isLastName1) {
            $result['lastName'] = $words[0];
            $result['firstName'] = $words[1];
            return $result;
        }
        if ($isLastName2) {
            $result['lastName'] = $words[1];
            $result['firstName'] = $words[0];
            return $result;
        }

        // Fallback: для кириллицы — первое может быть фамилией, но чаще последнее
        // Если первое явно имя — фамилия второе
        if ($isFirstName1) {
            $result['lastName'] = $words[1];
            $result['firstName'] = $words[0];
            return $result;
        }

        // По умолчанию: последнее = фамилия (более безопасно для смешанных данных)
        $result['lastName'] = $words[1];
        $result['firstName'] = $words[0];
        return $result;
    }

    /**
     * Три слова: Фамилия Имя Отчество или Имя Отчество Фамилия
     */
    private static function parseThreeWords($words, $types, $isCyrillic)
    {
        $result = ['lastName' => '', 'firstName' => '', 'patronymic' => ''];

        // Для латиницы — западный порядок: последнее = фамилия
        if (!$isCyrillic) {
            $result['lastName'] = $words[2];
            $result['firstName'] = $words[0] . ' ' . $words[1];
            return $result;
        }

        // Кириллица
        $lastNameIndex = -1;
        for ($i = 0; $i < 3; $i++) {
            if ($types[$i] === 'lastName') {
                $lastNameIndex = $i;
                break;
            }
        }

        if ($lastNameIndex !== -1) {
            $result['lastName'] = $words[$lastNameIndex];
            $remaining = array_values(array_diff_key($words, [$lastNameIndex => '']));
            if (count($remaining) === 2) {
                $r1 = mb_strtolower($remaining[0], 'UTF-8');
                $r2 = mb_strtolower($remaining[1], 'UTF-8');
                if (self::looksLikePatronymic($r2)) {
                    $result['firstName'] = $remaining[0];
                    $result['patronymic'] = $remaining[1];
                } elseif (self::looksLikePatronymic($r1)) {
                    $result['firstName'] = $remaining[1];
                    $result['patronymic'] = $remaining[0];
                } elseif (in_array($r1, self::$commonFirstNames)) {
                    $result['firstName'] = $remaining[0];
                    $result['patronymic'] = $remaining[1];
                } elseif (in_array($r2, self::$commonFirstNames)) {
                    $result['firstName'] = $remaining[1];
                    $result['patronymic'] = $remaining[0];
                } else {
                    $result['firstName'] = $remaining[0];
                    $result['patronymic'] = $remaining[1];
                }
            }
            return $result;
        }

        // Отчество в середине
        if ($types[1] === 'patronymic') {
            $result['patronymic'] = $words[1];
            $firstLower = mb_strtolower($words[0], 'UTF-8');
            $lastLower = mb_strtolower($words[2], 'UTF-8');

            if (self::looksLikeLastName($firstLower) && !self::looksLikeLastName($lastLower)) {
                $result['lastName'] = $words[0];
                $result['firstName'] = $words[2];
            } elseif (in_array($firstLower, self::$commonFirstNames)) {
                $result['firstName'] = $words[0];
                $result['lastName'] = $words[2];
            } else {
                $result['lastName'] = $words[2];
                $result['firstName'] = $words[0];
            }
            return $result;
        }

        // Fallback: последнее = фамилия
        $result['lastName'] = $words[2];
        $result['firstName'] = $words[0];
        $result['patronymic'] = $words[1];
        return $result;
    }

    /**
     * Более 3 слов
     */
    private static function parseComplex($words, $types, $isCyrillic)
    {
        $result = ['lastName' => '', 'firstName' => '', 'patronymic' => ''];
        $count = count($words);

        // Для латиницы — последнее слово фамилия
        if (!$isCyrillic) {
            $result['lastName'] = $words[$count - 1];
            $result['firstName'] = implode(' ', array_slice($words, 0, $count - 1));
            return $result;
        }

        $lastNameIndex = -1;
        for ($i = 0; $i < $count; $i++) {
            if ($types[$i] === 'lastName') {
                $lastNameIndex = $i;
                break;
            }
        }

        if ($lastNameIndex === -1) {
            for ($i = $count - 1; $i >= 0; $i--) {
                if (self::looksLikeLastName(mb_strtolower($words[$i], 'UTF-8'))) {
                    $lastNameIndex = $i;
                    break;
                }
            }
        }

        if ($lastNameIndex !== -1) {
            $result['lastName'] = $words[$lastNameIndex];
            $firstNameParts = [];
            $patronymicParts = [];
            for ($i = 0; $i < $count; $i++) {
                if ($i === $lastNameIndex) {
                    continue;
                }
                $wordLower = mb_strtolower($words[$i], 'UTF-8');
                if (self::looksLikePatronymic($wordLower)) {
                    $patronymicParts[] = $words[$i];
                } else {
                    $firstNameParts[] = $words[$i];
                }
            }
            $result['firstName'] = implode(' ', $firstNameParts);
            if (!empty($patronymicParts)) {
                $result['patronymic'] = implode(' ', $patronymicParts);
            }
            return $result;
        }

        $result['lastName'] = $words[$count - 1];
        $result['firstName'] = implode(' ', array_slice($words, 0, $count - 1));
        return $result;
    }

    /**
     * Нормализация фамилии
     */
    private static function normalizeLastName($lastName)
    {
        if (empty($lastName)) {
            return '';
        }

        $lastName = trim($lastName);
        $lastNameLower = mb_strtolower($lastName, 'UTF-8');
        $lastNameLower = str_replace('ё', 'е', $lastNameLower);
        $lastNameLower = self::removeDiacritics($lastNameLower);

        // Если латиница — просто lower + trim
        if (!preg_match('/[а-яё]/u', $lastNameLower)) {
            return $lastNameLower;
        }

        // Русские окончания: женские → мужские формы
        $genderMap = [
            'ская' => 'ский', 'цкая' => 'цкий', 'ая' => 'ый', 'яя' => 'ий',
            'ое' => 'ый', 'ее' => 'ий',
            'ова' => 'ов', 'ева' => 'ев', 'ёва' => 'ёв', 'ина' => 'ин',
            'ына' => 'ын', 'их' => 'их', 'ых' => 'ых',
            'ской' => 'ской', 'цкой' => 'цкой',
            'сон' => 'сон'
        ];

        foreach ($genderMap as $female => $male) {
            $endingLen = mb_strlen($female, 'UTF-8');
            if (mb_substr($lastNameLower, -$endingLen, null, 'UTF-8') === $female) {
                $root = mb_substr($lastName, 0, -$endingLen, 'UTF-8');
                return mb_strtolower($root . $male, 'UTF-8');
            }
        }

        return $lastNameLower;
    }
}
