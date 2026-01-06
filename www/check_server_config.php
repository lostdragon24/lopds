<?php
echo "<h1>🌐 Проверка конфигурации сервера</h1>";

echo "<h3>Переменные сервера:</h3>";
echo "DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'N/A') . "<br>";
echo "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "<br>";

echo "<h3>Проверка URL:</h3>";
$testUrls = [
    '/cache/covers/74819_thumb.jpg',
    '/api/cover_simple.php?id=74819&thumb=1',
    '/public/covers/74819_thumb.jpg'
];

foreach ($testUrls as $url) {
    $fullUrl = "http://" . $_SERVER['HTTP_HOST'] . $url;
    echo "<a href='{$fullUrl}' target='_blank'>{$url}</a><br>";
}

echo "<h3>Проверка включений:</h3>";
$includes = [
    'config/config.php' => file_exists(__DIR__ . '/config/config.php'),
    'lib/Database.php' => file_exists(__DIR__ . '/lib/Database.php'),
    'lib/Fb2CoverParser.php' => file_exists(__DIR__ . '/lib/Fb2CoverParser.php')
];

foreach ($includes as $file => $exists) {
    echo "{$file}: " . ($exists ? "✅ СУЩЕСТВУЕТ" : "❌ ОТСУТСТВУЕТ") . "<br>";
}
?>