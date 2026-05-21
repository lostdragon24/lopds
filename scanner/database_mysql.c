#include "database_mysql.h"
#include "common.h"
#include "config.h"
#include "database.h"
#include "path_validation.h"
#include "utils.h"
#include <limits.h>
#include <mysql/mysql.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>

// Определяем тип для is_null в зависимости от версии MySQL
#ifdef MYSQL_VERSION_ID
#if MYSQL_VERSION_ID >= 80000
typedef bool mysql_bool_t;
#define MYSQL_BOOL_TRUE true
#define MYSQL_BOOL_FALSE false
#else
typedef my_bool mysql_bool_t;
#define MYSQL_BOOL_TRUE 1
#define MYSQL_BOOL_FALSE 0
#endif
#else
typedef my_bool mysql_bool_t;
#define MYSQL_BOOL_TRUE 1
#define MYSQL_BOOL_FALSE 0
#endif

#ifndef SAFE_FREE
#define SAFE_FREE(ptr)  \
    do {                \
        if (ptr) {      \
            free(ptr);  \
            ptr = NULL; \
        }               \
    } while (0)
#endif

MySQLConnection* mysql_conn_connect(Config* config)
{
    if (!config || !config->database.host || !config->database.user) {
        return NULL;
    }

    log_message(config, "DEBUG", "Connecting to MySQL at %s...",
        config->database.host);

    MySQLConnection* mysql_conn = malloc(sizeof(MySQLConnection));
    if (!mysql_conn) {
        log_message(config, "ERROR", "Failed to allocate MySQL connection");
        return NULL;
    }

    mysql_conn->mysql = NULL;
    mysql_conn->stmt = NULL;

    mysql_conn->mysql = mysql_init(NULL);
    if (!mysql_conn->mysql) {
        log_message(config, "ERROR", "mysql_init failed");
        free(mysql_conn);
        return NULL;
    }

    unsigned int timeout = 30;
    mysql_options(mysql_conn->mysql, MYSQL_OPT_CONNECT_TIMEOUT, &timeout);
    mysql_options(mysql_conn->mysql, MYSQL_OPT_READ_TIMEOUT, &timeout);
    mysql_options(mysql_conn->mysql, MYSQL_OPT_WRITE_TIMEOUT, &timeout);

    if (!mysql_real_connect(mysql_conn->mysql, config->database.host,
            config->database.user, config->database.password,
            NULL, config->database.port, config->database.socket,
            config->database.flags)) {
        log_message(config, "ERROR", "MySQL connection failed: %s",
            mysql_error(mysql_conn->mysql));
        mysql_close(mysql_conn->mysql);
        free(mysql_conn);
        return NULL;
    }

    log_message(config, "INFO", "Connected to MySQL server");

    if (config->database.database) {
        log_message(config, "DEBUG", "Checking database '%s'...",
            config->database.database);

        char create_db_sql[512];
        snprintf(create_db_sql, sizeof(create_db_sql),
            "CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE "
            "utf8mb4_unicode_ci",
            config->database.database);

        if (mysql_query(mysql_conn->mysql, create_db_sql)) {
            log_message(config, "ERROR", "Failed to create database: %s",
                mysql_error(mysql_conn->mysql));
            mysql_close(mysql_conn->mysql);
            free(mysql_conn);
            return NULL;
        }

        log_message(config, "DEBUG", "Database '%s' created or already exists",
            config->database.database);

        if (mysql_select_db(mysql_conn->mysql, config->database.database)) {
            log_message(config, "ERROR", "Failed to select database: %s",
                mysql_error(mysql_conn->mysql));
            mysql_close(mysql_conn->mysql);
            free(mysql_conn);
            return NULL;
        }

        log_message(config, "INFO", "Using database '%s'",
            config->database.database);
    }

    if (mysql_set_character_set(mysql_conn->mysql, "utf8mb4")) {
        log_message(config, "WARNING", "Failed to set UTF-8 character set: %s",
            mysql_error(mysql_conn->mysql));
    }

    return mysql_conn;
}

int mysql_execute_query(MySQLConnection* mysql_conn, const char* sql,
    Config* config)
{
    if (!mysql_conn || !mysql_conn->mysql) {
        log_message(config, "ERROR", "MySQL connection is not initialized");
        return 0;
    }

    log_message(config, "DEBUG", "Executing MySQL query: %s", sql);

    if (mysql_query(mysql_conn->mysql, sql)) {
        log_message(config, "ERROR", "MySQL query failed: %s",
            mysql_error(mysql_conn->mysql));
        return 0;
    }

    MYSQL_RES* result = mysql_store_result(mysql_conn->mysql);
    if (result) {
        mysql_free_result(result);
    }

    return 1;
}

