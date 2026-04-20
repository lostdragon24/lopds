<?php
// api/read_pdf.php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../lib/Database.php';
require_once __DIR__.'/../init.php';

// Определяем basePath
$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = rtrim(dirname(dirname($scriptPath)), '/');

$db = Database::getInstance();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    exit(__('error_invalid_id'));
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book || 'pdf' !== strtolower($book['file_type'])) {
    exit(__('book_not_found'));
}

// Получаем путь к PDF файлу
if ($book['archive_path'] && $book['archive_internal_path']) {
    $tempFile = tempnam(sys_get_temp_dir(), 'pdf_').'.pdf';
    $zip = new ZipArchive();
    if (true === $zip->open($book['archive_path'])) {
        $content = $zip->getFromName($book['archive_internal_path']);
        $zip->close();
        if ($content) {
            file_put_contents($tempFile, $content);
            $pdfUrl = $tempFile;
            $isTemp = true;
        } else {
            exit(__('error_extract_failed'));
        }
    } else {
        exit(__('error_extract_failed'));
    }
} else {
    if (!file_exists($book['file_path'])) {
        exit(__('error_file_not_found'));
    }
    $pdfUrl = $book['file_path'];
    $isTemp = false;
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes">
    <title><?php echo htmlspecialchars($book['title'] ?: __('book_untitled')); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body, html {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #525659;
        }
        
        .pdf-container {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .toolbar {
            background: #323639;
            color: white;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            border-bottom: 1px solid #1a1c1e;
            z-index: 100;
            flex-shrink: 0;
        }
        
        .toolbar button {
            background: #525659;
            border: none;
            color: white;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }
        
        .toolbar button:hover {
            background: #6c7075;
        }
        
        .toolbar button:active {
            background: #3a3d40;
        }
        
        .toolbar button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .toolbar .separator {
            width: 1px;
            height: 24px;
            background: #525659;
            margin: 0 4px;
        }
        
        .page-info {
            font-size: 14px;
            color: #e0e0e0;
        }
        
        .zoom-level {
            font-size: 14px;
            color: #e0e0e0;
            min-width: 50px;
            text-align: center;
        }
        
        .title {
            flex: 1;
            font-size: 14px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #e0e0e0;
        }
        
        .title a {
            color: #e0e0e0;
            text-decoration: none;
        }
        
        .title a:hover {
            text-decoration: underline;
        }
        
        /* Область просмотра с прокруткой */
        .pdf-viewer {
            flex: 1;
            position: relative;
            background: #525659;
            overflow: auto;
        }
        
        /* Контейнер для canvas */
        .canvas-container {
            display: flex;
            justify-content: center;
            min-width: 100%;
            min-height: 100%;
        }
        
        canvas {
            display: block;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            margin: 20px auto;
        }
        
        .loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 18px;
            text-align: center;
            z-index: 10;
        }
        
        .loading .spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 10px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .error-message {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #ff6b6b;
            background: rgba(0,0,0,0.8);
            padding: 20px;
            border-radius: 8px;
            z-index: 10;
        }
        
        .error-message a {
            color: #fff;
            background: #007bff;
            padding: 8px 16px;
            text-decoration: none;
            border-radius: 4px;
            display: inline-block;
            margin-top: 16px;
        }
        
        /* Стили для полосы прокрутки */
        .pdf-viewer::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }
        
        .pdf-viewer::-webkit-scrollbar-track {
            background: #323639;
        }
        
        .pdf-viewer::-webkit-scrollbar-thumb {
            background: #6c7075;
            border-radius: 5px;
        }
        
        .pdf-viewer::-webkit-scrollbar-thumb:hover {
            background: #8c9095;
        }
        
        @media (max-width: 768px) {
            .toolbar {
                padding: 6px 12px;
                gap: 8px;
            }
            
            .toolbar button {
                padding: 4px 8px;
                font-size: 12px;
            }
            
            .page-info, .zoom-level {
                font-size: 12px;
            }
            
            .title {
                font-size: 12px;
                max-width: 150px;
            }
        }
    </style>

    <script type="module" src="<?php echo $basePath; ?>/js/pdfjs/build/pdf.mjs"></script>


   <?php echo __('reader_download'); ?>

