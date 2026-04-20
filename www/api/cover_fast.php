<?php

// api/cover_fast.php

define('LOPDS_ROOT', __DIR__.'/..');

// Подключаем только самое необходимое
require_once LOPDS_ROOT.'/config/config.php';

// Заглушки для функций, чтобы не подключать init.php
if (!function_exists('__')) {
    function __($key)
    {
        return $key;
    }
}

if (!function_exists('error_log')) {
    function error_log($msg)
    { /* тихо */
    }
}

// Подключаем только Database без всей инициализации
class LightDatabase
{
    private static $instance;
    private $pdo;

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $dbConfig = [
            'type' => Config::getDbType(),
            'path' => Config::getDbPath(),
            'host' => Config::getDbConfig()['host'] ?? 'localhost',
            'user' => Config::getDbConfig()['user'] ?? '',
            'pass' => Config::getDbConfig()['pass'] ?? '',
            'name' => Config::getDbConfig()['name'] ?? '',
        ];

        try {
            if ('sqlite' === $dbConfig['type']) {
                $this->pdo = new PDO('sqlite:'.$dbConfig['path']);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } else {
                $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4";
                $this->pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass']);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
        } catch (Exception $e) {
            // Тихо падаем
        }
    }

    public function getBook($id)
    {
        if (!$this->pdo) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, file_type, file_path, archive_path, archive_internal_path FROM books WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Класс для работы с обложками
class LightCoverParser
{
    public static function getCover($book, $thumb = false)
    {
        $filePath = null;

        if (!empty($book['archive_path']) && !empty($book['archive_internal_path'])) {
            $zip = new ZipArchive();
            if (true === $zip->open($book['archive_path'])) {
                $content = $zip->getFromName($book['archive_internal_path']);
                $zip->close();

                if ($content) {
                    return self::extractCoverFromContent($content, $book['file_type']);
                }
            }
        } elseif (!empty($book['file_path']) && file_exists($book['file_path'])) {
            $content = file_get_contents($book['file_path']);
            if ($content) {
                return self::extractCoverFromContent($content, $book['file_type']);
            }
        }

        return null;
    }

    private static function extractCoverFromContent($content, $fileType)
    {
        if ('fb2' === $fileType) {
            // Ищем обложку в FB2
            if (preg_match('/<binary[^>]*id="([^"]+)"[^>]*content-type="image\/[^"]+"[^>]*>([^<]+)<\/binary>/is', $content, $matches)) {
                return base64_decode($matches[2]);
            }
            if (preg_match('/<binary[^>]*id="([^"]+)"[^>]*>([^<]+)<\/binary>/is', $content, $matches)) {
                $data = base64_decode($matches[2]);
                if (strlen($data) > 1000 && (false !== strpos($data, 'JFIF') || false !== strpos($data, 'PNG'))) {
                    return $data;
                }
            }
        }

        return null;
    }
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$thumb = isset($_GET['thumb']);

if (!$id) {
    serveDefaultCover($thumb);
    exit;
}

$db = LightDatabase::getInstance();
$book = $db->getBook($id);

if (!$book) {
    serveDefaultCover($thumb);
    exit;
}

// Агрессивное кэширование на 1 год
header('Cache-Control: public, max-age=31536000, immutable');
header('Expires: '.gmdate('D, d M Y H:i:s', time() + 31536000).' GMT');

$etag = '"'.md5($id.($thumb ? 't' : 'f')).'"';
header('ETag: '.$etag);

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) == $etag) {
    header('HTTP/1.1 304 Not Modified');
    exit;
}

$coverData = LightCoverParser::getCover($book, $thumb);

if ($coverData) {
    header('Content-Type: image/jpeg');
    echo $coverData;
} else {
    serveDefaultCover($thumb);
}

function serveDefaultCover($thumb)
{
    $width = $thumb ? 200 : 600;
    $height = $thumb ? 300 : 800;

    $image = imagecreatetruecolor($width, $height);
    $bgColor = imagecolorallocate($image, 240, 240, 240);
    $textColor = imagecolorallocate($image, 150, 150, 150);

    imagefill($image, 0, 0, $bgColor);

    $text = 'Нет обложки';
    $fontSize = $thumb ? 3 : 5;
    $textWidth = imagefontwidth($fontSize) * strlen($text);
    $textHeight = imagefontheight($fontSize);
    $x = ($width - $textWidth) / 2;
    $y = ($height - $textHeight) / 2;

    imagestring($image, $fontSize, $x, $y, $text, $textColor);

    header('Content-Type: image/jpeg');
    imagejpeg($image);
    imagedestroy($image);
    exit;
}
