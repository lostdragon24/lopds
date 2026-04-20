<?php
// /install/templates/step5.php
?>

<div class="text-center mb-4">
    <i class="fas fa-user-shield fa-4x text-primary mb-3"></i>
    <h3><?php echo __('install_step5_title'); ?></h3>
    <p class="text-muted"><?php echo __('install_step5_desc'); ?></p>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-lock me-2"></i>
                    <?php echo __('install_step5_admin_data'); ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="post" id="adminForm">
                    <input type="hidden" name="action" value="save_admin">
                    <input type="hidden" name="step" value="5">
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('install_step5_username'); ?></label>
                        <input type="text" class="form-control" name="admin_user" 
                               value="admin" readonly>
                        <small class="text-muted"><?php echo __('install_step5_username_hint'); ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('install_step5_password'); ?></label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="admin_password" 
                                   id="admin_password" required minlength="6">
                            <button class="btn btn-outline-secondary" type="button" 
                                    onclick="togglePasswordVisibility('admin_password')">
                                <i class="fas fa-eye" id="toggleAdminPasswordIcon"></i>
                            </button>
                        </div>
                        <small class="text-muted"><?php echo __('install_step5_password_hint'); ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('install_step5_confirm'); ?></label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="confirm_password" 
                                   id="confirm_password" required>
                            <button class="btn btn-outline-secondary" type="button" 
                                    onclick="togglePasswordVisibility('confirm_password')">
                                <i class="fas fa-eye" id="toggleConfirmPasswordIcon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Индикатор сложности пароля -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <small><?php echo __('install_step5_password_strength'); ?></small>
                            <small id="passwordStrengthText"><?php echo __('install_step5_password_weak'); ?></small>
                        </div>
                        <div class="progress" style="height: 5px;">
                            <div class="progress-bar" id="passwordStrengthBar" 
                                 role="progressbar" style="width: 0%"></div>
                        </div>
                    </div>
                    
                    <div id="passwordMatch" class="mb-3" style="display: none;"></div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="remember_me" 
                               id="remember_me" checked>
                        <label class="form-check-label" for="remember_me">
                            <?php echo __('install_step5_remember'); ?>
                        </label>
                    </div>
                    
                    <hr>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong><?php echo __('install_step5_info_title'); ?></strong><br>
                        <?php echo __('install_step5_info_desc'); ?>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong><?php echo __('install_step5_password_important'); ?></strong><br>
                        <?php echo __('install_step5_password_important_desc'); ?>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <a href="?step=4" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i><?php echo __('install_back'); ?>
                        </a>
                        <button type="submit" class="btn btn-success" id="submitBtn">
                            <i class="fas fa-save me-2"></i>
                            <?php echo __('install_step5_create_btn'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Подсказки по безопасности -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card bg-light">
            <div class="card-header bg-dark text-white">
                <h6 class="mb-0"><i class="fas fa-shield-alt me-2"></i><?php echo __('install_step5_security_tips'); ?></h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="text-center mb-3">
                            <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                            <h6><?php echo __('install_step5_tip_length'); ?></h6>
                            <small class="text-muted"><?php echo __('install_step5_tip_length_desc'); ?></small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center mb-3">
                            <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                            <h6><?php echo __('install_step5_tip_mix'); ?></h6>
                            <small class="text-muted"><?php echo __('install_step5_tip_mix_desc'); ?></small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center mb-3">
                            <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                            <h6><?php echo __('install_step5_tip_unique'); ?></h6>
                            <small class="text-muted"><?php echo __('install_step5_tip_unique_desc'); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Переключение видимости пароля
function togglePasswordVisibility(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById('toggle' + fieldId.charAt(0).toUpperCase() + fieldId.slice(1) + 'Icon');
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        field.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

// Проверка сложности пароля
function checkPasswordStrength(password) {
    let strength = 0;
    
    // Длина
    if (password.length >= 8) strength += 25;
    else if (password.length >= 6) strength += 15;
    
    // Цифры
    if (/\d/.test(password)) strength += 25;
    
    // Специальные символы
    if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) strength += 25;
    
    // Заглавные и строчные
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength += 25;
    
    return Math.min(strength, 100);
}

// Обновление индикатора сложности
function updatePasswordStrength() {
    const password = document.getElementById('admin_password').value;
    const strength = checkPasswordStrength(password);
    const bar = document.getElementById('passwordStrengthBar');
    const text = document.getElementById('passwordStrengthText');
    
    bar.style.width = strength + '%';
    
    if (strength < 30) {
        bar.className = 'progress-bar bg-danger';
        text.textContent = '<?php echo __('install_step5_password_weak'); ?>';
        text.className = 'text-danger';
    } else if (strength < 60) {
        bar.className = 'progress-bar bg-warning';
        text.textContent = '<?php echo __('install_step5_password_medium'); ?>';
        text.className = 'text-warning';
    } else if (strength < 80) {
        bar.className = 'progress-bar bg-info';
        text.textContent = '<?php echo __('install_step5_password_good'); ?>';
        text.className = 'text-info';
    } else {
        bar.className = 'progress-bar bg-success';
        text.textContent = '<?php echo __('install_step5_password_strong'); ?>';
        text.className = 'text-success';
    }
}

// Проверка совпадения паролей
document.getElementById('admin_password')?.addEventListener('keyup', function() {
    checkPasswords();
    updatePasswordStrength();
});

document.getElementById('confirm_password')?.addEventListener('keyup', checkPasswords);

function checkPasswords() {
    const pass = document.getElementById('admin_password').value;
    const confirm = document.getElementById('confirm_password').value;
    const matchDiv = document.getElementById('passwordMatch');
    const submitBtn = document.getElementById('submitBtn');
    
    if (!matchDiv) return;
    
    if (pass === '' && confirm === '') {
        matchDiv.style.display = 'none';
        submitBtn.disabled = true;
        return;
    }
    
    if (pass === confirm) {
        if (pass.length >= 6) {
            matchDiv.innerHTML = '<div class="alert alert-success py-2"><i class="fas fa-check-circle me-1"></i> <?php echo __('install_step5_passwords_match'); ?></div>';
            submitBtn.disabled = false;
        } else {
            matchDiv.innerHTML = '<div class="alert alert-warning py-2"><i class="fas fa-exclamation-triangle me-1"></i> <?php echo __('install_step5_password_length_error'); ?></div>';
            submitBtn.disabled = true;
        }
    } else {
        matchDiv.innerHTML = '<div class="alert alert-danger py-2"><i class="fas fa-times-circle me-1"></i> <?php echo __('install_step5_passwords_dont_match'); ?></div>';
        submitBtn.disabled = true;
    }
    matchDiv.style.display = 'block';
}

// Защита от случайной отправки формы
document.getElementById('adminForm').addEventListener('submit', function(e) {
    const pass = document.getElementById('admin_password').value;
    const confirm = document.getElementById('confirm_password').value;
    
    if (pass !== confirm) {
        e.preventDefault();
        alert('<?php echo __('install_step5_passwords_dont_match'); ?>');
        return false;
    }
    
    if (pass.length < 6) {
        e.preventDefault();
        alert('<?php echo __('install_step5_password_length_error'); ?>');
        return false;
    }
    
    // Отключаем кнопку после отправки
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?php echo __('install_step5_creating'); ?>';
});

// Инициализация
document.addEventListener('DOMContentLoaded', function() {
    // Проверяем, не установлен ли уже пароль
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('success') === '1') {
        document.getElementById('adminForm').style.display = 'none';
        const successDiv = document.createElement('div');
        successDiv.className = 'alert alert-success text-center';
        successDiv.innerHTML = '<i class="fas fa-check-circle fa-3x mb-3"></i><h4><?php echo __('install_step5_success'); ?></h4><p><?php echo __('install_step5_success_desc'); ?></p><a href="?step=6" class="btn btn-primary mt-3"><?php echo __('install_step5_continue'); ?></a>';
        document.querySelector('.card-body').appendChild(successDiv);
    }
});
</script>