void mysql_conn_close(MySQLConnection* mysql_conn)
{
    if (!mysql_conn)
        return;

    if (mysql_conn->stmt) {
        mysql_stmt_close(mysql_conn->stmt);
        mysql_conn->stmt = NULL;
    }

    if (mysql_conn->mysql) {
        mysql_close(mysql_conn->mysql);
        mysql_conn->mysql = NULL;
    }

    free(mysql_conn);
}

int mysql_create_tables(MySQLConnection* mysql_conn, Config* config)
{
    const char* create_books_table = "CREATE TABLE IF NOT EXISTS books ("
                                     "    id INT AUTO_INCREMENT PRIMARY KEY,"
                                     "    file_path TEXT,"
                                     "    file_name TEXT,"
                                     "    file_size BIGINT,"
                                     "    file_type VARCHAR(10),"
                                     "    archive_path TEXT,"
                                     "    archive_internal_path TEXT,"
                                     "    file_hash VARCHAR(64) UNIQUE,"
                                     "    title TEXT,"
                                     "    author TEXT,"
                                     "    genre TEXT,"
                                     "    series TEXT,"
                                     "    series_number INT,"
                                     "    year INT,"
                                     "    language VARCHAR(10),"
                                     "    publisher TEXT,"
                                     "    description TEXT,"
                                     "    added_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
                                     "    last_modified TIMESTAMP NULL,"
                                     "    last_scanned TIMESTAMP NULL,"
                                     "    file_mtime BIGINT,"
                                     "    UNIQUE KEY unique_book (file_path(255), archive_path(255), archive_internal_path(255)),"
                                     "    UNIQUE KEY unique_title_author (title(255), author(255)),"
                                     "    UNIQUE KEY unique_file_hash (file_hash),"
                                     "    INDEX idx_author (author(100)),"
                                     "    INDEX idx_title (title(100)),"
                                     "    INDEX idx_genre (genre(50)),"
                                     "    INDEX idx_series (series(100)),"
                                     "    INDEX idx_added_date (added_date),"
                                     "    INDEX idx_file_type (file_type),"
                                     "    INDEX idx_year (year),"
                                     "    FULLTEXT INDEX ft_search (title, author, genre, series)"
                                     ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!mysql_execute_query(mysql_conn, create_books_table, config)) {
        return 0;
    }

    if (!mysql_create_archive_table(mysql_conn, config)) {
        return 0;
    }

    log_message(config, "INFO", "MySQL tables created successfully");
    return 1;
}

int mysql_create_archive_table(MySQLConnection* mysql_conn, Config* config)
{
    const char* create_archives_table = "CREATE TABLE IF NOT EXISTS archives ("
                                        "    id INT AUTO_INCREMENT PRIMARY KEY,"
                                        "    archive_path TEXT,"
                                        "    archive_hash VARCHAR(64),"
                                        "    file_count INT,"
                                        "    total_size BIGINT,"
                                        "    last_modified BIGINT,"
                                        "    last_scanned TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
                                        "    needs_rescan BOOLEAN DEFAULT TRUE,"
                                        "    UNIQUE KEY unique_archive (archive_path(255))"
                                        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!mysql_execute_query(mysql_conn, create_archives_table, config)) {
        return 0;
    }

    if (!mysql_create_ratings_table(mysql_conn, config)) {
        return 0;
    }

    return 1;
}

int mysql_create_ratings_table(MySQLConnection* mysql_conn, Config* config)
{
    const char* create_ratings_table_sql = "CREATE TABLE IF NOT EXISTS book_ratings ("
                                           "    id INT AUTO_INCREMENT PRIMARY KEY,"
                                           "    book_id INT NOT NULL,"
                                           "    user_ip VARCHAR(45) NOT NULL,"
                                           "    rating TINYINT NOT NULL,"
                                           "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
                                           "    CONSTRAINT chk_rating_range CHECK (rating >= 1 AND rating <= 5),"
                                           "    CONSTRAINT unique_user_book UNIQUE (user_ip, book_id),"
                                           "    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE"
                                           ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!mysql_execute_query(mysql_conn, create_ratings_table_sql, config)) {
        return 0;
    }

    if (!mysql_create_favorites_table(mysql_conn, config)) {
        return 0;
    }

    return 1;
}

int mysql_create_favorites_table(MySQLConnection* mysql_conn, Config* config)
{
    const char* create_favorites_table_sql = "CREATE TABLE IF NOT EXISTS book_favorites ("
                                             "    id INT AUTO_INCREMENT PRIMARY KEY,"
                                             "    book_id INT NOT NULL,"
                                             "    user_ip VARCHAR(45) NOT NULL,"
                                             "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
                                             "    CONSTRAINT unique_user_favorite UNIQUE (user_ip, book_id),"
                                             "    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE"
                                             ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return mysql_execute_query(mysql_conn, create_favorites_table_sql, config);
}

