<?php
// templates/admin/library_backup.php

$backups = $backups ?? [];
$library_size = $library_size ?? '0 B';
$can_backup = $can_backup ?? true;
$backup_stats = $backup_stats ?? [];
$csrf_token = $csrf_token ?? '';
$message = $message ?? '';
$message_type = $message_type ?? '';
?>

<h1 class="mb-4">
    <i class="fas fa-archive me-2"></i>
    <?php echo __('admin_library_backup'); ?>
</h1>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Информация о библиотеке -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-database me-2"></i>
                    <?php echo __('backup_library_info'); ?>
                </h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%"><?php echo __('backup_library_size'); ?>:</th>
                        <td><strong><?php echo $library_size; ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php echo __('backup_library_limit'); ?>:</th>
                        <td><strong>1 GB</strong> (<?php echo __('backup_library_limit_desc'); ?>)</td>
                    </tr>
                    <tr>
                        <th><?php echo __('backup_library_status'); ?>:</th>
                        <td>
                            <?php if ($can_backup): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-check-circle me-1"></i>
                                    <?php echo __('backup_library_can_backup'); ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    <?php echo __('backup_library_too_large'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <i class="fas fa-chart-bar me-2"></i>
                    <?php echo __('backup_library_stats'); ?>
                </h5>
            </div>
            <div class="card-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%"><?php echo __('backup_library_backups_count'); ?>:</th>
                        <td><strong><?php echo $backup_stats['count'] ?? 0; ?></strong> / <?php echo $backup_stats['max_backups'] ?? 5; ?></td>
                    </tr>
                    <tr>
                        <th><?php echo __('backup_library_backups_size'); ?>:</th>
                        <td><strong><?php echo $backup_stats['total_size_formatted'] ?? '0 B'; ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php echo __('backup_library_free_space'); ?>:</th>
                        <td><strong><?php echo $backup_stats['free_space_formatted'] ?? 'N/A'; ?></strong></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Кнопка создания бэкапа -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body text-center">
                <form method="post" action="index.php" class="d-inline">
                    <input type="hidden" name="action" value="library_backup_create">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" class="btn btn-success btn-lg" 
                            <?php echo !$can_backup ? 'disabled' : ''; ?>
                            onclick="return confirm('<?php echo __('backup_library_create_confirm'); ?>')">
                        <i class="fas fa-plus-circle me-2"></i>
                        <?php echo __('backup_library_create_btn'); ?>
                    </button>
                </form>
                
                <?php if (!$can_backup): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo __('backup_library_too_large_desc'); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Список бэкапов -->
<div class="card">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0">
            <i class="fas fa-list me-2"></i>
            <?php echo __('backup_library_backups_list'); ?>
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($backups)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-folder-open fa-3x mb-3 d-block"></i>
                <h5><?php echo __('backup_library_no_backups'); ?></h5>
                <p><?php echo __('backup_library_no_backups_desc'); ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php echo __('backup_library_date'); ?></th>
                            <th><?php echo __('backup_library_filename'); ?></th>
                            <th><?php echo __('backup_library_size'); ?></th>
                            <th><?php echo __('backup_library_books'); ?></th>
                            <th><?php echo __('backup_library_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                             <tr>
                                 <td><?php echo $backup['date']; ?></td>
                                 <td><code><?php echo htmlspecialchars($backup['filename']); ?></code></td>
                                 <td><?php echo $backup['size_formatted']; ?></td>
                                 <td>
                                    <?php if ($backup['info'] && isset($backup['info']['books_count'])): ?>
                                        <span class="badge bg-primary"><?php echo number_format($backup['info']['books_count']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                 </td>
                                 <td>
                                     <div class="btn-group btn-group-sm">
                                         <a href="?action=library_backup_download&file=<?php echo urlencode($backup['filename']); ?>" 
                                            class="btn btn-success"
                                            title="<?php echo __('backup_library_download'); ?>">
                                             <i class="fas fa-download"></i>
                                         </a>
                                         
                                         <button type="button" class="btn btn-warning"
                                                 onclick="restoreBackup('<?php echo htmlspecialchars($backup['filename']); ?>')"
                                                 title="<?php echo __('backup_library_restore'); ?>">
                                             <i class="fas fa-undo"></i>
                                         </button>
                                         
                                         <button type="button" class="btn btn-danger"
                                                 onclick="deleteBackup('<?php echo htmlspecialchars($backup['filename']); ?>')"
                                                 title="<?php echo __('backup_library_delete'); ?>">
                                             <i class="fas fa-trash"></i>
                                         </button>
                                     </div>
                                 </td>
                             </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="mt-3">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php echo __('backup_library_retention'); ?>
                </small>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Формы для восстановления и удаления (скрытые) -->
<form method="post" action="index.php" id="restoreForm" style="display: none;">
    <input type="hidden" name="action" value="library_backup_restore">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <input type="hidden" name="backup_file" id="restoreFile" value="">
</form>

<form method="post" action="index.php" id="deleteForm" style="display: none;">
    <input type="hidden" name="action" value="library_backup_delete">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <input type="hidden" name="backup_file" id="deleteFile" value="">
</form>

<script>
function restoreBackup(filename) {
    if (confirm('<?php echo __('backup_library_restore_confirm'); ?>')) {
        document.getElementById('restoreFile').value = filename;
        document.getElementById('restoreForm').submit();
    }
}

function deleteBackup(filename) {
    if (confirm('<?php echo __('backup_library_delete_confirm'); ?>'.replace('%s', filename))) {
        document.getElementById('deleteFile').value = filename;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<style>
.table td {
    vertical-align: middle;
}
.btn-group .btn {
    margin: 0 2px;
}
.badge {
    font-size: 0.9rem;
}
</style>