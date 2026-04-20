<?php

// lib/Cache.php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../init.php';

class Cache
{
    private static $apcuEnabled = false;
    private static $initialized = false;

    // Константы для типов
    public const TYPE_FAVORITES = 'favorites';
    public const TYPE_RATINGS = 'ratings';
    public const TYPE_PAGE = 'page_cache';
    public const TYPE_SEARCH = 'search_results';
    public const TYPE_STATS = 'statistics';
    public const TYPE_BOOK = 'book_data';
    public const TYPE_OPDS = 'opds_feeds';

    /**
     * Инициализация кэша.
     */
    public static function init()
    {
        if (self::$initialized) {
            return;
        }

        self::$apcuEnabled = Config::USE_APCU && extension_loaded('apcu') && apcu_enabled();

        if (!self::$apcuEnabled && Config::ENABLE_CACHE) {
            error_log(__('cache_warning_apcu_not_available'));
        }

        self::$initialized = true;
    }

    /**
     * Получить данные из кэша.
     */
    public static function get($key, $type = 'default')
    {
        if (!Config::ENABLE_CACHE || !self::$apcuEnabled) {
            return null;
        }

        $prefixedKey = $type.'::'.$key;

        $success = false;
        $value = apcu_fetch($prefixedKey, $success);

        if (!$success && Config::isDevelopment()) {
            error_log(sprintf(__('cache_miss'), $prefixedKey));
        }

        return $success ? $value : null;
    }

    /**
     * Сохранить данные в кэш.
     */
    public static function set($key, $data, $type = 'default', $ttl = null)
    {
        if (!Config::ENABLE_CACHE || !self::$apcuEnabled) {
            return false;
        }

        $prefixedKey = $type.'::'.$key;

        if (null === $ttl) {
            $config = Config::CACHE_CONFIG[$type] ?? ['ttl' => Config::CACHE_TTL];
            $ttl = $config['ttl'];
        }

        $result = apcu_store($prefixedKey, $data, $ttl);

        if ($result && Config::isDevelopment()) {
            error_log(sprintf(__('cache_store'), $prefixedKey, $ttl));
        }

        // Индексируем по типу для быстрой инвалидации
        self::addToTypeIndex($type, $prefixedKey);

        return $result;
    }

    /**
     * Добавить ключ в индекс типа.
     */
    private static function addToTypeIndex($type, $key)
    {
        if (!self::$apcuEnabled) {
            return;
        }

        $indexKey = 'type_idx_'.$type;
        $keys = apcu_fetch($indexKey) ?: [];

        if (count($keys) < 10000) {
            $keys[] = $key;
            $keys = array_unique($keys);
            apcu_store($indexKey, $keys, 86400);
        }
    }

    /**
     * Инвалидация по типу.
     */
    public static function invalidateByType($type)
    {
        if (!self::$apcuEnabled) {
            return 0;
        }

        $indexKey = 'type_idx_'.$type;
        $keys = apcu_fetch($indexKey) ?: [];

        $deleted = 0;
        foreach ($keys as $key) {
            if (apcu_delete($key)) {
                ++$deleted;
            }
        }

        apcu_delete($indexKey);

        if ($deleted > 0) {
            error_log(sprintf(__('cache_invalidated'), $deleted, $type));
        }

        return $deleted;
    }

    /**
     * Удалить данные из кэша.
     */
    public static function delete($key)
    {
        if (!self::$apcuEnabled) {
            return false;
        }

        $result = apcu_delete($key);

        if ($result && Config::isDevelopment()) {
            error_log(sprintf(__('cache_deleted'), $key));
        }

        return $result;
    }

    /**
     * Очистить весь кэш.
     */
    public static function clear()
    {
        if (!self::$apcuEnabled) {
            error_log(__('cache_clear_error'));

            return false;
        }

        apcu_clear_cache();
        error_log(__('cache_cleared'));

        return true;
    }

    /**
     * Получить статистику кэша.
     */
    public static function getStats()
    {
        $stats = [
            'enabled' => self::$apcuEnabled,
            'message' => self::$apcuEnabled ? __('cache_enabled_status') : __('cache_disabled_status'),
        ];

        if (self::$apcuEnabled) {
            $apcuStats = apcu_cache_info(true);
            $stats['apcu'] = [
                'hits' => $apcuStats['num_hits'] ?? 0,
                'misses' => $apcuStats['num_misses'] ?? 0,
                'memory_usage' => $apcuStats['mem_size'] ?? 0,
                'entries' => $apcuStats['num_entries'] ?? 0,
                'effectiveness' => ($apcuStats['num_hits'] + $apcuStats['num_misses']) > 0 ?
                    round($apcuStats['num_hits'] / ($apcuStats['num_hits'] + $apcuStats['num_misses']) * 100, 1) : 0,
            ];
        }

        return $stats;
    }

    /**
     * Проверить наличие ключа в кэше.
     */
    public static function exists($key)
    {
        if (!Config::ENABLE_CACHE || !self::$apcuEnabled) {
            return false;
        }

        return apcu_exists($key);
    }

    /**
     * Получить несколько значений за раз.
     */
    public static function getMultiple($keys)
    {
        if (!Config::ENABLE_CACHE || !self::$apcuEnabled) {
            return [];
        }

        return apcu_fetch($keys);
    }

    /**
     * Сохранить несколько значений за раз.
     */
    public static function setMultiple($values, $type = 'default', $ttl = null)
    {
        if (!Config::ENABLE_CACHE || !self::$apcuEnabled) {
            return false;
        }

        if (null === $ttl) {
            $config = Config::CACHE_CONFIG[$type] ?? ['ttl' => Config::CACHE_TTL];
            $ttl = $config['ttl'];
        }

        $prefixedValues = [];
        foreach ($values as $key => $value) {
            $prefixedValues[$type.'::'.$key] = $value;
        }

        return apcu_store($prefixedValues, null, $ttl);
    }

    /**
     * Получить информацию о кэше для отладки.
     */
    public static function getInfo()
    {
        $info = [
            'enabled' => Config::ENABLE_CACHE,
            'apcu_available' => self::$apcuEnabled,
            'cache_ttl' => Config::CACHE_TTL,
            'types' => array_keys(Config::CACHE_CONFIG),
        ];

        if (self::$apcuEnabled) {
            $info['apcu_version'] = phpversion('apcu');
            $info['memory_limit'] = ini_get('apcu.shm_size');
        }

        return $info;
    }
}

// Автоматическая инициализация
Cache::init();
