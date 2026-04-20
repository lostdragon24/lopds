<?php
// templates/admin/books.php

$genresList = $genresList ?? [];
$fileTypesList = $fileTypesList ?? [];
$authorsList = $authorsList ?? [];
$filter = $filter ?? [];
$message = $message ?? '';
$message_type = $message_type ?? '';
$csrf_token = $csrf_token ?? '';
?>

<h1 class="mb-4">
    <i class="fas fa-book me-2"></i>
    <?php echo __('admin_books_manage'); ?>
    
    <!-- кнопка "Добавить книгу" -->
    <a href="?action=book_edit" class="btn btn-success float-end">
        <i class="fas fa-plus me-2"></i>
        <?php echo __('admin_book_add_new'); ?>
    </a>
</h1>

<!-- Форма фильтрации -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <i class="fas fa-filter me-2"></i>
        <?php echo __('admin_books_filter'); ?>
    </div>
    <div class="card-body">
        <form method="get" action="index.php" class="row g-3">
            <input type="hidden" name="action" value="books">
            
            <div class="col-md-3">
                <label class="form-label"><?php echo __('admin_filter_search'); ?></label>
                <input type="text" class="form-control" name="filter[search]" 
                       value="<?php echo htmlspecialchars($filter['search'] ?? ''); ?>" 
                       placeholder="<?php echo __('admin_filter_search_placeholder'); ?>">
            </div>
            
            <div class="col-md-2">
                <label class="form-label"><?php echo __('admin_filter_genre'); ?></label>
                <select class="form-select" name="filter[genre]">
                    <option value=""><?php echo __('admin_filter_genre_all'); ?></option>
                    <?php foreach ($genresList as $g) { ?>
                        <option value="<?php echo htmlspecialchars($g['genre']); ?>" 
                            <?php echo ($filter['genre'] ?? '') === $g['genre'] ? 'selected' : ''; ?>>
                            <?php
                            $readable = GenreManager::getReadableName($g['genre']);
                        echo htmlspecialchars($readable ?: $g['genre']);
                        ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label"><?php echo __('admin_filter_format'); ?></label>
                <select class="form-select" name="filter[file_type]">
                    <option value=""><?php echo __('admin_filter_format_all'); ?></option>
                    <?php foreach ($fileTypesList as $ft) { ?>
                        <option value="<?php echo htmlspecialchars($ft['file_type']); ?>"
                            <?php echo ($filter['file_type'] ?? '') === $ft['file_type'] ? 'selected' : ''; ?>>
                            <?php echo strtoupper($ft['file_type']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label"><?php echo __('admin_filter_author'); ?></label>
                <select class="form-select" name="filter[author]">
                    <option value=""><?php echo __('admin_filter_author_all'); ?></option>
                    <?php foreach ($authorsList as $a) { ?>
                        <option value="<?php echo htmlspecialchars($a['author']); ?>"
                            <?php echo ($filter['author'] ?? '') === $a['author'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(mb_substr($a['author'], 0, 40)); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            
            <div class="col-md-1">
                <label class="form-label"><?php echo __('admin_filter_year'); ?></label>
                <input type="number" class="form-control" name="filter[year]" 
                       value="<?php echo htmlspecialchars($filter['year'] ?? ''); ?>" 
                       placeholder="<?php echo __('admin_filter_year_placeholder'); ?>"
                       min="0"
                       max="<?php echo date('Y'); ?>">
            </div>
            
            <div class="col-md-1">
                <label class="form-label"><?php echo __('admin_filter_archive'); ?></label>
                <select class="form-select" name="filter[in_archive]">
                    <option value=""><?php echo __('admin_filter_archive_all'); ?></option>
                    <option value="yes" <?php echo ($filter['in_archive'] ?? '') === 'yes' ? 'selected' : ''; ?>>
                        <?php echo __('admin_filter_archive_yes'); ?>
                    </option>
                    <option value="no" <?php echo ($filter['in_archive'] ?? '') === 'no' ? 'selected' : ''; ?>>
                        <?php echo __('admin_filter_archive_no'); ?>
                    </option>
                </select>
            </div>
            
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search me-2"></i><?php echo __('admin_filter_apply'); ?>
                </button>
                <a href="?action=books" class="btn btn-secondary">
                    <i class="fas fa-undo me-2"></i><?php echo __('admin_filter_reset'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Массовые операции -->
<div class="card mb-4">
    <div class="card-header bg-warning text-dark">
        <i class="fas fa-tasks me-2"></i>
        <?php echo __('admin_bulk_actions'); ?>
    </div>
    <div class="card-body">
        <form method="post" action="index.php" id="bulkForm">
            <input type="hidden" name="action" value="book_bulk">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="bulk_action" id="bulkAction" value="">
            
            <div class="row align-items-center">
                <div class="col-md-3">
                    <select class="form-select" id="bulkActionSelect">
                        <option value=""><?php echo __('admin_bulk_select'); ?></option>
                        <option value="delete"><?php echo __('admin_bulk_delete'); ?></option>
                        <option value="update_genre"><?php echo __('admin_bulk_genre'); ?></option>
                        <option value="update_year"><?php echo __('admin_bulk_year'); ?></option>
                    </select>
                </div>
                
                <div class="col-md-3" id="bulkGenreField" style="display: none;">
                    <select class="form-select" name="bulk_genre">
                        <option value=""><?php echo __('admin_bulk_genre_select'); ?></option>
                        <?php foreach ($genresList as $g) { ?>
                            <option value="<?php echo htmlspecialchars($g['genre']); ?>">
                                <?php
                            $readable = GenreManager::getReadableName($g['genre']);
                            echo htmlspecialchars($readable ?: $g['genre']);
                            ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                
                <div class="col-md-2" id="bulkYearField" style="display: none;">
                    <input type="number" class="form-control" name="bulk_year" 
                           placeholder="<?php echo __('admin_bulk_year_placeholder'); ?>"
                           min="0"
                           max="<?php echo date('Y'); ?>">
                </div>
                
                <div class="col-md-2">
                    <button type="button" class="btn btn-warning" onclick="executeBulkAction()">
                        <i class="fas fa-check me-2"></i><?php echo __('admin_bulk_apply'); ?>
                    </button>
                </div>
            </div>
            
            <div class="mt-2">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php echo __('admin_bulk_hint'); ?>
                </small>
            </div>
        </form>
    </div>
</div>

<!-- Таблица книг -->
<div class="card">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span>
            <i class="fas fa-list me-2"></i>
            <?php echo sprintf(__('admin_books_list'), $total); ?>
        </span>
        <span>
            <?php echo sprintf(__('admin_books_page'), $page, ceil($total / 20)); ?>
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="30">
                            <input type="checkbox" id="selectAll" onclick="toggleAll(this)">
                        </th>
                        <th><?php echo __('admin_books_id'); ?></th>
                        <th><?php echo __('admin_books_title'); ?></th>
                        <th><?php echo __('admin_books_author'); ?></th>
                        <th><?php echo __('admin_books_genre'); ?></th>
                        <th><?php echo __('admin_books_series'); ?></th>
                        <th><?php echo __('admin_books_year'); ?></th>
                        <th><?php echo __('admin_books_format'); ?></th>
                        <th><?php echo __('admin_books_rating'); ?></th>
                        <th><?php echo __('admin_books_added'); ?></th>
                        <th><?php echo __('admin_books_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($books)) { ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">
                                <i class="fas fa-book-open fa-3x mb-2"></i><br>
                                <?php echo __('admin_books_empty'); ?>
                            </td>
                        </tr>
                    <?php } else { ?>
                        <?php foreach ($books as $book) { ?>
                        <tr id="book-row-<?php echo $book['id']; ?>">
                            <td>
                                <input type="checkbox" name="book_ids[]" value="<?php echo $book['id']; ?>" 
                                       class="book-checkbox" form="bulkForm">
                            </td>
                            <td><?php echo $book['id']; ?></td>
                            <td>
                                <a href="../book_detail.php?id=<?php echo $book['id']; ?>" 
                                   target="_blank"
                                   title="<?php echo __('admin_book_view'); ?>">
                                    <?php echo htmlspecialchars(mb_substr($book['title'] ?: __('book_untitled'), 0, 50)); ?>
                                    <?php if (mb_strlen($book['title'] ?? '') > 50) { ?>...<?php } ?>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($book['author'])) { ?>
                                    <?php echo htmlspecialchars(mb_substr($book['author'], 0, 35)); ?>
                                <?php } else { ?>
                                    <span class="text-muted">—</span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php
                            $readable = GenreManager::getReadableName($book['genre'] ?? '');
                            echo htmlspecialchars($readable ?: ($book['genre'] ?: '—'));
                            ?>
                            </td>
                            <td>
                                <?php if ($book['series']) { ?>
                                    <span title="<?php echo htmlspecialchars($book['series']); ?>">
                                        <?php echo htmlspecialchars(mb_substr($book['series'], 0, 25)); ?>
                                        <?php if (mb_strlen($book['series']) > 25) { ?>...<?php } ?>
                                    </span>
                                    <?php if ($book['series_number']) { ?>
                                        <span class="badge bg-secondary ms-1">#<?php echo $book['series_number']; ?></span>
                                    <?php } ?>
                                <?php } else { ?>
                                    <span class="text-muted">—</span>
                                <?php } ?>
                            </td>
                            <td><?php echo $book['year'] ?: '—'; ?></td>
                            <td>
                                <span class="badge bg-info"><?php echo strtoupper($book['file_type'] ?: '?'); ?></span>
                                <?php if ($book['archive_path']) { ?>
                                    <span class="badge bg-secondary" title="<?php echo __('book_in_archive'); ?>">
                                        <i class="fas fa-archive"></i>
                                    </span>
                                <?php } ?>
                            </td>
                            <td class="text-center">
                                <?php if (($book['votes'] ?? 0) > 0) { ?>
                                    <div class="d-flex flex-column align-items-center">
                                        <span class="text-warning fw-bold"><?php echo number_format($book['avg_rating'] ?? 0, 1); ?></span>
                                        <small class="text-muted">(<?php echo $book['votes']; ?>)</small>
                                    </div>
                                <?php } else { ?>
                                    <small class="text-muted">—</small>
                                <?php } ?>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?php echo date('d.m.Y', strtotime($book['added_date'])); ?>
                                </small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="?action=book_edit&id=<?php echo $book['id']; ?>" 
                                       class="btn btn-outline-primary" 
                                       title="<?php echo __('admin_book_edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="../book_detail.php?id=<?php echo $book['id']; ?>" 
                                       class="btn btn-outline-info" 
                                       title="<?php echo __('admin_book_view'); ?>"
                                       target="_blank">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-danger" 
                                            onclick="deleteBook(<?php echo $book['id']; ?>, '<?php echo htmlspecialchars(addslashes($book['title'] ?: __('book_untitled'))); ?>')"
                                            title="<?php echo __('admin_book_delete'); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php } ?>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Пагинация -->
    <?php if ($total > 20) { ?>
    <div class="card-footer">
        <nav aria-label="<?php echo __('pagination'); ?>">
            <ul class="pagination justify-content-center mb-0">
                <?php
                $totalPages = ceil($total / 20);
        $currentPage = $page;
        $queryParams = buildFilterQuery($filter);

        if ($currentPage > 1) { ?>
                    <li class="page-item">
                        <a class="page-link" href="?action=books&page=<?php echo $currentPage - 1; ?><?php echo $queryParams; ?>">
                            <i class="fas fa-chevron-left"></i>
                            <span class="visually-hidden"><?php echo __('previous'); ?></span>
                        </a>
                    </li>
                <?php }

        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);

        if ($start > 1) { ?>
                    <li class="page-item">
                        <a class="page-link" href="?action=books&page=1<?php echo $queryParams; ?>">1</a>
                    </li>
                    <?php if ($start > 2) { ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php } ?>
                <?php }

        for ($i = $start; $i <= $end; ++$i) { ?>
                    <li class="page-item <?php echo $i == $currentPage ? 'active' : ''; ?>">
                        <a class="page-link" href="?action=books&page=<?php echo $i; ?><?php echo $queryParams; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php }

        if ($end < $totalPages) { ?>
                    <?php if ($end < $totalPages - 1) { ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php } ?>
                    <li class="page-item">
                        <a class="page-link" href="?action=books&page=<?php echo $totalPages; ?><?php echo $queryParams; ?>">
                            <?php echo $totalPages; ?>
                        </a>
                    </li>
                <?php }

        if ($currentPage < $totalPages) { ?>
                    <li class="page-item">
                        <a class="page-link" href="?action=books&page=<?php echo $currentPage + 1; ?><?php echo $queryParams; ?>">
                            <i class="fas fa-chevron-right"></i>
                            <span class="visually-hidden"><?php echo __('next'); ?></span>
                        </a>
                    </li>
                <?php } ?>
            </ul>
        </nav>
    </div>
    <?php } ?>
</div>

<!-- Форма удаления (скрытая) -->
<form method="post" action="index.php" id="deleteForm">
    <input type="hidden" name="action" value="book_delete">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <input type="hidden" name="id" id="deleteId" value="">
</form>

<script>
// Выделить все чекбоксы
function toggleAll(source) {
    document.querySelectorAll('.book-checkbox').forEach(cb => cb.checked = source.checked);
    updateBulkButtonState();
}

// Обновить состояние кнопки массовых операций
function updateBulkButtonState() {
    const checked = document.querySelectorAll('.book-checkbox:checked').length;
    const bulkBtn = document.querySelector('#bulkActionSelect');
    if (bulkBtn) {
        bulkBtn.disabled = checked === 0;
    }
}

// Показать/скрыть поля для массовых операций
document.getElementById('bulkActionSelect').addEventListener('change', function() {
    const action = this.value;
    const genreField = document.getElementById('bulkGenreField');
    const yearField = document.getElementById('bulkYearField');
    
    genreField.style.display = action === 'update_genre' ? 'block' : 'none';
    yearField.style.display = action === 'update_year' ? 'block' : 'none';
});

// Выполнить массовую операцию
function executeBulkAction() {
    const actionSelect = document.getElementById('bulkActionSelect');
    const action = actionSelect.value;
    
    if (!action) {
        alert('<?php echo __('admin_bulk_select_error'); ?>');
        return;
    }
    
    const checked = document.querySelectorAll('.book-checkbox:checked');
    if (checked.length === 0) {
        alert('<?php echo __('admin_bulk_no_selection'); ?>');
        return;
    }
    
    let confirmMsg = '';
    if (action === 'delete') {
        confirmMsg = '<?php echo __('admin_bulk_delete_confirm'); ?>'.replace('%d', checked.length);
        if (!confirm(confirmMsg)) {
            return;
        }
    } else if (action === 'update_genre') {
        const genre = document.querySelector('select[name="bulk_genre"]').value;
        if (!genre) {
            alert('<?php echo __('admin_bulk_genre_select_error'); ?>');
            return;
        }
        confirmMsg = '<?php echo __('admin_bulk_genre_confirm'); ?>'.replace('%s', 
            document.querySelector('select[name="bulk_genre"] option:checked').text);
        if (!confirm(confirmMsg)) {
            return;
        }
    } else if (action === 'update_year') {
        const year = document.querySelector('input[name="bulk_year"]').value;
        if (!year || year < 0 || year > new Date().getFullYear()) {
            alert('<?php echo __('admin_bulk_year_invalid'); ?>');
            return;
        }
        confirmMsg = '<?php echo __('admin_bulk_year_confirm'); ?>'.replace('%s', year);
        if (!confirm(confirmMsg)) {
            return;
        }
    }
    
    document.getElementById('bulkAction').value = action;
    document.getElementById('bulkForm').submit();
}

// Удалить одну книгу
function deleteBook(id, title) {
    if (confirm('<?php echo __('admin_book_delete_confirm'); ?>'.replace('%s', title))) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}

// Инициализация
document.addEventListener('DOMContentLoaded', function() {
    // Обновляем состояние кнопки при загрузке
    updateBulkButtonState();
    
    // Слушаем изменения чекбоксов
    document.querySelectorAll('.book-checkbox').forEach(cb => {
        cb.addEventListener('change', updateBulkButtonState);
    });
});
</script>

<?php
// Вспомогательная функция для построения строки фильтра
function buildFilterQuery($filter)
{
    if (empty($filter)) {
        return '';
    }
    $parts = [];
    foreach ($filter as $key => $value) {
        if ('' !== $value) {
            $parts[] = "filter[$key]=".urlencode($value);
        }
    }

    return $parts ? '&'.implode('&', $parts) : '';
}
?>

<style>
.table td {
    vertical-align: middle;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.table th {
    white-space: nowrap;
}

.btn-group .btn {
    padding: 0.25rem 0.5rem;
}

.badge {
    font-size: 0.75rem;
    padding: 0.35rem 0.5rem;
}

.page-link i {
    font-size: 0.8rem;
}

.card-header .badge {
    font-size: 0.8rem;
}

@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.85rem;
    }
    
    .btn-group .btn {
        padding: 0.2rem 0.4rem;
    }
    
    .badge {
        font-size: 0.7rem;
    }
}
</style>
