#include "database.h"
#include "common.h"
#include "database_mysql.h"
#include "utils.h"
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>

DatabaseHandle* db_connect(Config* config)
{
    if (!config) {
        log_message(NULL, "ERROR", "Config is NULL");
        return NULL;
    }

    log_message(config, "DEBUG", "Attempting to connect to database type: %s",
        config->database.type);

    DatabaseHandle* db_handle = malloc(sizeof(DatabaseHandle));
    if (!db_handle) {
        log_message(config, "ERROR",
            "Failed to allocate memory for database handle");
        return NULL;
    }

    // Инициализируем структуру
    db_handle->connection = NULL;
    db_handle->db_type = -1; // Устанавливаем недопустимое значение по умолчанию

    if (strcmp(config->database.type, "sqlite") == 0) {
        log_message(config, "DEBUG", "Connecting to SQLite database...");
        db_handle->db_type = DB_SQLITE;
        sqlite3* db;

        // Открываем базу данных
        int rc = sqlite3_open(config->database.path, &db);
        if (rc == SQLITE_OK) {
            db_handle->connection = db;
            log_message(config, "INFO", "Connected to SQLite database: %s",
                config->database.path);

            // Включаем поддержку внешних ключей
            sqlite3_exec(db, "PRAGMA foreign_keys = ON;", NULL, NULL, NULL);

            return db_handle;
        } else {
            log_message(config, "ERROR", "Cannot open SQLite database: %s",
                sqlite3_errmsg(db));
            sqlite3_close(db);
            free(db_handle);
            return NULL;
        }
    } else if (strcmp(config->database.type, "mysql") == 0) {
        log_message(config, "DEBUG", "Connecting to MySQL database...");
        db_handle->db_type = DB_MYSQL;
        MySQLConnection* mysql_conn = mysql_conn_connect(config);
        if (mysql_conn) {
            db_handle->connection = mysql_conn;
            log_message(config, "INFO", "Connected to MySQL database");
            return db_handle;
        } else {
            log_message(config, "ERROR", "Failed to connect to MySQL database");
            free(db_handle);
            return NULL;
        }
    } else {
        log_message(config, "ERROR", "Unknown database type: %s",
            config->database.type);
        free(db_handle);
        return NULL;
    }
}

void db_close(DatabaseHandle* db_handle)
{
    if (!db_handle)
        return;

    switch (db_handle->db_type) {
    case DB_SQLITE:
        if (db_handle->connection) {
            sqlite3_close((sqlite3*)db_handle->connection);
        }
        break;
    case DB_MYSQL:
        if (db_handle->connection) {
            mysql_conn_close((MySQLConnection*)db_handle->connection);
        }
        break;
    default:
        // Ничего не делаем для неизвестного типа
        break;
    }
    free(db_handle);
}

int db_execute(DatabaseHandle* db_handle, const char* sql, Config* config)
{
    if (!db_handle || !db_handle->connection) {
        log_message(config, "ERROR", "No database connection for execute");
        return 0;
    }

    switch (db_handle->db_type) {
    case DB_SQLITE: {
        char* err_msg = NULL;
        int rc = sqlite3_exec((sqlite3*)db_handle->connection, sql, NULL, NULL,
            &err_msg);
        if (rc != SQLITE_OK) {
            log_message(config, "ERROR", "SQL error: %s", err_msg);
            sqlite3_free(err_msg);
            return 0;
        }
        return 1;
    }
    case DB_MYSQL:
        return mysql_execute_query((MySQLConnection*)db_handle->connection, sql,
            config);
    default:
        log_message(config, "ERROR", "Unknown database type in execute: %d",
            db_handle->db_type);
        return 0;
    }
}

