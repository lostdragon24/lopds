<?php

class EncodingHelper {
    
    /**
     * Определить реальную кодировку файла, игнорируя неверные XML декларации
     */
    public static function detectRealEncoding($content) {
        if (empty($content)) {
            return 'UTF-8';
        }
        
        // Берем первые 5000 байт для анализа
        $sample = substr($content, 0, 5000);
        
        // 1. Проверяем BOM (самый надежный показатель)
        $bom = substr($sample, 0, 3);
        if ($bom === "\xEF\xBB\xBF") {
            return 'UTF-8';
        } elseif ($bom === "\xFF\xFE") {
            return 'UTF-16LE';
        } elseif ($bom === "\xFE\xFF") {
            return 'UTF-16BE';
        }
        
        // 2. Анализируем фактическое содержимое, а не XML декларацию
        // Определяем кодировку по статистике символов
        
        // Проверяем, является ли содержимое валидным UTF-8
        if (self::isValidUTF8($sample)) {
            // Проверяем, есть ли кириллица в UTF-8
            if (preg_match('/[А-Яа-яЁё]/u', $sample)) {
                return 'UTF-8';
            }
        }
        
        // 3. Анализируем частоту встречаемости символов Windows-1251
        $windows1251Score = self::calculateWindows1251Score($sample);
        $koi8rScore = self::calculateKOI8RScore($sample);
        $cp866Score = self::calculateCP866Score($sample);
        
        // 4. Выбираем кодировку с наибольшим счетом
        $scores = [
            'Windows-1251' => $windows1251Score,
            'KOI8-R' => $koi8rScore,
            'CP866' => $cp866Score,
        ];
        
        arsort($scores);
        $bestEncoding = key($scores);
        $bestScore = current($scores);
        
        // Если есть явный лидер, возвращаем его
        if ($bestScore > 10) {
            return $bestEncoding;
        }
        
        // 5. Пробуем mb_detect_encoding как запасной вариант
        if (function_exists('mb_detect_encoding')) {
            $detected = mb_detect_encoding($sample, ['UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-5', 'CP866'], true);
            if ($detected) {
                return $detected;
            }
        }
        
        // 6. По умолчанию Windows-1251 для русских текстов
        return 'Windows-1251';
    }
    
    /**
     * Проверить, является ли текст валидным UTF-8
     */
    public static function isValidUTF8($text) {
        return mb_check_encoding($text, 'UTF-8');
    }
    
    /**
     * Подсчет "счета" для Windows-1251
     */
    private static function calculateWindows1251Score($text) {
        $score = 0;
        $length = strlen($text);
        
        // Частые русские буквы в Windows-1251
        $commonLetters = [
            "\xEE", // о
            "\xE0", // а
            "\xE5", // е
            "\xED", // н
            "\xF2", // т
            "\xF0", // р
            "\xE8", // и
            "\xE2", // в
            "\xEB", // л
            "\xE4", // д
        ];
        
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if (in_array($char, $commonLetters)) {
                $score += 2;
            }
            
            // Русские буквы в диапазоне Windows-1251
            if (ord($char) >= 0xC0 && ord($char) <= 0xFF) {
                $score++;
            }
            
            // Частые биграммы
            if ($i < $length - 1) {
                $bigram = $text[$i] . $text[$i + 1];
                $commonBigrams = ["\xEE\xF2", "\xE0\xED", "\xED\xE0", "\xEE\xE1"];
                if (in_array($bigram, $commonBigrams)) {
                    $score += 3;
                }
            }
        }
        
