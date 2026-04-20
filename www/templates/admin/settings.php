<?php
// templates/admin/settings.php

$settingsData = $settingsData ?? [];
$currentSettings = $settingsData['settings'] ?? [];
$groups = $settingsData['groups'] ?? [];
$current = $settingsData['current'] ?? [];
$backups = $backups ?? [];
$csrf_token = $csrf_token ?? '';

// Функция для получения значения
function getFieldValue($fieldKey, $field, $currentSettings, $current)
{
    // 1. Сначала из .env
    if (isset($currentSettings[$fieldKey]) && '' !== $currentSettings[$fieldKey]) {
        return $currentSettings[$fieldKey];
    }

    // 2. Затем из текущих значений Config
    switch ($fieldKey) {
        case 'BOOKS_DIR': return $current['books_dir'] ?? $field['default'] ?? '';
        case 'CACHE_DIR': return $current['cache_dir'] ?? $field['default'] ?? '';
        case 'COVER_CACHE_DIR': return $current['cover_cache_dir'] ?? $field['default'] ?? '';
        case 'SCANNER_PATH': return $current['scanner_path'] ?? $field['default'] ?? '';
        case 'DB_TYPE': return $current['db_type'] ?? $field['default'] ?? 'mysql';
        default: return $field['default'] ?? '';
    }
}
?>

<h1 class="mb-4">
    <i class="fas fa-sliders-h me-2"></i>
    <?php echo __('admin_settings_title'); ?>
</h1>

