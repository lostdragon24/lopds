<?php

// admin/SettingsManager.php

require_once __DIR__ . '/../lib/EnvManager.php';
require_once __DIR__ . '/../lib/BackupManager.php';
require_once __DIR__ . '/../lib/SettingsValidator.php';

class SettingsManager
{
    private $envManager;
    private $backupManager;
    private $validator;
    private $groups = [];

    public function __construct()
    {
        $this->envManager = new EnvManager();
        $this->backupManager = new BackupManager();
        $this->validator = new SettingsValidator();
        $this->initGroups();
    }

    /**
     * Инициализация групп настроек
     */
    private function initGroups()
    {
        $this->groups = [
            'site' => [
                'title' => __('settings_group_site'),
                'icon' => 'fa-globe',
                'fields' => [
                    'SITE_TITLE' => [
                        'label' => __('settings_field_site_title'),
                        'type' => 'text',
                        'default' => 'Моя домашняя библиотека',
                        'description' => __('settings_field_site_title_desc'),
                        'required' => true,
                        'max_length' => 100
                    ],
                    'ITEMS_PER_PAGE' => [
                        'label' => __('settings_field_items_per_page'),
                        'type' => 'number',
                        'default' => 20,
                        'description' => __('settings_field_items_per_page_desc'),
                        'required' => true,
                        'min' => 5,
                        'max' => 100
                    ]
                ]
            ],

'opds' => [
    'title' => __('settings_group_opds'),
    'icon' => 'fa-rss',
    'fields' => [
        'OPDS_TITLE' => [
            'label' => __('settings_field_opds_title'),
            'type' => 'text',
            'default' => 'Моя библиотека',
            'description' => __('settings_field_opds_title_desc'),
            'required' => true
        ],
        'OPDS_AUTHOR' => [
            'label' => __('settings_field_opds_author'),
            'type' => 'text',
            'default' => 'Book Lib',
            'description' => __('settings_field_opds_author_desc'),
            'required' => true
        ],
        'OPDS_ID' => [
            'label' => __('settings_field_opds_id'),
            'type' => 'text',
            'default' => 'urn:uuid:your-uuid-here',
            'description' => __('settings_field_opds_id_desc'),
            'required' => true
        ],
        // НОВОЕ ПОЛЕ: Язык OPDS по умолчанию
        'OPDS_DEFAULT_LANG' => [
            'label' => __('settings_field_opds_default_lang'),
            'type' => 'select',
            'options' => [
                'auto' => 'Авто (из сессии/куки)',
                'en' => 'English',
                'ru' => 'Русский',
                'ua' => 'Українська',
                'by' => 'Беларускі',
                'kz' => 'Қазақ'
            ],
            'default' => 'auto',
            'description' => __('settings_field_opds_default_lang_desc')
        ]
    ]
],
            'cache' => [
                'title' => __('settings_group_cache'),
                'icon' => 'fa-bolt',
                'fields' => [
                    'ENABLE_CACHE' => [
                        'label' => __('settings_field_enable_cache'),
                        'type' => 'checkbox',
                        'default' => true,
                        'description' => __('settings_field_enable_cache_desc')
                    ],
                    'USE_APCU' => [
                        'label' => __('settings_field_use_apcu'),
                        'type' => 'checkbox',
                        'default' => true,
                        'description' => __('settings_field_use_apcu_desc')
                    ],
                    'CACHE_TTL' => [
                        'label' => __('settings_field_cache_ttl'),
                        'type' => 'number',
                        'default' => 36000,
                        'description' => __('settings_field_cache_ttl_desc'),
                        'min' => 60,
                        'max' => 86400
                    ],
                    'PAGE_CACHE_ENABLED' => [
                        'label' => __('settings_field_page_cache'),
                        'type' => 'checkbox',
                        'default' => true,
                        'description' => __('settings_field_page_cache_desc')
                    ]
                ]
            ],

            'database' => [
                'title' => __('settings_group_database'),
                'icon' => 'fa-database',
                'fields' => [
                    'DB_TYPE' => [
                        'label' => __('settings_field_db_type'),
                        'type' => 'select',
                        'options' => ['mysql' => 'MySQL', 'sqlite' => 'SQLite'],
                        'default' => 'mysql',
                        'description' => __('settings_field_db_type_desc'),
                        'required' => true
                    ],
                    'DB_HOST' => [
                        'label' => __('settings_field_db_host'),
                        'type' => 'text',
                        'default' => 'localhost',
                        'description' => __('settings_field_db_host_desc'),
                        'condition' => ['DB_TYPE' => 'mysql']
                    ],
                    'DB_NAME' => [
                        'label' => __('settings_field_db_name'),
                        'type' => 'text',
                        'default' => 'mybook2',
                        'description' => __('settings_field_db_name_desc'),
                        'condition' => ['DB_TYPE' => 'mysql']
                    ],
                    'DB_USER' => [
                        'label' => __('settings_field_db_user'),
                        'type' => 'text',
                        'default' => 'root',
                        'description' => __('settings_field_db_user_desc'),
                        'condition' => ['DB_TYPE' => 'mysql']
                    ],
                    'DB_PASS' => [
                        'label' => __('settings_field_db_pass'),
                        'type' => 'password',
                        'default' => '',
                        'description' => __('settings_field_db_pass_desc'),
                        'condition' => ['DB_TYPE' => 'mysql']
                    ],
                    'DB_PATH' => [
                        'label' => __('settings_field_db_path'),
                        'type' => 'text',
                        'default' => '',
                        'description' => __('settings_field_db_path_desc'),
                        'condition' => ['DB_TYPE' => 'sqlite']
                    ]
                ]
            ],

            'paths' => [
                'title' => __('settings_group_paths'),
                'icon' => 'fa-folder-open',
                'fields' => [
                    'BOOKS_DIR' => [
                        'label' => __('settings_field_books_dir'),
                        'type' => 'text',
                        'default' => '',
                        'description' => __('settings_field_books_dir_desc'),
                        'required' => true
                    ],
                    'CACHE_DIR' => [
                        'label' => __('settings_field_cache_dir'),
                        'type' => 'text',
                        'default' => '',
                        'description' => __('settings_field_cache_dir_desc')
                    ],
                    'COVER_CACHE_DIR' => [
                        'label' => __('settings_field_cover_cache_dir'),
                        'type' => 'text',
                        'default' => '',
                        'description' => __('settings_field_cover_cache_dir_desc')
                    ],
                    'SCANNER_PATH' => [
                        'label' => __('settings_field_scanner_path'),
                        'type' => 'text',
                        'default' => '',
                        'description' => __('settings_field_scanner_path_desc')
                    ]
                ]
            ],

            'performance' => [
                'title' => __('settings_group_performance'),
                'icon' => 'fa-tachometer-alt',
                'fields' => [
                    'MEMORY_LIMIT' => [
                        'label' => __('settings_field_memory_limit'),
                        'type' => 'text',
                        'default' => '512M',
                        'description' => __('settings_field_memory_limit_desc')
                    ],
                    'MAX_SEARCH_RESULTS' => [
                        'label' => __('settings_field_max_search_results'),
                        'type' => 'number',
                        'default' => 500,
                        'description' => __('settings_field_max_search_results_desc'),
                        'min' => 10,
                        'max' => 5000
                    ]
                ]
            ],

            'security' => [
                'title' => __('settings_group_security'),
                'icon' => 'fa-shield-alt',
                'fields' => [
                    'ADMIN_USER' => [
                        'label' => __('settings_field_admin_user'),
                        'type' => 'text',
                        'default' => 'admin',
                        'description' => __('settings_field_admin_user_desc'),
                        'required' => true,
                        'min_length' => 3
                    ],
                    'ADMIN_PASSWORD_HASH' => [
                        'label' => __('settings_field_admin_password'),
                        'type' => 'password_hash',
                        'default' => '',
                        'description' => __('settings_field_admin_password_desc')
                    ],
                    'ADMIN_ALLOWED_IPS' => [
                        'label' => __('settings_field_admin_allowed_ips'),
                        'type' => 'text',
                        'default' => '127.0.0.1',
                        'description' => __('settings_field_admin_allowed_ips_desc')
                    ]
                ]
            ]
        ];
    }

