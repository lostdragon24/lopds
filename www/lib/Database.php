<?php

require_once __DIR__ . '/GenreManager.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/SecurityHelper.php';
require_once __DIR__ . '/DatabaseChecker.php';
require_once __DIR__ . '/../init.php';

class Database
{
    private $pdo;
    private static $instance = null;
    private $queryCount = 0;
    private $cacheHits = 0;
    private $cacheMisses = 0;
    private $security;
    private $isAvailable = true;
    private $lastError = null;

    private function __construct()
    {
        $this->security = SecurityHelper::getInstance();

        $checker = DatabaseChecker::getInstance();
        if (!$checker->checkDatabase()) {
            $this->isAvailable = false;
            $this->lastError = $checker->getErrorMessage();
            my_log("Database unavailable: " . $this->lastError);
            return;
        }

        $dbConfig = Config::getDbConfig();

        try {
            switch ($dbConfig['type']) {
                case 'sqlite':
                    $dbDir = dirname($dbConfig['path']);
                    if (!file_exists($dbDir)) {
                        mkdir($dbDir, 0755, true);
                    }

                    $dsn = 'sqlite:' . $dbConfig['path'];
                    $this->pdo = new PDO($dsn);
                    $this->pdo->exec('PRAGMA journal_mode = WAL');
                    $this->pdo->exec('PRAGMA synchronous = NORMAL');
                    $this->pdo->exec('PRAGMA cache = shared');
                    $this->pdo->exec('PRAGMA cache_size = -64000');
                    $this->pdo->exec('PRAGMA temp_store = memory');
                    $this->pdo->exec('PRAGMA mmap_size = 268435456');
                    break;

                case 'mysql':
                    $dsn = 'mysql:host=' . $dbConfig['host'] .
                           ';dbname=' . $dbConfig['name'] .
                           ';charset=utf8mb4';
                    $this->pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
                        PDO::ATTR_PERSISTENT => true, // Включаем persistent connection
                        PDO::ATTR_TIMEOUT => 30,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false
                    ]);

                    // Оптимизации MySQL
                    $this->pdo->exec("SET SESSION sql_mode = 'TRADITIONAL'");
                    $this->pdo->exec("SET SESSION optimizer_search_depth = 0");
                    break;

                default:
                    throw new Exception(__('error_unsupported_db_type'));
            }

            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        } catch (PDOException $e) {
            $this->isAvailable = false;
            $this->lastError = __('error_db_connection') . ': ' . $e->getMessage();
            my_log($this->lastError);
        } catch (Exception $e) {
            $this->isAvailable = false;
            $this->lastError = $e->getMessage();
            my_log($this->lastError);
        }
    }


    /**
     * Получить тип базы данных
     */
    public function getDbType()
    {
        return Config::getDbType();
    }


    /**
     * Получить общее количество книг (с кэшированием)
     */
    public function getTotalBooksCount()
    {
        $cacheKey = 'total_books_count_v2';

        $cached = Cache::get($cacheKey, 'statistics');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;

        try {
            // Используем приблизительное значение для MySQL для скорости
            //    if (Config::isMysql() === 'mysql') {
            //            $stmt = $this->executeQuery("SELECT TABLE_ROWS FROM information_schema.TABLES
            //                                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'books'");
            //            $result = $stmt->fetch();
            //            $count = $result['TABLE_ROWS'] ?? 0;
            //        } else {
            $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books");
            $result = $stmt->fetch();
            $count = $result['count'] ?? 0;
            //    }
        } catch (Exception $e) {
            my_log("Error getting total books count: " . $e->getMessage());
            $count = 0;
        }

        Cache::set($cacheKey, $count, 'statistics', 3600); // Кэш на час

        return $count;
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Безопасное выполнение запроса с параметрами
     */
    private function executeQuery($sql, $params = [])
    {
        if (!$this->isAvailable()) {
            my_log("Query skipped - database unavailable: " . $sql);
            return false;
        }

        $this->queryCount++;

        if (Config::isQuerylogging() && Config::isDevelopment()) {
            my_log("DB Query: " . $sql);
            my_log("Params: " . json_encode($params));
        }

        $startTime = microtime(true);

        try {
            $stmt = $this->pdo->prepare($sql);

            // ===== ВАЖНО: правильная передача параметров =====
            foreach ($params as $index => $value) {
                $paramType = PDO::PARAM_STR;
                if (is_int($value)) {
                    $paramType = PDO::PARAM_INT;
                } elseif (is_bool($value)) {
                    $paramType = PDO::PARAM_BOOL;
                } elseif (is_null($value)) {
                    $paramType = PDO::PARAM_NULL;
                }

                // PDO использует 1-индексацию для позиционных параметров
                $stmt->bindValue($index + 1, $value, $paramType);
            }

            $stmt->execute();

            $queryTime = microtime(true) - $startTime;
            if ($queryTime > 0.5 && Config::isQuerylogging()) {
                my_log("Slow query (" . round($queryTime, 3) . "s): " . $sql);
            }

            return $stmt;

        } catch (PDOException $e) {
            my_log("Query failed: " . $sql);
            my_log("Error: " . $e->getMessage());
            throw new Exception(__('error_db_query') . ': ' . $e->getMessage());
        }
    }

    /**
     * Поиск книг с использованием FULLTEXT индекса
     */
    public function searchBooks($query, $field = 'all', $page = 1, $perPage = null, $filters = [])
    {
        if (null === $perPage) {
            $perPage = Config::getItemsPerPage();
        }

        $query = $this->security->sanitizeSearchQuery($query);
        $field = $this->security->sanitizeSearchField($field);

        $cacheKey = $this->getCacheKey('search_books_v2', [
            'query' => $query,
            'field' => $field,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => $filters
        ]);

        $cached = Cache::get($cacheKey, 'search_results');
        if (null !== $cached) {
            ++$this->cacheHits;
            return $cached;
        }
        ++$this->cacheMisses;

        $offset = (int)(($page - 1) * $perPage);
        $perPage = min((int)$perPage, 100);

        // Пытаемся использовать FULLTEXT поиск для MySQL
        if (Config::isMysql() && strlen($query) >= 3 && Config::SEARCH_OPTIMIZATION['enable_fulltext']) {
            return $this->searchBooksFulltext($query, $field, $offset, $perPage, $filters);
        }

        // Fallback на LIKE поиск
        return $this->searchBooksLike($query, $field, $offset, $perPage, $cacheKey, $filters);
    }


    /**
     * Найти коды жанров по читаемому названию
     */
    private function findGenreCodesByName($name)
    {
        $codes = [];
        $name = mb_strtolower(trim($name), 'UTF-8');

        // Получаем все жанры
        $allGenres = GenreManager::getAllGenres();

        foreach ($allGenres as $code => $readable) {
            $readableLower = mb_strtolower($readable, 'UTF-8');
            $codeLower = mb_strtolower($code, 'UTF-8');

            // Точное совпадение по названию
            if ($readableLower === $name) {
                $codes[] = $code;
            }
            // Частичное совпадение по названию (если не нашли точное)
            elseif (empty($codes) && strpos($readableLower, $name) !== false) {
                $codes[] = $code;
            }
            // Совпадение по коду (если пользователь ввёл код)
            elseif ($codeLower === $name) {
                $codes[] = $code;
            }
        }

        return array_unique($codes);
    }


    /**
     * Получить количество результатов поиска
     */
    public function getSearchCount($query, $field = 'all', $filters = [])
    {
        if (empty($query)) {
            return $this->getTotalBooksCount();
        }

        $cacheKey = $this->getCacheKey('search_count_v2', [
            'query' => $query,
            'field' => $field,
            'filters' => $filters
        ]);

        $cached = Cache::get($cacheKey, 'statistics');
        if (null !== $cached) {
            ++$this->cacheHits;
            return $cached;
        }
        ++$this->cacheMisses;

        $query = $this->security->sanitizeSearchQuery($query);
        $field = $this->security->sanitizeSearchField($field);

        // Используем приблизительный подсчет для больших таблиц
        if (Config::isMysql() && $this->getTotalBooksCount() > 10000) {
            try {
                $searchTerms = $this->prepareFulltextTerms($query);
                $sql = 'SELECT COUNT(*) as count FROM books
                    WHERE MATCH(title, author, genre, series) AGAINST(? IN BOOLEAN MODE)';
                $params = [$searchTerms];

                // Фильтры
                if (!empty($filters['lang'])) {
                    $sql .= ' AND language = ?';
                    $params[] = $filters['lang'];
                }

                if (!empty($filters['format'])) {
                    $sql .= ' AND file_type = ?';
                    $params[] = $filters['format'];
                }

                $stmt = $this->executeQuery($sql, $params);
                $result = $stmt->fetch();
                $count = $result['count'] ?? 0;

                Cache::set($cacheKey, $count, 'statistics');
                return $count;

            } catch (Exception $e) {
                // Fallback на LIKE
            }
        }

        // LIKE подсчет
        $sql = 'SELECT COUNT(*) as count FROM books WHERE 1=1';
        $params = [];

        if (!empty($query)) {
            $searchTerm = "%{$query}%";

            switch ($field) {
                case 'author':
                    $sql .= ' AND author LIKE ?';
                    $params[] = $searchTerm;
                    break;
                case 'title':
                    $sql .= ' AND title LIKE ?';
                    $params[] = $searchTerm;
                    break;
                case 'genre':
                    $sql .= ' AND genre LIKE ?';
                    $params[] = $searchTerm;
                    break;
                case 'series':
                    $sql .= ' AND series LIKE ?';
                    $params[] = $searchTerm;
                    break;
                default:
                    $sql .= ' AND (author LIKE ? OR title LIKE ? OR genre LIKE ? OR series LIKE ?)';
                    $params = array_fill(0, 4, $searchTerm);
                    break;
            }
        }

        // Фильтры
        if (!empty($filters['lang'])) {
            $sql .= ' AND language = ?';
            $params[] = $filters['lang'];
        }

        if (!empty($filters['format'])) {
            $sql .= ' AND file_type = ?';
            $params[] = $filters['format'];
        }

        $stmt = $this->executeQuery($sql, $params);
        $result = $stmt->fetch();
        $count = $result['count'] ?? 0;

        Cache::set($cacheKey, $count, 'statistics');

        return $count;
    }

    /**
     * FULLTEXT поиск (быстрый)
     */
    private function searchBooksFulltext($query, $field, $offset, $perPage, $filters = [])
    {
        try {
            $searchTerms = $this->prepareFulltextTerms($query);

            $sql = 'SELECT *, MATCH(title, author, genre, series) AGAINST(? IN BOOLEAN MODE) as relevance
                FROM books
                WHERE MATCH(title, author, genre, series) AGAINST(? IN BOOLEAN MODE)';

            $params = [$searchTerms, $searchTerms];

            // Фильтры
            if (!empty($filters['lang'])) {
                $sql .= ' AND language = ?';
                $params[] = $filters['lang'];
            }

            if (!empty($filters['format'])) {
                $sql .= ' AND file_type = ?';
                $params[] = $filters['format'];
            }

            if ($field !== 'all') {
                $sql .= " AND $field LIKE ?";
                $params[] = "%{$query}%";
            }

            $orderBy = $this->buildOrderBy($filters['sort'] ?? 'new');
            $sql .= " {$orderBy} LIMIT ? OFFSET ?";
            $params[] = $perPage;
            $params[] = $offset;

            $stmt = $this->executeQuery($sql, $params);
            return $stmt->fetchAll();

        } catch (Exception $e) {
            error_log('Fulltext search failed, falling back to LIKE: ' . $e->getMessage());
            return $this->searchBooksLike($query, $field, $offset, $perPage, null, $filters);
        }
    }

    /**
     * LIKE поиск (медленный, но надежный)
     */
    private function searchBooksLike($query, $field, $offset, $perPage, $cacheKey = null, $filters = [])
    {
        $sql = 'SELECT * FROM books WHERE 1=1';
        $params = [];

        // ===== ПОИСК ПО ЗАПРОСУ (как работало) =====
        if (!empty($query)) {
            $searchTerm = "%{$query}%";

            switch ($field) {
                case 'author':
                    $sql .= ' AND author LIKE ?';
                    $params[] = $searchTerm;
                    break;
                case 'title':
                    $sql .= ' AND title LIKE ?';
                    $params[] = $searchTerm;
                    break;
                case 'genre':
                    $sql .= ' AND genre LIKE ?';
                    $params[] = $searchTerm;
                    break;
                case 'series':
                    $sql .= ' AND series LIKE ?';
                    $params[] = $searchTerm;
                    break;
                default:
                    $sql .= ' AND (author LIKE ? OR title LIKE ? OR genre LIKE ? OR series LIKE ?)';
                    $params = array_fill(0, 4, $searchTerm);
                    break;
            }
        }

        // ===== ФИЛЬТРЫ (добавляем аккуратно) =====
        if (!empty($filters['lang'])) {
            $sql .= ' AND language = ?';
            $params[] = $filters['lang'];
        }

        if (!empty($filters['format'])) {
            $sql .= ' AND file_type = ?';
            $params[] = $filters['format'];
        }

        // ===== СОРТИРОВКА =====
        $orderBy = $this->buildOrderBy($filters['sort'] ?? 'new');
        $sql .= " {$orderBy} LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->executeQuery($sql, $params);
        $result = $stmt->fetchAll();

        if ($cacheKey) {
            Cache::set($cacheKey, $result, 'search_results');
        }

        return $result;
    }

    /**
     * Подготовка терминов для FULLTEXT поиска
     */
    private function prepareFulltextTerms($query)
    {
        $words = preg_split('/\s+/', trim($query));
        $terms = [];

        foreach ($words as $word) {
            if (strlen($word) >= 3) {
                $terms[] = '+' . $word . '*';
            }
        }

        return implode(' ', $terms);
    }


    /**
     * Получить книгу по ID (с кэшированием)
     */
    public function getBook($id)
    {
        $id = (int)$id;

        $cacheKey = $this->getCacheKey('book_v2', ['id' => $id]);

        $cached = Cache::get($cacheKey, 'book_data');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;

        $stmt = $this->executeQuery("SELECT * FROM books WHERE id = ?", [$id]);
        $result = $stmt->fetch();

        if ($result) {
            Cache::set($cacheKey, $result, 'book_data', 3600);
        }

        return $result;
    }

    /**
     * Получить последние добавленные книги (оптимизировано)
     */
    public function getRecentBooks($limit = 10, $offset = 0, $filters = [])
    {
        $limit = min((int)$limit, 100);
        $offset = (int)$offset;

        $cacheKey = $this->getCacheKey('recent_books_v2', [
            'limit' => $limit,
            'offset' => $offset,
            'filters' => $filters
        ]);

        $cached = Cache::get($cacheKey, 'search_results');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;

        $sql = "SELECT * FROM books WHERE 1=1";
        $params = [];

        // Фильтры
        if (!empty($filters['lang'])) {
            $sql .= ' AND language = ?';
            $params[] = $filters['lang'];
        }

        if (!empty($filters['format'])) {
            $sql .= ' AND file_type = ?';
            $params[] = $filters['format'];
        }

        $orderBy = $this->buildOrderBy($filters['sort'] ?? 'new');
        $sql .= " {$orderBy} LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->executeQuery($sql, $params);
        $result = $stmt->fetchAll();

        Cache::set($cacheKey, $result, 'search_results', 300);

        return $result;
    }

    /**
     * Получить рейтинги для нескольких книг одним запросом
     */
    public function getRatingsForBooks($bookIds)
    {
        if (empty($bookIds)) {
            return [];
        }

        // Сортируем ID для консистентности ключа
        sort($bookIds);
        $cacheKey = 'ratings_batch_' . md5(implode(',', $bookIds));

        $cached = Cache::get($cacheKey, 'statistics');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;

        $placeholders = implode(',', array_fill(0, count($bookIds), '?'));

        $sql = "SELECT
                book_id,
                COUNT(*) as votes,
                AVG(rating) as average
            FROM book_ratings
            WHERE book_id IN ($placeholders)
            GROUP BY book_id";

        $stmt = $this->executeQuery($sql, $bookIds);
        $results = $stmt->fetchAll();

        $ratings = [];
        foreach ($results as $row) {
            $ratings[$row['book_id']] = [
                'votes' => (int)$row['votes'],
                'average' => (float)$row['average'],
                'average_rounded' => round((float)$row['average'] * 2) / 2
            ];
        }

        // Добавляем пустые рейтинги для книг без оценок
        foreach ($bookIds as $id) {
            if (!isset($ratings[$id])) {
                $ratings[$id] = [
                    'votes' => 0,
                    'average' => 0,
                    'average_rounded' => 0
                ];
            }
        }

        Cache::set($cacheKey, $ratings, 'statistics', 300); // Кэш на 5 минут

        // Индексируем ключ для последующей инвалидации
        $this->indexRatingBatchKey($cacheKey);

        return $ratings;
    }

    /**
     * Получить статус избранного для нескольких книг одним запросом
     */
    public function getFavoritesForBooks($bookIds, $userIp)
    {
        if (empty($bookIds)) {
            return [];
        }

        $cacheKey = 'favorites_batch_' . md5(implode(',', $bookIds) . '_' . $userIp);

        $cached = Cache::get($cacheKey, 'statistics');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;

        $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
        $params = array_merge($bookIds, [$userIp]);

        $sql = "SELECT book_id 
                FROM book_favorites 
                WHERE book_id IN ($placeholders) AND user_ip = ?";

        $stmt = $this->executeQuery($sql, $params);
        $results = $stmt->fetchAll();

        $favorites = [];
        foreach ($results as $row) {
            $favorites[$row['book_id']] = true;
        }

        Cache::set($cacheKey, $favorites, 'statistics', 300); // Кэш на 5 минут

        return $favorites;
    }

    /**
     * Получить статистику коллекции (оптимизированная версия)
     */
    public function getCollectionStats()
    {
        $cacheKey = 'collection_stats_v4'; // Обновляем версию

        $cached = Cache::get($cacheKey, 'statistics');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;

        $dbType = Config::getDbType();

        if ($dbType === 'mysql') {
            // Для MySQL используем более быстрые запросы
            $stats = $this->getMySQLStatsOptimized();
        } else {
            // Для SQLite
            $stats = $this->getSQLiteStats();
        }

        // Статистика по форматам (общая для обоих типов БД)
        $stmt = $this->executeQuery("
        SELECT file_type, COUNT(*) as count
        FROM books
        WHERE file_type IS NOT NULL
        GROUP BY file_type
        ORDER BY count DESC
    ");
        $stats['file_types'] = $stmt->fetchAll();

        Cache::set($cacheKey, $stats, 'statistics', 7200); // Увеличиваем до 2 часов

        return $stats;
    }

    /**
     * Оптимизированная статистика для MySQL
     */
    private function getMySQLStatsOptimized()
    {
        // Используем один запрос для всей статистики
        $sql = "SELECT
                (SELECT COUNT(*) FROM books) as total_books,
                (SELECT COUNT(DISTINCT author) FROM books WHERE author IS NOT NULL AND author != '') as total_authors,
                (SELECT COUNT(DISTINCT genre) FROM books WHERE genre IS NOT NULL AND genre != '') as total_genres,
                (SELECT COUNT(DISTINCT series) FROM books WHERE series IS NOT NULL AND series != '') as total_series,
                (SELECT MAX(added_date) FROM books) as last_update,
                (SELECT COUNT(*) FROM books WHERE archive_path IS NOT NULL) as books_in_archives,
                (SELECT COUNT(*) FROM book_ratings) as total_ratings,
                (SELECT COUNT(DISTINCT book_id) FROM book_ratings) as rated_books,
                (SELECT COUNT(DISTINCT user_ip) FROM book_ratings) as unique_voters,
                (SELECT COUNT(*) FROM book_favorites) as total_favorites,
                (SELECT COUNT(DISTINCT book_id) FROM book_favorites) as favorited_books,
                (SELECT COUNT(DISTINCT user_ip) FROM book_favorites) as users_with_favorites";

        $stmt = $this->executeQuery($sql);
        $stats = $stmt->fetch();

        // Преобразуем в нужный формат
        return [
            'total_books' => (int)$stats['total_books'],
            'total_authors' => (int)$stats['total_authors'],
            'total_genres' => (int)$stats['total_genres'],
            'total_series' => (int)$stats['total_series'],
            'last_update' => $stats['last_update'],
            'books_in_archives' => (int)$stats['books_in_archives'],
            'total_ratings' => (int)$stats['total_ratings'],
            'rated_books' => (int)$stats['rated_books'],
            'unique_voters' => (int)$stats['unique_voters'],
            'total_favorites' => (int)$stats['total_favorites'],
            'favorited_books' => (int)$stats['favorited_books'],
            'users_with_favorites' => (int)$stats['users_with_favorites']
        ];
    }

    /**
     * Статистика для SQLite
     */
    private function getSQLiteStats()
    {
        $sql = "SELECT
                (SELECT COUNT(*) FROM books) as total_books,
                (SELECT COUNT(DISTINCT author) FROM books WHERE author IS NOT NULL AND author != '') as total_authors,
                (SELECT COUNT(DISTINCT genre) FROM books WHERE genre IS NOT NULL AND genre != '') as total_genres,
                (SELECT COUNT(DISTINCT series) FROM books WHERE series IS NOT NULL AND series != '') as total_series,
                (SELECT MAX(added_date) FROM books) as last_update,
                (SELECT COUNT(*) FROM books WHERE archive_path IS NOT NULL) as books_in_archives,
                (SELECT COUNT(*) FROM book_ratings) as total_ratings,
                (SELECT COUNT(DISTINCT book_id) FROM book_ratings) as rated_books,
                (SELECT COUNT(DISTINCT user_ip) FROM book_ratings) as unique_voters,
                (SELECT COUNT(*) FROM book_favorites) as total_favorites,
                (SELECT COUNT(DISTINCT book_id) FROM book_favorites) as favorited_books,
                (SELECT COUNT(DISTINCT user_ip) FROM book_favorites) as users_with_favorites";

        $stmt = $this->executeQuery($sql);
        $stats = $stmt->fetch();

        return [
            'total_books' => (int)$stats['total_books'],
            'total_authors' => (int)$stats['total_authors'],
            'total_genres' => (int)$stats['total_genres'],
            'total_series' => (int)$stats['total_series'],
            'last_update' => $stats['last_update'],
            'books_in_archives' => (int)$stats['books_in_archives'],
            'total_ratings' => (int)$stats['total_ratings'],
            'rated_books' => (int)$stats['rated_books'],
            'unique_voters' => (int)$stats['unique_voters'],
            'total_favorites' => (int)$stats['total_favorites'],
            'favorited_books' => (int)$stats['favorited_books'],
            'users_with_favorites' => (int)$stats['users_with_favorites']
        ];
    }


    /**
     * Принудительно обновить статистику (игнорируя кэш)
     */
    public function refreshCollectionStats()
    {
        $cacheKey = 'collection_stats_v4';
        Cache::delete($cacheKey);

        // Также удаляем связанные кэши
        Cache::delete('collection_stats_sidebar');
        Cache::delete('total_books_count_v2');

        return $this->getCollectionStats();
    }


    /**
     * Получить рейтинг книги
     */
    public function getBookRating($bookId)
    {
        $cacheKey = 'book_rating_' . $bookId;

        $cached = Cache::get($cacheKey, 'ratings');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;

        try {
            $stmt = $this->executeQuery(
                "SELECT
                    COUNT(*) as votes,
                    AVG(rating) as average_rating
                 FROM book_ratings
                 WHERE book_id = ?",
                [$bookId]
            );

            $result = $stmt->fetch();
            if (!$result || $result['votes'] == 0) {
                $rating = [
                    'votes' => 0,
                    'average' => 0,
                    'average_rounded' => 0,
                    'distribution' => [0, 0, 0, 0, 0]
                ];
            } else {
                $average = (float)$result['average_rating'];

                $rating = [
                    'votes' => (int)$result['votes'],
                    'average' => $average,
                    'average_rounded' => round($average * 2) / 2,
                    'distribution' => $this->getRatingDistribution($bookId)
                ];
            }

            Cache::set($cacheKey, $rating, 'ratings', 300); // Кэш на 5 минут

            return $rating;
        } catch (Exception $e) {
            my_log("Error getting book rating: " . $e->getMessage());
            return ['votes' => 0, 'average' => 0, 'average_rounded' => 0, 'distribution' => [0,0,0,0,0]];
        }
    }

    /**
     * Получить распределение оценок
     */
    private function getRatingDistribution($bookId)
    {
        try {
            $stmt = $this->executeQuery(
                "SELECT rating, COUNT(*) as count 
                 FROM book_ratings 
                 WHERE book_id = ? 
                 GROUP BY rating 
                 ORDER BY rating DESC",
                [$bookId]
            );

            $distribution = [0, 0, 0, 0, 0];
            $results = $stmt->fetchAll();

            foreach ($results as $row) {
                $index = 5 - $row['rating'];
                $distribution[$index] = (int)$row['count'];
            }

            return $distribution;
        } catch (Exception $e) {
            return [0, 0, 0, 0, 0];
        }
    }

    /**
     * Получить рейтинг пользователя
     */
    public function getUserRating($bookId, $userIp)
    {
        $cacheKey = 'user_rating_' . $bookId . '_' . md5($userIp);

        $cached = Cache::get($cacheKey, 'ratings');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;

        try {
            $stmt = $this->executeQuery(
                "SELECT rating FROM book_ratings WHERE book_id = ? AND user_ip = ?",
                [$bookId, $userIp]
            );
            $result = $stmt->fetch();
            $rating = $result ? (int)$result['rating'] : 0;

            Cache::set($cacheKey, $rating, 'ratings', 300);

            return $rating;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Проверить, в избранном ли книга
     */
    public function isBookInFavorites($bookId, $userIp)
    {
        $cacheKey = 'fav_check_' . $bookId . '_' . md5($userIp);

        $cached = Cache::get($cacheKey, 'favorites');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;

        try {
            $stmt = $this->executeQuery(
                "SELECT id FROM book_favorites WHERE book_id = ? AND user_ip = ?",
                [$bookId, $userIp]
            );
            $result = $stmt->fetch() !== false;

            Cache::set($cacheKey, $result, 'favorites', 300);

            return $result;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Оценить книгу (с инвалидацией кэша)
     */
    public function rateBook($bookId, $rating, $userIp, $csrfToken = null)
    {
        if (!Config::validateCsrfToken($csrfToken)) {
            my_log("CSRF validation failed in rateBook. Token: " . ($csrfToken ?? 'null'));
            throw new Exception('Invalid CSRF token');
        }

        $rating = max(1, min(5, (int)$rating));
        //$userIp = $this->sanitizeIp($userIp);
        $bookId = (int)$bookId;

        try {
            $stmt = $this->getConnection()->prepare(
                "SELECT id FROM book_ratings WHERE book_id = ? AND user_ip = ?"
            );
            $stmt->execute([$bookId, $userIp]);

            if ($stmt->fetch()) {
                $stmt = $this->getConnection()->prepare(
                    "UPDATE book_ratings SET rating = ?, created_at = CURRENT_TIMESTAMP
                 WHERE book_id = ? AND user_ip = ?"
                );
                $stmt->execute([$rating, $bookId, $userIp]);
                $result = 'updated';
            } else {
                $stmt = $this->getConnection()->prepare(
                    "INSERT INTO book_ratings (book_id, user_ip, rating) VALUES (?, ?, ?)"
                );
                $stmt->execute([$bookId, $userIp, $rating]);
                $result = 'added';
            }

            // ========== УЛУЧШЕННАЯ ИНВАЛИДАЦИЯ КЭША ==========
            // 1. Инвалидируем кэш рейтингов
            Cache::invalidateByType('ratings');
            Cache::invalidateByType('statistics');

            // 2. ВАЖНО: Очищаем кэш топ-100 книг (все версии)
            Cache::delete('top_rated_data_v2');
            Cache::delete('top_rated_data_v3');
            Cache::delete('top_rated_all_v3');

            // 3. Очищаем кэш глобальной статистики рейтингов
            Cache::delete('rating_stats_global');
            Cache::delete('rating_stats_global_v2');

            // 4. Очищаем все страницы топа в PageCache
            if (class_exists('PageCache')) {
                for ($i = 1; $i <= 20; $i++) {
                    Cache::delete('page_top_rated_page_' . $i);
                }
                PageCache::invalidateUserPages($userIp);
            }

            my_log("Rating cache fully invalidated for book {$bookId}");
            // =================================================

            return $result;

        } catch (Exception $e) {
            my_log("Error in rateBook: " . $e->getMessage());
            throw new Exception("Failed to rate book");
        }
    }

    /**
     * Добавить/удалить книгу в избранное (с инвалидацией кэша)
     */
    public function toggleFavorite($bookId, $userIp, $csrfToken = null)
    {
        if (!Config::validateCsrfToken($csrfToken)) {
            my_log("CSRF validation failed in toggleFavorite. Token: " . ($csrfToken ?? 'null'));
            throw new Exception('Invalid CSRF token');
        }

        //$userIp = $this->sanitizeIp($userIp);
        $bookId = (int)$bookId;

        try {
            $stmt = $this->getConnection()->prepare(
                "SELECT id FROM book_favorites WHERE book_id = ? AND user_ip = ?"
            );
            $stmt->execute([$bookId, $userIp]);

            if ($stmt->fetch()) {
                $stmt = $this->getConnection()->prepare(
                    "DELETE FROM book_favorites WHERE book_id = ? AND user_ip = ?"
                );
                $stmt->execute([$bookId, $userIp]);
                $result = 'removed';
            } else {
                $stmt = $this->getConnection()->prepare(
                    "INSERT INTO book_favorites (book_id, user_ip) VALUES (?, ?)"
                );
                $stmt->execute([$bookId, $userIp]);
                $result = 'added';
            }

            // Инвалидация кэша
            Cache::invalidateByType('favorites');

            return $result;
        } catch (Exception $e) {
            my_log("Error in toggleFavorite: " . $e->getMessage());
            throw new Exception("Failed to toggle favorite");
        }
    }

    /**
     * Создать ключ кэша
     */
    private function getCacheKey($prefix, $params)
    {
        return $prefix . '_' . md5(serialize($params));
    }

    /**
     * Санитизация IP адреса
     */
    private function sanitizeIp($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return '0.0.0.0';
    }

    /**
     * Получить соединение
     */
    public function getConnection()
    {
        if (!$this->isAvailable()) {
            throw new Exception(__('error_db_not_available') . ': ' . $this->lastError);
        }
        return $this->pdo;
    }

    /**
     * Проверить доступность
     */
    public function isAvailable()
    {
        return $this->isAvailable && $this->pdo !== null;
    }

    /**
     * Получить количество запросов
     */
    public function getQueryCount()
    {
        return $this->queryCount;
    }

    /**
     * Получить статистику кэша
     */
    public function getCacheStats()
    {
        return [
            'hits' => $this->cacheHits,
            'misses' => $this->cacheMisses,
            'effectiveness' => ($this->cacheHits + $this->cacheMisses) > 0 ?
                round($this->cacheHits / ($this->cacheHits + $this->cacheMisses) * 100, 1) : 0
        ];
    }

    /**
     * Получить избранные книги пользователя с пагинацией
     */
    public function getUserFavorites($userIp, $page = 1, $perPage = 20)
    {
        $offset = (int)(($page - 1) * $perPage);
        //$userIp = $this->sanitizeIp($userIp);

        $stmt = $this->executeQuery(
            "SELECT b.*, f.created_at as favorited_at
         FROM books b
         JOIN book_favorites f ON b.id = f.book_id
         WHERE f.user_ip = ?
         ORDER BY f.created_at DESC
         LIMIT ? OFFSET ?",
            [$userIp, $perPage, $offset]
        );

        return $stmt->fetchAll();
    }

    /**
     * Получить количество избранных книг пользователя
     */
    public function getUserFavoritesCount($userIp)
    {
        $stmt = $this->executeQuery(
            "SELECT COUNT(*) as count FROM book_favorites WHERE user_ip = ?",
            [$userIp]
        );
        $result = $stmt->fetch();
        return $result ? (int)$result['count'] : 0;
    }

    /**
     * Получить книги по автору (для OPDS)
     */
    public function getBooksByAuthor($author, $page, $perPage, $filters = [])
    {
        [$where, $params] = $this->buildFiltersWhere($filters);
        $orderBy = $this->buildOrderBy($filters['sort'] ?? 'new');

        $sql = "SELECT * FROM books
            WHERE author = :author {$where}
            {$orderBy}
            LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':author', $author);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Получить количество книг по автору
     */
    public function getBooksCountByAuthor($author, $filters = [])
    {
        [$where, $params] = $this->buildFiltersWhere($filters);

        $sql = "SELECT COUNT(*) FROM books WHERE author = :author {$where}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':author', $author);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Получить книги по жанру
     */
    public function getBooksByGenre($genre, $page, $perPage, $filters = [])
    {
        $offset = ($page - 1) * $perPage;
        [$where, $params] = $this->buildFiltersWhere($filters, 'b');
        $orderBy = $this->buildOrderBy($filters['sort'] ?? 'new');

        $sql = "SELECT b.* FROM books b
            WHERE b.genre = :genre {$where}
            {$orderBy}
            LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':genre', $genre);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Получить количество книг по жанру
     */
    public function getBooksCountByGenre($genre, $filters = [])
    {
        [$where, $params] = $this->buildFiltersWhere($filters, 'b');

        $sql = "SELECT COUNT(*) FROM books b
            WHERE b.genre = :genre{$where}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':genre', $genre);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Получить читаемое название жанра
     */
    public function getReadableGenre($genre)
    {

        if (empty($genre)) {
            return null;
        }

        return GenreManager::getReadableName($genre);
    }


    /**
     * Очистить кэш
     */
    public function clearCache()
    {
        Cache::clear();
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        return true;
    }


    /**
     * Получить книги по серии (для OPDS)
     */
    public function getBooksBySeries($series, $page, $perPage, $filters = [])
    {
        [$where, $params] = $this->buildFiltersWhere($filters);
        $orderBy = $this->buildOrderBy($filters['sort'] ?? 'new');

        $sql = "SELECT * FROM books
            WHERE series = :series {$where}
            {$orderBy}
            LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':series', $series);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Получить количество книг по серии
     */
    public function getBooksCountBySeries($series, $filters = [])
    {
        [$where, $params] = $this->buildFiltersWhere($filters);

        $sql = "SELECT COUNT(*) FROM books WHERE series = :series {$where}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':series', $series);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Инвалидировать кэш рейтингов для конкретной книги
     * Удаляет все батч-ключи, которые могут содержать эту книгу
     */
    private function invalidateRatingCache($bookId)
    {
        $bookId = (int)$bookId;

        // 1. Инвалидируем конкретный рейтинг книги
        Cache::delete('book_rating_' . $bookId);
        Cache::invalidateByType('ratings');

        // 2. Удаляем все батч-ключи, которые могут содержать эту книгу
        //    Для APCu это можно сделать только перебором ключей
        if (function_exists('apcu_cache_info') && Config::isUseApcu()) {
            $this->invalidateRatingBatches($bookId);
        }

        // 3. Инвалидируем статистику (там тоже есть рейтинги)
        Cache::invalidateByType('statistics');
    }

    /**
     * Удалить все батч-ключи рейтингов из APCu
     */
    private function invalidateRatingBatches($bookId)
    {
        try {
            $info = apcu_cache_info(true);
            if (!isset($info['cache_list'])) {
                return;
            }

            $deletedCount = 0;
            foreach ($info['cache_list'] as $entry) {
                $key = $entry['key'];
                // Ищем ключи, начинающиеся с 'ratings_batch_'
                if (strpos($key, 'ratings_batch_') === 0) {
                    // Пробуем удалить ключ
                    if (apcu_delete($key)) {
                        $deletedCount++;
                    }
                }
            }

            if ($deletedCount > 0 && Config::isDevelopment()) {
                my_log("Invalidated {$deletedCount} rating batch keys for book {$bookId}");
            }

        } catch (Exception $e) {
            my_log("Error invalidating rating batches: " . $e->getMessage());
        }
    }

    /**
     * Альтернативный метод: хранить список батч-ключей и удалять их
     * Более эффективный, требует дополнительного хранения
     */
    private function invalidateRatingBatchesByIndex($bookId)
    {
        // Сохраняем все созданные батч-ключи в специальный индекс
        $indexKey = 'ratings_batches_index';
        $batches = Cache::get($indexKey, 'statistics');

        if (is_array($batches) && !empty($batches)) {
            foreach ($batches as $batchKey) {
                Cache::delete($batchKey);
            }
            Cache::delete($indexKey);
            my_log("Invalidated all rating batches via index");
        }

        // Также инвалидируем по типу как fallback
        Cache::invalidateByType('statistics');
    }

    /**
     * Сохранить батч-ключ в индекс (вызывать при создании нового батча)
     */
    private function indexRatingBatchKey($batchKey)
    {
        if (!Config::isCacheEnabled()) {
            return;
        }

        $indexKey = 'ratings_batches_index';
        $batches = Cache::get($indexKey, 'statistics');

        if (!is_array($batches)) {
            $batches = [];
        }

        // Ограничиваем размер индекса (последние 1000 батчей)
        if (count($batches) > 1000) {
            $batches = array_slice($batches, -500);
        }

        if (!in_array($batchKey, $batches)) {
            $batches[] = $batchKey;
            Cache::set($indexKey, $batches, 'statistics', 86400); // Храним сутки
        }
    }


    /**
     * Получить топ авторов (оптимизировано с LIMIT)
     */
    public function getTopAuthors($limit = 20)
    {
        $cacheKey = 'top_authors_v3_' . $limit;

        $cached = Cache::get($cacheKey, 'statistics');
        if ($cached !== null) {
            return $cached;
        }

        // Универсальный SQL без USE INDEX (работает и в MySQL, и в SQLite)
        $sql = "SELECT author, COUNT(*) as count
            FROM books
            WHERE author IS NOT NULL AND author != ''
            GROUP BY author
            ORDER BY count DESC, author ASC
            LIMIT ?";

        $stmt = $this->executeQuery($sql, [$limit]);
        $result = $stmt->fetchAll();

        Cache::set($cacheKey, $result, 'statistics', 3600);

        return $result;
    }

    /**
     * Получить список жанров с количеством книг (оптимизировано с LIMIT)
     */
    public function getGenresWithCount($limit = 50)
    {
        $cacheKey = 'genres_with_count_v3_' . $limit;

        $cached = Cache::get($cacheKey, 'statistics');
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }
        $this->cacheMisses++;

        try {
            // Универсальный SQL без USE INDEX
            $sql = "SELECT
                    genre,
                    COUNT(*) as count
                FROM books
                WHERE genre IS NOT NULL AND genre != ''
                GROUP BY genre
                ORDER BY count DESC, genre ASC
                LIMIT ?";

            $stmt = $this->executeQuery($sql, [$limit]);
            $results = $stmt->fetchAll();

            // Добавляем читаемые названия жанров
            foreach ($results as &$genre) {
                $genre['readable_name'] = $this->getReadableGenre($genre['genre']);
            }

            Cache::set($cacheKey, $results, 'statistics', 3600);

            return $results;

        } catch (Exception $e) {
            my_log("Error getting genres with count: " . $e->getMessage());
            return [];
        }
    }

    public function getDistinctLanguages()
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT language
         FROM books
         WHERE language IS NOT NULL AND language != ''
         ORDER BY language"
        );
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function getDistinctFormats()
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT file_type
         FROM books
         WHERE file_type IS NOT NULL AND file_type != ''
         ORDER BY file_type"
        );
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Построить WHERE-условия для фильтров.
     *
     * @param array $filters
     * @param string $alias Алиас таблицы (например 'b' для books)
     * @return array [string $whereSql, array $params]
     */
    private function buildFiltersWhere($filters, $alias = '')
    {
        $prefix = $alias ? $alias . '.' : '';
        $conditions = [];
        $params = [];

        if (!empty($filters['lang'])) {
            $conditions[] = "{$prefix}language = :f_lang";
            $params[':f_lang'] = $filters['lang'];
        }

        if (!empty($filters['format'])) {
            $conditions[] = "{$prefix}file_type = :f_format";
            $params[':f_format'] = $filters['format'];
        }

        if (empty($conditions)) {
            return ['', []];
        }

        // ВАЖНО: добавляем пробел перед AND, чтобы не было "ANDAND"
        $sql = ' AND ' . implode(' AND ', $conditions);
        return [$sql, $params];
    }

    /**
     * Построить ORDER BY с защитой от инъекций.
     */
    private function buildOrderBy($sort)
    {
        $allowed = [
            'new'    => 'added_date DESC, id DESC',
            'title'  => 'title ASC, added_date DESC',
            'author' => 'author ASC, title ASC',
        ];

        $clause = $allowed[$sort] ?? $allowed['new'];
        return 'ORDER BY ' . $clause;
    }

    /**
     * Получить последние книги для чтения пользователя
     */
    public function getContinueReading($fingerprint, $limit = 10)
    {
        if (empty($fingerprint)) {
            return [];
        }

        try {
            $sql = "SELECT 
                    b.*, 
                    bm.page_number, 
                    bm.percentage,
                    bm.updated_at as last_read_at
                FROM bookmarks bm
                INNER JOIN books b ON bm.book_id = b.id
                WHERE bm.user_fingerprint = :fingerprint 
                  AND bm.note = 'Последнее прочитанное'
                  AND bm.is_deleted = 0
                ORDER BY bm.updated_at DESC
                LIMIT :limit";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':fingerprint', $fingerprint, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            my_log("Error getting continue reading: " . $e->getMessage());
            return [];
        }
    }


}
