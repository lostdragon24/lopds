<?php

// /install/includes/functions.php

/**
 * Сохранить конфигурацию в .env файл.
 */
function handleSaveEnv($post)
{
    try {
        $type = $_SESSION['db_config']['type'] ?? 'sqlite';
        $paths = $_SESSION['paths'] ?? [];

        $envContent = '; '.__('install_config_file_header')."\n";
        $envContent .= '; '.sprintf(__('install_config_file_created'), date('Y-m-d H:i:s'))."\n\n";

        $envContent .= 'DB_TYPE = '.$type."\n\n";

        if ('mysql' === $type) {
            $envContent .= 'DB_HOST = '.($_SESSION['db_config']['host'] ?? 'localhost')."\n";
            $envContent .= 'DB_NAME = '.($_SESSION['db_config']['database'] ?? 'library')."\n";
            $envContent .= 'DB_USER = '.($_SESSION['db_config']['user'] ?? '')."\n";
            $envContent .= 'DB_PASS = '.($_SESSION['db_config']['password'] ?? '')."\n";
        } else {
            $envContent .= 'DB_PATH = '.($_SESSION['db_config']['path'] ?? Config::getDbPath())."\n";
        }

        $envContent .= "\nBOOKS_DIR = ".($paths['books_dir'] ?? Config::getBooksDir())."\n";

        if (!empty($paths['scanner_path'])) {
            $envContent .= 'SCANNER_PATH = '.$paths['scanner_path']."\n";
        }

        $envContent .= 'CACHE_DIR = '.Config::getCacheDir()."\n";

        // Добавляем пароль администратора если есть
        if (isset($_SESSION['admin_password_set']) && file_exists(__DIR__.'/../../config/.env')) {
            $existingEnv = file_get_contents(__DIR__.'/../../config/.env');
            if (preg_match('/ADMIN_PASSWORD_HASH\s*=\s*(.+)/', $existingEnv, $matches)) {
                $envContent .= "\nADMIN_PASSWORD_HASH = ".trim($matches[1])."\n";
                $envContent .= "ADMIN_USER = admin\n";
            }
        }

        $envFile = __DIR__.'/../../config/.env';

        // Создаем бэкап если файл существует
        if (file_exists($envFile)) {
            copy($envFile, $envFile.'.backup.'.date('YmdHis'));
        }

        file_put_contents($envFile, $envContent);
        chmod($envFile, 0600);

        $_SESSION['env_saved'] = true;

        return ['success' => true, 'message' => __('install_config_saved')];
    } catch (Exception $e) {
        return ['success' => false, 'message' => __('install_error').': '.$e->getMessage()];
    }
}

/**
 * Создать необходимые директории.
 */
function handleCreateDirectories($post)
{
    $created = [];
    $errors = [];

    $dirs = [
        Config::getBasePath().'/data',
        Config::getCacheDir(),
        Config::getCoverCacheDir(),
        dirname(Config::getScannerPath()),
    ];

    foreach ($dirs as $dir) {
        if (!file_exists($dir)) {
            if (@mkdir($dir, 0755, true)) {
                $created[] = $dir;
            } else {
                $errors[] = __('install_error_create_dir').': '.$dir;
            }
        }
    }

    if (empty($errors)) {
        return [
            'success' => true,
            'message' => __('install_directories_created')."\n".implode("\n", $created),
        ];
    }

    return [
        'success' => false,
        'message' => __('install_errors')."\n".implode("\n", $errors),
    ];
}

/**
 * Исправить права на директории.
 */
function handleFixPermissions($post)
{
    $fixed = [];
    $errors = [];

    $dirs = [
        Config::getBasePath().'/data' => 0755,
        Config::getCacheDir() => 0755,
        Config::getCoverCacheDir() => 0755,
        dirname(Config::getScannerPath()) => 0755,
    ];

    foreach ($dirs as $dir => $perms) {
        if (file_exists($dir)) {
            if (@chmod($dir, $perms)) {
                $fixed[] = "$dir -> ".decoct($perms);
            } else {
                $errors[] = __('install_error_fix_perms').': '.$dir;
            }
        }
    }

    if (empty($errors)) {
        return [
            'success' => true,
            'message' => __('install_permissions_fixed')."\n".implode("\n", $fixed),
        ];
    }

    return [
        'success' => false,
        'message' => __('install_errors')."\n".implode("\n", $errors),
    ];
}

/**
 * Сохранить пароль администратора.
 */
