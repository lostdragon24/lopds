<?php
// templates/header.php

require_once __DIR__ . '/../csp.php';

// Получаем базовый путь без учета админки
$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = rtrim(dirname(dirname($scriptPath)), '/');

// Если мы не в админке, используем обычный путь
if (strpos($scriptPath, '/admin/') === false) {
    $basePath = rtrim(dirname($scriptPath), '/');
}

$csrfToken = Config::startSecureSession();

// Определяем, находимся ли мы в админке
$isAdmin = strpos($scriptPath, '/admin/') !== false;

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
    <link rel="shortcut icon" href="<?php echo $basePath; ?>/favicon.ico" type="image/x-icon">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/all.min.css">
    <script src="<?php echo $basePath; ?>/css/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo $basePath; ?>/js/api-client.js?v=<?php echo time(); ?>"></script>


<!-- Тёмная тема -->
<link rel="stylesheet" href="<?php echo $basePath; ?>/css/dark-theme.css">

    <!-- Глобальные переменные JavaScript -->
    <script>
    window.CSRF_TOKEN = '<?php echo $csrfToken; ?>';
    window.API_URL = '<?php echo $basePath; ?>/api/rating.php';
    window.BASE_PATH = '<?php echo $basePath; ?>';
    window.CURRENT_LANG = '<?php echo $currentLang; ?>';
    window.TRANSLATIONS = {
        'error': '<?php echo __('error'); ?>',
        'success': '<?php echo __('success'); ?>',
        'warning': '<?php echo __('warning'); ?>',
        'info': '<?php echo __('info'); ?>',
        'close': '<?php echo __('close'); ?>',
        'error_occurred': '<?php echo __('error_occurred'); ?>',
        'error_unknown': '<?php echo __('error_unknown'); ?>',
        'error_csrf': '<?php echo __('error_csrf'); ?>',
        'error_invalid_id': '<?php echo __('error_invalid_id'); ?>',
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
        'favorites_add': '<?php echo __('favorites_add'); ?>',
        'favorites_remove': '<?php echo __('favorites_remove'); ?>',
        'favorites_added': '<?php echo __('favorites_added'); ?>',
        'favorites_removed': '<?php echo __('favorites_removed'); ?>',
        'favorites_error_remove': '<?php echo __('favorites_error_remove'); ?>',
        'confirm_delete': '<?php echo __('confirm_delete'); ?>'
    };
    </script>

    <!-- Основная JS библиотека -->
    <script src="<?php echo $basePath; ?>/js/library.js?v=<?php echo time(); ?>"></script>

    <!-- ============================================
         СТИЛИ ДЛЯ ТЁМНОЙ ТЕМЫ (все в одном месте)
         ============================================ -->
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

        
        /* ============================================
           ПЕРЕКЛЮЧАТЕЛЬ ТЕМЫ
           ============================================ */
        .theme-toggle-nav {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.3);
            color: #fff;
            padding: 6px 12px;
            border-radius: 30px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-left: 8px;
        }

        .theme-toggle-nav:hover {
            background: rgba(255,255,255,0.1);
            transform: scale(1.05);
        }

        .theme-toggle-nav i {
            font-size: 1rem;
        }

        /* Тёмная тема для навбара */
        body.dark-theme .theme-toggle-nav {
            border-color: #404040;
            color: #e0e0e0;
        }

        body.dark-theme .theme-toggle-nav:hover {
            background: #404040;
        }
        
        
        
        
/* ============================================
   ПЕРЕКЛЮЧЕНИЕ ВИДА ПЛИТКА/СПИСОК
   ============================================ */

/* По умолчанию - плитка */
/* По умолчанию - 4 карточки в строке */
#booksGrid .book-item {
    flex: 0 0 20%;
    max-width: 20%;
}

/* Планшеты - 3 карточки */
@media (max-width: 992px) {
    #booksGrid .book-item {
        flex: 0 0 33.333%;
        max-width: 33.333%;
    }
}

/* Телефоны - 2 карточки */
@media (max-width: 768px) {
    #booksGrid .book-item {
        flex: 0 0 50%;
        max-width: 50%;
    }
}

/* Маленькие телефоны - 1 карточка */
@media (max-width: 480px) {
    #booksGrid .book-item {
        flex: 0 0 100%;
        max-width: 100%;
    }
}



