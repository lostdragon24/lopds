#include "common.h"
#include "database.h"
#include "database_mysql.h"
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>

DatabaseHandle* db_connect(Config *config) {
    if (!config) {
        return NULL;
    }

    log_message(config, "DEBUG", "Attempting to connect to database type: %s", config->database.type);

    DatabaseHandle *db_handle = malloc(sizeof(DatabaseHandle));
    if (!db_handle) {
        log_message(config, "ERROR", "Failed to allocate memory for database handle");
        return NULL;
    }

    db_handle->connection = NULL;
    db_handle->db_type = -1;

    if (strcmp(config->database.type, "sqlite") == 0) {
        log_message(config, "DEBUG", "Connecting to SQLite database...");
        db_handle->db_type = DB_SQLITE;
        sqlite3 *db;
        if (sqlite3_open(config->database.path, &db) == SQLITE_OK) {
            db_handle->connection = db;
            log_message(config, "INFO", "Connected to SQLite database: %s", config->database.path);
            return db_handle;
        } else {
            log_message(config, "ERROR", "Cannot open SQLite database: %s", sqlite3_errmsg(db));
            sqlite3_close(db);
        }
    }
    else if (strcmp(config->database.type, "mysql") == 0) {
        log_message(config, "DEBUG", "Connecting to MySQL database...");
        db_handle->db_type = DB_MYSQL;
        MySQLConnection *mysql_conn = mysql_conn_connect(config);
        if (mysql_conn) {
            db_handle->connection = mysql_conn;
            log_message(config, "INFO", "Connected to MySQL database");
            return db_handle;
        } else {
            log_message(config, "ERROR", "Failed to connect to MySQL database");
        }
    }
    else {
        log_message(config, "ERROR", "Unknown database type: %s", config->database.type);
    }

    log_message(config, "ERROR", "Database connection failed completely");
    free(db_handle);
    return NULL;
}

void db_close(DatabaseHandle *db_handle) {
    if (!db_handle) return;

    switch (db_handle->db_type) {
        case DB_SQLITE:
            sqlite3_close((sqlite3*)db_handle->connection);
            break;
        case DB_MYSQL:
            mysql_conn_close((MySQLConnection*)db_handle->connection);
            break;
        case DB_POSTGRESQL:
            break;
    }
    free(db_handle);
}

int db_execute(DatabaseHandle *db_handle, const char *sql, Config *config) {
    if (!db_handle || !db_handle->connection) return 0;

    switch (db_handle->db_type) {
        case DB_SQLITE: {
            char *err_msg = NULL;
            int rc = sqlite3_exec((sqlite3*)db_handle->connection, sql, NULL, NULL, &err_msg);
            if (rc != SQLITE_OK) {
                log_message(config, "ERROR", "SQL error: %s", err_msg);
                sqlite3_free(err_msg);
                return 0;
            }
            return 1;
        }
        case DB_MYSQL:
            return mysql_execute_query((MySQLConnection*)db_handle->connection, sql, config);
        default:
            return 0;
    }
}

int create_database_tables(DatabaseHandle *db_handle, Config *config) {
    if (!db_handle || !db_handle->connection) return 0;

    switch (db_handle->db_type) {
        case DB_SQLITE: {
            const char *create_books_table =
                "CREATE TABLE IF NOT EXISTS books ("
                "    id INTEGER PRIMARY KEY AUTOINCREMENT,"
                "    file_path TEXT,"
                "    file_name TEXT,"
                "    file_size INTEGER,"
                "    file_type TEXT,"
                "    archive_path TEXT,"
                "    archive_internal_path TEXT,"
                "    file_hash TEXT,"
                "    title TEXT,"
                "    author TEXT,"
                "    genre TEXT,"
                "    series TEXT,"
                "    series_number INTEGER,"
                "    year INTEGER,"
                "    language TEXT,"
                "    publisher TEXT,"
                "    description TEXT,"
                "    added_date DATETIME DEFAULT CURRENT_TIMESTAMP,"
                "    last_modified DATETIME,"
                "    last_scanned DATETIME,"
                "    file_mtime INTEGER,"
                "    UNIQUE(file_path, archive_path, archive_internal_path)"
                ");";

            if (!db_execute(db_handle, create_books_table, config)) {
                return 0;
            }
            break;
        }
        case DB_MYSQL:
            return mysql_create_tables((MySQLConnection*)db_handle->connection, config);
        default:
            return 0;
    }

    if (!create_archive_table(db_handle, config)) {
        return 0;
    }

    log_message(config, "INFO", "Database tables created successfully");
    return 1;
}

