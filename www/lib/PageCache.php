<?php

// lib/PageCache.php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/Cache.php';
require_once __DIR__.'/../init.php';

class PageCache
{
    private static $enabled;
    private static $currentKey;

    /**
     * Инициализация кэширования страниц.
     */
    public static function init()
    {
        if (null !== self::$enabled) {
            return;
        }

        self::$enabled = Config::PERFORMANCE['enable_page_cache'] && Config::ENABLE_CACHE;

        if (self::$enabled && !headers_sent()) {
            ob_start();
        }

        if (!self::$enabled && Config::isDevelopment()) {
            error_log(__('page_cache_disabled'));
        }
    }

    /**
     * Начать кэширование страницы.
     */
    public static function start($key = null)
    {
        if (!self::$enabled) {
            return false;
        }

        if (null === $key) {
            $key = self::generateKey();
        }

        self::$currentKey = $key;

        // Проверяем AJAX запросы - для них не используем кэш
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && 'xmlhttprequest' == strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            return false;
        }

        // Проверяем наличие параметров, влияющих на персонализацию
        if (isset($_GET['page']) || isset($_GET['q']) || isset($_GET['field'])) {
            $cached = Cache::get($key, Cache::TYPE_PAGE);
            if (null !== $cached) {
                if (Config::isDevelopment()) {
                    header('X-Page-Cache: HIT');
                }
                echo $cached;
                ob_end_flush();
                exit;
            }
        } else {
            $cached = Cache::get($key, Cache::TYPE_PAGE);
            if (null !== $cached) {
                if (Config::isDevelopment()) {
                    header('X-Page-Cache: HIT');
                }
                echo $cached;
                ob_end_flush();
                exit;
            }
        }

        if (Config::isDevelopment()) {
            header('X-Page-Cache: MISS');
        }

        return true;
    }

    /**
     * Сохранить страницу в кэш.
     */
    public static function save()
    {
        if (!self::$enabled || null === self::$currentKey) {
            return false;
        }

        $content = ob_get_contents();
        if ($content) {
            if (0 === strpos(self::$currentKey, 'favorites_')) {
                Cache::set(self::$currentKey, $content, Cache::TYPE_FAVORITES);
            } else {
                Cache::set(self::$currentKey, $content, Cache::TYPE_PAGE);
            }

            if (Config::isDevelopment()) {
                error_log(sprintf(__('page_cache_saved'), self::$currentKey));
            }
        }

        self::$currentKey = null;

        return true;
    }

    /**
     * Инвалидировать кэш для всех страниц пользователя.
     */
    public static function invalidateUserPages($userIp)
    {
        Cache::invalidateByType(Cache::TYPE_FAVORITES);
        Cache::invalidateByType('top_rated');
        Cache::invalidateByType(Cache::TYPE_PAGE);

        if (Config::isDevelopment()) {
            error_log(sprintf(__('page_cache_invalidated_user'), $userIp));
        }

        return true;
    }

    /**
     * Сгенерировать ключ для кэша на основе URL и параметров.
     */
    private static function generateKey()
    {
        $key = $_SERVER['REQUEST_URI'];

        // Добавляем язык в ключ кэша
        $currentLang = LanguageDetector::getInstance()->getCurrentLanguage();
        $key .= '_lang_'.$currentLang;

        // Добавляем параметры GET
        if (!empty($_GET)) {
            ksort($_GET);
            $key .= '?'.http_build_query($_GET);
        }

        return 'page_'.md5($key);
    }

    /**
     * Очистить кэш страниц.
     */
    public static function clear()
    {
        $deleted = Cache::invalidateByType(Cache::TYPE_PAGE);
        $deleted += Cache::invalidateByType(Cache::TYPE_FAVORITES);

        // Очищаем файловый кэш если используется
        $cacheDir = Config::getCacheDir().'/page_cache';
        if (file_exists($cacheDir)) {
            $files = glob($cacheDir.'/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                    ++$deleted;
                }
            }
        }

        if (Config::isDevelopment()) {
            error_log(sprintf(__('page_cache_cleared'), $deleted));
        }

        return $deleted;
    }

    /**
     * Инвалидировать кэш для определенной страницы.
     */
    public static function invalidate($key)
    {
        return Cache::delete($key);
    }

    /**
     * Очистить кэш для конкретного языка.
     */
    public static function clearLanguageCache($lang)
    {
        if (!function_exists('apcu_cache_info')) {
            return 0;
        }

        $info = apcu_cache_info(true);
        if (!isset($info['cache_list'])) {
            return 0;
        }

        $deleted = 0;
        foreach ($info['cache_list'] as $entry) {
            if (false !== strpos($entry['key'], '_lang_'.$lang)) {
                if (apcu_delete($entry['key'])) {
                    ++$deleted;
                }
            }
        }

        if (Config::isDevelopment() && $deleted > 0) {
            error_log(sprintf(__('page_cache_lang_cleared'), $lang, $deleted));
        }

        return $deleted;
    }

    /**
     * Получить статус кэширования страниц.
     */
    public static function getStatus()
    {
        return [
            'enabled' => self::$enabled,
            'current_key' => self::$currentKey,
            'stats' => Cache::getStats(),
        ];
    }
}

// Автоматическая инициализация
PageCache::init();