/* Вид "Список" */
#booksGrid.list-view .book-item {
    flex: 0 0 100%;
    max-width: 100%;
}

#booksGrid.list-view .book-card {
    flex-direction: row !important;
    min-height: 140px;
}

#booksGrid.list-view .book-cover-wrapper {
    width: 120px;
    flex-shrink: 0;
}

#booksGrid.list-view .book-cover-img,
#booksGrid.list-view .book-cover-placeholder {
    height: 100% !important;
    min-height: 140px;
    width: 120px;
    object-fit: cover;
}

#booksGrid.list-view .cover-actions {
    display: none !important;
}

#booksGrid.list-view .format-badge,
#booksGrid.list-view .archive-badge {
    display: none;
}

#booksGrid.list-view .card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

#booksGrid.list-view .card-body .card-title {
    font-size: 1.1rem;
}

#booksGrid.list-view .card-body .card-text {
    font-size: 0.95rem;
}


/* ============================================
   ВИД "СПИСОК" - КОМПАКТНЫЙ И ЭЛЕГАНТНЫЙ
   ============================================ */

#booksGrid.list-view .book-item {
    flex: 0 0 100% !important;
    max-width: 100% !important;
    margin-bottom: 8px;
}

#booksGrid.list-view .book-card {
    flex-direction: row !important;
    align-items: stretch;
    min-height: 80px;
    max-height: 120px;
    border-radius: 8px;
    overflow: hidden;
    transition: all 0.2s ease;
}

#booksGrid.list-view .book-card:hover {
    background: #f8f9fa;
    transform: translateX(4px);
    box-shadow: 0 2px 12px rgba(0,0,0,0.08) !important;
}

/* Обложка в списке - компактная */
#booksGrid.list-view .book-cover-wrapper {
    width: 70px !important;
    height: 100%;
    flex-shrink: 0;
    min-height: 80px;
    max-height: 120px;
    overflow: hidden;
}

#booksGrid.list-view .book-cover-img {
    width: 70px !important;
    height: 100% !important;
    min-height: 80px;
    max-height: 120px;
    object-fit: cover !important;
}

#booksGrid.list-view .book-cover-placeholder {
    width: 70px !important;
    height: 100% !important;
    min-height: 80px;
    max-height: 120px;
    display: flex !important;
    align-items: center;
    justify-content: center;
    background: #f0f0f0;
}

#booksGrid.list-view .book-cover-placeholder i {
    font-size: 1.5rem;
    color: #adb5bd;
}

#booksGrid.list-view .book-cover-placeholder .small {
    display: none;
}

/* Скрываем кнопки на обложке в списке */
#booksGrid.list-view .cover-actions {
    display: none !important;
}

#booksGrid.list-view .format-badge,
#booksGrid.list-view .archive-badge {
    display: none !important;
}

/* Контент карточки в списке */
#booksGrid.list-view .card-body {
    flex: 1;
    padding: 8px 14px !important;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 80px;
}

#booksGrid.list-view .card-body .card-title {
    font-size: 0.95rem;
    margin-bottom: 1px;
    font-weight: 600;
}

#booksGrid.list-view .card-body .card-title a {
    color: #2c3e50;
    text-decoration: none;
}

#booksGrid.list-view .card-body .card-title a:hover {
    color: #007bff;
}

#booksGrid.list-view .card-body .card-text {
    font-size: 0.8rem;
    margin-bottom: 2px;
    color: #6c757d;
}

#booksGrid.list-view .card-body .card-text a {
    color: #6c757d;
    text-decoration: none;
}

#booksGrid.list-view .card-body .card-text a:hover {
    color: #007bff;
    text-decoration: underline;
}

#booksGrid.list-view .book-rating-mini {
    font-size: 0.75rem;
    margin-top: 2px;
}

#booksGrid.list-view .book-rating-mini i {
    font-size: 0.7em !important;
}

/* Дополнительная информация в списке */
#booksGrid.list-view .list-meta {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.7rem;
    color: #adb5bd;
    margin-top: 4px;
}

#booksGrid.list-view .list-meta .badge {
    font-size: 0.6rem;
    padding: 1px 6px;
}

