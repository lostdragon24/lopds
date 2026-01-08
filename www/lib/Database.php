<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Cache.php';

class Database {
    private $pdo;
    private static $instance = null;
    private $queryCount = 0;
    private $cacheHits = 0;
    private $cacheMisses = 0;
    
    private function __construct() {
        try {
            switch (Config::DB_TYPE) {
                case 'sqlite':
                    $dsn = 'sqlite:' . Config::DB_PATH;
                    $this->pdo = new PDO($dsn);
                    // Оптимизации для SQLite на Raspberry Pi
                    $this->pdo->exec('PRAGMA journal_mode = WAL');
                    $this->pdo->exec('PRAGMA synchronous = NORMAL');
                    $this->pdo->exec('PRAGMA cache_size = -64000');
                    $this->pdo->exec('PRAGMA temp_store = memory');
                    break;
                case 'mysql':
                    $dsn = 'mysql:host=' . Config::DB_HOST . ';dbname=' . Config::DB_NAME . ';charset=utf8mb4';
                    $this->pdo = new PDO($dsn, Config::DB_USER, Config::DB_PASS, [
                        PDO::ATTR_PERSISTENT => false,
                        PDO::ATTR_TIMEOUT => 30,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                    ]);
                    break;
                case 'pgsql':
                    $dsn = 'pgsql:host=' . Config::DB_HOST . ';dbname=' . Config::DB_NAME;
                    $this->pdo = new PDO($dsn, Config::DB_USER, Config::DB_PASS);
                    break;
                default:
                    throw new Exception('Unsupported database type');
            }
            
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Универсальный метод для выполнения запросов с параметрами
     */
    private function executeQuery($sql, $params = []) {
        $this->queryCount++;
        
        if (Config::PERFORMANCE['enable_query_logging']) {
            error_log("DB Query: " . $sql . " | Params: " . json_encode($params));
        }
        
        $startTime = microtime(true);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            
            // Привязываем параметры с правильными типами
            foreach ($params as $index => $value) {
                $paramType = PDO::PARAM_STR;
                if (is_int($value)) {
                    $paramType = PDO::PARAM_INT;
                } elseif (is_bool($value)) {
                    $paramType = PDO::PARAM_BOOL;
                } elseif (is_null($value)) {
                    $paramType = PDO::PARAM_NULL;
                }
                
                $stmt->bindValue($index + 1, $value, $paramType);
            }
            
            $stmt->execute();
            
            $queryTime = microtime(true) - $startTime;
            if ($queryTime > 1.0 && Config::PERFORMANCE['enable_query_logging']) {
                error_log("Slow query (" . round($queryTime, 3) . "s): " . $sql);
            }
            
            return $stmt;
            
        } catch (PDOException $e) {
            error_log("Query failed: " . $sql . " | Error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Получить количество выполненных запросов (для отладки)
     */
    public function getQueryCount() {
        return $this->queryCount;
    }
    
    /**
     * Получить статистику кэширования
     */
    public function getCacheStats() {
        return [
            'hits' => $this->cacheHits,
            'misses' => $this->cacheMisses,
            'effectiveness' => ($this->cacheHits + $this->cacheMisses) > 0 ? 
                round($this->cacheHits / ($this->cacheHits + $this->cacheMisses) * 100, 1) : 0
        ];
    }
    
    /**
     * Вспомогательный метод для создания ключа кэша
     */
    private function getCacheKey($prefix, $params) {
        return $prefix . '_' . md5(serialize($params));
    }
    
    // === ОСНОВНЫЕ МЕТОДЫ ДОСТУПА К ДАННЫМ С КЭШИРОВАНИЕМ ===
    
    /**
     * Поиск книг - оптимизированная версия с кэшированием
     */


private function getSearchStrategy($query, $field) {
    if (empty($query)) {
        return 'generic';
    }
    
    if (Config::DB_TYPE !== 'mysql') {
        return 'generic';
    }
    
    // Проверяем доступность нужных FULLTEXT индексов
    try {
        $stmt = $this->executeQuery("SHOW INDEXES FROM books WHERE Index_type = 'FULLTEXT'");
        $fulltextIndexes = $stmt->fetchAll();
        
        $availableIndexes = [];
        foreach ($fulltextIndexes as $index) {
            $availableIndexes[$index['Key_name']] = $index['Column_name'];
        }
        
        // Выбираем стратегию в зависимости от поля
        switch ($field) {
            case 'title':
                if (isset($availableIndexes['ft_title']) || isset($availableIndexes['ft_title_author'])) {
                    return 'fulltext';
                }
                break;
                
            case 'author':
                if (isset($availableIndexes['ft_author']) || isset($availableIndexes['ft_title_author'])) {
                    return 'fulltext';
                }
                break;
                
            case 'genre':
                if (isset($availableIndexes['ft_genre'])) {
                    return 'fulltext';
                }
                break;
                
            case 'series':
                if (isset($availableIndexes['ft_series'])) {
                    return 'fulltext';
                }
                break;
                
            case 'all':
                // Для поиска по всем полям нужен общий индекс
                if (isset($availableIndexes['ft_search'])) {
                    return 'fulltext';
                }
                break;
        }
        
    } catch (Exception $e) {
        error_log("Error checking indexes: " . $e->getMessage());
    }
    
    return 'generic';
}




/**
 * Оптимизированный поиск книг с учетом типа БД
 */



public function searchBooks($query, $field = 'all', $page = 1, $perPage = null) {
    if ($perPage === null) {
        $perPage = Config::ITEMS_PER_PAGE;
    }
    
    // Создаем ключ кэша
    $cacheKey = $this->getCacheKey('search_books', [
        'query' => $query,
        'field' => $field,
        'page' => $page,
        'perPage' => $perPage
    ]);
    
    // Пробуем получить из кэша
    if (Config::PERFORMANCE['enable_db_cache']) {
        $cached = Cache::get($cacheKey, 'search_results');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;
    }
    
    // Выполняем запрос
    $offset = (int)(($page - 1) * $perPage);
    $perPage = min((int)$perPage, 100);
    
    $result = [];
    
    // Упрощенная логика: всегда используем LIKE для совместимости
    $result = $this->searchBooksGeneric($query, $field, $page, $perPage);
    
    // Сохраняем в кэш
    if (Config::PERFORMANCE['enable_db_cache']) {
        Cache::set($cacheKey, $result, 'search_results');
    }
    
    return $result;
}


/**
 * Оптимизированный поиск для MySQL с FULLTEXT
 */

private function searchBooksMySQL($query, $field = 'all', $page = 1, $perPage = 20) {
    $offset = (int)(($page - 1) * $perPage);
    
    if (empty($query)) {
        // Если запрос пустой, возвращаем последние книги
        return $this->searchBooksGeneric($query, $field, $page, $perPage);
    }
    
    $searchQuery = $this->prepareFulltextQuery($query);
    $params = [$searchQuery];
    
    // Используем FULLTEXT индекс для поиска по всем полям
    $sql = "SELECT * FROM books WHERE MATCH(title, author, genre, series) AGAINST(? IN BOOLEAN MODE)";
    
    // Если нужно фильтровать по конкретному полю, добавляем дополнительное условие
    if ($field !== 'all') {
        switch ($field) {
            case 'author':
                $sql .= " AND author LIKE ?";
                $params[] = "%$query%";
                break;
            case 'title':
                $sql .= " AND title LIKE ?";
                $params[] = "%$query%";
                break;
            case 'genre':
                $sql .= " AND genre LIKE ?";
                $params[] = "%$query%";
                break;
            case 'series':
                $sql .= " AND series LIKE ?";
                $params[] = "%$query%";
                break;
        }
    }
    
    $sql .= " ORDER BY added_date DESC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    
    try {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        // Если FULLTEXT поиск не работает, используем LIKE
        error_log("FULLTEXT search failed: " . $e->getMessage() . ". Falling back to LIKE search.");
        return $this->searchBooksGeneric($query, $field, $page, $perPage);
    }
}

/**
 * Поиск для других БД (SQLite и др.)
 */

private function searchBooksGeneric($query, $field = 'all', $page = 1, $perPage = 20) {
    $offset = (int)(($page - 1) * $perPage);
    $params = [];
    
    $sql = "SELECT * FROM books WHERE 1=1";
    
    if (!empty($query)) {
        // Используем префиксный поиск для лучшей производительности с индексами
        switch ($field) {
            case 'author':
                $sql .= " AND author LIKE ?";
                $params[] = "$query%";  // Префиксный поиск для использования индекса
                break;
                
            case 'title':
                $sql .= " AND title LIKE ?";
                $params[] = "$query%";  // Префиксный поиск
                break;
                
            case 'genre':
                $sql .= " AND genre LIKE ?";
                $params[] = "$query%";  // Префиксный поиск
                break;
                
            case 'series':
                $sql .= " AND series LIKE ?";
                $params[] = "$query%";  // Префиксный поиск
                break;
                
            case 'all':
            default:
                // Для поиска по всем полям пробуем разные стратегии
                if (strlen($query) >= 3) {
                    // Для длинных запросов используем несколько OR условий
                    $sql .= " AND (author LIKE ? OR title LIKE ? OR genre LIKE ? OR series LIKE ?)";
                    $params[] = "$query%";
                    $params[] = "$query%";
                    $params[] = "$query%";
                    $params[] = "$query%";
                } else {
                    // Для коротких запросов используем более точный поиск
                    $sql .= " AND (author LIKE ? OR title LIKE ?)";
                    $params[] = "$query%";
                    $params[] = "$query%";
                }
                break;
        }
    }
    
    $sql .= " ORDER BY added_date DESC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    
    try {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Generic search failed: " . $e->getMessage());
        // В крайнем случае возвращаем пустой массив
        return [];
    }
}


/**
 * Создать оптимальные индексы для поиска
 */
public function createOptimalIndexes() {
    if (Config::DB_TYPE !== 'mysql') {
        return false;
    }
    
    try {
        // Удаляем существующие FULLTEXT индексы если есть
        $stmt = $this->executeQuery("SHOW INDEXES FROM books WHERE Index_type = 'FULLTEXT'");
        $existingIndexes = $stmt->fetchAll();
        
        foreach ($existingIndexes as $index) {
            $this->executeQuery("ALTER TABLE books DROP INDEX {$index['Key_name']}");
            error_log("Удален FULLTEXT индекс: {$index['Key_name']}");
        }
        
        // Создаем отдельные FULLTEXT индексы для каждого поля
        $indexes = [
            "ft_title" => "title",
            "ft_author" => "author", 
            "ft_genre" => "genre",
            "ft_series" => "series",
            "ft_title_author" => "title, author", // Комбинированный индекс для поиска по title и author
        ];
        
        foreach ($indexes as $name => $columns) {
            try {
                $this->executeQuery("ALTER TABLE books ADD FULLTEXT {$name} ({$columns})");
                error_log("Создан FULLTEXT индекс: {$name} для колонок: {$columns}");
            } catch (Exception $e) {
                error_log("Не удалось создать индекс {$name}: " . $e->getMessage());
            }
        }
        
        // Также создаем BTREE индексы для префиксного поиска
        $btreeIndexes = [
            "idx_author_prefix" => "author(100)",
            "idx_title_prefix" => "title(100)",
            "idx_genre_prefix" => "genre(50)",
            "idx_series_prefix" => "series(100)",
        ];
        
        foreach ($btreeIndexes as $name => $column) {
            try {
                $this->executeQuery("CREATE INDEX IF NOT EXISTS {$name} ON books ({$column})");
                error_log("Создан BTREE индекс: {$name}");
            } catch (Exception $e) {
                error_log("Не удалось создать BTREE индекс {$name}: " . $e->getMessage());
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Ошибка при создании индексов: " . $e->getMessage());
        return false;
    }
}















/**
 * Подготовить запрос для FULLTEXT поиска
 */
private function prepareFulltextQuery($query) {
    // Разбиваем запрос на слова
    $words = preg_split('/\s+/', trim($query));
    $preparedWords = [];
    
    foreach ($words as $word) {
        $word = trim($word);
        if (strlen($word) >= 3) { // Минимальная длина для FULLTEXT
            $preparedWords[] = "+$word*";
        }
    }
    
    if (empty($preparedWords)) {
        // Если все слова слишком короткие, ищем как есть
        return $query;
    }
    
    return implode(' ', $preparedWords);
}


    
    /**
     * Получить книгу по ID
     */
    public function getBook($id) {
        $cacheKey = $this->getCacheKey('book', ['id' => $id]);
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'book_data');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery("SELECT * FROM books WHERE id = ?", [$id]);
        $result = $stmt->fetch();
        
        if (Config::PERFORMANCE['enable_db_cache'] && $result) {
            Cache::set($cacheKey, $result, 'book_data');
        }
        
        return $result;
    }
    
    /**
     * Получить последние добавленные книги
     */
    public function getRecentBooks($limit = 10, $offset = 0) {
        $limit = min((int)$limit, 100);
        $offset = (int)$offset;
        
        $cacheKey = $this->getCacheKey('recent_books', [
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'search_results');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $sql = "SELECT * FROM books ORDER BY added_date DESC LIMIT ? OFFSET ?";
        $stmt = $this->executeQuery($sql, [$limit, $offset]);
        $result = $stmt->fetchAll();
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $result, 'search_results');
        }
        
        return $result;
    }
    
    /**
     * Получить количество книг для поиска
     */

/**
 * Получить количество книг для поиска (оптимизированная версия)
 */
public function getSearchCount($query, $field = 'all') {
    $cacheKey = $this->getCacheKey('search_count', [
        'query' => $query,
        'field' => $field
    ]);
    
    if (Config::PERFORMANCE['enable_db_cache']) {
        $cached = Cache::get($cacheKey, 'statistics');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;
    }
    
    $count = 0;
    
    try {
        if (Config::DB_TYPE === 'mysql' && !empty($query)) {
            // Проверяем наличие FULLTEXT индекса
            $stmt = $this->executeQuery("SHOW INDEXES FROM books WHERE Index_type = 'FULLTEXT' LIMIT 1");
            $hasFulltext = $stmt->fetch() !== false;
            
            if ($hasFulltext) {
                $count = $this->getSearchCountMySQL($query, $field);
            } else {
                $count = $this->getSearchCountGeneric($query, $field);
            }
        } else {
            $count = $this->getSearchCountGeneric($query, $field);
        }
    } catch (Exception $e) {
        error_log("Error in getSearchCount: " . $e->getMessage());
        $count = $this->getSearchCountGeneric($query, $field);
    }
    
    $maxCount = Config::PERFORMANCE['max_search_results'];
    if ($count > $maxCount) {
        $count = $maxCount;
    }
    
    if (Config::PERFORMANCE['enable_db_cache']) {
        Cache::set($cacheKey, $count, 'statistics');
    }
    
    return $count;
}

/**
 * Подсчет результатов поиска для MySQL с FULLTEXT
 */
private function getSearchCountMySQL($query, $field = 'all') {
    $searchQuery = $this->prepareFulltextQuery($query);
    $params = [$searchQuery];
    
    // Для FULLTEXT индекса ft_search (title, author, genre, series)
    // Можем искать только по всем полям или создавать отдельные условия
    $sql = "SELECT COUNT(*) as count FROM books WHERE MATCH(title, author, genre, series) AGAINST(? IN BOOLEAN MODE)";
    
    // Если нужно фильтровать по конкретному полю, добавляем дополнительное условие
    if ($field !== 'all') {
        switch ($field) {
            case 'author':
                $sql .= " AND author LIKE ?";
                $params[] = "%$query%";
                break;
            case 'title':
                $sql .= " AND title LIKE ?";
                $params[] = "%$query%";
                break;
            case 'genre':
                $sql .= " AND genre LIKE ?";
                $params[] = "%$query%";
                break;
            case 'series':
                $sql .= " AND series LIKE ?";
                $params[] = "%$query%";
                break;
        }
    }
    
    try {
        $stmt = $this->executeQuery($sql, $params);
        $result = $stmt->fetch();
        return $result['count'];
    } catch (Exception $e) {
        // Если FULLTEXT не работает, используем обычный поиск
        error_log("FULLTEXT count failed: " . $e->getMessage());
        return $this->getSearchCountGeneric($query, $field);
    }
}

/**
 * Подсчет результатов для generic поиска (LIKE)
 */
private function getSearchCountGeneric($query, $field = 'all') {
    $params = [];
    $sql = "SELECT COUNT(*) as count FROM books WHERE 1=1";
    
    if (!empty($query)) {
        // Используем префиксный поиск для использования индексов
        switch ($field) {
            case 'author':
                $sql .= " AND author LIKE ?";
                $params[] = "$query%";
                break;
            case 'title':
                $sql .= " AND title LIKE ?";
                $params[] = "$query%";
                break;
            case 'genre':
                $sql .= " AND genre LIKE ?";
                $params[] = "$query%";
                break;
            case 'series':
                $sql .= " AND series LIKE ?";
                $params[] = "$query%";
                break;
            case 'all':
            default:
                // Для поиска по всем полям используем FULLTEXT если доступен
                // иначе используем LIKE с OR (медленно, но работает)
                if (Config::DB_TYPE === 'mysql') {
                    // Пробуем использовать FULLTEXT для поиска по всем полям
                    try {
                        $searchQuery = $this->prepareFulltextQuery($query);
                        $stmt = $this->executeQuery(
                            "SELECT COUNT(*) as count FROM books WHERE MATCH(title, author, genre, series) AGAINST(? IN BOOLEAN MODE)",
                            [$searchQuery]
                        );
                        $result = $stmt->fetch();
                        return $result['count'];
                    } catch (Exception $e) {
                        // Fallback to LIKE
                        $sql .= " AND (title LIKE ? OR author LIKE ? OR genre LIKE ? OR series LIKE ?)";
                        $params[] = "$query%";
                        $params[] = "$query%";
                        $params[] = "$query%";
                        $params[] = "$query%";
                    }
                } else {
                    $sql .= " AND (title LIKE ? OR author LIKE ? OR genre LIKE ? OR series LIKE ?)";
                    $params[] = "$query%";
                    $params[] = "$query%";
                    $params[] = "$query%";
                    $params[] = "$query%";
                }
                break;
        }
    }
    
    $stmt = $this->executeQuery($sql, $params);
    $result = $stmt->fetch();
    return $result['count'];
}





    
    /**
     * Получить все авторы
     */
    public function getAuthors() {
        $cacheKey = 'authors_list';
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'author_list');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery(
            "SELECT DISTINCT author FROM books WHERE author IS NOT NULL AND author != '' ORDER BY author LIMIT 5000"
        );
        $result = $stmt->fetchAll();
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $result, 'author_list');
        }
        
        return $result;
    }
    
    /**
     * Получить все жанры
     */
    public function getGenres() {
        $cacheKey = 'genres_list';
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'genre_list');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery(
            "SELECT DISTINCT genre FROM books WHERE genre IS NOT NULL AND genre != '' ORDER BY genre LIMIT 1000"
        );
        $result = $stmt->fetchAll();
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $result, 'genre_list');
        }
        
        return $result;
    }
    
    /**
     * Получить все серии
     */
    public function getSeries() {
        $cacheKey = 'series_list';
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'series_list');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery(
            "SELECT DISTINCT series FROM books WHERE series IS NOT NULL AND series != '' ORDER BY series LIMIT 5000"
        );
        $result = $stmt->fetchAll();
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $result, 'series_list');
        }
        
        return $result;
    }
    
    /**
     * Получить статистику коллекции
     */

