<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Cache.php';

class PageCache {
    private static $enabled = null;
    private static $currentKey = null;
    
    /**
     * Инициализация кэширования страниц
     */
    public static function init() {
        if (self::$enabled !== null) {
            return;
        }
        
        self::$enabled = Config::PERFORMANCE['enable_page_cache'] && Config::ENABLE_CACHE;
        
        // Начинаем буферизацию вывода если включено кэширование
        if (self::$enabled && !headers_sent()) {
            ob_start();
        }
    }
    
    /**
     * Начать кэширование страницы
     */
    public static function start($key = null) {
        if (!self::$enabled) {
            return false;
        }
        
        if ($key === null) {
            $key = self::generateKey();
        }
        
        self::$currentKey = $key;
        
        // Пробуем получить кэшированную версию
        $cached = Cache::get($key, 'page_cache');
        if ($cached !== null) {
            echo $cached;
            ob_end_flush();
            exit;
        }
        
        return true;
    }
    
    /**
     * Сохранить страницу в кэш
     */
    public static function save() {
        if (!self::$enabled || self::$currentKey === null) {
            return false;
        }
        
        $content = ob_get_contents();
        if ($content) {
            Cache::set(self::$currentKey, $content, 'page_cache');
        }
        
        self::$currentKey = null;
        return true;
    }
    
    /**
     * Сгенерировать ключ для кэша на основе URL и параметров
     */
    private static function generateKey() {
        $key = $_SERVER['REQUEST_URI'];
        
        // Добавляем параметры GET
        if (!empty($_GET)) {
            ksort($_GET); // Сортируем для консистентности
            $key .= '?' . http_build_query($_GET);
        }
        
        // Добавляем язык если используется
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $key .= '_' . substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        }
        
        return 'page_' . md5($key);
    }
    
    /**
     * Очистить кэш страниц
     */
    public static function clear() {
        // Реализация очистки всех кэшированных страниц
        // Может потребоваться специальная логика для поиска ключей страниц
        return Cache::clear();
    }
    
    /**
     * Инвалидировать кэш для определенной страницы
     */
    public static function invalidate($key) {
        return Cache::delete($key);
    }
    
    /**
     * Получить статистику кэширования страниц
     */
    public static function getStats() {
        $stats = Cache::getStats();
        if (isset($stats['apcu'])) {
            // Можно добавить специфичную статистику для страниц
            $stats['page_cache'] = [
                'enabled' => self::$enabled,
                'current_key' => self::$currentKey
            ];
        }
        return $stats;
    }
}

// Автоматическая инициализация
PageCache::init();
?>