#booksGrid.list-view .list-actions {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-left: auto;
}

#booksGrid.list-view .list-actions .btn {
    padding: 2px 8px;
    font-size: 0.7rem;
    border-radius: 4px;
}

/* Темная тема для списка */
body.dark-theme #booksGrid.list-view .book-card {
    background: #2d2d2d;
    border-color: #404040;
}

body.dark-theme #booksGrid.list-view .book-card:hover {
    background: #3a3a3a;
}

body.dark-theme #booksGrid.list-view .card-body .card-title a {
    color: #e0e0e0;
}

body.dark-theme #booksGrid.list-view .card-body .card-title a:hover {
    color: #66b0ff;
}

body.dark-theme #booksGrid.list-view .card-body .card-text {
    color: #b0b0b0;
}

body.dark-theme #booksGrid.list-view .card-body .card-text a {
    color: #b0b0b0;
}

body.dark-theme #booksGrid.list-view .card-body .card-text a:hover {
    color: #66b0ff;
}

body.dark-theme #booksGrid.list-view .book-cover-placeholder {
    background: #3d3d3d;
}

body.dark-theme #booksGrid.list-view .list-meta {
    color: #6c757d;
}


/* ============================================
   ВИД "СПИСОК" - С КНОПКАМИ ДЕЙСТВИЙ
   ============================================ */

#booksGrid.list-view .book-item {
    flex: 0 0 100% !important;
    max-width: 100% !important;
    margin-bottom: 6px;
}

#booksGrid.list-view .book-card {
    flex-direction: row !important;
    align-items: stretch;
    min-height: 70px;
    max-height: 90px;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #e9ecef;
    transition: all 0.2s ease;
    background: #fff;
}

#booksGrid.list-view .book-card:hover {
    background: #f8f9fa;
    border-color: #dee2e6;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06) !important;
}

/* Обложка в списке - миниатюра */
#booksGrid.list-view .book-cover-wrapper {
    width: 65px !important;
    min-height: 70px;
    max-height: 90px;
    flex-shrink: 0;
    overflow: hidden;
}

#booksGrid.list-view .book-cover-img {
    width: 65px !important;
    height: 100% !important;
    min-height: 70px;
    max-height: 90px;
    object-fit: cover !important;
}

#booksGrid.list-view .book-cover-placeholder {
    width: 65px !important;
    height: 100% !important;
    min-height: 70px;
    max-height: 90px;
    display: flex !important;
    align-items: center;
    justify-content: center;
    background: #f0f0f0;
}

#booksGrid.list-view .book-cover-placeholder i {
    font-size: 1.2rem;
    color: #adb5bd;
}

#booksGrid.list-view .book-cover-placeholder .small {
    display: none;
}

/* Скрываем старые кнопки на обложке */
#booksGrid.list-view .cover-actions {
    display: none !important;
}

#booksGrid.list-view .format-badge,
#booksGrid.list-view .archive-badge {
    display: none !important;
}

/* Контент карточки в списке */
#booksGrid.list-view .card-body {
    flex: 1;
    padding: 6px 12px !important;
    display: flex;
    flex-direction: row;
    align-items: center;
    min-height: 70px;
    max-height: 90px;
    gap: 10px;
}

#booksGrid.list-view .card-body .book-info {
    flex: 1;
    min-width: 0;
}

#booksGrid.list-view .card-body .card-title {
    font-size: 0.9rem;
    margin-bottom: 1px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

#booksGrid.list-view .card-body .card-title a {
    color: #2c3e50;
    text-decoration: none;
}

#booksGrid.list-view .card-body .card-title a:hover {
    color: #007bff;
}

#booksGrid.list-view .card-body .card-text {
    font-size: 0.75rem;
    margin-bottom: 1px;
    color: #6c757d;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

#booksGrid.list-view .card-body .card-text a {
    color: #6c757d;
    text-decoration: none;
}

#booksGrid.list-view .card-body .card-text a:hover {
    color: #007bff;
    text-decoration: underline;
}

#booksGrid.list-view .book-rating-mini {
    font-size: 0.65rem;
    margin-top: 1px;
}

#booksGrid.list-view .book-rating-mini i {
    font-size: 0.6em !important;
}

