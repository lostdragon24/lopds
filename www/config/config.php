<?php

class Config {
    // Настройки базы данных
    const DB_TYPE =  'mysql'; // или 'mysql', 'sqlite'
    const DB_PATH = '/path/to/db/library.db';
    const DB_HOST = 'localhost';
    const DB_USER = 'USER_DB';
    const DB_PASS = 'PASSWORD_DB';
    const DB_NAME = 'mybook';
    
    // Настройки сканера
    const BOOKS_DIR = '/path/to/book/';
    const SCANNER_PATH = __DIR__ . '/scanner/path/';
    const SCANNER_CONFIG = __DIR__ . '/config.ini';
    
    // Настройки веб-интерфейса
    const SITE_TITLE = 'Моя домашняя библиотека';
    const ITEMS_PER_PAGE = 10;
    const CACHE_DIR = '/path/to/www/dir/cache';
    const COVER_CACHE_DIR = '/path/to/www/dir/cache/covers';
    
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
    const USE_MEMCACHED = true; // Отключаем для Raspberry Pi
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


public static function getMimeType($fileType) {
    $mimeTypes = [
        'fb2' => 'application/x-fictionbook+xml',
        'epub' => 'application/epub+zip',
        'pdf' => 'application/pdf',
        'mobi' => 'application/x-mobipocket-ebook',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed'
    ];
    
    return $mimeTypes[strtolower($fileType)] ?? 'application/octet-stream';
}



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
    // Основные жанры FB2
    'adv_animal' => 'Природа и животные',
    'adv_geo' => 'Путешествия и география',
    'adv_history' => 'История',
    'adv_indian' => 'Вестерн',
    'adv_maritime' => 'Море',
    'adv_modern' => 'Приключения в современном мире',
    'adv_story' => 'Авантюрный роман',
    'adv_western' => 'Вестерн',
    'adventure' => 'Приключения',
    'child_adv' => 'Приключения для детей и подростков',
    'tale_chivalry' => 'Рыцарский роман',
    'antique' => 'Старинное',
    'antique_ant' => 'Античность',
    'antique_east' => 'Древневосточная литература',
    'antique_european' => 'Старая европейская литература',
    'antique_russian' => 'Древнерусская литература',
    'architecture_book' => 'Архитектура',
    'art_criticism' => 'Искусствоведение',
    'art_world_culture' => 'Мировая художественная культура',
    'cine' => 'Кино',
    'cinema_theatre' => ' theatre',
    'design' => 'Дизайн',
    'music' => 'Музыка',
    'music_dancing' => ' dancing',
    'nonf_criticism' => 'Критика',
    'notes' => 'Партитуры',
    'painting' => 'Живопись',
    'sci_culture' => 'Культура',
    'theatre' => 'Театр',
    'visual_arts' => 'Живопись',
    'child_classical' => 'Классическая детская литература',
    'child_det' => 'Детектив',
    'child_education' => 'Образовательная литература',
    'child_prose' => 'Детская проза',
    'child_sf' => 'Детская научная фантастика',
    'child_tale' => 'Сказки',
    'child_tale_rus' => 'Русские сказки',
    'child_verse' => 'Стихи',
    'children' => 'Детская литература',
    'foreign_children' => 'Детские книги',
    'prose_game' => 'Игры',
    'comp_db' => 'Базы данных',
    'comp_hard' => 'Компьютеры',
    'comp_osnet' => 'Операционные системы',
    'comp_programming' => 'Программирование',
    'comp_soft' => 'Программы',
    'comp_www' => 'Интернет',
    'computers' => 'Компьютеры',
    'tbg_computers' => 'Учебные пособия',
    'det_action' => 'Боевик',
    'det_classic' => 'Классический',
    'det_crime' => 'Криминал',
    'det_espionage' => 'Шпионаж',
    'det_hard' => 'Крутой',
    'det_history' => 'Исторический',
    'det_irony' => 'Иронический',
    'det_maniac' => 'Про маньяков',
    'det_police' => 'Полицейский',
    'det_political' => 'Политический',
    'det_su' => 'Советский детектив',
    'detective' => 'Детектив',
    'thriller' => 'Триллер',
    'comedy' => 'Комедия',
    'drama' => 'Драма',
    'drama_antique' => 'Античная драма',
    'dramaturgy' => 'Драматургия',
    'foreign_dramaturgy' => 'Драматургия',
    'screenplays' => 'Сценарий',
    'tragedy' => 'Трагедия',
    'vaudeville' => 'Мистерия',
    'accounting' => 'Экономика',
    'banking' => 'Банкинг',
    'economics' => 'Экономика',
    'economics_ref' => 'Деловая литература',
    'global_economy' => 'Глобальная экономика',
    'marketing' => 'Маркетинг',
    'org_behavior' => 'Организация',
    'personal_finance' => 'Личные финансы',
    'popular_business' => 'Бизнес',
    'real_estate' => 'Недвижимость',
    'small_business' => 'Малый бизнес',
    'stock' => 'Биржа',
    'auto_business' => 'Автодело',
    'equ_history' => 'История техники',
    'military_weapon' => 'Военная техника и вооружение',
    'sci_build' => 'Строительство и сопромат',
    'sci_metal' => 'Металлургия',
    'sci_radio' => 'Радиоэлектроника',
    'sci_tech' => 'Техника',
    'sci_transport' => 'Транспорт и авиация',
    'city_fantasy' => 'Городское фэнтези',
    'dragon_fantasy' => 'Драконы',
    'fairy_fantasy' => 'Мифологическое фэнтези',
    'fantasy_fight' => 'Битвы',
    'historical_fantasy' => 'Историческое фэнтези',
    'modern_tale' => 'Современная сказка',
    'russian_fantasy' => 'Русское фэнтези',
    'sf_fantasy' => 'Фэнтези',
    'sf_fantasy_city' => 'Городское фэнтези',
    'sf_mystic' => 'Мистика',
    'sf_stimpank' => 'Стимпанк',
    'sf_technofantasy' => 'Технофэнтези',
    'antique_myths' => 'Мифы',
    'child_folklore' => 'Детский фольклор',
    'epic' => 'Былины',
    'folk_songs' => 'Народные песни',
    'folk_tale' => 'Народные сказки',
    'folklore' => 'Фольклор',
    'limerick' => 'Частушки',
    'proverbs' => 'Пословицы',
    'foreign_action' => 'Боевик',
    'foreign_adventure' => 'Приключения',
    'foreign_business' => 'Бизнес',
    'foreign_comp' => 'Компьютеры',
    'foreign_contemporary' => 'Современное',
    'foreign_contemporary_lit' => 'Современная литература',
    'foreign_desc' => 'Описания',
    'foreign_detective' => 'Детектив',
    'foreign_edu' => 'Образование',
    'foreign_fantasy' => 'Фэнтези',
    'foreign_home' => 'Дом',
    'foreign_humor' => 'Юмор',
    'foreign_language' => 'Языкознание',
    'foreign_love' => 'Любовное',
    'foreign_novel' => 'Новеллы',
    'foreign_other' => 'Другое',
    'foreign_psychology' => 'Психология',
    'foreign_publicism' => 'Публицистика',
    'foreign_sf' => 'Научная фантастика',
    'geo_guides' => 'Справочники',
    'geography_book' => 'География',
    'family' => 'Семейные отношения',
    'home' => 'Дом',
    'home_collecting' => 'Коллекционирование',
    'home_cooking' => 'Кулинария',
    'home_crafts' => 'Увлечения',
    'home_diy' => 'Сделай сам',
    'home_entertain' => 'Развлечения',
    'home_garden' => 'Сад',
    'home_health' => 'Здоровье',
    'home_pets' => 'Домашние животные',
    'home_sex' => 'Секс',
    'home_sport' => 'Спорт',
    'sci_pedagogy' => 'Педагогика',
    'humor' => 'Юмор',
    'humor_anecdote' => 'Анекдоты',
    'humor_fantasy' => 'Фэнтези',
    'humor_prose' => 'Юмористическая проза',
    'humor_satire' => 'Сатира',
    'love' => 'Любовные романы',
    'love_contemporary' => 'Современные любовные романы',
    'love_detective' => 'Остросюжетные любовные романы',
    'love_erotica' => 'Эротика',
    'love_fantasy' => 'Любовное фэнтези',
    'love_hard' => 'Порно',
    'love_history' => 'Любовные исторические романы',
    'love_sf' => 'Любовно-фантастические романы',
    'love_short' => 'Короткое',
    'military_special' => 'Военное дело',
    'nonf_biography' => 'Биографии и Мемуары',
    'nonf_military' => 'Военная документалистика и аналитика',
    'nonf_publicism' => 'Публицистика',
    'nonfiction' => 'Художественная литература',
    'travel_notes' => 'Путевые заметки',
    'aphorism_quote' => 'Афоризмы',
    'auto_regulations' => 'Автомобили',
    'beginning_authors' => 'Начинающие авторы',
    'comics' => 'Комиксы',
    'essays' => 'Эссе',
    'fanfiction' => 'Фанфик',
    'industries' => 'Промышленность',
    'job_hunting' => 'Поиск работы',
    'magician_book' => 'Магия',
    'management' => 'Менеджмент',
    'narrative' => 'Повествовательное',
    'network_literature' => 'Самиздат',
    'newspapers' => 'Газеты',
    'other' => 'Неотсортированное',
    'paper_work' => 'Бумажная работа',
    'pedagogy_book' => 'Педагогика',
    'periodic' => 'Периодические издания',
    'russian_contemporary' => 'Современная российская литература',
    'short_story' => 'Короткие истории',
    'sketch' => 'Скетч',
    'unfinished' => 'Незавершенное',
    'unrecognised' => 'Неизвестный',
    'upbringing_book' => 'Воспитание',
    'vampire_book' => 'Вампиры',
    'foreign_poetry' => 'Поэзия',
    'humor_verse' => 'Стихи',
    'lyrics' => 'Лирика',
    'palindromes' => 'Визуальная и экспериментальная поэзия',
    'poem' => 'Поэма',
    'poetry' => 'Поэзия',
    'poetry_classical' => 'Классическая поэзия',
    'poetry_east' => 'Поэзия Востока',
    'poetry_for_classical' => 'Классическая зарубежная поэзия',
    'poetry_for_modern' => 'Современная зарубежная поэзия',
    'poetry_modern' => 'Современная поэзия',
    'poetry_rus_classical' => 'Классическая русская поэзия',
    'poetry_rus_modern' => 'Современная русская поэзия',
    'song_poetry' => 'Песенная поэзия',
    'aphorisms' => 'Афоризмы',
    'epistolary_fiction' => 'Эпистолярная проза',
    'foreign_antique' => 'Средневековая классическая проза',
    'foreign_prose' => 'Зарубежная классическая проза',
    'gothic_novel' => 'Готический роман',
    'great_story' => 'Роман',
    'literature_18' => 'Классическая проза XVII-XVIII веков',
    'literature_19' => 'Классическая проза ХIX века',
    'literature_20' => 'Классическая проза ХX века',
    'prose' => 'Проза',
    'prose_abs' => 'Фантасмагория',
    'prose_classic' => 'Классика',
    'prose_contemporary' => 'Современная проза',
    'prose_counter' => 'Контр-проза',
    'prose_history' => 'История',
    'prose_magic' => 'Магический реализм',
    'prose_military' => 'Военная проза',
    'prose_neformatny' => 'Экспериментальная',
    'prose_rus_classic' => 'Русская классика',
    'prose_su_classics' => 'Советская классика',
    'story' => 'Малые литературные формы',
    'psy_alassic' => 'Психология',
    'psy_childs' => 'Дети',
    'psy_generic' => 'Общее',
    'psy_personal' => 'Личное',
    'psy_sex_and_family' => 'Секс и семья',
    'psy_social' => 'Социальное',
    'psy_theraphy' => 'Терапия',
    'ref_dict' => 'Словари',
    'ref_encyc' => 'Энциклопедии',
    'ref_guide' => 'Инструкции',
    'ref_ref' => 'Справочники',
    'reference' => 'Справочники',
    'astrology' => 'Астрология и хиромантия',
    'foreign_religion' => 'Иностранная религиозная литература',
    'religion' => 'Религия',
    'religion_budda' => 'Буддизм',
    'religion_catholicism' => 'Католицизм',
    'religion_christianity' => 'Христианство',
    'religion_esoterics' => 'Эзотерика',
    'religion_hinduism' => 'Индуизм',
    'religion_islam' => 'Islam',
    'religion_judaism' => 'Иудаизм',
    'religion_orthodoxy' => 'Православие',
    'religion_paganism' => 'Язычество',
    'religion_protestantism' => 'Протестантизм',
    'religion_rel' => 'Религия, эзотерика',
    'religion_self' => 'Самопознание',
    'military_history' => 'Военная история',
    'sci_biology' => 'Биология',
    'sci_botany' => 'Ботаника',
    'sci_chem' => 'Химия',
    'sci_cosmos' => 'Астрономия и Космос',
    'sci_ecology' => 'Экология',
    'sci_economy' => 'Экономика',
    'sci_geo' => 'Геология и география',
    'sci_history' => 'История',
    'sci_juris' => 'Юриспруденция',
    'sci_linguistic' => 'Лингвистика',
    'sci_math' => 'Математика',
    'sci_medicine' => 'Медицина',
    'sci_medicine_alternative' => 'Альтернативная (не)медицина',
    'sci_oriental' => 'Востоковедение',
    'sci_philology' => 'Литературоведение',
    'sci_philosophy' => 'Философия',
    'sci_phys' => 'Физика',
    'sci_politics' => 'Политика',
    'sci_popular' => 'Научно-популярная литература',
    'sci_psychology' => 'Психология и психотерапия',
    'sci_religion' => 'Религия',
    'sci_social_studies' => 'Обществознание',
    'sci_state' => 'Государство и право',
    'sci_theories' => 'Альтернативные (не)науки и (не)научные теории',
    'sci_veterinary' => 'Ветеринария',
    'sci_zoo' => 'Зоология',
    'science' => 'Наука',
    'sociology_book' => 'Социология',
    'hronoopera' => 'Хроноопера',
    'popadancy' => 'Уе… попаданцы(1)',
    'popadanec' => 'Уе… попаданцы(2)',
    'sf' => 'Научная фантастика',
    'sf_action' => 'Боевая фантастика',
    'sf_cyberpunk' => 'Киберпанк',
    'sf_detective' => 'Детектив',
    'sf_epic' => 'Эпическая фантастика',
    'sf_etc' => 'Фантастика',
    'sf_heroic' => 'Героическое',
    'sf_history' => 'История',
    'sf_horror' => 'Ужас',
    'sf_humor' => 'Юмор',
    'sf_litrpg' => 'ЛитРПГ',
    'sf_postapocalyptic' => 'Постапокалипсис',
    'sf_social' => 'Социально-психологическая фантастика',
    'sf_space' => 'Космос',
    'sci_textbook' => 'Учебники и пособия',
    'tbg_higher' => 'Учебники и пособия ВУЗов',
    'tbg_school' => 'Школьные учебники и пособия',
    'tbg_secondary' => 'Учебники и пособия для среднего и специального образования',

