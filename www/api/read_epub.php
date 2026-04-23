<?php
// api/read_epub.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../init.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    die(__('error_invalid_id'));
}

$db = Database::getInstance();
$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book || strtolower($book['file_type']) !== 'epub') {
    http_response_code(404);
    die(__('book_not_found'));
}

// Определяем basePath для CSS и JS
$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = rtrim(dirname(dirname($scriptPath)), '/');

// Читаем файл и конвертируем в base64
if ($book['archive_path'] && $book['archive_internal_path']) {
    // Если книга в архиве
    $zip = new ZipArchive();
    if ($zip->open($book['archive_path']) === true) {
        $content = $zip->getFromName($book['archive_internal_path']);
        $zip->close();
    } else {
        http_response_code(500);
        die(__('error_extract_failed'));
    }
} else {
    // Прямой файл
    if (!file_exists($book['file_path'])) {
        http_response_code(404);
        die(__('error_file_not_found'));
    }
    $content = file_get_contents($book['file_path']);
}

if (!$content) {
    http_response_code(500);
    die(__('error_extract_failed'));
}

$base64 = base64_encode($content);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($book['title'] ?: __('book_untitled')); ?></title>
    
    <!-- JSZip должен быть загружен ДО EPUB.js -->
    <script src="<?php echo $basePath; ?>/js/epubjs/jszip.min.js"></script>
    <script src="<?php echo $basePath; ?>/js/epubjs/epub.min.js"></script>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f8f9fa;
            height: 100vh;
            overflow: hidden;
            font-family: 'Georgia', serif;
        }
        
        #viewer {
            width: 100%;
            height: 100%;
            background: white;
            overflow: hidden;
        }
        
        /* Стили для темной темы */
        body.dark-theme {
            background: #1a1a1a;
        }
        
        body.dark-theme #viewer {
            background: #1a1a1a;
            color: #e0e0e0;
        }
        
        .epub-view iframe {
            border: none;
            width: 100%;
            height: 100%;
            background: inherit;
        }
        
        #status {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #333;
            color: white;
            border-radius: 5px;
            display: none;
            z-index: 1000;
        }
        
        #status.success {
            background: #27ae60;
        }
        
        #status.error {
            background: #e74c3c;
        }
    </style>
</head>
<body>
    <div id="viewer"></div>
    <div id="status"></div>
    
    <script>
        (function() {
            const statusDiv = document.getElementById('status');
            const translations = {
                loading: '<?php echo __('reader_loading'); ?>',
                error: '<?php echo __('error_occurred'); ?>',
                success: '<?php echo __('success_operation'); ?>'
            };
            const bookData = '<?php echo $base64; ?>';
            
            function setStatus(msg, type = 'info') {
                statusDiv.textContent = msg;
                statusDiv.className = type;
                statusDiv.style.display = 'block';
                setTimeout(() => {
                    statusDiv.style.display = 'none';
                }, 3000);
                console.log(msg);
            }
            
            // Сообщаем родительскому окну о готовности
            window.parent.postMessage({ type: 'ready' }, '*');
            
            try {
                // Проверяем загрузку библиотек
                if (typeof JSZip === 'undefined') {
                    throw new Error('JSZip not loaded');
                }
                if (typeof ePub === 'undefined') {
                    throw new Error('EPUB.js not loaded');
                }
                
                console.log('JSZip version:', JSZip.version);
                console.log('EPUB.js version:', ePub.VERSION);
                console.log('Book data length:', bookData.length);
                
                // Создаем книгу
                const book = ePub();
                
                // Открываем книгу из base64
                book.open(bookData, 'base64').then(function() {
                    console.log('Book opened successfully');
                    
                    // Создаем рендерер
                    const rendition = book.renderTo('viewer', {
                        width: '100%',
                        height: '100%',
                        spread: 'none',
                        flow: 'paginated'
                    });
                    
                    // Отображаем первую страницу
                    return rendition.display().then(function() {
                        console.log('First page displayed');
                        
                        // Генерируем пагинацию
                        return book.locations.generate();
                    }).then(function() {
                        const total = book.locations.length() || 1;
                        console.log('Total pages:', total);
                        
                        // Отправляем информацию о пагинации
                        window.parent.postMessage({
                            type: 'pagination',
                            currentPage: 1,
                            totalPages: total
                        }, '*');
                        
                        // Отслеживаем смену страниц
                        rendition.on('relocated', function(location) {
                            if (location && location.start) {
                                window.parent.postMessage({
                                    type: 'pagination',
                                    currentPage: location.start.index + 1,
                                    totalPages: book.locations.length() || 1
                                }, '*');
                            }
                        });
                        
                        // Слушаем команды от родительского окна
                        window.addEventListener('message', function(event) {
                            if (!event.data || !event.data.type) return;
                            
                            switch (event.data.type) {
                                case 'navigate':
                                    if (event.data.direction === 'next') {
                                        rendition.next();
                                    } else if (event.data.direction === 'prev') {
                                        rendition.prev();
                                    }
                                    break;
                                    
                                case 'fontSize':
                                    rendition.themes.fontSize(event.data.size + '%');
                                    break;
                                    
                                case 'theme':
                                    document.body.classList.toggle('dark-theme', event.data.dark);
                                    if (event.data.dark) {
                                        rendition.themes.update({ 
                                            body: { 
                                                background: '#1a1a1a', 
                                                color: '#e0e0e0' 
                                            } 
                                        });
                                    } else {
                                        rendition.themes.update({ 
                                            body: { 
                                                background: '#fff', 
                                                color: '#000' 
                                            } 
                                        });
                                    }
                                    break;
                            }
                        });
                        
                    }).catch(function(err) {
                        console.error('Error generating locations:', err);
                        setStatus(translations.error + ': ' + err.message, 'error');
                    });
                    
                }).catch(function(err) {
                    console.error('Error opening book:', err);
                    setStatus(translations.error + ': ' + err.message, 'error');
                });
                
            } catch (err) {
                console.error('Initialization error:', err);
                setStatus(translations.error + ': ' + err.message, 'error');
            }
        })();
    </script>
</body>
</html>