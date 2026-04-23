<?php

// Проверяем, что мы в режиме установки
if (!defined('INSTALL_MODE') || INSTALL_MODE !== true) {
    return;
}

// Объявляем класс только если его еще нет
if (!class_exists('Database', false)) {
    class Database
    {
        private static $instance = null;

        public static function getInstance()
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function isAvailable()
        {
            return false;
        }

        public function getConnection()
        {
            throw new Exception('Database not configured yet');
        }

        public function getTotalBooksCount()
        {
            return 0;
        }

        public function getTopAuthors($limit = 5)
        {
            return [];
        }

        public function __call($name, $arguments)
        {
            return null;
        }
    }
}
