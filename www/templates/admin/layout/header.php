<?php
// templates/admin/layout/header.php

$currentAction = $_GET['action'] ?? 'dashboard';
$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = rtrim(dirname(dirname($scriptPath)), '/');

// Получаем информацию о языках
$detector = LanguageDetector::getInstance();
$currentLang = $detector->getCurrentLanguage();
$availableLangs = $detector->getAvailableLanguages();
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('admin_panel'); ?> | <?php echo Config::SITE_TITLE; ?></title>
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/all.min.css">
    
    <style>
        :root {
            --sidebar-width: 250px;
        }
        
        body {
            background: #f8f9fa;
        }
        
        .wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: var(--sidebar-width);
            background: #2c3e50;
            color: #fff;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            transition: all 0.3s;
        }
        
        .sidebar .nav-link:hover {
            background: #34495e;
            color: #fff;
        }
        
        .sidebar .nav-link.active {
            background: #3498db;
            color: #fff;
        }
        
        .sidebar .nav-link i {
            width: 24px;
            margin-right: 10px;
        }
        
        .content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 20px;
        }
        
        .navbar-top {
            background: #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            padding: 15px 20px;
            border-radius: 8px;
        }
        
        .card {
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: none;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .card-header {
            background: #fff;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .card-header i {
            margin-right: 8px;
            color: #3498db;
        }
        
        .stat-card {
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>

<div class="wrapper">
    <!-- Боковое меню -->
    <div class="sidebar">
        <div class="p-3">
            <h5 class="text-white mb-4">
                <i class="fas fa-cogs me-2"></i>
                <?php echo __('admin_panel'); ?>
            </h5>
        </div>
        
        <nav class="nav flex-column">
            <a class="nav-link <?php echo 'dashboard' === $currentAction ? 'active' : ''; ?>" 
               href="?action=dashboard">
                <i class="fas fa-tachometer-alt"></i>
                <?php echo __('admin_dashboard'); ?>
            </a>
            
            <a class="nav-link <?php echo 'books' === $currentAction ? 'active' : ''; ?>" 
               href="?action=books">
                <i class="fas fa-book"></i>
                <?php echo __('admin_books'); ?>
            </a>
            
            <a class="nav-link <?php echo 'scanner' === $currentAction ? 'active' : ''; ?>" 
               href="?action=scanner">
                <i class="fas fa-robot"></i>
                <?php echo __('admin_scanner'); ?>
            </a>
            
            <a class="nav-link <?php echo 'database' === $currentAction ? 'active' : ''; ?>" 
               href="?action=database">
                <i class="fas fa-database"></i>
                <?php echo __('admin_database'); ?>
            </a>
            
            <a class="nav-link <?php echo 'settings' === $currentAction ? 'active' : ''; ?>" 
               href="?action=settings">
                <i class="fas fa-sliders-h"></i>
                <?php echo __('admin_settings'); ?>
            </a>
            
            <a class="nav-link <?php echo 'logs' === $currentAction ? 'active' : ''; ?>" 
               href="?action=logs">
                <i class="fas fa-history"></i>
                <?php echo __('admin_logs'); ?>
            </a>

         <a class="nav-link <?php echo 'library_backup' === $currentAction ? 'active' : ''; ?>" 
	    href="?action=library_backup">
	    <i class="fas fa-archive"></i>
	    <?php echo __('admin_library_backup'); ?>
    </a>
            
            <div class="border-top border-secondary my-3"></div>
            
            <a class="nav-link" href="../index.php" target="_blank">
                <i class="fas fa-external-link-alt"></i>
                <?php echo __('admin_go_to_site'); ?>
            </a>
            
            <a class="nav-link text-danger" href="?action=logout">
                <i class="fas fa-sign-out-alt"></i>
                <?php echo __('admin_logout'); ?>
            </a>
        </nav>
    </div>
    
    <!-- Основной контент -->
    <div class="content">
        <div class="navbar-top d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <?php
                $titles = [
                    'dashboard' => __('admin_dashboard'),
                    'books' => __('admin_books'),
                    'scanner' => __('admin_scanner'),
                    'database' => __('admin_database'),
                    'settings' => __('admin_settings'),
                    'logs' => __('admin_logs'),
                ];
echo $titles[$currentAction] ?? __('admin_panel');
?>
            </h5>
            <div>
                <span class="badge bg-secondary me-2">
                    <i class="fas fa-user me-1"></i>
                    <?php echo __('admin_administrator'); ?>
                </span>
                <span class="badge bg-info">
                    <i class="fas fa-clock me-1"></i>
                    <?php echo date('d.m.Y H:i'); ?>
                </span>
                
                <!-- Переключатель языка -->
                <?php if (count($availableLangs) > 1) { ?>
                <div class="dropdown d-inline-block ms-2">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" 
                            data-bs-toggle="dropdown" aria-expanded="false">
                        <?php echo $detector->getLanguageFlag().' '.$detector->getLanguageName(); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach ($availableLangs as $lang) {
                            $langFlag = $detector->getLanguageFlag($lang);
                            $langName = $detector->getLanguageName($lang);
                            ?>
                        <li>
                            <a class="dropdown-item <?php echo $lang === $currentLang ? 'active' : ''; ?>" 
                               href="#" 
                               data-lang="<?php echo $lang; ?>">
                                <?php echo $langFlag.' '.$langName; ?>
                            </a>
                        </li>
                        <?php } ?>
                    </ul>
                </div>
                <?php } ?>
            </div>
        </div>
