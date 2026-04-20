<?php
// /install/templates/step1.php

$dirs = checkDirectories();
$scanner = new ScannerManager();
?>

<div class="text-center mb-4">
    <i class="fas fa-folder-open fa-4x text-primary mb-3"></i>
    <h3><?php echo __('install_step1_title'); ?></h3>
    <p class="text-muted"><?php echo __('install_step1_desc'); ?></p>
</div>

<!-- Статус сканера -->
<div class="alert <?php echo $scanner->isAvailable() ? 'alert-success' : 'alert-warning'; ?> mb-4">
    <div class="d-flex align-items-center">
        <i class="fas fa-robot fa-2x me-3"></i>
        <div>
            <strong><?php echo __('install_scanner'); ?></strong><br>
            <?php if ($scanner->isAvailable()) { ?>
                <?php echo sprintf(__('install_scanner_available'), $scanner->getVersion() ?: __('install_scanner_version_unknown')); ?><br>
                <small class="text-muted"><?php echo sprintf(__('install_scanner_path'), Config::getScannerPath()); ?></small>
            <?php } else { ?>
                <?php echo __('install_scanner_not_available'); ?><br>
                <small class="text-muted"><?php echo sprintf(__('install_scanner_path'), Config::getScannerPath()); ?></small>
                <span class="badge bg-info ms-2"><?php echo __('install_scanner_later'); ?></span>
            <?php } ?>
        </div>
    </div>
</div>

<!-- Таблица с директориями -->
<table class="table table-bordered">
    <thead class="table-light">
        <tr>
            <th><?php echo __('install_dir'); ?></th>
            <th><?php echo __('install_path'); ?></th>
            <th><?php echo __('install_status'); ?></th>
            <th><?php echo __('install_permissions'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($dirs as $key => $dir) { ?>
        <tr>
            <td>
                <strong><?php echo ucfirst($key); ?></strong><br>
                <small class="text-muted"><?php echo $dir['description']; ?></small>
            </td>
            <td><small><?php echo $dir['path']; ?></small></td>
            <td>
                <?php if (!$dir['exists']) { ?>
                    <span class="badge bg-warning"><?php echo __('install_not_exists'); ?></span>
                <?php } elseif (!$dir['writable']) { ?>
                    <span class="badge bg-danger"><?php echo __('install_not_writable'); ?></span>
                <?php } else { ?>
                    <span class="badge bg-success"><?php echo __('install_ok'); ?></span>
                <?php } ?>
            </td>
            <td>
                <?php if ($dir['exists']) { ?>
                    <code><?php echo $dir['perms']; ?></code>
                    <small class="text-muted">(<?php echo $dir['owner']; ?>)</small>
                <?php } else { ?>
                    <span class="text-muted">—</span>
                <?php } ?>
            </td>
        </tr>
        <?php } ?>
    </tbody>
</table>

<!-- Кнопки действий -->
<div class="d-flex justify-content-between mt-4">
    <div>
        <?php if (!$dirs['data']['exists'] || !$dirs['cache']['exists'] || !$dirs['books']['exists']) { ?>
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="create_directories">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-plus-circle me-2"></i>
                    <?php echo __('install_create_dirs'); ?>
                </button>
            </form>
        <?php } ?>
        
        <?php if (($dirs['data']['exists'] && !$dirs['data']['writable'])
                  || ($dirs['cache']['exists'] && !$dirs['cache']['writable'])
                  || ($dirs['books']['exists'] && !$dirs['books']['writable'])) { ?>
            <form method="post" class="d-inline ms-2">
                <input type="hidden" name="action" value="fix_permissions">
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-wrench me-2"></i>
                    <?php echo __('install_fix_perms'); ?>
                </button>
            </form>
        <?php } ?>
    </div>
    
    <a href="?step=2" class="btn btn-primary">
        <?php echo __('install_continue'); ?>
        <i class="fas fa-arrow-right ms-2"></i>
    </a>
</div>