function handleSaveAdminPassword($post)
{
    try {
        $password = $post['admin_password'] ?? '';
        $confirm = $post['confirm_password'] ?? '';

        if (empty($password)) {
            throw new Exception(__('install_error_password_empty'));
        }

        if ($password !== $confirm) {
            throw new Exception(__('install_error_passwords_dont_match'));
        }

        if (strlen($password) < 6) {
            throw new Exception(__('install_error_password_length'));
        }

        // Создаем хеш пароля
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Загружаем текущий .env
        $envFile = __DIR__.'/../../config/.env';
        $envContent = file_exists($envFile) ? file_get_contents($envFile) : '';

        // Обновляем или добавляем ADMIN_PASSWORD_HASH
        if (preg_match('/^ADMIN_PASSWORD_HASH\s*=\s*.*$/m', $envContent)) {
            $envContent = preg_replace(
                '/^ADMIN_PASSWORD_HASH\s*=\s*.*$/m',
                'ADMIN_PASSWORD_HASH = '.$hash,
                $envContent
            );
        } else {
            $envContent .= "\n; ".__('install_admin_hash_comment')."\n";
            $envContent .= 'ADMIN_PASSWORD_HASH = '.$hash."\n";
        }

        // Добавляем ADMIN_USER если его нет
        if (!preg_match('/^ADMIN_USER\s*=\s*.*$/m', $envContent)) {
            $envContent .= "ADMIN_USER = admin\n";
        }

        file_put_contents($envFile, $envContent);
        chmod($envFile, 0600);

        $_SESSION['admin_password_set'] = true;
        $_SESSION['just_installed'] = true;

        return [
            'success' => true,
            'message' => __('install_admin_saved'),
            'redirect' => 'index.php?step=6',
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => __('install_error').': '.$e->getMessage(),
        ];
    }
}

/**
 * Сохранить пути.
 */
function handleSavePaths($post)
{
    error_log('=== handleSavePaths called ===');
    error_log('POST data: '.print_r($post, true));

    $_SESSION['paths'] = [
        'books_dir' => $post['books_dir'],
        'scanner_path' => $post['scanner_path'],
        'cache_dir' => $post['cache_dir'] ?? Config::getCacheDir(),
    ];

    // Сохраняем в .env
    $envFile = __DIR__.'/../../config/.env';
    $envContent = file_exists($envFile) ? file_get_contents($envFile) : '';

    $updates = [
        'BOOKS_DIR' => $post['books_dir'],
        'CACHE_DIR' => $post['cache_dir'] ?? Config::getCacheDir(),
    ];

    if (!empty($post['scanner_path'])) {
        $updates['SCANNER_PATH'] = $post['scanner_path'];
    }

    foreach ($updates as $key => $value) {
        if (preg_match('/^'.$key.'\s*=\s*.*$/m', $envContent)) {
            $envContent = preg_replace(
                '/^'.$key.'\s*=\s*.*$/m',
                $key.' = '.$value,
                $envContent
            );
        } else {
            $envContent .= "\n".$key.' = '.$value."\n";
        }
    }

    file_put_contents($envFile, $envContent);
    chmod($envFile, 0600);

    error_log('Session after save: '.print_r($_SESSION, true));
    error_log('Redirecting to step 3');

    return [
        'success' => true,
        'message' => __('install_paths_saved'),
        'redirect' => 'index.php?step=3',
    ];
}

/**
 * Сохранить администратора в .env.
 */
function saveAdminToEnv($user, $hash)
{
    $envFile = __DIR__.'/../../config/.env';

    // Загружаем существующий .env или создаем новый
    $env = [];
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (false !== strpos($line, '=') && 0 !== strpos($line, '#')) {
                list($key, $value) = explode('=', $line, 2);
                $env[trim($key)] = trim($value);
            }
        }
    }

    // Добавляем/обновляем данные администратора
    $env['ADMIN_USER'] = $user;
    $env['ADMIN_PASSWORD_HASH'] = $hash;

    // Добавляем пути из сессии если есть
    if (isset($_SESSION['paths'])) {
        $env['BOOKS_DIR'] = $_SESSION['paths']['books_dir'];
        $env['CACHE_DIR'] = $_SESSION['paths']['cache_dir'] ?? Config::getCacheDir();
        if (!empty($_SESSION['paths']['scanner_path'])) {
            $env['SCANNER_PATH'] = $_SESSION['paths']['scanner_path'];
        }
    }

    // Добавляем настройки БД
    if (isset($_SESSION['db_config'])) {
        $env['DB_TYPE'] = $_SESSION['db_config']['type'];
        if ('sqlite' === $_SESSION['db_config']['type']) {
            $env['DB_PATH'] = $_SESSION['db_config']['path'];
        } else {
            $env['DB_HOST'] = $_SESSION['db_config']['host'];
            $env['DB_NAME'] = $_SESSION['db_config']['database'];
            $env['DB_USER'] = $_SESSION['db_config']['user'];
            $env['DB_PASS'] = $_SESSION['db_config']['password'];
        }
    }

    // Генерируем содержимое
    $content = '; '.__('install_config_file_header')."\n";
    $content .= '; '.sprintf(__('install_config_file_created'), date('Y-m-d H:i:s'))."\n\n";

    foreach ($env as $key => $value) {
        $content .= "$key = $value\n";
    }

    file_put_contents($envFile, $content);
    @chmod($envFile, 0600);
}

