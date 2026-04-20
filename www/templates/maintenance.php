<?php
// templates/maintenance.php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../lib/DatabaseChecker.php';
require_once __DIR__.'/../init.php';

$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = rtrim(dirname($scriptPath), '/');
$installPath = $basePath.'/install';
$adminPath = $basePath.'/admin';

$isAdmin = false !== strpos($scriptPath, '/admin/');

// Проверяем, есть ли таблицы
$checker = DatabaseChecker::getInstance();
$status = $checker->getDetailedStatus();
$hasTables = $status['tables_exist'];
$dbAvailable = $status['database_available'];

// Определяем тип ошибки
if (!$dbAvailable) {
    $errorType = 'database';
    $errorTitle = __('error_database');
    $errorMessage = __('error_database_desc');
    $icon = 'fa-database';
    $iconColor = 'error';
} elseif (!$hasTables) {
    $errorType = 'init';
    $errorTitle = __('error_init');
    $errorMessage = __('error_init_desc');
    $icon = 'fa-table';
    $iconColor = 'warning';
} else {
    $errorType = 'unknown';
    $errorTitle = __('error_unknown');
    $errorMessage = __('error_unknown_desc');
    $icon = 'fa-question-circle';
    $iconColor = 'error';
}

// Получаем информацию о конфигурации
$dbConfig = Config::getDbConfig();
$configInfo = [
    'type' => $dbConfig['type'],
    'host' => $dbConfig['host'] ?? 'N/A',
    'database' => $dbConfig['name'] ?? $dbConfig['path'] ?? 'N/A',
    'user' => ('mysql' === $dbConfig['type'] && isset($dbConfig['user'])) ? '***' : '('.__('empty').')',
    'path' => $dbConfig['path'] ?? 'N/A',
];

// Проверяем существование директории install
$installDirExists = file_exists(__DIR__.'/../install');
$installFileExists = file_exists(__DIR__.'/../install/index.php');
?>
<!DOCTYPE html>
<html lang="<?php echo Translator::getInstance()->getCurrentLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(Config::getSiteTitle()); ?> - <?php echo __('maintenance_title'); ?></title>
    
    <!-- Bootstrap CSS -->

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/all.min.css">
    <script src="<?php echo $basePath; ?>/css/js/bootstrap.bundle.min.js"></script>
    
