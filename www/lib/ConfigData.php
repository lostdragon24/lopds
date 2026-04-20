<?php

class ConfigData
{
    // Настройки базы данных
    public const DB_TYPE = 'mysql';
    public const DB_HOST = 'localhost';
    public const SITE_TITLE = 'Моя домашняя библиотека';
    public const ITEMS_PER_PAGE = 10;
    public const OPDS_TITLE = 'Моя библиотека';
    public const OPDS_AUTHOR = 'Book Lib';
    public const OPDS_ID = 'urn:uuid:your-uuid-here';

    // Настройки кэширования
    public const ENABLE_CACHE = true;
    public const CACHE_TTL = 36000;
    public const USE_APCU = true;
    public const APCU_TTL = 1800;
    public const USE_MEMCACHED = true;
    public const MEMCACHED_HOST = 'localhost';
    public const MEMCACHED_PORT = 11211;
    public const MEMCACHED_TTL = 7200;

    // Уровни кэширования
    public const CACHE_LEVEL_APCU = 'apcu';
    public const CACHE_LEVEL_MEMCACHED = 'memcached';
    public const CACHE_LEVEL_FILE = 'file';

    public const SEARCH_OPTIMIZATION = [
        'enable_fulltext' => true,
        'min_word_length' => 3,
        'use_boolean_mode' => true,
        'cache_search_results' => true,
        'search_cache_ttl' => 300,
        'partial_search_fallback' => true,
    ];

    // Настройки для разных типов данных
    public const CACHE_CONFIG = [
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
    public const COVER_PROCESSING = [
        'max_width' => 800,
        'max_height' => 1200,
        'quality' => 85,
        'max_processing_time' => 10,
        'skip_large_archives' => true,
        'max_archive_size' => 50 * 1024 * 1024,
        'enable_file_cache' => true,
        'cache_ttl' => 86400,
        'enable_apcu_cache' => true,
        'apcu_ttl' => 3600,
    ];

    // Оптимизации производительности
    public const PERFORMANCE = [
        'max_search_results' => 500,
        'enable_query_logging' => false,
        'batch_processing' => true,
        'optimize_images' => true,
        'memory_limit' => '512M',
        'enable_page_cache' => true,
        'page_cache_ttl' => 300,
        'enable_db_cache' => true,
        'db_cache_ttl' => 900,
    ];

    // Настройки пагинации
    public const PAGINATION = [
        'max_pages' => 100,
        'default_per_page' => 20,
        'large_results_threshold' => 500,
    ];

    // Безопасные значения для сортировки
    public const ALLOWED_ORDER_BY = ['added_date', 'title', 'author', 'year', 'id'];
    public const ALLOWED_ORDER_DIR = ['ASC', 'DESC'];
}
