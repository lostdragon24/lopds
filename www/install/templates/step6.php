<?php
// /install/templates/step6.php

// Получаем статистику для поздравления
$totalBooks = 0;
$totalAuthors = 0;

try {
    if (!class_exists('Database', false)) {
        require_once __DIR__ . '/../../lib/Database.php';
    }
    $db = Database::getInstance();
    if ($db->isAvailable()) {
        $totalBooks = $db->getTotalBooksCount();
        $stmt = $db->getConnection()->query("SELECT COUNT(DISTINCT author) FROM books WHERE author IS NOT NULL AND author != ''");
        $totalAuthors = $stmt->fetchColumn();
    }
} catch (Exception $e) {
    error_log("Error getting stats: " . $e->getMessage());
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
           "://" . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME']));

// Получаем информацию о языках для кнопок
$detector = LanguageDetector::getInstance();
$currentLang = $detector->getCurrentLanguage();
?>

<div class="text-center">
    <div class="success-animation mb-4">
        <i class="fas fa-check-circle fa-6x text-success"></i>
        <i class="fas fa-star position-absolute text-warning" style="font-size: 2rem; top: -10px; right: -10px; animation: spin 3s linear infinite;"></i>
        <i class="fas fa-star position-absolute text-primary" style="font-size: 1.5rem; bottom: -10px; left: -10px; animation: spin 2s linear infinite reverse;"></i>
    </div>
    
    <h1 class="display-4 mb-3">🎉 <?php echo __('install_step6_title'); ?></h1>
    <p class="lead text-muted mb-4">
        <?php echo __('install_step6_desc'); ?>
    </p>
    
    <!-- Статистика библиотеки -->
    <div class="row justify-content-center mb-5">
        <div class="col-md-3">
            <div class="card bg-primary text-white shadow-lg stat-card">
                <div class="card-body text-center py-4">
                    <i class="fas fa-book fa-3x mb-3"></i>
                    <h2 class="display-4"><?php echo number_format($totalBooks, 0, '', ' '); ?></h2>
                    <p class="mb-0"><?php echo __('install_stats_books'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white shadow-lg stat-card">
                <div class="card-body text-center py-4">
                    <i class="fas fa-user-edit fa-3x mb-3"></i>
                    <h2 class="display-4"><?php echo number_format($totalAuthors, 0, '', ' '); ?></h2>
                    <p class="mb-0"><?php echo __('install_stats_authors'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white shadow-lg stat-card">
                <div class="card-body text-center py-4">
                    <i class="fas fa-database fa-3x mb-3"></i>
                    <h2 class="display-4"><?php echo strtoupper(Config::getDbType()); ?></h2>
                    <p class="mb-0"><?php echo __('install_stats_db_type'); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Поздравление и советы -->
    <div class="row mb-5">
        <div class="col-12">
            <div class="card bg-light">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 text-center mb-3 mb-md-0">
                            <i class="fas fa-rocket text-primary fa-3x mb-3"></i>
                            <h5><?php echo __('install_step6_tip_start_title'); ?></h5>
                            <p class="small"><?php echo __('install_step6_tip_start_desc'); ?></p>
                        </div>
                        <div class="col-md-4 text-center mb-3 mb-md-0">
                            <i class="fas fa-cog text-warning fa-3x mb-3"></i>
                            <h5><?php echo __('install_step6_tip_configure_title'); ?></h5>
                            <p class="small"><?php echo __('install_step6_tip_configure_desc'); ?></p>
                        </div>
                        <div class="col-md-4 text-center">
                            <i class="fas fa-shield-alt text-danger fa-3x mb-3"></i>
                            <h5><?php echo __('install_step6_tip_secure_title'); ?></h5>
                            <p class="small"><?php echo __('install_step6_tip_secure_desc'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Кнопки действий -->
    <div class="row g-3 mb-5">
        <div class="col-md-4">
            <a href="<?php echo $baseUrl; ?>/index.php" class="btn btn-primary btn-lg w-100 py-3 action-btn">
                <i class="fas fa-home me-2"></i>
                <?php echo __('install_go_to_library'); ?>
            </a>
            <small class="text-muted d-block mt-2"><?php echo __('install_go_to_library_desc'); ?></small>
        </div>
        
        <div class="col-md-4">
            <a href="<?php echo $baseUrl; ?>/admin/index.php" class="btn btn-warning btn-lg w-100 py-3 action-btn">
                <i class="fas fa-cog me-2"></i>
                <?php echo __('install_go_to_admin'); ?>
            </a>
            <small class="text-muted d-block mt-2"><?php echo __('install_go_to_admin_desc'); ?></small>
        </div>
        
        <div class="col-md-4">
            <a href="<?php echo $baseUrl; ?>/stats.php" class="btn btn-info btn-lg w-100 py-3 action-btn">
                <i class="fas fa-chart-bar me-2"></i>
                <?php echo __('install_go_to_stats'); ?>
            </a>
            <small class="text-muted d-block mt-2"><?php echo __('install_go_to_stats_desc'); ?></small>
        </div>
    </div>
    
    <!-- Важное предупреждение безопасности -->
    <div class="alert alert-danger shadow-lg mb-4" role="alert">
        <div class="d-flex align-items-center">
            <div class="me-4">
                <i class="fas fa-shield-alt fa-3x"></i>
            </div>
            <div class="text-start">
                <h4 class="alert-heading mb-2">⚠️ <?php echo __('install_security_warning'); ?></h4>
                <p class="mb-0">
                    <?php echo sprintf(__('install_security_desc'), '<code>/install/</code>'); ?>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Инструкция по удалению -->
    <div class="card bg-light mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="bg-dark text-white p-3 rounded">
                        <code class="text-white"># <?php echo __('install_security_cli'); ?></code><br>
                        <code class="text-success">rm -rf <?php echo dirname(__DIR__); ?></code>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="bg-dark text-white p-3 rounded">
                        <code class="text-white"># <?php echo __('install_security_ftp'); ?></code><br>
                        <code class="text-warning"><?php echo __('install_security_ftp_desc'); ?></code>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Дополнительные ресурсы -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0"><i class="fas fa-link me-2"></i><?php echo __('install_step6_resources'); ?></h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="list-unstyled">
                                <li><i class="fas fa-book me-2 text-primary"></i> <a href="<?php echo $baseUrl; ?>/api/opds.php" target="_blank">OPDS Catalog</a> - <?php echo __('install_step6_resource_opds'); ?></li>
                                <li><i class="fas fa-question-circle me-2 text-info"></i> <a href="#" onclick="alert('<?php echo __('install_step6_resource_help_alert'); ?>')"><?php echo __('install_step6_resource_help'); ?></a></li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-unstyled">
                                <li><i class="fas fa-envelope me-2 text-success"></i> <a href="mailto:ldragon24@gmail.com?body=привет&subject=вопрос"> <?php echo __('install_step6_resource_support'); ?></a></li>
                                <li><i class="fas fa-star me-2 text-warning"></i> <a href="https://github.com/lostdragon24/little-opds" target="_blank">GitHub</a> - <?php echo __('install_step6_resource_github'); ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Язык установки (информация) -->
    <div class="mt-4 text-muted small">
        <i class="fas fa-language me-1"></i>
        <?php echo __('install_step6_language'); ?>: 
        <?php echo $detector->getLanguageFlag() . ' ' . $detector->getLanguageName(); ?>
    </div>
</div>

<style>
.success-animation {
    position: relative;
    display: inline-block;
    animation: bounceIn 0.8s ease;
}

.stat-card {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-10px);
    box-shadow: 0 20px 30px rgba(0,0,0,0.2) !important;
}

.action-btn {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.action-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.2);
}

@keyframes bounceIn {
    0% { transform: scale(0.3); opacity: 0; }
    50% { transform: scale(1.05); }
    70% { transform: scale(0.95); }
    100% { transform: scale(1); opacity: 1; }
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .display-4 {
        font-size: 2.5rem;
    }
    
    .stat-card {
        margin-bottom: 15px;
    }
    
    .action-btn {
        margin-bottom: 10px;
    }
}
</style>

<script>
// Автоматическое скрытие установщика через 30 секунд (напоминание)
setTimeout(function() {
    if (confirm('<?php echo __('install_step6_remove_reminder'); ?>')) {
        window.location.href = '<?php echo $baseUrl; ?>/admin/index.php';
    }
}, 30000);

// Анимация конфетти при загрузке (опционально)
document.addEventListener('DOMContentLoaded', function() {
    console.log('🎉 Installation completed successfully!');
    
    // Можно добавить простую анимацию
    const stars = document.querySelectorAll('.fa-star');
    stars.forEach((star, index) => {
        star.style.animation = `spin ${3 + index}s linear infinite`;
    });
});
</script>