        return $score;
    }
    
    /**
     * Подсчет "счета" для KOI8-R
     */
    private static function calculateKOI8RScore($text) {
        $score = 0;
        $length = strlen($text);
        
        // Русские буквы в KOI8-R
        for ($i = 0; $i < $length; $i++) {
            $charCode = ord($text[$i]);
            
            // Строчные русские буквы в KOI8-R (0xE0-0xFF)
            if ($charCode >= 0xE0 && $charCode <= 0xFF) {
                $score++;
            }
            
            // Прописные русские буквы в KOI8-R (0xC0-0xDF)
            if ($charCode >= 0xC0 && $charCode <= 0xDF) {
                $score++;
            }
        }
        
        return $score;
    }
    
    /**
     * Подсчет "счета" для CP866
     */
    private static function calculateCP866Score($text) {
        $score = 0;
        $length = strlen($text);
        
        for ($i = 0; $i < $length; $i++) {
            $charCode = ord($text[$i]);
            
            // Русские буквы в CP866 (0x80-0xAF и 0xE0-0xF7)
            if (($charCode >= 0x80 && $charCode <= 0xAF) || 
                ($charCode >= 0xE0 && $charCode <= 0xF7)) {
                $score++;
            }
        }
        
        return $score;
    }
    
    /**
     * Исправить неверную XML декларацию
     */
    public static function fixXmlDeclaration($content, $realEncoding) {
        if (empty($content)) {
            return $content;
        }
        
        // Если файл в Windows-1251, но декларация говорит UTF-8
        if ($realEncoding === 'Windows-1251') {
            // Заменяем неверную декларацию
            $content = preg_replace(
                '/encoding=["\']UTF-8["\']/i', 
                'encoding="Windows-1251"', 
                $content
            );
        } elseif ($realEncoding === 'KOI8-R') {
            $content = preg_replace(
                '/encoding=["\']UTF-8["\']/i', 
                'encoding="KOI8-R"', 
                $content
            );
        } elseif ($realEncoding === 'CP866') {
            $content = preg_replace(
                '/encoding=["\']UTF-8["\']/i', 
                'encoding="CP866"', 
                $content
            );
        }
        
        return $content;
    }
    
    /**
     * Конвертировать текст в UTF-8 с правильным определением кодировки
     */
    public static function convertToUTF8($content) {
        if (empty($content)) {
            return $content;
        }
        
        // Определяем реальную кодировку
        $realEncoding = self::detectRealEncoding($content);
        
        // Если уже UTF-8, возвращаем как есть
        if ($realEncoding === 'UTF-8') {
            return $content;
        }
        
        // Исправляем XML декларацию если нужно
        $content = self::fixXmlDeclaration($content, $realEncoding);
        
        // Конвертируем в UTF-8
        return self::convertEncoding($content, $realEncoding, 'UTF-8');
    }
    
    /**
     * Конвертировать между кодировками
     */
    public static function convertEncoding($content, $from, $to = 'UTF-8') {
        if (empty($content) || $from === $to) {
            return $content;
        }
        
        // Пробуем iconv
        if (function_exists('iconv')) {
            $converted = @iconv($from, $to . '//IGNORE', $content);
            if ($converted !== false) {
                return $converted;
            }
        }
        
        // Пробуем mb_convert_encoding
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($content, $to, $from);
        }
        
        // Простая таблица для Windows-1251 -> UTF-8
        if ($from === 'Windows-1251' && $to === 'UTF-8') {
            return self::convertWindows1251ToUTF8($content);
        }
        
        // Если ничего не сработало, возвращаем исходный текст
        return $content;
    }
    
    /**
     * Простая конвертация Windows-1251 в UTF-8
     */
    private static function convertWindows1251ToUTF8($text) {
        $table = [
            "\xC0" => "А", "\xC1" => "Б", "\xC2" => "В", "\xC3" => "Г",
            "\xC4" => "Д", "\xC5" => "Е", "\xC6" => "Ж", "\xC7" => "З",
            "\xC8" => "И", "\xC9" => "Й", "\xCA" => "К", "\xCB" => "Л",
            "\xCC" => "М", "\xCD" => "Н", "\xCE" => "О", "\xCF" => "П",
            "\xD0" => "Р", "\xD1" => "С", "\xD2" => "Т", "\xD3" => "У",
            "\xD4" => "Ф", "\xD5" => "Х", "\xD6" => "Ц", "\xD7" => "Ч",
            "\xD8" => "Ш", "\xD9" => "Щ", "\xDA" => "Ъ", "\xDB" => "Ы",
            "\xDC" => "Ь", "\xDD" => "Э", "\xDE" => "Ю", "\xDF" => "Я",
            "\xE0" => "а", "\xE1" => "б", "\xE2" => "в", "\xE3" => "г",
            "\xE4" => "д", "\xE5" => "е", "\xE6" => "ж", "\xE7" => "з",
            "\xE8" => "и", "\xE9" => "й", "\xEA" => "к", "\xEB" => "л",
            "\xEC" => "м", "\xED" => "н", "\xEE" => "о", "\xEF" => "п",
            "\xF0" => "р", "\xF1" => "с", "\xF2" => "т", "\xF3" => "у",
            "\xF4" => "ф", "\xF5" => "х", "\xF6" => "ц", "\xF7" => "ч",
            "\xF8" => "ш", "\xF9" => "щ", "\xFA" => "ъ", "\xFB" => "ы",
            "\xFC" => "ь", "\xFD" => "э", "\xFE" => "ю", "\xFF" => "я",
            "\xB8" => "ё", "\xA8" => "Ё"
        ];
        
        return strtr($text, $table);
    }
    
    /**
     * Извлечь описание из FB2 с правильной обработкой кодировки
     */
    public static function extractDescriptionFromFB2($content) {
        if (empty($content)) {
            return '';
        }
        
        // Конвертируем в UTF-8 с правильным определением кодировки
        $utf8Content = self::convertToUTF8($content);
        
        // Пробуем разные паттерны для извлечения описания
        $patterns = [
            // Полный паттерн с annotation внутри title-info
            '/<description>.*?<title-info>.*?<annotation>(.*?)<\/annotation>.*?<\/title-info>.*?<\/description>/is',
            // Просто annotation
            '/<annotation>(.*?)<\/annotation>/is',
            // P внутри annotation
            '/<annotation>.*?<p>(.*?)<\/p>.*?<\/annotation>/is',
            // Любой текст внутри annotation
            '/<annotation>\s*(.*?)\s*<\/annotation>/is',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $utf8Content, $matches)) {
                $description = trim($matches[1]);
                
                // Удаляем HTML теги, но сохраняем переносы строк
                $description = strip_tags($description);
                $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $description = preg_replace('/\s+/', ' ', $description);
                $description = self::cleanText($description);
                
                if (!empty($description) && mb_strlen($description) > 10) {
                    return $description;
                }
            }
        }
        
        return '';
    }
    
    /**
     * Очистка текста от мусора
     */
    private static function cleanText($text) {
        // Убираем лишние пробелы
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Убираем управляющие символы
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Убираем повторяющиеся точки
        $text = preg_replace('/\.{3,}/', '...', $text);
        
        // Обрезаем слишком длинный текст
        if (mb_strlen($text) > 5000) {
            $text = mb_substr($text, 0, 5000) . '...';
        }
        
        return trim($text);
    }
}
?>