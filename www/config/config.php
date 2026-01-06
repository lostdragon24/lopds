<?php

class Config {
    // Настройки базы данных
    const DB_TYPE =  'mysql'; // или 'mysql', 'sqlite'
    const DB_PATH = '/path/to/db/library.db';
    const DB_HOST = 'localhost';
    const DB_USER = 'userDB';
    const DB_PASS = 'passwordDB';
    const DB_NAME = 'mybook';
    
    // Настройки сканера
    const BOOKS_DIR = '/path/to/book/';
    const SCANNER_PATH = __DIR__ . '/scanner/path/';
    const SCANNER_CONFIG = __DIR__ . '/config.ini';
    
    // Настройки веб-интерфейса
    const SITE_TITLE = 'Моя домашняя библиотека';
    const ITEMS_PER_PAGE = 10;
    const CACHE_DIR = './cache';
    const COVER_CACHE_DIR = './covers';
    
    // Настройки OPDS
    const OPDS_TITLE = 'Моя библиотека';
    const OPDS_AUTHOR = 'Book Lib';
    const OPDS_ID = 'urn:uuid:your-uuid-here';
    
    // === ВКЛЮЧАЕМ КЭШИРОВАНИЕ ===
    const ENABLE_CACHE = true; // ВКЛЮЧАЕМ КЭШИРОВАНИЕ
    const CACHE_TTL = 36000;
    
    // Использование APCu для опкода и данных
    const USE_APCU = true; // ВКЛЮЧАЕМ APCu
    const APCU_TTL = 1800;
    
    // Использование Memcached для распределенного кэша
    const USE_MEMCACHED = false; // Отключаем для Raspberry Pi
    const MEMCACHED_HOST = 'localhost';
    const MEMCACHED_PORT = 11211;
    const MEMCACHED_TTL = 7200;
    
    // Уровни кэширования
    const CACHE_LEVEL_APCU = 'apcu';
    const CACHE_LEVEL_MEMCACHED = 'memcached';
    const CACHE_LEVEL_FILE = 'file';

const SEARCH_OPTIMIZATION = [
    'enable_fulltext' => true,
    'min_word_length' => 3, // Минимальная длина слова для FULLTEXT
    'use_boolean_mode' => true, // Использовать BOOLEAN MODE для поиска
    'cache_search_results' => true,
    'search_cache_ttl' => 300, // 5 минут
    'partial_search_fallback' => true // Если FULLTEXT не нашел, использовать LIKE
];
    
    // Настройки для разных типов данных
    const CACHE_CONFIG = [
        'search_results' => ['level' => self::CACHE_LEVEL_APCU, 'ttl' => 900],
        'book_data' => ['level' => self::CACHE_LEVEL_APCU, 'ttl' => 3600],
        'statistics' => ['level' => self::CACHE_LEVEL_APCU, 'ttl' => 1800],
        'opds_feeds' => ['level' => self::CACHE_LEVEL_APCU, 'ttl' => 300],
        'author_list' => ['level' => self::CACHE_LEVEL_APCU, 'ttl' => 7200],
        'genre_list' => ['level' => self::CACHE_LEVEL_APCU, 'ttl' => 7200],
        'series_list' => ['level' => self::CACHE_LEVEL_APCU, 'ttl' => 7200],
        'page_cache' => ['level' => self::CACHE_LEVEL_APCU, 'ttl' => 300],
    ];
    
    // Ограничения для обработки обложек
    const COVER_PROCESSING = [
        'max_width' => 800,
        'max_height' => 1200, 
        'quality' => 85,
        'max_processing_time' => 10, // секунд
        'skip_large_archives' => true,
        'max_archive_size' => 50 * 1024 * 1024, // 50MB
        'enable_file_cache' => true, // Файловый кэш для обложек
        'cache_ttl' => 86400, // 24 часа
        'enable_apcu_cache' => true, // Кэширование в памяти
        'apcu_ttl' => 3600
    ];
    
