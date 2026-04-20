<?php
// /install/templates/footer.php
?>
                    </div>
                </div>
                
                <!-- Предупреждение о безопасности -->
                <div class="alert alert-warning text-center">
                    <i class="fas fa-shield-alt me-2"></i>
                    <strong><?php echo __('security_warning_short'); ?></strong>
                    <?php echo sprintf(__('security_warning_install'), '<code>/install/</code>'); ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="<?php echo $basePath; ?>/css/js/bootstrap.bundle.min.js"></script>

    <!-- Installer JS -->
    <script src="assets/js/installer.js"></script>
</body>
</html>