<?php

// lib/OpdsGenerator.php

require_once __DIR__.'/Database.php';
require_once __DIR__.'/GenreManager.php';
require_once __DIR__.'/../init.php';

class OpdsGenerator
{
    private $db;
    private $baseUrl;

    public function __construct($baseUrl)
    {
        $this->db = Database::getInstance();
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function generateCatalog()
    {
        header('Content-Type: application/atom+xml; charset=utf-8');

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = 25;

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');

        $xml->writeElement('id', ConfigData::OPDS_ID.($page > 1 ? ':page:'.$page : ''));
        $xml->writeElement('title', ConfigData::OPDS_TITLE.($page > 1 ? ' - '.__('opds_page').' '.$page : ''));
        $xml->writeElement('updated', date('c'));
        $xml->writeElement('icon', $this->baseUrl.'/favicon.ico');

        $xml->startElement('author');
        $xml->writeElement('name', ConfigData::OPDS_AUTHOR);
        $xml->endElement();

        // Ссылка на сам каталог
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php'.($page > 1 ? '?page='.$page : ''));
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        // Ссылка на стартовую страницу
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        // Навигационные ссылки
        $this->addNavigationLinks($xml);

        // Пагинация
        $this->addPaginationLinks($xml, $page, $perPage, 'catalog');

        // Книги с пагинацией
        $books = $this->db->getRecentBooks($perPage, ($page - 1) * $perPage);
        foreach ($books as $book) {
            $this->addBookEntry($xml, $book);
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * Генерация результатов поиска.
     */
    public function generateSearchResults($query, $page = 1)
    {
        error_log('OPDS Search called with query: '.$query.', page: '.$page);

        header('Content-Type: application/atom+xml; charset=utf-8');

        $perPage = 25;
        $booksCount = 0;

        try {
            $booksCount = $this->db->getSearchCount($query, 'all');
            error_log("OPDS Search count for '$query': ".$booksCount);

            $totalPages = $booksCount > 0 ? ceil($booksCount / $perPage) : 0;

            $xml = new XMLWriter();
            $xml->openMemory();
            $xml->setIndent(true);
            $xml->setIndentString('  ');

            $xml->startDocument('1.0', 'UTF-8');

            $xml->startElement('feed');
            $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
            $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
            $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');

            $xml->writeElement('id', ConfigData::OPDS_ID.':search:'.urlencode($query).($page > 1 ? ':page:'.$page : ''));
            $xml->writeElement('title', sprintf(__('opds_search_results'), htmlspecialchars($query)).($page > 1 ? ' - '.__('opds_page').' '.$page : ''));
            $xml->writeElement('updated', date('c'));
            $xml->writeElement('icon', $this->baseUrl.'/favicon.ico');

            $xml->startElement('author');
            $xml->writeElement('name', ConfigData::OPDS_AUTHOR);
            $xml->endElement();

            // Ссылка на сам каталог
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'self');
            $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?search='.urlencode($query).($page > 1 ? '&page='.$page : ''));
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
            $xml->endElement();

            // Ссылка на стартовую страницу
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'start');
            $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
            $xml->endElement();

            // Ссылка на поиск
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'search');
            $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?search={searchTerms}');
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
            $xml->writeAttribute('opds:searchType', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
            $xml->endElement();

            // Навигационные ссылки
            $this->addNavigationLinks($xml);

            // Пагинация
            $this->addPaginationLinks($xml, $page, $perPage, 'search', $totalPages, ['search' => $query]);

            $books = $this->db->searchBooks($query, 'all', $page, $perPage);
            error_log('OPDS: Found '.count($books).' books');

            if (count($books) > 0) {
                foreach ($books as $book) {
                    $this->addBookEntry($xml, $book);
                }
            } else {
                // Добавляем сообщение если ничего не найдено
                $xml->startElement('entry');
                $xml->writeElement('title', sprintf(__('opds_no_results'), htmlspecialchars($query)));
                $xml->writeElement('updated', date('c'));
                $xml->writeElement('content', __('opds_try_different'), 'text');

                $xml->startElement('link');
                $xml->writeAttribute('rel', 'alternate');
                $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
                $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
                $xml->endElement();

                $xml->endElement();
            }

            $xml->endElement();

            return $xml->outputMemory();
        } catch (Exception $e) {
            error_log('OPDS Search error: '.$e->getMessage());

            $xml = new XMLWriter();
            $xml->openMemory();
            $xml->setIndent(true);

            $xml->startDocument('1.0', 'UTF-8');

            $xml->startElement('feed');
            $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
            $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
            $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');

            $xml->writeElement('id', ConfigData::OPDS_ID.':search:error');
            $xml->writeElement('title', __('opds_search_error').' - '.ConfigData::OPDS_TITLE);
            $xml->writeElement('updated', date('c'));
            $xml->writeElement('icon', $this->baseUrl.'/favicon.ico');

            $xml->startElement('author');
            $xml->writeElement('name', ConfigData::OPDS_AUTHOR);
            $xml->endElement();

            $xml->startElement('entry');
            $xml->writeElement('title', __('opds_search_error'));
            $xml->writeElement('updated', date('c'));
            $xml->writeElement('content', sprintf(__('opds_search_error_desc'), htmlspecialchars($query)), 'text');

            $xml->startElement('link');
            $xml->writeAttribute('rel', 'alternate');
            $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
            $xml->endElement();

            $xml->endElement();

            $xml->endElement();

            return $xml->outputMemory();
        }
    }

    /**
     * Навигация по авторам
     */
    public function generateByAuthors($page = 1)
    {
        header('Content-Type: application/atom+xml; charset=utf-8');

        $perPage = 50;
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');

        $xml->writeElement('id', ConfigData::OPDS_ID.':authors:navigation'.($page > 1 ? ':page:'.$page : ''));
        $xml->writeElement('title', __('opds_browse_authors').($page > 1 ? ' - '.__('opds_page').' '.$page : ''));
        $xml->writeElement('updated', date('c'));

        $xml->startElement('author');
        $xml->writeElement('name', ConfigData::OPDS_AUTHOR);
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?by=authors'.($page > 1 ? '&page='.$page : ''));
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        $this->addBrowseLinks($xml);

        $authors = $this->db->getTopAuthors($perPage);

        foreach ($authors as $author) {
            $xml->startElement('entry');

            $xml->writeElement('title', htmlspecialchars($author['author']).' ('.$author['count'].')');
            $xml->writeElement('updated', date('c'));
            $xml->writeElement('content', sprintf(__('opds_books_by_author'), $author['count'], htmlspecialchars($author['author'])));
            $xml->writeAttribute('type', 'text');

            $xml->startElement('link');
            $xml->writeAttribute('rel', 'subsection');
            $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?author='.urlencode($author['author']));
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
            $xml->endElement();

            $xml->endElement();
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * Навигация по жанрам
     */
    public function generateGenresNavigation()
    {
        header('Content-Type: application/atom+xml; charset=utf-8');

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');

        $xml->writeElement('id', ConfigData::OPDS_ID.':genres:navigation');
        $xml->writeElement('title', __('opds_browse_genres'));
        $xml->writeElement('updated', date('c'));

        $xml->startElement('author');
        $xml->writeElement('name', ConfigData::OPDS_AUTHOR);
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?by=genres');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        $this->addBrowseLinks($xml);

        $genres = $this->db->getGenresWithCount();
        $genreCategories = $this->categorizeGenres($genres);

        foreach ($genreCategories as $category => $categoryGenres) {
            $xml->startElement('entry');
            $xml->writeElement('title', htmlspecialchars($category));
            $xml->writeElement('updated', date('c'));
            $xml->writeElement('content', sprintf(__('opds_genres_in_category'), count($categoryGenres), $category));
            $xml->writeAttribute('type', 'text');

            $xml->startElement('link');
            $xml->writeAttribute('rel', 'subsection');
            $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?by=genres&category='.urlencode($category));
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
            $xml->endElement();

            $xml->endElement();
        }

        if (count($genres) <= 50) {
            $xml->startElement('entry');
            $xml->writeElement('title', __('opds_all_genres'));
            $xml->writeElement('updated', date('c'));
            $xml->writeElement('content', sprintf(__('opds_browse_all_genres'), count($genres)));
            $xml->writeAttribute('type', 'text');

            $xml->startElement('link');
            $xml->writeAttribute('rel', 'subsection');
            $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?by=genres&view=all');
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
            $xml->endElement();

            $xml->endElement();
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * Детальная навигация по жанрам (по категориям).
     */
    public function generateGenresByCategory($category)
    {
        header('Content-Type: application/atom+xml; charset=utf-8');

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');

        $xml->writeElement('id', ConfigData::OPDS_ID.':genres:category:'.urlencode($category));
        $xml->writeElement('title', sprintf(__('opds_genres_category'), htmlspecialchars($category)));
        $xml->writeElement('updated', date('c'));

        $xml->startElement('author');
        $xml->writeElement('name', ConfigData::OPDS_AUTHOR);
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?by=genres&category='.urlencode($category));
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        $this->addBrowseLinks($xml);

        $genres = $this->db->getGenresWithCount();
        $categoryGenres = $this->getGenresByCategory($genres, $category);

        foreach ($categoryGenres as $genre) {
            $xml->startElement('entry');

            $genreName = $genre['readable_name'] ?: $genre['genre'];
            $xml->writeElement('title', htmlspecialchars($genreName).' ('.$genre['count'].')');
            $xml->writeElement('updated', date('c'));
            $xml->writeElement('content', sprintf(__('opds_books_in_genre'), $genre['count'], htmlspecialchars($genreName)));
            $xml->writeAttribute('type', 'text');

            $xml->startElement('link');
            $xml->writeAttribute('rel', 'subsection');
            $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?genre='.urlencode($genre['genre']));
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
            $xml->endElement();

            $xml->endElement();
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * Все жанры в одном списке.
     */
    public function generateAllGenres()
    {
        header('Content-Type: application/atom+xml; charset=utf-8');

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = 50;

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');

        $xml->writeElement('id', ConfigData::OPDS_ID.':genres:all'.($page > 1 ? ':page:'.$page : ''));
        $xml->writeElement('title', __('opds_all_genres').($page > 1 ? ' - '.__('opds_page').' '.$page : ''));
        $xml->writeElement('updated', date('c'));

        $xml->startElement('author');
        $xml->writeElement('name', ConfigData::OPDS_AUTHOR);
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?by=genres&view=all'.($page > 1 ? '&page='.$page : ''));
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        $this->addBrowseLinks($xml);

        $allGenres = $this->db->getGenresWithCount();
        $totalGenres = count($allGenres);
        $totalPages = ceil($totalGenres / $perPage);

        $this->addPaginationLinks($xml, $page, $perPage, 'genres_all', $totalPages, ['by' => 'genres', 'view' => 'all']);

        $startIndex = ($page - 1) * $perPage;
        $genres = array_slice($allGenres, $startIndex, $perPage);

        foreach ($genres as $genre) {
            $xml->startElement('entry');

            $genreName = $genre['readable_name'] ?: $genre['genre'];
            $xml->writeElement('title', htmlspecialchars($genreName).' ('.$genre['count'].')');
            $xml->writeElement('updated', date('c'));
            $xml->writeElement('content', sprintf(__('opds_books_in_genre'), $genre['count'], htmlspecialchars($genreName)));
            $xml->writeAttribute('type', 'text');

            $xml->startElement('link');
            $xml->writeAttribute('rel', 'subsection');
            $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?genre='.urlencode($genre['genre']));
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
            $xml->endElement();

            $xml->endElement();
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * Каталог по авторам (старый метод для совместимости).
     */
    public function generateByGenres($page = 1)
    {
        $view = $_GET['view'] ?? '';
        $category = $_GET['category'] ?? '';

        if ($category) {
            return $this->generateGenresByCategory($category);
        } elseif ('all' === $view) {
            return $this->generateAllGenres($page);
        }

        return $this->generateGenresNavigation();
    }

    /**
     * Книги конкретного автора.
     */
    public function generateByAuthor($author, $page = 1)
    {
        header('Content-Type: application/atom+xml; charset=utf-8');

        $perPage = 25;
        $booksCount = $this->db->getBooksCountByAuthor($author);
        $totalPages = ceil($booksCount / $perPage);

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');

        $xml->writeElement('id', ConfigData::OPDS_ID.':author:'.urlencode($author).($page > 1 ? ':page:'.$page : ''));
        $xml->writeElement('title', sprintf(__('opds_books_by_author_title'), htmlspecialchars($author)).($page > 1 ? ' - '.__('opds_page').' '.$page : ''));
        $xml->writeElement('updated', date('c'));

        $xml->startElement('author');
        $xml->writeElement('name', ConfigData::OPDS_AUTHOR);
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?author='.urlencode($author).($page > 1 ? '&page='.$page : ''));
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'related');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?by=authors');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->writeAttribute('title', __('opds_browse_all_authors'));
        $xml->endElement();

        $this->addBrowseLinks($xml);

        $this->addPaginationLinks($xml, $page, $perPage, 'author', $totalPages, ['author' => $author]);

        $books = $this->db->getBooksByAuthor($author, $page, $perPage);
        foreach ($books as $book) {
            $this->addBookEntry($xml, $book);
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * Книги конкретного жанра.
     */
    public function generateByGenre($genre, $page = 1)
    {
        header('Content-Type: application/atom+xml; charset=utf-8');

        $perPage = 25;
        $booksCount = $this->db->getBooksCountByGenre($genre);
        $totalPages = ceil($booksCount / $perPage);

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');

        $readableGenre = GenreManager::getReadableName($genre);
        $displayGenre = $readableGenre ?: $genre;

        $xml->writeElement('id', ConfigData::OPDS_ID.':genre:'.urlencode($genre).($page > 1 ? ':page:'.$page : ''));
        $xml->writeElement('title', sprintf(__('opds_books_in_genre_title'), htmlspecialchars($displayGenre)).($page > 1 ? ' - '.__('opds_page').' '.$page : ''));
        $xml->writeElement('updated', date('c'));

        $xml->startElement('author');
        $xml->writeElement('name', ConfigData::OPDS_AUTHOR);
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?genre='.urlencode($genre).($page > 1 ? '&page='.$page : ''));
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'related');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?by=genres');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->writeAttribute('title', __('opds_browse_all_genres'));
        $xml->endElement();

        $this->addBrowseLinks($xml);

        $this->addPaginationLinks($xml, $page, $perPage, 'genre', $totalPages, ['genre' => $genre]);

        $books = $this->db->getBooksByGenre($genre, $page, $perPage);
        foreach ($books as $book) {
            $this->addBookEntry($xml, $book);
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * Книги конкретной серии.
     */
    public function generateBySeries($series, $page = 1)
    {
        header('Content-Type: application/atom+xml; charset=utf-8');

        $perPage = 25;
        $booksCount = $this->db->getBooksCountBySeries($series);
        $totalPages = ceil($booksCount / $perPage);

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');

        $xml->writeElement('id', ConfigData::OPDS_ID.':series:'.urlencode($series).($page > 1 ? ':page:'.$page : ''));
        $xml->writeElement('title', sprintf(__('opds_books_in_series'), htmlspecialchars($series)).($page > 1 ? ' - '.__('opds_page').' '.$page : ''));
        $xml->writeElement('updated', date('c'));

        $xml->startElement('author');
        $xml->writeElement('name', ConfigData::OPDS_AUTHOR);
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?series='.urlencode($series).($page > 1 ? '&page='.$page : ''));
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        $this->addBrowseLinks($xml);

        $this->addPaginationLinks($xml, $page, $perPage, 'series', $totalPages, ['series' => $series]);

        $books = $this->db->getBooksBySeries($series, $page, $perPage);
        foreach ($books as $book) {
            $this->addBookEntry($xml, $book);
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * Категоризация жанров.
     */
    private function categorizeGenres($genres)
    {
        $categories = GenreManager::getGenresByCategory();

        $categorized = [];

        foreach ($categories as $category => $categoryGenres) {
            $categorized[$category] = [];
            foreach ($categoryGenres as $code => $name) {
                foreach ($genres as $genre) {
                    if ($genre['genre'] === $code) {
                        $categorized[$category][] = $genre;
                        break;
                    }
                }
            }
        }

        return $categorized;
    }

    /**
     * Получить жанры по категории.
     */
    private function getGenresByCategory($genres, $category)
    {
        $categorized = $this->categorizeGenres($genres);

        return $categorized[$category] ?? [];
    }

    private function addNavigationLinks($xml)
    {
        // Новые книги
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'http://opds-spec.org/sort/new');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();

        // По авторам
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'subsection');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?by=authors');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->endElement();

        // По жанрам
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'subsection');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?by=genres');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->endElement();

        // Поиск
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'search');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?search={searchTerms}');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->writeAttribute('opds:searchType', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();
    }

    private function addBrowseLinks($xml)
    {
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'subsection');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?by=authors');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'subsection');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?by=genres');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();
    }

    private function addPaginationLinks($xml, $currentPage, $perPage, $type, $totalPages = null, $params = [])
    {
        if ($currentPage > 1) {
            $prevParams = http_build_query(array_merge($params, ['page' => $currentPage - 1]));
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'previous');
            $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?'.$prevParams);
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog');
            $xml->endElement();
        }

        if ($totalPages && $currentPage < $totalPages) {
            $nextParams = http_build_query(array_merge($params, ['page' => $currentPage + 1]));
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'next');
            $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?'.$nextParams);
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog');
            $xml->endElement();
        }

        if ($currentPage > 2) {
            $firstParams = http_build_query(array_merge($params, ['page' => 1]));
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'first');
            $xml->writeAttribute('href', $this->baseUrl.'/api/opds.php?'.$firstParams);
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog');
            $xml->endElement();
        }
    }

    private function addBookEntry($xml, $book)
    {
        $xml->startElement('entry');

        $xml->writeElement('id', ConfigData::OPDS_ID.':book:'.$book['id']);
        $xml->writeElement('title', htmlspecialchars($book['title'] ?: __('book_untitled')));
        $xml->writeElement('updated', date('c', strtotime($book['added_date'])));

        if ($book['author']) {
            $xml->startElement('author');
            $xml->writeElement('name', htmlspecialchars($book['author']));
            $xml->endElement();
        }

        // Описание
        if ($book['description']) {
            $description = substr($book['description'], 0, 500);
            $xml->writeElement('summary', htmlspecialchars($description));
            $xml->writeAttribute('type', 'text');
        }

        // Обложка
        $coverUrl = $this->baseUrl.'/api/cover.php?id='.$book['id'];
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'http://opds-spec.org/image');
        $xml->writeAttribute('href', $coverUrl);
        $xml->writeAttribute('type', 'image/jpeg');
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'http://opds-spec.org/image/thumbnail');
        $xml->writeAttribute('href', $coverUrl.'&thumb=1');
        $xml->writeAttribute('type', 'image/jpeg');
        $xml->endElement();

        // Ссылка для скачивания
        $downloadUrl = $this->baseUrl.'/api/download.php?id='.$book['id'];
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'http://opds-spec.org/acquisition/open-access');
        $xml->writeAttribute('href', $downloadUrl);
        $xml->writeAttribute('type', $this->getMimeType($book['file_type']));
        $xml->endElement();

        $xml->startElement('link');
        $xml->writeAttribute('rel', 'http://opds-spec.org/acquisition');
        $xml->writeAttribute('href', $downloadUrl);
        $xml->writeAttribute('type', $this->getMimeType($book['file_type']));
        $xml->endElement();

        // Метаданные
        if ($book['genre']) {
            $readableGenre = GenreManager::getReadableName($book['genre']);
            $xml->startElement('category');
            $xml->writeAttribute('term', htmlspecialchars($book['genre']));
            $xml->writeAttribute('label', htmlspecialchars($readableGenre ?: $book['genre']));
            $xml->endElement();
        }

        if ($book['language']) {
            $xml->writeElement('dc:language', htmlspecialchars($book['language']));
        }

        if ($book['publisher']) {
            $xml->writeElement('dc:publisher', htmlspecialchars($book['publisher']));
        }

        if ($book['year']) {
            $xml->writeElement('dc:issued', $book['year']);
        }

        $xml->endElement();
    }

    private function getMimeType($fileType)
    {
        $mimeTypes = [
            'epub' => 'application/epub+zip',
            'pdf' => 'application/pdf',
            'fb2' => 'application/x-fictionbook+xml',
            'mobi' => 'application/x-mobipocket-ebook',
            'txt' => 'text/plain',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
        ];

        return $mimeTypes[strtolower($fileType)] ?? 'application/octet-stream';
    }
}
