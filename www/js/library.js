(function() {
    'use strict';

    // ========== КОНСТАНТЫ И НАСТРОЙКИ ==========
    const CONFIG = {
        NOTIFICATION_DURATION: 3000,
        ANIMATION_DURATION: 400,
        MIN_CSRF_LENGTH: 10,
        DEBOUNCE_DELAY: 300,
        STORAGE_KEYS: {
            USER_RATINGS: 'book_user_ratings',
            FAVORITES: 'book_favorites'
        }
    };

    // ========== СОСТОЯНИЕ ПРИЛОЖЕНИЯ ==========
    const state = {
        pendingRequests: new Map(),
        favorites: new Set(),
        userRatings: new Map()
    };

    // ========== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ==========

    /**
     * Получить базовый URL API
     */
    function getApiUrl() {
        return window.API_URL || (window.BASE_PATH + '/api/rating.php');
    }

    /**
     * Проверка CSRF токена
     */
    function validateCsrfToken() {
        if (!window.CSRF_TOKEN || window.CSRF_TOKEN.length < CONFIG.MIN_CSRF_LENGTH) {
            console.error('CSRF token is missing or invalid');
            showNotification(getTranslation('error_csrf'), 'error');
            return false;
        }
        return true;
    }

    /**
     * Получить перевод по ключу
     */
    function getTranslation(key) {
        const translations = window.TRANSLATIONS || {};
        return translations[key] || key;
    }

    /**
     * Централизованная функция для API запросов
     */
    async function apiRequest(action, data = {}, requireCsrf = true) {
        const requestId = `${action}_${JSON.stringify(data)}`;

        if (state.pendingRequests.has(requestId)) {
            console.log(`Request ${requestId} already in progress`);
            return state.pendingRequests.get(requestId);
        }

        if (requireCsrf && !validateCsrfToken()) {
            throw new Error(getTranslation('error_csrf'));
        }

        const requestData = {
            action,
            ...data,
            ...(requireCsrf && { csrf_token: window.CSRF_TOKEN })
        };

        const requestPromise = (async () => {
            try {
                const response = await fetch(getApiUrl(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(requestData)
                });

                if (!response.ok) {
                    let errorMsg = `HTTP error ${response.status}`;
                    try {
                        const errData = await response.json();
                        errorMsg = errData.message || errorMsg;
                    } catch (e) {
                        errorMsg = response.statusText || errorMsg;
                    }
                    throw new Error(errorMsg);
                }

                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.message || getTranslation('error_unknown'));
                }

                return result;
            } finally {
                setTimeout(() => {
                    state.pendingRequests.delete(requestId);
                }, 100);
            }
        })();

        state.pendingRequests.set(requestId, requestPromise);
        return requestPromise;
    }

    /**
     * Debounce функция для оптимизации производительности
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // ========== УВЕДОМЛЕНИЯ ==========

    const notificationTypes = {
        success: { class: 'alert-success', icon: 'fa-check-circle', emoji: '✅' },
        error: { class: 'alert-danger', icon: 'fa-exclamation-circle', emoji: '❌' },
        info: { class: 'alert-info', icon: 'fa-info-circle', emoji: 'ℹ️' },
        warning: { class: 'alert-warning', icon: 'fa-exclamation-triangle', emoji: '⚠️' }
    };

    class NotificationManager {
        constructor() {
            this.container = null;
            this.activeNotifications = new Set();
        }

        ensureContainer() {
            if (this.container) return this.container;

            if (!document.body) {
                return null;
            }

            let container = document.getElementById('notification-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'notification-container';
                container.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 9999;
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                    pointer-events: none;
                `;
                document.body.appendChild(container);
            }

            this.container = container;
            return container;
        }

        show(message, type = 'info', duration = CONFIG.NOTIFICATION_DURATION) {
            const container = this.ensureContainer();

            if (!container) {
                setTimeout(() => this.show(message, type, duration), 50);
                return null;
            }

            const config = notificationTypes[type] || notificationTypes.info;
            const id = 'notification_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

            const notification = document.createElement('div');
            notification.id = id;
            notification.className = `alert ${config.class} alert-dismissible fade show`;
            notification.style.cssText = `
                pointer-events: auto;
                min-width: 300px;
                max-width: 400px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                animation: slideInRight 0.3s ease;
                border-radius: 8px;
                margin: 0;
            `;

            notification.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas ${config.icon} me-2 fa-lg"></i>
                    <div class="flex-grow-1">${config.emoji} ${message}</div>
                    <button type="button" class="btn-close ms-2" aria-label="${getTranslation('close')}"></button>
                </div>
            `;

            const closeBtn = notification.querySelector('.btn-close');
            closeBtn.addEventListener('click', () => this.dismiss(id));

            container.appendChild(notification);
            this.activeNotifications.add(id);

            if (duration > 0) {
                setTimeout(() => this.dismiss(id), duration);
            }

            return id;
        }

        dismiss(id) {
            const notification = document.getElementById(id);
            if (!notification || !this.activeNotifications.has(id)) return;

            this.activeNotifications.delete(id);
            notification.style.animation = 'slideOutRight 0.3s ease';

            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 300);
        }

        dismissAll() {
            this.activeNotifications.forEach(id => this.dismiss(id));
        }
    }

    const notificationManager = new NotificationManager();

    function showNotification(message, type = 'info', duration = CONFIG.NOTIFICATION_DURATION) {
        return notificationManager.show(message, type, duration);
    }

    // ========== АНИМАЦИИ ==========

    class AnimationManager {
        static async animate(element, animationClass, duration = CONFIG.ANIMATION_DURATION) {
            if (!element) return;

            element.classList.add(animationClass);

            return new Promise(resolve => {
                setTimeout(() => {
                    element.classList.remove(animationClass);
                    resolve();
                }, duration);
            });
        }

        static async heartBeat(button, isAdding) {
            const heart = button.querySelector('i');
            if (!heart) return;

            await this.animate(heart, 'animate-pop');

            if (isAdding) {
                heart.classList.remove('far');
                heart.classList.add('fas');
            } else {
                heart.classList.remove('fas');
                heart.classList.add('far');
            }
        }

        static async starBurst(containerId) {
            const stars = document.querySelectorAll(`${containerId} .fa-star, ${containerId} .fa-star-half-alt`);
            const animations = [];

            stars.forEach((star, index) => {
                animations.push(
                    new Promise(resolve => {
                        setTimeout(async () => {
                            await this.animate(star, 'animate-pulse', 500);
                            resolve();
                        }, index * 100);
                    })
                );
            });

            await Promise.all(animations);
        }

        static async ratingUpdate() {
            const avgElement = document.getElementById('average-rating');
            if (avgElement) {
                await this.animate(avgElement, 'rating-updated');
            }
            await this.starBurst('#average-stars');
        }
    }

    // ========== ИЗБРАННОЕ ==========

    class FavoriteManager {
        constructor() {
            this.initFromStorage();
        }

        initFromStorage() {
            try {
                const saved = localStorage.getItem(CONFIG.STORAGE_KEYS.FAVORITES);
                if (saved) {
                    state.favorites = new Set(JSON.parse(saved));
                }
            } catch (e) {
                console.warn('Failed to load favorites from storage:', e);
            }
        }

        saveToStorage() {
            try {
                localStorage.setItem(
                    CONFIG.STORAGE_KEYS.FAVORITES,
                    JSON.stringify([...state.favorites])
                );
            } catch (e) {
                console.warn('Failed to save favorites to storage:', e);
            }
        }

        isFavorite(bookId) {
            return state.favorites.has(Number(bookId));
        }

        async toggle(bookId, button) {
            console.log('Toggle favorite:', bookId);

            if (!bookId || bookId === 0 || bookId === '0') {
                console.log('Skipping invalid bookId:', bookId);
                return;
            }

            if (button?.disabled) {
                console.log('Button already processing');
                return;
            }

            const bookIdNum = Number(bookId);
            const originalHtml = button?.innerHTML;
            const wasFavorite = this.isFavorite(bookIdNum);

            try {
                if (button) {
                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    button.disabled = true;
                }

                if (wasFavorite) {
                    state.favorites.delete(bookIdNum);
                } else {
                    state.favorites.add(bookIdNum);
                }
                this.saveToStorage();

                const data = await apiRequest('toggle_favorite', { book_id: bookIdNum });

                if (data.success) {
                    const isNowFavorite = data.is_favorite;

                    if (isNowFavorite) {
                        state.favorites.add(bookIdNum);
                        showNotification(getTranslation('favorites_added'), 'success');
                    } else {
                        state.favorites.delete(bookIdNum);
                        showNotification(getTranslation('favorites_removed'), 'info');
                    }
                    this.saveToStorage();

                    if (button) {
                        this.updateButtonUI(button, isNowFavorite);
                        await AnimationManager.heartBeat(button, isNowFavorite);
                    }
                } else {
                    if (wasFavorite) {
                        state.favorites.add(bookIdNum);
                    } else {
                        state.favorites.delete(bookIdNum);
                    }
                    throw new Error(data.message || getTranslation('error_unknown'));
                }
            } catch (error) {
                console.error('Error toggling favorite:', error);

                if (wasFavorite) {
                    state.favorites.add(bookIdNum);
                } else {
                    state.favorites.delete(bookIdNum);
                }

                if (button) {
                    button.innerHTML = originalHtml;
                    this.updateButtonUI(button, wasFavorite);
                }

                showNotification(getTranslation('error_occurred') + ': ' + error.message, 'error');
            } finally {
                if (button) {
                    button.disabled = false;
                }
            }
        }

        updateButtonUI(button, isFavorite) {
            if (isFavorite) {
                button.classList.remove('btn-outline-danger');
                button.classList.add('btn-danger');
                button.innerHTML = '<i class="fas fa-heart"></i>';
                button.title = getTranslation('favorites_remove');
            } else {
                button.classList.remove('btn-danger');
                button.classList.add('btn-outline-danger');
                button.innerHTML = '<i class="far fa-heart"></i>';
                button.title = getTranslation('favorites_add');
            }
        }
    }

    // ========== РЕЙТИНГИ ==========

    class RatingManager {
        constructor() {
            this.initFromStorage();
        }

        initFromStorage() {
            try {
                const saved = localStorage.getItem(CONFIG.STORAGE_KEYS.USER_RATINGS);
                if (saved) {
                    state.userRatings = new Map(JSON.parse(saved));
                }
            } catch (e) {
                console.warn('Failed to load ratings from storage:', e);
            }
        }

        saveToStorage() {
            try {
                localStorage.setItem(
                    CONFIG.STORAGE_KEYS.USER_RATINGS,
                    JSON.stringify([...state.userRatings])
                );
            } catch (e) {
                console.warn('Failed to save ratings to storage:', e);
            }
        }

        getUserRating(bookId) {
            return state.userRatings.get(Number(bookId)) || 0;
        }

        async rate(bookId, rating, clickedStar) {
            console.log('Rate book:', bookId, rating);

            if (!bookId || bookId === 0) {
                showNotification(getTranslation('error_invalid_id'), 'error');
                return;
            }

            const bookIdNum = Number(bookId);
            const oldRating = this.getUserRating(bookIdNum);

            try {
                if (clickedStar) {
                    await AnimationManager.animate(clickedStar, 'animate-pulse');
                }

                state.userRatings.set(bookIdNum, Number(rating));
                this.saveToStorage();
                this.setStarsEnabled(false);

                const data = await apiRequest('rate', {
                    book_id: bookIdNum,
                    rating: Number(rating)
                });

                if (data.success) {
                    this.updateBookDetailRating(data.rating, data.user_rating || rating);
                    showNotification(getTranslation('rating_saved'), 'success');
                    await AnimationManager.starBurst('#user-rating-stars');
                } else {
                    throw new Error(data.message || getTranslation('rating_error'));
                }
            } catch (error) {
                console.error('Error rating book:', error);

                if (oldRating) {
                    state.userRatings.set(bookIdNum, oldRating);
                } else {
                    state.userRatings.delete(bookIdNum);
                }

                showNotification(getTranslation('error_occurred') + ': ' + error.message, 'error');
                this.updateUserStars(oldRating || 0);
            } finally {
                this.setStarsEnabled(true);
            }
        }

        setStarsEnabled(enabled) {
            document.querySelectorAll('.rating-star').forEach(star => {
                star.style.opacity = enabled ? '1' : '0.5';
                star.disabled = !enabled;
            });
        }

        async loadRating(bookId, element) {
            console.log('Load rating for book:', bookId);

            if (!bookId || bookId === 0 || bookId === '0') {
                if (element) {
                    element.innerHTML = '<small class="text-muted">—</small>';
                }
                return;
            }

            const bookIdNum = Number(bookId);

            if (!element) {
                element = document.getElementById('rating-' + bookIdNum) ||
                         document.getElementById('rating-section');
                if (!element) {
                    console.log('Rating element not found for book', bookIdNum);
                    return;
                }
            }

            try {
                const data = await apiRequest('get_rating', { book_id: bookIdNum }, false);

                if (data.success && data.rating) {
                    if (element.id === 'rating-section') {
                        this.updateBookDetailRating(data.rating, data.user_rating);
                    } else if (data.rating.votes > 0) {
                        this.displayMiniRating(element, data.rating);
                    } else {
                        element.innerHTML = '<small class="text-muted">' + getTranslation('rating_no_votes') + '</small>';
                    }
                } else {
                    element.innerHTML = '<small class="text-muted">' + getTranslation('rating_no_votes') + '</small>';
                }
            } catch (error) {
                console.error('Error loading rating:', error);
                element.innerHTML = '<small class="text-muted">' + getTranslation('error_occurred') + '</small>';
            }
        }

        displayMiniRating(element, ratingData) {
            const rating = ratingData.average_rounded;
            let starsHtml = '';

            for (let i = 1; i <= 5; i++) {
                if (i <= Math.floor(rating)) {
                    starsHtml += '<i class="fas fa-star text-warning" style="font-size: 0.8em;"></i>';
                } else if (i - 0.5 <= rating) {
                    starsHtml += '<i class="fas fa-star-half-alt text-warning" style="font-size: 0.8em;"></i>';
                } else {
                    starsHtml += '<i class="far fa-star text-warning" style="font-size: 0.8em;"></i>';
                }
            }
            element.innerHTML = starsHtml + ` <small class="text-muted">${ratingData.average.toFixed(1)}</small>`;
        }

        updateBookDetailRating(ratingData, userRating) {
            console.log('Updating book detail rating:', ratingData);

            const avgElement = document.getElementById('average-rating');
            if (avgElement) {
                avgElement.textContent = ratingData.average ? ratingData.average.toFixed(1) : '0.0';
            }

            const votesElement = document.getElementById('votes-count');
            if (votesElement) {
                const votes = ratingData.votes || 0;
                votesElement.textContent = votes + ' ' + this.getVotesWord(votes);
            }

            this.updateAverageStars(ratingData.average_rounded || 0);
            this.updateUserStars(userRating || 0);
            AnimationManager.ratingUpdate();
        }

        updateUserStars(userRating) {
            const stars = document.querySelectorAll('.rating-star');
            stars.forEach(star => {
                const starRating = parseInt(star.dataset.rating);
                const icon = star.querySelector('i');

                if (starRating <= userRating) {
                    icon.className = 'fas fa-star fa-2x text-warning';
                } else {
                    icon.className = 'far fa-star fa-2x text-muted';
                }
                star.style.opacity = '1';
                star.disabled = false;
            });

            const userRatingText = document.getElementById('user-rating-text');
            if (userRatingText) {
                if (userRating > 0) {
                    const starsWord = userRating === 1 ? getTranslation('rating_star_1') : 
                                     (userRating < 5 ? getTranslation('rating_star_2') : getTranslation('rating_star_5'));
                    userRatingText.textContent = getTranslation('rating_your_value')
                        .replace('%s', userRating)
                        .replace('%s', starsWord);
                } else {
                    userRatingText.textContent = getTranslation('rating_click_to_rate');
                }
            }
        }

        updateAverageStars(avgRounded) {
            const starsContainer = document.getElementById('average-stars');
            if (!starsContainer) return;

            const fullStars = Math.floor(avgRounded);
            const halfStar = (avgRounded - fullStars) >= 0.5;
            const emptyStars = 5 - fullStars - (halfStar ? 1 : 0);

            let starsHtml = '';
            for (let i = 0; i < fullStars; i++) starsHtml += '<i class="fas fa-star text-warning fa-2x"></i>';
            if (halfStar) starsHtml += '<i class="fas fa-star-half-alt text-warning fa-2x"></i>';
            for (let i = 0; i < emptyStars; i++) starsHtml += '<i class="far fa-star text-warning fa-2x"></i>';

            starsContainer.innerHTML = starsHtml;
        }

        highlightStars(rating) {
            document.querySelectorAll('.rating-star').forEach(star => {
                const starRating = parseInt(star.dataset.rating);
                if (starRating <= rating) {
                    star.style.transform = 'scale(1.1)';
                    star.querySelector('i').className = 'fas fa-star fa-2x text-warning';
                }
            });
        }

        resetStars() {
            document.querySelectorAll('.rating-star').forEach(star => {
                star.style.transform = 'scale(1)';
            });
        }

        getVotesWord(count) {
            if (count % 10 === 1 && count % 100 !== 11) return getTranslation('rating_vote_1');
            if ([2,3,4].includes(count % 10) && ![12,13,14].includes(count % 100)) return getTranslation('rating_vote_2');
            return getTranslation('rating_vote_5');
        }
    }

    // ========== УПРАВЛЕНИЕ ИНТЕРФЕЙСОМ ==========

    class UIManager {
        constructor() {
            this.favoriteManager = new FavoriteManager();
            this.ratingManager = new RatingManager();
            this.init();
        }

        init() {
            this.addAnimationStyles();
            this.initializeButtons();
            this.initializeBackToTop();
            this.loadAllRatings();
        }

        addAnimationStyles() {
            if (document.getElementById('library-animation-styles')) return;

            const style = document.createElement('style');
            style.id = 'library-animation-styles';
            style.textContent = `
                :root {
                    --rating-star-size: 24px;
                    --rating-star-size-mobile: 24px;
                }
                
                .rating-star i, .rating-star-btn i {
                    font-size: var(--rating-star-size) !important;
                    width: var(--rating-star-size);
                    height: var(--rating-star-size);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                @media (max-width: 768px) {
                    .rating-star i, .rating-star-btn i {
                        font-size: var(--rating-star-size-mobile) !important;
                        width: var(--rating-star-size-mobile);
                        height: var(--rating-star-size-mobile);
                    }
                }

                @keyframes slideInRight {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOutRight {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                @keyframes heartPop {
                    0% { transform: scale(1); }
                    50% { transform: scale(1.4); }
                    100% { transform: scale(1); }
                }
                @keyframes starPulse {
                    0% { transform: scale(1); opacity: 1; }
                    50% { transform: scale(1.2); opacity: 0.8; }
                    100% { transform: scale(1); opacity: 1; }
                }
                @keyframes ratingUpdate {
                    0% { transform: scale(1); }
                    50% { transform: scale(1.1); color: #ffc107; }
                    100% { transform: scale(1); }
                }

                .fa-spinner { animation: spin 1s linear infinite; }
                .rating-star { cursor: pointer; transition: transform 0.2s; }
                .rating-star:hover { transform: scale(1.1); }
                .fa-heart.animate-pop { animation: heartPop 0.4s ease-out; }
                .fa-star.animate-pulse { animation: starPulse 0.5s ease-in-out; }
                .rating-updated { animation: ratingUpdate 0.5s ease-in-out; }

                #notification-container {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 9999;
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                }
            `;
            document.head.appendChild(style);
        }

        initializeButtons() {
            this.initializeFavoriteButtons();
            this.initializeRatingStars();
        }

        initializeFavoriteButtons() {
            const selector = '.favorite-btn, .favorite-btn-mini, [data-favorite-button]';

            document.querySelectorAll(selector).forEach(button => {
                if (button.dataset.favoriteInitialized === 'true') return;

                button.removeAttribute('onclick');

                const bookId = button.dataset.bookId;
                if (bookId && this.favoriteManager.isFavorite(bookId)) {
                    this.favoriteManager.updateButtonUI(button, true);
                }

                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    if (button.disabled) return;

                    const bookId = button.dataset.bookId;
                    if (bookId && !isNaN(parseInt(bookId))) {
                        this.favoriteManager.toggle(parseInt(bookId), button);
                    }
                });

                button.dataset.favoriteInitialized = 'true';
            });
        }

        initializeRatingStars() {
            document.querySelectorAll('.rating-star').forEach(star => {
                if (star.dataset.ratingInitialized === 'true') return;

                const container = star.closest('[data-book-id]');
                if (container) {
                    const bookId = container.dataset.bookId;
                    if (bookId) {
                        const userRating = this.ratingManager.getUserRating(bookId);
                        if (userRating > 0) {
                            this.ratingManager.updateUserStars(userRating);
                        }
                    }
                }

                star.addEventListener('mouseenter', (e) => {
                    const rating = parseInt(star.dataset.rating);
                    this.ratingManager.highlightStars(rating);
                });

                star.addEventListener('mouseleave', () => {
                    this.ratingManager.resetStars();
                });

                star.dataset.ratingInitialized = 'true';
            });
        }

        initializeBackToTop() {
            const backToTop = document.getElementById('btn-back-to-top');
            if (!backToTop) return;

            const toggleButton = debounce(() => {
                const shouldShow = document.body.scrollTop > 100 ||
                                 document.documentElement.scrollTop > 100;
                backToTop.style.display = shouldShow ? 'block' : 'none';
            }, 100);

            window.addEventListener('scroll', toggleButton);

            backToTop.addEventListener('click', (e) => {
                e.preventDefault();
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        }

        loadAllRatings() {
            document.querySelectorAll('[id^="rating-"]').forEach(element => {
                const bookId = element.dataset.bookId || element.id.replace('rating-', '');
                if (bookId && !isNaN(parseInt(bookId))) {
                    this.ratingManager.loadRating(parseInt(bookId), element);
                }
            });
        }
    }

    // ========== ИНИЦИАЛИЗАЦИЯ ПРИ ЗАГРУЗКЕ ==========

    let uiManager;

    document.addEventListener('DOMContentLoaded', function() {
        console.log('Library JS initialized');
        console.log('BASE_PATH:', window.BASE_PATH);
        console.log('API_URL:', window.API_URL);

        uiManager = new UIManager();
    });

// ============ наблюдатель за видимостью

document.addEventListener('DOMContentLoaded', function() {
    if ('IntersectionObserver' in window) {
        const images = document.querySelectorAll('.book-cover[loading="lazy"]');
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.loading = 'eager';
                    imageObserver.unobserve(img);
                }
            });
        });
        
        images.forEach(img => imageObserver.observe(img));
    }
});



    // ========== ЭКСПОРТ В ГЛОБАЛЬНУЮ ОБЛАСТЬ ==========

    window.showNotification = showNotification;
    window.validateCsrfToken = validateCsrfToken;
    window.getTranslation = getTranslation;
    window.toggleFavorite = (bookId, button) => uiManager?.favoriteManager.toggle(bookId, button);
    window.rateBook = (bookId, rating, star) => uiManager?.ratingManager.rate(bookId, rating, star);
    window.loadBookRating = (bookId, element) => uiManager?.ratingManager.loadRating(bookId, element);
    window.notificationManager = notificationManager;
    window.AnimationManager = AnimationManager;
    window.apiRequest = apiRequest;

})();
