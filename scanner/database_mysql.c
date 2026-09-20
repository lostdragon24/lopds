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

MySQLConnection *mysql_conn_connect(Config *config) {
  if (!config || !config->database.host || !config->database.user) {
    return NULL;
  }

  log_message(config, "DEBUG", "Connecting to MySQL at %s...",
              config->database.host);

  MySQLConnection *mysql_conn = malloc(sizeof(MySQLConnection));
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

  mysql_query(mysql_conn->mysql, "SET unique_checks = 0");
  mysql_query(mysql_conn->mysql, "SET foreign_key_checks = 0");
  mysql_query(mysql_conn->mysql, "SET autocommit = 0");

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

int mysql_execute_query(MySQLConnection *mysql_conn, const char *sql,
                        Config *config) {
  if (!mysql_conn || !mysql_conn->mysql) {
    log_message(config, "ERROR", "MySQL connection is not initialized");
    return 0;
  }

  // ПРОВЕРКА СОЕДИНЕНИЯ
  if (mysql_ping(mysql_conn->mysql) != 0) {
    log_message(config, "WARNING", "MySQL connection lost, reconnecting...");
    if (!mysql_reconnect(mysql_conn, config)) {
      log_message(config, "ERROR", "Reconnection failed");
      return 0;
    }
  }

  log_message(config, "DEBUG", "Executing MySQL query: %s", sql);

  if (mysql_query(mysql_conn->mysql, sql)) {
    log_message(config, "ERROR", "MySQL query failed: %s",
                mysql_error(mysql_conn->mysql));
    return 0;
  }

  MYSQL_RES *result = mysql_store_result(mysql_conn->mysql);
  if (result) {
    mysql_free_result(result);
  }

  return 1;
}

void mysql_conn_close(MySQLConnection *mysql_conn) {
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

int mysql_create_tables(MySQLConnection *mysql_conn, Config *config) {
  const char *create_books_table =
      "CREATE TABLE IF NOT EXISTS books ("
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
      "    description LONGTEXT,"
      "    added_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
      "    last_modified TIMESTAMP NULL,"
      "    last_scanned TIMESTAMP NULL,"
      "    file_mtime BIGINT,"
      "    UNIQUE KEY unique_book (file_path(255), archive_path(255), "
      "archive_internal_path(255)),"
      "    UNIQUE KEY unique_title_author (title(255), author(255)),"
      "    UNIQUE KEY unique_file_hash (file_hash),"
      "    INDEX idx_author (author(100)),"
      "    INDEX idx_title (title(100)),"
      "    INDEX idx_genre (genre(50)),"
      "    INDEX idx_series (series(100)),"
      "    INDEX idx_added_date (added_date),"
      "    INDEX idx_file_type (file_type),"
      "    INDEX idx_year (year),"
      "    FULLTEXT INDEX ft_search (title, author, genre, series, description)"
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

int mysql_create_archive_table(MySQLConnection *mysql_conn, Config *config) {
  const char *create_archives_table =
      "CREATE TABLE IF NOT EXISTS archives ("
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

int mysql_create_ratings_table(MySQLConnection *mysql_conn, Config *config) {
  const char *create_ratings_table_sql =
      "CREATE TABLE IF NOT EXISTS book_ratings ("
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

int mysql_create_favorites_table(MySQLConnection *mysql_conn, Config *config) {
  const char *create_favorites_table_sql =
      "CREATE TABLE IF NOT EXISTS book_favorites ("
      "    id INT AUTO_INCREMENT PRIMARY KEY,"
      "    book_id INT NOT NULL,"
      "    user_ip VARCHAR(45) NOT NULL,"
      "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
      "    CONSTRAINT unique_user_favorite UNIQUE (user_ip, book_id),"
      "    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE"
      ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

  if (!mysql_execute_query(mysql_conn, create_favorites_table_sql, config)) {
    return 0;
  }

  if (!mysql_create_bookmarks_table(mysql_conn, config)) {
    return 0;
  }

  return 1;
}

int mysql_create_bookmarks_table(MySQLConnection *mysql_conn, Config *config) {
  const char *query =
      "CREATE TABLE IF NOT EXISTS bookmarks ("
      "    id INT AUTO_INCREMENT PRIMARY KEY,"
      "    user_fingerprint VARCHAR(64) NOT NULL,"
      "    book_id INT NOT NULL,"
      "    cfi_range VARCHAR(255) NOT NULL,"
      "    page_number INT DEFAULT 0,"
      "    percentage DECIMAL(5,2) DEFAULT 0.00,"
      "    note TEXT,"
      "    type VARCHAR(20) DEFAULT 'bookmark',"
      "    color VARCHAR(20) DEFAULT 'yellow',"
      "    selected_text TEXT,"
      "    context_before TEXT,"
      "    context_after TEXT,"
      "    tags TEXT,"
      "    is_public TINYINT(1) DEFAULT 0,"
      "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
      "    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE "
      "CURRENT_TIMESTAMP,"
      "    last_read TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
      "    is_deleted TINYINT(1) DEFAULT 0,"
      "    INDEX idx_bookmarks_user_book (user_fingerprint, book_id),"
      "    INDEX idx_bookmarks_last_read (last_read),"
      "    INDEX idx_bookmarks_book (book_id),"
      "    INDEX idx_bookmarks_type (type),"
      "    INDEX idx_bookmarks_color (color),"
      "    INDEX idx_bookmarks_public (is_public),"
      "    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE"
      ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

  if (!mysql_execute_query(mysql_conn, query, config)) {
    return 0;
  }

  if (!mysql_create_bookmark_tags_table(mysql_conn, config)) {
    return 0;
  }

  if (!mysql_create_reading_history_table(mysql_conn, config)) {
    return 0;
  }

  return 1;
}

int mysql_create_bookmark_tags_table(MySQLConnection *mysql_conn,
                                     Config *config) {
  const char *query =
      "CREATE TABLE IF NOT EXISTS bookmark_tags ("
      "    id INT AUTO_INCREMENT PRIMARY KEY,"
      "    user_fingerprint VARCHAR(64) NOT NULL,"
      "    name VARCHAR(50) NOT NULL,"
      "    color VARCHAR(20) DEFAULT 'default',"
      "    usage_count INT DEFAULT 0,"
      "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
      "    UNIQUE KEY unique_user_tag (user_fingerprint, name),"
      "    INDEX idx_tags_user (user_fingerprint),"
      "    INDEX idx_tags_name (name)"
      ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

  if (!mysql_execute_query(mysql_conn, query, config)) {
    return 0;
  }

  if (!mysql_create_books_fts_table(mysql_conn, config)) {
    return 0;
  }

  return 1;
}

int mysql_create_books_fts_table(MySQLConnection *mysql_conn, Config *config) {
  // Для MySQL используем FULLTEXT индекс прямо в таблице bookmarks
  const char *query = "ALTER TABLE books "
                      "ADD FULLTEXT INDEX ft_books_search (title, "
                      "author, genre, series, publisher, description)";

  // Пробуем добавить FULLTEXT индекс (если его нет)
  if (!mysql_execute_query(mysql_conn, query, config)) {
    // Если индекс уже есть, ошибку игнорируем
    log_message(config, "DEBUG", "FULLTEXT index may already exist");
  }

  if (!mysql_create_bookmarks_fts_table(mysql_conn, config)) {
    return 0;
  }

  return 1;
}

int mysql_create_bookmarks_fts_table(MySQLConnection *mysql_conn,
                                     Config *config) {
  // Для MySQL используем FULLTEXT индекс прямо в таблице bookmarks
  const char *query = "ALTER TABLE bookmarks "
                      "ADD FULLTEXT INDEX ft_bookmarks_search (note, "
                      "selected_text, context_before, context_after, tags)";

  // Пробуем добавить FULLTEXT индекс (если его нет)
  if (!mysql_execute_query(mysql_conn, query, config)) {
    // Если индекс уже есть, ошибку игнорируем
    log_message(config, "DEBUG", "FULLTEXT index may already exist");
  }

  if (!mysql_create_reading_history_table(mysql_conn, config)) {
    return 0;
  }

  return 1;
}

int mysql_create_reading_history_table(MySQLConnection *mysql_conn,
                                       Config *config) {
  const char *query =
      "CREATE TABLE IF NOT EXISTS reading_history ("
      "    id INT AUTO_INCREMENT PRIMARY KEY,"
      "    user_fingerprint VARCHAR(64) NOT NULL,"
      "    book_id INT NOT NULL,"
      "    cfi_range VARCHAR(255) NOT NULL,"
      "    page_number INT DEFAULT 0,"
      "    percentage DECIMAL(5,2) DEFAULT 0.00,"
      "    read_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
      "    duration_seconds INT DEFAULT 0,"
      "    INDEX idx_reading_history_user (user_fingerprint),"
      "    INDEX idx_reading_history_book (book_id),"
      "    INDEX idx_reading_history_time (read_time),"
      "    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE"
      ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

  return mysql_execute_query(mysql_conn, query, config);
}

int mysql_archive_needs_rescan(MySQLConnection *mysql_conn,
                               const char *archive_path,
                               const char *current_hash, Config *config) {
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

  if (mysql_ping(mysql_conn->mysql) != 0) {
    log_message(
        config, "WARNING",
        "[MYSQL_ARCHIVE_NEEDS_RESCAN] Connection lost, reconnecting...");
    if (!mysql_reconnect(mysql_conn, config)) {
      log_message(config, "ERROR",
                  "[MYSQL_ARCHIVE_NEEDS_RESCAN] Reconnection failed");
      return 1;
    }
  }

  char *escaped_path = malloc(strlen(archive_path) * 2 + 1);
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

  MYSQL_RES *result = mysql_store_result(mysql_conn->mysql);
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
    const char *stored_hash = row[0];
    const char *mtime_str = row[1];
    const char *needs_rescan_str = row[2];

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

    if (stored_hash && current_hash && strcmp(stored_hash, current_hash) == 0 &&
        stored_mtime == st.st_mtime) {

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

void mysql_update_archive_info(MySQLConnection *mysql_conn,
                               const char *archive_path, const char *hash,
                               int file_count, long total_size,
                               Config *config) {
  if (!mysql_conn || !mysql_conn->mysql)
    return;

  // ПРОВЕРКА СОЕДИНЕНИЯ
  if (mysql_ping(mysql_conn->mysql) != 0) {
    log_message(config, "WARNING", "MySQL connection lost, reconnecting...");
    if (!mysql_reconnect(mysql_conn, config)) {
      log_message(config, "ERROR", "Reconnection failed");
      return;
    }
  }

  struct stat st;
  if (stat(archive_path, &st) != 0)
    return;

  const char *sql =
      "INSERT INTO archives (archive_path, archive_hash, file_count, "
      "total_size, last_modified, last_scanned, needs_rescan) "
      "VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, FALSE) "
      "ON DUPLICATE KEY UPDATE archive_hash = VALUES(archive_hash), file_count "
      "= VALUES(file_count), "
      "total_size = VALUES(total_size), last_modified = VALUES(last_modified), "
      "last_scanned = VALUES(last_scanned), needs_rescan = "
      "VALUES(needs_rescan)";

  MYSQL_STMT *stmt = mysql_stmt_init(mysql_conn->mysql);
  if (!stmt)
    return;

  if (mysql_stmt_prepare(stmt, sql, strlen(sql))) {
    mysql_stmt_close(stmt);
    return;
  }

  MYSQL_BIND bind[5];
  unsigned long lengths[5];
  bool is_null[5] = {0};
  bool false_val = 0;

  memset(bind, 0, sizeof(bind));

  // archive_path
  lengths[0] = strlen(archive_path);
  bind[0].buffer_type = MYSQL_TYPE_STRING;
  bind[0].buffer = (char *)archive_path;
  bind[0].buffer_length = lengths[0];
  bind[0].length = &lengths[0];
  bind[0].is_null = &false_val;

  // archive_hash
  if (hash) {
    lengths[1] = strlen(hash);
    bind[1].buffer_type = MYSQL_TYPE_STRING;
    bind[1].buffer = (char *)hash;
    bind[1].buffer_length = lengths[1];
    bind[1].length = &lengths[1];
    bind[1].is_null = &false_val;
  } else {
    is_null[1] = 1;
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

int mysql_book_exists(MySQLConnection *mysql_conn, const char *filepath,
                      const char *archive_path, const char *internal_path,
                      const char *file_hash, Config *config) {
  if (!mysql_conn || !mysql_conn->mysql) {
    log_message(config, "ERROR",
                "[MYSQL_BOOK_EXISTS] MySQL connection is NULL");
    return 0;
  }

  // Проверяем соединение
  if (mysql_ping(mysql_conn->mysql) != 0) {
    log_message(config, "WARNING",
                "[MYSQL_BOOK_EXISTS] Connection lost, reconnecting...");
    if (!mysql_reconnect(mysql_conn, config)) {
      log_message(config, "ERROR", "[MYSQL_BOOK_EXISTS] Reconnection failed");
      return 0;
    }
  }

  char sql[4096];

  // 1. Проверяем по хешу сначала (самый надёжный)
  if (file_hash && file_hash[0] != '\0') {
    char *escaped_hash = malloc(strlen(file_hash) * 2 + 1);
    if (!escaped_hash) {
      log_message(config, "ERROR",
                  "[MYSQL_BOOK_EXISTS] Memory allocation failed for hash");
      return 0;
    }
    mysql_real_escape_string(mysql_conn->mysql, escaped_hash, file_hash,
                             strlen(file_hash));
    snprintf(sql, sizeof(sql),
             "SELECT id FROM books WHERE file_hash = '%s' LIMIT 1",
             escaped_hash);
    free(escaped_hash);

    if (mysql_query(mysql_conn->mysql, sql) == 0) {
      MYSQL_RES *result = mysql_store_result(mysql_conn->mysql);
      if (result) {
        int exists = (mysql_num_rows(result) > 0);
        mysql_free_result(result);
        if (exists) {
          log_message(config, "DEBUG",
                      "[MYSQL_BOOK_EXISTS] Book exists by hash: %s", file_hash);
          return 1;
        }
      }
    } else {
      log_message(config, "ERROR", "[MYSQL_BOOK_EXISTS] Hash query failed: %s",
                  mysql_error(mysql_conn->mysql));
    }
  }

  // 2. Проверяем по пути (только если filepath не NULL)
  if (!filepath || filepath[0] == '\0') {
    return 0;
  }

  char *escaped_filepath = malloc(strlen(filepath) * 2 + 1);
  if (!escaped_filepath) {
    log_message(config, "ERROR",
                "[MYSQL_BOOK_EXISTS] Memory allocation failed for filepath");
    return 0;
  }
  mysql_real_escape_string(mysql_conn->mysql, escaped_filepath, filepath,
                           strlen(filepath));

  if (archive_path && archive_path[0] != '\0') {
    char *escaped_archive = malloc(strlen(archive_path) * 2 + 1);
    if (!escaped_archive) {
      free(escaped_filepath);
      log_message(config, "ERROR",
                  "[MYSQL_BOOK_EXISTS] Memory allocation failed for archive");
      return 0;
    }
    mysql_real_escape_string(mysql_conn->mysql, escaped_archive, archive_path,
                             strlen(archive_path));

    char *escaped_internal = NULL;
    if (internal_path && internal_path[0] != '\0') {
      escaped_internal = malloc(strlen(internal_path) * 2 + 1);
      if (escaped_internal) {
        mysql_real_escape_string(mysql_conn->mysql, escaped_internal,
                                 internal_path, strlen(internal_path));
      }
    }

    if (escaped_internal) {
      snprintf(sql, sizeof(sql),
               "SELECT id FROM books WHERE file_path = '%s' AND archive_path = "
               "'%s' AND archive_internal_path = '%s' LIMIT 1",
               escaped_filepath, escaped_archive, escaped_internal);
      free(escaped_internal);
    } else {
      snprintf(sql, sizeof(sql),
               "SELECT id FROM books WHERE file_path = '%s' AND archive_path = "
               "'%s' LIMIT 1",
               escaped_filepath, escaped_archive);
    }
    free(escaped_archive);
  } else {
    snprintf(sql, sizeof(sql),
             "SELECT id FROM books WHERE file_path = '%s' AND archive_path IS "
             "NULL LIMIT 1",
             escaped_filepath);
  }

  free(escaped_filepath);

  if (mysql_query(mysql_conn->mysql, sql)) {
    log_message(config, "ERROR", "[MYSQL_BOOK_EXISTS] Path query failed: %s",
                mysql_error(mysql_conn->mysql));
    return 0;
  }

  MYSQL_RES *result = mysql_store_result(mysql_conn->mysql);
  if (!result) {
    return 0;
  }

  int exists = (mysql_num_rows(result) > 0);
  mysql_free_result(result);

  if (exists) {
    log_message(config, "DEBUG", "[MYSQL_BOOK_EXISTS] Book exists by path: %s",
                filepath);
  }

  return exists;
}

int mysql_reconnect(MySQLConnection *mysql_conn, Config *config) {
  log_message(config, "DEBUG", "[MYSQL_RECONNECT] Attempting to reconnect...");

  if (!mysql_conn) {
    log_message(config, "ERROR", "[MYSQL_RECONNECT] mysql_conn is NULL");
    return 0;
  }

  // Закрываем старое соединение если есть
  if (mysql_conn->mysql) {
    mysql_close(mysql_conn->mysql);
    mysql_conn->mysql = NULL;
  }

  // Создаём новое соединение
  mysql_conn->mysql = mysql_init(NULL);
  if (!mysql_conn->mysql) {
    log_message(config, "ERROR", "[MYSQL_RECONNECT] mysql_init failed");
    return 0;
  }

  unsigned int timeout = 30;
  mysql_options(mysql_conn->mysql, MYSQL_OPT_CONNECT_TIMEOUT, &timeout);
  mysql_options(mysql_conn->mysql, MYSQL_OPT_READ_TIMEOUT, &timeout);
  mysql_options(mysql_conn->mysql, MYSQL_OPT_WRITE_TIMEOUT, &timeout);

  // Получаем параметры подключения из config
  // Нужно сохранить параметры в MySQLConnection или передавать их
  // Временно используем глобальные настройки из config

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

void mysql_insert_book(MySQLConnection *mysql_conn, const char *filepath,
                       BookMeta *meta, const char *archive_path,
                       const char *internal_path, const char *file_hash,
                       Config *config) {
  if (!mysql_conn || !mysql_conn->mysql) {
    log_message(config, "ERROR", "MySQL connection is not valid");
    return;
  }

  if (!meta || !filepath) {
    log_message(config, "ERROR", "Invalid parameters for book insertion");
    return;
  }

  // ============================================================
  // ПРОВЕРКА СОЕДИНЕНИЯ - БЕЗ ПЕРЕПОДКЛЮЧЕНИЯ!
  // ============================================================
  if (mysql_ping(mysql_conn->mysql) != 0) {
    log_message(config, "WARNING", "MySQL connection lost, reconnecting...");
    // Просто закрываем и создаём новое
    MySQLConnection *new_conn = mysql_conn_connect(config);
    if (new_conn) {
      // Безопасно заменяем: копируем указатели, не копируя структуру
      if (mysql_conn->mysql) {
        mysql_close(mysql_conn->mysql);
      }
      mysql_conn->mysql = new_conn->mysql;
      free(new_conn);
      log_message(config, "INFO", "Reconnected successfully");
    } else {
      log_message(config, "ERROR", "Reconnection failed");
      return;
    }
  }

  // Подготовка данных
  const char *filename = "unknown";
  if (internal_path && internal_path[0] != '\0') {
    filename = internal_path;
  } else {
    const char *slash = strrchr(filepath, '/');
    if (slash) {
      filename = slash + 1;
    } else {
      filename = filepath;
    }
  }

  const char *file_type = normalize_file_type(filename);

  // Экранируем строки с правильной обработкой
  char *escaped_filepath = malloc(strlen(filepath) * 2 + 1);
  char *escaped_filename = malloc(strlen(filename) * 2 + 1);
  char *escaped_filetype = malloc(strlen(file_type) * 2 + 1);
  char *escaped_title =
      malloc((meta->title ? strlen(meta->title) : 13) * 2 + 1);
  char *escaped_author =
      malloc((meta->author ? strlen(meta->author) : 14) * 2 + 1);
  char *escaped_genre =
      meta->genre ? malloc(strlen(meta->genre) * 2 + 1) : NULL;
  char *escaped_series =
      meta->series ? malloc(strlen(meta->series) * 2 + 1) : NULL;
  char *escaped_language =
      meta->language ? malloc(strlen(meta->language) * 2 + 1) : NULL;
  char *escaped_publisher =
      meta->publisher ? malloc(strlen(meta->publisher) * 2 + 1) : NULL;
  char *escaped_description = NULL;
  char *escaped_archive =
      archive_path ? malloc(strlen(archive_path) * 2 + 1) : NULL;
  char *escaped_internal =
      internal_path ? malloc(strlen(internal_path) * 2 + 1) : NULL;

  if (!escaped_filepath || !escaped_filename || !escaped_filetype ||
      !escaped_title || !escaped_author) {
    log_message(config, "ERROR", "Memory allocation failed");
    free(escaped_filepath);
    free(escaped_filename);
    free(escaped_filetype);
    free(escaped_title);
    free(escaped_author);
    free(escaped_genre);
    free(escaped_series);
    free(escaped_language);
    free(escaped_publisher);
    free(escaped_description);
    free(escaped_archive);
    free(escaped_internal);
    return;
  }

  mysql_real_escape_string(mysql_conn->mysql, escaped_filepath, filepath,
                           strlen(filepath));
  mysql_real_escape_string(mysql_conn->mysql, escaped_filename, filename,
                           strlen(filename));
  mysql_real_escape_string(mysql_conn->mysql, escaped_filetype, file_type,
                           strlen(file_type));
  mysql_real_escape_string(mysql_conn->mysql, escaped_title,
                           meta->title ? meta->title : "Unknown Title",
                           meta->title ? strlen(meta->title) : 13);
  mysql_real_escape_string(mysql_conn->mysql, escaped_author,
                           meta->author ? meta->author : "Unknown Author",
                           meta->author ? strlen(meta->author) : 14);

  if (meta->genre) {
    mysql_real_escape_string(mysql_conn->mysql, escaped_genre, meta->genre,
                             strlen(meta->genre));
  }
  if (meta->series) {
    mysql_real_escape_string(mysql_conn->mysql, escaped_series, meta->series,
                             strlen(meta->series));
  }
  if (meta->language) {
    mysql_real_escape_string(mysql_conn->mysql, escaped_language,
                             meta->language, strlen(meta->language));
  }
  if (meta->publisher) {
    mysql_real_escape_string(mysql_conn->mysql, escaped_publisher,
                             meta->publisher, strlen(meta->publisher));
  }
  if (archive_path) {
    mysql_real_escape_string(mysql_conn->mysql, escaped_archive, archive_path,
                             strlen(archive_path));
  }
  if (internal_path) {
    mysql_real_escape_string(mysql_conn->mysql, escaped_internal, internal_path,
                             strlen(internal_path));
  }

  // ============================================================
  // ОБРАБОТКА DESCRIPTION
  // ============================================================
  if (meta->description && meta->description[0] != '\0') {
    char *desc_to_use = NULL;
    char *sanitized = NULL; // Для отслеживания выделенной памяти

    if (!is_valid_utf8_string(meta->description)) {
      log_message(config, "WARNING",
                  "Invalid UTF-8 in description, sanitizing");
      sanitized = sanitize_utf8_string(meta->description);
      desc_to_use = sanitized;
    } else {
      desc_to_use = (char *)meta->description;
    }

    if (desc_to_use) {
      size_t desc_len = strlen(desc_to_use);
      if (desc_len > 65535) {
        log_message(config, "WARNING", "Description too long (%zu), truncating",
                    desc_len);
        desc_len = 65535;
        char *truncated = malloc(desc_len + 1);
        if (truncated) {
          memcpy(truncated, desc_to_use, desc_len);
          truncated[desc_len] = '\0';
          if (sanitized) {
            free(sanitized);
            sanitized = NULL;
          }
          desc_to_use = truncated;
        }
      }

      if (desc_to_use) {
        escaped_description = malloc(strlen(desc_to_use) * 2 + 1);
        if (escaped_description) {
          mysql_real_escape_string(mysql_conn->mysql, escaped_description,
                                   desc_to_use, strlen(desc_to_use));
        }
        if (sanitized) {
          free(sanitized);
          sanitized = NULL;
        }
      }
    }
  }

  // ============================================================
  // ФОРМИРУЕМ SQL ЗАПРОС
  // ============================================================
  char sql[32768]; // Увеличиваем буфер
  int len = 0;
  int written;

  // size_t sql_size =
  //     4096 + (meta->description ? strlen(meta->description) * 2 + 1 : 0);
  // char *sql = malloc(sql_size);

  written =
      snprintf(sql + len, sizeof(sql) - len,
               "INSERT IGNORE INTO books ("
               "file_path, file_name, file_size, file_type, "
               "archive_path, archive_internal_path, file_hash, title, author, "
               "genre, series, series_number, year, language, publisher, "
               "description, last_modified"
               ") VALUES (");
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at header");
    goto cleanup;
  }
  len += written;

  // 1. file_path
  written = snprintf(sql + len, sizeof(sql) - len, "'%s', ", escaped_filepath);
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at file_path");
    goto cleanup;
  }
  len += written;

  // 2. file_name
  written = snprintf(sql + len, sizeof(sql) - len, "'%s', ", escaped_filename);
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at file_name");
    goto cleanup;
  }
  len += written;

  // 3. file_size
  written = snprintf(sql + len, sizeof(sql) - len, "%lld, ",
                     meta->file_size > 0 ? (long long)meta->file_size : 0);
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at file_size");
    goto cleanup;
  }
  len += written;

  // 4. file_type
  written = snprintf(sql + len, sizeof(sql) - len, "'%s', ", escaped_filetype);
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at file_type");
    goto cleanup;
  }
  len += written;

  // 5. archive_path
  if (archive_path && archive_path[0] != '\0' && escaped_archive) {
    written = snprintf(sql + len, sizeof(sql) - len, "'%s', ", escaped_archive);
  } else {
    written = snprintf(sql + len, sizeof(sql) - len, "NULL, ");
  }
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at archive_path");
    goto cleanup;
  }
  len += written;

  // 6. archive_internal_path
  if (internal_path && internal_path[0] != '\0' && escaped_internal) {
    written =
        snprintf(sql + len, sizeof(sql) - len, "'%s', ", escaped_internal);
  } else {
    written = snprintf(sql + len, sizeof(sql) - len, "NULL, ");
  }
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR",
                "SQL buffer overflow at archive_internal_path");
    goto cleanup;
  }
  len += written;

  // 7. file_hash
  if (file_hash && file_hash[0] != '\0') {
    written = snprintf(sql + len, sizeof(sql) - len, "'%s', ", file_hash);
  } else {
    written = snprintf(sql + len, sizeof(sql) - len, "NULL, ");
  }
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at file_hash");
    goto cleanup;
  }
  len += written;

  // 8. title
  written = snprintf(sql + len, sizeof(sql) - len, "'%s', ", escaped_title);
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at title");
    goto cleanup;
  }
  len += written;

  // 9. author
  written = snprintf(sql + len, sizeof(sql) - len, "'%s', ", escaped_author);
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at author");
    goto cleanup;
  }
  len += written;

  // 10. genre
  if (meta->genre && escaped_genre) {
    written = snprintf(sql + len, sizeof(sql) - len, "'%s', ", escaped_genre);
  } else {
    written = snprintf(sql + len, sizeof(sql) - len, "NULL, ");
  }
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at genre");
    goto cleanup;
  }
  len += written;

  // 11. series
  if (meta->series && escaped_series) {
    written = snprintf(sql + len, sizeof(sql) - len, "'%s', ", escaped_series);
  } else {
    written = snprintf(sql + len, sizeof(sql) - len, "NULL, ");
  }
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at series");
    goto cleanup;
  }
  len += written;

  // 12. series_number
  written = snprintf(sql + len, sizeof(sql) - len, "%d, ",
                     meta->series_number > 0 ? meta->series_number : 0);
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at series_number");
    goto cleanup;
  }
  len += written;

  // 13. year
  written = snprintf(sql + len, sizeof(sql) - len, "%d, ",
                     meta->year > 0 ? meta->year : 0);
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at year");
    goto cleanup;
  }
  len += written;

  // 14. language
  if (meta->language && escaped_language) {
    written =
        snprintf(sql + len, sizeof(sql) - len, "'%s', ", escaped_language);
  } else {
    written = snprintf(sql + len, sizeof(sql) - len, "NULL, ");
  }
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at language");
    goto cleanup;
  }
  len += written;

  // 15. publisher
  if (meta->publisher && escaped_publisher) {
    written =
        snprintf(sql + len, sizeof(sql) - len, "'%s', ", escaped_publisher);
  } else {
    written = snprintf(sql + len, sizeof(sql) - len, "NULL, ");
  }
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at publisher");
    goto cleanup;
  }
  len += written;

  // 16. description
  if (escaped_description) {
    written =
        snprintf(sql + len, sizeof(sql) - len, "'%s'", escaped_description);
  } else {
    written = snprintf(sql + len, sizeof(sql) - len, "NULL");
  }
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at description");
    goto cleanup;
  }
  len += written;

  // 17. last_modified
  written = snprintf(sql + len, sizeof(sql) - len, ", NOW())");
  if (written < 0 || (size_t)written >= sizeof(sql) - len) {
    log_message(config, "ERROR", "SQL buffer overflow at last_modified");
    goto cleanup;
  }
  len += written;

  // Выполняем запрос
  if (mysql_query(mysql_conn->mysql, sql)) {
    log_message(config, "ERROR", "MySQL insert failed: %s",
                mysql_error(mysql_conn->mysql));
    log_message(config, "ERROR", "Failed SQL: %s", sql);
    goto cleanup;
  }

  my_ulonglong affected_rows = mysql_affected_rows(mysql_conn->mysql);
  if (affected_rows == 0) {
    log_message(config, "DEBUG", "Book already exists: %s - %s",
                meta->title ? meta->title : "Unknown",
                meta->author ? meta->author : "Unknown");
  } else {
    log_message(config, "INFO",
                "Book inserted successfully: %s - %s (type: %s)",
                meta->title ? meta->title : "Unknown",
                meta->author ? meta->author : "Unknown", file_type);
  }