    // Оптимизации производительности для Raspberry Pi
    const PERFORMANCE = [
        'max_search_results' => 500, // Уменьшаем для Raspberry Pi
        'enable_query_logging' => false, // Логирование запросов (только для отладки)
        'batch_processing' => true, // Пакетная обработка
        'optimize_images' => true, // Оптимизация изображений
        'memory_limit' => '512M', // Лимит памяти для PHP
        'enable_page_cache' => true, // Кэширование страниц
        'page_cache_ttl' => 300, // 5 минут
        'enable_db_cache' => true, // Кэширование запросов к БД
        'db_cache_ttl' => 900, // 15 минут
    ];
    
    // Настройки пагинации
    const PAGINATION = [
        'max_pages' => 100, // Максимальное количество страниц
        'default_per_page' => 20,
        'large_results_threshold' => 500, // Уменьшаем порог
    ];

    public static function init() {
        // Создаем необходимые директории
        if (!file_exists(self::CACHE_DIR)) {
            mkdir(self::CACHE_DIR, 0755, true);
        }
        if (!file_exists(self::COVER_CACHE_DIR)) {
            mkdir(self::COVER_CACHE_DIR, 0755, true);
        }
        
        // Устанавливаем лимит памяти
        if (self::PERFORMANCE['memory_limit']) {
            ini_set('memory_limit', self::PERFORMANCE['memory_limit']);
        }
        
        // Настройки для оптимизации производительности
        if (self::PERFORMANCE['optimize_images']) {
            ini_set('gd.jpeg_ignore_warning', 1);
        }
        
        // Включаем буферизацию вывода
        if (self::PERFORMANCE['enable_page_cache']) {
            ob_start();
        }
        
        // Создаем конфиг для сканера если его нет
        if (!file_exists(self::SCANNER_CONFIG)) {
            self::createScannerConfig();
        }
        
        // Инициализируем APCu если включено
        if (self::ENABLE_CACHE && self::USE_APCU && extension_loaded('apcu')) {
            if (!apcu_enabled()) {
                error_log("APCu is installed but not enabled. Check php.ini settings.");
            }
        }
    }
    
    private static function createScannerConfig() {
        $config_content = "[database]\n";
        $config_content .= "type = " . self::DB_TYPE . "\n";
        
        if (self::DB_TYPE === 'sqlite') {
            $config_content .= "path = " . self::DB_PATH . "\n";
        } else {
            $config_content .= "host = " . self::DB_HOST . "\n";
            $config_content .= "user = " . self::DB_USER . "\n";
            $config_content .= "password = " . self::DB_PASS . "\n";
            $config_content .= "database = " . self::DB_NAME . "\n";
        }
        
        $config_content .= "\n[scanner]\n";
        $config_content .= "books_dir = " . self::BOOKS_DIR . "\n";
        $config_content .= "log_file = NULL\n";
        
        file_put_contents(self::SCANNER_CONFIG, $config_content);
    }

