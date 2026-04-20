<?php
// templates/admin/logs.php

$php_log = $php_log ?? [];
$scanner_log = $scanner_log ?? [];
$system_log = $system_log ?? [];
$csrf_token = $csrf_token ?? '';

// Получаем статистику логов
$phpLogData = is_array($php_log) ? $php_log : ['lines' => [], 'exists' => false, 'size_formatted' => '0 B'];
$scannerLogData = is_array($scanner_log) ? $scanner_log : ['lines' => [], 'exists' => false, 'size_formatted' => '0 B'];
$systemLogData = is_array($system_log) ? $system_log : ['lines' => [], 'exists' => false, 'size_formatted' => '0 B'];

$activeTab = $_GET['tab'] ?? 'system';
?>

<h1 class="mb-4">
    <i class="fas fa-history me-2"></i>
    <?php echo __('admin_logs_title'); ?>
</h1>

<!-- Вкладки логов -->
<ul class="nav nav-tabs mb-4" id="logsTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo 'system' === $activeTab ? 'active' : ''; ?>" 
                id="system-tab" 
                data-bs-toggle="tab" 
                data-bs-target="#system-log" 
                type="button" role="tab">
            <i class="fas fa-server me-2"></i>
            <?php echo __('log_type_system'); ?>
            <?php if (!empty($systemLogData['lines'])) { ?>
                <span class="badge bg-secondary ms-1"><?php echo count($systemLogData['lines']); ?></span>
            <?php } ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo 'scanner' === $activeTab ? 'active' : ''; ?>" 
                id="scanner-tab" 
                data-bs-toggle="tab" 
                data-bs-target="#scanner-log" 
                type="button" role="tab">
            <i class="fas fa-robot me-2"></i>
            <?php echo __('log_type_scanner'); ?>
            <?php if (!empty($scannerLogData['lines'])) { ?>
                <span class="badge bg-secondary ms-1"><?php echo count($scannerLogData['lines']); ?></span>
            <?php } ?>
            <?php if (($scannerLogData['stats']['errors'] ?? 0) > 0) { ?>
                <span class="badge bg-danger ms-1"><?php echo $scannerLogData['stats']['errors']; ?> err</span>
            <?php } ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo 'php' === $activeTab ? 'active' : ''; ?>" 
                id="php-tab" 
                data-bs-toggle="tab" 
                data-bs-target="#php-log" 
                type="button" role="tab">
            <i class="fab fa-php me-2"></i>
            <?php echo __('log_type_php'); ?>
            <?php if (!empty($phpLogData['lines'])) { ?>
                <span class="badge bg-secondary ms-1"><?php echo count($phpLogData['lines']); ?></span>
            <?php } ?>
        </button>
    </li>
</ul>

