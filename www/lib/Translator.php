<?php

// lib/Translator.php

require_once __DIR__.'/LanguageDetector.php';

class Translator
{
    private static $instance;
    private $translations = [];
    private $languageDetector;
    private static $translationsCache = [];

    private function __construct()
    {
        $this->languageDetector = LanguageDetector::getInstance();
        $this->loadLanguage($this->languageDetector->getCurrentLanguage());
    }

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function loadLanguage($lang)
    {
        // Проверяем кэш в памяти
        if (isset(self::$translationsCache[$lang])) {
            $this->translations = self::$translationsCache[$lang];
            error_log("Translator: Using cached translations for {$lang}");

            return;
        }

        $langFile = __DIR__."/../lang/{$lang}.php";
        if (file_exists($langFile)) {
            $this->translations = include $langFile;
            self::$translationsCache[$lang] = $this->translations;
            error_log('Translator: Loaded '.count($this->translations)." translations from $langFile");
        } else {
            $fallback = __DIR__.'/../lang/ru.php';
            if (file_exists($fallback)) {
                $this->translations = include $fallback;
                self::$translationsCache['ru'] = $this->translations;
            }
        }
    }

    public function getAllTranslations()
    {
        return $this->translations;
    }

    public function translate($key, $params = [])
    {
        $text = $this->translations[$key] ?? $key;

        if (!empty($params)) {
            foreach ($params as $placeholder => $value) {
                $text = str_replace("%{$placeholder}%", $value, $text);
                $text = str_replace(":{$placeholder}", $value, $text);
                $text = str_replace("{{$placeholder}}", $value, $text);
            }
        }

        return $text;
    }

    public function getCurrentLanguage()
    {
        return $this->languageDetector->getCurrentLanguage();
    }

    public function setLanguage($lang)
    {
        error_log('Translator::setLanguage() called with: '.$lang);

        if ($this->languageDetector->setLanguage($lang)) {
            $this->loadLanguage($lang);
            if (class_exists('GenreManager')) {
                GenreManager::reload();
            }
            error_log('Translator::setLanguage() - Language changed to: '.$lang);

            return true;
        }
        error_log('Translator::setLanguage() - Failed to change language');

        return false;
    }

    public function getAvailableLanguages()
    {
        return $this->languageDetector->getAvailableLanguages();
    }

    public function getLanguageFlag($lang = null)
    {
        return $this->languageDetector->getLanguageFlag($lang);
    }

    public function getLanguageName($lang = null)
    {
        return $this->languageDetector->getLanguageName($lang);
    }
}

function __($key, $params = [])
{
    return Translator::getInstance()->translate($key, $params);
}