public function getCollectionStats() {
    $cacheKey = 'collection_stats';
    
    if (Config::PERFORMANCE['enable_db_cache']) {
        $cached = Cache::get($cacheKey, 'statistics');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;
    }
    
    $stats = [];
    
    try {
        // Общее количество книг
        $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books");
        $result = $stmt->fetch();
        $stats['total_books'] = $result['count'];
        
        // Количество авторов
        $stmt = $this->executeQuery(
            "SELECT COUNT(DISTINCT author) as count FROM books WHERE author IS NOT NULL AND author != ''"
        );
        $result = $stmt->fetch();
        $stats['total_authors'] = $result['count'];
        
        // Количество жанров
        $stmt = $this->executeQuery(
            "SELECT COUNT(DISTINCT genre) as count FROM books WHERE genre IS NOT NULL AND genre != ''"
        );
        $result = $stmt->fetch();
        $stats['total_genres'] = $result['count'];
        
        // Количество серий
        $stmt = $this->executeQuery(
            "SELECT COUNT(DISTINCT series) as count FROM books WHERE series IS NOT NULL AND series != ''"
        );
        $result = $stmt->fetch();
        $stats['total_series'] = $result['count'];
        
        // Последнее обновление
        $stmt = $this->executeQuery("SELECT MAX(added_date) as last_update FROM books");
        $result = $stmt->fetch();
        $stats['last_update'] = $result['last_update'];
        
        // Статистика по форматам файлов (исправлено для ONLY_FULL_GROUP_BY)
        $stmt = $this->executeQuery("
            SELECT file_type, COUNT(*) as count 
            FROM books 
            WHERE file_type IS NOT NULL 
            GROUP BY file_type 
            ORDER BY count DESC
            LIMIT 10
        ");
        $stats['file_types'] = $stmt->fetchAll();
        
        // Книги в архивах
        $stmt = $this->executeQuery(
            "SELECT COUNT(*) as count FROM books WHERE archive_path IS NOT NULL AND archive_path != ''"
        );
        $result = $stmt->fetch();
        $stats['books_in_archives'] = $result['count'];
        
        // Для MySQL добавляем информацию о размере таблицы
        if (Config::DB_TYPE === 'mysql') {
            $stats['table_info'] = $this->getMySQLTableInfo();
        }
        
    } catch (Exception $e) {
        error_log("Error getting collection stats: " . $e->getMessage());
        // Возвращаем базовые значения при ошибке
        $stats = array_merge($stats, [
            'total_books' => 0,
            'total_authors' => 0,
            'total_genres' => 0,
            'total_series' => 0,
            'last_update' => null,
            'file_types' => [],
            'books_in_archives' => 0
        ]);
    }
    
    if (Config::PERFORMANCE['enable_db_cache']) {
        Cache::set($cacheKey, $stats, 'statistics');
    }
    
    return $stats;
}


private function getMySQLTableInfo() {
    try {
        $sql = "
            SELECT 
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as total_mb,
                ROUND(SUM(data_length) / 1024 / 1024, 2) as data_mb,
                ROUND(SUM(index_length) / 1024 / 1024, 2) as index_mb,
                table_rows as estimated_rows
            FROM information_schema.TABLES 
            WHERE table_schema = DATABASE() 
            AND table_name = 'books'
            GROUP BY table_name, table_rows
        ";
        
        $stmt = $this->executeQuery($sql);
        $result = $stmt->fetch();
        
        return [
            'total_size_mb' => $result['total_mb'] ?? 0,
            'data_size_mb' => $result['data_mb'] ?? 0,
            'index_size_mb' => $result['index_mb'] ?? 0,
            'estimated_rows' => $result['estimated_rows'] ?? 0
        ];
        
    } catch (Exception $e) {
        error_log("Error getting MySQL table info: " . $e->getMessage());
        return null;
    }
}



    
    /**
     * Преобразовать жанр FB2 в читаемое название
     */
    public function getReadableGenre($genre) {
        if (empty($genre)) {
            return null;
        }
        
        if (in_array($genre, array_values(Config::FB2_GENRES))) {
            return $genre;
        }
        
        if (isset(Config::FB2_GENRES[$genre])) {
            return Config::FB2_GENRES[$genre];
        }
        
        return ucfirst(str_replace('_', ' ', $genre));
    }
    
    /**
     * Получить все жанры с их частотой и читаемыми названиями
     */
    public function getGenresWithCount() {
        $cacheKey = 'genres_with_count';
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'statistics');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery("
            SELECT genre, COUNT(*) as count 
            FROM books 
            WHERE genre IS NOT NULL AND genre != '' 
            GROUP BY genre 
            ORDER BY count DESC, genre
            LIMIT 100
        ");
        $genres = $stmt->fetchAll();
        
        foreach ($genres as &$genre) {
            $genre['readable_name'] = $this->getReadableGenre($genre['genre']);
        }
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $genres, 'statistics');
        }
        
        return $genres;
    }
    
    /**
     * Получить топ авторов
     */
    public function getTopAuthors($limit = 20) {
        $limit = min((int)$limit, 100);
        $cacheKey = $this->getCacheKey('top_authors', ['limit' => $limit]);
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'statistics');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery("
            SELECT author, COUNT(*) as count 
            FROM books 
            WHERE author IS NOT NULL AND author != '' 
            GROUP BY author 
            ORDER BY count DESC, author
            LIMIT ?
        ", [$limit]);
        $result = $stmt->fetchAll();
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $result, 'statistics');
        }
        
        return $result;
    }
    
    /**
     * Получить топ серий
     */
    public function getTopSeries($limit = 20) {
        $limit = min((int)$limit, 100);
        $cacheKey = $this->getCacheKey('top_series', ['limit' => $limit]);
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'statistics');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery("
            SELECT series, COUNT(*) as count 
            FROM books 
            WHERE series IS NOT NULL AND series != '' 
            GROUP BY series 
            ORDER BY count DESC, series
            LIMIT ?
        ", [$limit]);
        $result = $stmt->fetchAll();
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $result, 'statistics');
        }
        
        return $result;
    }
    
    /**
     * Получить книги по автору с пагинацией
     */
    public function getBooksByAuthor($author, $page = 1, $perPage = 20) {
        $offset = (int)(($page - 1) * $perPage);
        $perPage = min((int)$perPage, 100);
        
        $cacheKey = $this->getCacheKey('books_by_author', [
            'author' => $author,
            'page' => $page,
            'perPage' => $perPage
        ]);
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'search_results');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery("
            SELECT * FROM books 
            WHERE author = ? 
            ORDER BY series, series_number, title
            LIMIT ? OFFSET ?
        ", [$author, $perPage, $offset]);
        $result = $stmt->fetchAll();
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $result, 'search_results');
        }
        
        return $result;
    }
    
    /**
     * Получить количество книг по автору
     */
    public function getBooksCountByAuthor($author) {
        $cacheKey = $this->getCacheKey('books_count_by_author', ['author' => $author]);
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'statistics');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books WHERE author = ?", [$author]);
        $result = $stmt->fetch();
        $count = $result['count'];
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $count, 'statistics');
        }
        
        return $count;
    }
    
    /**
     * Получить книги по жанру с пагинацией
     */
    public function getBooksByGenre($genre, $page = 1, $perPage = 20) {
        $offset = (int)(($page - 1) * $perPage);
        $perPage = min((int)$perPage, 100);
        
        $cacheKey = $this->getCacheKey('books_by_genre', [
            'genre' => $genre,
            'page' => $page,
            'perPage' => $perPage
        ]);
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'search_results');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery("
            SELECT * FROM books 
            WHERE genre = ? 
            ORDER BY author, title
            LIMIT ? OFFSET ?
        ", [$genre, $perPage, $offset]);
        $result = $stmt->fetchAll();
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $result, 'search_results');
        }
        
        return $result;
    }
    
    /**
     * Получить количество книг по жанру
     */
    public function getBooksCountByGenre($genre) {
        $cacheKey = $this->getCacheKey('books_count_by_genre', ['genre' => $genre]);
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'statistics');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books WHERE genre = ?", [$genre]);
        $result = $stmt->fetch();
        $count = $result['count'];
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $count, 'statistics');
        }
        
        return $count;
    }
    
    /**
     * Получить случайные книги
     */
    public function getRandomBooks($limit = 10) {
        $limit = min((int)$limit, 50);
        $cacheKey = $this->getCacheKey('random_books', ['limit' => $limit]);
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'search_results');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        if (Config::DB_TYPE === 'mysql') {
            $randomFunc = 'RAND()';
        } else {
            $randomFunc = 'RANDOM()';
        }
        
        $stmt = $this->executeQuery("
            SELECT * FROM books 
            ORDER BY {$randomFunc} 
            LIMIT ?
        ", [$limit]);
        $result = $stmt->fetchAll();
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $result, 'search_results');
        }
        
        return $result;
    }
    
    /**
     * Получить книги по серии с пагинацией
     */
    public function getBooksBySeries($series, $page = 1, $perPage = 20) {
        $offset = (int)(($page - 1) * $perPage);
        $perPage = min((int)$perPage, 100);
        
        $cacheKey = $this->getCacheKey('books_by_series', [
            'series' => $series,
            'page' => $page,
            'perPage' => $perPage
        ]);
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'search_results');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery("
            SELECT * FROM books 
            WHERE series = ? 
            ORDER BY series_number, title
            LIMIT ? OFFSET ?
        ", [$series, $perPage, $offset]);
        $result = $stmt->fetchAll();
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $result, 'search_results');
        }
        
        return $result;
    }
    
    /**
     * Получить количество книг по серии
     */
    public function getBooksCountBySeries($series) {
        $cacheKey = $this->getCacheKey('books_count_by_series', ['series' => $series]);
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'statistics');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books WHERE series = ?", [$series]);
        $result = $stmt->fetch();
        $count = $result['count'];
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $count, 'statistics');
        }
        
        return $count;
    }
    
    /**
     * Проверить существование книги
     */
    public function bookExists($filePath, $archivePath = null, $internalPath = null) {
        // Не кэшируем этот запрос, так как он используется при сканировании
        $sql = "SELECT id FROM books WHERE file_path = ?";
        $params = [$filePath];
        
        if ($archivePath) {
            $sql .= " AND archive_path = ?";
            $params[] = $archivePath;
        } else {
            $sql .= " AND archive_path IS NULL";
        }
        
        if ($internalPath) {
            $sql .= " AND archive_internal_path = ?";
            $params[] = $internalPath;
        } else {
            $sql .= " AND archive_internal_path IS NULL";
        }
        
        $sql .= " LIMIT 1";
        
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Получить общее количество книг
     */
    public function getTotalBooksCount() {
        $cacheKey = 'total_books_count';
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'statistics');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books");
        $result = $stmt->fetch();
        $count = $result['count'];
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $count, 'statistics');
        }
        
        return $count;
    }
    
    /**
     * Получить книги с пагинацией
     */
    public function getBooksWithPagination($page = 1, $perPage = 20, $orderBy = 'added_date', $orderDir = 'DESC') {
        $offset = (int)(($page - 1) * $perPage);
        $perPage = min((int)$perPage, 100);
        
        $cacheKey = $this->getCacheKey('books_pagination', [
            'page' => $page,
            'perPage' => $perPage,
            'orderBy' => $orderBy,
            'orderDir' => $orderDir
        ]);
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            $cached = Cache::get($cacheKey, 'search_results');
            if ($cached !== null) {
                $this->cacheHits++;
                return $cached;
            }
            $this->cacheMisses++;
        }
        
        $allowedOrders = ['added_date', 'title', 'author', 'year'];
        $allowedDirs = ['ASC', 'DESC'];
        
        $orderBy = in_array($orderBy, $allowedOrders) ? $orderBy : 'added_date';
        $orderDir = in_array($orderDir, $allowedDirs) ? $orderDir : 'DESC';
        
        $stmt = $this->executeQuery("
            SELECT * FROM books 
            ORDER BY {$orderBy} {$orderDir}
            LIMIT ? OFFSET ?
        ", [$perPage, $offset]);
        $result = $stmt->fetchAll();
        
        if (Config::PERFORMANCE['enable_db_cache']) {
            Cache::set($cacheKey, $result, 'search_results');
        }
        
        return $result;
    }
    
    /**
     * Очистить кэш (для админки)
     */
    public function clearCache() {
        Cache::clear();
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        return true;
    }
    
    /**
     * Инвалидировать кэш для определенных типов данных
     */
    public function invalidateCache($types = []) {
        if (empty($types)) {
            return $this->clearCache();
        }
        
        // Реализация инвалидации по типам может быть добавлена позже
        return true;
    }


