<?php

require_once __DIR__ . '/../config/config.php';

class Cache {
    private static $apcuEnabled = false;
    private static $initialized = false;
    
    /**
     * Инициализация кэша
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }
        
        self::$apcuEnabled = Config::USE_APCU && extension_loaded('apcu') && apcu_enabled();
        
        if (self::$apcuEnabled) {
            error_log("APCu cache enabled");
        } else {
            error_log("APCu cache disabled or not available");
        }
        
        self::$initialized = true;
    }
    
    /**
     * Получить данные из кэша
     */
    public static function get($key, $type = 'default') {
        if (!Config::ENABLE_CACHE || !self::$apcuEnabled) {
            return null;
        }
        
        $success = false;
        $value = apcu_fetch($key, $success);
        
        return $success ? $value : null;
    }
    
    /**
     * Сохранить данные в кэш
     */
    public static function set($key, $data, $type = 'default') {
        if (!Config::ENABLE_CACHE || !self::$apcuEnabled) {
            return false;
        }
        
        $config = Config::CACHE_CONFIG[$type] ?? ['ttl' => Config::CACHE_TTL];
        $ttl = $config['ttl'];
        
        return apcu_store($key, $data, $ttl);
    }
    
    /**
     * Удалить данные из кэша
     */
    public static function delete($key) {
        if (!self::$apcuEnabled) {
            return false;
        }
        
        return apcu_delete($key);
    }
    
    /**
     * Очистить весь кэш (для админки)
     */
    public static function clear() {
        if (self::$apcuEnabled) {
            apcu_clear_cache();
            return true;
        }
        
        return false;
    }
    
    /**
     * Получить статистику кэша
     */
    public static function getStats() {
        $stats = [];
        
        if (self::$apcuEnabled) {
            $apcuStats = apcu_cache_info(true);
            $stats['apcu'] = [
                'hits' => $apcuStats['num_hits'] ?? 0,
                'misses' => $apcuStats['num_misses'] ?? 0,
                'memory_usage' => $apcuStats['mem_size'] ?? 0,
                'entries' => $apcuStats['num_entries'] ?? 0,
                'effectiveness' => ($apcuStats['num_hits'] + $apcuStats['num_misses']) > 0 ? 
                    round($apcuStats['num_hits'] / ($apcuStats['num_hits'] + $apcuStats['num_misses']) * 100, 1) : 0
            ];
        }
        
        return $stats;
    }
    
    /**
     * Проверить наличие ключа в кэше
     */
    public static function exists($key) {
        if (!Config::ENABLE_CACHE || !self::$apcuEnabled) {
            return false;
        }
        
        return apcu_exists($key);
    }
    
    /**
     * Получить несколько значений за раз
     */
    public static function getMultiple($keys) {
        if (!Config::ENABLE_CACHE || !self::$apcuEnabled) {
            return [];
        }
        
        return apcu_fetch($keys);
    }
    
    /**
     * Сохранить несколько значений за раз
     */
    public static function setMultiple($values, $type = 'default') {
        if (!Config::ENABLE_CACHE || !self::$apcuEnabled) {
            return false;
        }
        
        $config = Config::CACHE_CONFIG[$type] ?? ['ttl' => Config::CACHE_TTL];
        $ttl = $config['ttl'];
        
        return apcu_store($values, null, $ttl);
    }
    
    /**
     * Увеличить значение (для счетчиков)
     */
    public static function increment($key, $step = 1) {
        if (!Config::ENABLE_CACHE || !self::$apcuEnabled) {
            return false;
        }
        
        if (!apcu_exists($key)) {
            return apcu_store($key, $step);
        }
        
        return apcu_inc($key, $step);
    }
    
    /**
     * Уменьшить значение (для счетчиков)
     */
    public static function decrement($key, $step = 1) {
        if (!Config::ENABLE_CACHE || !self::$apcuEnabled) {
            return false;
        }
        
        if (!apcu_exists($key)) {
            return apcu_store($key, -$step);
        }
        
        return apcu_dec($key, $step);
    }
}

// Автоматическая инициализация
Cache::init();
?>