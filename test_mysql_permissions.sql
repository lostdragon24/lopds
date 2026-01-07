-- Проверка прав пользователя
SHOW GRANTS FOR CURRENT_USER();

-- Создание простой тестовой таблицы
CREATE TABLE IF NOT EXISTS test_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Вставка тестовых данных
INSERT INTO test_permissions (name) VALUES ('test1'), ('test2');

-- Проверка данных
SELECT * FROM test_permissions;

-- Удаление тестовой таблицы
DROP TABLE IF EXISTS test_permissions;