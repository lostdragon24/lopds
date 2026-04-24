<?php
// api/opds.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/OpdsGenerator.php';
require_once __DIR__ . '/../lib/Cache.php';
require_once __DIR__ . '/../init.php';

// ============================================
// ВАЖНО: Устанавливаем язык ДО инициализации Translator
// ============================================

// Получаем язык из настроек
$opdsLang = Config::getOpdsDefaultLang();

// Если указан конкретный язык (не 'auto')
if ($opdsLang !== 'auto' && in_array($opdsLang, ['ru', 'en', 'ua', 'by', 'kz'])) {
    // ВАЖНО: Устанавливаем язык в сессию ДО того, как Translator прочитает его
    if (session_status() === PHP_SESSION_NONE) {
        session_name('USER_SESSION');
        session_start();
    }
    $_SESSION['user_lang'] = $opdsLang;

    // Также устанавливаем в Translator (который уже инициализирован в init.php)
    $translator = Translator::getInstance();
    $translator->setLanguage($opdsLang);

    // Перезагружаем жанры
    if (class_exists('GenreManager')) {
        GenreManager::reload();
    }

    error_log("OPDS: Language set to {$opdsLang} from config");
} else {
    error_log("OPDS: Using auto language detection");
}

// Базовый URL
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
           "://" . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME']));

$generator = new OpdsGenerator($baseUrl);

// Создаем ключ кэша с учетом языка
$cacheLang = $opdsLang !== 'auto' ? $opdsLang : 'auto';
$cacheKey = 'opds_' . md5($_SERVER['REQUEST_URI'] . '_lang_' . $cacheLang);

// Пробуем получить из кэша
if (Config::isCacheEnabled()) {
    $cached = Cache::get($cacheKey, 'opds_feeds');
    if ($cached !== null) {
        header('Content-Type: application/atom+xml; charset=utf-8');
        header('X-Cache: HIT');
        header('X-Language: ' . $opdsLang);
        echo $cached;
        exit;
    }
}

try {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

    ob_start();

    if (isset($_GET['search']) && !empty($_GET['search'])) {
        echo $generator->generateSearchResults($_GET['search'], $page);
    } elseif (isset($_GET['by']) && $_GET['by'] === 'authors') {
        echo $generator->generateByAuthors($page);
    } elseif (isset($_GET['by']) && $_GET['by'] === 'genres') {
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
            echo '<error><message>' . __('opds_series_not_available') . '</message></error>';
        }
    } else {
        echo $generator->generateCatalog($page);
    }

    $content = ob_get_clean();

    // Сохраняем в кэш
    if (Config::isCacheEnabled()) {
        Cache::set($cacheKey, $content, 'opds_feeds', 3600);
        header('X-Cache: MISS');
    }

    header('X-Language: ' . $opdsLang);
    echo $content;

} catch (Exception $e) {
    header('Content-Type: text/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<error><message>' . __('opds_error') . ': ' . htmlspecialchars($e->getMessage()) . '</message></error>';
    error_log("OPDS Error: " . $e->getMessage());
}
