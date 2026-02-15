<?php
require_once 'config/config.php';
require_once 'lib/Database.php';

$db = Database::getInstance();
$inReader = true;

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.0 400 Bad Request');
    die('Неверный ID книги');
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book) {
    header('HTTP/1.0 404 Not Found');
    die('Книга не найдена');
}

$fileType = strtolower($book['file_type']);
require 'templates/header.php';
?>

<div class="reader-wrapper">
    <!-- Верхняя панель -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark reader-navbar">
        <div class="container-fluid">
            <a class="navbar-brand" href="book_detail.php?id=<?php echo $bookId; ?>">
                <i class="fas fa-arrow-left me-2"></i>
                <?php echo htmlspecialchars(mb_substr($book['title'] ?: 'Без названия', 0, 50)); ?>
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3" id="pageInfo">Стр. 1 / ...</span>
                <a href="./api/download.php?id=<?php echo $bookId; ?>" class="btn btn-success me-2">
                    <i class="fas fa-download me-1"></i>Скачать
                </a>
                <button class="btn btn-outline-light" onclick="toggleFullscreen()">
                    <i class="fas fa-expand"></i>
                </button>


<button class="btn btn-outline-light me-2" onclick="showEncodingMenu()" title="Выбрать кодировку">
    <i class="fas fa-language"></i>
</button>

            </div>
        </div>
    </nav>

    <!-- Область чтения -->
    <div class="reader-container" id="readerContainer">
        <?php if ($fileType === 'fb2'): ?>
            <iframe src="./api/read_fb2.php?id=<?php echo $bookId; ?>&page=1" 
                    class="fb2-iframe" 
                    id="fb2Frame"
                    frameborder="0"></iframe>
        <?php elseif ($fileType === 'epub'): ?>
            <iframe src="./api/read_epub.php?id=<?php echo $bookId; ?>" 
                    class="epub-iframe" 
                    frameborder="0"></iframe>
        <?php elseif ($fileType === 'pdf'): ?>
            <iframe src="./api/read_pdf.php?id=<?php echo $bookId; ?>" 
                    class="pdf-iframe" 
                    frameborder="0"></iframe>
        <?php else: ?>
            <div class="alert alert-warning m-4">
                <h5>Формат не поддерживается для онлайн-чтения</h5>
                <p>Книга в формате <?php echo strtoupper($fileType); ?> не может быть отображена в браузере.</p>
                <a href="./api/download.php?id=<?php echo $bookId; ?>" class="btn btn-primary">
                    <i class="fas fa-download me-2"></i>Скачать книгу
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Нижняя панель управления -->
    <div class="reader-controls">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-4">
                    <button class="btn btn-outline-secondary" onclick="changeFontSize(-1)">
                        <i class="fas fa-minus"></i>
                    </button>
                    <span class="mx-2" id="fontSizeDisplay">100%</span>
                    <button class="btn btn-outline-secondary" onclick="changeFontSize(1)">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                <div class="col-4 text-center">
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="prevPage()">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button class="btn btn-primary" onclick="nextPage()">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                <div class="col-4 text-end">
                    <button class="btn btn-outline-secondary" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentPage = 1;
let totalPages = 1;
let fontSize = 100;
let fb2Frame = document.getElementById('fb2Frame');

// Слушаем сообщения от iframe
window.addEventListener('message', function(event) {
    if (event.data.type === 'pagination') {
        currentPage = event.data.currentPage;
        totalPages = event.data.totalPages;
        updatePageInfo();
    }
});


function showEncodingMenu() {
    if (fb2Frame) {
        fb2Frame.contentWindow.postMessage({
            type: 'showEncodingMenu'
        }, '*');
    }
}


function nextPage() {
    if (fb2Frame) {
        fb2Frame.contentWindow.postMessage({
            type: 'navigate',
            direction: 'next'
        }, '*');
    }
}

function prevPage() {
    if (fb2Frame) {
        fb2Frame.contentWindow.postMessage({
            type: 'navigate',
            direction: 'prev'
        }, '*');
    }
}

function updatePageInfo() {
    document.getElementById('pageInfo').textContent = `Стр. ${currentPage} / ${totalPages}`;
}

function changeFontSize(delta) {
    fontSize = Math.max(70, Math.min(200, fontSize + delta * 10));
    document.getElementById('fontSizeDisplay').textContent = fontSize + '%';
    
    if (fb2Frame) {
        fb2Frame.contentWindow.postMessage({
            type: 'fontSize',
            size: fontSize
        }, '*');
    }
}

function toggleTheme() {
    document.body.classList.toggle('dark-theme');
    const isDark = document.body.classList.contains('dark-theme');
    
    if (fb2Frame) {
        fb2Frame.contentWindow.postMessage({
            type: 'theme',
            dark: isDark
        }, '*');
    }
}

function toggleFullscreen() {
    if (!document.fullscreenElement) {
        document.querySelector('.reader-wrapper').requestFullscreen();
    } else {
        document.exitFullscreen();
    }
}

function changeFontSize(delta) {
    fontSize = Math.max(70, Math.min(200, fontSize + delta * 10));
    document.getElementById('fontSizeDisplay').textContent = fontSize + '%';
    
    if (fb2Frame) {
        fb2Frame.contentWindow.postMessage({
            type: 'fontSize',
            size: fontSize
        }, '*');
    } else {
        // Для других форматов можно добавить обработку
        console.log('Font size changed to', fontSize);
    }
}

// Добавляем сохранение размера шрифта
function saveFontSize(size) {
    document.cookie = 'reader_font_size=' + size + '; path=/; max-age=31536000';
}

// При загрузке читалки
window.addEventListener('load', function() {
    // Загружаем сохраненный размер шрифта
    const match = document.cookie.match(/reader_font_size=([0-9]+)/);
    if (match) {
        fontSize = parseInt(match[1]);
        document.getElementById('fontSizeDisplay').textContent = fontSize + '%';
    }
});



// Клавиши навигации
document.addEventListener('keydown', function(e) {
    if (e.key === 'ArrowRight') {
        nextPage();
        e.preventDefault();
    } else if (e.key === 'ArrowLeft') {
        prevPage();
        e.preventDefault();
    }
});
</script>

<style>
.reader-wrapper {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: #fff;
    z-index: 1050;
    display: flex;
    flex-direction: column;
}

.reader-navbar {
    flex-shrink: 0;
    border-radius: 0;
}

.reader-container {
    flex: 1;
    overflow: hidden;
    background: #f8f9fa;
}

.fb2-iframe,
.epub-iframe,
.pdf-iframe {
    width: 100%;
    height: 100%;
    border: none;
    background: inherit;
}

.reader-controls {
    flex-shrink: 0;
    background: #fff;
    border-top: 1px solid #dee2e6;
    padding: 10px 0;
}

/* Темная тема */
body.dark-theme .reader-wrapper {
    background: #1a1a1a;
}

body.dark-theme .reader-container {
    background: #1a1a1a;
}

body.dark-theme .reader-controls {
    background: #2d2d2d;
    border-top-color: #404040;
}

body.dark-theme .btn-outline-secondary {
    color: #e0e0e0;
    border-color: #404040;
}

body.dark-theme .btn-outline-secondary:hover {
    background: #404040;
}

@media (max-width: 768px) {
    .reader-controls .btn-group {
        margin: 5px 0;
    }
    
    .reader-controls .col-4 {
        text-align: center !important;
    }
    
    .reader-controls .text-end {
        text-align: center !important;
    }
}
</style>

<?php require 'templates/footer.php'; ?>