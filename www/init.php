<?php

// init.php

require_once __DIR__ . '/bootstrap.php';
require_once LOPDS_ROOT . '/config/config.php';

// ============================================
// ЗАПУСКАЕМ СЕССИЮ С ПРАВИЛЬНЫМ ИМЕНЕМ
// ============================================
require_once LOPDS_ROOT . '/lib/SessionManager.php';

// Определяем, в админке мы или нет
$isAdmin = strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false;

if ($isAdmin) {
    session_name('ADMIN_SESSION');
} else {
    session_name('USER_SESSION');
}

SessionManager::start();

require_once LOPDS_ROOT . '/lib/SecurityHelper.php';
SecurityHelper::getInstance()->addSecurityHeaders();


// ============================================
// ИНИЦИАЛИЗИРУЕМ ПРИЛОЖЕНИЕ
// ============================================
require_once LOPDS_ROOT . '/lib/AppInitializer.php';
AppInitializer::init();

// ============================================
// ПОДКЛЮЧАЕМ ПЕРЕВОД
// ============================================
require_once LOPDS_ROOT . '/lib/LanguageDetector.php';
require_once LOPDS_ROOT . '/lib/Translator.php';

// Инициализируем переводчик (он определит язык из сессии или POST)
$translator = Translator::getInstance();
$currentLang = $translator->getCurrentLanguage();

// Устанавливаем локаль
setlocale(LC_ALL, $currentLang . '_' . strtoupper($currentLang) . '.UTF-8');
if ($currentLang === 'ru') {
    setlocale(LC_TIME, 'ru_RU.UTF-8');
} else {
    setlocale(LC_TIME, 'en_US.UTF-8');
}

error_log("init.php - Is admin: " . ($isAdmin ? 'yes' : 'no'));
error_log("init.php - Session name: " . session_name());
error_log("init.php - Final current language: " . $currentLang);
