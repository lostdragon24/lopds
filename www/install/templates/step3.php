<?php
// /install/templates/step3.php
?>

<div class="text-center mb-4">
    <i class="fas fa-database fa-4x text-primary mb-3"></i>
    <h3><?php echo __('install_step3_title'); ?></h3>
    <p class="text-muted"><?php echo __('install_step3_desc'); ?></p>
</div>

<?php
// Определяем активную вкладку
$activeTab = 'sqlite';
if (isset($_SESSION['db_config']) && 'mysql' == $_SESSION['db_config']['type']) {
    $activeTab = 'mysql';
}
?>

<ul class="nav nav-tabs mb-4" id="dbTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo 'sqlite' == $activeTab ? 'active' : ''; ?>" 
                id="sqlite-tab" data-bs-toggle="tab" data-bs-target="#sqlite" 
                type="button" role="tab">
            <i class="fas fa-file-database me-2"></i><?php echo __('install_db_sqlite'); ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo 'mysql' == $activeTab ? 'active' : ''; ?>" 
                id="mysql-tab" data-bs-toggle="tab" data-bs-target="#mysql" 
                type="button" role="tab">
            <i class="fas fa-server me-2"></i><?php echo __('install_db_mysql'); ?>
        </button>
    </li>
</ul>

<div class="tab-content" id="dbTabsContent">
    <!-- SQLite форма -->
    <div class="tab-pane fade <?php echo 'sqlite' == $activeTab ? 'show active' : ''; ?>" 
         id="sqlite" role="tabpanel">
        <form class="test-connection-form" method="POST">
            <input type="hidden" name="action" value="test_connection">
            <input type="hidden" name="type" value="sqlite">
            
            <div class="mb-3">
                <label class="form-label"><?php echo __('install_sqlite_path'); ?></label>
                <div class="input-group">
                    <input type="text" class="form-control" name="path" 
                           value="<?php echo htmlspecialchars($_SESSION['db_config']['path'] ?? Config::getDbPath()); ?>" 
                           required>
                    <button class="btn btn-outline-secondary" type="button" 
                            onclick="suggestSqlitePath()">
                        <i class="fas fa-magic"></i> <?php echo __('install_sqlite_auto'); ?>
                    </button>
                </div>
                <small class="text-muted">
                    <?php echo __('install_sqlite_hint'); ?>
                </small>
            </div>
            
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="create_if_missing" 
                       id="createIfMissing" checked>
                <label class="form-check-label" for="createIfMissing">
                    <?php echo __('install_sqlite_create'); ?>
                </label>
            </div>
            
            <?php if (isset($_SESSION['db_diagnostics']) && isset($_SESSION['db_config']['type']) && 'sqlite' == $_SESSION['db_config']['type']) { ?>
                <div class="alert alert-info">
                    <h6><?php echo __('install_sqlite_diagnose'); ?></h6>
                    <pre class="mb-0 small"><?php print_r($_SESSION['db_diagnostics']); ?></pre>
                </div>
            <?php } ?>
            
            <div class="d-flex justify-content-between mt-4">
                <a href="?step=2" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i><?php echo __('install_back'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plug me-2"></i><?php echo __('install_test_connection'); ?>
                </button>
            </div>
        </form>
    </div>
    
    <!-- MySQL форма -->
    <div class="tab-pane fade <?php echo 'mysql' == $activeTab ? 'show active' : ''; ?>" 
         id="mysql" role="tabpanel">
        <form class="test-connection-form" method="POST">
            <input type="hidden" name="action" value="test_connection">
            <input type="hidden" name="type" value="mysql">
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('install_mysql_host'); ?></label>
                    <input type="text" class="form-control" name="host" 
                           value="<?php echo htmlspecialchars($_SESSION['db_config']['host'] ?? 'localhost'); ?>" 
                           required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('install_mysql_port'); ?></label>
                    <input type="number" class="form-control" name="port" 
                           value="<?php echo htmlspecialchars($_SESSION['db_config']['port'] ?? '3306'); ?>">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label"><?php echo __('install_mysql_dbname'); ?></label>
                <input type="text" class="form-control" name="database" 
                       value="<?php echo htmlspecialchars($_SESSION['db_config']['database'] ?? 'library'); ?>" 
                       required>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('install_mysql_user'); ?></label>
                    <input type="text" class="form-control" name="user" 
                           value="<?php echo htmlspecialchars($_SESSION['db_config']['user'] ?? ''); ?>" 
                           required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('install_mysql_password'); ?></label>
                    <input type="password" class="form-control" name="password" 
                           value="<?php echo htmlspecialchars($_SESSION['db_config']['password'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo __('install_mysql_warning'); ?>
            </div>
            
            <div class="d-flex justify-content-between mt-4">
                <a href="?step=2" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i><?php echo __('install_back'); ?>
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plug me-2"></i><?php echo __('install_test_connection'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($_SESSION['db_config'])) { ?>
    <div class="text-center mt-4">
        <a href="?step=4" class="btn btn-success btn-lg">
            <i class="fas fa-arrow-right me-2"></i>
            <?php echo __('install_continue_configured'); ?>
        </a>
    </div>
<?php } else { ?>
    <div class="alert alert-warning mt-4">
        <i class="fas fa-info-circle me-2"></i>
        <?php echo __('install_test_first'); ?>
    </div>
<?php } ?>