<div class="tab-content" id="logsTabsContent">
    <!-- Системный лог -->
    <div class="tab-pane fade <?php echo 'system' === $activeTab ? 'show active' : ''; ?>" 
         id="system-log" 
         role="tabpanel">
        <div class="card">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-server me-2"></i>
                    <?php echo __('log_type_system'); ?>
                </span>
                <div>
                    <?php if ($systemLogData['exists']) { ?>
                        <span class="badge bg-info me-2">
                            <i class="fas fa-database me-1"></i>
                            <?php echo $systemLogData['size_formatted']; ?>
                        </span>
                        <span class="badge bg-secondary">
                            <i class="fas fa-clock me-1"></i>
                            <?php echo $systemLogData['last_modified_formatted'] ?? __('admin_logs_unknown'); ?>
                        </span>
                    <?php } ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (!$systemLogData['exists']) { ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-file-alt fa-3x mb-3 d-block"></i>
                        <h5><?php echo __('admin_logs_empty'); ?></h5>
                        <p class="mb-0"><?php echo __('admin_logs_file_not_found'); ?></p>
                        <p class="small"><?php echo htmlspecialchars($systemLogData['file'] ?? ''); ?></p>
                    </div>
                <?php } elseif (empty($systemLogData['lines'])) { ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                        <h5><?php echo __('admin_logs_empty'); ?></h5>
                    </div>
                <?php } else { ?>
                    <div class="log-container">
                        <table class="table table-sm table-hover mb-0">
                            <tbody>
                                <?php foreach ($systemLogData['lines'] as $line) { ?>
                                    <tr class="log-line log-level-<?php echo is_array($line) ? $line['class'] : 'secondary'; ?>">
                                        <td class="font-monospace small">
                                            <?php if (is_array($line)) { ?>
                                                <span class="log-level-badge badge bg-<?php echo $line['class']; ?> me-2">
                                                    <?php echo $line['level']; ?>
                                                </span>
                                                <?php echo $line['text']; ?>
                                            <?php } else { ?>
                                                <?php echo $line; ?>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </div>
            <?php if ($systemLogData['exists'] && !empty($systemLogData['lines'])) { ?>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php echo __('admin_logs_last_lines', count($systemLogData['lines'])); ?>
                        </small>
                        <div>
                            <button class="btn btn-sm btn-warning" 
                                    onclick="clearLog('system')"
                                    title="<?php echo __('admin_logs_clear'); ?>">
                                <i class="fas fa-trash-alt me-1"></i>
                                <?php echo __('admin_logs_clear'); ?>
                            </button>
                            <a href="?action=logs&download=system" 
                               class="btn btn-sm btn-success"
                               title="<?php echo __('admin_logs_download'); ?>">
                                <i class="fas fa-download me-1"></i>
                                <?php echo __('admin_logs_download'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
    
    <!-- Лог сканера -->
    <div class="tab-pane fade <?php echo 'scanner' === $activeTab ? 'show active' : ''; ?>" 
         id="scanner-log" 
         role="tabpanel">
        <div class="card">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-robot me-2"></i>
                    <?php echo __('log_type_scanner'); ?>
                </span>
                <div>
                    <?php if ($scannerLogData['exists']) { ?>
                        <span class="badge bg-info me-2">
                            <i class="fas fa-database me-1"></i>
                            <?php echo $scannerLogData['size_formatted']; ?>
                        </span>
                        <span class="badge bg-secondary">
                            <i class="fas fa-clock me-1"></i>
                            <?php echo $scannerLogData['last_modified_formatted'] ?? __('admin_logs_unknown'); ?>
                        </span>
                    <?php } ?>
                </div>
            </div>
            <?php if ($scannerLogData['exists'] && !empty($scannerLogData['stats'])) { ?>
                <div class="card-header bg-light">
                    <div class="row text-center">
                        <div class="col-3">
                            <div class="small text-muted"><?php echo __('admin_logs_total_entries'); ?></div>
                            <div class="h5 mb-0"><?php echo $scannerLogData['stats']['total_entries']; ?></div>
                        </div>
                        <div class="col-3">
                            <div class="small text-danger"><?php echo __('admin_logs_errors'); ?></div>
                            <div class="h5 mb-0 text-danger"><?php echo $scannerLogData['stats']['errors']; ?></div>
                        </div>
                        <div class="col-3">
                            <div class="small text-warning"><?php echo __('admin_logs_warnings'); ?></div>
                            <div class="h5 mb-0 text-warning"><?php echo $scannerLogData['stats']['warnings']; ?></div>
                        </div>
                        <div class="col-3">
                            <div class="small text-success"><?php echo __('admin_logs_success'); ?></div>
                            <div class="h5 mb-0 text-success"><?php echo $scannerLogData['stats']['success']; ?></div>
                        </div>
                    </div>
                </div>
            <?php } ?>
            <div class="card-body p-0">
                <?php if (!$scannerLogData['exists']) { ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-file-alt fa-3x mb-3 d-block"></i>
                        <h5><?php echo __('admin_logs_empty'); ?></h5>
                        <p class="mb-0"><?php echo __('admin_logs_file_not_found'); ?></p>
                        <p class="small"><?php echo htmlspecialchars($scannerLogData['file'] ?? ''); ?></p>
                    </div>
                <?php } elseif (empty($scannerLogData['lines'])) { ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                        <h5><?php echo __('admin_logs_empty'); ?></h5>
                    </div>
                <?php } else { ?>
                    <div class="log-container scanner-log-container">
                        <table class="table table-sm table-hover mb-0">
                            <tbody>
                                <?php foreach ($scannerLogData['lines'] as $line) { ?>
                                    <tr class="log-line log-level-<?php echo is_array($line) ? $line['class'] : 'secondary'; ?>">
                                        <td class="font-monospace small">
                                            <?php if (is_array($line)) { ?>
                                                <span class="log-level-badge badge bg-<?php echo $line['class']; ?> me-2">
                                                    <?php echo $line['level']; ?>
                                                </span>
                                                <?php echo $line['text']; ?>
                                            <?php } else { ?>
                                                <?php echo $line; ?>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </div>
            <?php if ($scannerLogData['exists'] && !empty($scannerLogData['lines'])) { ?>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php echo __('admin_logs_last_lines', count($scannerLogData['lines'])); ?>
                        </small>
                        <div>
                            <button class="btn btn-sm btn-warning" 
                                    onclick="clearLog('scanner')"
                                    title="<?php echo __('admin_logs_clear'); ?>">
                                <i class="fas fa-trash-alt me-1"></i>
                                <?php echo __('admin_logs_clear'); ?>
                            </button>
                            <a href="?action=logs&download=scanner" 
                               class="btn btn-sm btn-success"
                               title="<?php echo __('admin_logs_download'); ?>">
                                <i class="fas fa-download me-1"></i>
                                <?php echo __('admin_logs_download'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
    
    <!-- PHP лог -->
    <div class="tab-pane fade <?php echo 'php' === $activeTab ? 'show active' : ''; ?>" 
         id="php-log" 
         role="tabpanel">
        <div class="card">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span>
                    <i class="fab fa-php me-2"></i>
                    <?php echo __('log_type_php'); ?>
                </span>
                <div>
                    <?php if ($phpLogData['exists']) { ?>
                        <span class="badge bg-info me-2">
                            <i class="fas fa-database me-1"></i>
                            <?php echo $phpLogData['size_formatted']; ?>
                        </span>
                        <span class="badge bg-secondary">
                            <i class="fas fa-clock me-1"></i>
                            <?php echo $phpLogData['last_modified_formatted'] ?? __('admin_logs_unknown'); ?>
                        </span>
                    <?php } ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (!$phpLogData['exists']) { ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-file-alt fa-3x mb-3 d-block"></i>
                        <h5><?php echo __('admin_logs_empty'); ?></h5>
                        <p class="mb-0"><?php echo __('admin_logs_file_not_found'); ?></p>
                        <p class="small"><?php echo htmlspecialchars($phpLogData['file'] ?? ''); ?></p>
                    </div>
                <?php } elseif (empty($phpLogData['lines'])) { ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                        <h5><?php echo __('admin_logs_empty'); ?></h5>
                    </div>
                <?php } else { ?>
                    <div class="log-container php-log-container">
                        <table class="table table-sm table-hover mb-0">
                            <tbody>
                                <?php foreach ($phpLogData['lines'] as $line) { ?>
                                    <tr class="log-line log-level-<?php echo is_array($line) ? $line['class'] : 'secondary'; ?>">
                                        <td class="font-monospace small">
                                            <?php if (is_array($line)) { ?>
                                                <span class="log-level-badge badge bg-<?php echo $line['class']; ?> me-2">
                                                    <?php echo $line['level']; ?>
                                                </span>
                                                <?php echo $line['text']; ?>
                                            <?php } else { ?>
                                                <?php echo $line; ?>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </div>
            <?php if ($phpLogData['exists'] && !empty($phpLogData['lines'])) { ?>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php echo __('admin_logs_last_lines', count($phpLogData['lines'])); ?>
                        </small>
                        <div>
                            <button class="btn btn-sm btn-warning" 
                                    onclick="clearLog('php')"
                                    title="<?php echo __('admin_logs_clear'); ?>">
                                <i class="fas fa-trash-alt me-1"></i>
                                <?php echo __('admin_logs_clear'); ?>
                            </button>
                            <a href="?action=logs&download=php" 
                               class="btn btn-sm btn-success"
                               title="<?php echo __('admin_logs_download'); ?>">
                                <i class="fas fa-download me-1"></i>
                                <?php echo __('admin_logs_download'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
</div>

<!-- Форма для очистки лога (скрытая) -->
<form method="post" action="index.php" id="clearLogForm" style="display: none;">
    <input type="hidden" name="action" value="log_clear">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <input type="hidden" name="log_type" id="clearLogType" value="">
</form>

<script>
function clearLog(logType) {
    let confirmMsg = '';
    switch(logType) {
        case 'system':
            confirmMsg = '<?php echo __('admin_logs_clear_confirm_system'); ?>';
            break;
        case 'scanner':
            confirmMsg = '<?php echo __('admin_logs_clear_confirm_scanner'); ?>';
            break;
        case 'php':
            confirmMsg = '<?php echo __('admin_logs_clear_confirm_php'); ?>';
            break;
        default:
            confirmMsg = '<?php echo __('admin_logs_clear_confirm'); ?>';
    }
    
    if (confirm(confirmMsg)) {
        document.getElementById('clearLogType').value = logType;
        document.getElementById('clearLogForm').submit();
    }
}

// Автообновление каждые 30 секунд (только если вкладка активна)
let autoRefreshInterval = null;
let currentTab = '<?php echo $activeTab; ?>';

function startAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    autoRefreshInterval = setInterval(function() {
        // Проверяем, активна ли вкладка
        const activeTab = document.querySelector('.tab-pane.active');
        if (activeTab && document.hasFocus()) {
            location.reload();
        }
    }, 30000);
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

// Прокрутка лога вниз
function scrollLogToBottom(container) {
    const logContainer = document.querySelector(container);
    if (logContainer) {
        logContainer.scrollTop = logContainer.scrollHeight;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Прокручиваем логи вниз
    scrollLogToBottom('.log-container');
    
    // Запускаем автообновление
    startAutoRefresh();
    
    // Останавливаем автообновление при потере фокуса
    window.addEventListener('blur', stopAutoRefresh);
    window.addEventListener('focus', startAutoRefresh);
    
    // Обновляем URL при смене вкладки
    const tabs = document.querySelectorAll('#logsTabs button');
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const tabId = this.id.replace('-tab', '');
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabId);
            window.history.replaceState({}, '', url);
        });
    });
});
</script>

<style>
.log-container {
    max-height: 500px;
    overflow-y: auto;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    background: #1e1e1e;
    color: #d4d4d4;
}

.log-container table {
    background: #1e1e1e;
    color: #d4d4d4;
}

.log-container td {
    border-color: #333;
    white-space: pre-wrap;
    word-wrap: break-word;
    padding: 8px 12px;
}

.log-line {
    transition: background-color 0.2s ease;
}

.log-line:hover {
    background-color: #2d2d2d !important;
}

.log-level-badge {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: normal;
}

.log-level-danger {
    border-left: 3px solid #dc3545;
}

.log-level-warning {
    border-left: 3px solid #ffc107;
}

.log-level-success {
    border-left: 3px solid #28a745;
}

.log-level-info {
    border-left: 3px solid #17a2b8;
}

.log-level-secondary {
    border-left: 3px solid #6c757d;
}

.scanner-log-container .log-level-danger {
    background-color: rgba(220, 53, 69, 0.1);
}

.scanner-log-container .log-level-warning {
    background-color: rgba(255, 193, 7, 0.1);
}

.scanner-log-container .log-level-success {
    background-color: rgba(40, 167, 69, 0.1);
}

.php-log-container .log-level-danger {
    background-color: rgba(220, 53, 69, 0.1);
}

.card-header .badge {
    font-size: 0.75rem;
}

@media (max-width: 768px) {
    .log-container {
        font-size: 10px;
    }
    
    .log-level-badge {
        font-size: 8px;
        padding: 1px 4px;
    }
    
    .card-header .d-flex {
        flex-direction: column;
        gap: 8px;
    }
}
</style>