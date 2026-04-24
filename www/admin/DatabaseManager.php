<?php

// admin/DatabaseManager.php

class DatabaseManager
{
    private $db;
    private $backupDir;
    private $dbType;

    public function __construct($db)
    {
        $this->db = $db;
        $this->backupDir = Config::getBasePath() . '/backups/database';
        $this->dbType = Config::getDbType();

        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * Получить подробную информацию о БД
     */
    public function getInfo()
    {
        $dbConfig = Config::getDbConfig();

        $info = [
            'type' => $this->dbType,
            'size' => 0,
            'tables_count' => 0,
            'version' => '',
            'path' => '',
            'status' => 'active',
            'status_text' => __('admin_db_status_active')
        ];

        try {
            if ($this->dbType === 'sqlite') {
                $info['path'] = $dbConfig['path'];
                $info['size'] = file_exists($info['path']) ? filesize($info['path']) : 0;
                $info['version'] = $this->db->getConnection()->query('SELECT sqlite_version()')->fetchColumn();

                // Количество таблиц
                $stmt = $this->db->getConnection()->query(
                    "SELECT COUNT(*) as count FROM sqlite_master WHERE type='table'"
                );
                $info['tables_count'] = $stmt->fetchColumn();

                // Размер WAL файла если есть
                $walFile = $info['path'] . '-wal';
                if (file_exists($walFile)) {
                    $info['wal_size'] = filesize($walFile);
                }

                // Информация о журнале
                $stmt = $this->db->getConnection()->query("PRAGMA journal_mode");
                $info['journal_mode'] = $stmt->fetchColumn();

            } else { // MySQL
                $stmt = $this->db->getConnection()->query("SELECT VERSION() as version");
                $info['version'] = $stmt->fetchColumn();

                $stmt = $this->db->getConnection()->query("SHOW TABLE STATUS");
                $tables = $stmt->fetchAll();
                $info['tables_count'] = count($tables);

                foreach ($tables as $table) {
                    $info['size'] += $table['Data_length'] + $table['Index_length'];
                }

                // Информация о сервере
                $stmt = $this->db->getConnection()->query("SHOW VARIABLES LIKE 'max_allowed_packet'");
                $info['max_allowed_packet'] = $stmt->fetchColumn(1);

                $stmt = $this->db->getConnection()->query("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'");
                $info['buffer_pool'] = $stmt->fetchColumn(1);
            }

            // Проверка доступности
            $info['is_writable'] = $this->isWritable();
            $info['is_readable'] = $this->isReadable();
            $info['writable_text'] = $info['is_writable'] ? __('admin_db_writable_yes') : __('admin_db_writable_no');
            $info['readable_text'] = $info['is_readable'] ? __('admin_db_readable_yes') : __('admin_db_readable_no');

        } catch (Exception $e) {
            error_log("Error getting DB info: " . $e->getMessage());
            $info['status'] = 'error';
            $info['status_text'] = __('admin_db_status_error');
            $info['error'] = $e->getMessage();
        }

        return $info;
    }

    /**
     * Получить список таблиц с деталями
     */
    public function getTables()
    {
        $tables = [];

        try {
            if ($this->dbType === 'sqlite') {
    // Получаем список таблиц
    $stmt = $this->db->getConnection()->query(
        "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
    );
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $name = $row['name'];

        // Пропускаем системные таблицы SQLite
        if (strpos($name, 'sqlite_') === 0) {
            continue;
        }

        // Количество записей
        $stmt2 = $this->db->getConnection()->prepare("SELECT COUNT(*) as count FROM \"$name\"");
        $stmt2->execute();
        $count = $stmt2->fetchColumn();

        // Размер таблицы - получаем через анализ страниц (более точный метод)
        $size = $this->getTableSizeSQLite($name);

        // Информация об индексах
        $stmt2 = $this->db->getConnection()->prepare(
            "SELECT COUNT(*) as idx_count FROM sqlite_master WHERE type='index' AND tbl_name = ?"
        );
        $stmt2->execute([$name]);
        $indexes = $stmt2->fetchColumn();

        $tables[] = [
            'name' => $name,
            'rows' => $count,
            'size' => $size,
            'indexes' => $indexes,
            'engine' => 'SQLite'
        ];
    }

            } else {
                $stmt = $this->db->getConnection()->query("SHOW TABLE STATUS");
                while ($row = $stmt->fetch()) {
                    $tables[] = [
                        'name' => $row['Name'],
                        'rows' => $row['Rows'],
                        'size' => $row['Data_length'] + $row['Index_length'],
                        'data_size' => $row['Data_length'],
                        'index_size' => $row['Index_length'],
                        'engine' => $row['Engine'],
                        'collation' => $row['Collation'] ?? 'utf8mb4_unicode_ci',
                        'auto_increment' => $row['Auto_increment'] ?? null,
                        'create_time' => $row['Create_time'] ?? null,
                        'update_time' => $row['Update_time'] ?? null
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("Error getting tables: " . $e->getMessage());
        }

        return $tables;
    }

    /**
 * Получить размер SQLite таблицы в байтах
 */
private function getTableSizeSQLite($tableName)
{
    try {
        // Метод 1: Через анализ страниц базы данных (более точный для SQLite)
        $stmt = $this->db->getConnection()->prepare(
            "SELECT SUM(pgsize) as size
             FROM dbstat
             WHERE name = ? AND aggregate = TRUE"
        );
        $stmt->execute([$tableName]);
        $result = $stmt->fetchColumn();

        if ($result !== false && $result !== null) {
            return (int)$result;
        }
    } catch (Exception $e) {
        // Таблица dbstat может быть недоступна, пробуем альтернативный метод
    }

    try {
        // Метод 2: Приблизительный размер через LENGTH всех полей
        // Получаем список колонок таблицы
        $stmt = $this->db->getConnection()->prepare("PRAGMA table_info(\"$tableName\")");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($columns)) {
            return 0;
        }

        // Строим запрос для вычисления суммы длин всех полей
        $lengthParts = [];
        foreach ($columns as $column) {
            $colName = $column['name'];
            $lengthParts[] = "LENGTH(IFNULL(\"$colName\", ''))";
        }

        $lengthExpr = implode(' + ', $lengthParts);

        $stmt = $this->db->getConnection()->prepare(
            "SELECT SUM($lengthExpr) as total_size FROM \"$tableName\""
        );
        $stmt->execute();
        $size = $stmt->fetchColumn();

        return $size ? (int)$size : 0;

    } catch (Exception $e) {
        error_log("Error calculating table size for $tableName: " . $e->getMessage());
        return 0;
    }
}



    /**
     * Создать бэкап базы данных
     */
    public function createBackup()
    {
        $filename = $this->backupDir . '/backup_' . date('Y-m-d_His') . '.';

        try {
            if ($this->dbType === 'sqlite') {
                $filename .= 'db';
                $this->createSqliteBackup($filename);
                $message = __('admin_db_backup_sqlite_created');
            } else {
                $filename .= 'sql';
                $this->createMysqlDump($filename);
                $message = __('admin_db_backup_mysql_created');
            }

            // Сжимаем если нужно
            if (filesize($filename) > 1024 * 1024) { // больше 1MB
                $this->compressBackup($filename);
                $filename .= '.gz';
                $message .= ' ' . __('admin_db_backup_compressed');
            }

            // Удаляем старые бэкапы
            $this->cleanupOldBackups();

            $size = filesize($filename);

            return [
                'success' => true,
                'filename' => basename($filename),
                'size' => $size,
                'size_formatted' => $this->formatBytes($size),
                'message' => $message
            ];

        } catch (Exception $e) {
            error_log("Backup failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => __('admin_db_backup_failed') . ': ' . $e->getMessage()
            ];
        }
    }

    /**
     * Создать SQLite бэкап
     */
    private function createSqliteBackup($filename)
    {
        $dbConfig = Config::getDbConfig();
        $sourceFile = $dbConfig['path'];

        if (!file_exists($sourceFile)) {
            throw new Exception(__('admin_db_backup_source_not_found'));
        }

        if (!copy($sourceFile, $filename)) {
            throw new Exception(__('admin_db_backup_copy_failed'));
        }

        // Копируем WAL файл если есть
        $walFile = $sourceFile . '-wal';
        if (file_exists($walFile)) {
            copy($walFile, $filename . '-wal');
        }
    }

    /**
     * Создать MySQL дамп
     */
    private function createMysqlDump($filename)
    {
        $dbConfig = Config::getDbConfig();

        $handle = fopen($filename, 'w');
        if (!$handle) {
            throw new Exception(__('admin_db_backup_create_failed'));
        }

        fwrite($handle, "-- MySQL dump created at " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Database: " . $dbConfig['name'] . "\n");
        fwrite($handle, "-- PHP Version: " . PHP_VERSION . "\n\n");

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
        fwrite($handle, "SET time_zone = '+00:00';\n\n");

        // Получаем список таблиц
        $tables = $this->getTables();

        foreach ($tables as $table) {
            $this->dumpTableToFile($handle, $table['name']);
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }

    /**
     * Создать дамп одной таблицы
     */
    private function dumpTableToFile($handle, $tableName)
    {
        fwrite($handle, "\n-- Table structure for `$tableName`\n");
        fwrite($handle, "DROP TABLE IF EXISTS `$tableName`;\n");

        // Получаем CREATE TABLE
        $stmt = $this->db->getConnection()->query("SHOW CREATE TABLE `$tableName`");
        $row = $stmt->fetch();
        fwrite($handle, $row['Create Table'] . ";\n\n");

        // Получаем количество записей
        $stmt = $this->db->getConnection()->query("SELECT COUNT(*) as count FROM `$tableName`");
        $totalRows = $stmt->fetchColumn();

        if ($totalRows == 0) {
            return;
        }

        fwrite($handle, "-- Dumping data for `$tableName` ($totalRows rows)\n");

        // Выгружаем данные порциями по 1000 записей
        $offset = 0;
        $limit = 1000;

        while ($offset < $totalRows) {
            $stmt = $this->db->getConnection()->prepare("SELECT * FROM `$tableName` LIMIT ? OFFSET ?");
            $stmt->execute([$limit, $offset]);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);

            if (!empty($rows)) {
                if ($offset == 0) {
                    fwrite($handle, "INSERT INTO `$tableName` VALUES \n");
                }

                $values = [];
                foreach ($rows as $row) {
                    $escaped = array_map(function ($val) use ($handle) {
                        if ($val === null) {
                            return 'NULL';
                        }
                        return "'" . addslashes($val) . "'";
                    }, $row);
                    $values[] = "(" . implode(', ', $escaped) . ")";
                }

                if ($offset + $limit >= $totalRows) {
                    // Последняя порция
                    fwrite($handle, implode(",\n", $values) . ";\n\n");
                } else {
                    // Не последняя - добавляем запятую в конце
                    fwrite($handle, implode(",\n", $values) . ",\n");
                }
            }

            $offset += $limit;

            // Очищаем память
            unset($rows);
            $this->db->getConnection()->query("DO 1"); // Держим соединение живым
        }
    }

    /**
     * Сжать бэкап
     */
    private function compressBackup($filename)
    {
        $data = file_get_contents($filename);
        $compressed = gzencode($data, 9);
        file_put_contents($filename . '.gz', $compressed);
        unlink($filename);
    }

    /**
     * Удалить старые бэкапы
     */
    private function cleanupOldBackups($keep = 10)
    {
        $backups = glob($this->backupDir . '/backup_*');
        if (count($backups) <= $keep) {
            return;
        }

        // Сортируем по времени создания
        usort($backups, function ($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        // Удаляем старые
        $toDelete = array_slice($backups, 0, count($backups) - $keep);
        foreach ($toDelete as $file) {
            unlink($file);
        }
    }

    /**
     * Получить список бэкапов
     */
    public function getBackups()
    {
        $files = glob($this->backupDir . '/backup_*');
        $backups = [];

        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'size_formatted' => $this->formatBytes(filesize($file)),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'timestamp' => filemtime($file),
                'type' => pathinfo($file, PATHINFO_EXTENSION)
            ];
        }

        // Сортируем по дате (новые сверху)
        usort($backups, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        return $backups;
    }

    /**
     * Восстановить из бэкапа
     */
    public function restoreBackup($filename)
    {
        $backupFile = $this->backupDir . '/' . basename($filename);

        if (!file_exists($backupFile)) {
            return ['success' => false, 'message' => __('admin_db_restore_file_not_found')];
        }

        try {
            if ($this->dbType === 'sqlite') {
                return $this->restoreSqliteBackup($backupFile);
            } else {
                return $this->restoreMysqlBackup($backupFile);
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => __('admin_db_restore_failed') . ': ' . $e->getMessage()];
        }
    }

    /**
     * Восстановить SQLite из бэкапа
     */
    private function restoreSqliteBackup($backupFile)
    {
        $dbConfig = Config::getDbConfig();
        $targetFile = $dbConfig['path'];

        // Создаём бэкап текущей БД
        $this->createBackup();

        // Закрываем соединение
        $this->db = null;

        // Копируем файл
        if (!copy($backupFile, $targetFile)) {
            throw new Exception(__('admin_db_restore_copy_failed'));
        }

        // Восстанавливаем WAL если есть
        $walFile = $backupFile . '-wal';
        if (file_exists($walFile)) {
            copy($walFile, $targetFile . '-wal');
        }

        return ['success' => true, 'message' => __('admin_db_restore_success')];
    }

    /**
     * Восстановить MySQL из бэкапа
     */
    private function restoreMysqlBackup($backupFile)
    {
        $dbConfig = Config::getDbConfig();

        // Распаковываем если сжато
        if (pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz') {
            $gz = gzopen($backupFile, 'rb');
            $sql = '';
            while (!gzeof($gz)) {
                $sql .= gzgets($gz, 4096);
            }
            gzclose($gz);
        } else {
            $sql = file_get_contents($backupFile);
        }

        // Выполняем SQL
        $pdo = $this->db->getConnection();
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $pdo->exec($sql);
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

        return ['success' => true, 'message' => __('admin_db_restore_success')];
    }

    /**
     * Оптимизировать базу данных
     */
    public function optimize()
    {
        $results = [];

        try {
            if ($this->dbType === 'sqlite') {
                // VACUUM
                $start = microtime(true);
                $this->db->getConnection()->exec("VACUUM");
                $results['vacuum_time'] = round(microtime(true) - $start, 2);

                // PRAGMA optimize
                $this->db->getConnection()->exec("PRAGMA optimize");

                // Анализ
                $this->db->getConnection()->exec("ANALYZE");

                $before = $this->getInfo()['size'];
                $after = filesize(Config::getDbConfig()['path']);

                $results['before'] = $before;
                $results['after'] = $after;
                $results['saved'] = $before - $after;
                $results['saved_formatted'] = $this->formatBytes($before - $after);

            } else {
                $tables = $this->getTables();
                foreach ($tables as $table) {
                    $start = microtime(true);
                    $this->db->getConnection()->exec("OPTIMIZE TABLE `{$table['name']}`");
                    $results[$table['name']] = round(microtime(true) - $start, 2);
                }

                // Анализ
                $this->db->getConnection()->exec("ANALYZE TABLE " . implode(', ', array_column($tables, 'name')));
            }

            return [
                'success' => true,
                'message' => __('admin_db_optimize_success'),
                'results' => $results
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => __('admin_db_optimize_failed') . ': ' . $e->getMessage()
            ];
        }
    }

    /**
     * Проверить целостность базы данных
     */
    public function checkIntegrity()
    {
        $results = [];

        try {
            if ($this->dbType === 'sqlite') {
                $stmt = $this->db->getConnection()->query("PRAGMA integrity_check");
                $results['integrity'] = $stmt->fetchColumn();

                $stmt = $this->db->getConnection()->query("PRAGMA foreign_key_check");
                $results['foreign_keys'] = $stmt->fetchAll();

                $status = $results['integrity'] === 'ok' ? __('admin_db_integrity_ok') : __('admin_db_integrity_error');

            } else {
                $tables = $this->getTables();
                $allOk = true;
                foreach ($tables as $table) {
                    $stmt = $this->db->getConnection()->query("CHECK TABLE `{$table['name']}`");
                    $row = $stmt->fetch();
                    $results[$table['name']] = $row['Msg_text'];
                    if ($row['Msg_text'] !== 'OK') {
                        $allOk = false;
                    }
                }
                $status = $allOk ? __('admin_db_integrity_ok') : __('admin_db_integrity_warning');
            }

            return [
                'success' => true,
                'message' => $status,
                'results' => $results
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => __('admin_db_integrity_failed') . ': ' . $e->getMessage()
            ];
        }
    }

    /**
     * Проверить доступность для записи
     */
    private function isWritable()
    {
        if ($this->dbType === 'sqlite') {
            $dbConfig = Config::getDbConfig();
            $dir = dirname($dbConfig['path']);
            return is_writable($dir) && (!file_exists($dbConfig['path']) || is_writable($dbConfig['path']));
        } else {
            // Для MySQL всегда true если есть соединение
            return true;
        }
    }

    /**
     * Проверить доступность для чтения
     */
    private function isReadable()
    {
        if ($this->dbType === 'sqlite') {
            $dbConfig = Config::getDbConfig();
            return !file_exists($dbConfig['path']) || is_readable($dbConfig['path']);
        }
        return true;
    }

    /**
     * Форматировать размер
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Получить детальную информацию о конкретной таблице
     */
    public function getTableInfo($tableName)
    {
        $info = [];

        try {
            if ($this->dbType === 'sqlite') {
                // Структура таблицы
                $stmt = $this->db->getConnection()->query("PRAGMA table_info(\"$tableName\")");
                $info['columns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Индексы
                $stmt = $this->db->getConnection()->query("PRAGMA index_list(\"$tableName\")");
                $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($indexes as &$index) {
                    $stmt = $this->db->getConnection()->query("PRAGMA index_info(\"{$index['name']}\")");
                    $index['columns'] = implode(', ', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name'));
                }
                $info['indexes'] = $indexes;

                // Внешние ключи
                $stmt = $this->db->getConnection()->query("PRAGMA foreign_key_list(\"$tableName\")");
                $info['foreign_keys'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            } else {
                // Структура таблицы для MySQL
                $stmt = $this->db->getConnection()->query("DESCRIBE `$tableName`");
                $info['columns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Индексы для MySQL
                $stmt = $this->db->getConnection()->query("SHOW INDEX FROM `$tableName`");
                $info['indexes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Внешние ключи для MySQL
                $stmt = $this->db->getConnection()->prepare(
                    "SELECT * FROM information_schema.KEY_COLUMN_USAGE 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = ? 
                     AND REFERENCED_TABLE_NAME IS NOT NULL"
                );
                $stmt->execute([$tableName]);
                $info['foreign_keys'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Error getting table info: " . $e->getMessage());
            $info['error'] = $e->getMessage();
        }

        return $info;
    }
}