int mysql_archive_needs_rescan(MySQLConnection* mysql_conn,
    const char* archive_path,
    const char* current_hash, Config* config)
{
    log_message(config, "DEBUG", "[MYSQL_ARCHIVE_NEEDS_RESCAN] START for: %s",
        archive_path);

    if (!mysql_conn || !mysql_conn->mysql) {
        log_message(config, "DEBUG",
            "[MYSQL_ARCHIVE_NEEDS_RESCAN] No MySQL connection");
        return 1;
    }

    struct stat st;
    if (stat(archive_path, &st) == -1) {
        log_message(config, "DEBUG",
            "[MYSQL_ARCHIVE_NEEDS_RESCAN] Cannot stat archive: %s",
            archive_path);
        return 1;
    }

    if (config->scanner.rescan_unchanged) {
        log_message(config, "DEBUG",
            "[MYSQL_ARCHIVE_NEEDS_RESCAN] Forced rescan enabled");
        return 1;
    }

    if (mysql_ping(mysql_conn->mysql)) {
        log_message(
            config, "WARNING",
            "[MYSQL_ARCHIVE_NEEDS_RESCAN] Connection lost, reconnecting...");
        if (!mysql_reconnect(mysql_conn, config)) {
            log_message(config, "ERROR",
                "[MYSQL_ARCHIVE_NEEDS_RESCAN] Reconnection failed");
            return 1;
        }
    }

    char* escaped_path = malloc(strlen(archive_path) * 2 + 1);
    if (!escaped_path) {
        log_message(config, "ERROR",
            "[MYSQL_ARCHIVE_NEEDS_RESCAN] Memory allocation failed");
        return 1;
    }

    mysql_real_escape_string(mysql_conn->mysql, escaped_path, archive_path,
        strlen(archive_path));

    char sql[2048];
    snprintf(sql, sizeof(sql),
        "SELECT archive_hash, last_modified, needs_rescan FROM archives "
        "WHERE archive_path = '%s'",
        escaped_path);

    log_message(config, "DEBUG", "[MYSQL_ARCHIVE_NEEDS_RESCAN] Executing SQL: %s",
        sql);

    if (mysql_query(mysql_conn->mysql, sql)) {
        log_message(config, "ERROR",
            "[MYSQL_ARCHIVE_NEEDS_RESCAN] Query failed: %s",
            mysql_error(mysql_conn->mysql));
        free(escaped_path);
        return 1;
    }

    MYSQL_RES* result = mysql_store_result(mysql_conn->mysql);
    if (!result) {
        log_message(
            config, "DEBUG",
            "[MYSQL_ARCHIVE_NEEDS_RESCAN] Archive not in database or no result: %s",
            archive_path);
        free(escaped_path);
        return 1;
    }

    MYSQL_ROW row = mysql_fetch_row(result);
    int needs_rescan = 1;

    if (row) {
        const char* stored_hash = row[0];
        const char* mtime_str = row[1];
        const char* needs_rescan_str = row[2];

        time_t stored_mtime = mtime_str ? atol(mtime_str) : 0;
        int needs_rescan_flag = needs_rescan_str ? atoi(needs_rescan_str) : 0;

        log_message(config, "DEBUG",
            "[MYSQL_ARCHIVE_NEEDS_RESCAN] Found in DB: hash=%s, mtime=%ld, "
            "needs_rescan=%d",
            stored_hash ? stored_hash : "NULL", stored_mtime,
            needs_rescan_flag);

        if (needs_rescan_flag) {
            log_message(config, "DEBUG",
                "[MYSQL_ARCHIVE_NEEDS_RESCAN] Flag needs_rescan=TRUE");
            mysql_free_result(result);
            free(escaped_path);
            return 1;
        }

        if (stored_hash && current_hash && strcmp(stored_hash, current_hash) == 0 && stored_mtime == st.st_mtime) {

            log_message(
                config, "DEBUG",
                "[MYSQL_ARCHIVE_NEEDS_RESCAN] Archive unchanged, skipping: %s",
                archive_path);

            char update_sql[1024];
            snprintf(
                update_sql, sizeof(update_sql),
                "UPDATE archives SET last_scanned = NOW() WHERE archive_path = '%s'",
                escaped_path);

            if (mysql_query(mysql_conn->mysql, update_sql)) {
                log_message(
                    config, "WARNING",
                    "[MYSQL_ARCHIVE_NEEDS_RESCAN] Failed to update last_scanned: %s",
                    mysql_error(mysql_conn->mysql));
            }

            needs_rescan = 0;
        } else {
            log_message(config, "DEBUG",
                "[MYSQL_ARCHIVE_NEEDS_RESCAN] Archive changed");
        }
    } else {
        log_message(config, "DEBUG",
            "[MYSQL_ARCHIVE_NEEDS_RESCAN] Archive not in database: %s",
            archive_path);
    }

    mysql_free_result(result);
    free(escaped_path);

    log_message(config, "DEBUG", "[MYSQL_ARCHIVE_NEEDS_RESCAN] Needs rescan: %d",
        needs_rescan);
    return needs_rescan;
}

