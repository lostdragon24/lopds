<?php
// /install/templates/header.php

// Определяем базовый путь надежным способом
$scriptPath = $_SERVER['SCRIPT_NAME'];
$installDir = dirname($scriptPath);

// Вариант 1: Если установщик в подпапке, поднимаемся на один уровень
if (basename($installDir) === 'install') {
    $basePath = dirname($installDir); // /5
} else {
    // Альтернативный способ: ищем корень сайта
    $basePath = rtrim($installDir, '/');
}

// Убираем возможные дублирующиеся слэши
$basePath = rtrim($basePath, '/');

$stepsConfig = include __DIR__ . '/../config/steps.php';
$progress = $stepsConfig['steps'][$step]['progress'] ?? 14;

// Получаем информацию о языках для переключателя
$detector = LanguageDetector::getInstance();
$currentLang = $detector->getCurrentLanguage();
$availableLangs = $detector->getAvailableLanguages();
?>

<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('install_wizard'); ?></title>

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/all.min.css">

    <!-- Installer CSS -->
    <link rel="stylesheet" href="assets/css/installer.css">

    <style>
        .language-selector {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .language-selector .dropdown-toggle {
            background: rgba(255,255,255,0.9);
            border: none;
            border-radius: 30px;
            padding: 8px 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .language-selector .dropdown-toggle:hover {
            background: white;
        }
        
        .language-selector .dropdown-menu {
            min-width: 120px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .language-selector .dropdown-item {
            padding: 8px 15px;
            cursor: pointer;
        }
        
        .language-selector .dropdown-item:hover {
            background-color: #f8f9fa;
        }
        
        .language-selector .dropdown-item.active {
            background-color: #007bff;
            color: white;
        }
        
        @media (max-width: 768px) {
            .language-selector {
                top: 10px;
                right: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Language selector -->
    <?php if (count($availableLangs) > 1): ?>
    <div class="language-selector dropdown">
        <button class="btn btn-light dropdown-toggle" type="button" id="languageDropdown" 
                data-bs-toggle="dropdown" aria-expanded="false">
            <?php echo $detector->getLanguageFlag() . ' ' . $detector->getLanguageName(); ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="languageDropdown">
            <?php foreach ($availableLangs as $lang):
                $langFlag = $detector->getLanguageFlag($lang);
                $langName = $detector->getLanguageName($lang);
                ?>
            <li>
                <a class="dropdown-item <?php echo $lang === $currentLang ? 'active' : ''; ?>" 
                   href="#" 
                   onclick="event.preventDefault(); changeLanguage('<?php echo $lang; ?>');">
                    <?php echo $langFlag . ' ' . $langName; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-magic me-2"></i>
                            <?php echo __('install_wizard'); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        
                        <!-- Прогресс-бар -->
                        <div class="progress mb-4" style="height: 30px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                 role="progressbar" 
                                 style="width: <?php echo $progress; ?>%;"
                                 aria-valuenow="<?php echo $progress; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                                <?php echo sprintf(__('install_step'), $step); ?>
                            </div>
                        </div>
                        
                        <!-- Сообщения -->
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <?php echo nl2br(htmlspecialchars($error)); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="fas fa-check-circle me-2"></i>
                                <?php echo nl2br(htmlspecialchars($success)); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($warning): ?>
                            <div class="alert alert-warning alert-dismissible fade show">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?php echo nl2br(htmlspecialchars($warning)); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

<script src="assets/js/installer.js"></script>

<script>
// Функция для смены языка
function changeLanguage(lang) {
    const form = document.createElement('form');
    form.method = 'POST';
    // Используем абсолютный путь от корня сайта
    form.action = '<?php echo $basePath; ?>/change-language.php';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'lang';
    input.value = lang;
    
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

// Отладка (можно убрать)
console.log('Installer loaded, basePath: <?php echo $basePath; ?>');
</script>
