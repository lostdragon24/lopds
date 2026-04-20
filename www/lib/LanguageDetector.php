<?php

// lib/LanguageDetector.php

class LanguageDetector
{
    private static $instance;
    private $availableLangs = ['ru', 'en', 'ua', 'by', 'kz'];
    private $defaultLang = 'ru';
    private $currentLang;
    private $sessionName;

    private function __construct()
    {
        // Определяем имя сессии в зависимости от того, где мы находимся
        $isAdmin = false !== strpos($_SERVER['SCRIPT_NAME'], '/admin/');
        $this->sessionName = $isAdmin ? 'ADMIN_SESSION' : 'USER_SESSION';

        // Запускаем сессию с правильным именем
        if (PHP_SESSION_NONE === session_status()) {
            session_name($this->sessionName);
            session_start();
        }

        $this->detectLanguage();
    }

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function detectLanguage()
    {
        error_log('=== LanguageDetector::detectLanguage() START ===');
        error_log('Session name: '.$this->sessionName);
        error_log('Is admin: '.(false !== strpos($_SERVER['SCRIPT_NAME'], '/admin/') ? 'yes' : 'no'));
        error_log('POST data: '.print_r($_POST, true));
        error_log('SESSION before: '.print_r($_SESSION, true));

        // 1. Сначала проверяем POST запрос (смена языка)
        if (isset($_POST['lang']) && in_array($_POST['lang'], $this->availableLangs)) {
            $this->currentLang = $_POST['lang'];
            $_SESSION['user_lang'] = $this->currentLang;
            setcookie('user_lang', $this->currentLang, time() + 86400 * 30, '/');
            error_log('Language set from POST: '.$this->currentLang);
            error_log('SESSION after POST: '.print_r($_SESSION, true));

            return;
        }

        // 2. Проверяем сессию
        if (isset($_SESSION['user_lang']) && in_array($_SESSION['user_lang'], $this->availableLangs)) {
            $this->currentLang = $_SESSION['user_lang'];
            error_log('Language from session: '.$this->currentLang);

            return;
        }

        // 3. Проверяем cookie (общий для всех)
        if (isset($_COOKIE['user_lang']) && in_array($_COOKIE['user_lang'], $this->availableLangs)) {
            $this->currentLang = $_COOKIE['user_lang'];
            $_SESSION['user_lang'] = $this->currentLang;
            error_log('Language from cookie: '.$this->currentLang);

            return;
        }

        // 4. Определяем из браузера
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $browserLangs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
            foreach ($browserLangs as $langWithPriority) {
                $langCode = substr(trim($langWithPriority), 0, 2);
                if (in_array($langCode, $this->availableLangs)) {
                    $this->currentLang = $langCode;
                    $_SESSION['user_lang'] = $this->currentLang;
                    setcookie('user_lang', $this->currentLang, time() + 86400 * 30, '/');
                    error_log('Language from browser: '.$this->currentLang);

                    return;
                }
            }
        }

        // 5. Язык по умолчанию
        $this->currentLang = $this->defaultLang;
        $_SESSION['user_lang'] = $this->defaultLang;
        error_log('Language default: '.$this->currentLang);
    }

    public function setLanguage($lang)
    {
        error_log('LanguageDetector::setLanguage() called with: '.$lang);

        if (in_array($lang, $this->availableLangs)) {
            $this->currentLang = $lang;
            $_SESSION['user_lang'] = $lang;
            setcookie('user_lang', $lang, time() + 86400 * 30, '/');

            // ============================================
            // ОЧИЩАЕМ КЭШ ПРИ СМЕНЕ ЯЗЫКА
            // ============================================
            if (class_exists('PageCache')) {
                // Очищаем весь кэш страниц
                PageCache::clear();

                // Также очищаем кэш для старого языка
                $oldLang = $_SESSION['user_lang'] ?? $this->defaultLang;
                if ($oldLang !== $lang && method_exists('PageCache', 'clearLanguageCache')) {
                    PageCache::clearLanguageCache($oldLang);
                }
            }

            // Принудительно сохраняем сессию
            session_write_close();

            // Перезапускаем сессию для текущего запроса
            if (PHP_SESSION_NONE === session_status()) {
                session_name($this->sessionName);
                session_start();
            }

            error_log('LanguageDetector::setLanguage() - New language: '.$lang);
            error_log('SESSION after set: '.print_r($_SESSION, true));

            return true;
        }
        error_log('LanguageDetector::setLanguage() - Invalid language: '.$lang);

        return false;
    }

    public function getCurrentLanguage()
    {
        return $this->currentLang;
    }

    public function getAvailableLanguages()
    {
        return $this->availableLangs;
    }

    public function getLanguageFlag($lang = null)
    {
        if (null === $lang) {
            $lang = $this->currentLang;
        }

        $flags = [
            'ru' => '🇷🇺',
            'en' => '🇬🇧',
            'ua' => '🇺🇦',
            'by' => '🇧🇾',
            'kz' => '🇰🇿',
        ];

        return $flags[$lang] ?? '🌐';
    }

    public function getLanguageName($lang = null)
    {
        if (null === $lang) {
            $lang = $this->currentLang;
        }

        $names = [
            'ru' => 'Русский',
            'en' => 'English',
            'ua' => 'Українська',
            'by' => 'Беларускі',
            'kz' => 'Қазақ',
        ];

        return $names[$lang] ?? $lang;
    }
}
