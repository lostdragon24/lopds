<?php
// reader.php

define('LOPDS_ROOT', __DIR__);

require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'init.php';

$db = Database::getInstance();
$inReader = true;

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.0 400 Bad Request');
    exit(__('book_invalid_id'));
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book) {
    header('HTTP/1.0 404 Not Found');
    exit(__('book_not_found'));
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
                <?php echo htmlspecialchars(mb_substr($book['title'] ?: __('book_untitled'), 0, 50)); ?>
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3" id="pageInfo"><?php echo __('reader_loading'); ?></span>
                <a href="./api/download.php?id=<?php echo $bookId; ?>" class="btn btn-success me-2">
                    <i class="fas fa-download me-1"></i><?php echo __('download'); ?>
                </a>
                <button class="btn btn-outline-light" onclick="toggleFullscreen()" title="<?php echo __('reader_fullscreen'); ?>">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </div>
    </nav>

    <!-- Область чтения -->
    <div class="reader-container" id="readerContainer">
        <?php if ('fb2' === $fileType) { ?>
            <iframe src="./api/read_fb2.php?id=<?php echo $bookId; ?>" 
                    class="fb2-iframe" 
                    id="readerFrame"
                    frameborder="0"
                    title="<?php echo __('reader_fb2_title'); ?>"></iframe>
        <?php } elseif ('epub' === $fileType) { ?>
            <iframe src="./api/read_epub.php?id=<?php echo $bookId; ?>" 
                    class="epub-iframe" 
                    id="readerFrame"
                    frameborder="0"
                    title="<?php echo __('reader_epub_title'); ?>"></iframe>
        <?php } elseif ('pdf' === $fileType) { ?>
            <iframe src="./api/read_pdf.php?id=<?php echo $bookId; ?>" 
                    class="pdf-iframe" 
                    id="readerFrame"
                    frameborder="0"
                    title="<?php echo __('reader_pdf_title'); ?>"></iframe>
        <?php } else { ?>
            <div class="alert alert-warning m-4">
                <h5><i class="fas fa-exclamation-triangle me-2"></i><?php echo __('reader_format_not_supported'); ?></h5>
                <p><?php echo sprintf(__('reader_format_desc'), strtoupper($fileType)); ?></p>
                <a href="./api/download.php?id=<?php echo $bookId; ?>" class="btn btn-primary">
                    <i class="fas fa-download me-2"></i><?php echo __('reader_download'); ?>
                </a>
            </div>
        <?php } ?>
    </div>

    <!-- Нижняя панель управления -->
    <div class="reader-controls">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-4">
                    <div class="btn-group" role="group">
                        <button class="btn btn-outline-secondary" onclick="changeFontSize(-1)" title="<?php echo __('reader_font_decrease'); ?>">
                            <i class="fas fa-minus"></i>
                        </button>
                        <span class="btn btn-outline-secondary disabled" id="fontSizeDisplay">100%</span>
                        <button class="btn btn-outline-secondary" onclick="changeFontSize(1)" title="<?php echo __('reader_font_increase'); ?>">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="col-4 text-center">
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="prevPage()" title="<?php echo __('reader_prev_page'); ?>">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button class="btn btn-primary" onclick="nextPage()" title="<?php echo __('reader_next_page'); ?>">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                <div class="col-4 text-end">
                    <div class="btn-group">
                        <button class="btn btn-outline-secondary" onclick="toggleTheme()" title="<?php echo __('reader_toggle_theme'); ?>">
                            <i class="fas fa-moon"></i>
                        </button>
                        <button class="btn btn-outline-secondary" onclick="toggleSettings()" title="<?php echo __('reader_settings'); ?>">
                            <i class="fas fa-cog"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Панель настроек (скрытая) -->
<div class="settings-panel" id="settingsPanel" style="display: none;">
    <div class="settings-panel-header">
        <h6><?php echo __('reader_settings_title'); ?></h6>
        <button type="button" class="btn-close" onclick="toggleSettings()"></button>
    </div>
    <div class="settings-panel-body">
        <div class="mb-3">
            <label class="form-label"><?php echo __('reader_font_family'); ?></label>
            <select class="form-select" id="fontFamily" onchange="changeFontFamily(this.value)">
                <option value="default"><?php echo __('reader_font_default'); ?></option>
                <option value="serif"><?php echo __('reader_font_serif'); ?></option>
                <option value="sans-serif"><?php echo __('reader_font_sans'); ?></option>
                <option value="monospace"><?php echo __('reader_font_mono'); ?></option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label"><?php echo __('reader_line_height'); ?></label>
            <select class="form-select" id="lineHeight" onchange="changeLineHeight(this.value)">
                <option value="1.2"><?php echo __('reader_line_compact'); ?></option>
                <option value="1.5" selected><?php echo __('reader_line_normal'); ?></option>
                <option value="1.8"><?php echo __('reader_line_relaxed'); ?></option>
                <option value="2.0"><?php echo __('reader_line_double'); ?></option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label"><?php echo __('reader_margin'); ?></label>
            <select class="form-select" id="margin" onchange="changeMargin(this.value)">
                <option value="0"><?php echo __('reader_margin_none'); ?></option>
                <option value="20" selected><?php echo __('reader_margin_small'); ?></option>
                <option value="40"><?php echo __('reader_margin_medium'); ?></option>
                <option value="60"><?php echo __('reader_margin_large'); ?></option>
            </select>
        </div>
    </div>
</div>

<script>
let currentPage = 1;
let totalPages = 1;
let fontSize = 100;
let readerFrame = document.getElementById('readerFrame');
let navigationTimeout;
let settingsVisible = false;

// Translations for JavaScript
const readerTranslations = {
    loading: '<?php echo __('reader_loading'); ?>',
    page: '<?php echo __('reader_page'); ?>',
    of: '<?php echo __('of'); ?>'
};

// Слушаем сообщения от iframe
window.addEventListener('message', function(event) {
    if (event.data.type === 'pagination') {
        currentPage = event.data.currentPage || 1;
        totalPages = event.data.totalPages || 1;
        updatePageInfo();
        
        if (navigationTimeout) {
            clearTimeout(navigationTimeout);
        }
    } else if (event.data.type === 'ready') {
        console.log('Reader ready, sending init...');
        setTimeout(() => {
            if (readerFrame && readerFrame.contentWindow) {
                readerFrame.contentWindow.postMessage({
                    type: 'init',
                    fontSize: fontSize,
                    theme: document.body.classList.contains('dark-theme'),
                    fontFamily: document.getElementById('fontFamily')?.value || 'default',
                    lineHeight: document.getElementById('lineHeight')?.value || '1.5',
                    margin: document.getElementById('margin')?.value || '20'
                }, '*');
            }
        }, 100);
    }
});

function nextPage() {
    if (!readerFrame || !readerFrame.contentWindow) return;
    
    readerFrame.contentWindow.postMessage({
        type: 'navigate',
        direction: 'next'
    }, '*');
    
    navigationTimeout = setTimeout(() => {
        console.log('Navigation timeout - requesting status');
        readerFrame.contentWindow.postMessage({
            type: 'getStatus'
        }, '*');
    }, 1000);
}

function prevPage() {
    if (!readerFrame || !readerFrame.contentWindow) return;
    
    readerFrame.contentWindow.postMessage({
        type: 'navigate',
        direction: 'prev'
    }, '*');
    
    navigationTimeout = setTimeout(() => {
        console.log('Navigation timeout - requesting status');
        readerFrame.contentWindow.postMessage({
            type: 'getStatus'
        }, '*');
    }, 1000);
}

function updatePageInfo() {
    document.getElementById('pageInfo').textContent = 
        readerTranslations.page + ' ' + currentPage + ' ' + readerTranslations.of + ' ' + totalPages;
    
    const prevBtn = document.querySelector('[onclick="prevPage()"]');
    const nextBtn = document.querySelector('[onclick="nextPage()"]');
    
    if (prevBtn && nextBtn) {
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
    }
}

function changeFontSize(delta) {
    fontSize = Math.max(70, Math.min(200, fontSize + delta * 10));
    document.getElementById('fontSizeDisplay').textContent = fontSize + '%';
    
    if (readerFrame && readerFrame.contentWindow) {
        readerFrame.contentWindow.postMessage({
            type: 'fontSize',
            size: fontSize
        }, '*');
    }
    
    document.cookie = 'reader_font_size=' + fontSize + '; path=/; max-age=31536000';
}

function changeFontFamily(fontFamily) {
    if (readerFrame && readerFrame.contentWindow) {
        readerFrame.contentWindow.postMessage({
            type: 'fontFamily',
            family: fontFamily
        }, '*');
    }
    document.cookie = 'reader_font_family=' + fontFamily + '; path=/; max-age=31536000';
}

function changeLineHeight(lineHeight) {
    if (readerFrame && readerFrame.contentWindow) {
        readerFrame.contentWindow.postMessage({
            type: 'lineHeight',
            height: lineHeight
        }, '*');
    }
    document.cookie = 'reader_line_height=' + lineHeight + '; path=/; max-age=31536000';
}

function changeMargin(margin) {
    if (readerFrame && readerFrame.contentWindow) {
        readerFrame.contentWindow.postMessage({
            type: 'margin',
            margin: margin
        }, '*');
    }
    document.cookie = 'reader_margin=' + margin + '; path=/; max-age=31536000';
}

function toggleTheme() {
    document.body.classList.toggle('dark-theme');
    const isDark = document.body.classList.contains('dark-theme');
    
    if (readerFrame && readerFrame.contentWindow) {
        readerFrame.contentWindow.postMessage({
            type: 'theme',
            dark: isDark
        }, '*');
    }
    
    // Меняем иконку
    const themeBtn = document.querySelector('[onclick="toggleTheme()"] i');
    if (themeBtn) {
        themeBtn.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }
    
    document.cookie = 'reader_dark_theme=' + (isDark ? '1' : '0') + '; path=/; max-age=31536000';
}

function toggleSettings() {
    const panel = document.getElementById('settingsPanel');
    settingsVisible = !settingsVisible;
    panel.style.display = settingsVisible ? 'block' : 'none';
}

function toggleFullscreen() {
    const readerWrapper = document.querySelector('.reader-wrapper');
    
    if (!readerWrapper) {
        console.error('Reader wrapper not found');
        return;
    }
    
    if (!document.fullscreenElement) {
        if (readerWrapper.requestFullscreen) {
            readerWrapper.requestFullscreen();
        } else if (readerWrapper.webkitRequestFullscreen) {
            readerWrapper.webkitRequestFullscreen();
        } else if (readerWrapper.msRequestFullscreen) {
            readerWrapper.msRequestFullscreen();
        }
        
        const btn = document.querySelector('[onclick="toggleFullscreen()"] i');
        if (btn) {
            btn.className = 'fas fa-compress';
        }
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        } else if (document.msExitFullscreen) {
            document.msExitFullscreen();
        }
        
        const btn = document.querySelector('[onclick="toggleFullscreen()"] i');
        if (btn) {
            btn.className = 'fas fa-expand';
        }
    }
}

