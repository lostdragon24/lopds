<?php

// lib/DatabaseChecker.php

require_once __DIR__ . '/../init.php';

class DatabaseChecker
{
    private static $instance = null;
    private $dbAvailable = null;
    private $tablesExist = null;
    private $errorMessage = null;
    private $checkResults = [];
    private $cachedStatus = null;
    private $cacheTime = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Проверить доступность базы данных И наличие таблиц
     */
    public function checkDatabase($checkTables = true)
    {
        if ($this->dbAvailable !== null && $this->tablesExist !== null) {
            return $this->dbAvailable && ($checkTables ? $this->tablesExist : true);
        }

        $this->checkResults = [];
        $dbConfig = Config::getDbConfig();

        try {
            $this->checkResults['config'] = $dbConfig;

            switch ($dbConfig['type']) {
                case 'sqlite':
                    $result = $this->checkSqliteDatabase($dbConfig['path']);
                    $this->dbAvailable = $result['available'];
                    $this->tablesExist = $result['tables_exist'];
                    $this->checkResults['sqlite'] = $result;
                    break;

                case 'mysql':
                    $result = $this->checkMysqlDatabase($dbConfig);
                    $this->dbAvailable = $result['available'];
                    $this->tablesExist = $result['tables_exist'];
                    $this->checkResults['mysql'] = $result;
                    break;

                default:
                    $this->dbAvailable = false;
                    $this->tablesExist = false;
                    $this->errorMessage = sprintf(__('db_checker_unsupported_type'), $dbConfig['type']);
            }

        } catch (Exception $e) {
            $this->dbAvailable = false;
            $this->tablesExist = false;
            $this->errorMessage = $e->getMessage();
            $this->checkResults['error'] = $e->getMessage();
        }

        return $this->dbAvailable && ($checkTables ? $this->tablesExist : true);
    }