    // Маппинг жанров FB2 (остается без изменений)
    const FB2_GENRES = [
        // Фантастика
        'sf_history' => 'Альтернативная история',
        'sf_action' => 'Боевая фантастика',
        'sf_epic' => 'Эпическая фантастика',
        'sf_heroic' => 'Героическая фантастика',
        'sf_detective' => 'Детективная фантастика',
        'sf_cyberpunk' => 'Киберпанк',
        'sf_space' => 'Космическая фантастика',
        'sf_social' => 'Социально-психологическая фантастика',
        'sf_horror' => 'Ужасы и Мистика',
        'sf_humor' => 'Юмористическая фантастика',
        'sf_fantasy' => 'Фэнтези',
        'sf' => 'Научная Фантастика',
        
        // Детективы и Триллеры
        'det_classic' => 'Классический детектив',
        'det_police' => 'Полицейский детектив',
        'det_action' => 'Боевик',
        'det_irony' => 'Иронический детектив',
        'det_history' => 'Исторический детектив',
        'det_espionage' => 'Шпионский детектив',
        'det_crime' => 'Криминальный детектив',
        'det_political' => 'Политический детектив',
        'det_maniac' => 'Маньяки',
        'det_hard' => 'Крутой детектив',
        'thriller' => 'Триллер',
        'detective' => 'Детектив',
        
        // Проза
        'prose_classic' => 'Классическая проза',
        'prose_history' => 'Историческая проза',
        'prose_contemporary' => 'Современная проза',
        'prose_counter' => 'Контркультура',
        'prose_rus_classic' => 'Русская классическая проза',
        'prose_su_classics' => 'Советская классическая проза',
        
        // Любовные романы
        'love_contemporary' => 'Современные любовные романы',
        'love_history' => 'Исторические любовные романы',
        'love_detective' => 'Остросюжетные любовные романы',
        'love_short' => 'Короткие любовные романы',
        'love_erotica' => 'Эротика',
        
        // Приключения
        'adv_western' => 'Вестерн',
        'adv_history' => 'Исторические приключения',
        'adv_indian' => 'Приключения про индейцев',
        'adv_maritime' => 'Морские приключения',
        'adv_geo' => 'Путешествия и география',
        'adv_animal' => 'Природа и животные',
        'adventure' => 'Приключения',
        
        // Детское
        'child_tale' => 'Сказка',
        'child_verse' => 'Детские стихи',
        'child_prose' => 'Детская проза',
        'child_sf' => 'Детская фантастика',
        'child_det' => 'Детские остросюжетные',
        'child_adv' => 'Детские приключения',
        'child_education' => 'Детская образовательная литература',
        'children' => 'Детская литература',
        
        // Поэзия, Драматургия
        'poetry' => 'Поэзия',
        'dramaturgy' => 'Драматургия',
        
        // Старинное
        'antique_ant' => 'Античная литература',
        'antique_european' => 'Европейская старинная литература',
        'antique_russian' => 'Древнерусская литература',
        'antique_east' => 'Древневосточная литература',
        'antique_myths' => 'Мифы. Легенды. Эпос',
        'antique' => 'Старинная литература',
        
        // Наука, Образование
        'sci_history' => 'История',
        'sci_psychology' => 'Психология',
        'sci_culture' => 'Культурология',
        'sci_religion' => 'Религиоведение',
        'sci_philosophy' => 'Философия',
        'sci_politics' => 'Политика',
        'sci_business' => 'Деловая литература',
        'sci_juris' => 'Юриспруденция',
        'sci_linguistic' => 'Языкознание',
        'sci_medicine' => 'Медицина',
        'sci_phys' => 'Физика',
        'sci_math' => 'Математика',
        'sci_chem' => 'Химия',
        'sci_biology' => 'Биология',
        'sci_tech' => 'Технические науки',
        'science' => 'Научная литература',
        
        // Компьютеры и Интернет
        'comp_www' => 'Интернет',
        'comp_programming' => 'Программирование',
        'comp_hard' => 'Компьютерное железо',
        'comp_soft' => 'Программы',
        'comp_db' => 'Базы данных',
        'comp_osnet' => 'ОС и Сети',
        'computers' => 'Компьютерная литература',
        
        // Справочная литература
        'ref_encyc' => 'Энциклопедии',
        'ref_dict' => 'Словари',
        'ref_ref' => 'Справочники',
        'ref_guide' => 'Руководства',
        'reference' => 'Справочная литература',
        
        // Документальная литература
        'nonf_biography' => 'Биографии и Мемуары',
        'nonf_publicism' => 'Публицистика',
        'nonf_criticism' => 'Критика',
        'design' => 'Искусство и Дизайн',
        'nonfiction' => 'Документальная литература',
        
        // Религия и духовность
        'religion_rel' => 'Религия',
        'religion_esoterics' => 'Эзотерика',
        'religion_self' => 'Самосовершенствование',
        'religion' => 'Религиозная литература',
        
        // Юмор
        'humor_anecdote' => 'Анекдоты',
        'humor_prose' => 'Юмористическая проза',
        'humor_verse' => 'Юмористические стихи',
        'humor' => 'Юмор',
        
        // Домоводство
        'home_cooking' => 'Кулинария',
        'home_pets' => 'Домашние животные',
        'home_crafts' => 'Хобби и ремесла',
        'home_entertain' => 'Развлечения',
        'home_health' => 'Здоровье',
        'home_garden' => 'Сад и огород',
        'home_diy' => 'Сделай сам',
        'home_sport' => 'Спорт',
        'home_sex' => 'Эротика, Секс',
        'home' => 'Домоводство'
    ];

}

Config::init();
?>
