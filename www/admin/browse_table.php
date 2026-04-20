<?php
// admin/browse_table.php

$table_name = $table_name ?? '';
$rows = $rows ?? [];
$columns = $columns ?? [];
$total = $total ?? 0;
$page = $page ?? 1;
$perPage = $perPage ?? 50;
$totalPages = $totalPages ?? 1;
$csrf_token = $csrf_token ?? '';
?>

<h1 class="mb-4">
    <i class="fas fa-table me-2"></i>
    <?php echo __('admin_db_table_view'); ?>: <code><?php echo htmlspecialchars($table_name); ?></code>
</h1>

<div class="mb-3 d-flex flex-wrap align-items-center gap-2">
    <a href="?action=database" class="btn btn-secondary">
        <i class="fas fa-arrow-left me-2"></i>
        <?php echo __('back'); ?>
    </a>
    
    <span class="text-muted">
        <?php echo __('admin_db_total_records'); ?>: <strong><?php echo number_format($total); ?></strong>
        <?php if ($total > 0) { ?>
            (<?php echo __('admin_db_showing'); ?> 
            <?php echo min(($page - 1) * $perPage + 1, $total); ?> - 
            <?php echo min($page * $perPage, $total); ?>)
        <?php } ?>
    </span>
    
    <?php if (!empty($rows)) { ?>
    <div class="ms-auto">
        <button class="btn btn-sm btn-outline-success" onclick="exportToCSV()" title="<?php echo __('admin_db_export_csv'); ?>">
            <i class="fas fa-download me-1"></i>
            <?php echo __('admin_db_export_csv'); ?>
        </button>
        <button class="btn btn-sm btn-outline-info" onclick="copyTableToClipboard()" title="<?php echo __('admin_db_copy_table'); ?>">
            <i class="fas fa-copy me-1"></i>
            <?php echo __('admin_db_copy_table'); ?>
        </button>
    </div>
    <?php } ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0" id="dataTable">
                <thead class="table-light">
                    <tr>
                        <?php if (empty($columns)) { ?>
                            <th><?php echo __('admin_db_no_data'); ?></th>
                        <?php } else { ?>
                            <?php foreach ($columns as $col) { ?>
                                <th class="sortable" data-column="<?php echo htmlspecialchars($col); ?>">
                                    <?php echo htmlspecialchars($col); ?>
                                    <i class="fas fa-sort text-muted ms-1"></i>
                                </th>
                            <?php } ?>
                        <?php } ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) { ?>
                        <tr>
                            <td colspan="<?php echo max(1, count($columns)); ?>" class="text-center text-muted py-5">
                                <?php if ($total > 0) { ?>
                                    <i class="fas fa-exclamation-triangle text-warning fa-3x mb-3 d-block"></i>
                                    <h5><?php echo __('admin_db_load_error'); ?></h5>
                                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="location.reload()">
                                        <i class="fas fa-sync-alt me-1"></i>
                                        <?php echo __('refresh'); ?>
                                    </button>
                                <?php } else { ?>
                                    <i class="fas fa-database fa-3x mb-3 d-block"></i>
                                    <h5><?php echo __('admin_db_table_empty'); ?></h5>
                                    <p class="mb-0 text-muted"><?php echo __('admin_db_table_empty_desc'); ?></p>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } else { ?>
                        <?php foreach ($rows as $rowIndex => $row) { ?>
                            <tr class="table-row-<?php echo $rowIndex; ?>">
                                <?php foreach ($columns as $col) { ?>
                                    <td class="table-cell" data-column="<?php echo htmlspecialchars($col); ?>" data-value="<?php echo htmlspecialchars($row[$col] ?? ''); ?>">
                                        <?php
                                        $value = $row[$col] ?? null;
                                    if (is_null($value)) {
                                        echo '<span class="badge bg-light text-muted border">NULL</span>';
                                    } elseif (is_bool($value)) {
                                        echo $value
                                            ? '<span class="badge bg-success">'.__('admin_db_true').'</span>'
                                            : '<span class="badge bg-secondary">'.__('admin_db_false').'</span>';
                                    } elseif (is_numeric($value)) {
                                        echo '<span class="font-monospace">'.number_format($value).'</span>';
                                    } elseif (is_string($value)) {
                                        if (empty($value)) {
                                            echo '<span class="badge bg-light text-muted border">'.__('admin_db_empty').'</span>';
                                        } elseif (strlen($value) > 100) {
                                            $preview = htmlspecialchars(substr($value, 0, 100));
                                            $full = htmlspecialchars($value);
                                            echo '<span class="text-truncate d-inline-block" style="max-width: 280px;" title="'.$full.'">';
                                            echo $preview.'…';
                                            echo '</span>';
                                            echo ' <button class="btn btn-sm btn-link p-0 ms-1" onclick="showFullValue(\''.addslashes($full).'\')" title="'.__('admin_db_show_full').'">';
                                            echo '<i class="fas fa-expand-alt text-muted"></i>';
                                            echo '</button>';
                                        } else {
                                            echo htmlspecialchars($value);
                                        }
                                    } elseif (is_array($value) || is_object($value)) {
                                        echo '<pre class="mb-0 small"><code>'.htmlspecialchars(print_r($value, true)).'</code></pre>';
                                    } else {
                                        echo htmlspecialchars((string) $value);
                                    }
                                    ?>
                                    </td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Пагинация -->
    <?php if ($totalPages > 1) { ?>
        <div class="card-footer">
            <nav aria-label="<?php echo __('pagination'); ?>">
                <ul class="pagination justify-content-center mb-0">
                    <?php if ($page > 1) { ?>
                        <li class="page-item">
                            <a class="page-link" href="?action=browse_table&table=<?php echo urlencode($table_name); ?>&page=1" 
                               title="<?php echo __('admin_db_first_page'); ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?action=browse_table&table=<?php echo urlencode($table_name); ?>&page=<?php echo $page - 1; ?>"
                               title="<?php echo __('admin_db_prev_page'); ?>">
                                <i class="fas fa-angle-left"></i>
                            </a>
                        </li>
                    <?php } ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);

        if ($startPage > 1) { ?>
                        <li class="page-item">
                            <a class="page-link" href="?action=browse_table&table=<?php echo urlencode($table_name); ?>&page=1">1</a>
                        </li>
                        <?php if ($startPage > 2) { ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php } ?>
                    <?php }

        for ($i = $startPage; $i <= $endPage; ++$i) { ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?action=browse_table&table=<?php echo urlencode($table_name); ?>&page=<?php echo $i; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php }

        if ($endPage < $totalPages) { ?>
                        <?php if ($endPage < $totalPages - 1) { ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php } ?>
                        <li class="page-item">
                            <a class="page-link" href="?action=browse_table&table=<?php echo urlencode($table_name); ?>&page=<?php echo $totalPages; ?>">
                                <?php echo $totalPages; ?>
                            </a>
                        </li>
                    <?php } ?>
                    
                    <?php if ($page < $totalPages) { ?>
                        <li class="page-item">
                            <a class="page-link" href="?action=browse_table&table=<?php echo urlencode($table_name); ?>&page=<?php echo $page + 1; ?>"
                               title="<?php echo __('admin_db_next_page'); ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?action=browse_table&table=<?php echo urlencode($table_name); ?>&page=<?php echo $totalPages; ?>"
                               title="<?php echo __('admin_db_last_page'); ?>">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        </li>
                    <?php } ?>
                </ul>
            </nav>
            
            <div class="text-center mt-2 text-muted small">
                <?php echo sprintf(__('admin_db_page_info'), $page, $totalPages); ?>
                <?php if ($total > 0) { ?>
                    | <?php echo sprintf(__('admin_db_records_per_page'), $perPage); ?>
                <?php } ?>
            </div>
        </div>
    <?php } ?>
</div>

<!-- Модальное окно для просмотра полного значения -->
<div class="modal fade" id="fullValueModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-alt me-2"></i>
                    <?php echo __('admin_db_full_value'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="fullValueContent" class="bg-light p-3 rounded" style="max-height: 400px; overflow: auto; white-space: pre-wrap; word-wrap: break-word; font-family: monospace;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>
                    <?php echo __('close'); ?>
                </button>
                <button type="button" class="btn btn-primary" onclick="copyToClipboard()">
                    <i class="fas fa-copy me-1"></i>
                    <?php echo __('admin_db_copy'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Отладочная информация (только для администратора) -->
<?php if (isset($_GET['debug']) && ($_SESSION['admin_logged_in'] ?? false)) { ?>
    <div class="card mt-4">
        <div class="card-header bg-warning">
            <h5 class="mb-0">
                <i class="fas fa-bug me-2"></i>
                <?php echo __('admin_db_debug_info'); ?>
            </h5>
        </div>
        <div class="card-body">
            <pre class="mb-0" style="max-height: 400px; overflow: auto;"><?php
            echo '=== '.__('admin_db_debug_table')." ===\n";
    echo "Table: $table_name\n";
    echo "Total rows: $total\n";
    echo "Page: $page\n";
    echo "Per page: $perPage\n";
    echo 'Rows in this page: '.count($rows)."\n";
    echo 'Columns: '.implode(', ', $columns)."\n";
    echo 'Columns count: '.count($columns)."\n";
    echo "\n=== ".__('admin_db_debug_rows')." ===\n";
    if (empty($rows)) {
        echo "No rows data\n";
    } else {
        foreach ($rows as $idx => $row) {
            echo "\nRow $idx:\n";
            print_r($row);
        }
    }
    ?></pre>
        </div>
    </div>
<?php } ?>

<script>
let currentFullValue = '';
let currentSortColumn = '';
let currentSortOrder = 'asc';

function showFullValue(value) {
    currentFullValue = value;
    const contentEl = document.getElementById('fullValueContent');
    if (contentEl) {
        contentEl.textContent = value;
        const modal = new bootstrap.Modal(document.getElementById('fullValueModal'));
        modal.show();
    }
}

function copyToClipboard() {
    if (currentFullValue) {
        navigator.clipboard.writeText(currentFullValue).then(() => {
            const btn = event.target.closest('button');
            if (btn) {
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check me-1"></i> <?php echo __('admin_db_copied'); ?>';
                setTimeout(() => {
                    btn.innerHTML = originalText;
                }, 2000);
            }
        }).catch(err => {
            console.error('Failed to copy: ', err);
            alert('<?php echo __('admin_db_copy_error'); ?>');
        });
    }
}

function exportToCSV() {
    const table = document.getElementById('dataTable');
    if (!table) return;
    
    const rows = table.querySelectorAll('tr');
    const csv = [];
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('th, td');
        const rowData = [];
        cols.forEach(col => {
            // Пропускаем кнопки и иконки сортировки
            if (col.querySelector('.fa-sort')) {
                let text = col.textContent?.replace(/[↑↓]/g, '').trim() || '';
                text = text.replace(/"/g, '""');
                rowData.push(`"${text}"`);
            } else {
                let text = col.textContent?.trim() || '';
                // Убираем кнопки и бейджи
                text = text.replace(/[↑↓]/g, '').trim();
                text = text.replace(/"/g, '""');
                rowData.push(`"${text}"`);
            }
        });
        csv.push(rowData.join(','));
    });
    
    const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `table_<?php echo $table_name; ?>_page_<?php echo $page; ?>_<?php echo date('Y-m-d'); ?>.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function copyTableToClipboard() {
    const table = document.getElementById('dataTable');
    if (!table) return;
    
    let text = '';
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('th, td');
        const rowText = [];
        cols.forEach(col => {
            let cellText = col.textContent?.trim() || '';
            // Убираем иконки сортировки и кнопки
            cellText = cellText.replace(/[↑↓]/g, '').trim();
            rowText.push(cellText);
        });
        text += rowText.join('\t') + '\n';
    });
    
    navigator.clipboard.writeText(text).then(() => {
        showTemporaryMessage('<?php echo __('admin_db_copied_table'); ?>', 'success');
    }).catch(err => {
        console.error('Failed to copy: ', err);
        alert('<?php echo __('admin_db_copy_error'); ?>');
    });
}

function showTemporaryMessage(message, type = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 end-0 m-3`;
    alertDiv.style.zIndex = '9999';
    alertDiv.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        alertDiv.classList.remove('show');
        setTimeout(() => alertDiv.remove(), 300);
    }, 3000);
}

// Сортировка таблицы
function sortTable(column, order = 'asc') {
    const table = document.getElementById('dataTable');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const colIndex = Array.from(table.querySelectorAll('thead th')).findIndex(th => th.textContent.trim() === column);
    
    if (colIndex === -1) return;
    
    rows.sort((a, b) => {
        let aVal = a.querySelectorAll('td')[colIndex]?.dataset.value || '';
        let bVal = b.querySelectorAll('td')[colIndex]?.dataset.value || '';
        
        // Пытаемся определить тип
        if (!isNaN(parseFloat(aVal)) && !isNaN(parseFloat(bVal))) {
            aVal = parseFloat(aVal);
            bVal = parseFloat(bVal);
        }
        
        if (order === 'asc') {
            return aVal > bVal ? 1 : -1;
        } else {
            return aVal < bVal ? 1 : -1;
        }
    });
    
    rows.forEach(row => tbody.appendChild(row));
    
    // Обновляем иконки сортировки
    const headers = table.querySelectorAll('thead th');
    headers.forEach(th => {
        const icon = th.querySelector('.fa-sort');
        if (icon) {
            icon.className = 'fas fa-sort text-muted ms-1';
        }
    });
    
    const currentHeader = headers[colIndex];
    const icon = currentHeader.querySelector('.fa-sort');
    if (icon) {
        icon.className = order === 'asc' ? 'fas fa-sort-up text-primary ms-1' : 'fas fa-sort-down text-primary ms-1';
    }
}

// Инициализация сортировки
document.addEventListener('DOMContentLoaded', function() {
    // Добавляем обработчики для сортировки
    const headers = document.querySelectorAll('#dataTable thead th.sortable');
    headers.forEach(header => {
        header.style.cursor = 'pointer';
        header.addEventListener('click', function() {
            const column = this.textContent.trim();
            const icon = this.querySelector('.fa-sort');
            const isAsc = icon?.classList.contains('fa-sort-up');
            sortTable(column, isAsc ? 'desc' : 'asc');
        });
    });
    
    // Добавляем подсказки для ячеек с длинным текстом
    document.querySelectorAll('.table-cell .text-truncate').forEach(cell => {
        cell.style.cursor = 'pointer';
        cell.addEventListener('click', function() {
            const fullText = this.getAttribute('title');
            if (fullText) {
                showFullValue(fullText);
            }
        });
    });
    
    // Добавляем поиск по таблице (если таблица большая)
    const searchInput = createSearchInput();
    if (searchInput && <?php echo count($rows) > 50 ? 'true' : 'false'; ?>) {
        const card = document.querySelector('.card');
        const cardBody = card?.querySelector('.card-body');
        if (cardBody) {
            cardBody.insertBefore(searchInput, cardBody.firstChild);
        }
    }
});

function createSearchInput() {
    const searchDiv = document.createElement('div');
    searchDiv.className = 'p-3 border-bottom';
    searchDiv.innerHTML = `
        <div class="input-group">
            <span class="input-group-text"><i class="fas fa-search"></i></span>
            <input type="text" class="form-control" id="tableSearch" placeholder="<?php echo __('admin_db_search_table'); ?>">
            <button class="btn btn-outline-secondary" type="button" id="clearSearch">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    const searchInput = searchDiv.querySelector('#tableSearch');
    const clearBtn = searchDiv.querySelector('#clearSearch');
    
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#dataTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        clearBtn?.addEventListener('click', function() {
            if (searchInput) {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('keyup'));
            }
        });
    }
    
    return searchDiv;
}
</script>

<style>
.table td {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    transition: all 0.2s ease;
    vertical-align: middle;
}

.table td:hover {
    overflow: visible;
    white-space: normal;
    word-break: break-word;
    background-color: #f8f9fa;
    position: relative;
    z-index: 10;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.page-link {
    padding: 0.5rem 0.75rem;
}

.badge {
    font-family: monospace;
    font-size: 0.75rem;
}

.table-cell {
    vertical-align: middle;
}

.font-monospace {
    font-family: 'Courier New', monospace;
}

.text-truncate {
    max-width: 280px;
    display: inline-block;
    vertical-align: middle;
}

.btn-link {
    text-decoration: none;
    font-size: 0.7rem;
}

.btn-link:hover {
    text-decoration: underline;
}

.sortable {
    cursor: pointer;
    user-select: none;
}

.sortable:hover {
    background-color: rgba(0,0,0,0.05);
}

@media (max-width: 768px) {
    .table td {
        max-width: 150px;
        font-size: 0.8rem;
    }
    
    .text-truncate {
        max-width: 120px;
    }
    
    .badge {
        font-size: 0.65rem;
    }
    
    .btn-sm {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
    }
}
</style>