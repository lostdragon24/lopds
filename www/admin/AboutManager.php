<?php

// admin/AboutManager.php

class AboutManager
{
    private $version = '0.1.16.2';
    private $appName = 'Little OPDS';

    public function getAppInfo()
    {
        return [
            'name' => $this->appName,
            'version' => $this->version,
            'author' => 'Squee&Dragon',
            'license' => 'GNU GPL v2',
            'website' => 'https://github.com/lostdragon24/lopds',
            'website2' => 'https://gitflic.ru/project/ldragon246/lopds',
            'description' => __('about_description'),
            'php_version' => PHP_VERSION,
            'db_type' => Config::getDbType(),
            'cache_enabled' => Config::isCacheEnabled() ? '✅ ' . __('yes') : '❌ ' . __('no'),
            'apcu_enabled' => extension_loaded('apcu') && apcu_enabled() ? '✅ ' . __('yes') : '❌ ' . __('no'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_execution_time' => ini_get('max_execution_time') . 's'
        ];
    }

    public function getChangelog()
    {
        return [
            '0.1.16.2' => [
                'date' => '2026-09-29',
                'title' => 'Cтабильная версия',
                'changes' => [
                    '🔧 Изменён дизайн главной страницы',
                    '➕ Добавлен блок поиска, с пятью рандомными книгами',
                    '➕ Добавлен фильтр жанров',
                    '➕ Добавлена тёмная тема',
                    '➕ Добавлена возможность включения полнотекстового поиска (параметр FTS в конфигурационном файле (true\false))',
                    '🔧 Бесконечный список вместо пагинации',
                    '🐛 Исправления в читалке (форматирование)',
                    '🐛 Исправлениt и доработка функции дедупликации авторов',
                    '🐛 Исправления конвертора FB2->EPUB',
                    '🐛 Исправления в установщике библиотеки (создание базы данных)',
                    '🐛 Исправления в управлении санером (включение\отключеие INPX)',
                ]
            ],
            '0.1.16' => [
                'date' => '2026-07-24',
                'title' => 'Cтабильная версия',
                'changes' => [
                    '➕ Добавлена система заметок и цитат',
                    '➕ Добавлена подсветка текста в читалке',
                    '➕ Добавлена панель аннотаций',
                    '➕ Добавлена страница закладок и заметок',
                    '➕ Добавлен экспорт заметок в Markdown',
                    '➕ Добавлен полнотекстовый поиск по заметкам',
                    '➕ Добавлен конвертор FB2->EPUB',
                    '➕ Добавлена система дедупликации авторов (поиск дубликатов в именах авторов)',
                    '🐛 Исправлена работа контекстного меню в читалке',
                    '🔧 Оптимизированы запросы к БД',
                    '🔧 Добавлены индексы для быстрого поиска',
                    '➕ Многопоточность в сканере книг (настраивается в конфигурационном файле, при запуске из вебинтерфейса используется один поток)',
                    '➕ Настраиваемый размер пачки для вставки в БД (настраивается в конфигурационном файле, при запуске из вебинтерфейса = 1000)',
                    '➕ Операции парсинга метаданных частично вынесены в оперативную память'
                ]
            ],
            '0.1.15' => [
                'date' => '2026-06-15',
                'title' => '2 Бета-версия',
                'changes' => [
                    '➕ Добавлена базовая система закладок',
                    '➕ Добавлена автоматическая синхронизация прогресса чтения',
                    '➕ Добавлена система журналирования (Панель управления\Логи\Система)',
                    '➕ Добавлена возможность добавления книги руками - Панель управления\Книги\Добавить',
                    '🐛 Исправлены ошибки в reader.php',
                    '🐛 Исправление ошибок в сканере (парсинг аннотации)',
                    '🔧 Улучшена производительность'
                ]
            ],
            '0.1.13' => [
                'date' => '2026-03-07',
                'title' => '1 Бета-версия',
                'changes' => [
                    '➕ Добавлена базовая система рейтингов',
                    '➕ Добавлена базовая система избранного',
                    '➕ Добавлена Панель управления (управление коллекцией, сканером, работа с БД)',
                    '➕ Добавлена система установки (первоначальной настройки) приложения',
                '➕ Добавлена поддержка форматов EPUB и PDF',
                    '🐛 Исправлены ошибки',
                    '🔧 Улучшена производительность'
                ]
            ],
            '0.1.1' => [
                'date' => '2017-06-15',
                'title' => 'Альфа-версия',
                'changes' => [
                    '➕ Создан сканер книг на C',
                    '➕ Добавлена поддержка SQLite и MySQL',
                    '➕ Добавлен импорт INPX',
                    '➕ Добавлена поддержка FB2',
                    '➕ Создан веб-интерфейс'
                ]
            ]
        ];
    }

    public function getCredits()
    {
        return [
            'core' => [
                'Алексей Плотников' => 'Идея, разработка',
                'Егор Плотников' => 'Тестирование...',
                'Дана Плотникова' => 'Тестирование OPDS...',
                'Андрей Поляков' => 'Идеи...',
            ],
            'libraries' => [
                'Bootstrap 5' => 'CSS фреймворк',
                'Font Awesome 6' => 'Иконки',
                'jQuery' => 'JavaScript библиотека',
                'PDF.js' => 'Просмотр PDF',
                'EPUB.js' => 'Просмотр EPUB',
                'SQLite3' => 'База данных SQLite',
                'MySQL/MariaDB' => 'База данных MySQL'
            ],
            'thanks' => [
                'Open Source Community' => 'За вдохновение и библиотеки',
                'Internet Archive, Feedbooks, O\' Reilly Media, Book Oven и Threepress' => 'За формат OPDS',
                'Calibre' => 'За стандарты метаданных',
                'AlekseyFadeev' => 'за FB2 -> EPUB Converter',
                'Mozilla' => 'за PDF.js',
                'FuturePress' => 'за EPUB.js',
                'Дмитрий Шелепнёв' => 'за SOPDS',
            ]
        ];
    }

    public function getSystemRequirements()
    {
        return [
            'php' => [
                'version' => '7.4+',
                'current' => PHP_VERSION,
                'status' => version_compare(PHP_VERSION, '7.4.0', '>=')
            ],
            'extensions' => [
                'PDO' => extension_loaded('pdo'),
                'PDO_SQLite' => extension_loaded('pdo_sqlite'),
                'PDO_MySQL' => extension_loaded('pdo_mysql'),
                'JSON' => extension_loaded('json'),
                'GD' => extension_loaded('gd'),
                'XML' => extension_loaded('xml'),
                'APCu' => extension_loaded('apcu') && apcu_enabled()
            ],
            'permissions' => [
                'books_dir' => is_writable(Config::getBooksDir()),
                'cache_dir' => is_writable(Config::getCacheDir()),
                'cover_cache_dir' => is_writable(Config::getCoverCacheDir())
            ]
        ];
    }
}
