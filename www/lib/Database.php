<?php

require_once __DIR__.'/GenreManager.php';
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/Cache.php';
require_once __DIR__.'/SecurityHelper.php';
require_once __DIR__.'/DatabaseChecker.php';
require_once __DIR__.'/EmptyPDOStatement.php';
require_once __DIR__.'/../init.php';

class Database
{
    private $pdo;
    private static $instance;
    private $queryCount = 0;
    private $cacheHits = 0;
    private $cacheMisses = 0;
    private $security;
    private $isAvailable = true;
    private $lastError;

    private function __construct()
    {
        $this->security = SecurityHelper::getInstance();

        $checker = DatabaseChecker::getInstance();
        if (!$checker->checkDatabase()) {
            $this->isAvailable = false;
            $this->lastError = $checker->getErrorMessage();
            error_log('Database unavailable: '.$this->lastError);

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

                    $dsn = 'sqlite:'.$dbConfig['path'];
                    $this->pdo = new PDO($dsn);
                    $this->pdo->exec('PRAGMA journal_mode = WAL');
                    $this->pdo->exec('PRAGMA synchronous = NORMAL');
                    $this->pdo->exec('PRAGMA cache_size = -64000');
                    $this->pdo->exec('PRAGMA temp_store = memory');
                    $this->pdo->exec('PRAGMA mmap_size = 268435456');
                    break;

                case 'mysql':
                    $dsn = 'mysql:host='.$dbConfig['host'].
                           ';dbname='.$dbConfig['name'].
                           ';charset=utf8mb4';
                    $this->pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
                        PDO::ATTR_PERSISTENT => true, // Включаем persistent connection
                        PDO::ATTR_TIMEOUT => 30,
                        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
                        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
                    ]);

                    // Оптимизации MySQL
                    $this->pdo->exec("SET SESSION sql_mode = 'TRADITIONAL'");
                    $this->pdo->exec('SET SESSION optimizer_search_depth = 0');
                    break;

