<?php
// templates/admin/browse_table.php

$table_name = $table_name ?? '';
$rows = $rows ?? [];
$columns = $columns ?? [];
$total = $total ?? 0;
$page = $page ?? 1;
$perPage = $perPage ?? 50;
$totalPages = $totalPages ?? 1;
$csrf_token = $csrf_token ?? '';
$debug = $debug ?? [];

// Отладка в консоль браузера
echo "<script>";
echo "console.log('Browse Table Debug:', " . json_encode([
    'table' => $table_name,
    'total' => $total,
    'rows_count' => count($rows),
    'columns' => $columns,
    'has_rows' => !empty($rows)
]) . ");";
if (!empty($rows)) {
    echo "console.log('First row:', " . json_encode($rows[0]) . ");";
}
echo "</script>";
?>

<h1 class="mb-4">
    <i class="fas fa-table me-2"></i>
    <?php echo __('admin_db_table_view'); ?>: <code><?php echo htmlspecialchars($table_name); ?></code>
</h1>

<div class="mb-3">
    <a href="?action=database" class="btn btn-secondary">
        <i class="fas fa-arrow-left me-2"></i>
        <?php echo __('back'); ?>
    </a>
    <span class="ms-3 text-muted">
        <?php echo __('admin_db_total_records'); ?>: <strong><?php echo number_format($total); ?></strong>
        <?php if ($total > 0): ?>
            (<?php echo __('admin_db_showing'); ?> 
            <?php echo min(($page - 1) * $perPage + 1, $total); ?> - 
            <?php echo min($page * $perPage, $total); ?>)
        <?php endif; ?>
    </span>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <?php if (empty($columns)): ?>
                            <th><?php echo __('admin_db_no_data'); ?></th>
                        <?php else: ?>
                            <?php foreach ($columns as $col): ?>
                                <th><?php echo htmlspecialchars($col); ?></th>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="<?php echo max(1, count($columns)); ?>" class="text-center text-muted py-4">
                                <?php if ($total > 0): ?>
                                    <i class="fas fa-exclamation-triangle text-warning me-2 fa-2x mb-2 d-block"></i>
                                    <?php echo __('admin_db_load_error'); ?>
                                    <div class="mt-2">
                                        <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">
                                            <i class="fas fa-sync-alt me-1"></i>
                                            <?php echo __('refresh'); ?>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <i class="fas fa-database me-2 fa-2x mb-2 d-block"></i>
                                    <?php echo __('admin_db_table_empty'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $rowIndex => $row): ?>
                            <tr class="table-row-<?php echo $rowIndex; ?>">
                                <?php foreach ($columns as $col): ?>
                                    <td class="table-cell" data-column="<?php echo htmlspecialchars($col); ?>">
                                        <?php
                                        $value = $row[$col] ?? null;
                                    if (is_null($value)) {
                                        echo '<span class="badge bg-light text-muted border">NULL</span>';
                                    } elseif (is_bool($value)) {
                                        echo $value ? '<span class="badge bg-success">true</span>' : '<span class="badge bg-secondary">false</span>';
                                    } elseif (is_numeric($value)) {
                                        echo '<span class="font-monospace">' . number_format($value) . '</span>';
                                    } elseif (is_string($value)) {
                                        if (empty($value)) {
                                            echo '<span class="badge bg-light text-muted border">' . __('admin_db_empty') . '</span>';
                                        } elseif (strlen($value) > 100) {
                                            $preview = htmlspecialchars(substr($value, 0, 100));
                                            $full = htmlspecialchars($value);
                                            echo '<span class="text-truncate d-inline-block" style="max-width: 300px;" title="' . $full . '">';
                                            echo $preview . '…';
                                            echo '</span>';
                                            echo ' <button class="btn btn-sm btn-link p-0 ms-1" onclick="showFullValue(\'' . addslashes($full) . '\')" title="' . __('admin_db_show_full') . '">';
                                            echo '<i class="fas fa-expand-alt text-muted"></i>';
                                            echo '</button>';
                                        } else {
                                            echo htmlspecialchars($value);
                                        }
                                    } else {
                                        echo '<pre class="mb-0 small"><code>' . htmlspecialchars(print_r($value, true)) . '</code></pre>';
                                    }
                                    ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Пагинация -->
    <?php if ($totalPages > 1): ?>
        <div class="card-footer">
            <nav aria-label="<?php echo __('pagination'); ?>">
                <ul class="pagination justify-content-center mb-0">
                    <?php if ($page > 1): ?>
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
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);

        if ($startPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?action=browse_table&table=<?php echo urlencode($table_name); ?>&page=1">1</a>
                        </li>
                        <?php if ($startPage > 2): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endif;

        for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?action=browse_table&table=<?php echo urlencode($table_name); ?>&page=<?php echo $i; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor;

        if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="?action=browse_table&table=<?php echo urlencode($table_name); ?>&page=<?php echo $totalPages; ?>">
                                <?php echo $totalPages; ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php if ($page < $totalPages): ?>
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
                    <?php endif; ?>
                </ul>
            </nav>
            
            <div class="text-center mt-2 text-muted small">
                <?php echo sprintf(__('admin_db_page_info'), $page, $totalPages); ?>
                <?php if ($total > 0): ?>
                    | <?php echo sprintf(__('admin_db_records_per_page'), $perPage); ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
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
                <pre id="fullValueContent" class="bg-light p-3 rounded" style="max-height: 400px; overflow: auto; white-space: pre-wrap; word-wrap: break-word;"></pre>
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
<?php if (isset($_GET['debug']) && ($_SESSION['admin_logged_in'] ?? false)): ?>
    <div class="card mt-4">
        <div class="card-header bg-warning">
            <h5 class="mb-0">
                <i class="fas fa-bug me-2"></i>
                <?php echo __('admin_db_debug_info'); ?>
            </h5>
        </div>
        <div class="card-body">
            <pre><?php
            echo "=== " . __('admin_db_debug_table') . " ===\n";
    echo "Table: $table_name\n";
    echo "Total rows: $total\n";
    echo "Page: $page\n";
    echo "Per page: $perPage\n";
    echo "Rows in this page: " . count($rows) . "\n";
    echo "Columns: " . implode(', ', $columns) . "\n";
    echo "Columns count: " . count($columns) . "\n";
    echo "\n=== " . __('admin_db_debug_rows') . " ===\n";
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
<?php endif; ?>

<script>
let currentFullValue = '';

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
            // Показываем временное уведомление
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check me-1"></i> <?php echo __('admin_db_copied'); ?>';
            setTimeout(() => {
                btn.innerHTML = originalText;
            }, 2000);
        }).catch(err => {
            console.error('Failed to copy: ', err);
            alert('<?php echo __('admin_db_copy_error'); ?>');
        });
    }
}