/**
 * Проверить наличие индексов и оптимизировать таблицы
 */
public function checkAndOptimizeIndexes() {
    if (Config::DB_TYPE !== 'mysql') {
        return false;
    }
    
    try {
        // 1. Создаем обычные индексы для часто используемых колонок
        $indexes = [
            'idx_author' => 'author',
            'idx_title' => 'title',
            'idx_genre' => 'genre',
            'idx_series' => 'series',
            'idx_added_date' => 'added_date',
        ];
        
        $stmt = $this->executeQuery("SHOW INDEXES FROM books");
        $existingIndexes = $stmt->fetchAll();
        $existingIndexNames = array_column($existingIndexes, 'Key_name');
        
        foreach ($indexes as $indexName => $column) {
            if (!in_array($indexName, $existingIndexNames)) {
                $this->executeQuery("CREATE INDEX {$indexName} ON books ({$column})");
                error_log("Индекс {$indexName} создан");
            }
        }
        
        // 2. Создаем FULLTEXT индекс для поиска
        $fulltextColumns = ['title', 'author', 'genre', 'series'];
        $columnList = implode(', ', $fulltextColumns);
        
        // Удаляем старые FULLTEXT индексы если есть
        foreach ($existingIndexes as $index) {
            if ($index['Index_type'] === 'FULLTEXT') {
                $this->executeQuery("ALTER TABLE books DROP INDEX {$index['Key_name']}");
                error_log("Старый FULLTEXT индекс {$index['Key_name']} удален");
            }
        }
        
        // Создаем новый FULLTEXT индекс
        $this->executeQuery("ALTER TABLE books ADD FULLTEXT ft_search ({$columnList})");
        error_log("FULLTEXT индекс ft_search создан для колонок: {$columnList}");
        
        // 3. Оптимизируем таблицу
        $this->executeQuery("OPTIMIZE TABLE books");
        error_log("Таблица books оптимизирована");
        
        // 4. Очищаем кэш проверки индексов
        Cache::delete('fulltext_index_check');
        
        return true;
        
    } catch (Exception $e) {
        error_log("Ошибка при оптимизации индексов: " . $e->getMessage());
        return false;
    }
}