                default:
                    throw new Exception(__('error_unsupported_db_type'));
            }

            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $e) {
            $this->isAvailable = false;
            $this->lastError = __('error_db_connection').': '.$e->getMessage();
            error_log($this->lastError);
        } catch (Exception $e) {
            $this->isAvailable = false;
            $this->lastError = $e->getMessage();
            error_log($this->lastError);
        }
    }

    /**
     * Получить тип базы данных.
     */
    public function getDbType()
    {
        return Config::getDbType();
    }

    /**
     * Получить общее количество книг (с кэшированием).
     */
    public function getTotalBooksCount()
    {
        $cacheKey = 'total_books_count_v2';

        $cached = Cache::get($cacheKey, 'statistics');
        if (null !== $cached) {
            ++$this->cacheHits;

            return $cached;
        }
        ++$this->cacheMisses;

        try {
            // Используем приблизительное значение для MySQL для скорости
            //    if (Config::DB_TYPE === 'mysql') {
            //            $stmt = $this->executeQuery("SELECT TABLE_ROWS FROM information_schema.TABLES
            //                                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'books'");
            //            $result = $stmt->fetch();
            //            $count = $result['TABLE_ROWS'] ?? 0;
            //        } else {
            $stmt = $this->executeQuery('SELECT COUNT(*) as count FROM books');
            $result = $stmt->fetch();
            $count = $result['count'] ?? 0;
            //    }
        } catch (Exception $e) {
            error_log('Error getting total books count: '.$e->getMessage());
            $count = 0;
        }

        Cache::set($cacheKey, $count, 'statistics', 3600); // Кэш на час

        return $count;
    }

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Безопасное выполнение запроса с параметрами.
     */
    private function executeQuery($sql, $params = [])
    {
        if (!$this->isAvailable()) {
            error_log('Query skipped - database unavailable: '.$sql);

            return new EmptyPDOStatement();
        }

        ++$this->queryCount;

        if (Config::PERFORMANCE['enable_query_logging'] && Config::isDevelopment()) {
            error_log('DB Query: '.$sql.' | Params: '.json_encode($params));
        }

        $startTime = microtime(true);

        try {
            $stmt = $this->pdo->prepare($sql);

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
            if ($queryTime > 0.5 && Config::PERFORMANCE['enable_query_logging']) {
                error_log('Slow query ('.round($queryTime, 3).'s): '.$sql);
            }

            return $stmt;
        } catch (PDOException $e) {
            error_log('Query failed: '.$sql.' | Error: '.$e->getMessage());
            throw new Exception(__('error_db_query'));
        }
    }

    /**
     * Поиск книг с использованием FULLTEXT индекса.
     */
    public function searchBooks($query, $field = 'all', $page = 1, $perPage = null)
    {
        if (null === $perPage) {
            $perPage = Config::ITEMS_PER_PAGE;
        }

        $query = $this->security->sanitizeSearchQuery($query);
        $field = $this->security->sanitizeSearchField($field);

        $cacheKey = $this->getCacheKey('search_books_v2', [
            'query' => $query,
            'field' => $field,
            'page' => $page,
            'perPage' => $perPage,
        ]);

        $cached = Cache::get($cacheKey, 'search_results');
        if (null !== $cached) {
            ++$this->cacheHits;

            return $cached;
        }
        ++$this->cacheMisses;

        $offset = (int) (($page - 1) * $perPage);
        $perPage = min((int) $perPage, 100);

        // Пытаемся использовать FULLTEXT поиск для MySQL
        if (Config::DB_TYPE === 'mysql' && strlen($query) >= 3 && Config::SEARCH_OPTIMIZATION['enable_fulltext']) {
            return $this->searchBooksFulltext($query, $field, $offset, $perPage);
        }

        // Fallback на LIKE поиск
        return $this->searchBooksLike($query, $field, $offset, $perPage, $cacheKey);
    }

    /**
     * FULLTEXT поиск (быстрый).
     */
    private function searchBooksFulltext($query, $field, $offset, $perPage)
    {
        try {
            $searchTerms = $this->prepareFulltextTerms($query);

            $sql = 'SELECT *, MATCH(title, author, genre, series) AGAINST(? IN BOOLEAN MODE) as relevance 
                    FROM books 
                    WHERE MATCH(title, author, genre, series) AGAINST(? IN BOOLEAN MODE)';

            if ('all' !== $field) {
                // Для конкретного поля используем дополнительный фильтр
                $sql .= " AND $field LIKE ?";
                $likeTerm = "%{$query}%";
                $stmt = $this->executeQuery(
                    $sql.' ORDER BY relevance DESC LIMIT ? OFFSET ?',
                    [$searchTerms, $searchTerms, $likeTerm, $perPage, $offset]
                );
            } else {
                $stmt = $this->executeQuery(
                    $sql.' ORDER BY relevance DESC LIMIT ? OFFSET ?',
                    [$searchTerms, $searchTerms, $perPage, $offset]
                );
            }

            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Fulltext search failed, falling back to LIKE: '.$e->getMessage());

            return $this->searchBooksLike($query, $field, $offset, $perPage);
        }
    }

    /**
     * LIKE поиск (медленный, но надежный).
     */
    private function searchBooksLike($query, $field, $offset, $perPage, $cacheKey = null)
    {
        $sql = 'SELECT * FROM books WHERE 1=1';
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

        $sql .= ' ORDER BY added_date DESC LIMIT ? OFFSET ?';
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
     * Подготовка терминов для FULLTEXT поиска.
     */
    private function prepareFulltextTerms($query)
    {
        $words = preg_split('/\s+/', trim($query));
        $terms = [];

        foreach ($words as $word) {
            if (strlen($word) >= 3) {
                $terms[] = '+'.$word.'*';
            }
        }

        return implode(' ', $terms);
    }

    /**
     * Получить количество результатов поиска (оптимизировано).
     */
    public function getSearchCount($query, $field = 'all')
    {
        if (empty($query)) {
            return $this->getTotalBooksCount();
        }

        $cacheKey = $this->getCacheKey('search_count_v2', [
            'query' => $query,
            'field' => $field,
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
        if (Config::DB_TYPE === 'mysql' && $this->getTotalBooksCount() > 10000) {
            try {
                $searchTerms = $this->prepareFulltextTerms($query);
                $stmt = $this->executeQuery(
                    'SELECT COUNT(*) as count FROM books 
                     WHERE MATCH(title, author, genre, series) AGAINST(? IN BOOLEAN MODE)',
                    [$searchTerms]
                );
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

        $stmt = $this->executeQuery($sql, $params);
        $result = $stmt->fetch();
        $count = $result['count'] ?? 0;

        Cache::set($cacheKey, $count, 'statistics');

        return $count;
    }

    /**
     * Получить книгу по ID (с кэшированием).
     */
    public function getBook($id)
    {
        $id = (int) $id;

        $cacheKey = $this->getCacheKey('book_v2', ['id' => $id]);

        $cached = Cache::get($cacheKey, 'book_data');
        if (null !== $cached) {
            ++$this->cacheHits;

            return $cached;
        }
        ++$this->cacheMisses;

        $stmt = $this->executeQuery('SELECT * FROM books WHERE id = ?', [$id]);
        $result = $stmt->fetch();

        if ($result) {
            Cache::set($cacheKey, $result, 'book_data', 3600);
        }

        return $result;
    }

    /**
     * Получить последние добавленные книги (оптимизировано).
     */
    public function getRecentBooks($limit = 10, $offset = 0)
    {
        $limit = min((int) $limit, 100);
        $offset = (int) $offset;

        $cacheKey = $this->getCacheKey('recent_books_v2', [
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $cached = Cache::get($cacheKey, 'search_results');
        if (null !== $cached) {
            ++$this->cacheHits;

            return $cached;
        }
        ++$this->cacheMisses;

        // Используем индекс idx_added_date
        //    $sql = "SELECT id, title, author, series, series_number, genre, file_type,
        //                   added_date, archive_path, year
        //            FROM books
        //            ORDER BY added_date DESC
        //            LIMIT ? OFFSET ?";
        $sql = 'SELECT * FROM books ORDER BY added_date DESC LIMIT ? OFFSET ?';

        $stmt = $this->executeQuery($sql, [$limit, $offset]);
        $result = $stmt->fetchAll();

        Cache::set($cacheKey, $result, 'search_results', 300); // Кэш на 5 минут

        return $result;
    }

    /**
     * Получить топ книг по рейтингу (оптимизированная версия).
     */
    public function getTopRatedBooks($limit = 100, $minVotes = 1)
    {
        $cacheKey = 'top_rated_optimized_'.$limit.'_'.$minVotes;

        $cached = Cache::get($cacheKey, 'statistics');
        if (null !== $cached) {
            ++$this->cacheHits;

            return $cached;
        }
        ++$this->cacheMisses;

        if (Config::DB_TYPE === 'mysql') {
            // MySQL оптимизация с подзапросом и использованием индексов
            $sql = 'SELECT b.id, b.title, b.author, b.series, b.series_number, 
                           b.genre, b.file_type, b.added_date,
                           COALESCE(r_stats.avg_rating, 0) as avg_rating,
                           COALESCE(r_stats.votes_count, 0) as votes_count
                    FROM books b
                    STRAIGHT_JOIN (
                        SELECT book_id, AVG(rating) as avg_rating, COUNT(*) as votes_count
                        FROM book_ratings
                        GROUP BY book_id
                        HAVING COUNT(*) >= ?
                    ) r_stats ON b.id = r_stats.book_id
                    ORDER BY r_stats.avg_rating DESC, r_stats.votes_count DESC, b.title
                    LIMIT ?';
        } else {
            // SQLite оптимизация
            $sql = 'SELECT b.id, b.title, b.author, b.series, b.series_number,
                           b.genre, b.file_type, b.added_date,
                           IFNULL(r_stats.avg_rating, 0) as avg_rating,
                           IFNULL(r_stats.votes_count, 0) as votes_count
                    FROM books b
                    LEFT JOIN (
                        SELECT book_id, AVG(rating) as avg_rating, COUNT(*) as votes_count
                        FROM book_ratings
                        GROUP BY book_id
                    ) r_stats ON b.id = r_stats.book_id
                    WHERE r_stats.votes_count >= ?
                    ORDER BY r_stats.avg_rating DESC, r_stats.votes_count DESC, b.title
                    LIMIT ?';
        }

        $stmt = $this->executeQuery($sql, [$minVotes, $limit]);
        $result = $stmt->fetchAll();

        Cache::set($cacheKey, $result, 'statistics', 1800); // Кэш на 30 минут

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

        $cacheKey = 'ratings_batch_'.md5(implode(',', $bookIds));

        $cached = Cache::get($cacheKey, 'statistics');
        if (null !== $cached) {
            ++$this->cacheHits;

            return $cached;
        }
        ++$this->cacheMisses;

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
                'votes' => (int) $row['votes'],
                'average' => (float) $row['average'],
                'average_rounded' => round((float) $row['average'] * 2) / 2,
            ];
        }

        // Добавляем пустые рейтинги для книг без оценок
        foreach ($bookIds as $id) {
            if (!isset($ratings[$id])) {
                $ratings[$id] = [
                    'votes' => 0,
                    'average' => 0,
                    'average_rounded' => 0,
                ];
            }
        }

        Cache::set($cacheKey, $ratings, 'statistics', 300); // Кэш на 5 минут

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

        $cacheKey = 'favorites_batch_'.md5(implode(',', $bookIds).'_'.$userIp);

        $cached = Cache::get($cacheKey, 'statistics');
        if (null !== $cached) {
            ++$this->cacheHits;

            return $cached;
        }
        ++$this->cacheMisses;

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
     * Получить статистику коллекции (оптимизированная версия).
     */
    public function getCollectionStats()
    {
        $cacheKey = 'collection_stats_v3';
        $cached = Cache::get($cacheKey, 'statistics');
        if (null !== $cached) {
            return $cached;
        }

        // Для MySQL используем приблизительные значения из information_schema
        if (Config::DB_TYPE === 'mysql') {
            $sql = 'SELECT 
            (SELECT COUNT(*) FROM books) as total_books,
            (SELECT COUNT(DISTINCT author) FROM books) as total_authors,
            (SELECT COUNT(DISTINCT genre) FROM books) as total_genres,
            (SELECT COUNT(DISTINCT series) FROM books) as total_series';
        } else {
            // Для SQLite оставляем как есть, но кэшируем
            $sql = "SELECT 
                    (SELECT COUNT(*) FROM books) as total_books,
                    (SELECT COUNT(DISTINCT author) FROM books WHERE author IS NOT NULL AND author != '') as total_authors,
                    (SELECT COUNT(DISTINCT genre) FROM books WHERE genre IS NOT NULL AND genre != '') as total_genres,
                    (SELECT COUNT(DISTINCT series) FROM books WHERE series IS NOT NULL AND series != '') as total_series";
        }

        $stmt = $this->executeQuery($sql);
        $stats = $stmt->fetch();

        // Статистика по форматам (оставляем)
        $stmt = $this->executeQuery('
        SELECT file_type, COUNT(*) as count 
        FROM books 
        WHERE file_type IS NOT NULL 
        GROUP BY file_type 
        ORDER BY count DESC
    ');
        $stats['file_types'] = $stmt->fetchAll();

        Cache::set($cacheKey, $stats, 'statistics', 3600);

        return $stats;
    }

    /**
     * Получить рейтинг книги.
     */
    public function getBookRating($bookId)
    {
        $cacheKey = 'book_rating_'.$bookId;

        $cached = Cache::get($cacheKey, 'ratings');
        if (null !== $cached) {
            ++$this->cacheHits;

            return $cached;
        }
        ++$this->cacheMisses;

        try {
            $stmt = $this->executeQuery(
                'SELECT
                    COUNT(*) as votes,
                    AVG(rating) as average_rating
                 FROM book_ratings
                 WHERE book_id = ?',
                [$bookId]
            );

            $result = $stmt->fetch();
            if (!$result || 0 == $result['votes']) {
                $rating = [
                    'votes' => 0,
                    'average' => 0,
                    'average_rounded' => 0,
                    'distribution' => [0, 0, 0, 0, 0],
                ];
            } else {
                $average = (float) $result['average_rating'];

                $rating = [
                    'votes' => (int) $result['votes'],
                    'average' => $average,
                    'average_rounded' => round($average * 2) / 2,
                    'distribution' => $this->getRatingDistribution($bookId),
                ];
            }

            Cache::set($cacheKey, $rating, 'ratings', 300); // Кэш на 5 минут

            return $rating;
        } catch (Exception $e) {
            error_log('Error getting book rating: '.$e->getMessage());

            return ['votes' => 0, 'average' => 0, 'average_rounded' => 0, 'distribution' => [0, 0, 0, 0, 0]];
        }
    }

    /**
     * Получить распределение оценок.
     */
    private function getRatingDistribution($bookId)
    {
        try {
            $stmt = $this->executeQuery(
                'SELECT rating, COUNT(*) as count 
                 FROM book_ratings 
                 WHERE book_id = ? 
                 GROUP BY rating 
                 ORDER BY rating DESC',
                [$bookId]
            );

            $distribution = [0, 0, 0, 0, 0];
            $results = $stmt->fetchAll();

            foreach ($results as $row) {
                $index = 5 - $row['rating'];
                $distribution[$index] = (int) $row['count'];
            }

            return $distribution;
        } catch (Exception $e) {
            return [0, 0, 0, 0, 0];
        }
    }

    /**
     * Получить рейтинг пользователя.
     */
    public function getUserRating($bookId, $userIp)
    {
        $cacheKey = 'user_rating_'.$bookId.'_'.md5($userIp);

        $cached = Cache::get($cacheKey, 'ratings');
        if (null !== $cached) {
            ++$this->cacheHits;

            return $cached;
        }
        ++$this->cacheMisses;

        try {
            $stmt = $this->executeQuery(
                'SELECT rating FROM book_ratings WHERE book_id = ? AND user_ip = ?',
                [$bookId, $userIp]
            );
            $result = $stmt->fetch();
            $rating = $result ? (int) $result['rating'] : 0;

            Cache::set($cacheKey, $rating, 'ratings', 300);

            return $rating;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Проверить, в избранном ли книга.
     */
    public function isBookInFavorites($bookId, $userIp)
    {
        $cacheKey = 'fav_check_'.$bookId.'_'.md5($userIp);

        $cached = Cache::get($cacheKey, 'favorites');
        if (null !== $cached) {
            ++$this->cacheHits;

            return $cached;
        }
        ++$this->cacheMisses;

        try {
            $stmt = $this->executeQuery(
                'SELECT id FROM book_favorites WHERE book_id = ? AND user_ip = ?',
                [$bookId, $userIp]
            );
            $result = false !== $stmt->fetch();

            Cache::set($cacheKey, $result, 'favorites', 300);

            return $result;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Получить топ авторов (оптимизировано).
     */
    public function getTopAuthors($limit = 20)
    {
        $cacheKey = 'top_authors_v2_'.$limit;

        $cached = Cache::get($cacheKey, 'statistics');
        if (null !== $cached) {
            return $cached;
        }

        $stmt = $this->executeQuery("
            SELECT author, COUNT(*) as count 
            FROM books USE INDEX (idx_author_count)
            WHERE author IS NOT NULL AND author != '' 
            GROUP BY author 
            ORDER BY count DESC
            LIMIT ?
        ", [$limit]);

        $result = $stmt->fetchAll();
        Cache::set($cacheKey, $result, 'statistics', 3600);

        return $result;
    }

    /**
     * Оценить книгу (с инвалидацией кэша).
     */
    public function rateBook($bookId, $rating, $userIp, $csrfToken = null)
    {
        if (!Config::validateCsrfToken($csrfToken)) {
            error_log('CSRF validation failed in rateBook. Token: '.($csrfToken ?? 'null'));
            throw new Exception('Invalid CSRF token');
        }

        $rating = max(1, min(5, (int) $rating));
        $userIp = $this->sanitizeIp($userIp);
        $bookId = (int) $bookId;

        try {
            $stmt = $this->getConnection()->prepare(
                'SELECT id FROM book_ratings WHERE book_id = ? AND user_ip = ?'
            );
            $stmt->execute([$bookId, $userIp]);

            if ($stmt->fetch()) {
                $stmt = $this->getConnection()->prepare(
                    'UPDATE book_ratings SET rating = ?, created_at = CURRENT_TIMESTAMP 
                     WHERE book_id = ? AND user_ip = ?'
                );
                $stmt->execute([$rating, $bookId, $userIp]);
                $result = 'updated';
            } else {
                $stmt = $this->getConnection()->prepare(
                    'INSERT INTO book_ratings (book_id, user_ip, rating) VALUES (?, ?, ?)'
                );
                $stmt->execute([$bookId, $userIp, $rating]);
                $result = 'added';
            }

            // Инвалидация кэша
            Cache::invalidateByType('ratings');
            Cache::invalidateByType('statistics');

            return $result;
        } catch (Exception $e) {
            error_log('Error in rateBook: '.$e->getMessage());
            throw new Exception('Failed to rate book');
        }
    }

    /**
     * Добавить/удалить книгу в избранное (с инвалидацией кэша).
     */
    public function toggleFavorite($bookId, $userIp, $csrfToken = null)
    {
        if (!Config::validateCsrfToken($csrfToken)) {
            error_log('CSRF validation failed in toggleFavorite. Token: '.($csrfToken ?? 'null'));
            throw new Exception('Invalid CSRF token');
        }

        $userIp = $this->sanitizeIp($userIp);
        $bookId = (int) $bookId;

        try {
            $stmt = $this->getConnection()->prepare(
                'SELECT id FROM book_favorites WHERE book_id = ? AND user_ip = ?'
            );
            $stmt->execute([$bookId, $userIp]);

            if ($stmt->fetch()) {
                $stmt = $this->getConnection()->prepare(
                    'DELETE FROM book_favorites WHERE book_id = ? AND user_ip = ?'
                );
                $stmt->execute([$bookId, $userIp]);
                $result = 'removed';
            } else {
                $stmt = $this->getConnection()->prepare(
                    'INSERT INTO book_favorites (book_id, user_ip) VALUES (?, ?)'
                );
                $stmt->execute([$bookId, $userIp]);
                $result = 'added';
            }

            // Инвалидация кэша
            Cache::invalidateByType('favorites');

            return $result;
        } catch (Exception $e) {
            error_log('Error in toggleFavorite: '.$e->getMessage());
            throw new Exception('Failed to toggle favorite');
        }
    }

    /**
     * Создать ключ кэша.
     */
    private function getCacheKey($prefix, $params)
    {
        return $prefix.'_'.md5(serialize($params));
    }

    /**
     * Санитизация IP адреса.
     */
    private function sanitizeIp($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return '0.0.0.0';
    }

    /**
     * Получить соединение.
     */
    public function getConnection()
    {
        if (!$this->isAvailable()) {
            throw new Exception(__('error_db_not_available').': '.$this->lastError);
        }

        return $this->pdo;
    }

    /**
     * Проверить доступность.
     */
    public function isAvailable()
    {
        return $this->isAvailable && null !== $this->pdo;
    }

    /**
     * Получить количество запросов.
     */
    public function getQueryCount()
    {
        return $this->queryCount;
    }

    /**
     * Получить статистику кэша.
     */
    public function getCacheStats()
    {
        return [
            'hits' => $this->cacheHits,
            'misses' => $this->cacheMisses,
            'effectiveness' => ($this->cacheHits + $this->cacheMisses) > 0 ?
                round($this->cacheHits / ($this->cacheHits + $this->cacheMisses) * 100, 1) : 0,
        ];
    }

    /**
     * Получить избранные книги пользователя с пагинацией.
     */
    public function getUserFavorites($userIp, $page = 1, $perPage = 20)
    {
        $offset = (int) (($page - 1) * $perPage);
        $userIp = $this->sanitizeIp($userIp);

        $stmt = $this->executeQuery(
            'SELECT b.*, f.created_at as favorited_at
         FROM books b
         JOIN book_favorites f ON b.id = f.book_id
         WHERE f.user_ip = ?
         ORDER BY f.created_at DESC
         LIMIT ? OFFSET ?',
            [$userIp, $perPage, $offset]
        );

        return $stmt->fetchAll();
    }

    /**
     * Получить количество избранных книг пользователя.
     */
    public function getUserFavoritesCount($userIp)
    {
        $stmt = $this->executeQuery(
            'SELECT COUNT(*) as count FROM book_favorites WHERE user_ip = ?',
            [$userIp]
        );
        $result = $stmt->fetch();

        return $result ? (int) $result['count'] : 0;
    }

    /**
     * Получить книги по автору (для OPDS).
     */
    public function getBooksByAuthor($author, $page = 1, $perPage = 25)
    {
        $offset = (int) (($page - 1) * $perPage);
        $stmt = $this->executeQuery(
            'SELECT * FROM books WHERE author = ? ORDER BY title LIMIT ? OFFSET ?',
            [$author, $perPage, $offset]
        );

        return $stmt->fetchAll();
    }

    /**
     * Получить количество книг по автору.
     */
    public function getBooksCountByAuthor($author)
    {
        $stmt = $this->executeQuery(
            'SELECT COUNT(*) as count FROM books WHERE author = ?',
            [$author]
        );
        $result = $stmt->fetch();

        return $result['count'] ?? 0;
    }

    /**
     * Получить книги по жанру.
     */
    public function getBooksByGenre($genre, $page = 1, $perPage = 25)
    {
        $offset = (int) (($page - 1) * $perPage);
        $stmt = $this->executeQuery(
            'SELECT * FROM books WHERE genre = ? ORDER BY title LIMIT ? OFFSET ?',
            [$genre, $perPage, $offset]
        );

        return $stmt->fetchAll();
    }

    /**
     * Получить количество книг по жанру.
     */
    public function getBooksCountByGenre($genre)
    {
        $stmt = $this->executeQuery(
            'SELECT COUNT(*) as count FROM books WHERE genre = ?',
            [$genre]
        );
        $result = $stmt->fetch();

        return $result['count'] ?? 0;
    }

    /**
     * Получить читаемое название жанра.
     */
    public function getReadableGenre($genre)
    {
        if (empty($genre)) {
            return null;
        }

        return GenreManager::getReadableName($genre);
    }

    /**
     * Очистить кэш.
     */
    public function clearCache()
    {
        Cache::clear();
        $this->cacheHits = 0;
        $this->cacheMisses = 0;

        return true;
    }

    // Добавить в конец класса Database, перед последней закрывающей скобкой

    /**
     * Получить список жанров с количеством книг.
     */
    public function getGenresWithCount()
    {
        $cacheKey = 'genres_with_count_v2';

        $cached = Cache::get($cacheKey, 'statistics');
        if (null !== $cached) {
            ++$this->cacheHits;

            return $cached;
        }
        ++$this->cacheMisses;

        try {
            $sql = "SELECT 
                        genre, 
                        COUNT(*) as count 
                    FROM books 
                    WHERE genre IS NOT NULL AND genre != '' 
                    GROUP BY genre 
                    ORDER BY count DESC";

            $stmt = $this->executeQuery($sql);
            $results = $stmt->fetchAll();

            // Добавляем читаемые названия жанров
            foreach ($results as &$genre) {
                $genre['readable_name'] = $this->getReadableGenre($genre['genre']);
            }

            Cache::set($cacheKey, $results, 'statistics', 3600);

            return $results;
        } catch (Exception $e) {
            error_log('Error getting genres with count: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Получить список серий с количеством книг.
     */
    public function getSeriesWithCount()
    {
        $cacheKey = 'series_with_count_v2';

        $cached = Cache::get($cacheKey, 'statistics');
        if (null !== $cached) {
            return $cached;
        }

        try {
            $sql = "SELECT 
                        series, 
                        COUNT(*) as count 
                    FROM books 
                    WHERE series IS NOT NULL AND series != '' 
                    GROUP BY series 
                    ORDER BY count DESC 
                    LIMIT 100";

            $stmt = $this->executeQuery($sql);
            $results = $stmt->fetchAll();

            Cache::set($cacheKey, $results, 'statistics', 3600);

            return $results;
        } catch (Exception $e) {
            error_log('Error getting series with count: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Получить книги по серии (для OPDS).
     */
    public function getBooksBySeries($series, $page = 1, $perPage = 25)
    {
        $offset = (int) (($page - 1) * $perPage);

        $sql = 'SELECT * FROM books 
                WHERE series = ? 
                ORDER BY series_number, title 
                LIMIT ? OFFSET ?';

        $stmt = $this->executeQuery($sql, [$series, $perPage, $offset]);

        return $stmt->fetchAll();
    }

    /**
     * Получить количество книг по серии.
     */
    public function getBooksCountBySeries($series)
    {
        $stmt = $this->executeQuery(
            'SELECT COUNT(*) as count FROM books WHERE series = ?',
            [$series]
        );
        $result = $stmt->fetch();

        return $result['count'] ?? 0;
    }

    public function getRecentBooksWithCount($limit = 10, $offset = 0)
    {
        $limit = min((int) $limit, 100);
        $offset = (int) $offset;

        $dbType = Config::getDbType();

        if ('mysql' === $dbType) {
            $sql = 'SELECT SQL_CALC_FOUND_ROWS * FROM books ORDER BY added_date DESC LIMIT ? OFFSET ?';
            $stmt = $this->executeQuery($sql, [$limit, $offset]);
            $books = $stmt->fetchAll();

            $countStmt = $this->executeQuery('SELECT FOUND_ROWS() as count');
            $total = (int) $countStmt->fetchColumn();
        } else {
            // SQLite - два отдельных запроса
            $sql = 'SELECT * FROM books ORDER BY added_date DESC LIMIT ? OFFSET ?';
            $stmt = $this->executeQuery($sql, [$limit, $offset]);
            $books = $stmt->fetchAll();

            $countStmt = $this->executeQuery('SELECT COUNT(*) as count FROM books');
            $total = (int) $countStmt->fetchColumn();
        }

        return ['books' => $books, 'total' => $total];
    }

    public function searchBooksWithCount($query, $field = 'all', $page = 1, $perPage = null)
    {
        if (null === $perPage) {
            $perPage = Config::ITEMS_PER_PAGE;
        }

        $query = $this->security->sanitizeSearchQuery($query);
        $field = $this->security->sanitizeSearchField($field);

        $offset = (int) (($page - 1) * $perPage);
        $perPage = min((int) $perPage, 100);

        $dbType = Config::getDbType();

        // Формируем WHERE условие
        $where = 'WHERE 1=1';
        $params = [];

        if (!empty($query)) {
            $searchTerm = "%{$query}%";

            switch ($field) {
                case 'author':
                    $where .= ' AND author LIKE ?';
                    $params[] = $searchTerm;
                    break;
                case 'title':
                    $where .= ' AND title LIKE ?';
                    $params[] = $searchTerm;
                    break;
                case 'genre':
                    $where .= ' AND genre LIKE ?';
                    $params[] = $searchTerm;
                    break;
                case 'series':
                    $where .= ' AND series LIKE ?';
                    $params[] = $searchTerm;
                    break;
                default:
                    $where .= ' AND (author LIKE ? OR title LIKE ? OR genre LIKE ? OR series LIKE ?)';
                    $params = array_fill(0, 4, $searchTerm);
                    break;
            }
        }

        if ('mysql' === $dbType) {
            $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM books $where ORDER BY added_date DESC LIMIT ? OFFSET ?";
            $stmtParams = array_merge($params, [$perPage, $offset]);
            $stmt = $this->executeQuery($sql, $stmtParams);
            $books = $stmt->fetchAll();

            $countStmt = $this->executeQuery('SELECT FOUND_ROWS() as count');
            $total = (int) $countStmt->fetchColumn();
        } else {
            // SQLite - сначала получаем общее количество
            $countSql = "SELECT COUNT(*) as count FROM books $where";
            $countStmt = $this->executeQuery($countSql, $params);
            $total = (int) $countStmt->fetchColumn();

            // Затем получаем данные с пагинацией
            $sql = "SELECT * FROM books $where ORDER BY added_date DESC LIMIT ? OFFSET ?";
            $stmtParams = array_merge($params, [$perPage, $offset]);
            $stmt = $this->executeQuery($sql, $stmtParams);
            $books = $stmt->fetchAll();
        }

        return ['books' => $books, 'total' => $total];
    }
}
