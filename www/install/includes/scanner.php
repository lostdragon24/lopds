<?php

/**
 * Запустить сканирование.
 */
function handleRunScanner($post)
{
    try {
        $scanner = new ScannerManager();

        if (!$scanner->isAvailable()) {
            throw new Exception('Сканер не доступен');
        }

        switch ($post['scan_mode'] ?? 'normal') {
            case 'inpx':
                $result = $scanner->importInpx();
                break;
            default:
                $result = $scanner->start(false);
        }

        if ($result['success'] ?? false) {
            $_SESSION['scan_completed'] = true;

            return [
                'success' => true,
                'message' => '✅ Сканирование запущено успешно',
                'redirect' => 'index.php?step=6&success=1',
            ];
        }
        throw new Exception($result['message'] ?? 'Unknown error');
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => '❌ Ошибка запуска сканера: '.$e->getMessage(),
        ];
    }
}

/**
 * Найти INPX файл.
 */
function findInpxFile($dir)
{
    if (!is_dir($dir)) {
        return null;
    }

    $files = scandir($dir);
    foreach ($files as $file) {
        if ('.' == $file || '..' == $file) {
            continue;
        }

        $path = $dir.'/'.$file;

        if (is_dir($path)) {
            $found = findInpxFile($path);
            if ($found) {
                return $found;
            }
        } elseif (is_file($path) && 'inpx' == strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            return $path;
        }
    }

    return null;
}
