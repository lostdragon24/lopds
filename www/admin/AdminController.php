<?php

// admin/AdminController.php

require_once __DIR__ . '/../define.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Cache.php';
require_once __DIR__ . '/../lib/ScannerManager.php';
require_once __DIR__ . '/../lib/GenreManager.php';
require_once __DIR__ . '/../lib/EnvLoader.php';

// Подключаем классы админки
require_once __DIR__ . '/DashboardWidgets.php';
require_once __DIR__ . '/BookManager.php';
require_once __DIR__ . '/SettingsManager.php';
require_once __DIR__ . '/DatabaseManager.php';
require_once __DIR__ . '/LogManager.php';

class AdminController
{
    private $db;
    private $scanner;
    private $bookManager;
    private $settingsManager;
    private $databaseManager;
    private $logManager;
    private $widgets;

    public function __construct()
    {
        // Убеждаемся что сессия запущена с правильными параметрами
        $this->initSession();

        $this->db = Database::getInstance();
        $this->scanner = new ScannerManager();
        $this->bookManager = new BookManager($this->db);
        $this->settingsManager = new SettingsManager();
        $this->databaseManager = new DatabaseManager($this->db);
        $this->logManager = new LogManager();
        $this->widgets = new DashboardWidgets($this->db, $this->scanner);
    }

    /**
     * Инициализация сессии с правильными параметрами
     */
    private function initSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Устанавливаем параметры сессии ДО её старта
            $cookieParams = session_get_cookie_params();
            session_set_cookie_params(
                3600, // lifetime
                '/', // path - корень сайта
                '', // domain
                false, // secure (только для HTTPS)
                true // httponly
            );

