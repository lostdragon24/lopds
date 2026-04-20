<?php
// templates/header.php

// Получаем базовый путь без учета админки
$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = rtrim(dirname(dirname($scriptPath)), '/'); // Поднимаемся на один уровень выше для админки

// Если мы не в админке, используем обычный путь
if (false === strpos($scriptPath, '/admin/')) {
    $basePath = rtrim(dirname($scriptPath), '/');
}

$csrfToken = Config::startSecureSession();

// Определяем, находимся ли мы в админке
$isAdmin = false !== strpos($scriptPath, '/admin/');

// Получаем информацию о языках
$detector = LanguageDetector::getInstance();
$currentLang = $detector->getCurrentLanguage();
$availableLangs = $detector->getAvailableLanguages();
$langName = $detector->getLanguageName();
$langFlag = $detector->getLanguageFlag();
?>

<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(Config::getSiteTitle()); ?></title>
    <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/all.min.css">
    <script src="<?php echo $basePath; ?>/css/js/bootstrap.bundle.min.js"></script>

    <!-- Глобальные переменные JavaScript -->
<script>
    window.CSRF_TOKEN = '<?php echo $csrfToken; ?>';
    window.API_URL = '<?php echo $basePath; ?>/api/rating.php';
    window.BASE_PATH = '<?php echo $basePath; ?>';
    window.CURRENT_LANG = '<?php echo $currentLang; ?>';
    window.TRANSLATIONS = {
        // Общие
        'error': '<?php echo __('error'); ?>',
        'success': '<?php echo __('success'); ?>',
        'warning': '<?php echo __('warning'); ?>',
        'info': '<?php echo __('info'); ?>',
        'close': '<?php echo __('close'); ?>',
        'error_occurred': '<?php echo __('error_occurred'); ?>',
        'error_unknown': '<?php echo __('error_unknown'); ?>',
        'error_csrf': '<?php echo __('error_csrf'); ?>',
        'error_invalid_id': '<?php echo __('error_invalid_id'); ?>',
        
        // Рейтинги
        'rating_click_to_rate': '<?php echo __('rating_click_to_rate'); ?>',
        'rating_saved': '<?php echo __('rating_saved'); ?>',
        'rating_error': '<?php echo __('rating_error'); ?>',
        'rating_no_votes': '<?php echo __('rating_no_votes'); ?>',
        'rating_vote_1': '<?php echo __('rating_vote_1'); ?>',
        'rating_vote_2': '<?php echo __('rating_vote_2'); ?>',
        'rating_vote_3': '<?php echo __('rating_vote_2'); ?>',
        'rating_vote_4': '<?php echo __('rating_vote_2'); ?>',
        'rating_vote_5': '<?php echo __('rating_vote_5'); ?>',
        'rating_star_1': '<?php echo __('rating_star_1'); ?>',
        'rating_star_2': '<?php echo __('rating_star_2'); ?>',
        'rating_star_3': '<?php echo __('rating_star_2'); ?>',
        'rating_star_4': '<?php echo __('rating_star_2'); ?>',
        'rating_star_5': '<?php echo __('rating_star_5'); ?>',
        'rating_your_value': '<?php echo __('rating_your_value'); ?>',
        
        // Избранное
        'favorites_add': '<?php echo __('favorites_add'); ?>',
        'favorites_remove': '<?php echo __('favorites_remove'); ?>',
        'favorites_added': '<?php echo __('favorites_added'); ?>',
        'favorites_removed': '<?php echo __('favorites_removed'); ?>',
        'favorites_error_remove': '<?php echo __('favorites_error_remove'); ?>',
        
        // Подтверждения
        'confirm_delete': '<?php echo __('confirm_delete'); ?>'
    };
    console.log('CSRF Token set in header');
    console.log('Current language:', window.CURRENT_LANG);
    console.log('Translations loaded:', Object.keys(window.TRANSLATIONS).length);
</script>


    <!-- Основная JS библиотека -->
    <script src="<?php echo $basePath; ?>/js/library.js?v=<?php echo time(); ?>"></script>

    <style>
        .book-cover { max-width: 100px; height: auto; }
        .book-card { margin-bottom: 20px; }
        .search-form { margin-bottom: 30px; }
        .stats { font-size: 0.85rem; }
        
        .rating-star {
            cursor: pointer;
            transition: transform 0.2s;
            background: none;
            border: none;
            font-size: 1.5rem;
        }
        .rating-star:hover {
            transform: scale(1.0);
        }
        .fa-spinner {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

/* Уменьшить звёзды в блоке рейтинга */
.rating-star i,
#average-stars i,
#user-rating-stars i {
    font-size: 1.2rem !important;  /* вместо стандартных 2rem */
}