// Добавляем класс для обрезания длинного текста
document.addEventListener('DOMContentLoaded', function() {
    // Добавляем подсказки для ячеек с длинным текстом
    document.querySelectorAll('.table-cell').forEach(cell => {
        const text = cell.textContent?.trim();
        if (text && text.length > 100) {
            cell.style.cursor = 'pointer';
            cell.addEventListener('click', function(e) {
                const fullText = this.querySelector('.text-truncate')?.getAttribute('title');
                if (fullText) {
                    showFullValue(fullText);
                }
            });
        }
    });
});

// Функция для экспорта таблицы в CSV (опционально)
function exportToCSV() {
    const table = document.querySelector('table');
    if (!table) return;
    
    const rows = table.querySelectorAll('tr');
    const csv = [];
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('th, td');
        const rowData = [];
        cols.forEach(col => {
            let text = col.textContent?.trim() || '';
            // Экранируем кавычки
            text = text.replace(/"/g, '""');
            rowData.push(`"${text}"`);
        });
        csv.push(rowData.join(','));
    });
    
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `table_<?php echo $table_name; ?>_page_<?php echo $page; ?>.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// Добавляем кнопку экспорта, если нужно
document.addEventListener('DOMContentLoaded', function() {
    const btnGroup = document.querySelector('.mb-3');
    if (btnGroup && <?php echo !empty($rows) ? 'true' : 'false'; ?>) {
        const exportBtn = document.createElement('button');
        exportBtn.className = 'btn btn-sm btn-outline-success ms-2';
        exportBtn.innerHTML = '<i class="fas fa-download me-1"></i> <?php echo __('admin_db_export_csv'); ?>';
        exportBtn.onclick = exportToCSV;
        btnGroup.appendChild(exportBtn);
    }
});
</script>

<style>
.table td {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    transition: all 0.2s ease;
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
}
</style>