<style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 20px;
        }
        
        .maintenance-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 800px;
            width: 100%;
            margin: 20px auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .status-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }
        
        .status-icon.warning {
            color: #f39c12;
        }
        
        .status-icon.error {
            color: #e74c3c;
        }
        
        .status-icon.success {
            color: #27ae60;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .maintenance-title {
            font-size: 32px;
            font-weight: 700;
            color: #333;
            margin-bottom: 15px;
        }
        
        .maintenance-message {
            color: #666;
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        .install-prompt {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin: 30px 0;
            text-align: center;
        }
        
        .install-prompt h3 {
            color: white;
            margin-bottom: 20px;
            font-size: 24px;
        }
        
        .install-prompt p {
            color: rgba(255,255,255,0.9);
            margin-bottom: 25px;
            font-size: 16px;
        }
        
        .install-button {
            display: inline-block;
            background: white;
            color: #667eea;
            padding: 15px 40px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 18px;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        
        .install-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.3);
            color: #764ba2;
            text-decoration: none;
        }
        
        .install-button i {
            margin-right: 10px;
        }
        
        .status-details {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: left;
            border: 1px solid #e9ecef;
        }
        
        .status-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .status-item:last-child {
            border-bottom: none;
        }
        
        .status-item .label {
            flex: 1;
            font-weight: 600;
            color: #555;
        }
        
        .status-item .value {
            font-family: monospace;
        }
        
        .error-detail {
            background: #fff3f3;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
            font-size: 14px;
            color: #721c24;
            text-align: left;
            word-break: break-word;
        }
        
        .maintenance-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        
        .maintenance-actions .btn {
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 50px;
            transition: all 0.3s ease;
        }
        
        .maintenance-actions .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .refresh-btn {
            animation: spin 2s linear infinite;
            display: inline-block;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .config-info {
            background: #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            font-size: 13px;
            text-align: left;
        }
        
        .config-info pre {
            margin: 10px 0 0;
            padding: 10px;
            background: #fff;
            border-radius: 5px;
            overflow-x: auto;
        }
        
        .steps {
            text-align: left;
            margin: 20px 0;
            padding-left: 20px;
        }
        
        .steps li {
            margin-bottom: 10px;
            color: #555;
        }
        
        .install-missing {
            background: #ffc107;
            color: #333;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        
        .language-selector {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.9);
            padding: 10px 15px;
            border-radius: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .language-selector select {
            border: none;
            background: transparent;
            font-weight: 500;
            cursor: pointer;
            outline: none;
        }
        
        @media (max-width: 768px) {
            .maintenance-card {
                padding: 20px;
            }
            
            .maintenance-actions .btn {
                width: 100%;
                margin: 5px 0;
            }
            
            .language-selector {
                top: 10px;
                right: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Language selector -->
    <?php
    $detector = LanguageDetector::getInstance();
$availableLangs = $detector->getAvailableLanguages();
$currentLang = $detector->getCurrentLanguage();
?>
    <?php if (count($availableLangs) > 1) { ?>
    <div class="language-selector">
        <form method="post" action="<?php echo $basePath; ?>/change-language.php">
            <select name="lang" onchange="this.form.submit()">
                <?php foreach ($availableLangs as $lang) {
                    $langName = $detector->getLanguageName($lang);
                    $langFlag = $detector->getLanguageFlag($lang);
                    ?>
                <option value="<?php echo $lang; ?>" <?php echo $lang === $currentLang ? 'selected' : ''; ?>>
                    <?php echo $langFlag.' '.$langName; ?>
                </option>
                <?php } ?>
            </select>
        </form>
    </div>
    <?php } ?>
    
    <div class="maintenance-card">
        <div class="text-center">
            <!-- Иконка в зависимости от статуса -->
            <div class="status-icon <?php echo $iconColor; ?>">
                <i class="fas <?php echo $icon; ?>"></i>
            </div>
            
            <h1 class="maintenance-title">
                <?php echo $errorTitle; ?>
            </h1>
            
            <div class="maintenance-message">
                <?php echo $errorMessage; ?>
            </div>
            
            <!-- ПРИГЛАШЕНИЕ К УСТАНОВКЕ -->
            <?php if ($installDirExists && $installFileExists && ('init' === $errorType || 'database' === $errorType)) { ?>
                <div class="install-prompt">
                    <i class="fas fa-magic fa-3x mb-3"></i>
                    <h3>🚀 <?php echo __('install_quick'); ?></h3>
                    <p>
                        <?php echo __('install_not_configured'); ?>
                    </p>
                    
                    <a href="<?php echo $installPath; ?>/" class="install-button">
                        <i class="fas fa-arrow-right"></i>
                        <?php echo __('install_run'); ?>
                    </a>
                    
                    <div class="steps">
                        <p class="mt-4 mb-2"><strong><?php echo __('install_will_do'); ?></strong></p>
                        <ul class="text-start">
                            <li>✅ <?php echo __('install_check_dirs'); ?></li>
                            <li>✅ <?php echo __('install_configure_db'); ?></li>
                            <li>✅ <?php echo __('install_create_tables'); ?></li>
                            <li>✅ <?php echo __('install_run_scanner'); ?></li>
                        </ul>
                    </div>
                </div>
            <?php } elseif ($installDirExists && $installFileExists && 'unknown' === $errorType) { ?>
                <div class="install-prompt" style="background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);">
                    <i class="fas fa-tools fa-3x mb-3"></i>
                    <h3>🔧 <?php echo __('maintenance_diagnose'); ?></h3>
                    <p>
                        <?php echo __('install_not_configured'); ?>
                    </p>
                    <a href="<?php echo $installPath; ?>/diagnose_sqlite.php" class="install-button" style="color: #f39c12;">
                        <i class="fas fa-stethoscope me-2"></i>
                        <?php echo __('maintenance_diagnose'); ?>
                    </a>
                </div>
            <?php } else { ?>
                <div class="install-missing">
                    <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                    <h4>⚠️ <?php echo __('install_not_found'); ?></h4>
                    <p>
                        <?php echo sprintf(__('install_dir_missing'), '<code>/install/</code>'); ?>
                    </p>
                </div>
            <?php } ?>
            
            <!-- Детальная информация о статусе -->
            <div class="status-details">
                <h6 class="mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <?php echo __('diagnostics'); ?>
                </h6>
                
                <div class="status-item">
                    <span class="label"><?php echo __('diagnostics_database'); ?></span>
                    <span class="value">
                        <?php if ($dbAvailable) { ?>
                            <span class="badge bg-success"><?php echo __('diagnostics_available'); ?></span>
                        <?php } else { ?>
                            <span class="badge bg-danger"><?php echo __('diagnostics_unavailable'); ?></span>
                        <?php } ?>
                    </span>
                </div>
                
                <?php if ($dbAvailable) { ?>
                <div class="status-item">
                    <span class="label"><?php echo __('diagnostics_tables'); ?></span>
                    <span class="value">
                        <?php if ($hasTables) { ?>
                            <span class="badge bg-success"><?php echo __('diagnostics_tables_created'); ?></span>
                        <?php } else { ?>
                            <span class="badge bg-warning"><?php echo __('diagnostics_tables_missing'); ?></span>
                        <?php } ?>
                    </span>
                </div>
                <?php } ?>
                
                <div class="status-item">
                    <span class="label"><?php echo __('diagnostics_db_type'); ?></span>
                    <span class="value">
                        <span class="badge bg-info"><?php echo strtoupper($configInfo['type']); ?></span>
                    </span>
                </div>
                
                <?php if ('sqlite' === $configInfo['type']) { ?>
                <div class="status-item">
                    <span class="label"><?php echo __('diagnostics_db_file'); ?></span>
                    <span class="value">
                        <small><?php echo basename($configInfo['database']); ?></small>
                    </span>
                </div>
                <div class="status-item">
                    <span class="label"><?php echo __('diagnostics_file_exists'); ?></span>
                    <span class="value">
                        <?php if (file_exists($configInfo['database'])) { ?>
                            <span class="badge bg-success"><?php echo __('diagnostics_yes'); ?></span>
                        <?php } else { ?>
                            <span class="badge bg-danger"><?php echo __('diagnostics_no'); ?></span>
                        <?php } ?>
                    </span>
                </div>
                <?php } ?>
            </div>
            
            <?php if (isset($status['error']) && $status['error']) { ?>
                <div class="error-detail">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong><?php echo __('error_details'); ?></strong><br>
                    <small><?php echo htmlspecialchars($status['error']); ?></small>
                </div>
            <?php } ?>
            
            <!-- Конфигурация для отладки (только для администраторов) -->
            <?php if ($isAdmin) { ?>
            <div class="config-info">
                <details>
                    <summary class="text-muted">
                        <i class="fas fa-cog me-1"></i>
                        <?php echo __('config_debug'); ?>
                    </summary>
                    <pre><?php echo htmlspecialchars(print_r($configInfo, true)); ?></pre>
                </details>
            </div>
            <?php } ?>
            
            <!-- Действия -->
            <div class="maintenance-actions">
                <button onclick="location.reload()" class="btn btn-primary">
                    <i class="fas fa-sync-alt refresh-btn me-2"></i>
                    <?php echo __('maintenance_refresh'); ?>
                </button>
                
                <?php if ($installDirExists && $installFileExists) { ?>
                    <?php if (!$dbAvailable) { ?>
                        <a href="<?php echo $installPath; ?>/?step=3" class="btn btn-warning">
                            <i class="fas fa-wrench me-2"></i>
                            <?php echo __('maintenance_configure_db'); ?>
                        </a>
                    <?php } elseif (!$hasTables) { ?>
                        <a href="<?php echo $installPath; ?>/?step=4" class="btn btn-success">
                            <i class="fas fa-play me-2"></i>
                            <?php echo __('maintenance_create_tables'); ?>
                        </a>
                    <?php } ?>
                    
                    <a href="<?php echo $installPath; ?>/" class="btn btn-info">
                        <i class="fas fa-magic me-2"></i>
                        <?php echo __('maintenance_run_installer'); ?>
                    </a>
                    
                    <a href="<?php echo $installPath; ?>/diagnose_sqlite.php" class="btn btn-secondary">
                        <i class="fas fa-stethoscope me-2"></i>
                        <?php echo __('maintenance_diagnose'); ?>
                    </a>
                <?php } ?>
            </div>
            
            <div class="mt-4 text-muted small">
                <i class="fas fa-clock me-1"></i>
                <?php echo date('d.m.Y H:i:s'); ?>
            </div>
            
            <?php if ($installDirExists && $installFileExists) { ?>
            <div class="mt-3">
                <div class="alert alert-warning py-2 mb-0">
                    <i class="fas fa-shield-alt me-2"></i>
                    <small>
                        <strong>⚠️ <?php echo __('security_warning'); ?></strong>
                    </small>
                </div>
            </div>
            <?php } ?>
        </div>
    </div>
</body>
</html>