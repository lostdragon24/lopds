<?php
// /install/templates/step2.php
?>

<div class="text-center mb-4">
    <i class="fas fa-road fa-4x text-primary mb-3"></i>
    <h3><?php echo __('install_step2_title'); ?></h3>
    <p class="text-muted"><?php echo __('install_step2_desc'); ?></p>
</div>

<form method="post" class="needs-validation" novalidate>
    <input type="hidden" name="action" value="save_paths">
    <input type="hidden" name="step" value="2">
    
    <div class="card mb-4">
        <div class="card-header bg-info text-white">
            <h6 class="mb-0"><i class="fas fa-book me-2"></i><?php echo __('install_books_dir'); ?></h6>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label"><?php echo __('install_books_dir_label'); ?></label>
                <div class="input-group">
                    <input type="text" class="form-control" name="books_dir" 
                           value="<?php echo htmlspecialchars($_SESSION['paths']['books_dir'] ?? Config::getBooksDir()); ?>" 
                           required>
                    <button class="btn btn-outline-secondary" type="button" 
                            onclick="document.querySelector('input[name=books_dir]').value = '<?php echo Config::getBasePath(); ?>/books/'">
                        <i class="fas fa-magic"></i> <?php echo __('install_books_dir_default'); ?>
                    </button>
                </div>
                <small class="text-muted">
                    <?php echo __('install_books_dir_hint'); ?>
                </small>
            </div>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong><?php echo __('install_tip'); ?></strong>
                <?php echo __('install_books_dir_tip'); ?>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-secondary text-white">
            <h6 class="mb-0"><i class="fas fa-bolt me-2"></i><?php echo __('install_cache_dir'); ?></h6>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label"><?php echo __('install_cache_dir_label'); ?></label>
                <div class="input-group">
                    <input type="text" class="form-control" name="cache_dir" 
                           value="<?php echo htmlspecialchars($_SESSION['paths']['cache_dir'] ?? Config::getCacheDir()); ?>">
                    <button class="btn btn-outline-secondary" type="button" 
                            onclick="document.querySelector('input[name=cache_dir]').value = '<?php echo Config::getBasePath(); ?>/cache'">
                        <i class="fas fa-magic"></i> <?php echo __('install_books_dir_default'); ?>
                    </button>
                </div>
                <small class="text-muted">
                    <?php echo __('install_cache_dir_hint'); ?>
                </small>
            </div>
        </div>
    </div>
    
    <div class="card mb-4">
        <div class="card-header bg-warning text-dark">
            <h6 class="mb-0"><i class="fas fa-robot me-2"></i><?php echo __('install_scanner_dir'); ?></h6>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label"><?php echo __('install_scanner_label'); ?></label>
                <div class="input-group">
                    <input type="text" class="form-control" name="scanner_path" 
                           value="<?php echo htmlspecialchars($_SESSION['paths']['scanner_path'] ?? Config::getScannerPath()); ?>">
                    <button class="btn btn-outline-secondary" type="button" 
                            onclick="document.querySelector('input[name=scanner_path]').value = '<?php echo Config::getBasePath(); ?>/scanner/book_scanner'">
                        <i class="fas fa-magic"></i> <?php echo __('install_books_dir_default'); ?>
                    </button>
                </div>
                <small class="text-muted">
                    <?php echo __('install_scanner_hint'); ?>
                </small>
            </div>
            
            <?php
            $scanner = new ScannerManager();
if ($scanner->isAvailable()):
    ?>
            <div class="alert alert-success mt-3">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo sprintf(__('install_scanner_found'), $scanner->getVersion() ?: __('install_scanner_version_unknown')); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Информация о текущих путях -->
    <div class="card mb-4 bg-light">
        <div class="card-header bg-dark text-white">
            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i><?php echo __('install_current_paths'); ?></h6>
        </div>
        <div class="card-body">
            <table class="table table-sm">
                <tr>
                    <th><?php echo __('install_base_path'); ?></th>
                    <td><code><?php echo Config::getBasePath(); ?></code></td>
                </tr>
                <tr>
                    <th><?php echo __('install_books_dir'); ?></th>
                    <td><code><?php echo Config::getBooksDir(); ?></code></td>
                </tr>
                <tr>
                    <th><?php echo __('install_cache_dir'); ?></th>
                    <td><code><?php echo Config::getCacheDir(); ?></code></td>
                </tr>
                <tr>
                    <th><?php echo __('install_scanner_default'); ?></th>
                    <td><code><?php echo Config::getScannerPath(); ?></code></td>
                </tr>
            </table>
            <small class="text-muted">
                <i class="fas fa-info-circle me-1"></i>
                <?php echo __('install_paths_note'); ?>
            </small>
        </div>
    </div>
    
    <div class="d-flex justify-content-between mt-4">
        <a href="?step=1" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-2"></i>
            <?php echo __('install_back'); ?>
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-2"></i>
            <?php echo __('install_save_paths'); ?>
        </button>
    </div>
</form>

<script>
// Валидация пути на стороне клиента
document.querySelector('form').addEventListener('submit', function(e) {
    const booksDir = document.querySelector('input[name=books_dir]').value.trim();
    if (!booksDir) {
        e.preventDefault();
        alert('<?php echo __('install_books_dir_required'); ?>');
        return;
    }
    
    // Проверка на абсолютный путь (для Unix-подобных систем)
    if (booksDir.charAt(0) !== '/' && booksDir.charAt(0) !== '~' && !booksDir.match(/^[A-Za-z]:/)) {
        if (!confirm('<?php echo __('install_path_relative_warning'); ?>')) {
            e.preventDefault();
            return;
        }
    }
});
</script>
