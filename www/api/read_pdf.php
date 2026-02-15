<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::getInstance();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid book ID');
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book || strtolower($book['file_type']) !== 'pdf') {
    die('Invalid book or format');
}

// Получаем путь к PDF файлу
if ($book['archive_path'] && $book['archive_internal_path']) {
    // Если PDF в архиве, нужно извлечь
    $pdfUrl = './extract_pdf.php?id=' . $bookId;
} else {
    $pdfUrl = './download.php?id=' . $bookId;
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Чтение PDF</title>
    <style>
        body, html {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .pdf-viewer {
            width: 100%;
            height: 100%;
        }
        
        .pdf-iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
        
        .error-message {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #666;
        }
        
        .toolbar {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            background: rgba(0,0,0,0.8);
            border-radius: 30px;
            padding: 10px 15px;
            color: white;
        }
        
        .toolbar a {
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <?php if ($book['archive_path'] && $book['archive_internal_path']): ?>
        <div class="error-message">
            <h3>PDF в архиве</h3>
            <p>Для чтения PDF из архива, пожалуйста, скачайте книгу.</p>
            <a href="./download.php?id=<?php echo $bookId; ?>" class="btn btn-primary">Скачать PDF</a>
        </div>
    <?php else: ?>
        <iframe src="<?php echo $pdfUrl; ?>" class="pdf-iframe" allowfullscreen></iframe>
        
        <div class="toolbar">
            <a href="<?php echo $pdfUrl; ?>" download>
                <i class="fas fa-download"></i> Скачать
            </a>
        </div>
    <?php endif; ?>
    
    <script src="https://kit.fontawesome.com/your-code.js" crossorigin="anonymous"></script>
</body>
</html>