    'vers_libre' => 'Верлибры',
    'trade' => 'Торговля',
    'sci_crib' => 'Шпаргалки',
    'sci_biophys' => 'Биофизика',
    'sci_biochem' => 'Биохимия',
    'scenarios' => 'Сценарии',
    'roman' => 'Романы',
    'riddles' => 'Фольклор',
    'military_arts' => 'Боевые исскуства',
    'military_all' => 'Тактика и стратеги',
    'military' => 'Организация и тактика боевых действий',
    'Islam' => 'Ислам религия',
    'islam' => 'Ислам религия',
    'in_verse' => 'Трагедии',
    'fantasy_alt_hist' => 'Фентези альтернативная история',
    'fable' => 'Байки',
    'Extravaganza' => 'Феерия',
    'essay' => 'Эссе',
    'dissident' => 'Диссидентская литература',
    'det_cozy' => 'Дамский роман',
    'det_all' => 'Дамский роман',
    'comp_all' => 'Компьютерная литература',
    'sagas' => 'Саги',
    'palmistry' => 'Хиромантия',
    'mystery' => 'Тайны',
    'Ref_all' => 'Всё о бо всём',
    'Sci_business' => 'О бизнесе',
    'Adv_all' => 'Детская литература',
    'Nonf_all' => 'О бо всём',

    // Русские жанры
    'rus_classic' => 'Русская классика',
    'sf_russian' => 'Русская фантастика',
    'fantasy_russian' => 'Русское фэнтези'

    ];

}

Config::init();
?>