/**
 * Проверить поддержку FULLTEXT и минимальную длину слова
 */
public function checkFulltextCompatibility() {
    if (Config::DB_TYPE !== 'mysql') {
        return ['supported' => false, 'message' => 'Not MySQL'];
    }
    
    try {
        // Проверяем наличие поддержки InnoDB FULLTEXT
        $stmt = $this->executeQuery("SHOW ENGINES");
        $engines = $stmt->fetchAll();
        $innodbSupport = false;
        
        foreach ($engines as $engine) {
            if (strtolower($engine['Engine']) === 'innodb' && 
                strtolower($engine['Support']) !== 'no') {
                $innodbSupport = true;
                break;
            }
        }
        
        if (!$innodbSupport) {
            return ['supported' => false, 'message' => 'InnoDB engine not supported'];
        }
        
        // Проверяем минимальную длину слова для FULLTEXT
        $stmt = $this->executeQuery("SHOW VARIABLES LIKE 'innodb_ft_min_token_size'");
        $minTokenSize = $stmt->fetch();
        $minLength = $minTokenSize ? $minTokenSize['Value'] : '3';
        
        return [
            'supported' => true,
            'engine' => 'InnoDB',
            'min_token_size' => $minLength,
            'message' => 'FULLTEXT search is supported'
        ];
        
    } catch (Exception $e) {
        return [
            'supported' => false,
            'message' => 'Error checking compatibility: ' . $e->getMessage()
        ];
    }
}










private function ensureFulltextIndex($columns) {
    if (Config::DB_TYPE !== 'mysql') {
        return false;
    }
    
    $cacheKey = 'fulltext_index_check';
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return true;
    }
    
    try {
        // Проверяем существующие FULLTEXT индексы
        $stmt = $this->executeQuery("SHOW INDEXES FROM books WHERE Index_type = 'FULLTEXT'");
        $existingIndexes = $stmt->fetchAll();
        
        if (empty($existingIndexes)) {
            // Создаем FULLTEXT индекс для указанных колонок
            $columnList = implode(', ', $columns);
            $this->executeQuery("ALTER TABLE books ADD FULLTEXT ft_search ({$columnList})");
            error_log("FULLTEXT индекс создан для колонок: {$columnList}");
        }
        
        Cache::set($cacheKey, true, 86400); // Кэшируем на 24 часа
        return true;
        
    } catch (Exception $e) {
        error_log("Ошибка при создании FULLTEXT индекса: " . $e->getMessage());
        return false;
    }
}





}

// Сохраняем счетчик запросов в глобальную переменную для футера
$GLOBALS['query_count'] = 0;
?>