    /**
     * Получить все настройки
     */
    public function getAll()
    {
        $envSettings = $this->envManager->getAll();

        return [
            'settings' => $envSettings,
            'groups' => $this->groups,
            'current' => [
                'db_type' => Config::getDbType(),
                'books_dir' => Config::getBooksDir(),
                'cache_dir' => Config::getCacheDir(),
                'cover_cache_dir' => Config::getCoverCacheDir(),
                'scanner_path' => Config::getScannerPath(),
                'cache_enabled' => Config::isCacheEnabled(),
                'use_apcu' => Config::isUseApcu()
            ]
        ];
    }

    /**
     * Получить значение настройки
     */
    public function get($key, $default = null)
    {
        return $this->envManager->get($key, $default);
    }

    /**
     * Сохранить настройки
     */
    public function saveSettings($post)
    {
        error_log("=== SAVE SETTINGS ===");

        // 1. Сначала получаем текущие настройки
        $currentEnv = $this->envManager->getAll();

        // 2. Собираем новые настройки из POST
        $newSettings = $this->collectSettingsFromPost($post);

        // 3. ВАЖНО: Сохраняем пароль БД если он не был изменен
        if (empty($post['DB_PASS']) || $post['DB_PASS'] === '********') {
            if (isset($currentEnv['DB_PASS'])) {
                $newSettings['DB_PASS'] = $currentEnv['DB_PASS'];
                error_log("Keeping existing DB password");
            }
        } else {
            error_log("Updating DB password");
            // Пароль уже в $newSettings из POST
        }

        // 4. Валидируем
        $errors = $this->validator->validate($newSettings, $this->groups);
        if (!empty($errors)) {
            throw new Exception(implode("\n", $errors));
        }

        // 5. Проверяем пути
        $pathErrors = $this->validator->validatePaths($newSettings);
        if (!empty($pathErrors)) {
            throw new Exception(implode("\n", $pathErrors));
        }

        // 6. Обрабатываем пароль администратора
        $newSettings = $this->processPassword($newSettings, $post);

        // 7. Сохраняем
        $result = $this->envManager->save($newSettings);

        // 8. Очищаем кэш
        $this->clearConfigCache();

        return $result;
    }

