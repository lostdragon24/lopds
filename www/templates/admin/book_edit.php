<!-- templates/admin/book_edit.php -->
<?php
$action = $action ?? 'edit';
$book = $book ?? null;
$genres = $genres ?? [];
$csrf_token = $csrf_token ?? '';
$error = $_SESSION['upload_error'] ?? '';
unset($_SESSION['upload_error']);

$pageTitle = $action === 'edit' ? __('admin_book_edit_title') : __('admin_book_add_title');
?>

<h1 class="mb-4">
    <i class="fas fa-<?php echo $action === 'edit' ? 'edit' : 'plus'; ?> me-2"></i>
    <?php echo $pageTitle; ?>
</h1>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post" action="index.php" id="bookForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="book_save">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <?php if ($book && isset($book['id'])): ?>
                <input type="hidden" name="id" value="<?php echo $book['id']; ?>">
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label required">
                        <?php echo __('admin_book_field_title'); ?>
                        <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control" name="title" 
                           value="<?php echo htmlspecialchars($book['title'] ?? ''); ?>" 
                           required
                           placeholder="<?php echo __('book_untitled'); ?>">
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">
                        <?php echo __('admin_book_field_author'); ?>
                    </label>
                    <input type="text" class="form-control" name="author" 
                           value="<?php echo htmlspecialchars($book['author'] ?? ''); ?>"
                           placeholder="<?php echo __('book_unknown_author'); ?>">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">
                        <?php echo __('admin_book_field_series'); ?>
                    </label>
                    <input type="text" class="form-control" name="series" 
                           value="<?php echo htmlspecialchars($book['series'] ?? ''); ?>"
                           placeholder="<?php echo __('admin_book_series_placeholder'); ?>">
                </div>
                
                <div class="col-md-6 mb-3">
                    <label class="form-label">
                        <?php echo __('admin_book_field_series_num'); ?>
                    </label>
                    <input type="number" class="form-control" name="series_number" 
                           value="<?php echo htmlspecialchars($book['series_number'] ?? ''); ?>"
                           min="1"
                           step="1"
                           placeholder="<?php echo __('admin_book_series_num_placeholder'); ?>">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">
                        <?php echo __('admin_book_field_genre'); ?>
                    </label>
                    <select class="form-select" name="genre" id="genreSelect">
                        <option value=""><?php echo __('admin_book_field_genre_select'); ?></option>
                        <?php foreach ($genres as $code => $name): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>"
                                <?php echo ($book['genre'] ?? '') === $code ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
    <div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">
            <?php echo __('admin_book_field_format'); ?>
        </label>
        <select class="form-select" name="file_type" id="file_type">
            <option value=""><?php echo __('admin_book_format_select'); ?></option>
            <option value="fb2" <?php echo (($book['file_type'] ?? '') === 'fb2') ? 'selected' : ''; ?>>FB2</option>
            <option value="epub" <?php echo (($book['file_type'] ?? '') === 'epub') ? 'selected' : ''; ?>>EPUB</option>
            <option value="pdf" <?php echo (($book['file_type'] ?? '') === 'pdf') ? 'selected' : ''; ?>>PDF</option>
            <option value="txt" <?php echo (($book['file_type'] ?? '') === 'txt') ? 'selected' : ''; ?>>TXT</option>
            <option value="mobi" <?php echo (($book['file_type'] ?? '') === 'mobi') ? 'selected' : ''; ?>>MOBI</option>
            <option value="zip" <?php echo (($book['file_type'] ?? '') === 'zip') ? 'selected' : ''; ?>>ZIP</option>
            <option value="rar" <?php echo (($book['file_type'] ?? '') === 'rar') ? 'selected' : ''; ?>>RAR</option>
        </select>
        <div class="form-text">
            <i class="fas fa-info-circle me-1"></i>
            <?php echo __('book_format'); ?>
        </div>
    </div>
    
            <div class="col-md-6 mb-3">
                <label class="form-label">
                    <?php echo __('book_added'); ?>
                </label>
                <input type="text" class="form-control bg-light"
               value="<?php echo htmlspecialchars($book['added_date'] ?? date('Y-m-d H:i:s')); ?>" 
               readonly disabled>
            </div>
        </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">
                        <?php echo __('admin_book_field_year'); ?>
                    </label>
                    <input type="number" class="form-control" name="year" 
                           value="<?php echo htmlspecialchars($book['year'] ?? ''); ?>"
                           min="0"
                           max="<?php echo date('Y'); ?>"
                           placeholder="<?php echo __('admin_book_year_placeholder'); ?>">
                </div>
                
                <div class="col-md-4 mb-3">
                    <label class="form-label">
                        <?php echo __('admin_book_field_lang'); ?>
                    </label>
                    <input type="text" class="form-control" name="language" 
                           value="<?php echo htmlspecialchars($book['language'] ?? ''); ?>"
                           placeholder="<?php echo __('admin_book_lang_placeholder'); ?>">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">
                    <?php echo __('admin_book_field_publisher'); ?>
                </label>
                <input type="text" class="form-control" name="publisher" 
                       value="<?php echo htmlspecialchars($book['publisher'] ?? ''); ?>"
                       placeholder="<?php echo __('admin_book_publisher_placeholder'); ?>">
            </div>
            
            <!-- Поле для загрузки файла (только для новых книг) -->
            <?php if ($action === 'add'): ?>
            <div class="mb-3">
                <label class="form-label required">
                    <?php echo __('admin_book_field_file'); ?>
                    <span class="text-danger">*</span>
                </label>
                <input type="file" class="form-control" name="book_file" id="bookFile" 
                       accept=".fb2,.epub,.pdf,.txt"
                       required
                       onchange="checkFileType(this)">
                <div class="form-text">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php echo __('admin_book_file_hint'); ?>
                </div>
                <div id="fileTypeError" class="text-danger mt-1" style="display: none;"></div>
            </div>
            <?php endif; ?>



            <div class="mb-3">
                <label class="form-label">
                    <?php echo __('admin_book_field_description'); ?>
                </label>
                <textarea class="form-control" name="description" rows="6" 
                          placeholder="<?php echo __('admin_book_description_placeholder'); ?>"><?php echo htmlspecialchars($book['description'] ?? '');?></textarea>
            </div>
            
            <?php if ($book && isset($book['file_path']) && $book['file_path']): ?>
            <div class="mb-3">
                <label class="form-label">
                    <?php echo __('book_path'); ?>
                </label>
                <div class="input-group">
                    <input type="text" class="form-control bg-light" 
                           value="<?php echo htmlspecialchars($book['file_path']); ?>" 
                           readonly>
                    <?php if (file_exists($book['file_path'])): ?>
                        <span class="input-group-text text-success">
                            <i class="fas fa-check-circle"></i>
                        </span>
                    <?php else: ?>
                        <span class="input-group-text text-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-save me-2"></i>
                    <?php echo __('save'); ?>
                </button>
                <a href="?action=books" class="btn btn-secondary">
                    <i class="fas fa-times me-2"></i>
                    <?php echo __('cancel'); ?>
                </a>
                <?php if ($action === 'edit' && $book && isset($book['id'])): ?>
                <a href="../book_detail.php?id=<?php echo $book['id']; ?>" 
                   class="btn btn-info ms-auto"
                   target="_blank">
                    <i class="fas fa-eye me-2"></i>
                    <?php echo __('view'); ?>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
