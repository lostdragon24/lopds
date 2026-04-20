<?php
$error = $error ?? '';
$csrf_token = $csrf_token ?? '';
$title = $title ?? __('login_title');
$username_label = $username_label ?? __('login_username');
$password_label = $password_label ?? __('login_password');
$button_text = $button_text ?? __('login_button');
$default_hint = $default_hint ?? __('login_default');
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-lg border-0 rounded-lg mt-5">
            <div class="card-header bg-primary text-white text-center py-4">
                <h4 class="mb-0">
                    <i class="fas fa-lock me-2"></i>
                    <?php echo htmlspecialchars($title); ?>
                </h4>
            </div>
            <div class="card-body p-4">
                <?php if ($error) { ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php } ?>
                
                <form method="post" action="index.php">
                    <input type="hidden" name="action" value="do_login">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-user me-2"></i><?php echo $username_label; ?>
                        </label>
                        <input type="text" 
                               name="username" 
                               class="form-control form-control-lg" 
                               placeholder="admin"
                               required
                               autofocus>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-key me-2"></i><?php echo $password_label; ?>
                        </label>
                        <input type="password" 
                               name="password" 
                               class="form-control form-control-lg" 
                               placeholder="••••••••"
                               required>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-sign-in-alt me-2"></i>
                            <?php echo $button_text; ?>
                        </button>
                    </div>
                </form>
                
                <hr class="my-4">
                
                <div class="text-center text-muted small">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php echo $default_hint; ?>
                </div>
            </div>
        </div>
    </div>
</div>