    /**
     * Собрать настройки из POST
     */
    private function collectSettingsFromPost($post)
    {
        $settings = [];

        // Обновляем из POST
        foreach ($this->groups as $group) {
            foreach ($group['fields'] as $fieldKey => $field) {
                if (isset($post[$fieldKey])) {
                    $value = $post[$fieldKey];

                    switch ($field['type']) {
                        case 'checkbox':
                            $value = ($value === 'on' || $value === '1') ? 'true' : 'false';
                            break;
                        case 'number':
                            $value = (int)$value;
                            break;
                        default:
                            $value = trim($value);
                    }

                    $settings[$fieldKey] = $value;
                } elseif ($field['type'] === 'checkbox') {
                    $settings[$fieldKey] = 'false';
                }
            }
        }

        return $settings;
    }

    /**
     * Обработать пароль администратора
     */
    private function processPassword($settings, $post)
    {
        // Если пароль не меняем - оставляем существующий
        if (empty($post['new_password'])) {
            $existing = $this->envManager->get('ADMIN_PASSWORD_HASH');
            if ($existing) {
                $settings['ADMIN_PASSWORD_HASH'] = $existing;
            }
            return $settings;
        }

        // Проверяем совпадение
        if ($post['new_password'] !== $post['confirm_password']) {
            throw new Exception(__('settings_passwords_dont_match'));
        }

        // Проверяем длину
        if (strlen($post['new_password']) < 6) {
            throw new Exception(__('settings_password_length'));
        }

        // Создаём хэш
        $settings['ADMIN_PASSWORD_HASH'] = password_hash($post['new_password'], PASSWORD_DEFAULT);

        return $settings;
    }

    /**
     * Получить список бэкапов
     */
    public function getBackups()
    {
        return $this->backupManager->getBackups();
    }

    /**
     * Восстановить из бэкапа
     */
    public function restoreBackup($filename)
    {
        $envFile = __DIR__ . '/../config/.env';

        // Создаём бэкап текущего файла
        $this->backupManager->createBackup($envFile);

        // Восстанавливаем
        $result = $this->backupManager->restore($filename, $envFile);

        if ($result) {
            $this->envManager->reset();
            $this->clearConfigCache();
        }

        return $result;
    }

    /**
     * Проверить подключение к БД
     */
    public function testDatabaseConnection($settings)
    {
        return $this->validator->testDatabaseConnection($settings);
    }

    /**
     * Очистить кэш конфигурации
     */
    private function clearConfigCache()
    {
        if (function_exists('apcu_delete')) {
            apcu_delete('ENV_LOADER_CACHE');
        }

        // Сбрасываем EnvLoader если он есть
        if (class_exists('EnvLoader') && method_exists('EnvLoader', 'reset')) {
            EnvLoader::reset();
        }
    }
}