int create_database_tables(DatabaseHandle* db_handle, Config* config)
{
    if (!db_handle || !db_handle->connection) {
        log_message(config, "ERROR", "No database connection for creating tables");
        return 0;
    }

    switch (db_handle->db_type) {
    case DB_SQLITE: {
        const char* create_books_table = "CREATE TABLE IF NOT EXISTS books ("
                                         "    id INTEGER PRIMARY KEY AUTOINCREMENT,"
                                         "    file_path TEXT NOT NULL,"
                                         "    file_name TEXT NOT NULL,"
                                         "    file_size INTEGER,"
                                         "    file_type TEXT,"
                                         "    archive_path TEXT,"
                                         "    archive_internal_path TEXT,"
                                         "    file_hash TEXT UNIQUE,"
                                         "    title TEXT NOT NULL,"
                                         "    author TEXT NOT NULL,"
                                         "    genre TEXT,"
                                         "    series TEXT,"
                                         "    series_number INTEGER,"
                                         "    year INTEGER,"
                                         "    language TEXT,"
                                         "    publisher TEXT,"
                                         "    description TEXT,"
                                         "    added_date DATETIME DEFAULT CURRENT_TIMESTAMP,"
                                         "    last_modified DATETIME DEFAULT CURRENT_TIMESTAMP,"
                                         "    last_scanned DATETIME DEFAULT CURRENT_TIMESTAMP,"
                                         "    file_mtime INTEGER,"
                                         "    UNIQUE(file_path, archive_path, archive_internal_path)"
                                         ");";

        if (!db_execute(db_handle, create_books_table, config)) {
            log_message(config, "ERROR", "Failed to create books table");
            return 0;
        }

        log_message(config, "DEBUG", "Books table created successfully");

        // Дополнительно создаём индекс для ускорения поиска по хешу
        const char* create_hash_index = "CREATE INDEX IF NOT EXISTS idx_books_file_hash ON books(file_hash);";
        if (!db_execute(db_handle, create_hash_index, config)) {
            log_message(config, "WARNING", "Failed to create hash index");
        }

        break;
    }
    case DB_MYSQL:
        return mysql_create_tables((MySQLConnection*)db_handle->connection,
            config);
    default:
        log_message(config, "ERROR", "Unknown database type in create tables: %d",
            db_handle->db_type);
        return 0;
    }

    if (!create_archive_table(db_handle, config)) {
        return 0;
    }

    log_message(config, "INFO", "Database tables created successfully");
    return 1;
}

int create_archive_table(DatabaseHandle* db_handle, Config* config)
{
    if (!db_handle || !db_handle->connection) {
        log_message(config, "ERROR",
            "No database connection for creating archive table");
        return 0;
    }

    switch (db_handle->db_type) {
    case DB_SQLITE: {
        const char* create_archives_table = "CREATE TABLE IF NOT EXISTS archives ("
                                            "    id INTEGER PRIMARY KEY AUTOINCREMENT,"
                                            "    archive_path TEXT UNIQUE NOT NULL,"
                                            "    archive_hash TEXT,"
                                            "    file_count INTEGER DEFAULT 0,"
                                            "    total_size INTEGER DEFAULT 0,"
                                            "    last_modified INTEGER,"
                                            "    last_scanned DATETIME DEFAULT CURRENT_TIMESTAMP,"
                                            "    needs_rescan BOOLEAN DEFAULT 1"
                                            ");";

        if (!db_execute(db_handle, create_archives_table, config)) {
            log_message(config, "ERROR", "Failed to create archives table");
            return 0;
        }

        log_message(config, "DEBUG", "Archives table created successfully");
        break;
    }
    case DB_MYSQL:
        return mysql_create_archive_table((MySQLConnection*)db_handle->connection,
            config);
    default:
        log_message(config, "ERROR",
            "Unknown database type in create archive table: %d",
            db_handle->db_type);

        return 0;
    }

    if (!create_ratings_table(db_handle, config)) {
        return 0;
    }

    return 1;
}

int create_ratings_table(DatabaseHandle* db_handle, Config* config)
{
    if (!db_handle || !db_handle->connection) {
        log_message(config, "ERROR",
            "No database connection for creating ratings table");
        return 0;
    }

    switch (db_handle->db_type) {
    case DB_SQLITE: {
        const char* create_ratings_table_sql = "CREATE TABLE IF NOT EXISTS book_ratings ("
                                               "    id INTEGER PRIMARY KEY AUTOINCREMENT,"
                                               "    book_id INTEGER NOT NULL,"
                                               "    user_ip VARCHAR(45) NOT NULL,"
                                               "    rating INTEGER NOT NULL CHECK (rating >= 1 AND rating <= 5),"
                                               "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
                                               "    UNIQUE(user_ip, book_id),"
                                               "    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE"
                                               ");";

        if (!db_execute(db_handle, create_ratings_table_sql, config)) {
            log_message(config, "ERROR", "Failed to create ratings table");
            return 0;
        }

        log_message(config, "DEBUG", "Ratings table created successfully");
        break;
    }
    case DB_MYSQL:
        return mysql_create_ratings_table((MySQLConnection*)db_handle->connection,
            config);
    default:
        log_message(config, "ERROR",
            "Unknown database type in create ratings table: %d",
            db_handle->db_type);
        return 0;
    }

    if (!create_favorites_table(db_handle, config)) {
        return 0;
    }

    return 1;
}

