<?php

// Определяем корневую константу, если ещё не определена
if (!defined('LOPDS_ROOT')) {
    define('LOPDS_ROOT', __DIR__);
}

// Функция для безопасного подключения
function require_with_check($file)
{
    if (!file_exists($file)) {
        throw new Exception("Required file not found: $file");
    }
    require_once $file;
}

// Автозагрузчик классов (опционально, для будущего использования)
spl_autoload_register(function ($class) {
    $paths = [
        LOPDS_ROOT.'/lib/',
        LOPDS_ROOT.'/admin/',
        LOPDS_ROOT.'/lib/CoverParser/',
    ];

    foreach ($paths as $path) {
        $file = $path.$class.'.php';
        if (file_exists($file)) {
            require_once $file;

            return true;
        }
    }

    return false;
});
