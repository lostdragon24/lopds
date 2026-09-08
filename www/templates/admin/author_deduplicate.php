<?php
// templates/admin/author_deduplicate.php

$stats = $stats ?? [];
$history = $history ?? [];
$csrf_token = $csrf_token ?? '';
$threshold = $threshold ?? 70;
?>

<h1 class="mb-4">
    <i class="fas fa-users me-2 text-primary"></i>
    <?php echo __('author_deduplicate_title'); ?>
</h1>

<!-- Статистика -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <h2 class="text-primary" id="totalAuthors"><?php echo number_format($stats['total'] ?? 0); ?></h2>
                <p class="text-muted mb-0"><?php echo __('author_deduplicate_total_authors'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <h2 class="text-warning" id="processedCount">0</h2>
                <p class="text-muted mb-0"><?php echo __('author_deduplicate_processed'); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <h2 class="text-success" id="mergeCount"><?php echo $mergeCount ?? 0; ?></h2>
                <p class="text-muted mb-0"><?php echo __('author_deduplicate_merged'); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Настройки -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-light">
        <h6 class="mb-0">
            <i class="fas fa-sliders-h me-2"></i>
            <?php echo __('author_deduplicate_settings'); ?>
        </h6>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label"><?php echo __('author_deduplicate_threshold'); ?></label>
                <div class="input-group">
                    <input type="range" class="form-range" id="thresholdRange" 
                           min="50" max="95" step="5"
                           value="<?php echo $threshold; ?>"
                           oninput="document.getElementById('thresholdValue').textContent = this.value + '%'">
                    <span class="ms-3" id="thresholdValue"><?php echo $threshold; ?>%</span>
                </div>
            </div>
            
            <div class="col-md-3">
                <label class="form-label"><?php echo __('author_deduplicate_batch'); ?></label>
                <select class="form-select" id="batchSize">
                    <option value="20" selected>20</option>
                    <option value="50" >50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                    <option value="500">500</option>
                    <option value="1000">1000</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label"><?php echo __('author_deduplicate_search'); ?></label>
                <input type="text" class="form-control" id="searchAuthor" 
                       placeholder="<?php echo __('author_deduplicate_search_placeholder'); ?>">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary w-100" id="startScanBtn">
                    <i class="fas fa-play me-2"></i>
                    <?php echo __('author_deduplicate_start_scan'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Результаты -->
<div class="card shadow-sm">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <h6 class="mb-0">
            <i class="fas fa-list me-2"></i>
            <?php echo __('author_deduplicate_authors_list'); ?>
            <span class="badge bg-secondary ms-2" id="listCount">0</span>
        </h6>
        <div>
            <span class="badge bg-info" id="progressInfo"><?php echo __('author_deduplicate_ready'); ?></span>
        </div>
    </div>
    <div class="card-body">
        <!-- Прогресс-бар -->
        <div id="progressContainer" style="display: none;">
            <div class="progress mb-3" style="height: 30px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                     id="progressBar" role="progressbar" 
                     style="width: 0%">0%</div>
            </div>
            <div class="text-center text-muted small" id="progressText">
                <?php echo __('author_deduplicate_processing'); ?>
            </div>
        </div>
        
        <!-- Таблица авторов -->
        <div class="table-responsive" id="authorsTableContainer">
            <table class="table table-hover" id="authorsTable">
                <thead>
                    <tr>
                        <th><?php echo __('author_deduplicate_author'); ?></th>
                        <th><?php echo __('books'); ?></th>
                        <th><?php echo __('author_deduplicate_similar'); ?></th>
                        <th><?php echo __('actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="authorsList">
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                            <i class="fas fa-info-circle me-2"></i>
                            <?php echo __('author_deduplicate_start_hint'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Пагинация -->
        <nav id="paginationContainer" style="display: none;">
            <ul class="pagination justify-content-center" id="paginationList">
            </ul>
        </nav>
    </div>
</div>

<!-- История объединений -->
<?php if (!empty($history)): ?>
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-light">
            <h6 class="mb-0">
                <i class="fas fa-history me-2"></i>
                <?php echo __('author_deduplicate_history'); ?>
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th><?php echo __('date'); ?></th>
                            <th><?php echo __('author_deduplicate_from'); ?></th>
                            <th><?php echo __('author_deduplicate_to'); ?></th>
                            <th><?php echo __('books'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                            <tr>
                                <td><?php echo $entry['date']; ?></td>
                                <td><code><?php echo htmlspecialchars($entry['from']); ?></code></td>
                                <td><code class="text-success"><?php echo htmlspecialchars($entry['to']); ?></code></td>
                                <td><span class="badge bg-primary"><?php echo $entry['count']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Модальное окно для подтверждения объединения -->
<div class="modal fade" id="mergeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-compress me-2 text-warning"></i>
                    <?php echo __('author_deduplicate_merge_confirm_title'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><?php echo __('author_deduplicate_merge_confirm_text'); ?></p>
                <div class="alert alert-info">
                    <strong><?php echo __('author_deduplicate_from'); ?>:</strong>
                    <code id="mergeFrom"></code>
                    <br>
                    <strong><?php echo __('author_deduplicate_to'); ?>:</strong>
                    <code id="mergeTo" class="text-success"></code>
                    <br>
                    <strong><?php echo __('books'); ?>:</strong>
                    <span id="mergeBooks">0</span>
                </div>
                <p class="text-danger small">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    <?php echo __('author_deduplicate_merge_warning'); ?>
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <?php echo __('cancel'); ?>
                </button>
                <button type="button" class="btn btn-danger" id="confirmMergeBtn">
                    <i class="fas fa-compress me-1"></i>
                    <?php echo __('author_deduplicate_merge'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================
// СОСТОЯНИЕ
// ============================================
let currentPage = 1;
let totalPages = 1;
let totalAuthors = <?php echo $stats['total'] ?? 0; ?>;
let isScanning = false;
let processedCount = 0;
let mergeCount = <?php echo $mergeCount ?? 0; ?>;
let currentThreshold = <?php echo $threshold; ?>;
let currentBatchSize = 500;
let pendingMerges = [];
let searchTimeout = null;

// ============================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function showNotification(message, type = 'info') {
    // Используем стандартную функцию уведомлений из админки
    if (typeof showAdminMessage === 'function') {
        showAdminMessage(message, type);
    } else {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-' + type + ' alert-dismissible fade show';
        alertDiv.innerHTML = message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        document.querySelector('.container-fluid').prepend(alertDiv);
        setTimeout(() => {
            alertDiv.classList.remove('show');
            setTimeout(() => alertDiv.remove(), 300);
        }, 5000);
    }
}

// ============================================
// AJAX ЗАПРОСЫ (без jQuery)
// ============================================
function ajaxRequest(url, method, data, callback) {
    const xhr = new XMLHttpRequest();
    xhr.open(method, url, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    
    if (method === 'POST') {
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    }
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    callback(response);
                } catch (e) {
                    callback({ success: false, message: 'Invalid JSON response' });
                }
            } else {
                callback({ success: false, message: 'HTTP error: ' + xhr.status });
            }
        }
    };
    
    if (method === 'POST' && data) {
        xhr.send(new URLSearchParams(data));
    } else if (method === 'GET' && data) {
        xhr.send();
    } else {
        xhr.send();
    }
}

// ============================================
// ЗАГРУЗКА АВТОРОВ
// ============================================
function loadAuthors(page = 1) {
    const search = document.getElementById('searchAuthor').value.trim();
    const perPage = parseInt(document.getElementById('batchSize').value);

    let url = 'ajax/author_search.php?action=get_authors&page=' + page + '&perPage=' + perPage;
    if (search) {
        url += '&search=' + encodeURIComponent(search);
    }

    // Показываем индикатор загрузки
    document.getElementById('authorsList').innerHTML = `
        <tr>
            <td colspan="4" class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Загрузка...</span>
                </div>
                <p class="mt-2 text-muted">Загрузка списка авторов...</p>
            </td>
        </tr>
    `;

    ajaxRequest(url, 'GET', null, function(response) {
        if (response.success) {
            renderAuthors(response.data);
        } else {
            showNotification('Ошибка загрузки данных: ' + response.message, 'danger');
        }
    });
}

// Новая функция рендеринга с похожими авторами
function renderAuthorsWithSimilar(data) {
    const tbody = document.getElementById('authorsList');
    const countSpan = document.getElementById('listCount');

    currentPage = data.page;
    totalPages = data.totalPages;
    countSpan.textContent = data.total;

    if (data.authors.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center text-muted py-4">
                    <i class="fas fa-info-circle me-2"></i>
                    ${data.total === 0 ? '<?php echo __('author_deduplicate_author_find'); ?>' : '<?php echo __('author_deduplicate_not_result_find'); ?>'}
                </td>
            </tr>
        `;
        document.getElementById('paginationContainer').style.display = 'none';
        return;
    }

    let html = '';
    data.authors.forEach(function(author) {
        // Формируем список похожих авторов
        let similarHtml = '';
        if (author.similar_authors && author.similar_authors.length > 0) {
            author.similar_authors.forEach(function(item) {
                similarHtml += `
                    <span class="badge bg-warning text-dark me-1 similar-item"
                          data-author="${escapeHtml(item.name)}"
                          data-books="${item.books}"
                          data-similarity="${item.similarity}"
                          style="cursor:pointer;">
                        ${escapeHtml(item.name)} (${item.books})
                        <span class="badge bg-light text-dark">${Math.round(item.similarity * 100)}%</span>
                    </span>
                `;
            });
        } else {
            similarHtml = '<span class="text-muted"><?php echo __('author_deduplicate_no_matches_found'); ?></span>';
        }

        html += `
            <tr data-author="${escapeHtml(author.author)}" data-books="${author.book_count}">
                <td><strong>${escapeHtml(author.author)}</strong></td>
                <td><span class="badge bg-primary">${author.book_count}</span></td>
                <td class="similar-results">${similarHtml}</td>
                <td>
                    ${author.has_duplicates ? `
                        <button class="btn btn-sm btn-success merge-btn"
                                data-author="${escapeHtml(author.author)}">
                            <i class="fas fa-compress me-1"></i>
                            <?php echo __('author_deduplicate_merge'); ?>
                        </button>
                    ` : ''}
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;

    // Пагинация
    if (totalPages > 1) {
        document.getElementById('paginationContainer').style.display = 'block';
        renderPagination();
    } else {
        document.getElementById('paginationContainer').style.display = 'none';
    }

    // Привязываем обработчики для кликабельных похожих авторов
    attachSimilarHandlers();
}

// Обработчики для похожих авторов
function attachSimilarHandlers() {
    document.querySelectorAll('.similar-item').forEach(function(item) {
        item.addEventListener('click', function() {
            const row = this.closest('tr');
            const main = row.dataset.author;
            const duplicate = this.dataset.author;
            const books = this.dataset.books;
            showMergeModal(main, duplicate, books);
        });
    });

    // Кнопки объединения
    document.querySelectorAll('.merge-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const row = this.closest('tr');
            const author = row.dataset.author;
            // Ищем первый похожий вариант
            const firstSimilar = row.querySelector('.similar-item');
            if (firstSimilar) {
                showMergeModal(author, firstSimilar.dataset.author, firstSimilar.dataset.books);
            }
        });
    });
}

// ============================================
// ОТРИСОВКА АВТОРОВ
// ============================================
function renderAuthors(data) {
    const tbody = document.getElementById('authorsList');
    const countSpan = document.getElementById('listCount');

    currentPage = data.page;
    totalPages = data.totalPages;
    countSpan.textContent = data.total;

    if (data.authors.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center text-muted py-4">
                    <i class="fas fa-info-circle me-2"></i>
                    ${data.total === 0 ? 'Авторы не найдены' : 'Ничего не найдено'}
                </td>
            </tr>
        `;
        document.getElementById('paginationContainer').style.display = 'none';
        return;
    }

    let html = '';
    data.authors.forEach(function(author) {
        html += `
            <tr data-author="${escapeHtml(author.author)}" data-books="${author.book_count}">
                <td><strong>${escapeHtml(author.author)}</strong></td>
                <td><span class="badge bg-primary">${author.book_count}</span></td>
                <td>
                    <button class="btn btn-sm btn-outline-info find-similar-btn"
                            data-author="${escapeHtml(author.author)}"
                            data-loaded="false">
                        <i class="fas fa-search me-1"></i>
                        <?php echo __('author_deduplicate_find'); ?>
                    </button>
                    <span class="similar-results ms-2"></span>
                </td>
                <td>
                    <button class="btn btn-sm btn-success merge-selected-btn"
                            data-author="${escapeHtml(author.author)}"
                            style="display:none;">
                        <i class="fas fa-compress me-1"></i>
                        <?php echo __('author_deduplicate_merge'); ?>
                    </button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;

    // Пагинация
    if (totalPages > 1) {
        document.getElementById('paginationContainer').style.display = 'block';
        renderPagination();
    } else {
        document.getElementById('paginationContainer').style.display = 'none';
    }

    // Привязываем обработчики
    attachEventHandlers();
}

// НОВАЯ ФУНКЦИЯ: Автоматический поиск похожих для всех авторов на странице
function autoFindSimilarForAll() {
    const buttons = document.querySelectorAll('.find-similar-btn');
    let index = 0;

    function processNext() {
        if (index >= buttons.length) {
            return; // Все обработаны
        }

        const button = buttons[index];
        const author = button.dataset.author;

        // Запускаем поиск
        findSimilar(author, button);

        index++;

        // Небольшая задержка между запросами, чтобы не перегружать сервер
        setTimeout(processNext, 200);
    }

    processNext();
}

// ============================================
// ПАГИНАЦИЯ
// ============================================
function renderPagination() {
    const pagination = document.getElementById('paginationList');
    let html = '';
    
    if (currentPage > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" data-page="${currentPage - 1}">&laquo;</a></li>`;
    }
    
    let start = Math.max(1, currentPage - 3);
    let end = Math.min(totalPages, currentPage + 3);
    
    if (start > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`;
        if (start > 2) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
    }
    
    for (let i = start; i <= end; i++) {
        html += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a>
                </li>`;
    }
    
    if (end < totalPages) {
        if (end < totalPages - 1) html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        html += `<li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">${totalPages}</a></li>`;
    }
    
    if (currentPage < totalPages) {
        html += `<li class="page-item"><a class="page-link" href="#" data-page="${currentPage + 1}">&raquo;</a></li>`;
    }
    
    pagination.innerHTML = html;
    
    // Обработчики для пагинации
    pagination.querySelectorAll('a.page-link').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const page = parseInt(this.dataset.page);
            if (!isNaN(page)) {
                loadAuthors(page);
            }
        });
    });
}