/* Или конкретно для разных блоков */
#average-stars i {
    font-size: 2rem;  /* средний рейтинг - крупнее */
}

#user-rating-stars i {
    font-size: 1.5rem;  /* звёзды для оценки - поменьше */
}
        
        /* Стили для переключателя языка */
        .language-switcher {
            margin-left: 15px;
        }
        .language-switcher .dropdown-menu {
            min-width: 120px;
        }
        .language-switcher .dropdown-item {
            cursor: pointer;
            padding: 8px 15px;
        }
        .language-switcher .dropdown-item:hover {
            background-color: #f8f9fa;
        }
        .language-switcher .dropdown-item.active {
            background-color: #007bff;
            color: white;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="<?php echo $basePath; ?>/index.php">
		<?php echo htmlspecialchars(Config::getSiteTitle()); ?>



            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/index.php">
                            <?php echo __('home'); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/stats.php">
                            <?php echo __('stats'); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/favorites.php">
                            <?php echo __('favorites'); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/top_rated.php">
                            <?php echo __('top_rated'); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $isAdmin ? 'active' : ''; ?>" 
                           href="<?php echo $basePath; ?>/admin/index.php">
                            <?php echo __('admin'); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/api/opds.php" target="_blank">
                            OPDS
                        </a>
                    </li>
                </ul>
                
                <!-- Language switcher -->
                <?php if (count($availableLangs) > 1) { ?>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown language-switcher">
                        <a class="nav-link dropdown-toggle" href="#" id="languageDropdown" 
                           role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php echo $langFlag.' '.$langName; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="languageDropdown">
                            <?php foreach ($availableLangs as $lang) {
                                $langFlag = $detector->getLanguageFlag($lang);
                                $langName = $detector->getLanguageName($lang);
                                ?>
                            <li>
                                <a class="dropdown-item <?php echo $lang === $currentLang ? 'active' : ''; ?>" 
                                   href="#" 
                                   onclick="event.preventDefault(); changeLanguage('<?php echo $lang; ?>');">
                                    <?php echo $langFlag.' '.$langName; ?>
                                </a>
                            </li>
                            <?php } ?>
                        </ul>
                    </li>
                </ul>
                <?php } ?>
            </div>
        </div>
    </nav>

    <!-- Индикатор режима чтения (будет показан только в reader.php) -->
    <?php if (isset($inReader) && $inReader) { ?>
    <style>
    .reader-mode-indicator {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 8px 0;
        font-size: 0.9rem;
        text-align: center;
        position: relative;
        z-index: 1040;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .reader-mode-indicator i {
        margin-right: 8px;
        animation: pulse 2s infinite;
    }
    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.6; }
        100% { opacity: 1; }
    }
    </style>
    <div class="reader-mode-indicator">
        <i class="fas fa-book-open"></i>
        <?php echo __('reader_mode'); ?>
    </div>
    <?php } ?>
    
    <div class="container mt-4">

<script>
// Функция для смены языка
function changeLanguage(lang) {
    // Создаем форму и отправляем
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?php echo $basePath; ?>/change-language.php';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'lang';
    input.value = lang;
    
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

// Функция для обработки ошибок загрузки обложек
function handleCoverError(img, height = 400) {
    // Предотвращаем бесконечный цикл
    if (img.getAttribute('data-error-handled') === 'true') {
        return;
    }
    img.setAttribute('data-error-handled', 'true');
    
    img.style.display = 'none';
    const parent = img.parentNode;
    
    // Ищем или создаем placeholder
    let placeholder = parent.querySelector('.cover-placeholder');
    if (!placeholder) {
        placeholder = document.createElement('div');
        placeholder.className = 'bg-light d-flex align-items-center justify-content-center rounded cover-placeholder';
        placeholder.style.cssText = `width:100%; height:${height}px;`;
        
        if (height >= 300) {
            placeholder.innerHTML = `
                <div class="text-center">
                    <i class="fas fa-book text-muted mb-3" style="font-size: 4rem;"></i>
                    <p class="text-muted mb-0">${window.TRANSLATIONS?.['book_no_cover'] || 'Нет обложки'}</p>
                </div>
            `;
        } else {
            placeholder.innerHTML = `<small class="text-muted">${window.TRANSLATIONS?.['book_no_cover'] || 'Нет обложки'}</small>`;
        }
        
        parent.appendChild(placeholder);
    }
    
    placeholder.style.display = 'flex';
}

</script>
