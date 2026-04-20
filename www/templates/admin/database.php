<?php
// templates/admin/database.php

$info = $info ?? [];
$tables = $tables ?? [];
$backups = $backups ?? [];
$cache_stats = $cache_stats ?? [];
$message = $message ?? '';
$message_type = $message_type ?? '';
$csrf_token = $csrf_token ?? '';
?>

<h1 class="mb-4">
    <i class="fas fa-database me-2"></i>
    <?php echo __('admin_db_title'); ?>
</h1>

<?php if ($message) { ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
        <i class="fas fa-<?php echo 'success' === $message_type ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php } ?>

<!-- Информация о БД -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    <?php echo __('admin_db_info'); ?>
                </h5>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th><?php echo __('admin_db_type'); ?></th>
                        <td>
                            <span class="badge bg-<?php echo 'sqlite' === $info['type'] ? 'info' : 'success'; ?>">
                                <?php echo strtoupper($info['type']); ?>
                            </span>
                        </td>
                    </tr>
                    
                    <?php if ('sqlite' === $info['type']) { ?>
                        <tr>
                            <th><?php echo __('admin_db_file'); ?></th>
                            <td><small><?php echo htmlspecialchars($info['path'] ?? ''); ?></small></td>
                        </tr>
                        <tr>
                            <th><?php echo __('admin_db_size'); ?></th>
                            <td>
                                <?php echo $info['size'] ? round($info['size'] / 1024 / 1024, 2) : 0; ?> MB
                                <?php if (isset($info['wal_size']) && $info['wal_size'] > 0) { ?>
                                    <br><small class="text-muted">WAL: <?php echo round($info['wal_size'] / 1024 / 1024, 2); ?> MB</small>
                                <?php } ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo __('admin_db_version_sqlite'); ?></th>
                            <td><code><?php echo $info['version'] ?? 'N/A'; ?></code></td>
                        </tr>
                        <tr>
                            <th><?php echo __('admin_db_journal_mode'); ?></th>
                            <td><code><?php echo $info['journal_mode'] ?? 'N/A'; ?></code></td>
                        </tr>
                    <?php } else { ?>
                        <tr>
                            <th><?php echo __('admin_db_version_mysql'); ?></th>
                            <td><code><?php echo $info['version'] ?? 'N/A'; ?></code></td>
                        </tr>
                        <tr>
                            <th><?php echo __('admin_db_data_size'); ?></th>
                            <td><?php echo $info['size'] ? round($info['size'] / 1024 / 1024, 2) : 0; ?> MB</td>
                        </tr>
                        <tr>
                            <th>max_allowed_packet:</th>
                            <td><?php echo isset($info['max_allowed_packet']) ? round($info['max_allowed_packet'] / 1024 / 1024, 2) : 'N/A'; ?> MB</td>
                        </tr>
                        <tr>
                            <th>Buffer Pool:</th>
                            <td><?php echo isset($info['buffer_pool']) ? round($info['buffer_pool'] / 1024 / 1024 / 1024, 2) : 'N/A'; ?> GB</td>
                        </tr>
                    <?php } ?>
                    
                    <tr>
                        <th><?php echo __('admin_db_tables_count'); ?></th>
                        <td><span class="badge bg-secondary"><?php echo $info['tables_count'] ?? 0; ?></span></td>
                    </tr>
                    <tr>
                        <th><?php echo __('admin_db_writable'); ?></th>
                        <td>
                            <?php if ($info['is_writable'] ?? false) { ?>
                                <span class="badge bg-success">✅ <?php echo __('admin_status_yes'); ?></span>
                            <?php } else { ?>
                                <span class="badge bg-danger">❌ <?php echo __('admin_status_no'); ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo __('admin_db_status'); ?></th>
                        <td>
                            <?php if (($info['status'] ?? '') === 'active') { ?>
                                <span class="badge bg-success"><?php echo __('admin_status_active'); ?></span>
                            <?php } else { ?>
                                <span class="badge bg-danger"><?php echo __('admin_status_error'); ?></span>
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
                <h5 class="mb-0">
                    <i class="fas fa-chart-bar me-2"></i>
                    <?php echo __('admin_db_cache_stats'); ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($cache_stats['apcu'])) { ?>
                    <table class="table table-sm">
                        <tr>
                            <th><?php echo __('admin_cache_hits'); ?></th>
                            <td><?php echo number_format($cache_stats['apcu']['hits']); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo __('admin_cache_misses'); ?></th>
                            <td><?php echo number_format($cache_stats['apcu']['misses']); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo __('admin_cache_efficiency'); ?></th>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-success" 
                                         style="width: <?php echo $cache_stats['apcu']['effectiveness']; ?>%">
                                        <?php echo $cache_stats['apcu']['effectiveness']; ?>%
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo __('admin_cache_entries'); ?></th>
                            <td><?php echo number_format($cache_stats['apcu']['entries']); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo __('admin_cache_memory'); ?></th>
                            <td><?php echo round($cache_stats['apcu']['memory_usage'] / 1024 / 1024, 2); ?> MB</td>
                        </tr>
                    </table>
                <?php } else { ?>
                    <p class="text-muted text-center py-4">
                        <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                        <?php echo __('admin_cache_not_used'); ?>
                    </p>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<!-- Действия с БД -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="fas fa-tools me-2"></i>
                    <?php echo __('admin_db_actions'); ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-2">
                        <form method="post" action="index.php" class="d-inline w-100">
                            <input type="hidden" name="action" value="database_backup">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <button type="submit" class="btn btn-primary w-100" 
                                    onclick="return confirm('<?php echo __('admin_db_backup_confirm'); ?>')">
                                <i class="fas fa-download me-2"></i>
                                <?php echo __('admin_db_backup'); ?>
                            </button>
                        </form>
                    </div>
                    
                    <div class="col-md-3 mb-2">
                        <form method="post" action="index.php" class="d-inline w-100" 
                              onsubmit="return confirm('<?php echo __('admin_db_optimize_confirm'); ?>')">
                            <input type="hidden" name="action" value="database_optimize">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-compress-alt me-2"></i>
                                <?php echo __('admin_db_optimize'); ?>
                            </button>
                        </form>
                    </div>
                    
                    <div class="col-md-3 mb-2">
                        <form method="post" action="index.php" class="d-inline w-100">
                            <input type="hidden" name="action" value="database_check">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <button type="submit" class="btn btn-info w-100">
                                <i class="fas fa-check-circle me-2"></i>
                                <?php echo __('admin_db_check'); ?>
                            </button>
                        </form>
                    </div>
                    
                    <div class="col-md-3 mb-2">
                        <button type="button" class="btn btn-secondary w-100" 
                                onclick="toggleElement('cacheStats')">
                            <i class="fas fa-bolt me-2"></i>
                            <?php echo __('admin_db_cache_details'); ?>
                        </button>
                    </div>
                </div>
                
                <div id="cacheStats" style="display: none;" class="mt-3">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong><?php echo __('admin_cache_raw_data'); ?></strong>
                    </div>
                    <pre class="bg-light p-3 rounded overflow-auto" style="max-height: 400px;"><?php print_r($cache_stats); ?></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Список таблиц -->
<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0">
            <i class="fas fa-table me-2"></i>
            <?php echo __('admin_db_tables'); ?>
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?php echo __('admin_table_name'); ?></th>
                        <th><?php echo __('admin_table_rows'); ?></th>
                        <th><?php echo __('admin_table_size'); ?></th>
                        <?php if ('sqlite' !== $info['type']) { ?>
                            <th><?php echo __('admin_table_data'); ?></th>
                            <th><?php echo __('admin_table_indexes'); ?></th>
                        <?php } ?>
                        <th><?php echo __('admin_table_engine'); ?></th>
                        <th><?php echo __('admin_table_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tables as $table) { ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($table['name']); ?></strong>
                                <?php if (($table['indexes'] ?? 0) > 0) { ?>
                                    <span class="badge bg-info ms-1" title="<?php echo __('admin_table_indexes_count'); ?>: <?php echo $table['indexes']; ?>">
                                        <?php echo $table['indexes']; ?> idx
                                    </span>
                                <?php } ?>
                            </td>
                            <td><?php echo number_format($table['rows'] ?? 0); ?></td>
                            <td><?php echo isset($table['size']) ? round($table['size'] / 1024, 2) : 0; ?> KB</td>
                            
                            <?php if ('sqlite' !== $info['type']) { ?>
                                <td><?php echo isset($table['data_size']) ? round($table['data_size'] / 1024, 2) : 0; ?> KB</td>
                                <td><?php echo isset($table['index_size']) ? round($table['index_size'] / 1024, 2) : 0; ?> KB</td>
                            <?php } ?>
                            
                            <td>
                                <span class="badge bg-secondary"><?php echo $table['engine'] ?? 'SQLite'; ?></span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-info" 
                                        onclick="showTableInfo('<?php echo $table['name']; ?>')"
                                        title="<?php echo __('admin_table_info'); ?>">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-primary" 
                                        onclick="browseTable('<?php echo $table['name']; ?>')"
                                        title="<?php echo __('admin_table_browse'); ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Бэкапы -->
<div class="card">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0">
            <i class="fas fa-archive me-2"></i>
            <?php echo __('admin_db_backups'); ?>
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($backups)) { ?>
            <p class="text-muted text-center py-4">
                <i class="fas fa-folder-open fa-3x mb-3 d-block"></i>
                <?php echo __('admin_backups_none'); ?>
            </p>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php echo __('admin_backup_date'); ?></th>
                            <th><?php echo __('admin_backup_filename'); ?></th>
                            <th><?php echo __('admin_backup_size'); ?></th>
                            <th><?php echo __('admin_backup_type'); ?></th>
                            <th><?php echo __('admin_backup_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup) { ?>
                            <tr>
                                <td><?php echo $backup['date']; ?></td>
                                <td><code><?php echo $backup['filename']; ?></code></td>
                                <td><?php echo $backup['size_formatted']; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo 'sql' === $backup['type'] ? 'success' : 'info'; ?>">
                                        <?php echo strtoupper($backup['type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-success" 
                                            onclick="restoreBackup('<?php echo $backup['filename']; ?>')"
                                            title="<?php echo __('admin_backup_restore'); ?>">
                                        <i class="fas fa-undo"></i>
                                    </button>
                                    <a href="../backups/database/<?php echo $backup['filename']; ?>" 
                                       class="btn btn-sm btn-primary"
                                       download
                                       title="<?php echo __('admin_backup_download'); ?>">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <button class="btn btn-sm btn-danger" 
                                            onclick="deleteBackup('<?php echo $backup['filename']; ?>')"
                                            title="<?php echo __('admin_backup_delete'); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </div>
    <div class="card-footer">
        <small class="text-muted">
            <i class="fas fa-info-circle me-1"></i>
            <?php echo __('admin_backup_retention'); ?>
        </small>
    </div>
</div>

<!-- Модальное окно для информации о таблице -->
<div class="modal fade" id="tableInfoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle me-2"></i>
                    <?php echo __('admin_table_info_title'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="tableInfoContent">
                <div class="text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br>
                    <?php echo __('admin_loading'); ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>
                    <?php echo __('close'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Функция для показа информации о таблице
function showTableInfo(tableName) {
    const modal = new bootstrap.Modal(document.getElementById('tableInfoModal'));
    const content = document.getElementById('tableInfoContent');
    content.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x mb-2"></i><br><?php echo __('admin_loading'); ?></div>';
    modal.show();
    
    fetch('ajax/table_info.php?table=' + encodeURIComponent(tableName))
        .then(response => {
            if (!response.ok) {
                if (response.status === 403) {
                    throw new Error('<?php echo __('admin_error_auth'); ?>');
                }
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                let html = '<div class="table-responsive"><table class="table table-sm table-bordered">';
                
                // Если есть структура таблицы
                if (data.info.columns && data.info.columns.length > 0) {
                    html += '<tr><th colspan="4" class="bg-light"><?php echo __('admin_structure'); ?></th></tr>';
                    html += '<tr><th><?php echo __('admin_field'); ?></th><th><?php echo __('admin_type'); ?></th><th>Null</th><th><?php echo __('admin_key'); ?></th></tr>';
                    
                    data.info.columns.forEach(col => {
                        html += '<tr>';
                        html += '<td>' + (col.name || col.Field || '') + '</td>';
                        html += '<td><code>' + (col.type || col.Type || '') + '</code></td>';
                        html += '<td>' + ((col.notnull === 0 || col.Null === 'YES') ? '✓' : '✗') + '</td>';
                        html += '<td>' + ((col.pk === 1 || col.Key === 'PRI') ? 'PRIMARY' : (col.Key || '')) + '</td>';
                        html += '</tr>';
                    });
                }
                
                // Если есть индексы
                if (data.info.indexes && data.info.indexes.length > 0) {
                    html += '<tr><th colspan="4" class="bg-light mt-2"><?php echo __('admin_indexes'); ?></th></tr>';
                    html += '<tr><th><?php echo __('admin_index_name'); ?></th><th><?php echo __('admin_unique'); ?></th><th><?php echo __('admin_columns'); ?></th><th></th></tr>';
                    
                    data.info.indexes.forEach(idx => {
                        html += '<tr>';
                        html += '<td>' + (idx.name || idx.Key_name || '') + '</td>';
                        html += '<td>' + ((idx.unique === 1 || idx.Non_unique === 0) ? '✓' : '✗') + '</td>';
                        html += '<td>' + (idx.columns || idx.Column_name || '') + '</td>';
                        html += '<td></td>';
                        html += '</tr>';
                    });
                }
                
                html += '</table></div>';
                
                // Добавим сырые данные в спойлер
                html += '<div class="mt-3">';
                html += '<button class="btn btn-sm btn-outline-secondary" type="button" onclick="toggleElement(\'rawData\')">';
                html += '<i class="fas fa-code me-1"></i> <?php echo __('admin_show_raw'); ?>';
                html += '</button>';
                html += '<div id="rawData" style="display: none;" class="mt-2"><pre class="bg-light p-3 overflow-auto" style="max-height: 400px;">' + 
                        JSON.stringify(data.info, null, 2) + '</pre></div>';
                html += '</div>';
                
                content.innerHTML = html;
            } else {
                content.innerHTML = '<div class="alert alert-danger">' + (data.message || '<?php echo __('admin_error_loading'); ?>') + '</div>';
                if (data.debug) {
                    console.log('Debug info:', data.debug);
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            content.innerHTML = '<div class="alert alert-danger"><?php echo __('admin_error_loading'); ?>: ' + error.message + '</div>';
        });
}

function browseTable(tableName) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php';
    
    form.innerHTML = `
        <input type="hidden" name="action" value="database_browse_table">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="table" value="${tableName}">
    `;
    
    document.body.appendChild(form);
    form.submit();
}

function restoreBackup(filename) {
    if (confirm('<?php echo __('admin_backup_restore_confirm'); ?>')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php';
        
        form.innerHTML = `
            <input type="hidden" name="action" value="database_restore">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="backup_file" value="${filename}">
        `;
        
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteBackup(filename) {
    if (confirm('<?php echo __('admin_backup_delete_confirm'); ?>'.replace('%s', filename))) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php';
        
        form.innerHTML = `
            <input type="hidden" name="action" value="database_delete_backup">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="backup_file" value="${filename}">
        `;
        
        document.body.appendChild(form);
        form.submit();
    }
}

function toggleElement(id) {
    const el = document.getElementById(id);
    if (el) {
        el.style.display = el.style.display === 'none' ? 'block' : 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const cacheBtn = document.querySelector('button[onclick*="cacheStats"]');
    if (cacheBtn) {
        cacheBtn.addEventListener('click', function() {
            toggleElement('cacheStats');
        });
    }
});
</script>

<style>
.table th {
    white-space: nowrap;
}
.badge {
    font-size: 0.85rem;
}
.progress {
    background-color: #e9ecef;
}
.card {
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.table td code {
    word-break: break-all;
    white-space: normal;
}
.btn-sm {
    margin: 0 2px;
}
.modal-body pre {
    font-size: 12px;
}
</style>