/* КНОПКИ ДЕЙСТВИЙ В СПИСКЕ */
#booksGrid.list-view .list-actions {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
    padding-left: 8px;
    border-left: 1px solid #e9ecef;
}

#booksGrid.list-view .list-actions .btn {
    padding: 4px 8px;
    font-size: 0.7rem;
    border-radius: 6px;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}

#booksGrid.list-view .list-actions .btn:hover {
    transform: scale(1.05);
}

#booksGrid.list-view .list-actions .btn-download {
    color: #28a745;
    border-color: #28a745;
}

#booksGrid.list-view .list-actions .btn-download:hover {
    background: #28a745;
    color: #fff;
}

#booksGrid.list-view .list-actions .btn-read {
    color: #007bff;
    border-color: #007bff;
}

#booksGrid.list-view .list-actions .btn-read:hover {
    background: #007bff;
    color: #fff;
}

#booksGrid.list-view .list-actions .btn-favorite {
    color: #dc3545;
    border-color: #dc3545;
}

#booksGrid.list-view .list-actions .btn-favorite:hover {
    background: #dc3545;
    color: #fff;
}

#booksGrid.list-view .list-actions .btn-favorite.active {
    background: #dc3545;
    color: #fff;
}

/* Мета-информация в списке */
#booksGrid.list-view .list-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.65rem;
    color: #adb5bd;
    flex-shrink: 0;
}

#booksGrid.list-view .list-meta .badge {
    font-size: 0.55rem;
    padding: 1px 6px;
}

/* Темная тема для списка */
body.dark-theme #booksGrid.list-view .book-card {
    background: #2d2d2d;
    border-color: #404040;
}

body.dark-theme #booksGrid.list-view .book-card:hover {
    background: #3a3a3a;
}

body.dark-theme #booksGrid.list-view .card-body .card-title a {
    color: #e0e0e0;
}

body.dark-theme #booksGrid.list-view .card-body .card-title a:hover {
    color: #66b0ff;
}

body.dark-theme #booksGrid.list-view .card-body .card-text {
    color: #b0b0b0;
}

body.dark-theme #booksGrid.list-view .card-body .card-text a {
    color: #b0b0b0;
}

body.dark-theme #booksGrid.list-view .card-body .card-text a:hover {
    color: #66b0ff;
}

body.dark-theme #booksGrid.list-view .book-cover-placeholder {
    background: #3d3d3d;
}

body.dark-theme #booksGrid.list-view .list-meta {
    color: #6c757d;
}

body.dark-theme #booksGrid.list-view .list-actions {
    border-left-color: #404040;
}

/* Адаптивность для списка */
@media (max-width: 768px) {
    #booksGrid.list-view .book-card {
        max-height: 80px;
        min-height: 65px;
    }
    
    #booksGrid.list-view .book-cover-wrapper {
        width: 55px !important;
        min-height: 65px;
        max-height: 80px;
    }
    
    #booksGrid.list-view .book-cover-img {
        width: 55px !important;
        min-height: 65px;
        max-height: 80px;
    }
    
    #booksGrid.list-view .book-cover-placeholder {
        width: 55px !important;
        min-height: 65px;
        max-height: 80px;
    }
    
    #booksGrid.list-view .card-body {
        padding: 4px 10px !important;
        min-height: 65px;
        max-height: 80px;
        gap: 6px;
    }
    
    #booksGrid.list-view .card-body .card-title {
        font-size: 0.8rem;
    }
    
    #booksGrid.list-view .card-body .card-text {
        font-size: 0.65rem;
    }
    
    #booksGrid.list-view .list-meta {
        display: none;
    }
    
    #booksGrid.list-view .list-actions .btn {
        padding: 2px 6px;
        font-size: 0.6rem;
    }
}

