<?php

// admin/ajax/scanner_status.php

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../lib/ScannerManager.php';

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['admin_logged_in']) || true !== $_SESSION['admin_logged_in']) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => __('admin_error_access_denied'),
        'code' => 'unauthorized',
    ]);
    exit;
}

$scanner = new ScannerManager();
$status = $scanner->getStatus();

// Берём только последние 20 строк лога (не 100)
$log = $scanner->getLastLogLines(20);

// Форматируем лог для отображения с подсветкой
$formattedLog = [];
foreach ($log as $line) {
    $formattedLog[] = formatLogLine($line);
}

// Получаем дополнительную статистику если сканер запущен
$stats = [];
if ($status['running'] ?? false) {
    try {
        $stats = $scanner->getStats();
    } catch (Exception $e) {
        error_log('Error getting scanner stats: '.$e->getMessage());
    }
}

// Форматируем время работы
$runningFor = null;
if (isset($status['started_at'])) {
    $runningFor = formatTimeDiff(strtotime($status['started_at']));
}

echo json_encode([
    'success' => true,
    'running' => $status['running'] ?? false,
    'pid' => $status['pid'] ?? null,
    'started_at' => $status['started_at'] ?? null,
    'running_for' => $runningFor,
    'log' => $formattedLog,
    'stats' => [
        'total_books' => $stats['total_books'] ?? 0,
        'archives_count' => $stats['archives_count'] ?? 0,
        'last_scan' => $stats['last_scan'] ?? null,
        'scans_count' => $stats['scans_count'] ?? 0,
    ],
    'scanner_available' => $status['available'] ?? false,
    'scanner_version' => $status['version'] ?? null,
    'log_file' => $status['log_file'] ?? null,
    'log_size' => $status['log_file'] && file_exists($status['log_file']) ?
        formatBytes(filesize($status['log_file'])) : null,
]);

/**
 * Форматировать строку лога для отображения.
 */
function formatLogLine($line)
{
    // Определяем уровень лога
    $levels = [
        'ERROR' => ['class' => 'danger', 'icon' => 'fa-exclamation-circle'],
        'WARNING' => ['class' => 'warning', 'icon' => 'fa-exclamation-triangle'],
        'NOTICE' => ['class' => 'info', 'icon' => 'fa-info-circle'],
        'INFO' => ['class' => 'secondary', 'icon' => 'fa-info-circle'],
        'SUCCESS' => ['class' => 'success', 'icon' => 'fa-check-circle'],
        'DEBUG' => ['class' => 'light', 'icon' => 'fa-bug'],
    ];

    $level = 'INFO';
    $class = 'secondary';
    $icon = 'fa-info-circle';

    foreach ($levels as $lvl => $info) {
        if (false !== stripos($line, $lvl)) {
            $level = $lvl;
            $class = $info['class'];
            $icon = $info['icon'];
            break;
        }
    }

    // Извлекаем время из строки если есть
    $time = null;
    if (preg_match('/\[(.*?)\]/', $line, $matches)) {
        $time = $matches[1];
    }

    // Очищаем строку от управляющих символов
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $line);
    $text = htmlspecialchars($text);

    return [
        'raw' => $line,
        'text' => $text,
        'level' => $level,
        'class' => $class,
        'icon' => $icon,
        'time' => $time,
        'has_error' => 'ERROR' === $level,
        'has_warning' => 'WARNING' === $level,
    ];
}

/**
 * Форматировать разницу во времени.
 */
function formatTimeDiff($timestamp)
{
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return sprintf(__('time_seconds'), $diff);
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        $seconds = $diff % 60;

        return sprintf(__('time_minutes_seconds'), $minutes, $seconds);
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        $minutes = floor(($diff % 3600) / 60);

        return sprintf(__('time_hours_minutes'), $hours, $minutes);
    }
    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);

    return sprintf(__('time_days_hours'), $days, $hours);
}

/**
 * Форматировать байты.
 */
function formatBytes($bytes, $precision = 1)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision).' '.$units[$pow];
}