int create_archive_table(DatabaseHandle *db_handle, Config *config) {
    if (!db_handle || !db_handle->connection) return 0;

    switch (db_handle->db_type) {
        case DB_SQLITE: {
            const char *create_archives_table =
                "CREATE TABLE IF NOT EXISTS archives ("
                "    id INTEGER PRIMARY KEY AUTOINCREMENT,"
                "    archive_path TEXT UNIQUE,"
                "    archive_hash TEXT,"
                "    file_count INTEGER,"
                "    total_size INTEGER,"
                "    last_modified INTEGER,"
                "    last_scanned DATETIME DEFAULT CURRENT_TIMESTAMP,"
                "    needs_rescan BOOLEAN DEFAULT 1"
                ");";

            if (!db_execute(db_handle, create_archives_table, config)) {
                return 0;
            }
            break;
        }
        case DB_MYSQL:
            return mysql_create_archive_table((MySQLConnection*)db_handle->connection, config);
        default:
            return 0;
    }
    return 1;
}


int archive_needs_rescan(DatabaseHandle *db_handle, const char *archive_path, const char *current_hash, Config *config) {
    if (!db_handle || !db_handle->connection) {
        log_message(config, "DEBUG", "[ARCHIVE_NEEDS_RESCAN] No database connection");
        return 1;
    }

    struct stat st;
    if (stat(archive_path, &st) == -1) {
        log_message(config, "DEBUG", "[ARCHIVE_NEEDS_RESCAN] Cannot stat archive: %s", archive_path);
        return 1;
    }

    if (config->scanner.rescan_unchanged) {
        log_message(config, "DEBUG", "[ARCHIVE_NEEDS_RESCAN] Forced rescan enabled for: %s", archive_path);
        return 1;
    }

    switch (db_handle->db_type) {
        case DB_SQLITE: {
            sqlite3 *db = (sqlite3*)db_handle->connection;

            const char *sql = "SELECT archive_hash, last_modified, needs_rescan FROM archives WHERE archive_path = ?";
            sqlite3_stmt *stmt;

            if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
                sqlite3_bind_text(stmt, 1, archive_path, -1, SQLITE_STATIC);

                if (sqlite3_step(stmt) == SQLITE_ROW) {
                    const char *stored_hash = (const char*)sqlite3_column_text(stmt, 0);
                    time_t stored_mtime = sqlite3_column_int64(stmt, 1);
                    int needs_rescan = sqlite3_column_int(stmt, 2);

                    log_message(config, "DEBUG", "[ARCHIVE_NEEDS_RESCAN] Found in DB: hash=%s, mtime=%ld, needs_rescan=%d",
                               stored_hash ? stored_hash : "NULL", stored_mtime, needs_rescan);

                    if (needs_rescan) {
                        log_message(config, "DEBUG", "[ARCHIVE_NEEDS_RESCAN] Flag needs_rescan=TRUE for: %s", archive_path);
                        sqlite3_finalize(stmt);
                        return 1;
                    }

                    if (stored_hash && current_hash && strcmp(stored_hash, current_hash) == 0 &&
                        stored_mtime == st.st_mtime) {

                        log_message(config, "DEBUG", "[ARCHIVE_NEEDS_RESCAN] Archive unchanged, skipping: %s", archive_path);

                        const char *update_sql = "UPDATE archives SET last_scanned = CURRENT_TIMESTAMP WHERE archive_path = ?";
                        sqlite3_stmt *update_stmt;
                        if (sqlite3_prepare_v2(db, update_sql, -1, &update_stmt, NULL) == SQLITE_OK) {
                            sqlite3_bind_text(update_stmt, 1, archive_path, -1, SQLITE_STATIC);
                            sqlite3_step(update_stmt);
                            sqlite3_finalize(update_stmt);
                        }

                        sqlite3_finalize(stmt);
                        return 0;
                    } else {
                        log_message(config, "DEBUG", "[ARCHIVE_NEEDS_RESCAN] Archive changed: %s", archive_path);
                    }
                } else {
                    log_message(config, "DEBUG", "[ARCHIVE_NEEDS_RESCAN] Archive not in database: %s", archive_path);
                }
                sqlite3_finalize(stmt);
            } else {
                log_message(config, "ERROR", "[ARCHIVE_NEEDS_RESCAN] SQLite prepare failed: %s", sqlite3_errmsg(db));
            }
            break;
        }

        case DB_MYSQL:
            return mysql_archive_needs_rescan((MySQLConnection*)db_handle->connection, archive_path, current_hash, config);

        default:
            log_message(config, "ERROR", "[ARCHIVE_NEEDS_RESCAN] Unknown database type: %d", db_handle->db_type);
            return 1;
    }

    return 1;
}