@media (max-width: 576px) {
    #booksGrid.list-view .book-card {
        max-height: 70px;
        min-height: 55px;
    }
    
    #booksGrid.list-view .book-cover-wrapper {
        width: 45px !important;
        min-height: 55px;
        max-height: 70px;
    }
    
    #booksGrid.list-view .book-cover-img {
        width: 45px !important;
        min-height: 55px;
        max-height: 70px;
    }
    
    #booksGrid.list-view .book-cover-placeholder {
        width: 45px !important;
        min-height: 55px;
        max-height: 70px;
    }
    
    #booksGrid.list-view .book-cover-placeholder i {
        font-size: 0.9rem;
    }
    
    #booksGrid.list-view .card-body {
        padding: 3px 8px !important;
        min-height: 55px;
        max-height: 70px;
        gap: 4px;
    }
    
    #booksGrid.list-view .card-body .card-title {
        font-size: 0.7rem;
    }
    
    #booksGrid.list-view .card-body .card-text {
        font-size: 0.6rem;
    }
    
    #booksGrid.list-view .book-rating-mini {
        font-size: 0.55rem;
    }
    
    #booksGrid.list-view .list-actions .btn {
        padding: 1px 4px;
        font-size: 0.55rem;
    }
    
    #booksGrid.list-view .list-actions {
        padding-left: 4px;
        gap: 2px;
    }
}



/* Анимация для новых карточек */
.book-item.loading {
    opacity: 0;
    transform: scale(0.95);
}

.book-item.loaded {
    animation: fadeInUp 0.4s ease forwards;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Индикатор загрузки */
#loadingIndicator .spinner-border {
    width: 40px;
    height: 40px;
}

#loadMoreBtn {
    transition: all 0.3s ease;
}

#loadMoreBtn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

#endMessage {
    opacity: 0.7;
    font-size: 0.9rem;
}


/* ============================================
   ПЕРЕКЛЮЧАТЕЛЬ ТЕМЫ
   ============================================ */

/* Вариант 1: Кнопка в hero-секции */
.theme-switcher-wrapper {
    position: absolute;
    top: 20px;
    right: 20px;
    z-index: 10;
}