// Следим за изменением полноэкранного режима
document.addEventListener('fullscreenchange', updateFullscreenButton);
document.addEventListener('webkitfullscreenchange', updateFullscreenButton);
document.addEventListener('msfullscreenchange', updateFullscreenButton);

function updateFullscreenButton() {
    const btn = document.querySelector('[onclick="toggleFullscreen()"] i');
    if (!btn) return;
    
    if (document.fullscreenElement) {
        btn.className = 'fas fa-compress';
    } else {
        btn.className = 'fas fa-expand';
    }
}

// Загружаем сохраненные настройки
(function() {
    const cookies = document.cookie.split(';').reduce((acc, cookie) => {
        const [key, value] = cookie.trim().split('=');
        acc[key] = value;
        return acc;
    }, {});
    
    if (cookies.reader_font_size) {
        fontSize = parseInt(cookies.reader_font_size);
        document.getElementById('fontSizeDisplay').textContent = fontSize + '%';
    }
    
    if (cookies.reader_font_family) {
        document.getElementById('fontFamily').value = cookies.reader_font_family;
    }
    
    if (cookies.reader_line_height) {
        document.getElementById('lineHeight').value = cookies.reader_line_height;
    }
    
    if (cookies.reader_margin) {
        document.getElementById('margin').value = cookies.reader_margin;
    }
    
    if (cookies.reader_dark_theme === '1') {
        document.body.classList.add('dark-theme');
        document.querySelector('[onclick="toggleTheme()"] i').className = 'fas fa-sun';
    }
})();