void update_archive_info(DatabaseHandle *db_handle, const char *archive_path, const char *hash,
                        int file_count, long total_size, Config *config) {
    if (!db_handle || !db_handle->connection) return;

    switch (db_handle->db_type) {
        case DB_SQLITE: {
            struct stat st;
            if (stat(archive_path, &st) != 0) return;

            sqlite3 *db = (sqlite3*)db_handle->connection;
            const char *sql = "INSERT OR REPLACE INTO archives (archive_path, archive_hash, file_count, total_size, last_modified, last_scanned, needs_rescan) "
                              "VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 0)";
            sqlite3_stmt *stmt;

            if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
                sqlite3_bind_text(stmt, 1, archive_path, -1, SQLITE_STATIC);
                sqlite3_bind_text(stmt, 2, hash, -1, SQLITE_STATIC);
                sqlite3_bind_int(stmt, 3, file_count);
                sqlite3_bind_int64(stmt, 4, total_size);
                sqlite3_bind_int64(stmt, 5, st.st_mtime);

                if (sqlite3_step(stmt) != SQLITE_DONE) {
                    log_message(config, "ERROR", "Failed to update archive info: %s", sqlite3_errmsg(db));
                } else {
                    log_message(config, "DEBUG", "Updated archive info: %s (%d files, %ld bytes)",
                               archive_path, file_count, total_size);
                }
                sqlite3_finalize(stmt);
            }
            break;
        }
        case DB_MYSQL:
            mysql_update_archive_info((MySQLConnection*)db_handle->connection, archive_path, hash, file_count, total_size, config);
            break;
        default:
            break;
    }
}

int book_exists(DatabaseHandle *db_handle, const char *filepath, const char *archive_path,
                const char *internal_path, const char *file_hash, Config *config) {
    if (!db_handle || !db_handle->connection) return 0;

    switch (db_handle->db_type) {
        case DB_SQLITE: {
            sqlite3 *db = (sqlite3*)db_handle->connection;

            if (file_hash && !config->scanner.rescan_unchanged) {
                const char *sql = "SELECT id, file_path FROM books WHERE file_hash = ?";
                sqlite3_stmt *stmt;

                if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
                    sqlite3_bind_text(stmt, 1, file_hash, -1, SQLITE_STATIC);

                    if (sqlite3_step(stmt) == SQLITE_ROW) {
                        int existing_id = sqlite3_column_int(stmt, 0);
                        const char *existing_path = (const char*)sqlite3_column_text(stmt, 1);

                        log_message(config, "DEBUG", "Book already exists (hash match): ID=%d, Path=%s",
                                   existing_id, existing_path);
                        sqlite3_finalize(stmt);
                        return 1;
                    }
                    sqlite3_finalize(stmt);
                }
            }

            const char *sql = "SELECT id FROM books WHERE file_path = ? AND archive_path IS ? AND archive_internal_path IS ?";
            sqlite3_stmt *stmt;

            if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
                sqlite3_bind_text(stmt, 1, filepath, -1, SQLITE_STATIC);
                sqlite3_bind_text(stmt, 2, archive_path, -1, SQLITE_STATIC);
                sqlite3_bind_text(stmt, 3, internal_path, -1, SQLITE_STATIC);

                int exists = (sqlite3_step(stmt) == SQLITE_ROW);
                sqlite3_finalize(stmt);

                if (exists) {
                    log_message(config, "DEBUG", "Book already exists (path match): %s", filepath);
                    return 1;
                }
            }
            break;
        }
        case DB_MYSQL:
            return mysql_book_exists((MySQLConnection*)db_handle->connection, filepath, archive_path, internal_path, file_hash, config);
        default:
            return 0;
    }

    return 0;
}