            session_name('ADMIN_SESSION');
            session_start();
        }
    }

    public function handleRequest()
    {
        // Определяем действие
        $action = $_GET['action'] ?? 'dashboard';
        $postAction = $_POST['action'] ?? '';

        my_log("=== HANDLE REQUEST ===");
        my_log("Session ID: " . session_id());
        my_log("Action: " . $action);
        my_log("POST Action: " . $postAction);

        // Публичные действия (не требуют авторизации)
        $publicActions = ['login', 'do_login', 'debug_session', 'log_download'];

        // Проверяем авторизацию для всех действий, кроме публичных
        if (!in_array($action, $publicActions) && !in_array($postAction, $publicActions)) {
            if (!$this->checkAuth()) {
                $this->showLogin();
                return;
            }
        }

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            // AJAX запросы обрабатываются в отдельном файле
            // Просто возвращаем управление
            return;
        }


        // Обработка POST запросов
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($postAction)) {
            $this->handlePost($postAction);
            return;
        }

        // Маршрутизация GET запросов
        switch ($action) {
            case 'dashboard':
                $this->showDashboard();
                break;
            case 'books':
                $page = $_GET['page'] ?? 1;
                $this->showBooks($page);
                break;
            case 'book_edit':
                $id = $_GET['id'] ?? null;
                $this->showBookEdit($id);
                break;
            case 'scanner':
                $this->showScanner();
                break;
            case 'settings':
                $this->showSettings();
                break;
            case 'database':
                $this->showDatabase();
                break;
            case 'logs':
                $this->showLogs();
                break;
            case 'log_download':
                $this->handleLogDownload();
                break;
            case 'login':
                $this->showLogin();
                break;
            case 'logout':
                $this->logout();
                break;
            case 'debug_session':
                $this->debugSession();
                break;
            case 'browse_table':
                $table = $_GET['table'] ?? '';
                $this->browseTable($table);
                break;
            case 'author_deduplicate':
                $this->showAuthorDeduplicate();
                break;
            case 'library_backup':
                $this->showLibraryBackup();
                break;
            case 'about_site':
                $this->showAbout();
                break;

            default:
                $this->showDashboard();
        }
    }

    private function handlePost($action)
    {
        my_log("=== HANDLE POST DEBUG ===");
        my_log("Action: " . $action);
        my_log("POST data: " . print_r($_POST, true));
        my_log("Session CSRF: " . ($_SESSION['csrf_token'] ?? 'not set'));
        my_log("POST CSRF: " . ($_POST['csrf_token'] ?? 'not set'));

        // ВАЖНО: проверяем CSRF для всех действий кроме login
        if ($action !== 'do_login') {
            $csrfToken = $_POST['csrf_token'] ?? '';
            if (empty($csrfToken)) {
                my_log("CSRF token missing in POST");
                $_SESSION['message'] = __('admin_error_csrf_missing');
                $_SESSION['message_type'] = 'danger';
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '?action=dashboard'));
                exit;
            }

            if (!Config::validateCsrfToken($csrfToken)) {
                my_log("CSRF validation failed. Token: $csrfToken");
                $_SESSION['message'] = __('admin_error_csrf_invalid');
                $_SESSION['message_type'] = 'danger';
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '?action=dashboard'));
                exit;
            }

            my_log("CSRF validation passed");
        }

        switch ($action) {
            case 'do_login':
                $this->doLogin($_POST);
                break;

            case 'book_save':
                try {
                    // Получаем загруженный файл
                    $file = $_FILES['book_file'] ?? null;

                    // Дополнительная проверка для файлов
                    if ($file && $file['error'] === UPLOAD_ERR_OK) {
                        // Проверяем MIME-тип (для дополнительной безопасности)
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mimeType = finfo_file($finfo, $file['tmp_name']);
                        finfo_close($finfo);

                        $allowedMimes = [
                            'fb2' => ['application/xml', 'text/xml', 'application/x-fictionbook+xml'],
                            'epub' => ['application/epub+zip', 'application/zip'],
                            'pdf' => ['application/pdf'],
                            'txt' => ['text/plain']
                        ];

                        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                        $isValid = false;

                        if (isset($allowedMimes[$extension])) {
                            foreach ($allowedMimes[$extension] as $allowedMime) {
                                if (strpos($mimeType, $allowedMime) !== false) {
                                    $isValid = true;
                                    break;
                                }
                            }
                        }

                        // Для ZIP-архивов (EPUB) дополнительная проверка
                        if ($extension === 'epub' && $mimeType === 'application/zip') {
                            $isValid = true;
                        }

                        if (!$isValid) {
                            throw new Exception(sprintf(__('admin_book_file_invalid_mime'), $mimeType));
                        }
                    }

                    // Вызываем saveBook с файлом
                    if ($this->bookManager->saveBook($_POST, $file)) {
                        $_SESSION['message'] = __('admin_books_edit_success');
                        $_SESSION['message_type'] = 'success';
                    }
                } catch (Exception $e) {
                    $_SESSION['message'] = __('admin_books_edit_error') . ': ' . $e->getMessage();
                    $_SESSION['message_type'] = 'danger';

                    // Сохраняем ошибку загрузки в сессию для отображения в форме
                    if (empty($_POST['id'])) {
                        $_SESSION['upload_error'] = $e->getMessage();
                        header('Location: ?action=book_edit');
                        exit;
                    }
                }
                header('Location: ?action=books');
                break;


            case 'book_delete':
                if ($this->bookManager->deleteBook($_POST['id'] ?? 0)) {
                    $_SESSION['message'] = __('admin_books_delete_success');
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = __('admin_books_delete_error');
                    $_SESSION['message_type'] = 'danger';
                }
                header('Location: ?action=books');
                break;

            case 'book_bulk':
                if ($this->bookManager->bulkAction($_POST)) {
                    $_SESSION['message'] = __('admin_books_bulk_success');
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = __('admin_books_bulk_error');
                    $_SESSION['message_type'] = 'danger';
                }
                header('Location: ?action=books');
                break;

            case 'scanner_start':
                $mode = $_POST['mode'] ?? 'normal';
                my_log("Scanner start requested, mode from POST: " . $mode);
                $background = isset($_POST['background']) && $_POST['background'] == '1';

                try {
                    if ($mode === 'inpx') {
                        // Используем отдельный метод для INPX
                        $result = $this->scanner->importInpx();
                    } else {
                        $result = $this->scanner->start($background, $mode);
                    }

                    $_SESSION['scanner_message'] = $result['message'];
                    if (isset($result['pid'])) {
                        $_SESSION['scanner_message'] .= " (PID: " . $result['pid'] . ")";
                    }
                } catch (Exception $e) {
                    $_SESSION['scanner_error'] = $e->getMessage();
                    my_log("Scanner start error: " . $e->getMessage());
                }
                header('Location: ?action=scanner');
                break;

            case 'scanner_stop':
                $result = $this->scanner->stop();
                $_SESSION['scanner_message'] = $result['message'];
                header('Location: ?action=scanner');
                break;

            case 'scanner_import_inpx':
                try {
                    $result = $this->scanner->importInpx();
                    $_SESSION['scanner_message'] = $result['message'] ?? __('admin_scanner_inpx_import_completed');
                } catch (Exception $e) {
                    $_SESSION['scanner_error'] = $e->getMessage();
                }
                header('Location: ?action=scanner');
                break;

            case 'scanner_clear_log':
                $this->scanner->clearLog();
                $_SESSION['scanner_message'] = __('admin_scanner_log_cleared');
                header('Location: ?action=scanner');
                break;

            case 'settings_save':
                try {
                    if ($this->settingsManager->saveSettings($_POST)) {
                        $_SESSION['message'] = __('admin_settings_saved');
                        $_SESSION['message_type'] = 'success';
                    }
                } catch (Exception $e) {
                    $_SESSION['message'] = __('admin_settings_save_error') . ': ' . $e->getMessage();
                    $_SESSION['message_type'] = 'danger';
                }
                header('Location: ?action=settings');
                break;

            case 'database_backup':
                $result = $this->databaseManager->createBackup();
                if ($result['success']) {
                    $_SESSION['message'] = sprintf(
                        __('admin_db_backup_created'),
                        $result['filename'],
                        $result['size_formatted']
                    );
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = sprintf(__('admin_db_backup_error'), $result['message']);
                    $_SESSION['message_type'] = 'danger';
                }
                header('Location: ?action=database');
                break;

            case 'database_optimize':
                $result = $this->databaseManager->optimize();
                if ($result['success']) {
                    $msg = __('admin_db_optimized');
                    if (isset($result['results']['saved_formatted'])) {
                        $msg .= ' ' . sprintf(__('admin_db_optimized_freed'), $result['results']['saved_formatted']);
                    }
                    $_SESSION['message'] = $msg;
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = sprintf(__('admin_db_optimize_error'), $result['message']);
                    $_SESSION['message_type'] = 'danger';
                }
                header('Location: ?action=database');
                break;

            case 'database_check':
                $result = $this->databaseManager->checkIntegrity();
                if ($result['success']) {
                    $msg = __('admin_db_integrity_ok');
                    if (isset($result['results']['integrity'])) {
                        $msg .= ' ' . $result['results']['integrity'];
                    }
                    $_SESSION['message'] = $msg;
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = sprintf(__('admin_db_integrity_error'), $result['message']);
                    $_SESSION['message_type'] = 'danger';
                }
                header('Location: ?action=database');
                break;

            case 'database_restore':
                $filename = $_POST['backup_file'] ?? '';
                if (empty($filename)) {
                    $_SESSION['message'] = __('admin_error_missing_params');
                    $_SESSION['message_type'] = 'danger';
                } else {
                    $result = $this->databaseManager->restoreBackup($filename);
                    if ($result['success']) {
                        $_SESSION['message'] = __('admin_db_restore_success');
                        $_SESSION['message_type'] = 'success';
                    } else {
                        $_SESSION['message'] = sprintf(__('admin_db_restore_error'), $result['message']);
                        $_SESSION['message_type'] = 'danger';
                    }
                }
                header('Location: ?action=database');
                break;

            case 'database_delete_backup':
                $filename = $_POST['backup_file'] ?? '';
                if (empty($filename)) {
                    $_SESSION['message'] = __('admin_error_missing_params');
                    $_SESSION['message_type'] = 'danger';
                } else {
                    $backupFile = Config::getBasePath() . '/backups/database/' . basename($filename);
                    if (file_exists($backupFile) && unlink($backupFile)) {
                        $_SESSION['message'] = __('admin_db_backup_deleted');
                        $_SESSION['message_type'] = 'success';
                    } else {
                        $_SESSION['message'] = __('admin_db_backup_delete_error');
                        $_SESSION['message_type'] = 'danger';
                    }
                }
                header('Location: ?action=database');
                break;

            case 'database_browse_table':
                $table = $_POST['table'] ?? $_GET['table'] ?? '';
                if (empty($table)) {
                    $_SESSION['message'] = __('admin_error_missing_params');
                    $_SESSION['message_type'] = 'danger';
                    header('Location: ?action=database');
                } else {
                    header('Location: ?action=browse_table&table=' . urlencode($table));
                }
                break;
            case 'library_backup_create':
                $this->createLibraryBackup();
                break;

            case 'library_backup_restore':
                $this->restoreLibraryBackup();
                break;

            case 'library_backup_delete':
                $this->deleteLibraryBackup();
                break;

            case 'library_backup_download':
                $this->downloadLibraryBackup();
                break;

            case 'log_clear':
                try {
                    $logType = $_POST['log_type'] ?? '';
                    if (empty($logType)) {
                        throw new Exception(__('admin_error_missing_params'));
                    }

                    if ($this->logManager->clearLog($logType)) {
                        $_SESSION['message'] = __('log_cleared');
                        $_SESSION['message_type'] = 'success';
                    }
                } catch (Exception $e) {
                    $_SESSION['message'] = $e->getMessage();
                    $_SESSION['message_type'] = 'danger';
                }
                header('Location: ?action=logs');
                break;

            case 'log_download':
                try {
                    $logType = $_GET['download'] ?? '';
                    if (empty($logType)) {
                        throw new Exception(__('admin_error_missing_params'));
                    }

                    // ВАЖНО: скачивание должно завершить скрипт, а не делать редирект
                    $this->logManager->downloadLog($logType);
                    exit; // Явный выход, хотя downloadLog сам делает exit
                } catch (Exception $e) {
                    $_SESSION['message'] = $e->getMessage();
                    $_SESSION['message_type'] = 'danger';
                    header('Location: ?action=logs');
                    exit;
                }
                break;

            default:
                my_log("Unknown POST action: " . $action);
                header('Location: ?action=dashboard');
        }
        exit;
    }

    // Добавляем отдельный метод для скачивания
    private function handleLogDownload()
    {
        try {
            $logType = $_GET['download'] ?? '';
            if (empty($logType)) {
                throw new Exception(__('admin_error_missing_params'));
            }

            // Проверяем авторизацию (для безопасности)
            if (!$this->checkAuth()) {
                throw new Exception(__('admin_error_access_denied'));
            }

            $this->logManager->downloadLog($logType);
            exit;
        } catch (Exception $e) {
            $_SESSION['message'] = $e->getMessage();
            $_SESSION['message_type'] = 'danger';
            header('Location: ?action=logs');
            exit;
        }
    }



    private function checkAuth()
    {
        $isLogged = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
        my_log("checkAuth() - Result: " . ($isLogged ? 'true' : 'false'));
        return $isLogged;
    }

    private function doLogin($post)
    {
        my_log("=== DO LOGIN ===");

        $username = $post['username'] ?? '';
        $password = $post['password'] ?? '';

        $adminUser = EnvLoader::get('ADMIN_USER', 'admin');
        $adminHash = EnvLoader::get('ADMIN_PASSWORD_HASH', '');

        if ($username === $adminUser && password_verify($password, $adminHash)) {
            // Успешный вход
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user'] = $username;

            // Регенерируем ID сессии для безопасности
            session_regenerate_id(true);

            my_log("Login successful. Session: " . print_r($_SESSION, true));

            header('Location: ?action=dashboard');
            exit;
        } else {
            my_log("Login failed for user: " . $username);
            $_SESSION['login_error'] = __('login_error');
            header('Location: ?action=login');
            exit;
        }
    }

    private function logout()
    {
        unset($_SESSION['admin_logged_in']);
        session_destroy();
        header('Location: ?action=login');
        exit;
    }

    private function showLogin()
    {
        $error = $_SESSION['login_error'] ?? '';
        unset($_SESSION['login_error']);

        // Генерируем CSRF токен для формы входа
        $csrfToken = Config::getCsrfToken();

        $this->render('login', [
            'error' => $error,
            'csrf_token' => $csrfToken
        ]);
    }

    private function debugSession()
    {
        echo "<pre>";
        echo "Session ID: " . session_id() . "\n";
        echo "Session data: ";
        print_r($_SESSION);
        echo "\n\n";
        echo "POST data: ";
        print_r($_POST);
        echo "\n\n";
        echo "GET data: ";
        print_r($_GET);
        echo "</pre>";

        echo '<br><a href="?action=login">' . __('back_to_list') . '</a>';
        exit;
    }

    private function showDashboard()
    {
        $data = [
            'stats' => $this->widgets->getStatistics(),
            'system' => $this->widgets->getSystemInfo(),
            'recent_books' => $this->widgets->getRecentBooks(10),
            'scanner_status' => $this->widgets->getScannerStatus(),
            'cache_stats' => $this->widgets->getCacheStats(),
            'csrf_token' => Config::getCsrfToken()
        ];

        $this->render('dashboard', $data);
    }

    private function showBooks($page = 1)
    {
        $filters = $_GET['filter'] ?? [];

        try {
            $genresList = $this->bookManager->getAllGenres();
            $fileTypesList = $this->bookManager->getAllFileTypes();
            $authorsList = $this->bookManager->getAllAuthors();
        } catch (Exception $e) {
            my_log("Error loading filter data: " . $e->getMessage());
            $genresList = [];
            $fileTypesList = [];
            $authorsList = [];
        }

        $data = [
            'books' => $this->bookManager->getBooks($page, 20, $filters),
            'total' => $this->bookManager->getTotalBooks($filters),
            'page' => $page,
            'filter' => $filters,
            'genresList' => $genresList,
            'fileTypesList' => $fileTypesList,
            'authorsList' => $authorsList,
            'message' => $_SESSION['message'] ?? '',
            'message_type' => $_SESSION['message_type'] ?? '',
            'csrf_token' => Config::getCsrfToken()
        ];

        unset($_SESSION['message'], $_SESSION['message_type']);

        $this->render('books', $data);
    }


    private function showBookEdit($id)
    {
        if ($id) {
            $book = $this->bookManager->getBook($id);
        } else {
            $book = null;

            // Проверяем права на запись в директорию книг
            $booksDir = Config::getBooksDir();
            if (!is_writable($booksDir)) {
                $_SESSION['message'] = sprintf(__('admin_book_dir_not_writable'), $booksDir);
                $_SESSION['message_type'] = 'warning';
            }
        }

        $data = [
            'book' => $book,
            'genres' => GenreManager::getAllGenres(),
            'action' => $id ? 'edit' : 'add',
            'csrf_token' => Config::getCsrfToken(),
            'upload_error' => $_SESSION['upload_error'] ?? ''
        ];

        unset($_SESSION['upload_error']);

        $this->render('book_edit', $data);
    }

    // admin/AdminController.php

    private function showScanner()
    {
        // Получаем статус
        $status = $this->scanner->getStatus();

        // Получаем информацию о сканере (включая версию)
        $scannerInfo = $this->scanner->getScannerInfo();

        // Получаем статистику отдельно
        $stats = [];
        try {
            $stats = $this->scanner->getStats();
        } catch (Exception $e) {
            my_log("Error getting scanner stats: " . $e->getMessage());
        }

        // Объединяем данные
        $viewData = array_merge($status, [
            'stats' => $stats,
            'scanner_info' => $scannerInfo
        ]);

        // Получаем сообщения из сессии
        $message = $_SESSION['scanner_message'] ?? '';
        $error = $_SESSION['scanner_error'] ?? '';
        unset($_SESSION['scanner_message'], $_SESSION['scanner_error']);

        // Получаем CSRF токен
        $csrf_token = Config::getCsrfToken();

        // Проверяем наличие INPX файла
        $hasInpx = $this->scanner->hasInpxFile();

        $data = [
            'status' => $viewData,
            'message' => $message,
            'error' => $error,
            'csrf_token' => $csrf_token,
            'hasInpx' => $hasInpx,
            'scanner' => $this->scanner,
            'scanner_info' => $scannerInfo  // Добавляем отдельно для шаблона
        ];

        $this->render('scanner', $data);
    }

    private function showSettings()
    {
        $data = [
            'settingsData' => $this->settingsManager->getAll(),
            'backups' => $this->settingsManager->getBackups(),
            'message' => $_SESSION['message'] ?? '',
            'message_type' => $_SESSION['message_type'] ?? '',
            'csrf_token' => Config::getCsrfToken()
        ];
        unset($_SESSION['message'], $_SESSION['message_type']);

        $this->render('settings', $data);
    }

    private function showDatabase()
    {
        $data = [
            'info' => $this->databaseManager->getInfo(),
            'tables' => $this->databaseManager->getTables(),
            'backups' => $this->databaseManager->getBackups(),
            'cache_stats' => Cache::getStats(),
            'message' => $_SESSION['message'] ?? '',
            'message_type' => $_SESSION['message_type'] ?? '',
            'csrf_token' => Config::getCsrfToken()
        ];
        unset($_SESSION['message'], $_SESSION['message_type']);

        $this->render('database', $data);
    }

    private function showLogs()
    {
        $data = [
            'php_log' => $this->logManager->getPhpLog(100),
            'scanner_log' => $this->logManager->getScannerLog(100),
            'system_log' => $this->logManager->getSystemLog(100),
            'csrf_token' => Config::getCsrfToken()
        ];

        $this->render('logs', $data);
    }

    private function render($template, $data = [])
    {
        extract($data);

        // Подключаем шапку
        require __DIR__ . '/../templates/admin/layout/header.php';

        // Подключаем основной шаблон
        $templateFile = __DIR__ . '/../templates/admin/' . $template . '.php';
        if (file_exists($templateFile)) {
            require $templateFile;
        } else {
            echo "<div class='alert alert-danger'>" . __('admin_template_not_found') . " $template</div>";
        }

        // Подключаем подвал
        require __DIR__ . '/../templates/admin/layout/footer.php';
    }


    private function showLibraryBackup()
    {
        $backupManager = new LibraryBackupManager();

        $data = [
            'backups' => $backupManager->getBackups(),
            'library_size' => $backupManager->getLibrarySizeFormatted(),
            'can_backup' => $backupManager->canBackup(),
            'backup_stats' => $backupManager->getBackupStats(),
            'csrf_token' => Config::getCsrfToken(),
            'message' => $_SESSION['message'] ?? '',
            'message_type' => $_SESSION['message_type'] ?? ''
        ];

        unset($_SESSION['message'], $_SESSION['message_type']);

        $this->render('library_backup', $data);
    }

    private function createLibraryBackup()
    {
        try {
            $backupManager = new LibraryBackupManager();
            $result = $backupManager->createBackup();

            if ($result['success']) {
                $_SESSION['message'] = $result['message'] . ': ' . $result['filename'] . ' (' . $result['size_formatted'] . ')';
                $_SESSION['message_type'] = 'success';
            }
        } catch (Exception $e) {
            $_SESSION['message'] = $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }

        header('Location: ?action=library_backup');
        exit;
    }

    private function restoreLibraryBackup()
    {
        try {
            $filename = $_POST['backup_file'] ?? '';

            if (empty($filename)) {
                throw new Exception(__('admin_error_missing_params'));
            }

            $backupManager = new LibraryBackupManager();
            $result = $backupManager->restoreBackup($filename);

            if ($result['success']) {
                $_SESSION['message'] = $result['message'];
                $_SESSION['message_type'] = 'success';
            }
        } catch (Exception $e) {
            $_SESSION['message'] = $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }

        header('Location: ?action=library_backup');
        exit;
    }

    private function deleteLibraryBackup()
    {
        try {
            $filename = $_POST['backup_file'] ?? '';

            if (empty($filename)) {
                throw new Exception(__('admin_error_missing_params'));
            }

            $backupManager = new LibraryBackupManager();
            $result = $backupManager->deleteBackup($filename);

            if ($result['success']) {
                $_SESSION['message'] = $result['message'];
                $_SESSION['message_type'] = 'success';
            }
        } catch (Exception $e) {
            $_SESSION['message'] = $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }

        header('Location: ?action=library_backup');
        exit;
    }

    private function downloadLibraryBackup()
    {
        try {
            $filename = $_GET['file'] ?? '';

            if (empty($filename)) {
                throw new Exception(__('admin_error_missing_params'));
            }

            $backupManager = new LibraryBackupManager();
            $backupManager->downloadBackup($filename);

        } catch (Exception $e) {
            $_SESSION['message'] = $e->getMessage();
            $_SESSION['message_type'] = 'danger';
            header('Location: ?action=library_backup');
        }
        exit;
    }

    private function showAbout()
    {
        require_once __DIR__ . '/AboutManager.php';
        $aboutManager = new AboutManager();

        // Получаем статистику для отображения
        try {
            $db = Database::getInstance();
            $stats = $db->getCollectionStats();
        } catch (Exception $e) {
            $stats = ['total_books' => 0, 'total_authors' => 0, 'total_genres' => 0];
        }

        $data = [
            'about' => $aboutManager->getAppInfo(),
            'changelog' => $aboutManager->getChangelog(),
            'credits' => $aboutManager->getCredits(),
            'requirements' => $aboutManager->getSystemRequirements(),
            'stats' => $stats,
            'csrf_token' => Config::getCsrfToken()
        ];

        $this->render('about', $data);
    }

    private function showAuthorDeduplicate()
    {
        require_once __DIR__ . '/AuthorDeduplicator.php';
        $deduplicator = new AuthorDeduplicator();

        $threshold = isset($_GET['threshold']) ? (int)$_GET['threshold'] : 70;
        $threshold = max(50, min(95, $threshold));
        $thresholdFloat = $threshold / 100;

        $groups = [];
        $stats = $deduplicator->getAuthorStats();
        $history = $deduplicator->getMergeHistory();
        $mergeCount = 0;

        // Автоматическое объединение
        //if (isset($_GET['auto_merge']) && $_GET['auto_merge'] == 1) {
        //    $result = $deduplicator->autoMergeAll($thresholdFloat);
        //    if ($result['success']) {
        //        $mergeCount = $result['merged'];
        //        $_SESSION['message'] = sprintf(
        //            __('author_deduplicate_auto_success'),
        //            $result['merged'],
        //            $result['groups_processed']
        //        );
        //        $_SESSION['message_type'] = 'success';
        //    } else {
        //        $_SESSION['message'] = __('author_deduplicate_auto_error') . ': ' . implode('; ', $result['errors']);
        //        $_SESSION['message_type'] = 'danger';
        //    }
        //    header('Location: ?action=author_deduplicate&threshold=' . $threshold);
        //    exit;
        //}

        // Обработка ручного объединения
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'author_merge') {
            $main = $_POST['main_author'] ?? '';
            $duplicate = $_POST['duplicate_author'] ?? '';

            if (!empty($main) && !empty($duplicate)) {
                $result = $deduplicator->mergeAuthors($main, $duplicate);
                if ($result['success']) {
                    $_SESSION['message'] = $result['message'];
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = $result['message'];
                    $_SESSION['message_type'] = 'danger';
                }
                header('Location: ?action=author_deduplicate&threshold=' . $threshold);
                exit;
            }
        }

        // Поиск групп
        if (isset($_GET['threshold']) || isset($_GET['auto_merge'])) {
            $result = $deduplicator->findSimilarAuthors($thresholdFloat);
            $groups = $result['groups'];

            // Обновляем статистику
            $stats['groups_found'] = $result['stats']['groups_found'];
            $stats['comparisons'] = $result['stats']['comparisons'];
            $stats['time'] = $result['stats']['time'];
        }

        $data = [
            'groups' => $groups,
            'stats' => $stats,
            'history' => $history,
            'threshold' => $threshold,
            'mergeCount' => $mergeCount,
            'message' => $_SESSION['message'] ?? '',
            'message_type' => $_SESSION['message_type'] ?? '',
            'csrf_token' => Config::getCsrfToken()
        ];

        unset($_SESSION['message'], $_SESSION['message_type']);

        $this->render('author_deduplicate', $data);
    }


    private function browseTable($tableName)
    {
        // Проверяем разрешенные таблицы
        $allowedTables = ['books', 'book_ratings', 'book_favorites', 'archives', 'bookmarks', 'reading_history', 'bookmark_tags', 'bookmarks_fts', 'bookmarks_fts_config', 'bookmarks_fts_data', 'bookmarks_fts_docsize', 'bookmarks_fts_idx', 'books_fts', 'books_fts_config', 'books_fts_data', 'books_fts_docsize', 'books_fts_idx'];



        if (!in_array($tableName, $allowedTables)) {
            $_SESSION['message'] = __('admin_error_access_denied');
            $_SESSION['message_type'] = 'danger';
            header('Location: ?action=database');
            return;
        }

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        try {
            $pdo = $this->db->getConnection();

            // Получаем общее количество записей
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$tableName`");
            $total = $stmt->fetchColumn();

            // Получаем данные с LIMIT
            $stmt = $pdo->prepare("SELECT * FROM `$tableName` LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Получаем названия колонок из первой строки (если есть данные)
            $columnNames = [];
            if (!empty($rows)) {
                $columnNames = array_keys($rows[0]);
            } else {
                // Если нет данных, получаем структуру таблицы
                if ($this->db->getDbType() === 'sqlite') {
                    $stmt = $pdo->query("PRAGMA table_info(\"$tableName\")");
                    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $columnNames = array_column($columns, 'name');
                } else {
                    $stmt = $pdo->query("DESCRIBE `$tableName`");
                    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $columnNames = array_column($columns, 'Field');
                }
            }

            // Формируем данные для шаблона
            $data = [
                'table_name' => $tableName,
                'rows' => $rows,
                'columns' => $columnNames,
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => ceil($total / $perPage),
                'csrf_token' => Config::getCsrfToken(),
                'debug' => [
                    'has_rows' => !empty($rows),
                    'column_count' => count($columnNames),
                    'row_count' => count($rows)
                ]
            ];

            $this->render('browse_table', $data);

        } catch (Exception $e) {
            my_log("Error browsing table: " . $e->getMessage());
            $_SESSION['message'] = __('admin_error_database') . ': ' . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
            header('Location: ?action=database');
        }
    }
}
