<?php

// admin/BookManager.php

class BookManager
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Получить список книг с фильтрацией
     */
    public function getBooks($page = 1, $perPage = 20, $filters = [])
    {
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT b.*, 
                       COALESCE(r.avg_rating, 0) as avg_rating,
                       COALESCE(r.votes, 0) as votes
                FROM books b
                LEFT JOIN (
                    SELECT book_id, AVG(rating) as avg_rating, COUNT(*) as votes
                    FROM book_ratings
                    GROUP BY book_id
                ) r ON b.id = r.book_id
                WHERE 1=1";

        $params = [];

        // Поиск по тексту
        if (!empty($filters['search'])) {
            $sql .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.series LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Фильтр по жанру
        if (!empty($filters['genre'])) {
            $sql .= " AND b.genre = ?";
            $params[] = $filters['genre'];
        }

        // Фильтр по формату
        if (!empty($filters['file_type'])) {
            $sql .= " AND b.file_type = ?";
            $params[] = $filters['file_type'];
        }

        // Фильтр по автору
        if (!empty($filters['author'])) {
            $sql .= " AND b.author LIKE ?";
            $params[] = "%{$filters['author']}%";
        }

        // Фильтр по году
        if (!empty($filters['year'])) {
            $sql .= " AND b.year = ?";
            $params[] = $filters['year'];
        }

        // Фильтр по наличию в архиве
        if (isset($filters['in_archive']) && $filters['in_archive'] !== '') {
            if ($filters['in_archive'] === 'yes') {
                $sql .= " AND b.archive_path IS NOT NULL";
            } else {
                $sql .= " AND b.archive_path IS NULL";
            }
        }

        // Сортировка
        $orderBy = $filters['order_by'] ?? 'added_date';
        $orderDir = $filters['order_dir'] ?? 'DESC';

        // Безопасные значения для сортировки
        $allowedOrder = ['id', 'title', 'author', 'year', 'added_date', 'avg_rating', 'votes'];
        if (!in_array($orderBy, $allowedOrder)) {
            $orderBy = 'added_date';
        }
        $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

        $sql .= " ORDER BY b.$orderBy $orderDir LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Получить общее количество книг с учётом фильтров
     */
    public function getTotalBooks($filters = [])
    {
        $sql = "SELECT COUNT(*) as count FROM books b WHERE 1=1";
        $params = [];

        if (!empty($filters['search'])) {
            $sql .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.series LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($filters['genre'])) {
            $sql .= " AND b.genre = ?";
            $params[] = $filters['genre'];
        }

        if (!empty($filters['file_type'])) {
            $sql .= " AND b.file_type = ?";
            $params[] = $filters['file_type'];
        }

        if (!empty($filters['author'])) {
            $sql .= " AND b.author LIKE ?";
            $params[] = "%{$filters['author']}%";
        }

        if (!empty($filters['year'])) {
            $sql .= " AND b.year = ?";
            $params[] = $filters['year'];
        }

        if (isset($filters['in_archive']) && $filters['in_archive'] !== '') {
            if ($filters['in_archive'] === 'yes') {
                $sql .= " AND b.archive_path IS NOT NULL";
            } else {
                $sql .= " AND b.archive_path IS NULL";
            }
        }

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return $result['count'] ?? 0;
    }

    /**
     * Получить книгу по ID
     */
    public function getBook($id)
    {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT b.*, 
                    COALESCE(r.avg_rating, 0) as avg_rating,
                    COALESCE(r.votes, 0) as votes
             FROM books b
             LEFT JOIN (
                 SELECT book_id, AVG(rating) as avg_rating, COUNT(*) as votes
                 FROM book_ratings
                 GROUP BY book_id
             ) r ON b.id = r.book_id
             WHERE b.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Сохранить книгу (добавить или обновить)
     */
    public function saveBook($data, $file = null)
    {
        $id = $data['id'] ?? null;

        // Валидация
        if (empty($data['title'])) {
            throw new Exception(__('admin_book_title_required'));
        }

        // Дополнительная валидация года
        if (!empty($data['year']) && ($data['year'] < 0 || $data['year'] > date('Y'))) {
            throw new Exception(__('admin_book_year_invalid'));
        }

        // Подготавливаем данные
        $title = trim($data['title']);
        $author = trim($data['author'] ?? '');
        $series = trim($data['series'] ?? '');
        $seriesNumber = !empty($data['series_number']) ? (int)$data['series_number'] : null;
        $genre = trim($data['genre'] ?? '');
        $year = !empty($data['year']) ? (int)$data['year'] : null;
        $language = trim($data['language'] ?? '');
        $publisher = trim($data['publisher'] ?? '');
        $description = trim($data['description'] ?? '');
        $fileType = trim($data['file_type'] ?? '');

        if ($id) {
            // ========== ОБНОВЛЕНИЕ СУЩЕСТВУЮЩЕЙ КНИГИ ==========
            $sql = "UPDATE books SET 
                title = :title, 
                author = :author, 
                series = :series, 
                series_number = :series_number,
                genre = :genre, 
                year = :year, 
                language = :language, 
                publisher = :publisher,
                description = :description, 
                file_type = :file_type,
                last_modified = CURRENT_TIMESTAMP
                WHERE id = :id";

            $stmt = $this->db->getConnection()->prepare($sql);
            $result = $stmt->execute([
                ':title' => $title,
                ':author' => $author,
                ':series' => $series,
                ':series_number' => $seriesNumber,
                ':genre' => $genre,
                ':year' => $year,
                ':language' => $language,
                ':publisher' => $publisher,
                ':description' => $description,
                ':file_type' => $fileType,
                ':id' => $id
            ]);

            if ($result) {
                // Очищаем кэш
                Cache::delete('book_v2_' . md5(serialize(['id' => $id])));
                Cache::invalidateByType('statistics');
                Cache::invalidateByType('search_results');
            }

            return $result;

        } else {
            // ========== ДОБАВЛЕНИЕ НОВОЙ КНИГИ ==========

            // Проверяем, что файл был загружен
            if (!$file || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception(__('admin_book_file_required'));
            }

            // Проверяем тип файла
            $allowedTypes = ['fb2', 'epub', 'pdf', 'txt'];
            $originalName = $file['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedTypes)) {
                throw new Exception(sprintf(__('admin_book_file_invalid_type'), implode(', ', $allowedTypes)));
            }

            // Проверяем размер файла (максимум 50 МБ)
            $maxSize = 50 * 1024 * 1024; // 50 MB
            if ($file['size'] > $maxSize) {
                throw new Exception(sprintf(__('admin_book_file_too_large'), 50));
            }

            // Получаем директорию для книг
            $booksDir = rtrim(Config::getBooksDir(), '/');

            // Проверяем, что директория существует
            if (!file_exists($booksDir)) {
                if (!mkdir($booksDir, 0755, true)) {
                    throw new Exception(sprintf(__('admin_book_cannot_create_dir'), $booksDir));
                }
            }

            // Проверяем права на запись
            if (!is_writable($booksDir)) {
                throw new Exception(sprintf(__('admin_book_dir_not_writable'), $booksDir));
            }

            // Генерируем безопасное имя файла (сохраняем оригинальное имя, но заменяем опасные символы)
            $safeFilename = $this->generateSafeFilename($originalName);
            $targetPath = $booksDir . '/' . $safeFilename;

            // Если файл с таким именем уже существует, добавляем номер
            $counter = 1;
            $originalPath = $targetPath;
            while (file_exists($targetPath)) {
                $pathinfo = pathinfo($originalPath);
                $targetPath = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '_' . $counter . '.' . $pathinfo['extension'];
                $counter++;
            }

            // ПРОСТО КОПИРУЕМ ФАЙЛ БЕЗ ИЗМЕНЕНИЙ
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                // Если move_uploaded_file не сработал, пробуем copy
                if (!copy($file['tmp_name'], $targetPath)) {
                    throw new Exception(__('admin_book_upload_failed'));
                }
                // Удаляем временный файл
                @unlink($file['tmp_name']);
            }

            // Устанавливаем права
            chmod($targetPath, 0644);

            // Проверяем, что файл действительно скопирован и не повреждён
            if (!file_exists($targetPath) || filesize($targetPath) != $file['size']) {
                throw new Exception(__('admin_book_upload_incomplete'));
            }

            // Сохраняем в базу данных
            $sql = "INSERT INTO books (
                    title, author, series, series_number,
                    genre, year, language, publisher,
                    description, file_type, file_path, file_name, file_size,
                    added_date, last_modified
                ) VALUES (
                    :title, :author, :series, :series_number,
                    :genre, :year, :language, :publisher,
                    :description, :file_type, :file_path, :file_name, :file_size,
                    :added_date, :last_modified
                )";

            $stmt = $this->db->getConnection()->prepare($sql);
            $result = $stmt->execute([
                ':title' => $title,
                ':author' => $author,
                ':series' => $series,
                ':series_number' => $seriesNumber,
                ':genre' => $genre,
                ':year' => $year,
                ':language' => $language,
                ':publisher' => $publisher,
                ':description' => $description,
                ':file_type' => $extension,
                ':file_path' => $targetPath,
                ':file_name' => $safeFilename,
                ':file_size' => $file['size'],
                ':added_date' => date('Y-m-d H:i:s'),
                ':last_modified' => date('Y-m-d H:i:s')
            ]);

            if ($result) {
                $newId = $this->db->getConnection()->lastInsertId();

                // Очищаем кэш
                Cache::invalidateByType('statistics');
                Cache::invalidateByType('search_results');

                // Логируем добавление
                error_log("New book added via admin panel: ID $newId - $title (file: $safeFilename, size: " . $file['size'] . " bytes)");
            }

            return $result;
        }
    }

    /**
     * Генерировать безопасное имя файла (сохраняем оригинальное имя)
     */
    private function generateSafeFilename($originalName)
    {
        // Получаем имя и расширение
        $pathinfo = pathinfo($originalName);
        $filename = $pathinfo['filename'];
        $extension = $pathinfo['extension'] ?? '';

        // Заменяем опасные символы на безопасные
        $filename = preg_replace('/[\/\\\:*?"<>|]/', '_', $filename);
        $filename = preg_replace('/\s+/', ' ', $filename);
        $filename = trim($filename);

        // Ограничиваем длину имени (100 символов достаточно)
        $filename = mb_substr($filename, 0, 100);

        // Если имя пустое, используем "book"
        if (empty($filename)) {
            $filename = 'book';
        }

        // Возвращаем полное имя
        return $filename . '.' . $extension;
    }


    /**
     * Удалить книгу
     */
    public function deleteBook($id)
    {
        // Получаем информацию о книге
        $book = $this->getBook($id);
        if (!$book) {
            throw new Exception(__('admin_book_not_found'));
        }

        // Начинаем транзакцию
        $this->db->getConnection()->beginTransaction();

        try {
            // Удаляем связанные записи
            $stmt = $this->db->getConnection()->prepare("DELETE FROM book_ratings WHERE book_id = ?");
            $stmt->execute([$id]);

            $stmt = $this->db->getConnection()->prepare("DELETE FROM book_favorites WHERE book_id = ?");
            $stmt->execute([$id]);

            // Удаляем книгу
            $stmt = $this->db->getConnection()->prepare("DELETE FROM books WHERE id = ?");
            $stmt->execute([$id]);

            $this->db->getConnection()->commit();

            // Очищаем кэш
            Cache::delete('book_v2_' . md5(serialize(['id' => $id])));
            Cache::invalidateByType('statistics');

            return true;

        } catch (Exception $e) {
            $this->db->getConnection()->rollBack();
            error_log("Error deleting book: " . $e->getMessage());
            throw new Exception(__('admin_book_delete_error') . ': ' . $e->getMessage());
        }
    }

    /**
     * Массовые операции с книгами
     */
    public function bulkAction($data)
    {
        $action = $data['bulk_action'] ?? '';
        $ids = $data['book_ids'] ?? [];

        if (empty($ids) || !is_array($ids)) {
            throw new Exception(__('admin_bulk_no_selection'));
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $affected = 0;

        try {
            $this->db->getConnection()->beginTransaction();

            switch ($action) {
                case 'delete':
                    // Удаляем связанные записи
                    $stmt = $this->db->getConnection()->prepare("DELETE FROM book_ratings WHERE book_id IN ($placeholders)");
                    $stmt->execute($ids);

                    $stmt = $this->db->getConnection()->prepare("DELETE FROM book_favorites WHERE book_id IN ($placeholders)");
                    $stmt->execute($ids);

                    // Удаляем книги
                    $stmt = $this->db->getConnection()->prepare("DELETE FROM books WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $affected = $stmt->rowCount();
                    break;

                case 'update_genre':
                    $genre = $data['bulk_genre'] ?? '';
                    if (empty($genre)) {
                        throw new Exception(__('admin_bulk_genre_select_error'));
                    }
                    $stmt = $this->db->getConnection()->prepare(
                        "UPDATE books SET genre = ? WHERE id IN ($placeholders)"
                    );
                    $params = array_merge([$genre], $ids);
                    $stmt->execute($params);
                    $affected = $stmt->rowCount();
                    break;

                case 'update_year':
                    $year = $data['bulk_year'] ?? '';
                    if (empty($year) || !is_numeric($year) || $year < 0 || $year > date('Y')) {
                        throw new Exception(__('admin_bulk_year_invalid'));
                    }
                    $stmt = $this->db->getConnection()->prepare(
                        "UPDATE books SET year = ? WHERE id IN ($placeholders)"
                    );
                    $params = array_merge([$year], $ids);
                    $stmt->execute($params);
                    $affected = $stmt->rowCount();
                    break;

                default:
                    throw new Exception(__('admin_bulk_select_error'));
            }

            $this->db->getConnection()->commit();

            // Очищаем кэш
            Cache::invalidateByType('statistics');

            return [
                'success' => true,
                'affected' => $affected,
                'message' => sprintf(__('admin_bulk_success'), $affected)
            ];

        } catch (Exception $e) {
            $this->db->getConnection()->rollBack();
            error_log("Error in bulk action: " . $e->getMessage());
            throw new Exception(__('admin_bulk_error') . ': ' . $e->getMessage());
        }
    }

    /**
     * Получить список всех жанров для фильтра
     */
    public function getAllGenres()
    {
        try {
            if (!$this->db || !$this->db->getConnection()) {
                error_log("BookManager::getAllGenres() - Database not available");
                return [];
            }

            $sql = "SELECT DISTINCT genre FROM books 
                    WHERE genre IS NOT NULL AND genre != '' 
                    ORDER BY genre";

            $stmt = $this->db->getConnection()->query($sql);
            $result = $stmt->fetchAll();

            error_log("getAllGenres() returned " . count($result) . " genres");
            return $result;

        } catch (Exception $e) {
            error_log("Error getting genres: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Получить список всех форматов для фильтра
     */
    public function getAllFileTypes()
    {
        try {
            if (!$this->db || !$this->db->getConnection()) {
                return [];
            }

            $sql = "SELECT DISTINCT file_type FROM books 
                    WHERE file_type IS NOT NULL AND file_type != '' 
                    ORDER BY file_type";

            $stmt = $this->db->getConnection()->query($sql);
            $result = $stmt->fetchAll();

            error_log("getAllFileTypes() returned " . count($result) . " file types");
            return $result;

        } catch (Exception $e) {
            error_log("Error getting file types: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Получить список авторов для фильтра
     */
    public function getAllAuthors()
    {
        try {
            if (!$this->db || !$this->db->getConnection()) {
                return [];
            }

            $sql = "SELECT DISTINCT author FROM books 
                    WHERE author IS NOT NULL AND author != '' 
                    ORDER BY author 
                    LIMIT 1000";

            $stmt = $this->db->getConnection()->query($sql);
            $result = $stmt->fetchAll();

            error_log("getAllAuthors() returned " . count($result) . " authors");
            return $result;

        } catch (Exception $e) {
            error_log("Error getting authors: " . $e->getMessage());
            return [];
        }
    }

}