/**
 * Сгенерировать финальный конфиг для сканера.
 */
function generateFinalConfig()
{
    $configFile = __DIR__.'/../../config/config.ini';
    $cacheDir = Config::getCacheDir();

    if (!file_exists($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $dbConfig = $_SESSION['db_config'] ?? [];
    $paths = $_SESSION['paths'] ?? [];

    $content = '; '.__('install_scanner_config_header')."\n";
    $content .= '; '.sprintf(__('install_scanner_config_created'), date('Y-m-d H:i:s'))."\n\n";

    $content .= "[database]\n";

    if ('sqlite' === $dbConfig['type']) {
        $content .= "type = sqlite\n";
        $content .= 'path = '.$dbConfig['path']."\n";
    } else {
        $content .= "type = mysql\n";
        $content .= 'host = '.($dbConfig['host'] ?? 'localhost')."\n";
        $content .= 'user = '.($dbConfig['user'] ?? '')."\n";
        $content .= 'password = '.($dbConfig['password'] ?? '')."\n";
        $content .= 'database = '.($dbConfig['database'] ?? 'library')."\n";
    }

    $content .= "\n[scanner]\n";
    $content .= 'books_dir = '.($paths['books_dir'] ?? Config::getBooksDir())."\n";
    $content .= 'log_file = '.$cacheDir."/scanner.log\n";
    $content .= "rescan_unchanged = no\n";
    $content .= "hash_algorithm = md5\n";
    $content .= "log_level = info\n";

    file_put_contents($configFile, $content);
    chmod($configFile, 0600);
}

/**
 * Сохранить данные администратора.
 */
function handleSaveAdmin($post)
{
    try {
        $password = $post['admin_password'] ?? '';
        $confirm = $post['confirm_password'] ?? '';

        if (empty($password)) {
            throw new Exception(__('install_error_password_empty'));
        }

        if ($password !== $confirm) {
            throw new Exception(__('install_error_passwords_dont_match'));
        }

        if (strlen($password) < 6) {
            throw new Exception(__('install_error_password_length'));
        }

        // Хешируем пароль
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Сохраняем в сессию
        $_SESSION['admin'] = [
            'user' => 'admin',
            'hash' => $hash,
        ];

        // Если отмечено "запомнить меня" - сохраняем в .env
        if (isset($post['remember_me'])) {
            saveAdminToEnv('admin', $hash);
        }

        // Генерируем конфиг для сканера
        generateFinalConfig();

        $_SESSION['admin_created'] = true;

        // Принудительно сохраняем сессию
        session_write_close();

        // Формируем URL для редиректа
        $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $basePath = dirname(dirname($_SERVER['SCRIPT_NAME']));
        $redirectUrl = $protocol.$host.$basePath.'/install/index.php?step=6';

        header('Location: '.$redirectUrl);
        exit;
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => __('install_error').': '.$e->getMessage(),
        ];
    }
}

/**
 * Получить обработчик для POST действия.
 */
function getPostHandler($action)
{
    $handlers = [
        'test_connection' => 'handleTestConnection',
        'save_paths' => 'handleSavePaths',
        'create_database' => 'handleCreateDatabase',
        'save_admin' => 'handleSaveAdmin',
        'fix_permissions' => 'handleFixPermissions',
        'create_directories' => 'handleCreateDirectories',
        'run_scanner' => 'handleRunScanner',
        'save_admin_password' => 'handleSaveAdminPassword',
    ];

    return $handlers[$action] ?? null;
}
