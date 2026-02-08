-- Установка для MySQL
-- 1. Таблица рейтингов
CREATE TABLE IF NOT EXISTS book_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    user_ip VARCHAR(45) NOT NULL,
    rating TINYINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_book (user_ip, book_id),
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Таблица избранного
CREATE TABLE IF NOT EXISTS book_favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    user_ip VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_favorite (user_ip, book_id),
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Индексы для оптимизации
CREATE INDEX idx_book_rating_book ON book_ratings (book_id);
CREATE INDEX idx_book_rating_user ON book_ratings (user_ip);
CREATE INDEX idx_book_rating_created ON book_ratings (created_at);

CREATE INDEX idx_favorite_book ON book_favorites (book_id);
CREATE INDEX idx_favorite_user ON book_favorites (user_ip);
CREATE INDEX idx_favorite_created ON book_favorites (created_at);

-- 4. Проверочный запрос
SELECT 'Таблицы созданы успешно!' as status;