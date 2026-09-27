<?php

// init.php

require_once __DIR__ . '/bootstrap.php';
require_once LOPDS_ROOT . '/config/config.php';

// ============================================
// ЗАПУСКАЕМ СЕССИЮ С ПРАВИЛЬНЫМ ИМЕНЕМ
// ============================================
require_once LOPDS_ROOT . '/lib/SessionManager.php';
require_once LOPDS_ROOT . '/lib/SessionInitializer.php';

// ============================================
// ПОДКЛЮЧАЕМ ПЕРЕВОД
// ============================================
require_once LOPDS_ROOT . '/lib/LanguageDetector.php';
require_once LOPDS_ROOT . '/lib/Translator.php';
require_once LOPDS_ROOT . '/lib/Logger.php';
Logger::setLogFile(Config::getCacheDir() . '/system.log');

SessionInitializer::initialize();



// Определяем, в админке мы или нет
//$isAdmin = strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false;
//
//if ($isAdmin) {
//    session_name('ADMIN_SESSION');
//} else {
//    session_name('USER_SESSION');
//}


// Определяем идентификатор устройства
if (isset($_COOKIE['device_fp'])) {
    $deviceId = $_COOKIE['device_fp'];
} else {
    $deviceId = $_SERVER['REMOTE_ADDR'];
}
define('DEVICE_ID', $deviceId);

SessionManager::start();

require_once LOPDS_ROOT . '/lib/SecurityHelper.php';
SecurityHelper::getInstance()->addSecurityHeaders();

// ============================================
// ИНИЦИАЛИЗИРУЕМ ПРИЛОЖЕНИЕ
// ============================================
require_once LOPDS_ROOT . '/lib/AppInitializer.php';
AppInitializer::init();

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

//my_log("init.php - Is admin: " . ($isAdmin ? 'yes' : 'no'));
my_log("init.php - Session name: " . session_name());
my_log("init.php - Final current language: " . $currentLang);