.theme-toggle-btn {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #fff;
    padding: 8px 16px;
    border-radius: 30px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.theme-toggle-btn:hover {
    background: rgba(255, 255, 255, 0.25);
    transform: scale(1.05);
}

.theme-toggle-btn i {
    font-size: 1rem;
}

/* Вариант 2: В навбаре */
.theme-toggle-nav {
    background: transparent;
    border: 1px solid #dee2e6;
    color: #495057;
    padding: 6px 12px;
    border-radius: 30px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.theme-toggle-nav:hover {
    background: #e9ecef;
    transform: scale(1.05);
}

.theme-toggle-nav i {
    font-size: 1rem;
}

/* Тёмная тема для навбара */
body.dark-theme .theme-toggle-nav {
    border-color: #404040;
    color: #e0e0e0;
}

body.dark-theme .theme-toggle-nav:hover {
    background: #404040;
}

/* Адаптивность */
@media (max-width: 768px) {
    .theme-switcher-wrapper {
        top: 10px;
        right: 10px;
    }
    
    .theme-toggle-btn {
        padding: 6px 12px;
        font-size: 0.8rem;
    }
    
    .theme-toggle-btn span {
        display: none;
    }
}

/* ============================================
   ТЁМНАЯ ТЕМА - ГЛОБАЛЬНЫЕ СТИЛИ
   ============================================ */

body.dark-theme {
    background: #1a1a1a;
    color: #e0e0e0;
}

body.dark-theme .navbar {
    background: #2d2d2d !important;
    border-bottom: 1px solid #404040;
}

body.dark-theme .navbar .nav-link {
    color: #b0b0b0;
}

body.dark-theme .navbar .nav-link:hover {
    color: #fff;
}

body.dark-theme .card {
    background: #2d2d2d;
    border-color: #404040;
}

body.dark-theme .card-header {
    background: #3d3d3d;
    border-bottom-color: #404040;
}

body.dark-theme .card-footer {
    background: #3d3d3d;
    border-top-color: #404040;
}

body.dark-theme .table {
    color: #e0e0e0;
}

body.dark-theme .table-light {
    background: #3d3d3d;
    color: #e0e0e0;
}

body.dark-theme .table-hover tbody tr:hover {
    background: #3d3d3d;
}

body.dark-theme .form-control,
body.dark-theme .form-select {
    background: #3d3d3d;
    color: #e0e0e0;
    border-color: #404040;
}

body.dark-theme .form-control:focus,
body.dark-theme .form-select:focus {
    background: #3d3d3d;
    color: #e0e0e0;
    border-color: #66b0ff;
}

body.dark-theme .alert-info {
    background: #2d3d4d;
    color: #b0d0e0;
    border-color: #1a2a3a;
}

body.dark-theme .alert-warning {
    background: #4d3d2d;
    color: #e0d0b0;
    border-color: #3a2a1a;
}

body.dark-theme .alert-success {
    background: #2d4d3d;
    color: #b0e0d0;
    border-color: #1a3a2a;
}

body.dark-theme .alert-danger {
    background: #4d2d2d;
    color: #e0b0b0;
    border-color: #3a1a1a;
}

body.dark-theme .pagination .page-link {
    background: #3d3d3d;
    color: #b0b0b0;
    border-color: #404040;
}

body.dark-theme .pagination .page-link:hover {
    background: #4d4d4d;
    color: #fff;
}

body.dark-theme .pagination .page-item.active .page-link {
    background: #007bff;
    border-color: #007bff;
    color: #fff;
}

body.dark-theme .list-group-item {
    background: #2d2d2d;
    color: #e0e0e0;
    border-color: #404040;
}

body.dark-theme .list-group-item:hover {
    background: #3d3d3d;
}

body.dark-theme .dropdown-menu {
    background: #2d2d2d;
    border-color: #404040;
}

body.dark-theme .dropdown-item {
    color: #e0e0e0;
}

body.dark-theme .dropdown-item:hover {
    background: #3d3d3d;
    color: #fff;
}

body.dark-theme .dropdown-divider {
    border-color: #404040;
}

body.dark-theme .bg-light {
    background: #2d2d2d !important;
}

body.dark-theme .text-muted {
    color: #b0b0b0 !important;
}

body.dark-theme .border {
    border-color: #404040 !important;
}

body.dark-theme .border-bottom {
    border-bottom-color: #404040 !important;
}

body.dark-theme .border-top {
    border-top-color: #404040 !important;
}

body.dark-theme .border-start {
    border-left-color: #404040 !important;
}

body.dark-theme .border-end {
    border-right-color: #404040 !important;
}

/* Кнопки в тёмной теме */
body.dark-theme .btn-outline-secondary {
    color: #b0b0b0;
    border-color: #404040;
}

body.dark-theme .btn-outline-secondary:hover {
    background: #404040;
    color: #fff;
}

body.dark-theme .btn-outline-primary {
    color: #66b0ff;
    border-color: #66b0ff;
}

body.dark-theme .btn-outline-primary:hover {
    background: #66b0ff;
    color: #1a1a1a;
}

body.dark-theme .btn-outline-success {
    color: #5cb85c;
    border-color: #5cb85c;
}

body.dark-theme .btn-outline-success:hover {
    background: #5cb85c;
    color: #1a1a1a;
}

body.dark-theme .btn-outline-danger {
    color: #d9534f;
    border-color: #d9534f;
}

body.dark-theme .btn-outline-danger:hover {
    background: #d9534f;
    color: #1a1a1a;
}

/* Стили для скроллбара в тёмной теме */
body.dark-theme ::-webkit-scrollbar {
    width: 10px;
    height: 10px;
}

body.dark-theme ::-webkit-scrollbar-track {
    background: #2d2d2d;
}

body.dark-theme ::-webkit-scrollbar-thumb {
    background: #555;
    border-radius: 5px;
}

body.dark-theme ::-webkit-scrollbar-thumb:hover {
    background: #777;
}



        
        
        
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="<?php echo $basePath; ?>/">
                <?php echo htmlspecialchars(Config::getSiteTitle()); ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/"><?php echo __('home'); ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/stats.php"><?php echo __('stats'); ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/favorites.php"><?php echo __('favorites'); ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/top_rated.php"><?php echo __('top_rated'); ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/bookmarks.php"><?php echo __('book_marks'); ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $isAdmin ? 'active' : ''; ?>" 
                           href="<?php echo $basePath; ?>/admin/index.php"><?php echo __('admin'); ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>/api/opds.php" target="_blank">OPDS</a>
                    </li>
                </ul>
                
                <!-- Language switcher -->
                <?php if (count($availableLangs) > 1): ?>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown language-switcher">
                        <a class="nav-link dropdown-toggle" href="#" id="languageDropdown" 
                           role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php echo $langFlag . ' ' . $langName; ?>
                        </a>
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
                    </li>
                </ul>
                <?php endif; ?>

                <!-- ПЕРЕКЛЮЧАТЕЛЬ ТЕМЫ -->
                <button class="btn theme-toggle-nav" id="themeToggleNav" title=<?= __('switch_theme') ?>>
                    <i class="fas fa-moon" id="themeIconNav"></i>
                </button>
            </div>
        </div>
    </nav>

    <!-- Индикатор режима чтения -->
    <?php if (isset($inReader) && $inReader): ?>
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
    <?php endif; ?>
    
    <div class="container mt-4">

<!-- ============================================
     JAVASCRIPT ДЛЯ ПЕРЕКЛЮЧАТЕЛЯ ТЕМЫ
     ============================================ -->
<script>
// ============================================
// ПЕРЕКЛЮЧАТЕЛЬ ТЁМНОЙ/СВЕТЛОЙ ТЕМЫ - ИСПРАВЛЕННЫЙ
// ============================================
(function() {
    // Ждём загрузки DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initThemeToggle);
    } else {
        initThemeToggle();
    }

    function initThemeToggle() {
        const toggleBtn = document.getElementById('themeToggleNav');
        const icon = document.getElementById('themeIconNav');
        
        if (!toggleBtn) {
            console.warn('Theme toggle button not found');
            return;
        }

        // Загружаем сохранённую тему
        const savedTheme = localStorage.getItem('theme') || 'light';
        
        // Функция применения темы
        function setTheme(theme) {
            if (theme === 'dark') {
                document.body.classList.add('dark-theme');
                if (icon) {
                    icon.className = 'fas fa-sun';
                }
                // Обновляем стиль кнопки
                toggleBtn.style.borderColor = '#ffc107';
                toggleBtn.style.color = '#ffc107';
            } else {
                document.body.classList.remove('dark-theme');
                if (icon) {
                    icon.className = 'fas fa-moon';
                }
                toggleBtn.style.borderColor = 'rgba(255,255,255,0.3)';
                toggleBtn.style.color = '#fff';
            }
            localStorage.setItem('theme', theme);
            
            // Отправляем событие для других компонентов
            document.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme: theme } }));
        }

        // Применяем сохранённую тему
        setTheme(savedTheme);

        // Обработчик клика
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const currentTheme = document.body.classList.contains('dark-theme') ? 'dark' : 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            setTheme(newTheme);
        });

        // Следим за изменениями темы из других мест
        document.addEventListener('themeChanged', function(e) {
            // Синхронизируем иконку если тема изменилась извне
            const theme = e.detail.theme;
            if (theme === 'dark') {
                if (icon) icon.className = 'fas fa-sun';
                toggleBtn.style.borderColor = '#ffc107';
                toggleBtn.style.color = '#ffc107';
            } else {
                if (icon) icon.className = 'fas fa-moon';
                toggleBtn.style.borderColor = 'rgba(255,255,255,0.3)';
                toggleBtn.style.color = '#fff';
            }
        });

        console.log('Theme toggle initialized, current theme:', savedTheme);
    }
})();

