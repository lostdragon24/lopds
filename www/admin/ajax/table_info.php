<?php

// admin/ajax/table_info.php

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../lib/Database.php';
require_once __DIR__.'/../DatabaseManager.php';

header('Content-Type: application/json');

// Убеждаемся что сессия запущена
if (PHP_SESSION_NONE === session_status()) {
    session_name('ADMIN_SESSION');
    session_start();
}

// Проверка авторизации
if (!isset($_SESSION['admin_logged_in']) || true !== $_SESSION['admin_logged_in']) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => __('admin_error_access_denied'),
        'code' => 'unauthorized',
        'debug' => [
            'session_id' => session_id(),
            'session_data' => isset($_SESSION['admin_logged_in']) ? 'logged_in='.($_SESSION['admin_logged_in'] ? 'true' : 'false') : 'not_set',
        ],
    ]);
    exit;
}

$table = $_GET['table'] ?? '';
if (empty($table)) {
    echo json_encode([
        'success' => false,
        'message' => __('admin_error_missing_params'),
        'code' => 'missing_table',
    ]);
    exit;
}

// Безопасность: разрешаем только определенные таблицы
$allowedTables = ['books', 'book_ratings', 'book_favorites', 'archives'];
if (!in_array($table, $allowedTables)) {
    echo json_encode([
        'success' => false,
        'message' => __('admin_error_invalid_table'),
        'code' => 'invalid_table',
        'table' => $table,
    ]);
    exit;
}

try {
    $db = Database::getInstance();
    if (!$db->isAvailable()) {
        throw new Exception(__('admin_error_database'));
    }

    $manager = new DatabaseManager($db);
    $info = $manager->getTableInfo($table);

    // Форматируем информацию для отображения
    $formattedInfo = formatTableInfo($info, $table);

    echo json_encode([
        'success' => true,
        'info' => $formattedInfo,
        'table' => $table,
        'message' => sprintf(__('admin_table_info_loaded'), $table),
    ]);
} catch (Exception $e) {
    error_log('Error in table_info.php: '.$e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => __('admin_error_loading').': '.$e->getMessage(),
        'code' => 'error',
        'exception' => $e->getMessage(),
    ]);
}

/**
 * Форматировать информацию о таблице для отображения.
 */
function formatTableInfo($info, $tableName)
{
    $result = [
        'table_name' => $tableName,
        'columns' => [],
        'indexes' => [],
        'foreign_keys' => [],
        'stats' => [],
    ];

    // Форматируем колонки
    if (isset($info['columns']) && is_array($info['columns'])) {
        foreach ($info['columns'] as $col) {
            $result['columns'][] = [
                'name' => $col['name'] ?? $col['Field'] ?? '?',
                'type' => $col['type'] ?? $col['Type'] ?? '?',
                'nullable' => isset($col['notnull']) ? 0 == $col['notnull'] : (($col['Null'] ?? 'YES') === 'YES'),
                'default' => $col['dflt_value'] ?? $col['Default'] ?? null,
                'primary_key' => isset($col['pk']) ? 1 == $col['pk'] : (($col['Key'] ?? '') === 'PRI'),
                'auto_increment' => isset($col['pk']) && 1 == $col['pk'] && false !== strpos($col['type'] ?? '', 'INTEGER'),
                'comment' => $col['comment'] ?? null,
            ];
        }
    }

    // Форматируем индексы
    if (isset($info['indexes']) && is_array($info['indexes'])) {
        $indexesGrouped = [];
        foreach ($info['indexes'] as $idx) {
            $idxName = $idx['name'] ?? $idx['Key_name'] ?? '?';
            if (!isset($indexesGrouped[$idxName])) {
                $indexesGrouped[$idxName] = [
                    'name' => $idxName,
                    'unique' => isset($idx['unique']) ? 1 == $idx['unique'] : (($idx['Non_unique'] ?? 1) == 0),
                    'columns' => [],
                ];
            }
            $indexesGrouped[$idxName]['columns'][] = $idx['columns'] ?? $idx['Column_name'] ?? '?';
        }
        $result['indexes'] = array_values($indexesGrouped);
    }

    // Форматируем внешние ключи
    if (isset($info['foreign_keys']) && is_array($info['foreign_keys'])) {
        foreach ($info['foreign_keys'] as $fk) {
            $result['foreign_keys'][] = [
                'column' => $fk['from'] ?? $fk['COLUMN_NAME'] ?? '?',
                'references_table' => $fk['table'] ?? $fk['REFERENCED_TABLE_NAME'] ?? '?',
                'references_column' => $fk['to'] ?? $fk['REFERENCED_COLUMN_NAME'] ?? '?',
                'on_delete' => $fk['on_delete'] ?? $fk['DELETE_RULE'] ?? 'RESTRICT',
                'on_update' => $fk['on_update'] ?? $fk['UPDATE_RULE'] ?? 'RESTRICT',
            ];
        }
    }

    // Добавляем статистику
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();

        // Количество записей
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$tableName`");
        $result['stats']['row_count'] = (int) $stmt->fetchColumn();

        // Размер таблицы (для SQLite)
        if ('sqlite' === Config::getDbType()) {
            $stmt = $pdo->query("SELECT SUM(length(*)) as size FROM `$tableName`");
            $size = $stmt->fetchColumn();
            $result['stats']['size'] = $size ?: 0;
            $result['stats']['size_formatted'] = formatBytes($size ?: 0);
        } else {
            // Для MySQL
            $stmt = $pdo->query("SHOW TABLE STATUS LIKE '$tableName'");
            $status = $stmt->fetch();
            $result['stats']['size'] = ($status['Data_length'] ?? 0) + ($status['Index_length'] ?? 0);
            $result['stats']['size_formatted'] = formatBytes($result['stats']['size']);
            $result['stats']['data_size'] = $status['Data_length'] ?? 0;
            $result['stats']['index_size'] = $status['Index_length'] ?? 0;
            $result['stats']['engine'] = $status['Engine'] ?? '?';
            $result['stats']['collation'] = $status['Collation'] ?? '?';
            $result['stats']['create_time'] = $status['Create_time'] ?? null;
            $result['stats']['update_time'] = $status['Update_time'] ?? null;
        }

        // Последние изменения
        $stmt = $pdo->query("SELECT MAX(added_date) as last_added FROM `$tableName` WHERE added_date IS NOT NULL");
        $lastAdded = $stmt->fetchColumn();
        if ($lastAdded) {
            $result['stats']['last_added'] = $lastAdded;
            $result['stats']['last_added_formatted'] = date('d.m.Y H:i:s', strtotime($lastAdded));
        }
    } catch (Exception $e) {
        error_log('Error getting table stats: '.$e->getMessage());
        $result['stats']['error'] = $e->getMessage();
    }

    return $result;
}

/**
 * Форматировать байты.
 */
function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision).' '.$units[$pow];
}