<!-- Информация о поддерживаемых базах данных -->
<div class="row mt-5">
    <div class="col-12">
        <div class="card bg-light">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i><?php echo __('install_db_info'); ?></h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-file-database text-primary me-2"></i><?php echo __('install_db_sqlite'); ?></h6>
                        <ul class="small">
                            <li>✅ <?php echo __('install_sqlite_pros_1'); ?></li>
                            <li>✅ <?php echo __('install_sqlite_pros_2'); ?></li>
                            <li>✅ <?php echo __('install_sqlite_pros_3'); ?></li>
                            <li>⚠️ <?php echo __('install_sqlite_cons_1'); ?></li>
                            <li>⚠️ <?php echo __('install_sqlite_cons_2'); ?></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-server text-success me-2"></i><?php echo __('install_db_mysql'); ?></h6>
                        <ul class="small">
                            <li>✅ <?php echo __('install_mysql_pros_1'); ?></li>
                            <li>✅ <?php echo __('install_mysql_pros_2'); ?></li>
                            <li>✅ <?php echo __('install_mysql_pros_3'); ?></li>
                            <li>⚠️ <?php echo __('install_mysql_cons_1'); ?></li>
                            <li>⚠️ <?php echo __('install_mysql_cons_2'); ?></li>
                        </ul>
                    </div>
                </div>
                <div class="alert alert-primary mt-3 mb-0">
                    <i class="fas fa-lightbulb me-2"></i>
                    <?php echo __('install_db_recommendation'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function suggestSqlitePath() {
    const basePath = '<?php echo Config::getBasePath(); ?>';
    document.querySelector('input[name="path"]').value = basePath + '/data/library.db';
}

// Дополнительная отладка
document.addEventListener('DOMContentLoaded', function() {
    console.log('Step 3 page loaded');
    console.log('Session ID:', '<?php echo session_id(); ?>');
    console.log('db_config in session:', <?php echo isset($_SESSION['db_config']) ? 'true' : 'false'; ?>);
});

// Переключение между SQLite и MySQL с подтверждением
document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
    tab.addEventListener('click', function(e) {
        const targetId = this.getAttribute('data-bs-target');
        const currentType = '<?php echo $activeTab; ?>';
        const newType = targetId === '#sqlite' ? 'sqlite' : 'mysql';
        
        if (currentType !== newType) {
            if (!confirm('<?php echo __('install_db_change_confirm'); ?>')) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        }
    });
});
</script>