    /**
     * Проверить SQLite базу данных
     */
    private function checkSqliteDatabase($path)
    {
        $result = [
            'available' => false,
            'tables_exist' => false,
            'file_exists' => file_exists($path),
            'file_readable' => false,
            'file_writable' => false,
            'file_size' => 0,
            'tables_found' => [],
            'journal_mode' => null,
            'page_size' => null,
            'encoding' => null
        ];

        // Проверяем существование файла
        if (!file_exists($path)) {
            $result['error'] = __('db_checker_sqlite_file_not_found');
            $this->errorMessage = sprintf(__('db_checker_sqlite_file_not_found_path'), $path);
            return $result;
        }

        $result['file_size'] = filesize($path);
        $result['file_readable'] = is_readable($path);
        $result['file_writable'] = is_writable($path);

        if (!$result['file_readable']) {
            $result['error'] = __('db_checker_sqlite_file_not_readable');
            $this->errorMessage = sprintf(__('db_checker_sqlite_file_not_readable_path'), $path);
            return $result;
        }

        // Пробуем открыть базу данных
        try {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);

            // Получаем информацию о базе данных
            $stmt = $pdo->query("PRAGMA journal_mode");
            $result['journal_mode'] = $stmt->fetchColumn();

            $stmt = $pdo->query("PRAGMA page_size");
            $result['page_size'] = $stmt->fetchColumn();

            $stmt = $pdo->query("PRAGMA encoding");
            $result['encoding'] = $stmt->fetchColumn();

            // Проверяем наличие основных таблиц
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $result['tables_found'] = $tables;

            // Проверяем наличие необходимых таблиц
            $requiredTables = ['books', 'book_ratings', 'book_favorites', 'archives'];
            $existingTables = array_intersect($requiredTables, $tables);

            $result['tables_exist'] = in_array('books', $tables) && !empty($existingTables);
            $result['available'] = true;
            $result['required_tables_found'] = $existingTables;
            $result['required_tables_missing'] = array_diff($requiredTables, $existingTables);

            // Дополнительная проверка - можем ли мы писать
            if ($result['file_writable']) {
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS _test (id INTEGER)");
                    $pdo->exec("DROP TABLE IF EXISTS _test");
                    $result['write_test'] = true;
                } catch (Exception $e) {
                    $result['write_test'] = false;
                    $result['write_test_error'] = $e->getMessage();
                }
            }

        } catch (PDOException $e) {
            $result['error'] = $e->getMessage();
            $this->errorMessage = __('db_checker_sqlite_error') . ': ' . $e->getMessage();
            $result['available'] = false;
        }

        return $result;
    }

    /**
     * Проверить MySQL базу данных
     */
    private function checkMysqlDatabase($config)
    {
        $result = [
            'available' => false,
            'tables_exist' => false,
            'db_exists' => false,
            'tables_found' => [],
            'server_version' => null,
            'connection_test' => false
        ];

        try {
            // Сначала проверяем подключение к серверу
            $dsn = 'mysql:host=' . $config['host'] .
                   (isset($config['port']) ? ';port=' . $config['port'] : '') .
                   ';charset=utf8mb4';

            $pdo = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5
            ]);

            $result['connection_test'] = true;

            // Получаем версию сервера
            $stmt = $pdo->query("SELECT VERSION()");
            $result['server_version'] = $stmt->fetchColumn();

            // Проверяем существование базы данных
            $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA 
                                 WHERE SCHEMA_NAME = " . $pdo->quote($config['name']));
            $result['db_exists'] = $stmt->fetch() !== false;

            if (!$result['db_exists']) {
                $result['error'] = __('db_checker_mysql_db_not_exists');
                $this->errorMessage = sprintf(__('db_checker_mysql_db_not_exists_path'), $config['name']);
                $result['available'] = true; // Сервер доступен, БД будет создана
                return $result;
            }

            // Подключаемся к конкретной базе данных
            $pdo = new PDO($dsn . ';dbname=' . $config['name'], $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5
            ]);

            // Получаем список таблиц
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $result['tables_found'] = $tables;

            // Проверяем наличие необходимых таблиц
            $requiredTables = ['books', 'book_ratings', 'book_favorites', 'archives'];
            $existingTables = array_intersect($requiredTables, $tables);

            $result['tables_exist'] = in_array('books', $tables) && !empty($existingTables);
            $result['available'] = true;
            $result['required_tables_found'] = $existingTables;
            $result['required_tables_missing'] = array_diff($requiredTables, $existingTables);

            // Проверяем права пользователя
            $stmt = $pdo->query("SHOW GRANTS");
            $grants = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $result['grants'] = $grants;

        } catch (PDOException $e) {
            $result['error'] = $e->getMessage();
            $this->errorMessage = __('db_checker_mysql_error') . ': ' . $e->getMessage();
            $result['available'] = false;
        }

        return $result;
    }

    /**
     * Получить детальную информацию о состоянии
     */
    public function getDetailedStatus($force = false)
    {

        if (!$force && $this->cachedStatus !== null && $this->cacheTime > time() - 600) {
            return $this->cachedStatus;
        }
        $this->checkDatabase(false);

        $status = [
            'database_available' => $this->dbAvailable,
            'tables_exist' => $this->tablesExist,
            'ready' => $this->dbAvailable && $this->tablesExist,
            'error' => $this->errorMessage,
            'check_results' => $this->checkResults,
            'status_text' => '',
            'status_class' => ''
        ];

        // Добавляем информацию о том, какой шаг не пройден
        if (!$this->dbAvailable) {
            $status['status'] = 'database_unavailable';
            $status['status_text'] = __('db_checker_status_unavailable');
            $status['status_class'] = 'danger';
            $status['message'] = __('db_checker_message_unavailable');
        } elseif (!$this->tablesExist) {
            $status['status'] = 'tables_missing';
            $status['status_text'] = __('db_checker_status_tables_missing');
            $status['status_class'] = 'warning';
            $status['message'] = __('db_checker_message_tables_missing');
        } else {
            $status['status'] = 'ready';
            $status['status_text'] = __('db_checker_status_ready');
            $status['status_class'] = 'success';
            $status['message'] = __('db_checker_message_ready');
        }


        $this->cachedStatus = $status;
        $this->cacheTime = time();

        return $status;
    }

    /**
     * Получить сообщение об ошибке
     */
    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    /**
     * Проверить, существует ли таблица books
     */
    public function hasBooksTable()
    {
        if (!$this->dbAvailable) {
            return false;
        }

        $dbConfig = Config::getDbConfig();

        try {
            if ($dbConfig['type'] === 'sqlite') {
                $pdo = new PDO('sqlite:' . $dbConfig['path']);
                $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='books'");
                return $result->fetch() !== false;
            } else {
                $dsn = 'mysql:host=' . $dbConfig['host'] . ';dbname=' . $dbConfig['name'] . ';charset=utf8mb4';
                $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass']);
                $result = $pdo->query("SHOW TABLES LIKE 'books'");
                return $result->fetch() !== false;
            }
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Получить версию базы данных
     */
    public function getDatabaseVersion()
    {
        if (!$this->dbAvailable) {
            return null;
        }

        $dbConfig = Config::getDbConfig();

        try {
            if ($dbConfig['type'] === 'sqlite') {
                $pdo = new PDO('sqlite:' . $dbConfig['path']);
                return $pdo->query('SELECT sqlite_version()')->fetchColumn();
            } else {
                $dsn = 'mysql:host=' . $dbConfig['host'] . ';dbname=' . $dbConfig['name'] . ';charset=utf8mb4';
                $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass']);
                return $pdo->query('SELECT VERSION()')->fetchColumn();
            }
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Сбросить кэш конфигурации (для тестов)
     */
    public static function resetConfig()
    {
        // Сбрасываем статические переменные
        $reflection = new ReflectionClass('Config');

        // Сбрасываем env
        $envProperty = $reflection->getProperty('env');
        $envProperty->setAccessible(true);
        $envProperty->setValue(null);

        $envLoadedProperty = $reflection->getProperty('envLoaded');
        $envLoadedProperty->setAccessible(true);
        $envLoadedProperty->setValue(false);

        // Сбрасываем пути
        $booksDirProperty = $reflection->getProperty('booksDir');
        $booksDirProperty->setAccessible(true);
        $booksDirProperty->setValue(null);

        $dbPathProperty = $reflection->getProperty('dbPath');
        $dbPathProperty->setAccessible(true);
        $dbPathProperty->setValue(null);

        // Принудительно перезагружаем
        Config::init();
    }
}
