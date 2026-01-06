<?php
require_once 'config/config.php';

echo "<h1>🔐 Проверка прав доступа</h1>";

$paths = [
    Config::COVER_CACHE_DIR,
    '/var/www/html/4/cache',
    '/var/www/html/4/cache/covers',
    '/var/www/html/4/api'
];

foreach ($paths as $path) {
    echo "<h3>📁 {$path}</h3>";
    
    if (file_exists($path)) {
        $perms = fileperms($path);
        echo "Существует: ✅ ДА<br>";
        echo "Права: " . substr(sprintf('%o', $perms), -3) . "<br>";
        echo "Чтение: " . (is_readable($path) ? "✅ ДА" : "❌ НЕТ") . "<br>";
        echo "Запись: " . (is_writable($path) ? "✅ ДА" : "❌ НЕТ") . "<br>";
        
        // Проверяем владельца
        $owner = fileowner($path);
        $group = filegroup($path);
        echo "Владелец: " . posix_getpwuid($owner)['name'] . "<br>";
        echo "Группа: " . posix_getgrgid($group)['name'] . "<br>";
        
        // Пробуем прочитать файл
        $files = glob($path . '/*.jpg');
        echo "Файлов .jpg: " . count($files) . "<br>";
        if (count($files) > 0) {
            $testFile = $files[0];
            echo "Тестовый файл: " . basename($testFile) . " - ";
            echo is_readable($testFile) ? "✅ Читается" : "❌ Не читается";
        }
    } else {
        echo "Существует: ❌ НЕТ<br>";
    }
    echo "<hr>";
}
?>