// Проверка типа файла
function checkFileType(input) {
    const allowedTypes = ['fb2', 'epub', 'pdf', 'txt'];
    const file = input.files[0];
    const errorDiv = document.getElementById('fileTypeError');
    const submitBtn = document.getElementById('submitBtn');
    
    if (!file) return;
    
    const extension = file.name.split('.').pop().toLowerCase();
    
    if (!allowedTypes.includes(extension)) {
        errorDiv.textContent = '<?php echo __('admin_book_file_invalid'); ?>';
        errorDiv.style.display = 'block';
        submitBtn.disabled = true;
        input.value = '';
    } else {
        errorDiv.style.display = 'none';
        submitBtn.disabled = false;
        
        // Автоматически устанавливаем формат
        const formatSelect = document.querySelector('select[name="file_type"]');
        if (formatSelect) {
            formatSelect.value = extension;
        }
    }
}

// Валидация перед отправкой
document.getElementById('bookForm')?.addEventListener('submit', function(e) {
    const titleField = document.querySelector('input[name="title"]');
    if (!titleField.value.trim()) {
        e.preventDefault();
        alert('<?php echo __('admin_book_title_required_alert'); ?>');
        titleField.focus();
        return false;
    }
    
    // Проверка файла для новой книги
    <?php if ($action === 'add'): ?>
    const fileField = document.getElementById('bookFile');
    if (!fileField.files || fileField.files.length === 0) {
        e.preventDefault();
        alert('<?php echo __('admin_book_file_required'); ?>');
        fileField.focus();
        return false;
    }
    <?php endif; ?>
    
    // Блокируем кнопку после отправки
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?php echo __('admin_saving'); ?>';
});

// Валидация года
const yearField = document.querySelector('input[name="year"]');
if (yearField) {
    yearField.addEventListener('input', function() {
        const year = parseInt(this.value);
        const currentYear = new Date().getFullYear();
        if (year > currentYear) {
            this.setCustomValidity('<?php echo __('admin_book_year_invalid'); ?>');
        } else if (year < 0) {
            this.setCustomValidity('<?php echo __('admin_book_year_negative'); ?>');
        } else {
            this.setCustomValidity('');
        }
    });
}
</script>
