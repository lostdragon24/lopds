<?php
// templates/admin/layout/footer.php
?>
    </div> <!-- Закрываем content -->
</div> <!-- Закрываем wrapper -->

<!-- Bootstrap JS -->
    <script src="<?php echo $basePath; ?>/css/js/bootstrap.bundle.min.js"></script>



<script>
// ============================================
// Функция смены языка
// ============================================
function changeLanguage(lang) {
    // Показываем индикатор загрузки
    showAdminMessage('<?php echo __('changing_language'); ?>', 'info');
    
    // Создаем форму для отправки
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?php echo $basePath; ?>/change-language.php';
    form.style.display = 'none';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'lang';
    input.value = lang;
    
    form.appendChild(input);
    document.body.appendChild(form);
    
    // Отправляем форму
    form.submit();
}

// ============================================
// Уведомления в админке
// ============================================
function showAdminMessage(message, type = 'info') {
    // Проверяем, есть ли уже контейнер для уведомлений
    let container = document.getElementById('admin-notification-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'admin-notification-container';
        container.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
        `;
        document.body.appendChild(container);
    }
    
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.style.cssText = `
        animation: slideInRight 0.3s ease;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        margin-bottom: 10px;
    `;
    
    let icon = 'fa-info-circle';
    if (type === 'success') icon = 'fa-check-circle';
    if (type === 'danger') icon = 'fa-exclamation-circle';
    if (type === 'warning') icon = 'fa-exclamation-triangle';
    
    alertDiv.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="fas ${icon} me-2 fa-lg"></i>
            <div class="flex-grow-1">${message}</div>
            <button type="button" class="btn-close ms-2" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    `;
    
    container.appendChild(alertDiv);
    
    // Автоматическое закрытие через 5 секунд
    setTimeout(() => {
        if (alertDiv && alertDiv.parentNode) {
            alertDiv.classList.remove('show');
            setTimeout(() => {
                if (alertDiv.parentNode) alertDiv.remove();
            }, 300);
        }
    }, 5000);
    
    // Обработчик закрытия
    const closeBtn = alertDiv.querySelector('.btn-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            alertDiv.classList.remove('show');
            setTimeout(() => alertDiv.remove(), 300);
        });
    }
}

// ============================================
// Вспомогательные функции
// ============================================
function toggleElement(id) {
    const el = document.getElementById(id);
    if (el) {
        el.style.display = el.style.display === 'none' ? 'block' : 'none';
    }
}

// ============================================
// Инициализация обработчиков
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Инициализация Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Инициализация Bootstrap popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
    
    // Обработчик для переключателя языка
    const langItems = document.querySelectorAll('.dropdown-item[data-lang]');
    langItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const lang = this.getAttribute('data-lang');
            changeLanguage(lang);
        });
    });
});

// ============================================
// Стили для анимаций
// ============================================
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
    
    .alert {
        border-radius: 8px;
        border: none;
    }
    
    .fa-spinner {
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
`;
document.head.appendChild(style);
</script>

</body>
</html>