void mysql_update_archive_info(MySQLConnection* mysql_conn,
    const char* archive_path, const char* hash,
    int file_count, long total_size,
    Config* config)
{
    if (!mysql_conn || !mysql_conn->mysql)
        return;

    struct stat st;
    if (stat(archive_path, &st) != 0)
        return;

    const char* sql = "INSERT INTO archives (archive_path, archive_hash, file_count, "
                      "total_size, last_modified, last_scanned, needs_rescan) "
                      "VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, FALSE) "
                      "ON DUPLICATE KEY UPDATE archive_hash = VALUES(archive_hash), file_count "
                      "= VALUES(file_count), "
                      "total_size = VALUES(total_size), last_modified = VALUES(last_modified), "
                      "last_scanned = VALUES(last_scanned), needs_rescan = "
                      "VALUES(needs_rescan)";

    MYSQL_STMT* stmt = mysql_stmt_init(mysql_conn->mysql);
    if (!stmt)
        return;

    if (mysql_stmt_prepare(stmt, sql, strlen(sql))) {
        mysql_stmt_close(stmt);
        return;
    }

    MYSQL_BIND bind[5];
    unsigned long lengths[5];
    mysql_bool_t is_null[5] = { MYSQL_BOOL_FALSE };
    mysql_bool_t false_val = MYSQL_BOOL_FALSE;

    memset(bind, 0, sizeof(bind));

    // archive_path
    lengths[0] = strlen(archive_path);
    bind[0].buffer_type = MYSQL_TYPE_STRING;
    bind[0].buffer = (char*)archive_path;
    bind[0].buffer_length = lengths[0];
    bind[0].length = &lengths[0];
    bind[0].is_null = &false_val;

    // archive_hash
    if (hash) {
        lengths[1] = strlen(hash);
        bind[1].buffer_type = MYSQL_TYPE_STRING;
        bind[1].buffer = (char*)hash;
        bind[1].buffer_length = lengths[1];
        bind[1].length = &lengths[1];
        bind[1].is_null = &false_val;
    } else {
        is_null[1] = MYSQL_BOOL_TRUE;
        bind[1].is_null = &is_null[1];
    }

    // file_count
    bind[2].buffer_type = MYSQL_TYPE_LONG;
    bind[2].buffer = &file_count;
    bind[2].is_null = &false_val;

    // total_size
    long long total_size_ll = total_size;
    bind[3].buffer_type = MYSQL_TYPE_LONGLONG;
    bind[3].buffer = &total_size_ll;
    bind[3].is_null = &false_val;

    // last_modified
    long long mtime_ll = st.st_mtime;
    bind[4].buffer_type = MYSQL_TYPE_LONGLONG;
    bind[4].buffer = &mtime_ll;
    bind[4].is_null = &false_val;

    if (mysql_stmt_bind_param(stmt, bind)) {
        mysql_stmt_close(stmt);
        return;
    }

    if (mysql_stmt_execute(stmt)) {
        log_message(config, "ERROR", "Failed to update archive info: %s",
            mysql_stmt_error(stmt));
    } else {
        log_message(config, "DEBUG",
            "Updated archive info: %s (%d files, %ld bytes)", archive_path,
            file_count, total_size);
    }

    mysql_stmt_close(stmt);
}

int mysql_book_exists(MySQLConnection* mysql_conn, const char* filepath,
    const char* archive_path, const char* internal_path,
    const char* file_hash, Config* config)
{
    (void)archive_path;
    (void)internal_path;
    (void)file_hash;

    if (!mysql_conn || !mysql_conn->mysql)
        return 0;

    log_message(config, "DEBUG",
        "[MYSQL_BOOK_EXISTS] Checking if book exists: %s", filepath);

    char* escaped_filepath = malloc(strlen(filepath) * 2 + 1);
    if (!escaped_filepath)
        return 0;

    mysql_real_escape_string(mysql_conn->mysql, escaped_filepath, filepath,
        strlen(filepath));

    char sql[4096];
    snprintf(sql, sizeof(sql), "SELECT id FROM books WHERE file_path = '%s'",
        escaped_filepath);

    log_message(config, "DEBUG", "[MYSQL_BOOK_EXISTS] Executing SQL: %s", sql);

    if (mysql_query(mysql_conn->mysql, sql)) {
        log_message(config, "ERROR", "[MYSQL_BOOK_EXISTS] Query failed: %s",
            mysql_error(mysql_conn->mysql));
        free(escaped_filepath);
        return 0;
    }

    MYSQL_RES* result = mysql_store_result(mysql_conn->mysql);
    if (!result) {
        free(escaped_filepath);
        return 0;
    }

    int exists = (mysql_num_rows(result) > 0);
    mysql_free_result(result);
    free(escaped_filepath);

    log_message(config, "DEBUG", "[MYSQL_BOOK_EXISTS] Book %s exists: %s",
        filepath, exists ? "YES" : "NO");
    return exists;
}