int create_favorites_table(DatabaseHandle* db_handle, Config* config)
{
    if (!db_handle || !db_handle->connection) {
        log_message(config, "ERROR",
            "No database connection for creating favorites table");
        return 0;
    }

    switch (db_handle->db_type) {
    case DB_SQLITE: {
        const char* create_favorites_table_sql = "CREATE TABLE IF NOT EXISTS book_favorites ("
                                                 "    id INTEGER PRIMARY KEY AUTOINCREMENT,"
                                                 "    book_id INTEGER NOT NULL,"
                                                 "    user_ip VARCHAR(45) NOT NULL,"
                                                 "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
                                                 "    UNIQUE(user_ip, book_id),"
                                                 "    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE"
                                                 ");";

        if (!db_execute(db_handle, create_favorites_table_sql, config)) {
            log_message(config, "ERROR", "Failed to create favorites table");
            return 0;
        }

        log_message(config, "DEBUG", "Favorites table created successfully");
        break;
    }
    case DB_MYSQL:
        return mysql_create_favorites_table(
            (MySQLConnection*)db_handle->connection, config);
    default:
        log_message(config, "ERROR",
            "Unknown database type in create favorites table: %d",
            db_handle->db_type);
        return 0;
    }
    return 1;
}

int archive_needs_rescan(DatabaseHandle* db_handle, const char* archive_path,
    const char* current_hash, Config* config)
{
    if (!db_handle || !db_handle->connection) {
        log_message(config, "DEBUG",
            "[ARCHIVE_NEEDS_RESCAN] No database connection");
        return 1;
    }

    struct stat st;
    if (stat(archive_path, &st) == -1) {
        log_message(config, "DEBUG",
            "[ARCHIVE_NEEDS_RESCAN] Cannot stat archive: %s", archive_path);
        return 1;
    }

    if (config->scanner.rescan_unchanged) {
        log_message(config, "DEBUG",
            "[ARCHIVE_NEEDS_RESCAN] Forced rescan enabled for: %s",
            archive_path);
        return 1;
    }

    switch (db_handle->db_type) {
    case DB_SQLITE: {
        sqlite3* db = (sqlite3*)db_handle->connection;

        const char* sql = "SELECT archive_hash, last_modified, needs_rescan FROM "
                          "archives WHERE archive_path = ?";
        sqlite3_stmt* stmt;

        if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
            sqlite3_bind_text(stmt, 1, archive_path, -1, SQLITE_STATIC);

            if (sqlite3_step(stmt) == SQLITE_ROW) {
                const char* stored_hash = (const char*)sqlite3_column_text(stmt, 0);
                time_t stored_mtime = sqlite3_column_int64(stmt, 1);
                int needs_rescan = sqlite3_column_int(stmt, 2);

                log_message(config, "DEBUG",
                    "[ARCHIVE_NEEDS_RESCAN] Found in DB: hash=%s, mtime=%ld, "
                    "needs_rescan=%d",
                    stored_hash ? stored_hash : "NULL", stored_mtime,
                    needs_rescan);

                if (needs_rescan) {
                    log_message(config, "DEBUG",
                        "[ARCHIVE_NEEDS_RESCAN] Flag needs_rescan=TRUE for: %s",
                        archive_path);
                    sqlite3_finalize(stmt);
                    return 1;
                }

                if (stored_hash && current_hash && strcmp(stored_hash, current_hash) == 0 && stored_mtime == st.st_mtime) {

                    log_message(config, "DEBUG",
                        "[ARCHIVE_NEEDS_RESCAN] Archive unchanged, skipping: %s",
                        archive_path);

                    const char* update_sql = "UPDATE archives SET last_scanned = "
                                             "CURRENT_TIMESTAMP WHERE archive_path = ?";
                    sqlite3_stmt* update_stmt;
                    if (sqlite3_prepare_v2(db, update_sql, -1, &update_stmt, NULL) == SQLITE_OK) {
                        sqlite3_bind_text(update_stmt, 1, archive_path, -1, SQLITE_STATIC);
                        sqlite3_step(update_stmt);
                        sqlite3_finalize(update_stmt);
                    }

                    sqlite3_finalize(stmt);
                    return 0;
                } else {
                    log_message(config, "DEBUG",
                        "[ARCHIVE_NEEDS_RESCAN] Archive changed: %s",
                        archive_path);
                }
            } else {
                log_message(config, "DEBUG",
                    "[ARCHIVE_NEEDS_RESCAN] Archive not in database: %s",
                    archive_path);
            }
            sqlite3_finalize(stmt);
        } else {
            log_message(config, "ERROR",
                "[ARCHIVE_NEEDS_RESCAN] SQLite prepare failed: %s",
                sqlite3_errmsg(db));
        }
        break;
    }

    case DB_MYSQL:
        return mysql_archive_needs_rescan((MySQLConnection*)db_handle->connection,
            archive_path, current_hash, config);

    default:
        log_message(config, "ERROR",
            "[ARCHIVE_NEEDS_RESCAN] Unknown database type: %d",
            db_handle->db_type);
        return 1;
    }

    return 1;
}

