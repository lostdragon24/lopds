-- Установка для SQLite
-- 1. Таблица рейтингов
CREATE TABLE IF NOT EXISTS book_ratings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_id INTEGER NOT NULL,
    user_ip TEXT NOT NULL,
    rating INTEGER NOT NULL CHECK (rating >= 1 AND rating <= 5),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_ip, book_id),
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
);

-- 2. Таблица избранного
CREATE TABLE IF NOT EXISTS book_favorites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_id INTEGER NOT NULL,
    user_ip TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_ip, book_id),
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
);

-- 3. Индексы для оптимизации
CREATE INDEX IF NOT EXISTS idx_book_rating_book ON book_ratings (book_id);
CREATE INDEX IF NOT EXISTS idx_book_rating_user ON book_ratings (user_ip);

CREATE INDEX IF NOT EXISTS idx_favorite_book ON book_favorites (book_id);
CREATE INDEX IF NOT EXISTS idx_favorite_user ON book_favorites (user_ip);

-- 4. Проверочный запрос
SELECT 'Таблицы созданы успешно!' as status;