int mysql_reconnect(MySQLConnection* mysql_conn, Config* config)
{
    log_message(config, "DEBUG", "[MYSQL_RECONNECT] Attempting to reconnect...");

    if (mysql_conn->mysql) {
        mysql_close(mysql_conn->mysql);
        mysql_conn->mysql = NULL;
    }

    mysql_conn->mysql = mysql_init(NULL);
    if (!mysql_conn->mysql) {
        log_message(config, "ERROR", "[MYSQL_RECONNECT] mysql_init failed");
        return 0;
    }

    unsigned int timeout = 30;
    mysql_options(mysql_conn->mysql, MYSQL_OPT_CONNECT_TIMEOUT, &timeout);
    mysql_options(mysql_conn->mysql, MYSQL_OPT_READ_TIMEOUT, &timeout);
    mysql_options(mysql_conn->mysql, MYSQL_OPT_WRITE_TIMEOUT, &timeout);

    if (!mysql_real_connect(mysql_conn->mysql, config->database.host,
            config->database.user, config->database.password,
            config->database.database, config->database.port,
            config->database.socket, config->database.flags)) {
        log_message(config, "ERROR", "[MYSQL_RECONNECT] Reconnection failed: %s",
            mysql_error(mysql_conn->mysql));
        mysql_close(mysql_conn->mysql);
        mysql_conn->mysql = NULL;
        return 0;
    }

    mysql_set_character_set(mysql_conn->mysql, "utf8mb4");

    log_message(config, "DEBUG", "[MYSQL_RECONNECT] Successfully reconnected");
    return 1;
}