// ============================================
// ПОИСК ПОХОЖИХ АВТОРОВ
// ============================================
function findSimilar(author, button) {
    const row = button.closest('tr');
    const resultsSpan = row.querySelector('.similar-results');
    const mergeBtn = row.querySelector('.merge-selected-btn');

    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Поиск...';

    const url = 'ajax/author_search.php?action=find_similar&author=' + encodeURIComponent(author) +
                '&threshold=' + (currentThreshold / 100) + '&limit=5';

    // ДОБАВЛЕНО: Отладочная информация
    // console.log('Запрос:', url);
    // console.log('Порог:', currentThreshold / 100);

    ajaxRequest(url, 'GET', null, function(response) {
        // ДОБАВЛЕНО: Логируем ответ
        // console.log('Ответ сервера:', response);

        button.disabled = false;
        button.innerHTML = '<i class="fas fa-search me-1"></i> <?php echo __('author_deduplicate_find'); ?>';
        button.dataset.loaded = 'true';

        if (response.success && response.similar && response.similar.length > 0) {
            // console.log('Найдено совпадений:', response.similar.length);
            let html = '';
            response.similar.forEach(function(item) {
                html += `
                    <span class="badge bg-warning text-dark me-1 similar-item"
                          data-author="${escapeHtml(item.name)}"
                          data-books="${item.books}"
                          data-similarity="${item.similarity}"
                          style="cursor:pointer;">
                        ${escapeHtml(item.name)} (${item.books})
                        <span class="badge bg-light text-dark">${Math.round(item.similarity * 100)}%</span>
                    </span>
                `;
            });
            resultsSpan.innerHTML = html;
            mergeBtn.style.display = 'inline-block';

            // Обработчики для клика по похожему автору
            resultsSpan.querySelectorAll('.similar-item').forEach(function(item) {
                item.addEventListener('click', function() {
                    const duplicate = this.dataset.author;
                    const books = this.dataset.books;
                    showMergeModal(author, duplicate, books);
                });
            });
        } else {
            // console.log('Совпадений нет. response.success:', response.success);
            // console.log('response.similar:', response.similar);
            resultsSpan.innerHTML = '<span class="text-muted small"><?php echo __('author_deduplicate_no_matches_found'); ?></span>';
            mergeBtn.style.display = 'none';
        }
    });
}

