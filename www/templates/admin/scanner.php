<?php
// templates/admin/scanner.php

$status = $status ?? [];
$message = $message ?? '';
$error = $error ?? '';
$stats = $status['stats'] ?? [];
$csrf_token = $csrf_token ?? '';

$totalBooks = $stats['total_books'] ?? 0;
$archivesCount = $stats['archives_count'] ?? 0;
$lastScan = $stats['last_scan'] ?? null;
$topAuthors = $stats['top_authors'] ?? [];

// Проверяем наличие INPX файла
$hasInpx = false;
if (isset($scanner) && method_exists($scanner, 'hasInpxFile')) {
    $hasInpx = $scanner->hasInpxFile();
}
?>

<h1 class="mb-4">
    <i class="fas fa-robot me-2"></i>
    <?php echo __('admin_scanner_title'); ?>
</h1>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Статус сканера -->

<div class="col-md-6 mb-4">
    <div class="card h-100">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-microchip me-2"></i>
                <?php echo __('admin_scanner_status'); ?>
            </h5>
        </div>
        <div class="card-body">
            <table class="table table-borderless">
                <tr>
                    <th width="150"><?php echo __('admin_scanner_available'); ?>:</th>
                    <td>
                        <?php if ($status['available'] ?? false): ?>
                            <span class="badge bg-success fs-6">
                                <i class="fas fa-check-circle me-1"></i> <?php echo __('admin_scanner_yes'); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger fs-6">
                                <i class="fas fa-times-circle me-1"></i> <?php echo __('admin_scanner_no'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <!-- ВЕРСИЯ СКАНЕРА -->
                <tr>
                    <th><?php echo __('admin_scanner_version'); ?>:</th>
                    <td>
                        <?php if (($status['available'] ?? false) && ($scanner_info['version'] ?? null)): ?>
                            <span class="badge bg-info fs-6">
                                <i class="fab fa-github-alt me-1"></i>
                                v<?php echo htmlspecialchars($scanner_info['version']); ?>
                            </span>
                            
                            <!-- Кнопка обновления информации о версии -->
                            <button class="btn btn-sm btn-outline-secondary ms-2" 
                                    onclick="checkScannerVersion()" 
                                    title="<?php echo __('admin_scanner_refresh_version'); ?>">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        <?php elseif ($status['available'] ?? false): ?>
                            <span class="badge bg-warning">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                <?php echo __('admin_scanner_version_unknown'); ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <!-- Дополнительная информация о сканере -->
                <?php if ($status['available'] ?? false): ?>
                    <tr>
                        <th><?php echo __('admin_scanner_path'); ?>:</th>
                        <td>
                            <small class="text-muted" id="scanner-path">
                                <?php echo htmlspecialchars($scanner_info['path'] ?? $status['scanner_path'] ?? __('admin_scanner_path_not_set')); ?>
                            </small>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><?php echo __('admin_scanner_size'); ?>:</th>
                        <td>
                            <?php if ($scanner_info['size_formatted'] ?? null): ?>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-hdd me-1"></i>
                                    <?php echo $scanner_info['size_formatted']; ?>
                                </span>
                                <small class="text-muted ms-2">
                                    (<?php echo __('admin_scanner_modified'); ?>: <?php echo $scanner_info['modified']; ?>)
                                </small>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><?php echo __('admin_scanner_permissions'); ?>:</th>
                        <td>
                            <?php if ($scanner_info['executable'] ?? false): ?>
                                <span class="badge bg-success">
                                    <i class="fas fa-check-circle me-1"></i> <?php echo __('admin_scanner_executable'); ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger">
                                    <i class="fas fa-times-circle me-1"></i> <?php echo __('admin_scanner_not_executable'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                
                <tr>
                    <th><?php echo __('admin_scanner_running'); ?>:</th>
                    <td>
                        <?php if ($status['running'] ?? false): ?>
                            <span class="badge bg-warning text-dark fs-6">
                                <i class="fas fa-play-circle me-1"></i> <?php echo __('admin_scanner_yes'); ?> (PID: <?php echo $status['pid'] ?? '?'; ?>)
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary fs-6">
                                <i class="fas fa-stop-circle me-1"></i> <?php echo __('admin_scanner_no'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <?php if ($status['running'] ?? false): ?>
                <tr>
                    <th><?php echo __('admin_scanner_started_at'); ?>:</th>
                    <td><?php echo $status['started_at'] ?? '?'; ?></td>
                </tr>
                <tr>
                    <th><?php echo __('admin_scanner_running_time'); ?>:</th>
                    <td><?php echo $status['running_for'] ?? '?'; ?></td>
                </tr>
                <?php endif; ?>
                
                <tr>
                    <th><?php echo __('admin_scanner_config'); ?>:</th>
                    <td><small class="text-muted"><?php echo $status['config_path'] ?? __('admin_scanner_path_not_set'); ?></small></td>
                </tr>
                
                <tr>
                    <th><?php echo __('admin_scanner_inpx'); ?>:</th>
                    <td>
                        <?php if ($hasInpx): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-check"></i> <?php echo __('admin_scanner_inpx_found'); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary">
                                <i class="fas fa-times"></i> <?php echo __('admin_scanner_inpx_not_found'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</div>   
 
<!-- Статистика библиотеки -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="fas fa-chart-bar me-2"></i>
                    <?php echo __('admin_library_stats'); ?>
                </h5>
            </div>
            <div class="card-body">
                <?php
                // Получаем статистику из $status['stats']
                $statsData = $status['stats'] ?? [];
                $totalBooks = $statsData['total_books'] ?? 0;
                $archivesCount = $statsData['archives_count'] ?? 0;
                $lastScan = $statsData['last_scan'] ?? null;
                $scansCount = $statsData['scans_count'] ?? 0;
                ?>
                
                <table class="table table-borderless">
                    <tr>
                        <th width="150"><?php echo __('admin_library_books'); ?>:</th>
                        <td>
                            <span class="badge bg-primary fs-6">
                                <?php echo number_format($totalBooks); ?>
                            </span>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><?php echo __('admin_library_archives'); ?>:</th>
                        <td>
                            <span class="badge bg-info fs-6">
                                <?php echo number_format($archivesCount); ?>
                            </span>
                        </td>
                    </tr>
                    
                    <?php if (!empty($lastScan)): ?>
                    <tr>
                        <th><?php echo __('admin_library_last_scan'); ?>:</th>
                        <td><?php echo date('d.m.Y H:i', strtotime($lastScan)); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                
                <!-- Информация о директории с книгами -->
                <div class="mt-3 p-3 bg-light rounded">
                    <h6 class="mb-2">
                        <i class="fas fa-folder-open me-2"></i>
                        <?php echo __('admin_library_books_dir'); ?>
                    </h6>
                    <p class="mb-1 small text-break">
                        <?php echo htmlspecialchars(Config::getBooksDir()); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Управление сканером -->
<div class="card mb-4">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0">
            <i class="fas fa-cog me-2"></i>
            <?php echo __('admin_scanner_control'); ?>
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6 class="mb-3"><?php echo __('admin_scanner_start'); ?></h6>
                
                <form method="post" class="mb-3">
                    <input type="hidden" name="action" value="scanner_start">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" 
                                   id="modeNormal" value="normal" checked>
                            <label class="form-check-label" for="modeNormal">
                                <strong><?php echo __('admin_scan_mode_normal'); ?></strong>
                                <br>
                                <small class="text-muted"><?php echo __('admin_scan_mode_normal_desc'); ?></small>
                            </label>
                        </div>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" 
                                   id="modeQuick" value="quick">
                            <label class="form-check-label" for="modeQuick">
                                <strong><?php echo __('admin_scan_mode_quick'); ?></strong>
                                <br>
                                <small class="text-muted"><?php echo __('admin_scan_mode_quick_desc'); ?></small>
                            </label>
                        </div>
                        
                        <?php if ($hasInpx): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" 
                                   id="modeInpx" value="inpx">
                            <label class="form-check-label" for="modeInpx">
                                <strong><?php echo __('admin_scan_mode_inpx'); ?></strong>
                                <br>
                                <small class="text-muted"><?php echo __('admin_scan_mode_inpx_desc'); ?></small>
                            </label>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" 
                                   id="modeForce" value="force">
                            <label class="form-check-label" for="modeForce">
                                <strong><?php echo __('admin_scan_mode_force'); ?></strong>
                                <br>
                                <small class="text-muted"><?php echo __('admin_scan_mode_force_desc'); ?></small>
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="background" 
                                   id="background" value="1" checked>
                            <label class="form-check-label" for="background">
                                <strong><?php echo __('admin_scan_background'); ?></strong>
                                <br>
                                <small class="text-muted"><?php echo __('admin_scan_background_desc'); ?></small>
                            </label>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-success" 
                            <?php echo ($status['running'] ?? false) ? 'disabled' : ''; ?>>
                        <i class="fas fa-play me-2"></i>
                        <?php echo __('admin_scan_start_btn'); ?>
                    </button>
                </form>
            </div>
            
            <div class="col-md-6">
                <h6 class="mb-3"><?php echo __('admin_scanner_actions'); ?></h6>
                
                <form method="post" class="d-inline-block me-2">
                    <input type="hidden" name="action" value="scanner_stop">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" class="btn btn-danger" 
                            <?php echo !($status['running'] ?? false) ? 'disabled' : ''; ?>
                            onclick="return confirm('<?php echo __('admin_scan_stop_confirm'); ?>')">
                        <i class="fas fa-stop me-2"></i>
                        <?php echo __('admin_scan_stop_btn'); ?>
                    </button>
                </form>
                
                <form method="post" class="d-inline-block me-2">
                    <input type="hidden" name="action" value="scanner_clear_log">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" class="btn btn-warning" 
                            onclick="return confirm('<?php echo __('admin_scan_clear_log_confirm'); ?>')">
                        <i class="fas fa-trash-alt me-2"></i>
                        <?php echo __('admin_scan_clear_log_btn'); ?>
                    </button>
                </form>
                
                <?php if ($hasInpx): ?>
                <form method="post" class="d-inline-block me-2">
                    <input type="hidden" name="action" value="scanner_import_inpx">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" class="btn btn-primary" 
                            <?php echo ($status['running'] ?? false) ? 'disabled' : ''; ?>>
                        <i class="fas fa-database me-2"></i>
                        <?php echo __('admin_scan_import_inpx_btn'); ?>
                    </button>
                </form>
                <?php endif; ?>
                
                <a href="?action=scanner&refresh=1" class="btn btn-info">
                    <i class="fas fa-sync-alt me-2"></i>
                    <?php echo __('admin_scan_refresh_btn'); ?>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Лог сканера -->
<div class="card">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span>
            <i class="fas fa-history me-2"></i>
            <?php echo __('admin_scanner_log'); ?>
        </span>
        <span>
            <small>
                <i class="fas fa-file me-1"></i>
                <?php echo basename($status['log_file'] ?? 'scanner.log'); ?>
            </small>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($status['last_log'])): ?>
            <div class="text-center text-muted py-4">
                <i class="fas fa-file-alt fa-3x mb-2"></i>
                <p><?php echo __('admin_scanner_log_empty'); ?></p>
            </div>
        <?php else: ?>
            <div class="scanner-log" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                        <?php foreach ($status['last_log'] as $line): ?>
                            <tr>
                                <td class="font-monospace small">
                                    <?php
                    // Подсветка строк
                    $lineClass = '';
                            if (strpos($line, 'ERROR') !== false || strpos($line, 'Error') !== false) {
                                $lineClass = 'text-danger';
                            } elseif (strpos($line, 'WARNING') !== false) {
                                $lineClass = 'text-warning';
                            } elseif (strpos($line, 'SUCCESS') !== false) {
                                $lineClass = 'text-success';
                            }
                            ?>
                                    <span class="<?php echo $lineClass; ?>">
                                        <?php echo htmlspecialchars($line); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($status['last_log'])): ?>
    <div class="card-footer text-muted small">
        <i class="fas fa-info-circle me-1"></i>
        <?php echo __('admin_scanner_log_updated'); ?> <?php echo date('H:i:s'); ?>
        <?php if (isset($status['log_file']) && file_exists($status['log_file'])): ?>
            | <?php echo __('admin_scanner_log_size'); ?> <?php echo round(filesize($status['log_file']) / 1024, 1); ?> KB
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.scanner-log {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    line-height: 1.4;
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 10px;
}

.scanner-log table {
    background: #1e1e1e;
    color: #d4d4d4;
}

.scanner-log td {
    border-color: #333;
    white-space: pre-wrap;
    word-wrap: break-word;
    max-width: 800px;
}

.scanner-log .text-danger { color: #f44 !important; }
.scanner-log .text-warning { color: #ff6 !important; }
.scanner-log .text-success { color: #6f6 !important; }

.card-header .badge {
    font-size: 0.9rem;
}
</style>


<script>
// Функция для проверки версии сканера
function checkScannerVersion() {
    const versionBadge = document.querySelector('.badge.bg-info');
    const refreshBtn = event?.target?.closest('button');
    
    if (refreshBtn) {
        const originalHtml = refreshBtn.innerHTML;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    }
    
    fetch('ajax/scanner_version.php', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.version) {
            if (versionBadge) {
                versionBadge.innerHTML = '<i class="fab fa-github-alt me-1"></i> v' + data.version;
                versionBadge.className = 'badge bg-success fs-6';
            }
            
            // Показываем уведомление
            if (typeof showAdminMessage === 'function') {
                showAdminMessage('<?php echo __('admin_scanner_version_updated'); ?>: v' + data.version, 'success');
            }
        } else {
            if (versionBadge) {
                versionBadge.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> <?php echo __('admin_scanner_version_unknown'); ?>';
                versionBadge.className = 'badge bg-warning';
            }
            
            if (typeof showAdminMessage === 'function') {
                showAdminMessage('<?php echo __('admin_scanner_version_error'); ?>', 'warning');
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (typeof showAdminMessage === 'function') {
            showAdminMessage('<?php echo __('admin_scanner_version_error'); ?>: ' + error, 'danger');
        }
    })
    .finally(() => {
        if (refreshBtn) {
            refreshBtn.disabled = false;
            refreshBtn.innerHTML = originalHtml;
        }
    });
}
</script>



<script>
// Автообновление каждые 5 секунд, если сканер запущен
<?php if ($status['running'] ?? false): ?>
setTimeout(function() {
    location.reload();
}, 5000);
<?php endif; ?>

// Прокрутка лога вниз
window.addEventListener('load', function() {
    const logDiv = document.querySelector('.scanner-log');
    if (logDiv) {
        logDiv.scrollTop = logDiv.scrollHeight;
    }
});
</script>