// === ИСПРАВЛЕННАЯ mysql_insert_book() ===
void mysql_insert_book(MySQLConnection* mysql_conn, const char* filepath,
    BookMeta* meta, const char* archive_path,
    const char* internal_path, const char* file_hash,
    Config* config)
{
    if (!mysql_conn || !mysql_conn->mysql) {
        log_message(config, "ERROR", "MySQL connection is not valid");
        return;
    }

    if (!meta || !filepath) {
        log_message(config, "ERROR", "Invalid parameters for book insertion");
        return;
    }

    // Проверяем соединение
    if (mysql_ping(mysql_conn->mysql)) {
        log_message(config, "WARNING", "MySQL connection lost, reconnecting...");
        if (!mysql_reconnect(mysql_conn, config)) {
            log_message(config, "ERROR", "Reconnection failed");
            return;
        }
    }

    log_message(config, "INFO", "Inserting book: %s", filepath);

    // Подготовка данных
    const char* filename = "unknown";
    if (internal_path && internal_path[0] != '\0') {
        filename = internal_path;
    } else {
        const char* slash = strrchr(filepath, '/');
        if (slash) {
            filename = slash + 1;
        } else {
            filename = filepath;
        }
    }

    const char* file_type = normalize_file_type(filename);
    log_message(config, "DEBUG", "[MYSQL_INSERT_BOOK] File: %s, Detected type: %s",
        filename, file_type);

    // SQL запрос с подготовленным выражением
    const char* sql = "INSERT INTO books (file_path, file_name, file_size, file_type, "
                      "archive_path, archive_internal_path, file_hash, title, author, genre, series, "
                      "series_number, year, language, publisher, description, last_modified) VALUES ("
                      "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    MYSQL_STMT* stmt = mysql_stmt_init(mysql_conn->mysql);
    if (!stmt) {
        log_message(config, "ERROR", "mysql_stmt_init failed");
        return;
    }

    if (mysql_stmt_prepare(stmt, sql, strlen(sql))) {
        log_message(config, "ERROR", "mysql_stmt_prepare failed: %s",
            mysql_stmt_error(stmt));
        mysql_stmt_close(stmt);
        return;
    }

    // Инициализация параметров
    MYSQL_BIND bind[17];
    unsigned long lengths[17];
    mysql_bool_t is_null[17] = { MYSQL_BOOL_FALSE };
    mysql_bool_t false_val = MYSQL_BOOL_FALSE;
    mysql_bool_t true_val = MYSQL_BOOL_TRUE;

    memset(bind, 0, sizeof(bind));
    memset(lengths, 0, sizeof(lengths));

    // ========== 1. file_path ==========
    lengths[0] = strlen(filepath);
    bind[0].buffer_type = MYSQL_TYPE_STRING;
    bind[0].buffer = (char*)filepath;
    bind[0].buffer_length = lengths[0];
    bind[0].length = &lengths[0];
    bind[0].is_null = &false_val;

    // ========== 2. file_name ==========
    lengths[1] = strlen(filename);
    bind[1].buffer_type = MYSQL_TYPE_STRING;
    bind[1].buffer = (char*)filename;
    bind[1].buffer_length = lengths[1];
    bind[1].length = &lengths[1];
    bind[1].is_null = &false_val;

    // ========== 3. file_size ==========
    long long file_size_val = (meta->file_size > 0) ? meta->file_size : 0;
    bind[2].buffer_type = MYSQL_TYPE_LONGLONG;
    bind[2].buffer = &file_size_val;
    bind[2].is_null = (meta->file_size <= 0) ? &true_val : &false_val;

    // ========== 4. file_type ==========
    lengths[3] = strlen(file_type);
    bind[3].buffer_type = MYSQL_TYPE_STRING;
    bind[3].buffer = (char*)file_type;
    bind[3].buffer_length = lengths[3];
    bind[3].length = &lengths[3];
    bind[3].is_null = &false_val;

    // ========== 5. archive_path ==========
    if (archive_path && archive_path[0] != '\0') {
        lengths[4] = strlen(archive_path);
        bind[4].buffer_type = MYSQL_TYPE_STRING;
        bind[4].buffer = (char*)archive_path;
        bind[4].buffer_length = lengths[4];
        bind[4].length = &lengths[4];
        is_null[4] = MYSQL_BOOL_FALSE;
    } else {
        is_null[4] = MYSQL_BOOL_TRUE;
    }
    bind[4].is_null = &is_null[4];

    // ========== 6. archive_internal_path ==========
    if (internal_path && internal_path[0] != '\0') {
        lengths[5] = strlen(internal_path);
        bind[5].buffer_type = MYSQL_TYPE_STRING;
        bind[5].buffer = (char*)internal_path;
        bind[5].buffer_length = lengths[5];
        bind[5].length = &lengths[5];
        is_null[5] = MYSQL_BOOL_FALSE;
    } else {
        is_null[5] = MYSQL_BOOL_TRUE;
    }
    bind[5].is_null = &is_null[5];

    // ========== 7. file_hash ==========
    if (file_hash && file_hash[0] != '\0') {
        lengths[6] = strlen(file_hash);
        bind[6].buffer_type = MYSQL_TYPE_STRING;
        bind[6].buffer = (char*)file_hash;
        bind[6].buffer_length = lengths[6];
        bind[6].length = &lengths[6];
        is_null[6] = MYSQL_BOOL_FALSE;
    } else {
        is_null[6] = MYSQL_BOOL_TRUE;
    }
    bind[6].is_null = &is_null[6];

    // ========== 8. title (ОБЯЗАТЕЛЬНОЕ) ==========
    const char* title = (meta->title && meta->title[0] != '\0') ? meta->title : "Unknown Title";
    lengths[7] = strlen(title);
    bind[7].buffer_type = MYSQL_TYPE_STRING;
    bind[7].buffer = (char*)title;
    bind[7].buffer_length = lengths[7];
    bind[7].length = &lengths[7];
    is_null[7] = MYSQL_BOOL_FALSE;
    bind[7].is_null = &is_null[7];

    // ========== 9. author (ОБЯЗАТЕЛЬНОЕ) ==========
    const char* author = (meta->author && meta->author[0] != '\0') ? meta->author : "Unknown Author";
    lengths[8] = strlen(author);
    bind[8].buffer_type = MYSQL_TYPE_STRING;
    bind[8].buffer = (char*)author;
    bind[8].buffer_length = lengths[8];
    bind[8].length = &lengths[8];
    is_null[8] = MYSQL_BOOL_FALSE;
    bind[8].is_null = &is_null[8];

    // ========== 10. genre ==========
    if (meta->genre && meta->genre[0] != '\0') {
        lengths[9] = strlen(meta->genre);
        bind[9].buffer_type = MYSQL_TYPE_STRING;
        bind[9].buffer = meta->genre;
        bind[9].buffer_length = lengths[9];
        bind[9].length = &lengths[9];
        is_null[9] = MYSQL_BOOL_FALSE;
    } else {
        is_null[9] = MYSQL_BOOL_TRUE;
    }
    bind[9].is_null = &is_null[9];

    // ========== 11. series ==========
    if (meta->series && meta->series[0] != '\0') {
        lengths[10] = strlen(meta->series);
        bind[10].buffer_type = MYSQL_TYPE_STRING;
        bind[10].buffer = meta->series;
        bind[10].buffer_length = lengths[10];
        bind[10].length = &lengths[10];
        is_null[10] = MYSQL_BOOL_FALSE;
    } else {
        is_null[10] = MYSQL_BOOL_TRUE;
    }
    bind[10].is_null = &is_null[10];

    // ========== 12. series_number ==========
    int series_num = (meta->series_number > 0) ? meta->series_number : 0;
    bind[11].buffer_type = MYSQL_TYPE_LONG;
    bind[11].buffer = &series_num;
    is_null[11] = (meta->series_number <= 0) ? MYSQL_BOOL_TRUE : MYSQL_BOOL_FALSE;
    bind[11].is_null = &is_null[11];

    // ========== 13. year ==========
    int year_val = (meta->year > 0) ? meta->year : 0;
    bind[12].buffer_type = MYSQL_TYPE_LONG;
    bind[12].buffer = &year_val;
    is_null[12] = (meta->year <= 0) ? MYSQL_BOOL_TRUE : MYSQL_BOOL_FALSE;
    bind[12].is_null = &is_null[12];

    // ========== 14. language ==========
    if (meta->language && meta->language[0] != '\0') {
        lengths[13] = strlen(meta->language);
        bind[13].buffer_type = MYSQL_TYPE_STRING;
        bind[13].buffer = meta->language;
        bind[13].buffer_length = lengths[13];
        bind[13].length = &lengths[13];
        is_null[13] = MYSQL_BOOL_FALSE;
    } else {
        is_null[13] = MYSQL_BOOL_TRUE;
    }
    bind[13].is_null = &is_null[13];

    // ========== 15. publisher ==========
    if (meta->publisher && meta->publisher[0] != '\0') {
        lengths[14] = strlen(meta->publisher);
        bind[14].buffer_type = MYSQL_TYPE_STRING;
        bind[14].buffer = meta->publisher;
        bind[14].buffer_length = lengths[14];
        bind[14].length = &lengths[14];
        is_null[14] = MYSQL_BOOL_FALSE;
    } else {
        is_null[14] = MYSQL_BOOL_TRUE;
    }
    bind[14].is_null = &is_null[14];

    // ========== 16. description ==========
    char* cleaned_description = NULL;
    if (meta->description && meta->description[0] != '\0') {
        // Проверяем валидность UTF-8
        if (!is_valid_utf8_string(meta->description)) {
            log_message(config, "WARNING", "Invalid UTF-8 in description, sanitizing");
            cleaned_description = sanitize_utf8_string(meta->description);
            if (!cleaned_description) {
                is_null[15] = MYSQL_BOOL_TRUE;
                bind[15].is_null = &is_null[15];
                goto skip_description;
            }
        } else {
            cleaned_description = (char*)meta->description;
        }

        size_t desc_len = strlen(cleaned_description);
        if (desc_len > 65535) {
            log_message(config, "WARNING", "Description too long (%zu), truncating", desc_len);
            desc_len = 65535;
            char* truncated = malloc(desc_len + 1);
            if (truncated) {
                memcpy(truncated, cleaned_description, desc_len);
                truncated[desc_len] = '\0';
                if (cleaned_description != meta->description) {
                    free(cleaned_description);
                }
                cleaned_description = truncated;
            }
        }

        lengths[15] = desc_len;
        bind[15].buffer_type = MYSQL_TYPE_STRING;
        bind[15].buffer = cleaned_description;
        bind[15].buffer_length = lengths[15];
        bind[15].length = &lengths[15];
        is_null[15] = MYSQL_BOOL_FALSE;
    } else {
        is_null[15] = MYSQL_BOOL_TRUE;
    }
    bind[15].is_null = &is_null[15];

skip_description:

    // ========== 17. last_modified (NOW() в запросе) ==========
    // Не нужен параметр

    // Биндим параметры
    if (mysql_stmt_bind_param(stmt, bind)) {
        log_message(config, "ERROR", "mysql_stmt_bind_param failed: %s",
            mysql_stmt_error(stmt));
        mysql_stmt_close(stmt);
        return;
    }

    // Выполняем запрос
    if (mysql_stmt_execute(stmt)) {
        log_message(config, "ERROR", "mysql_stmt_execute failed: %s",
            mysql_stmt_error(stmt));
        mysql_stmt_close(stmt);
        return;
    }

    my_ulonglong affected_rows = mysql_stmt_affected_rows(stmt);
    if (affected_rows == 0) {
        log_message(config, "WARNING", "Book already exists or no changes: %s - %s",
            meta->title, meta->author);
    } else {
        log_message(config, "INFO", "Book inserted successfully: %s - %s (type: %s)",
            meta->title ? meta->title : "Unknown",
            meta->author ? meta->author : "Unknown",
            file_type);
    }

    mysql_stmt_close(stmt);

    if (cleaned_description && cleaned_description != meta->description) {
        free(cleaned_description);
    }
}