void update_archive_info(DatabaseHandle* db_handle, const char* archive_path,
    const char* hash, int file_count, long total_size,
    Config* config)
{
    if (!db_handle || !db_handle->connection) {
        log_message(config, "ERROR",
            "No database connection for update archive info");
        return;
    }

    switch (db_handle->db_type) {
    case DB_SQLITE: {
        struct stat st;
        if (stat(archive_path, &st) != 0) {
            log_message(config, "ERROR", "Cannot stat archive for update: %s",
                archive_path);
            return;
        }

        sqlite3* db = (sqlite3*)db_handle->connection;
        const char* sql = "INSERT OR REPLACE INTO archives (archive_path, archive_hash, "
                          "file_count, total_size, last_modified, last_scanned, needs_rescan) "
                          "VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 0)";
        sqlite3_stmt* stmt;

        if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
            sqlite3_bind_text(stmt, 1, archive_path, -1, SQLITE_STATIC);
            sqlite3_bind_text(stmt, 2, hash, -1, SQLITE_STATIC);
            sqlite3_bind_int(stmt, 3, file_count);
            sqlite3_bind_int64(stmt, 4, total_size);
            sqlite3_bind_int64(stmt, 5, st.st_mtime);

            if (sqlite3_step(stmt) != SQLITE_DONE) {
                log_message(config, "ERROR", "Failed to update archive info: %s",
                    sqlite3_errmsg(db));
            } else {
                log_message(config, "DEBUG",
                    "Updated archive info: %s (%d files, %ld bytes)",
                    archive_path, file_count, total_size);
            }
            sqlite3_finalize(stmt);
        } else {
            log_message(config, "ERROR",
                "Failed to prepare statement for update archive info: %s",
                sqlite3_errmsg(db));
        }
        break;
    }
    case DB_MYSQL:
        mysql_update_archive_info((MySQLConnection*)db_handle->connection,
            archive_path, hash, file_count, total_size,
            config);
        break;
    default:
        log_message(config, "ERROR",
            "Unknown database type in update archive info: %d",
            db_handle->db_type);
        break;
    }
}

