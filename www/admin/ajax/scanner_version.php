<?php

// admin/ajax/scanner_version.php

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../lib/ScannerManager.php';

header('Content-Type: application/json');

// Проверка авторизации
session_name('ADMIN_SESSION');
session_start();

if (!isset($_SESSION['admin_logged_in']) || true !== $_SESSION['admin_logged_in']) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => __('admin_error_access_denied'),
    ]);
    exit;
}

$scanner = new ScannerManager();
$version = $scanner->getVersion();

if ($version) {
    echo json_encode([
        'success' => true,
        'version' => $version,
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Unable to get scanner version',
    ]);
}