int check_book_exists_smart(MySQLConnection* mysql_conn, BookMeta* meta,
    Config* config)
{
    if (!mysql_conn || !mysql_conn->mysql) {
        log_message(config, "ERROR",
            "[CHECK_BOOK_EXISTS_SMART] MySQL connection is NULL");
        return 0;
    }

    if (!meta) {
        log_message(config, "ERROR", "[CHECK_BOOK_EXISTS_SMART] BookMeta is NULL");
        return 0;
    }

    if (!meta->title || !meta->author) {
        log_message(
            config, "DEBUG",
            "[CHECK_BOOK_EXISTS_SMART] Missing title or author, cannot check");
        return 0;
    }

    log_message(config, "DEBUG",
        "[CHECK_BOOK_EXISTS_SMART] Checking: '%s' by '%s' (size: %ld)",
        meta->title, meta->author, meta->file_size);

    char* escaped_title = NULL;
    char* escaped_author = NULL;
    MYSQL_RES* result = NULL;
    int should_skip = 0;

    size_t title_len = strlen(meta->title);
    size_t author_len = strlen(meta->author);

    if (title_len > 1024 || author_len > 1024) {
        log_message(
            config, "WARNING",
            "[CHECK_BOOK_EXISTS_SMART] Title or author too long (%zu/%zu chars)",
            title_len, author_len);
        return 0;
    }

    escaped_title = malloc(title_len * 2 + 1);
    escaped_author = malloc(author_len * 2 + 1);

    if (!escaped_title || !escaped_author) {
        log_message(config, "ERROR",
            "[CHECK_BOOK_EXISTS_SMART] Memory allocation failed");
        SAFE_FREE(escaped_title);
        SAFE_FREE(escaped_author);
        return 0;
    }

    mysql_real_escape_string(mysql_conn->mysql, escaped_title, meta->title,
        title_len);
    mysql_real_escape_string(mysql_conn->mysql, escaped_author, meta->author,
        author_len);

    char sql[8192];
    int sql_len = snprintf(sql, sizeof(sql),
        "SELECT id, file_size, file_path FROM books WHERE title = '%s' "
        "AND author = '%s' ORDER BY file_size DESC",
        escaped_title, escaped_author);

    if (sql_len < 0 || (size_t)sql_len >= sizeof(sql)) {
        log_message(
            config, "ERROR",
            "[CHECK_BOOK_EXISTS_SMART] SQL buffer overflow (needed %d bytes)",
            sql_len);
        SAFE_FREE(escaped_title);
        SAFE_FREE(escaped_author);
        return 0;
    }

    if (mysql_query(mysql_conn->mysql, sql)) {
        log_message(config, "ERROR", "[CHECK_BOOK_EXISTS_SMART] Query failed: %s",
            mysql_error(mysql_conn->mysql));
        SAFE_FREE(escaped_title);
        SAFE_FREE(escaped_author);
        return 0;
    }

    result = mysql_store_result(mysql_conn->mysql);
    if (!result) {
        if (mysql_errno(mysql_conn->mysql) != 0) {
            log_message(config, "ERROR",
                "[CHECK_BOOK_EXISTS_SMART] Failed to store result: %s",
                mysql_error(mysql_conn->mysql));
        }
        SAFE_FREE(escaped_title);
        SAFE_FREE(escaped_author);
        return 0;
    }

    int existing_count = mysql_num_rows(result);
    if (existing_count == 0) {
        mysql_free_result(result);
        SAFE_FREE(escaped_title);
        SAFE_FREE(escaped_author);
        return 0;
    }

    MYSQL_ROW row;
    while ((row = mysql_fetch_row(result))) {
        if (!row[0] || !row[1] || !row[2]) {
            continue;
        }

        long existing_size = row[1] ? atol(row[1]) : 0;

        if (meta->file_size <= 0 || existing_size <= 0) {
            continue;
        }

        if (meta->file_size < existing_size / 2) {
            should_skip = 1;
            break;
        }

        if (meta->file_size > existing_size * 11 / 10) {
            int existing_id = atoi(row[0]);
            char delete_sql[256];
            snprintf(delete_sql, sizeof(delete_sql),
                "DELETE FROM books WHERE id = %d", existing_id);
            mysql_query(mysql_conn->mysql, delete_sql);
            should_skip = 0;
            break;
        }

        if (meta->file_size >= existing_size / 2 && meta->file_size <= existing_size * 15 / 10) {
            should_skip = 1;
            break;
        }
    }

    mysql_free_result(result);
    SAFE_FREE(escaped_title);
    SAFE_FREE(escaped_author);

    return should_skip;
}
