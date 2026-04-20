<?php

class DatabaseStub
{
    private static $instance;
    private $isAvailable = false;

    public static function getInstance()
    {
        if (null === self::$instance) {
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

    public function __call($name, $arguments)
    {
        return null;
    }
}