// Функция для смены языка
function changeLanguage(lang) {
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
    if (img.getAttribute('data-error-handled') === 'true') return;
    img.setAttribute('data-error-handled', 'true');
    img.style.display = 'none';
    const parent = img.parentNode;
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

<!-- ============================================
     FINGERPRINT
     ============================================ -->
<script>
(async function() {
    // Функция получения fingerprint
    async function getFingerprint() {
        // Убираем проверку на Fingerprint, так как она вызывает ошибку
        // if (Fingerprint) return Fingerprint;
        
        try {
            // Собираем стабильные данные
            const data = {
                screen: screen.width + 'x' + screen.height + 'x' + screen.colorDepth,
                language: navigator.language,
                platform: navigator.platform,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                cpuCores: navigator.hardwareConcurrency || 0,
                memory: navigator.deviceMemory || 0,
                touchPoints: navigator.maxTouchPoints || 0,
                audio: await withTimeout(getAudioFingerprint(), 2000, 'audio_timeout'),
                canvas: await withTimeout(getCanvasFingerprint(), 1000, 'canvas_timeout'),
                webgl: await withTimeout(getWebGLFingerprint(), 1000, null),
            };
            
            const str = JSON.stringify(data);
            const hash = await cryptoHash(str);
            return 'fp_' + hash.slice(0, 32);
        } catch (error) {
            console.warn('Fingerprint generation failed, using fallback:', error);
            // Fallback - используем случайный ID
            return 'fp_fallback_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        }
    }
    
    function withTimeout(promise, ms, fallback) {
        return Promise.race([
            promise,
            new Promise(resolve => setTimeout(() => resolve(fallback), ms))
        ]).catch(() => fallback);
    }
    
    async function cryptoHash(str) {
        try {
            const encoder = new TextEncoder();
            const data = encoder.encode(str);
            const hashBuffer = await window.crypto.subtle.digest('SHA-256', data);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        } catch (error) {
            console.warn('Crypto hash failed, using simple hash:', error);
            // Простой fallback для старых браузеров
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                const char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash;
            }
            return Math.abs(hash).toString(16);
        }
    }
    
    async function getCanvasFingerprint() {
        try {
            const canvas = document.createElement('canvas');
            canvas.width = 200;
            canvas.height = 50;
            const ctx = canvas.getContext('2d');
            
            ctx.font = '18pt Arial';
            ctx.textBaseline = 'top';
            ctx.fillStyle = '#f60';
            ctx.fillRect(0, 0, 200, 50);
            ctx.fillStyle = '#069';
            ctx.fillText('FingerprintTest', 2, 35);
            ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
            ctx.fillText('CanvasTest', 4, 45);
            
            return canvas.toDataURL().slice(-64);
        } catch (e) {
            console.warn('Canvas fingerprint failed:', e);
            return 'canvas_error';
        }
    }
    
    async function getWebGLFingerprint() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            if (!gl) return null;
            
            const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            if (debugInfo) {
                return {
                    vendor: gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL),
                    renderer: gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL)
                };
            }
            return null;
        } catch (e) {
            console.warn('WebGL fingerprint failed:', e);
            return null;
        }
    }
    
    async function getAudioFingerprint() {
        try {
            const AudioContext = window.OfflineAudioContext || window.webkitOfflineAudioContext;
            if (!AudioContext) return "not_supported";
            
            const context = new AudioContext(1, 44100, 44100);
            const oscillator = context.createOscillator();
            oscillator.type = "triangle";
            oscillator.frequency.setValueAtTime(10000, context.currentTime);
            
            const compressor = context.createDynamicsCompressor();
            compressor.threshold.setValueAtTime(-50, context.currentTime);
            compressor.knee.setValueAtTime(40, context.currentTime);
            compressor.ratio.setValueAtTime(12, context.currentTime);
            compressor.attack.setValueAtTime(0, context.currentTime);
            compressor.release.setValueAtTime(0.25, context.currentTime);
            
            oscillator.connect(compressor);
            compressor.connect(context.destination);
            oscillator.start(0);
            
            const renderedBuffer = await context.startRendering();
            const audioData = renderedBuffer.getChannelData(0);
            
            let hash = 0;
            for (let i = 4000; i < 4500; i++) {
                hash += Math.abs(audioData[i]);
            }
            return hash.toString();
        } catch (e) {
            console.warn('Audio fingerprint failed:', e);
            return "audio_error";
        }
    }
    
    // Получаем или создаём fingerprint
    let fingerprint = localStorage.getItem('device_fingerprint');
    
    if (!fingerprint) {
        console.log('Generating new device fingerprint...');
        fingerprint = await getFingerprint();
        localStorage.setItem('device_fingerprint', fingerprint);
        console.log('New device fingerprint created:', fingerprint);
    } else {
        console.log('Existing device fingerprint:', fingerprint);
    }
    
    // Сохраняем в куку для PHP
    document.cookie = 'device_fp=' + fingerprint + '; path=/; max-age=' + (365 * 24 * 3600 * 10);
    
    // Сохраняем в глобальную переменную для доступа из других скриптов
    window.DEVICE_FINGERPRINT = fingerprint;
    
    console.log('Fingerprint set in cookie:', document.cookie.match(/device_fp=([^;]+)/)?.[1]);
})();

</script>
