<?php

// admin/ajax/scanner_status_light.php

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../lib/ScannerManager.php';

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => __('admin_error_access_denied'),
        'code' => 'unauthorized'
    ]);
    exit;
}

$scanner = new ScannerManager();

// Только базовая информация, без статистики БД
$running = $scanner->isRunning();
$status = [
    'success' => true,
    'running' => $running,
    'timestamp' => time(),
    'timestamp_formatted' => date('H:i:s')
];

if ($running) {
    $status['pid'] = $scanner->getPid();
    $startTime = $scanner->getStartTime();
    if ($startTime) {
        $status['started_at'] = date('Y-m-d H:i:s', $startTime);
        $status['running_for'] = formatTimeDiff($startTime);
        $status['running_for_short'] = formatTimeDiffShort($startTime);
    }

    // Получаем последние 5 строк лога для быстрого просмотра
    $lastLogLines = $scanner->getLastLogLines(5);
    $status['last_log'] = array_map('formatLogLineLight', $lastLogLines);
    $status['has_errors'] = hasErrorsInLog($lastLogLines);
}

// Добавляем информацию о доступности сканера
$status['scanner_available'] = $scanner->isAvailable();
if ($status['scanner_available']) {
    $status['scanner_version'] = $scanner->getVersion();
}

echo json_encode($status);

/**
 * Форматировать разницу во времени (полный формат)
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
    } else {
        $days = floor($diff / 86400);
        $hours = floor(($diff % 86400) / 3600);
        return sprintf(__('time_days_hours'), $days, $hours);
    }
}

/**
 * Форматировать разницу во времени (короткий формат)
 */
function formatTimeDiffShort($timestamp)
{
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return $diff . 's';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . 'm';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . 'h';
    } else {
        return floor($diff / 86400) . 'd';
    }
}

/**
 * Форматировать строку лога для лёгкой версии
 */
function formatLogLineLight($line)
{
    // Определяем уровень лога
    $levels = [
        'ERROR' => ['class' => 'danger', 'icon' => 'fa-exclamation-circle'],
        'WARNING' => ['class' => 'warning', 'icon' => 'fa-exclamation-triangle'],
        'NOTICE' => ['class' => 'info', 'icon' => 'fa-info-circle'],
        'INFO' => ['class' => 'secondary', 'icon' => 'fa-info-circle'],
        'SUCCESS' => ['class' => 'success', 'icon' => 'fa-check-circle']
    ];

    $level = 'INFO';
    $class = 'secondary';
    $icon = 'fa-info-circle';

    foreach ($levels as $lvl => $info) {
        if (stripos($line, $lvl) !== false) {
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
        // Если время содержит дату, извлекаем только время
        if (strpos($time, ' ') !== false) {
            $parts = explode(' ', $time);
            $time = end($parts);
        }
    }

    // Очищаем строку от управляющих символов и обрезаем
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $line);
    $text = htmlspecialchars($text);

    // Обрезаем слишком длинные строки
    if (strlen($text) > 100) {
        $text = mb_substr($text, 0, 97) . '...';
    }

    return [
        'text' => $text,
        'level' => $level,
        'class' => $class,
        'icon' => $icon,
        'time' => $time
    ];
}

/**
 * Проверить наличие ошибок в последних строках лога
 */
function hasErrorsInLog($logLines)
{
    foreach ($logLines as $line) {
        if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) {
            return true;
        }
    }
    return false;
}