int book_exists(DatabaseHandle* db_handle, const char* filepath,
    const char* archive_path, const char* internal_path,
    const char* file_hash, Config* config)
{
    if (!db_handle || !db_handle->connection) {
        log_message(config, "ERROR", "No database connection for book_exists");
        return 0;
    }

    switch (db_handle->db_type) {
    case DB_SQLITE: {
        sqlite3* db = (sqlite3*)db_handle->connection;

        if (file_hash && !config->scanner.rescan_unchanged) {
            const char* sql = "SELECT id, file_path FROM books WHERE file_hash = ?";
            sqlite3_stmt* stmt;

            if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
                sqlite3_bind_text(stmt, 1, file_hash, -1, SQLITE_STATIC);

                if (sqlite3_step(stmt) == SQLITE_ROW) {
                    int existing_id = sqlite3_column_int(stmt, 0);
                    const char* existing_path = (const char*)sqlite3_column_text(stmt, 1);

                    log_message(config, "DEBUG",
                        "Book already exists (hash match): ID=%d, Path=%s",
                        existing_id, existing_path);
                    sqlite3_finalize(stmt);
                    return 1;
                }
                sqlite3_finalize(stmt);
            }
        }

        const char* sql = "SELECT id FROM books WHERE file_path = ? AND "
                          "archive_path IS ? AND archive_internal_path IS ?";
        sqlite3_stmt* stmt;

        if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
            sqlite3_bind_text(stmt, 1, filepath, -1, SQLITE_STATIC);
            sqlite3_bind_text(stmt, 2, archive_path, -1, SQLITE_STATIC);
            sqlite3_bind_text(stmt, 3, internal_path, -1, SQLITE_STATIC);

            int exists = (sqlite3_step(stmt) == SQLITE_ROW);
            sqlite3_finalize(stmt);

            if (exists) {
                log_message(config, "DEBUG", "Book already exists (path match): %s",
                    filepath);
                return 1;
            }
        }
        break;
    }
    case DB_MYSQL:
        return mysql_book_exists((MySQLConnection*)db_handle->connection, filepath,
            archive_path, internal_path, file_hash, config);
    default:
        log_message(config, "ERROR", "Unknown database type in book_exists: %d",
            db_handle->db_type);
        return 0;
    }

    return 0;
}

