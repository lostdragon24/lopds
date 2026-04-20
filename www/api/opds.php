<?php

// api/opds.php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../lib/Database.php';
require_once __DIR__.'/../lib/OpdsGenerator.php';
require_once __DIR__.'/../lib/Cache.php';
require_once __DIR__.'/../init.php';

// Базовый URL
$baseUrl = (isset($_SERVER['HTTPS']) && 'on' === $_SERVER['HTTPS'] ? 'https' : 'http').
           '://'.$_SERVER['HTTP_HOST'].dirname(dirname($_SERVER['SCRIPT_NAME']));

$generator = new OpdsGenerator($baseUrl);

// Создаем ключ кэша для OPDS
$cacheKey = 'opds_'.md5($_SERVER['REQUEST_URI']);

// Пробуем получить из кэша
if (Config::ENABLE_CACHE) {
    $cached = Cache::get($cacheKey, 'opds_feeds');
    if (null !== $cached) {
        header('Content-Type: application/atom+xml; charset=utf-8');
        header('X-Cache: HIT');
        echo $cached;
        exit;
    }
}

try {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

    ob_start();

    if (isset($_GET['search']) && !empty($_GET['search'])) {
        echo $generator->generateSearchResults($_GET['search'], $page);
    } elseif (isset($_GET['by']) && 'authors' === $_GET['by']) {
        echo $generator->generateByAuthors($page);
    } elseif (isset($_GET['by']) && 'genres' === $_GET['by']) {
        echo $generator->generateByGenres($page);
    } elseif (isset($_GET['author']) && !empty($_GET['author'])) {
        echo $generator->generateByAuthor($_GET['author'], $page);
    } elseif (isset($_GET['genre']) && !empty($_GET['genre'])) {
        echo $generator->generateByGenre($_GET['genre'], $page);
    } elseif (isset($_GET['series']) && !empty($_GET['series'])) {
        if (method_exists($generator, 'generateBySeries')) {
            echo $generator->generateBySeries($_GET['series'], $page);
        } else {
            header('Content-Type: application/atom+xml; charset=utf-8');
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<error><message>'.__('opds_series_not_available').'</message></error>';
        }
    } else {
        echo $generator->generateCatalog($page);
    }

    $content = ob_get_clean();

    // Сохраняем в кэш
    if (Config::ENABLE_CACHE) {
        Cache::set($cacheKey, $content, 'opds_feeds');
        header('X-Cache: MISS');
    }

    echo $content;
} catch (Exception $e) {
    header('Content-Type: text/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<error><message>'.__('opds_error').': '.htmlspecialchars($e->getMessage()).'</message></error>';
    error_log('OPDS Error: '.$e->getMessage());
}
