// js/reader-bookmarks.js

class ReaderBookmarks {
    constructor(bookId, readerFrame) {
        this.bookId = bookId;
        this.readerFrame = readerFrame;
        this.bookmarks = [];
        this.fingerprint = this.getFingerprint();
        this.csrfToken = this.getCsrfToken();
        this.loading = false;
        
        this.init();
    }
    
    getFingerprint() {
        // Получаем fingerprint из кук
        const match = document.cookie.match(/device_fp=([^;]+)/);
        return match ? match[1] : null;
    }
    
    getCsrfToken() {
        return window.CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content;
    }
    
    init() {
        // Создаем UI для закладок
        this.createUI();
        // Загружаем закладки
        this.loadBookmarks();
        // Добавляем обработчики клавиш
        this.addKeyboardShortcuts();
    }
    
    createUI() {
        // Контейнер для закладок в читалке
        const container = document.querySelector('.reader-controls');
        if (!container) return;
        
        // Создаем панель закладок
        const panel = document.createElement('div');
        panel.className = 'reader-bookmarks-panel';
        panel.innerHTML = `
            <button class="btn btn-outline-primary bookmark-btn" title="Добавить закладку (Ctrl+B)">
                <i class="fas fa-bookmark"></i>
            </button>
            <button class="btn btn-outline-secondary bookmarks-list-btn" title="Список закладок (Ctrl+Shift+B)">
                <i class="fas fa-list"></i>
            </button>
            <span class="bookmark-count badge bg-primary">0</span>
        `;
        
        // Добавляем в панель управления
        const controls = container.querySelector('.col-4:last-child');
        if (controls) {
            controls.prepend(panel);
        }
        
        // Обработчики
        panel.querySelector('.bookmark-btn').addEventListener('click', () => this.addBookmark());
        panel.querySelector('.bookmarks-list-btn').addEventListener('click', () => this.showBookmarksList());
    }
    
    addKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl+B - добавить закладку
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                this.addBookmark();
            }
            
            // Ctrl+Shift+B - показать закладки
            if (e.ctrlKey && e.shiftKey && e.key === 'B') {
                e.preventDefault();
                this.showBookmarksList();
            }
        });
    }
    
    async loadBookmarks() {
        if (this.loading) return;
        this.loading = true;
        
        try {
            const response = await fetch('/api/bookmarks.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'get_for_book',
                    book_id: this.bookId,
                    csrf_token: this.csrfToken
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.bookmarks = data.data;
                this.updateBookmarkCount();
            }
        } catch (error) {
            console.error('Error loading bookmarks:', error);
        } finally {
            this.loading = false;
        }
    }
    
    async addBookmark() {
        // Получаем текущую позицию из iframe
        const position = await this.getCurrentPosition();
        
        if (!position) {
            showNotification('Не удалось определить позицию', 'error');
            return;
        }
        
        const note = prompt('Введите заметку для закладки (необязательно):');
        if (note === null) return; // Отмена
        
        try {
            const response = await fetch('/api/bookmarks.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'create',
                    book_id: this.bookId,
                    cfi_range: position.cfi,
                    page_number: position.page || 0,
                    percentage: position.percentage || 0,
                    note: note || '',
                    csrf_token: this.csrfToken
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                showNotification('Закладка добавлена!', 'success');
                this.loadBookmarks();
                
                // Визуальный эффект
                const btn = document.querySelector('.bookmark-btn');
                if (btn) {
                    btn.classList.add('active');
                    btn.querySelector('i').classList.add('fa-bounce');
                    setTimeout(() => {
                        btn.querySelector('i').classList.remove('fa-bounce');
                    }, 1000);
                }
            } else {
                showNotification(data.message || 'Ошибка добавления закладки', 'error');
            }
        } catch (error) {
            console.error('Error adding bookmark:', error);
            showNotification('Ошибка сети', 'error');
        }
    }
    
    async getCurrentPosition() {
        return new Promise((resolve) => {
            if (!this.readerFrame || !this.readerFrame.contentWindow) {
                resolve(null);
                return;
            }
            
            // Запрашиваем позицию у iframe
            this.readerFrame.contentWindow.postMessage({
                type: 'getPosition'
            }, '*');
            
            // Ждем ответ
            const handler = (event) => {
                if (event.data && event.data.type === 'position') {
                    window.removeEventListener('message', handler);
                    resolve(event.data.position);
                }
            };
            
            window.addEventListener('message', handler);
            
            // Таймаут
            setTimeout(() => {
                window.removeEventListener('message', handler);
                resolve(null);
            }, 3000);
        });
    }
    
    updateBookmarkCount() {
        const countEl = document.querySelector('.bookmark-count');
        if (countEl) {
            const count = this.bookmarks.filter(b => b.note !== 'Последнее прочитанное').length;
            countEl.textContent = count;
            countEl.style.display = count > 0 ? 'inline-block' : 'none';
        }
    }
    
    async showBookmarksList() {
        // Создаем модальное окно со списком закладок
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.id = 'bookmarksModal';
        modal.setAttribute('tabindex', '-1');
        modal.innerHTML = `
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-bookmark me-2"></i>
                            Закладки
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="bookmarks-list">
                            ${this.renderBookmarksList()}
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        const modalInstance = new bootstrap.Modal(modal);
        modalInstance.show();
        
        // Удаляем модальное окно после закрытия
        modal.addEventListener('hidden.bs.modal', () => {
            modal.remove();
        });
        
        // Обработчики для кнопок в списке
        modal.querySelectorAll('.go-to-bookmark').forEach(btn => {
            btn.addEventListener('click', () => {
                const cfi = btn.dataset.cfi;
                this.goToPosition(cfi);
                modalInstance.hide();
            });
        });
        
        modal.querySelectorAll('.delete-bookmark').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = btn.dataset.id;
                if (confirm('Удалить закладку?')) {
                    await this.deleteBookmark(id);
                    this.loadBookmarks();
                    modalInstance.hide();
                }
            });
        });
    }
    
    renderBookmarksList() {
        if (this.bookmarks.length === 0) {
            return '<div class="text-center text-muted py-4">Нет закладок</div>';
        }
        
        let html = '<div class="list-group">';
        
        this.bookmarks.forEach(bm => {
            const isLastRead = bm.note === 'Последнее прочитанное';
            html += `
                <div class="list-group-item list-group-item-action">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="flex-grow-1">
                            ${isLastRead ? '<span class="badge bg-info me-2">Последнее</span>' : ''}
                            <span class="text-muted small">
                                ${bm.percentage > 0 ? Math.round(bm.percentage) + '%' : 'Стр. ' + bm.page_number}
                            </span>
                            ${bm.note && bm.note !== 'Последнее прочитанное' ? 
                                `<div class="mt-1"><strong>${bm.note}</strong></div>` : ''}
                            <div class="text-muted small">
                                ${new Date(bm.created_at).toLocaleString()}
                            </div>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary go-to-bookmark" 
                                    data-cfi="${bm.cfi_range}">
                                <i class="fas fa-arrow-right"></i>
                            </button>
                            ${!isLastRead ? `
                                <button class="btn btn-outline-danger delete-bookmark" 
                                        data-id="${bm.id}">
                                    <i class="fas fa-trash"></i>
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
        return html;
    }
    
    async deleteBookmark(id) {
        try {
            const response = await fetch('/api/bookmarks.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'delete',
                    bookmark_id: id,
                    csrf_token: this.csrfToken
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                showNotification('Закладка удалена', 'info');
                this.loadBookmarks();
            } else {
                showNotification(data.message || 'Ошибка удаления', 'error');
            }
        } catch (error) {
            console.error('Error deleting bookmark:', error);
            showNotification('Ошибка сети', 'error');
        }
    }
    
    goToPosition(cfi) {
        if (this.readerFrame && this.readerFrame.contentWindow) {
            this.readerFrame.contentWindow.postMessage({
                type: 'goTo',
                cfi: cfi
            }, '*');
        }
    }
}

// Инициализация при загрузке читалки
document.addEventListener('DOMContentLoaded', function() {
    const bookId = document.querySelector('[data-book-id]')?.dataset.bookId;
    const readerFrame = document.getElementById('readerFrame');
    
    if (bookId && readerFrame) {
        window.bookmarks = new ReaderBookmarks(bookId, readerFrame);
    }
});