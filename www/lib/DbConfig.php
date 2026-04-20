<?php

require_once __DIR__.'/EnvLoader.php';
require_once __DIR__.'/PathManager.php';

class DbConfig
{
    /**
     * Получить настройки базы данных.
     */
    public static function getConfig()
    {
        return [
            'type' => self::getType(),
            'host' => self::getHost(),
            'name' => self::getName(),
            'user' => self::getUser(),
            'pass' => self::getPass(),
            'path' => self::getPath(),
        ];
    }

    /**
     * Получить тип базы данных.
     */
    public static function getType()
    {
        return EnvLoader::get('DB_TYPE', ConfigData::DB_TYPE);
    }

    /**
     * Получить хост MySQL.
     */
    public static function getHost()
    {
        return EnvLoader::get('DB_HOST', ConfigData::DB_HOST);
    }

    /**
     * Получить имя базы данных MySQL.
     */
    public static function getName()
    {
        return EnvLoader::get('DB_NAME', 'mybook');
    }

    /**
     * Получить пользователя MySQL.
     */
    public static function getUser()
    {
        return EnvLoader::get('DB_USER', '');
    }

    /**
     * Получить пароль MySQL.
     */
    public static function getPass()
    {
        return EnvLoader::get('DB_PASS', '');
    }

    /**
     * Получить путь к SQLite базе.
     */
    public static function getPath()
    {
        return EnvLoader::get('DB_PATH', PathManager::getDbPath());
    }

    /**
     * Проверить, используется ли SQLite.
     */
    public static function isSqlite()
    {
        return 'sqlite' === self::getType();
    }

    /**
     * Проверить, используется ли MySQL.
     */
    public static function isMysql()
    {
        return 'mysql' === self::getType();
    }
}