<?php if (!empty($message)) { ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
        <i class="fas fa-<?php echo 'success' === $message_type ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php } ?>

<!-- Вкладки настроек -->
<ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
    <?php $first = true; ?>
    <?php foreach ($groups as $groupKey => $group) { ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $first ? 'active' : ''; ?>" 
                    id="<?php echo $groupKey; ?>-tab" 
                    data-bs-toggle="tab" 
                    data-bs-target="#<?php echo $groupKey; ?>" 
                    type="button" role="tab">
                <i class="fas <?php echo $group['icon'] ?? 'fa-cog'; ?> me-2"></i>
                <?php echo $group['title'] ?? $groupKey; ?>
            </button>
        </li>
        <?php $first = false; ?>
    <?php } ?>
    
    <li class="nav-item">
        <button class="nav-link" id="backups-tab" data-bs-toggle="tab" data-bs-target="#backups" type="button" role="tab">
            <i class="fas fa-archive me-2"></i>
            <?php echo __('settings_backups_title'); ?>
        </button>
    </li>
</ul>

<!-- Форма настроек -->
<form method="post" action="index.php" id="settingsForm">
    <input type="hidden" name="action" value="settings_save">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    
    <div class="tab-content" id="settingsTabsContent">
        <?php $first = true; ?>
        <?php foreach ($groups as $groupKey => $group) { ?>
            <div class="tab-pane fade <?php echo $first ? 'show active' : ''; ?>" 
                 id="<?php echo $groupKey; ?>" 
                 role="tabpanel">
                
                <div class="card">
                    <div class="card-header bg-<?php echo $first ? 'primary' : 'secondary'; ?> text-white">
                        <h5 class="mb-0">
                            <i class="fas <?php echo $group['icon'] ?? 'fa-cog'; ?> me-2"></i>
                            <?php echo $group['title'] ?? $groupKey; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($group['fields'] as $fieldKey => $field) { ?>
                            <?php
                            // Проверяем условия отображения
                            $show = true;
                            if (isset($field['condition']) && is_array($field['condition'])) {
                                foreach ($field['condition'] as $condKey => $condValue) {
                                    if (($currentSettings[$condKey] ?? '') != $condValue) {
                                        $show = false;
                                        break;
                                    }
                                }
                            }
                            if (!$show) {
                                continue;
                            }

                            $fieldValue = getFieldValue($fieldKey, $field, $currentSettings, $current);
                            ?>
                            
                            <div class="mb-3 row field-<?php echo $fieldKey; ?>" data-type="<?php echo $field['type']; ?>">
                                <label class="col-sm-4 col-form-label">
                                    <?php echo $field['label'] ?? $fieldKey; ?>
                                    <?php if (isset($field['description'])) { ?>
                                        <i class="fas fa-info-circle text-muted ms-1" 
                                           data-bs-toggle="tooltip" 
                                           title="<?php echo htmlspecialchars($field['description']); ?>"></i>
                                    <?php } ?>
                                    <?php if (!empty($field['required'])) { ?>
                                        <span class="text-danger">*</span>
                                    <?php } ?>
                                </label>
                                <div class="col-sm-8">
                                    <?php if ('checkbox' === $field['type']) { ?>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" 
                                                   type="checkbox" 
                                                   name="<?php echo $fieldKey; ?>" 
                                                   id="<?php echo $fieldKey; ?>"
                                                   <?php echo ('true' === $fieldValue || '1' === $fieldValue) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="<?php echo $fieldKey; ?>">
                                                <?php echo $field['label'] ?? $fieldKey; ?>
                                            </label>
                                        </div>
                                        
                                    <?php } elseif ('select' === $field['type']) { ?>
                                        <select class="form-select" name="<?php echo $fieldKey; ?>" id="<?php echo $fieldKey; ?>">
                                            <?php foreach ($field['options'] as $optValue => $optLabel) { ?>
                                                <option value="<?php echo $optValue; ?>" 
                                                    <?php echo $fieldValue == $optValue ? 'selected' : ''; ?>>
                                                    <?php echo $optLabel; ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                        
                                    <?php } elseif ('password_hash' === $field['type']) { ?>
                                        <div class="border p-3 bg-light rounded">
                                            <div class="mb-2">
                                                <span class="badge bg-success">
                                                    <i class="fas fa-lock me-1"></i>
                                                    <?php echo !empty($currentSettings['ADMIN_PASSWORD_HASH']) ? __('settings_password_saved') : __('settings_password_not_set'); ?>
                                                </span>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-2">
                                                    <input type="password" class="form-control" 
                                                           name="new_password" 
                                                           id="new_password"
                                                           placeholder="<?php echo __('settings_field_new_password'); ?>"
                                                           autocomplete="off">
                                                </div>
                                                <div class="col-md-6 mb-2">
                                                    <input type="password" class="form-control" 
                                                           name="confirm_password" 
                                                           id="confirm_password"
                                                           placeholder="<?php echo __('settings_field_confirm_password'); ?>"
                                                           autocomplete="off">
                                                </div>
                                            </div>
                                            <div class="form-text">
                                                <i class="fas fa-info-circle me-1"></i>
                                                <?php echo __('settings_password_hint'); ?>
                                            </div>
                                            <div id="passwordMatch" class="mt-2" style="display: none;"></div>
                                        </div>
                                        
                                    <?php } elseif ('DB_PASS' === $fieldKey) { ?>
                                        <div class="input-group">
                                            <input type="password" class="form-control" 
                                                   name="<?php echo $fieldKey; ?>" 
                                                   id="<?php echo $fieldKey; ?>"
                                                   value="********"
                                                   placeholder="<?php echo __('settings_db_password'); ?>"
                                                   autocomplete="off">
                                            <button class="btn btn-outline-secondary" type="button" 
                                                    onclick="toggleDbPassVisibility()">
                                                <i class="fas fa-eye" id="toggleDbPassIcon"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">
                                            <i class="fas fa-info-circle me-1"></i>
                                            <?php echo __('settings_db_password_hint'); ?>
                                        </div>
                                        
                                    <?php } else { ?>
                                        <input type="<?php echo $field['type'] ?? 'text'; ?>" 
                                               class="form-control" 
                                               name="<?php echo $fieldKey; ?>" 
                                               id="<?php echo $fieldKey; ?>"
                                               value="<?php echo htmlspecialchars($fieldValue); ?>"
                                               <?php echo isset($field['min']) ? 'min="'.$field['min'].'"' : ''; ?>
                                               <?php echo isset($field['max']) ? 'max="'.$field['max'].'"' : ''; ?>
                                               <?php echo isset($field['step']) ? 'step="'.$field['step'].'"' : ''; ?>
                                               <?php echo !empty($field['required']) ? 'required' : ''; ?>
                                               placeholder="<?php echo $field['label'] ?? $fieldKey; ?>">
                                    <?php } ?>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
            <?php $first = false; ?>
        <?php } ?>
        
        <!-- Вкладка с бэкапами -->
        <div class="tab-pane fade" id="backups" role="tabpanel">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-archive me-2"></i>
                        <?php echo __('settings_backups_title'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($backups)) { ?>
                        <p class="text-muted text-center py-4">
                            <i class="fas fa-folder-open fa-3x mb-3 d-block"></i>
                            <?php echo __('settings_backups_none'); ?>
                        </p>
                    <?php } else { ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo __('settings_backup_date'); ?></th>
                                        <th><?php echo __('settings_backup_file'); ?></th>
                                        <th><?php echo __('settings_backup_size'); ?></th>
                                        <th><?php echo __('admin_backup_actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backups as $backup) { ?>
                                        <tr>
                                            <td><?php echo $backup['date'] ?? ''; ?></td>
                                            <td><code><?php echo $backup['filename'] ?? ''; ?></code></td>
                                            <td><?php echo isset($backup['size']) ? round($backup['size'] / 1024, 2) : 0; ?> KB</td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-success"
                                                        onclick="restoreBackup('<?php echo $backup['filename'] ?? ''; ?>')"
                                                        title="<?php echo __('settings_backup_restore'); ?>">
                                                    <i class="fas fa-undo"></i>
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
                        <?php echo __('settings_backup_auto'); ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Информация о текущих путях -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card bg-light">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <?php echo __('settings_current_paths'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th><?php echo __('settings_books_dir'); ?></th>
                                    <td><code><?php echo htmlspecialchars($current['books_dir'] ?? __('settings_not_defined')); ?></code></td>
                                </tr>
                                <tr>
                                    <th><?php echo __('settings_cache_dir'); ?></th>
                                    <td><code><?php echo htmlspecialchars($current['cache_dir'] ?? __('settings_not_defined')); ?></code></td>
                                </tr>
                                <tr>
                                    <th><?php echo __('settings_cover_cache_dir'); ?></th>
                                    <td><code><?php echo htmlspecialchars($current['cover_cache_dir'] ?? __('settings_not_defined')); ?></code></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th><?php echo __('settings_scanner_path'); ?></th>
                                    <td><code><?php echo htmlspecialchars($current['scanner_path'] ?? __('settings_not_defined')); ?></code></td>
                                </tr>
                                <tr>
                                    <th><?php echo __('settings_db_type'); ?></th>
                                    <td><code><?php echo htmlspecialchars($current['db_type'] ?? __('settings_not_defined')); ?></code></td>
                                </tr>
                                <tr>
                                    <th><?php echo __('settings_caching'); ?></th>
                                    <td>
                                        <code>
                                            <?php echo isset($current['cache_enabled']) && $current['cache_enabled']
                                                ? __('settings_enabled')
                                                : __('settings_disabled'); ?>
                                        </code>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Кнопки сохранения -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary btn-lg" id="saveSettingsBtn">
                        <i class="fas fa-save me-2"></i>
                        <?php echo __('settings_save_all'); ?>
                    </button>
                    
                    <button type="button" class="btn btn-secondary btn-lg ms-2" 
                            onclick="testDatabaseConnection()">
                        <i class="fas fa-plug me-2"></i>
                        <?php echo __('settings_test_db'); ?>
                    </button>
                    
                    <button type="button" class="btn btn-info btn-lg ms-2"
                            onclick="window.location.reload()">
                        <i class="fas fa-sync-alt me-2"></i>
                        <?php echo __('settings_refresh'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Форма для восстановления бэкапа (скрытая) -->
<form method="post" action="index.php" id="restoreForm" style="display: none;">
    <input type="hidden" name="action" value="settings_restore">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <input type="hidden" name="backup_file" id="backupFile" value="">
</form>

<script>
// Переключение видимости пароля БД
function toggleDbPassVisibility() {
    const passField = document.getElementById('DB_PASS');
    const icon = document.getElementById('toggleDbPassIcon');
    
    if (passField) {
        if (passField.type === 'password') {
            passField.type = 'text';
            icon.className = 'fas fa-eye-slash';
        } else {
            passField.type = 'password';
            icon.className = 'fas fa-eye';
        }
    }
}

// Проверка совпадения паролей
const newPassField = document.getElementById('new_password');
const confirmPassField = document.getElementById('confirm_password');
if (newPassField && confirmPassField) {
    newPassField.addEventListener('keyup', checkPasswords);
    confirmPassField.addEventListener('keyup', checkPasswords);
}

function checkPasswords() {
    const newPass = document.getElementById('new_password').value;
    const confirmPass = document.getElementById('confirm_password').value;
    const matchDiv = document.getElementById('passwordMatch');
    
    if (!matchDiv) return;
    
    if (newPass === '' && confirmPass === '') {
        matchDiv.style.display = 'none';
        return;
    }
    
    if (newPass === confirmPass) {
        if (newPass.length >= 6) {
            matchDiv.innerHTML = '<div class="alert alert-success py-1"><i class="fas fa-check-circle me-1"></i> <?php echo __('settings_passwords_match'); ?></div>';
        } else {
            matchDiv.innerHTML = '<div class="alert alert-warning py-1"><i class="fas fa-exclamation-triangle me-1"></i> <?php echo __('settings_password_length'); ?></div>';
        }
    } else {
        matchDiv.innerHTML = '<div class="alert alert-danger py-1"><i class="fas fa-times-circle me-1"></i> <?php echo __('settings_passwords_dont_match'); ?></div>';
    }
    matchDiv.style.display = 'block';
}

// Восстановление из бэкапа
function restoreBackup(filename) {
    if (confirm('<?php echo __('settings_restore_confirm'); ?>'.replace('%s', filename))) {
        document.getElementById('backupFile').value = filename;
        document.getElementById('restoreForm').submit();
    }
}

// Тестирование подключения к БД
async function testDatabaseConnection() {
    const formData = new FormData(document.getElementById('settingsForm'));
    const data = {};
    formData.forEach((value, key) => {
        if (key !== 'action' && key !== 'csrf_token') {
            data[key] = value;
        }
    });
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?php echo __('settings_testing'); ?>';
    
    try {
        const response = await fetch('index.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'settings_test_db',
                csrf_token: '<?php echo $csrf_token; ?>',
                ...data
            })
        });
        
        const result = await response.json();
        if (result.success) {
            alert('✅ ' + result.message);
        } else {
            alert('❌ ' + result.message);
        }
    } catch (error) {
        alert('❌ <?php echo __('settings_test_error'); ?>: ' + error);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

// Инициализация tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Предупреждение при изменении типа БД
const dbTypeSelect = document.getElementById('DB_TYPE');
if (dbTypeSelect) {
    dbTypeSelect.addEventListener('change', function() {
        if (this.value !== '<?php echo $current['db_type'] ?? ''; ?>') {
            if (!confirm('<?php echo __('settings_db_warning'); ?>')) {
                this.value = '<?php echo $current['db_type'] ?? ''; ?>';
            }
        }
    });
}

// Блокировка кнопки сохранения при отправке
document.getElementById('settingsForm').addEventListener('submit', function() {
    const btn = document.getElementById('saveSettingsBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?php echo __('settings_saving'); ?>';
});
</script>

<style>
.tab-content .card {
    border-top-left-radius: 0;
    border-top-right-radius: 0;
}

.form-switch .form-check-input {
    width: 3em;
    height: 1.5em;
    cursor: pointer;
}

.form-switch .form-check-input:checked {
    background-color: #28a745;
    border-color: #28a745;
}

.form-label i {
    cursor: help;
}

.table td {
    vertical-align: middle;
}

code {
    background: #f8f9fa;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.9em;
    word-break: break-all;
}

.badge {
    font-size: 0.9rem;
}

.input-group .btn {
    border: 1px solid #ced4da;
}

.card-header .badge {
    font-size: 0.8rem;
}

@media (max-width: 768px) {
    .col-sm-4, .col-sm-8 {
        width: 100%;
    }
    
    .form-switch {
        margin-top: 0.5rem;
    }
    
    .btn-lg {
        font-size: 0.9rem;
        padding: 8px 16px;
    }
}
</style>