// Клавиши навигации
document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
        return;
    }
    
    if (e.key === 'ArrowRight') {
        nextPage();
        e.preventDefault();
    } else if (e.key === 'ArrowLeft') {
        prevPage();
        e.preventDefault();
    } else if (e.key === '+' || e.key === '=') {
        changeFontSize(1);
        e.preventDefault();
    } else if (e.key === '-' || e.key === '_') {
        changeFontSize(-1);
        e.preventDefault();
    } else if (e.key === 'f' || e.key === 'F') {
        toggleFullscreen();
        e.preventDefault();
    } else if (e.key === 's' || e.key === 'S') {
        toggleSettings();
        e.preventDefault();
    } else if (e.key === 't' || e.key === 'T') {
        toggleTheme();
        e.preventDefault();
    } else if (e.key === 'Escape') {
        if (document.fullscreenElement) {
            toggleFullscreen();
        }
        if (settingsVisible) {
            toggleSettings();
        }
    }
});

// Инициализация после загрузки страницы
window.addEventListener('load', function() {
    updateFullscreenButton();
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
    padding: 0.5rem 1rem;
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

.settings-panel {
    position: fixed;
    top: 70px;
    right: 20px;
    width: 300px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
    z-index: 1060;
    animation: slideIn 0.3s ease;
}

.settings-panel-header {
    padding: 15px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.settings-panel-header h6 {
    margin: 0;
    font-weight: 600;
}

.settings-panel-body {
    padding: 15px;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
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
    color: white;
}

body.dark-theme .settings-panel {
    background: #2d2d2d;
    color: #e0e0e0;
    border: 1px solid #404040;
}

body.dark-theme .settings-panel-header {
    border-bottom-color: #404040;
}

body.dark-theme .form-select {
    background-color: #3d3d3d;
    color: #e0e0e0;
    border-color: #404040;
}

body.dark-theme .form-select option {
    background-color: #3d3d3d;
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
    
    .settings-panel {
        width: 90%;
        right: 5%;
        top: 60px;
    }
    
    .reader-navbar .navbar-brand {
        font-size: 0.9rem;
        max-width: 150px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
}
</style>

<?php require 'templates/footer.php'; ?>