// ============================================
// ОБЪЕДИНЕНИЕ АВТОРОВ
// ============================================
function showMergeModal(main, duplicate, books) {
    document.getElementById('mergeFrom').textContent = duplicate;
    document.getElementById('mergeTo').textContent = main;
    document.getElementById('mergeBooks').textContent = books || '?';
    
    document.getElementById('confirmMergeBtn').dataset.main = main;
    document.getElementById('confirmMergeBtn').dataset.duplicate = duplicate;
    
    const modal = new bootstrap.Modal(document.getElementById('mergeModal'));
    modal.show();
}

document.getElementById('confirmMergeBtn').addEventListener('click', function() {
    const main = this.dataset.main;
    const duplicate = this.dataset.duplicate;
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Объединение...';

    const data = {
        action: 'merge',
        main: main,
        duplicate: duplicate,
        csrf_token: csrfToken
    };

    ajaxRequest('ajax/author_search.php', 'POST', data, function(response) {
        const modal = bootstrap.Modal.getInstance(document.getElementById('mergeModal'));
        modal.hide();

        if (response.success) {
            mergeCount += response.updated || 0;
            document.getElementById('mergeCount').textContent = mergeCount;
            showNotification('✅ ' + response.message, 'success');

            // Обновляем только текущую строку вместо всей страницы
            const row = document.querySelector(`tr[data-author="${escapeHtml(main)}"]`);
            if (row) {
                const similarSpan = row.querySelector('.similar-results');
                const mergeBtn = row.querySelector('.merge-selected-btn');
                const findBtn = row.querySelector('.find-similar-btn');

                similarSpan.innerHTML = '<span class="text-success small">✓ <?php echo __('author_deduplicate_merged'); ?></span>';
                mergeBtn.style.display = 'none';
                findBtn.dataset.loaded = 'false';
            }
        } else {
            showNotification('❌ ' + response.message, 'danger');
        }

        document.getElementById('confirmMergeBtn').disabled = false;
        document.getElementById('confirmMergeBtn').innerHTML = '<i class="fas fa-compress me-1"></i> <?php echo __('author_deduplicate_merge'); ?>';
    });
});


