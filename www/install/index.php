<?php

// /install/index.php

// Определяем константу, что мы в режиме установки
define('INSTALL_MODE', true);

// Подключаем основные классы
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../init.php';

// Сессия уже запущена в config.php, но убедимся
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_log("Session ID before any processing: " . session_id());
error_log("Session data before: " . print_r($_SESSION, true));

// ПОЛУЧАЕМ ТЕКУЩИЙ ШАГ ИЗ GET ИЛИ POST
$step = isset($_POST['step']) ? (int)$_POST['step'] : (isset($_GET['step']) ? (int)$_GET['step'] : 1);

// Подключаем заглушку для Database (для всех шагов)
require_once __DIR__ . '/includes/database_stub.php';

require_once __DIR__ . '/../lib/ScannerManager.php';
require_once __DIR__ . '/../lib/DatabaseChecker.php';

// Подключаем файлы установщика
require_once __DIR__ . '/config/steps.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/scanner.php';
require_once __DIR__ . '/includes/paths.php';

$error = null;
$success = null;
$warning = null;

// Создаем экземпляр сканера
$scanner = new ScannerManager();

// ОТЛАДКА: выводим содержимое сессии в лог
error_log("=== INSTALL DEBUG ===");
error_log("Step: " . $step);
error_log("Session ID: " . session_id());
error_log("Session data: " . print_r($_SESSION, true));
error_log("POST data: " . print_r($_POST, true));

// Функция для проверки директорий (если еще не определена)
if (!function_exists('checkDirectories')) {
    function checkDirectories()
    {
        $dirs = [
            'data' => [
                'path' => Config::getBasePath() . '/data',
                'required' => true,
                'description' => __('install_dir_data')
            ],
            'cache' => [
                'path' => Config::getCacheDir(),
                'required' => true,
                'description' => __('install_dir_cache')
            ],
            'books' => [
                'path' => Config::getBooksDir(),
                'required' => false,
                'description' => __('install_dir_books')
            ],
            'scanner' => [
                'path' => dirname(Config::getScannerPath()),
                'required' => false,
                'description' => __('install_dir_scanner')
            ]
        ];

        foreach ($dirs as $key => &$dir) {
            $dir['exists'] = file_exists($dir['path']);
            $dir['writable'] = is_writable($dir['path']);
            $dir['readable'] = is_readable($dir['path']);

            if ($dir['exists']) {
                $dir['perms'] = substr(sprintf('%o', fileperms($dir['path'])), -4);
                $dir['owner'] = function_exists('posix_getpwuid') ?
                    posix_getpwuid(fileowner($dir['path']))['name'] : 'N/A';
            }
        }

        return $dirs;
    }
    error_log("Added temporary checkDirectories() function");
}

// Обработка POST запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $handler = getPostHandler($_POST['action'] ?? '');

    if ($handler) {
        error_log("Found handler: " . $handler);
        $result = $handler($_POST);

        // Для AJAX-запросов возвращаем JSON
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {

            header('Content-Type: application/json');

            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => $result['message'],
                    'redirect' => $result['redirect'] ?? 'index.php?step=' . ($step + 1) . '&success=1'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $result['message']
                ]);
            }
            exit;
        }

        // Для обычных POST-запросов
        if ($result['success']) {
            if (isset($result['redirect'])) {
                error_log("Redirecting to: " . $result['redirect']);
                header('Location: ' . $result['redirect']);
                exit;
            }
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } else {
        error_log("No handler found for action: " . ($_POST['action'] ?? 'none'));
    }
}

// Проверяем успешные действия
if (isset($_GET['success']) && $_GET['success'] == 1) {
    if ($step == 3 && isset($_SESSION['db_config'])) {
        $success = __('install_success_connection');
        error_log("SUCCESS: db_config found in session");
    } elseif ($step == 4 && isset($_SESSION['db_created'])) {
        $success = __('install_success_db_created');
        error_log("SUCCESS: database created");
    } elseif ($step == 5 && isset($_SESSION['admin_created'])) {
        $success = __('install_success_admin_created');
        error_log("SUCCESS: admin created");
    } elseif ($step == 6) {
        $success = __('install_success_complete');
        error_log("SUCCESS: installation complete");
    }
}

// Подключаем шапку
require_once __DIR__ . '/templates/header.php';

// Подключаем соответствующий шаг
$stepFile = __DIR__ . '/templates/step' . $step . '.php';
if (file_exists($stepFile)) {
    require_once $stepFile;
} else {
    echo '<div class="alert alert-danger">' . __('install_step_not_found') . '</div>';
}

// Подключаем подвал
require_once __DIR__ . '/templates/footer.php';