void insert_book_to_db(DatabaseHandle* db_handle, const char* filepath,
    BookMeta* meta, const char* archive_path,
    const char* internal_path, const char* file_hash, Config* config)
{
    if (!db_handle) {
        log_message(config, "ERROR", "[INSERT_BOOK_TO_DB] Database handle is NULL");
        return;
    }

    if (!db_handle->connection) {
        log_message(config, "ERROR", "[INSERT_BOOK_TO_DB] Database connection is NULL");
        return;
    }

    if (!filepath) {
        log_message(config, "ERROR", "[INSERT_BOOK_TO_DB] filepath is NULL");
        return;
    }

    if (!meta) {
        log_message(config, "ERROR", "[INSERT_BOOK_TO_DB] meta is NULL");
        return;
    }

    // Подавляем warning о неиспользуемом параметре
    (void)file_hash;

    log_message(config, "DEBUG", "[INSERT_BOOK_TO_DB] Inserting book: %s", filepath);
    log_message(config, "DEBUG", "[INSERT_BOOK_TO_DB] Database type: %d", db_handle->db_type);

    switch (db_handle->db_type) {
    case DB_SQLITE: {
        sqlite3* db = (sqlite3*)db_handle->connection;

        // Проверяем существование книги
        if (book_exists(db_handle, filepath, archive_path, internal_path, NULL, config)) {
            log_message(config, "DEBUG", "[INSERT_BOOK_TO_DB] Book already exists, skipping: %s", filepath);
            return;
        }

        // ИСПРАВЛЕНО: 16 полей для вставки, 16 знаков вопроса
        const char* sql = "INSERT INTO books ("
                          "file_path, file_name, file_size, file_type, "
                          "archive_path, archive_internal_path, file_hash, title, author, "
                          "genre, series, series_number, year, language, publisher, description"
                          ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        sqlite3_stmt* stmt;
        int rc = sqlite3_prepare_v2(db, sql, -1, &stmt, NULL);
        if (rc != SQLITE_OK) {
            log_message(config, "ERROR", "Failed to prepare SQL statement: %s", sqlite3_errmsg(db));
            return;
        }

        // Извлекаем имя файла
        const char* filename = "unknown";
        if (internal_path && internal_path[0] != '\0') {
            filename = internal_path;
        } else {
            const char* slash = strrchr(filepath, '/');
            filename = slash ? slash + 1 : filepath;
        }

        // Извлекаем расширение для file_type
        const char* file_type = normalize_file_type(filename);

        log_message(config, "DEBUG", "[INSERT_BOOK_TO_DB] File: %s, Type: %s", filename, file_type);

        // Биндим 16 параметров
        int param = 1;

        // 1. file_path
        sqlite3_bind_text(stmt, param++, filepath, -1, SQLITE_STATIC);

        // 2. file_name
        sqlite3_bind_text(stmt, param++, filename, -1, SQLITE_STATIC);

        // 3. file_size
        if (meta->file_size > 0) {
            sqlite3_bind_int64(stmt, param++, meta->file_size);
        } else {
            sqlite3_bind_null(stmt, param++);
        }

        // 4. file_type
        sqlite3_bind_text(stmt, param++, file_type, -1, SQLITE_STATIC);

        // 5. archive_path
        if (archive_path && archive_path[0] != '\0') {
            sqlite3_bind_text(stmt, param++, archive_path, -1, SQLITE_STATIC);
        } else {
            sqlite3_bind_null(stmt, param++);
        }

        // 6. archive_internal_path
        if (internal_path && internal_path[0] != '\0') {
            sqlite3_bind_text(stmt, param++, internal_path, -1, SQLITE_STATIC);
        } else {
            sqlite3_bind_null(stmt, param++);
        }

        // 7. file_hash (используем meta->file_hash, а не параметр file_hash)
        if (meta->file_hash && meta->file_hash[0] != '\0') {
            sqlite3_bind_text(stmt, param++, meta->file_hash, -1, SQLITE_STATIC);
        } else {
            sqlite3_bind_null(stmt, param++);
        }

        // 8. title
        const char* title = (meta->title && meta->title[0] != '\0') ? meta->title : "Unknown Title";
        sqlite3_bind_text(stmt, param++, title, -1, SQLITE_STATIC);

        // 9. author
        const char* author = (meta->author && meta->author[0] != '\0') ? meta->author : "Unknown Author";
        sqlite3_bind_text(stmt, param++, author, -1, SQLITE_STATIC);

        // 10. genre
        if (meta->genre && meta->genre[0] != '\0') {
            sqlite3_bind_text(stmt, param++, meta->genre, -1, SQLITE_STATIC);
        } else {
            sqlite3_bind_null(stmt, param++);
        }

        // 11. series
        if (meta->series && meta->series[0] != '\0') {
            sqlite3_bind_text(stmt, param++, meta->series, -1, SQLITE_STATIC);
        } else {
            sqlite3_bind_null(stmt, param++);
        }

        // 12. series_number
        if (meta->series_number > 0) {
            sqlite3_bind_int(stmt, param++, meta->series_number);
        } else {
            sqlite3_bind_null(stmt, param++);
        }

        // 13. year
        if (meta->year > 0) {
            sqlite3_bind_int(stmt, param++, meta->year);
        } else {
            sqlite3_bind_null(stmt, param++);
        }

        // 14. language
        if (meta->language && meta->language[0] != '\0') {
            sqlite3_bind_text(stmt, param++, meta->language, -1, SQLITE_STATIC);
        } else {
            sqlite3_bind_null(stmt, param++);
        }

        // 15. publisher
        if (meta->publisher && meta->publisher[0] != '\0') {
            sqlite3_bind_text(stmt, param++, meta->publisher, -1, SQLITE_STATIC);
        } else {
            sqlite3_bind_null(stmt, param++);
        }

        // 16. description
        if (meta->description && meta->description[0] != '\0') {
            if (strlen(meta->description) > 1000) {
                char* short_desc = strndup(meta->description, 1000);
                sqlite3_bind_text(stmt, param++, short_desc, -1, SQLITE_TRANSIENT);
                free(short_desc);
            } else {
                sqlite3_bind_text(stmt, param++, meta->description, -1, SQLITE_STATIC);
            }
        } else {
            sqlite3_bind_null(stmt, param++);
        }

        // last_modified, added_date, last_scanned имеют DEFAULT CURRENT_TIMESTAMP
        // поэтому их не нужно указывать в INSERT

        rc = sqlite3_step(stmt);
        if (rc != SQLITE_DONE) {
            log_message(config, "ERROR", "Failed to insert book: %s (error code: %d, rc=%d)",
                sqlite3_errmsg(db), rc, rc);
        } else {
            log_message(config, "INFO", "Book inserted successfully: %s (type: %s)",
                meta->title ? meta->title : filename, file_type);
        }

        sqlite3_finalize(stmt);
        break;
    }
    case DB_MYSQL: {
        MySQLConnection* mysql_conn = (MySQLConnection*)db_handle->connection;

        if (!mysql_conn || !mysql_conn->mysql) {
            log_message(config, "ERROR", "[INSERT_BOOK_TO_DB] MySQL connection is invalid");
            return;
        }

        mysql_insert_book(mysql_conn, filepath, meta, archive_path, internal_path,
            meta->file_hash, config);
        break;
    }
    default:
        log_message(config, "ERROR", "[INSERT_BOOK_TO_DB] Unknown database type: %d", db_handle->db_type);
        break;
    }
}