cleanup:
  // Освобождаем память
  free(escaped_filepath);
  free(escaped_filename);
  free(escaped_filetype);
  free(escaped_title);
  free(escaped_author);
  free(escaped_genre);
  free(escaped_series);
  free(escaped_language);
  free(escaped_publisher);
  if (escaped_description)
    free(escaped_description);
  free(escaped_archive);
  free(escaped_internal);
}

int mysql_begin_transaction(MySQLConnection *mysql_conn, Config *config) {
  if (!mysql_conn || !mysql_conn->mysql) {
    log_message(config, "ERROR", "MySQL connection is NULL");
    return 0;
  }

  // Проверяем, что autocommit выключен
  if (mysql_query(mysql_conn->mysql, "SET autocommit = 0")) {
    log_message(config, "WARNING", "Failed to disable autocommit: %s",
                mysql_error(mysql_conn->mysql));
  }

  if (mysql_query(mysql_conn->mysql, "START TRANSACTION")) {
    log_message(config, "ERROR", "Failed to start transaction: %s",
                mysql_error(mysql_conn->mysql));
    return 0;
  }

  log_message(config, "DEBUG", "Transaction started");
  return 1;
}

int mysql_commit_transaction(MySQLConnection *mysql_conn, Config *config) {
  if (!mysql_conn || !mysql_conn->mysql) {
    log_message(config, "ERROR", "MySQL connection is NULL");
    return 0;
  }

  if (mysql_query(mysql_conn->mysql, "COMMIT")) {
    log_message(config, "ERROR", "Failed to commit transaction: %s",
                mysql_error(mysql_conn->mysql));
    return 0;
  }

  return 1;
}

int mysql_rollback_transaction(MySQLConnection *mysql_conn, Config *config) {
  if (!mysql_conn || !mysql_conn->mysql) {
    log_message(config, "ERROR", "MySQL connection is NULL");
    return 0;
  }

  if (mysql_query(mysql_conn->mysql, "ROLLBACK")) {
    log_message(config, "ERROR", "Failed to rollback transaction: %s",
                mysql_error(mysql_conn->mysql));
    return 0;
  }

  return 1;
}