// ============================================
// ОБЪЕДИНЕНИЕ ВЫБРАННОГО АВТОРА
// ============================================
function mergeSelectedAuthor(author, button) {
    const row = button.closest('tr');
    const selectedRadio = row.querySelector('input[type="radio"]:checked');

    if (!selectedRadio) {
        showNotification('<?php echo __('author_deduplicate_merge_bath'); ?> ', 'warning');
        return;
    }

    const duplicate = selectedRadio.dataset.duplicate;
    const books = selectedRadio.dataset.books;
    showMergeModal(author, duplicate, books);
}


// ============================================
// СКАНИРОВАНИЕ ВСЕХ АВТОРОВ (ПОШАГОВОЕ)
// ============================================
function startScan() {
    if (isScanning) return;
    isScanning = true;
    
    document.getElementById('startScanBtn').disabled = true;
    document.getElementById('startScanBtn').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> <?php echo __('author_deduplicate_scan'); ?>...';
    document.getElementById('progressContainer').style.display = 'block';
    document.getElementById('progressInfo').textContent = '<?php echo __('author_deduplicate_scan'); ?>...';
    document.getElementById('progressInfo').className = 'badge bg-warning';
    
    processedCount = 0;
    let page = 1;
    const perPage = parseInt(document.getElementById('batchSize').value);
    const total = <?php echo $stats['total'] ?? 0; ?>;
    pendingMerges = [];
    
    function processNextPage() {
        const url = 'ajax/author_search.php?action=get_authors&page=' + page + '&perPage=' + perPage;
        
        ajaxRequest(url, 'GET', null, function(response) {
            if (!response.success || !response.data.authors || response.data.authors.length === 0) {
                finishScan();
                return;
            }
            
            // Обрабатываем каждого автора последовательно
            let index = 0;
            
            function processNextAuthor() {
                if (index >= response.data.authors.length) {
                    page++;
                    if (page <= response.data.totalPages) {
                        setTimeout(processNextPage, 100);
                    } else {
                        finishScan();
                    }
                    return;
                }
                
                const author = response.data.authors[index];
                const simUrl = 'ajax/author_search.php?action=find_similar&author=' + encodeURIComponent(author.author) + 
                              '&threshold=' + (currentThreshold / 100) + '&limit=5';
                
                ajaxRequest(simUrl, 'GET', null, function(simResponse) {
                    if (simResponse.success && simResponse.similar && simResponse.similar.length > 0) {
                        const bestMatch = simResponse.similar[0];
                        if (bestMatch.similarity >= currentThreshold / 100) {
                            pendingMerges.push({
                                main: bestMatch.name,
                                duplicate: author.author,
                                books: author.book_count
                            });
                        }
                    }
                    
                    processedCount++;
                    const progress = Math.min(100, (processedCount / total) * 100);
                    document.getElementById('progressBar').style.width = progress + '%';
                    document.getElementById('progressBar').textContent = Math.round(progress) + '%';
                    document.getElementById('progressText').textContent = 
                        'Обработано ' + processedCount + ' из ' + total + ' авторов';
                    
                    index++;
                    setTimeout(processNextAuthor, 50);
                });
            }
            
            processNextAuthor();
        });
    }
    
    function finishScan() {
        isScanning = false;
        document.getElementById('startScanBtn').disabled = false;
        document.getElementById('startScanBtn').innerHTML = '<i class="fas fa-play me-2"></i><?php echo __('author_deduplicate_start_scan'); ?> ';
        
        document.getElementById('progressInfo').textContent = '<?php echo __('author_deduplicate_scan_Done'); ?> ';
        document.getElementById('progressInfo').className = 'badge bg-success';
        
        // Показываем найденные дубликаты
        if (pendingMerges.length > 0) {
            showNotification('<?php echo __('author_deduplicate_found'); ?> ' + pendingMerges.length + ' <?php echo __('author_deduplicate_merge2'); ?>', 'info');
            loadAuthors(1);
        } else {
            showNotification('<?php echo __('author_deduplicate_not_found'); ?>', 'success');
        }
        
        document.getElementById('processedCount').textContent = processedCount;
    }
    
    processNextPage();
}