</head>
<body>
    <div class="pdf-container">
        <div class="toolbar">
            <button id="prevPage" title='<?php echo __('reader_prev_page'); ?>'>
                <i class="fas fa-chevron-left"></i> <?php echo __('back'); ?>
            </button>
            <span class="page-info">
                 <?php echo __('page'); ?> <span id="pageNum">1</span> / <span id="pageCount">?</span>
            </span>
            <button id="nextPage" title='<?php echo __('reader_next_page'); ?>'>
                <?php echo __('forward'); ?> <i class="fas fa-chevron-right"></i>
            </button>
            
            <div class="separator"></div>
            
            <button id="zoomOut" title='<?php echo __('reader_font_decrease'); ?>' >
                <i class="fas fa-search-minus"></i>
            </button>
            <span class="zoom-level" id="zoomLevel">100%</span>
            <button id="zoomIn" title='<?php echo __('reader_font_increase'); ?>'>
                <i class="fas fa-search-plus"></i>
            </button>
            <button id="zoomFit" title='<?php echo __('reader_fit_to_width'); ?>'>
                <i class="fas fa-arrows-alt-h"></i>
            </button>
            <button id="zoomReset" title='<?php echo __('reader_reset_scale'); ?>'>
                <i class="fas fa-percent"></i>
            </button>
            
            <div class="separator"></div>
            
            <button id="downloadBtn" title=<?php echo __('reader_download'); ?>>
                <i class="fas fa-download"></i> <?php echo __('reader_download'); ?>
            </button>
            
            <div class="title">
                <a href="book_detail.php?id=<?php echo $bookId; ?>">
                    <?php echo htmlspecialchars(mb_substr($book['title'] ?: __('book_untitled'), 0, 50)); ?>
                </a>
            </div>
        </div>
        
        <div class="pdf-viewer" id="pdfViewer">
            <div class="loading">
                <div class="spinner"></div>
                <div><?php echo __('reader_loading'); ?></div>
            </div>
            <div class="canvas-container" id="canvasContainer">
                <canvas id="pdfCanvas"></canvas>
            </div>
        </div>
    </div>
    
    <script type="module">
    // Импортируем PDF.js как модуль
    import * as pdfjsLib from '<?php echo $basePath; ?>/js/pdfjs/build/pdf.mjs';

    // Указываем путь к воркеру
    pdfjsLib.GlobalWorkerOptions.workerSrc = '<?php echo $basePath; ?>/js/pdfjs/build/pdf.worker.mjs';

    // Состояние
    let pdfDoc = null;
    let currentPage = 1;
    let currentZoom = 1.0;
    let totalPages = 0;
    let isRendering = false;

    // DOM элементы
    const canvas = document.getElementById('pdfCanvas');
    const ctx = canvas.getContext('2d');
    const viewer = document.getElementById('pdfViewer');
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');
    const zoomInBtn = document.getElementById('zoomIn');
    const zoomOutBtn = document.getElementById('zoomOut');
    const zoomFitBtn = document.getElementById('zoomFit');
    const zoomResetBtn = document.getElementById('zoomReset');
    const downloadBtn = document.getElementById('downloadBtn');
    const pageNumSpan = document.getElementById('pageNum');
    const pageCountSpan = document.getElementById('pageCount');
    const zoomLevelSpan = document.getElementById('zoomLevel');
    const loadingDiv = document.querySelector('.loading');

    // URL PDF
    <?php if ($isTemp) { ?>
    const pdfUrl = '<?php echo addslashes($pdfUrl); ?>';
    <?php } else { ?>
    const pdfUrl = './download.php?id=<?php echo $bookId; ?>';
    <?php } ?>

    // Рендер страницы
    async function renderPage(pageNum) {
        if (!pdfDoc || isRendering) return;

        isRendering = true;

        try {
            const page = await pdfDoc.getPage(pageNum);
            const viewport = page.getViewport({ scale: currentZoom });

            // Сохраняем позицию прокрутки
            const scrollTop = viewer.scrollTop;
            const scrollLeft = viewer.scrollLeft;

            // Устанавливаем размер canvas
            canvas.width = viewport.width;
            canvas.height = viewport.height;

            // Рендерим
            const renderContext = {
                canvasContext: ctx,
                viewport: viewport
            };

            await page.render(renderContext).promise;

            // Обновляем отображение
            pageNumSpan.textContent = currentPage;
            zoomLevelSpan.textContent = Math.round(currentZoom * 100) + '%';

            // Восстанавливаем прокрутку с задержкой
            setTimeout(() => {
                viewer.scrollTop = scrollTop;
                viewer.scrollLeft = scrollLeft;
            }, 50);

            updateButtons();

            // Сообщаем родительскому окну
            window.parent.postMessage({
                type: 'pagination',
                currentPage: currentPage,
                totalPages: totalPages
            }, '*');

        } catch (error) {
            console.error('Render error:', error);
        } finally {
            isRendering = false;
        }
    }

    // Загрузка PDF
    async function loadPdf() {
        loadingDiv.style.display = 'block';

        try {
            pdfDoc = await pdfjsLib.getDocument(pdfUrl).promise;
            totalPages = pdfDoc.numPages;
            pageCountSpan.textContent = totalPages;

            loadingDiv.style.display = 'none';
            await renderPage(currentPage);
            updateButtons();

            window.parent.postMessage({ type: 'ready' }, '*');

        } catch (error) {
            console.error('Error loading PDF:', error);
            loadingDiv.innerHTML = '<div class="error-message">' +
            '<i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 16px; display: block;"></i>' +
            'Не удалось загрузить PDF файл<br>' +
            '<small>' + error.message + '</small><br><br>' +
            '<a href="' + pdfUrl + '" download>Скачать PDF</a>' +
            '</div>';
            loadingDiv.style.display = 'block';
        }
    }

    // Обновление кнопок
    function updateButtons() {
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
    }

    // Навигация
    function nextPage() {
        if (currentPage < totalPages && !isRendering) {
            currentPage++;
            renderPage(currentPage);
            setTimeout(() => { viewer.scrollTop = 0; viewer.scrollLeft = 0; }, 100);
        }
    }

    function prevPage() {
        if (currentPage > 1 && !isRendering) {
            currentPage--;
            renderPage(currentPage);
            setTimeout(() => { viewer.scrollTop = 0; viewer.scrollLeft = 0; }, 100);
        }
    }

    // Масштабирование
    function zoomIn() {
        if (currentZoom < 3.0) {
            currentZoom = Math.min(3.0, currentZoom + 0.25);
            renderPage(currentPage);
        }
    }

    function zoomOut() {
        if (currentZoom > 0.3) {
            currentZoom = Math.max(0.3, currentZoom - 0.25);
            renderPage(currentPage);
        }
    }

    function zoomToFit() {
        if (!pdfDoc) return;

        pdfDoc.getPage(currentPage).then(function(page) {
            const viewport = page.getViewport({ scale: 1 });
            const containerWidth = viewer.clientWidth - 40;
            currentZoom = containerWidth / viewport.width;
            currentZoom = Math.min(2.0, Math.max(0.5, currentZoom));
            renderPage(currentPage);
        });
    }

    function zoomReset() {
        currentZoom = 1.0;
        renderPage(currentPage);
        setTimeout(() => {
            viewer.scrollTop = 0;
            viewer.scrollLeft = 0;
        }, 100);
    }

    function downloadPdf() {
        window.location.href = pdfUrl;
    }

    // Обработчики
    prevBtn.addEventListener('click', prevPage);
    nextBtn.addEventListener('click', nextPage);
    zoomInBtn.addEventListener('click', zoomIn);
    zoomOutBtn.addEventListener('click', zoomOut);
    zoomFitBtn.addEventListener('click', zoomToFit);
    zoomResetBtn.addEventListener('click', zoomReset);
    downloadBtn.addEventListener('click', downloadPdf);

    // Клавиатура
    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

        switch(e.key) {
            case 'ArrowLeft': prevPage(); e.preventDefault(); break;
            case 'ArrowRight': nextPage(); e.preventDefault(); break;
            case '+': case '=': zoomIn(); e.preventDefault(); break;
            case '-': case '_': zoomOut(); e.preventDefault(); break;
            case '0': zoomReset(); e.preventDefault(); break;
            case 'f': case 'F': zoomToFit(); e.preventDefault(); break;
        }
    });

    // Сообщения от родителя
    window.addEventListener('message', (event) => {
        if (!event.data?.type) return;

        switch(event.data.type) {
            case 'navigate':
                if (event.data.direction === 'next') nextPage();
                else if (event.data.direction === 'prev') prevPage();
                break;
            case 'getStatus':
                window.parent.postMessage({
                    type: 'pagination',
                    currentPage: currentPage,
                    totalPages: totalPages
                }, '*');
                break;
        }
    });

    // Адаптация при изменении размера окна
    let resizeTimeout;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => renderPage(currentPage), 300);
    });

    // Запуск
    loadPdf();
    </script>
    
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/all.min.css">
</body>
</html>

<?php
if (isset($isTemp) && $isTemp && file_exists($pdfUrl)) {
    register_shutdown_function(function () use ($pdfUrl) {
        @unlink($pdfUrl);
    });
}
?>
