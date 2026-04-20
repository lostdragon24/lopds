<?php
// /install/templates/step4.php

// Проверяем, есть ли данные в сессии
if (!isset($_SESSION['db_config'])) {
    echo '<div class="alert alert-danger">';
    echo '<i class="fas fa-exclamation-triangle me-2"></i>';
    echo __('install_step4_no_config');
    echo '</div>';
    echo '<div class="text-center">';
    echo '<a href="?step=3" class="btn btn-primary">'.__('install_back_to_step3').'</a>';
    echo '</div>';

    return;
}

$dbType = $_SESSION['db_config']['type'] ?? 'sqlite';
$scanner = new ScannerManager();
$dbExists = $scanner->checkDatabaseExists();
$tablesExist = $scanner->checkTablesExist();

$dbConfig = $_SESSION['db_config'] ?? [];
$permissionsOk = true;
$permissionMessage = '';

if ('sqlite' === $dbConfig['type'] && isset($dbConfig['path'])) {
    $dbFile = $dbConfig['path'];
    $dbDir = dirname($dbFile);

    if (!is_writable($dbDir)) {
        $permissionsOk = false;
        $permissionMessage = sprintf(__('install_step4_dir_not_writable'), $dbDir);
    }

    if (file_exists($dbFile) && !is_writable($dbFile)) {
        $permissionsOk = false;
        $permissionMessage = __('install_step4_file_not_writable');
    }
}

$justCreated = false;
if ('sqlite' == $dbType && isset($_SESSION['db_created'])) {
    $justCreated = true;
    unset($_SESSION['db_created']);
}
?>

<?php if (!$permissionsOk) { ?>
    <div class="alert alert-danger mt-3">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong><?php echo __('install_step4_permission_problem'); ?></strong>
        <?php echo $permissionMessage; ?>
    </div>
<?php } ?>

<div class="text-center mb-4">
    <i class="fas fa-table fa-4x text-primary mb-3"></i>
    <h3><?php echo __('install_step4_title'); ?></h3>
    <p class="text-muted"><?php echo __('install_step4_desc'); ?></p>
</div>

<?php if (isset($_GET['success']) && 1 == $_GET['success']) { ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle me-2"></i>
        ✅ <?php echo __('install_step4_success'); ?>
    </div>
<?php } ?>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0"><?php echo __('install_step4_db_status'); ?></h6>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th><?php echo __('install_step4_db_type'); ?></th>
                        <td><?php echo strtoupper($dbType); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo __('install_step4_db_file'); ?></th>
                        <td>
                            <?php if ('sqlite' == $dbType) { ?>
                                <small><?php echo $_SESSION['db_config']['path'] ?? __('install_step4_not_specified'); ?></small>
                            <?php } else { ?>
                                <small><?php echo $_SESSION['db_config']['database'] ?? __('install_step4_not_specified'); ?></small>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo __('install_step4_db_exists'); ?></th>
                        <td>
                            <?php if ($dbExists || $justCreated) { ?>
                                <span class="badge bg-success">✅ <?php echo __('yes'); ?></span>
                            <?php } else { ?>
                                <span class="badge bg-warning">⚠️ <?php echo __('install_step4_will_be_created'); ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo __('install_step4_tables_exist'); ?></th>
                        <td>
                            <?php if ($tablesExist || $justCreated) { ?>
                                <span class="badge bg-success">✅ <?php echo __('yes'); ?></span>
                            <?php } else { ?>
                                <span class="badge bg-warning">⚠️ <?php echo __('install_step4_will_be_created'); ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0"><?php echo __('install_step4_tables_list'); ?></h6>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex align-items-center">
                        <i class="fas fa-book me-2 text-primary"></i>
                        <code>books</code> - <?php echo __('install_step4_table_books'); ?>
                    </li>
                    <li class="list-group-item d-flex align-items-center">
                        <i class="fas fa-star me-2 text-warning"></i>
                        <code>book_ratings</code> - <?php echo __('install_step4_table_ratings'); ?>
                    </li>
                    <li class="list-group-item d-flex align-items-center">
                        <i class="fas fa-heart me-2 text-danger"></i>
                        <code>book_favorites</code> - <?php echo __('install_step4_table_favorites'); ?>
                    </li>
                    <li class="list-group-item d-flex align-items-center">
                        <i class="fas fa-archive me-2 text-secondary"></i>
                        <code>archives</code> - <?php echo __('install_step4_table_archives'); ?>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($scanner->isAvailable()) { ?>
    <div class="alert alert-info">
        <div class="d-flex">
            <div class="me-3">
                <i class="fas fa-robot fa-2x"></i>
            </div>
            <div>
                <h6 class="alert-heading">✅ <?php echo __('install_step4_scanner_available'); ?></h6>
                <p class="mb-0">
                    <?php echo sprintf(__('install_step4_scanner_used'), $scanner->getVersion() ?: __('install_scanner_version_unknown')); ?>
                </p>
            </div>
        </div>
    </div>
<?php } else { ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?php echo __('install_step4_scanner_not_available'); ?>
    </div>
<?php } ?>

<!-- Форма создания базы данных -->
<form method="post" id="createDbForm" class="mt-4">
    <input type="hidden" name="action" value="create_database">
    <input type="hidden" name="db_type" value="<?php echo $dbType; ?>">
    <input type="hidden" name="step" value="4">
    
    <div class="row">
        <div class="col-md-6">
            <a href="?step=3" class="btn btn-secondary btn-lg w-100">
                <i class="fas fa-arrow-left me-2"></i>
                <?php echo __('install_back'); ?>
            </a>
        </div>
        <div class="col-md-6">
            <button type="submit" class="btn btn-success btn-lg w-100" id="createDbBtn">
                <i class="fas fa-play me-2"></i>
                <?php echo __('install_step4_create_btn'); ?>
            </button>
        </div>
    </div>
</form>

<!-- Кнопка для перехода к следующему шагу -->
<div class="text-center mt-4">
    <a href="?step=5" class="btn btn-primary btn-lg">
        <i class="fas fa-arrow-right me-2"></i>
        <?php echo __('install_step4_continue'); ?>
    </a>
    
    <?php if ($tablesExist || $justCreated || isset($_GET['success'])) { ?>
        <div class="alert alert-success mt-3">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo __('install_step4_ready'); ?>
        </div>
    <?php } ?>
</div>

<!-- Дополнительная информация для отладки -->
<?php if (isset($_GET['debug'])) { ?>
<div class="card mt-4">
    <div class="card-header bg-warning">
        <h6 class="mb-0"><?php echo __('debug_info'); ?></h6>
    </div>
    <div class="card-body">
        <pre class="mb-0"><?php print_r($_SESSION); ?></pre>
    </div>
</div>
<?php } ?>

<script>
// Простое подтверждение без лишних проверок
document.getElementById('createDbForm')?.addEventListener('submit', function(e) {
    if (!confirm('<?php echo __('install_step4_create_confirm'); ?>')) {
        e.preventDefault();
        return false;
    }
    
    // Показываем индикатор загрузки
    const btn = document.getElementById('createDbBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?php echo __('install_step4_creating'); ?>';
    btn.disabled = true;
});

// Убираем все лишние проверки, которые могли блокировать переключение на MySQL
console.log('Step 4 loaded, database type: <?php echo $dbType; ?>');
</script>