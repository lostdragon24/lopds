<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::getInstance();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid book ID');
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book || strtolower($book['file_type']) !== 'epub') {
    die('Invalid book or format');
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Чтение EPUB</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/epub.js/0.3.88/reader.min.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        #viewer {
            width: 100%;
            height: 100vh;
            overflow: hidden;
        }
        
        .epub-container {
            overflow: hidden !important;
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
            display: flex;
            gap: 10px;
        }
        
        .toolbar button {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 5px;
        }
        
        .toolbar button:hover {
            background: rgba(255,255,255,0.2);
        }
    </style>
</head>
<body>
    <div id="viewer"></div>
    
    <div class="toolbar">
        <button onclick="prevPage()"><i class="fas fa-chevron-left"></i></button>
        <span id="pageNum">1</span> / <span id="pageCount">...</span>
        <button onclick="nextPage()"><i class="fas fa-chevron-right"></i></button>
        <button onclick="changeFontSize(-1)"><i class="fas fa-minus"></i></button>
        <button onclick="changeFontSize(1)"><i class="fas fa-plus"></i></button>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/epub.js/0.3.88/ebook.min.js"></script>
    <script>
        // URL для получения EPUB файла
        const bookUrl = './download.php?id=<?php echo $bookId; ?>';
        
        const book = ePub(bookUrl);
        const rendition = book.renderTo("viewer", {
            width: "100%",
            height: "100%",
            spread: "none",
            flow: "paginated"
        });
        
        let currentPage = 1;
        let totalPages = 1;
        
        rendition.display().then(() => {
            return rendition.getSize();
        }).then((size) => {
            totalPages = size.total;
            updatePageInfo();
        });
        
        function nextPage() {
            rendition.next();
            currentPage = Math.min(currentPage + 1, totalPages);
            updatePageInfo();
        }
        
        function prevPage() {
            rendition.prev();
            currentPage = Math.max(currentPage - 1, 1);
            updatePageInfo();
        }
        
        function updatePageInfo() {
            document.getElementById('pageNum').textContent = currentPage;
            document.getElementById('pageCount').textContent = totalPages;
        }
        
        function changeFontSize(delta) {
            const currentSize = parseInt(rendition.themes.fontSize());
            const newSize = Math.max(50, Math.min(200, currentSize + delta * 10));
            rendition.themes.fontSize(newSize + '%');
        }
        
        // Клавиши для навигации
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight') nextPage();
            if (e.key === 'ArrowLeft') prevPage();
        });
    </script>
    <script src="https://kit.fontawesome.com/your-code.js" crossorigin="anonymous"></script>
</body>
</html>