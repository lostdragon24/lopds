<?php

// change-language.php

// Определяем, откуда пришли (админка или сайт)
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$isFromAdmin = false !== strpos($referer, '/admin/');

// Устанавливаем имя сессии в зависимости от источника
if ($isFromAdmin) {
    session_name('ADMIN_SESSION');
} else {
    session_name('USER_SESSION');
}

session_start();

error_log('=== change-language.php called ===');
error_log('Referer: '.$referer);
error_log('Is from admin: '.($isFromAdmin ? 'yes' : 'no'));
error_log('Session name: '.session_name());
error_log('POST data: '.print_r($_POST, true));
error_log('Session before: '.print_r($_SESSION, true));

if (isset($_POST['lang'])) {
    $lang = $_POST['lang'];
    error_log('Attempting to change language to: '.$lang);

    // Сохраняем язык в сессию
    $_SESSION['user_lang'] = $lang;

    // Сохраняем в cookie на 30 дней (для всех страниц)
    setcookie('user_lang', $lang, time() + 86400 * 30, '/');

    // ============================================
    // ВАЖНО: ОЧИЩАЕМ КЭШ СТРАНИЦ ПРИ СМЕНЕ ЯЗЫКА
    // ============================================

    // Подключаем классы для работы с кэшем
    require_once __DIR__.'/lib/Cache.php';
    require_once __DIR__.'/lib/PageCache.php';

    // Очищаем кэш страниц
    PageCache::clear();

    // Также очищаем кэш по типам, которые могут зависеть от языка
    Cache::invalidateByType('page_cache');
    Cache::invalidateByType('statistics');
    Cache::invalidateByType('search_results');
    Cache::invalidateByType('book_data');

    error_log('Cache cleared after language change to: '.$lang);

    // Принудительно сохраняем сессию
    session_write_close();

    error_log('Session after save: '.print_r($_SESSION, true));

    // Возвращаемся обратно
    $redirect = $referer ?: '/';
    error_log('Redirecting to: '.$redirect);

    header('Location: '.$redirect);
    exit;
}

error_log('No lang in POST, redirecting to /');
header('Location: /');
exit;
