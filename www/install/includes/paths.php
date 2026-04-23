<?php

// /install/includes/paths.php

/**
 * Проверить права на директории
 */
function checkDirectories()
{
    $dirs = [
        'data' => [
            'path' => Config::getBasePath() . '/data',
            'required' => true,
            'description' => __('install_dir_data_desc')
        ],
        'cache' => [
            'path' => Config::getCacheDir(),
            'required' => true,
            'description' => __('install_dir_cache_desc')
        ],
        'books' => [
            'path' => Config::getBooksDir(),
            'required' => false,
            'description' => __('install_dir_books_desc')
        ],
        'scanner' => [
            'path' => dirname(Config::getScannerPath()),
            'required' => false,
            'description' => __('install_dir_scanner_desc')
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

/**
 * Получить размер директории
 */
function getDirectorySize($path)
{
    if (!file_exists($path)) {
        return 0;
    }

    $size = 0;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        $size += $file->getSize();
    }

    return $size;
}

/**
 * Проверить, доступна ли директория для чтения/записи
 */
function checkDirectoryPermissions($path)
{
    if (!file_exists($path)) {
        return [
            'exists' => false,
            'readable' => false,
            'writable' => false,
            'executable' => false,
            'message' => __('install_dir_not_exists')
        ];
    }

    $readable = is_readable($path);
    $writable = is_writable($path);
    $executable = is_executable($path);

    $message = '';
    if (!$readable) {
        $message .= ' ' . __('install_dir_not_readable');
    }
    if (!$writable) {
        $message .= ' ' . __('install_dir_not_writable');
    }
    if (!$executable && is_dir($path)) {
        $message .= ' ' . __('install_dir_not_executable');
    }

    return [
        'exists' => true,
        'readable' => $readable,
        'writable' => $writable,
        'executable' => $executable,
        'message' => trim($message),
        'perms' => substr(sprintf('%o', fileperms($path)), -4),
        'owner' => function_exists('posix_getpwuid') ?
            posix_getpwuid(fileowner($path))['name'] : 'N/A'
    ];
}

/**
 * Попробовать создать директорию с правильными правами
 */
function createDirectoryWithPermissions($path, $perms = 0755)
{
    if (file_exists($path)) {
        return true;
    }

    if (!mkdir($path, $perms, true)) {
        return false;
    }

    chmod($path, $perms);

    // Пробуем сменить владельца на www-data если мы root
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        @exec("chown www-data:www-data " . escapeshellarg($path) . " 2>/dev/null");
    }

    return true;
}

/**
 * Проверить все необходимые директории
 */
function validateAllDirectories()
{
    $results = [];
    $allOk = true;

    $dirs = [
        'data' => Config::getBasePath() . '/data',
        'cache' => Config::getCacheDir(),
        'covers' => Config::getCoverCacheDir(),
        'books' => Config::getBooksDir(),
        'scanner' => dirname(Config::getScannerPath())
    ];

    foreach ($dirs as $name => $path) {
        $result = checkDirectoryPermissions($path);
        $results[$name] = $result;

        if (!$result['writable']) {
            $allOk = false;
        }
    }

    return [
        'success' => $allOk,
        'results' => $results
    ];
}

/**
 * Получить информацию о дисковом пространстве
 */
function getDiskSpaceInfo($path)
{
    if (!file_exists($path)) {
        return null;
    }

    $free = disk_free_space($path);
    $total = disk_total_space($path);
    $used = $total - $free;
    $percent = ($used / $total) * 100;

    return [
        'free' => $free,
        'free_formatted' => formatBytes($free),
        'total' => $total,
        'total_formatted' => formatBytes($total),
        'used' => $used,
        'used_formatted' => formatBytes($used),
        'percent_used' => round($percent, 1),
        'percent_free' => round(100 - $percent, 1)
    ];
}

/**
 * Форматировать байты в читаемый вид
 */
function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Получить рекомендации по правам доступа
 */
function getPermissionRecommendations($path)
{
    $recommendations = [];

    if (!file_exists($path)) {
        $recommendations[] = sprintf(__('install_rec_create_dir'), $path);
        return $recommendations;
    }

    if (!is_readable($path)) {
        $recommendations[] = sprintf(__('install_rec_add_read'), $path);
    }

    if (!is_writable($path)) {
        $recommendations[] = sprintf(__('install_rec_add_write'), $path);
    }

    if (is_dir($path) && !is_executable($path)) {
        $recommendations[] = sprintf(__('install_rec_add_execute'), $path);
    }

    $currentPerms = substr(sprintf('%o', fileperms($path)), -4);
    $recommendations[] = sprintf(__('install_rec_current_perms'), $currentPerms);

    return $recommendations;
}