void insert_book_to_db(DatabaseHandle *db_handle, const char *filepath, BookMeta *meta,
                      const char *archive_path, const char *internal_path, Config *config) {
    if (!db_handle || !db_handle->connection) {
        log_message(config, "ERROR", "[INSERT_BOOK_TO_DB] Database handle or connection is NULL");
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

    log_message(config, "DEBUG", "[INSERT_BOOK_TO_DB] Inserting book: %s", filepath);

    switch (db_handle->db_type) {
        case DB_SQLITE: {
            sqlite3 *db = (sqlite3*)db_handle->connection;

            if (meta->title && meta->author) {
                const char *check_sql = "SELECT COUNT(*) FROM books WHERE title = ? AND author = ?";
                sqlite3_stmt *check_stmt;

                if (sqlite3_prepare_v2(db, check_sql, -1, &check_stmt, NULL) == SQLITE_OK) {
                    sqlite3_bind_text(check_stmt, 1, meta->title, -1, SQLITE_STATIC);
                    sqlite3_bind_text(check_stmt, 2, meta->author, -1, SQLITE_STATIC);

                    if (sqlite3_step(check_stmt) == SQLITE_ROW) {
                        int count = sqlite3_column_int(check_stmt, 0);
                        if (count > 0) {
                            log_message(config, "DEBUG", "[INSERT_BOOK_TO_DB] Book already exists, skipping: '%s' by '%s'",
                                       meta->title, meta->author);
                            sqlite3_finalize(check_stmt);
                            return;
                        }
                    }
                    sqlite3_finalize(check_stmt);
                }
            }

            const char *sql = "INSERT INTO books (file_path, file_name, file_size, file_type, "
                              "archive_path, archive_internal_path, title, author, genre, series, "
                              "series_number, year, language, publisher, description, last_modified) "
                              "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";

            sqlite3_stmt *stmt;
            int rc = sqlite3_prepare_v2(db, sql, -1, &stmt, NULL);
            if (rc != SQLITE_OK) {
                log_message(config, "ERROR", "Failed to prepare SQL statement: %s", sqlite3_errmsg(db));
                return;
            }

            const char *filename = internal_path ? internal_path : strrchr(filepath, '/');
            filename = filename ? (internal_path ? filename : filename + 1) : filepath;
            const char *ext = strrchr(filename, '.');

            sqlite3_bind_text(stmt, 1, filepath, -1, SQLITE_STATIC);
            sqlite3_bind_text(stmt, 2, filename, -1, SQLITE_STATIC);

            if (meta->file_size > 0) {
                sqlite3_bind_int64(stmt, 3, meta->file_size);
            } else {
                sqlite3_bind_null(stmt, 3);
            }

            sqlite3_bind_text(stmt, 4, ext ? ext + 1 : "unknown", -1, SQLITE_STATIC);
            sqlite3_bind_text(stmt, 5, archive_path, -1, SQLITE_STATIC);
            sqlite3_bind_text(stmt, 6, internal_path, -1, SQLITE_STATIC);
            sqlite3_bind_text(stmt, 7, meta->title, -1, SQLITE_STATIC);
            sqlite3_bind_text(stmt, 8, meta->author, -1, SQLITE_STATIC);
            sqlite3_bind_text(stmt, 9, meta->genre, -1, SQLITE_STATIC);
            sqlite3_bind_text(stmt, 10, meta->series, -1, SQLITE_STATIC);
            sqlite3_bind_int(stmt, 11, meta->series_number);
            sqlite3_bind_int(stmt, 12, meta->year);
            sqlite3_bind_text(stmt, 13, meta->language, -1, SQLITE_STATIC);
            sqlite3_bind_text(stmt, 14, meta->publisher, -1, SQLITE_STATIC);

            if (meta->description && strlen(meta->description) > 1000) {
                char *short_desc = strndup(meta->description, 1000);
                sqlite3_bind_text(stmt, 15, short_desc, -1, SQLITE_TRANSIENT);
                free(short_desc);
            } else {
                sqlite3_bind_text(stmt, 15, meta->description, -1, SQLITE_STATIC);
            }

            rc = sqlite3_step(stmt);
            if (rc != SQLITE_DONE) {
                log_message(config, "ERROR", "Failed to insert book: %s", sqlite3_errmsg(db));
            } else {
                log_message(config, "DEBUG", "[INSERT_BOOK_TO_DB] Book inserted successfully");
            }

            sqlite3_finalize(stmt);
            break;
        }
        case DB_MYSQL: {
            MySQLConnection *mysql_conn = (MySQLConnection*)db_handle->connection;

            if (!mysql_conn || !mysql_conn->mysql) {
                log_message(config, "ERROR", "[INSERT_BOOK_TO_DB] MySQL connection is invalid");
                return;
            }

            mysql_insert_book(mysql_conn, filepath, meta, archive_path, internal_path, config);
            break;
        }
        default:
            log_message(config, "ERROR", "[INSERT_BOOK_TO_DB] Unknown database type: %d", db_handle->db_type);
            break;
    }
}