// ============================================
// ОБРАБОТЧИКИ СОБЫТИЙ
// ============================================
function attachEventHandlers() {
    // Кнопки "Найти похожих"
    document.querySelectorAll('.find-similar-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const author = this.dataset.author;
            findSimilar(author, this);
        });
    });

    // Кнопки "Объединить выбранное"
    document.querySelectorAll('.merge-selected-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const author = this.dataset.author;
            mergeSelectedAuthor(author, this);
        });
    });

    // ПОИСК с debounce
    const searchInput = document.getElementById('searchAuthor');
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            loadAuthors(1);
        }, 500); // Увеличили задержку до 500мс
    });

    // Enter в поле поиска
    searchInput.addEventListener('keyup', function(e) {
        if (e.key === 'Enter') {
            clearTimeout(searchTimeout);
            loadAuthors(1);
        }
    });
}


// ============================================
// ИНИЦИАЛИЗАЦИЯ
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Загружаем первую страницу
    loadAuthors(1);

    // Кнопка сканирования
    document.getElementById('startScanBtn').addEventListener('click', startScan);

    // Порог
    document.getElementById('thresholdRange').addEventListener('input', function() {
        currentThreshold = parseInt(this.value);
        document.getElementById('thresholdValue').textContent = currentThreshold + '%';
    });
});

</script>

<style>
#authorsTable tbody tr {
    transition: background-color 0.2s;
}
#authorsTable tbody tr:hover {
    background-color: #f8f9fa;
}
.similar-item {
    cursor: pointer;
    transition: transform 0.2s;
}
.similar-item:hover {
    transform: scale(1.05);
}
.progress {
    border-radius: 10px;
}
.progress-bar {
    transition: width 0.3s ease;
}
#authorsTable .badge {
    font-size: 0.8rem;
}

.similar-item-wrapper {
    display: inline-block;
}
.similar-label {
    cursor: pointer;
    transition: all 0.2s;
}
.similar-label:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.btn-check:checked + .similar-label {
    background-color: #ffc107;
    border-color: #ffc107;
    color: #000;
    font-weight: bold;
}
</style>
