#include "database.h"
#include "common.h"
#include "database_mysql.h"
#include "utils.h"
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>

DatabaseHandle *db_connect(Config *config) {
  if (!config) {
    log_message(NULL, "ERROR", "Config is NULL");
    return NULL;
  }

  log_message(config, "DEBUG", "Attempting to connect to database type: %s",
              config->database.type);

  DatabaseHandle *db_handle = malloc(sizeof(DatabaseHandle));
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
    sqlite3 *db;

    // Открываем базу данных
    int rc = sqlite3_open(config->database.path, &db);

    if (rc == SQLITE_OK) {
      db_handle->connection = db;
      db_handle->stmt_insert_book = NULL; // Изначально NULL

      log_message(config, "INFO", "Connected to SQLite database: %s",
                  config->database.path);

      // Ждать до 10000 миллисекунд (10 секунд), если файл базы заблокирован
      sqlite3_busy_timeout(db, 10000);

      // Включаем WAL-режим (пишет в лог вместо перезаписи основного файла)
      sqlite3_exec(db, "PRAGMA journal_mode = WAL;", NULL, NULL, NULL);

      // 1. Включаем поддержку внешних ключей
      sqlite3_exec(db, "PRAGMA foreign_keys = ON;", NULL, NULL, NULL);

      // 2. Включаем режим WAL (Write-Ahead Logging).
      // Избавляет от блокировок чтения-записи и ускоряет вставку на Linux.
      sqlite3_exec(db, "PRAGMA journal_mode = WAL;", NULL, NULL, NULL);

      // 3. Ослабляем синхронизацию с диском.
      // Режим NORMAL в сочетании с WAL абсолютно безопасен при падении
      // приложения, но в редких случаях (сбой питания) может
      // повредить последние транзакции. Если нужна максимальная скорость и
      // данные не критичны — можно поставить "OFF".
      sqlite3_exec(db, "PRAGMA synchronous = NORMAL;", NULL, NULL, NULL);

      // 4. Увеличиваем размер кэша в оперативной памяти (например, до 1000000
      // страниц). По умолчанию кэш часто равен всего 2000 страниц (~2 МБ).
      sqlite3_exec(db, "PRAGMA cache_size = -1000000;", NULL, NULL,
                   NULL); // Минус означает КБ (~1000 МБ кэша)

      // 5. Храним временные таблицы и индексы в оперативной памяти, а не на
      // диске.
      sqlite3_exec(db, "PRAGMA temp_store = MEMORY;", NULL, NULL, NULL);

      // 6. -- 256MB mmap
      sqlite3_exec(db, "PRAGMA mmap_size = 268435456;", NULL, NULL, NULL);

      return db_handle;

    } else {
      // Важно: если sqlite3_open вернул ошибку, дескриптор 'db' все равно может
      // требовать освобождения, но только если он не NULL. Безопасная проверка:
      log_message(config, "ERROR", "Cannot open SQLite database: %s",
                  db ? sqlite3_errmsg(db) : "Unknown memory error");
      if (db)
        sqlite3_close(db);
      free(db_handle);
      return NULL;
    }
  } else if (strcmp(config->database.type, "mysql") == 0) {
    log_message(config, "DEBUG", "Connecting to MySQL database...");
    db_handle->db_type = DB_MYSQL;
    MySQLConnection *mysql_conn = mysql_conn_connect(config);
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

void db_close(DatabaseHandle *db_handle) {
  if (!db_handle)
    return;

  switch (db_handle->db_type) {
  case DB_SQLITE:
    if (db_handle->connection) {
      sqlite3 *db = (sqlite3 *)db_handle->connection;
      // Закрываем все подготовленные запросы
      while (sqlite3_close(db) == SQLITE_BUSY) {
        sqlite3_stmt *stmt;
        while ((stmt = sqlite3_next_stmt(db, NULL)) != NULL) {
          sqlite3_finalize(stmt);
        }
      }
    }
    break;
  case DB_MYSQL:
    if (db_handle->connection) {
      mysql_conn_close((MySQLConnection *)db_handle->connection);
    }
    break;
  default:
    // Ничего не делаем для неизвестного типа
    break;
  }
  free(db_handle);
}

int db_execute(DatabaseHandle *db_handle, const char *sql, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR", "No database connection for execute");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    char *err_msg = NULL;
    int rc = sqlite3_exec((sqlite3 *)db_handle->connection, sql, NULL, NULL,
                          &err_msg);
    if (rc != SQLITE_OK) {
      log_message(config, "ERROR", "SQL error: %s", err_msg);
      sqlite3_free(err_msg);
      return 0;
    }
    return 1;
  }
  case DB_MYSQL:
    return mysql_execute_query((MySQLConnection *)db_handle->connection, sql,
                               config);
  default:
    log_message(config, "ERROR", "Unknown database type in execute: %d",
                db_handle->db_type);
    return 0;
  }
}

int create_database_tables(DatabaseHandle *db_handle, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR", "No database connection for creating tables");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    const char *create_books_table =
        "CREATE TABLE IF NOT EXISTS books ("
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
    const char *create_hash_index =
        "CREATE INDEX IF NOT EXISTS idx_books_file_hash ON books(file_hash);";
    if (!db_execute(db_handle, create_hash_index, config)) {
      log_message(config, "WARNING", "Failed to create hash index");
    }

    const char *idx_books_file_path =
        "CREATE INDEX IF NOT EXISTS idx_books_file_path ON books(file_path);";
    if (!db_execute(db_handle, idx_books_file_path, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_books_file_path index");
    }

    const char *idx_books_archive_path =
        "CREATE INDEX IF NOT EXISTS idx_books_archive_path ON "
        "books(archive_path);";
    if (!db_execute(db_handle, idx_books_archive_path, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_books_archive_path index");
    }

    const char *idx_books_title =
        "CREATE INDEX IF NOT EXISTS idx_books_title ON books(title);";
    if (!db_execute(db_handle, idx_books_title, config)) {
      log_message(config, "WARNING", "Failed to create idx_books_title index");
    }

    const char *idx_books_author =
        "CREATE INDEX IF NOT EXISTS idx_books_author ON books(author);";
    if (!db_execute(db_handle, idx_books_author, config)) {
      log_message(config, "WARNING", "Failed to create idx_books_author index");
    }

    const char *idx_books_genre =
        "CREATE INDEX IF NOT EXISTS idx_books_genre ON books(genre);";
    if (!db_execute(db_handle, idx_books_genre, config)) {
      log_message(config, "WARNING", "Failed to create idx_books_genre index");
    }

    const char *idx_books_series =
        "CREATE INDEX IF NOT EXISTS idx_books_series ON books(series);";
    if (!db_execute(db_handle, idx_books_series, config)) {
      log_message(config, "WARNING", "Failed to create idx_books_series index");
    }

    const char *idx_books_year =
        "CREATE INDEX IF NOT EXISTS idx_books_year ON books(year);";
    if (!db_execute(db_handle, idx_books_year, config)) {
      log_message(config, "WARNING", "Failed to create idx_books_year index");
    }

    const char *idx_books_language =
        "CREATE INDEX IF NOT EXISTS idx_books_language ON books(language);";
    if (!db_execute(db_handle, idx_books_language, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_books_language index");
    }

    const char *idx_books_publisher =
        "CREATE INDEX IF NOT EXISTS idx_books_publisher ON books(publisher);";
    if (!db_execute(db_handle, idx_books_publisher, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_books_publisher index");
    }

    break;
  }
  case DB_MYSQL:
    return mysql_create_tables((MySQLConnection *)db_handle->connection,
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

int create_archive_table(DatabaseHandle *db_handle, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR",
                "No database connection for creating archive table");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    const char *create_archives_table =
        "CREATE TABLE IF NOT EXISTS archives ("
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
    return mysql_create_archive_table((MySQLConnection *)db_handle->connection,
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

int create_ratings_table(DatabaseHandle *db_handle, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR",
                "No database connection for creating ratings table");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    const char *create_ratings_table_sql =
        "CREATE TABLE IF NOT EXISTS book_ratings ("
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
    return mysql_create_ratings_table((MySQLConnection *)db_handle->connection,
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

int create_favorites_table(DatabaseHandle *db_handle, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR",
                "No database connection for creating favorites table");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    const char *create_favorites_table_sql =
        "CREATE TABLE IF NOT EXISTS book_favorites ("
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
        (MySQLConnection *)db_handle->connection, config);
  default:
    log_message(config, "ERROR",
                "Unknown database type in create favorites table: %d",
                db_handle->db_type);
    return 0;
  }

  if (!create_bookmarks_table(db_handle, config)) {
    return 0;
  }

  return 1;
}

int create_bookmarks_table(DatabaseHandle *db_handle, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR",
                "No database connection for creating bookmarks table");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    const char *create_bookmarks_table_sql =
        "CREATE TABLE IF NOT EXISTS bookmarks ("
        "    id INTEGER PRIMARY KEY AUTOINCREMENT,"
        "    user_fingerprint VARCHAR(64) NOT NULL,"
        "    book_id INTEGER NOT NULL,"
        "    cfi_range VARCHAR(255) NOT NULL,"
        "    page_number INTEGER DEFAULT 0,"
        "    percentage DECIMAL(5,2) DEFAULT 0,"
        "    note TEXT,"
        "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
        "    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
        "    last_read TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
        "    is_deleted BOOLEAN DEFAULT 0, type TEXT DEFAULT 'bookmark', color "
        "TEXT DEFAULT 'yellow', selected_text TEXT, context_before TEXT, "
        "context_after TEXT, tags TEXT, is_public BOOLEAN DEFAULT 0,"
        "    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE"
        ");";

    if (!db_execute(db_handle, create_bookmarks_table_sql, config)) {
      log_message(config, "ERROR", "Bookmarks to create favorites table");
      return 0;
    }

    log_message(config, "DEBUG", "Bookmarks table created successfully");

    const char *idx_bookmarks_user_book =
        "CREATE INDEX IF NOT EXISTS idx_bookmarks_user_book ON "
        "bookmarks(user_fingerprint, book_id);";
    if (!db_execute(db_handle, idx_bookmarks_user_book, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_bookmarks_user_book index");
    }

    const char *idx_bookmarks_last_read =
        "CREATE INDEX IF NOT EXISTS idx_bookmarks_last_read ON "
        "bookmarks(last_read DESC);";
    if (!db_execute(db_handle, idx_bookmarks_last_read, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_bookmarks_last_read index");
    }

    const char *idx_bookmarks_book =
        "CREATE INDEX IF NOT EXISTS idx_bookmarks_book ON bookmarks(book_id);";
    if (!db_execute(db_handle, idx_bookmarks_book, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_bookmarks_book index");
    }

    const char *idx_bookmarks_type =
        "CREATE INDEX IF NOT EXISTS idx_bookmarks_type ON bookmarks(type);";
    if (!db_execute(db_handle, idx_bookmarks_type, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_bookmarks_type index");
    }

    const char *idx_bookmarks_color =
        "CREATE INDEX IF NOT EXISTS idx_bookmarks_color ON bookmarks(color);";
    if (!db_execute(db_handle, idx_bookmarks_color, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_bookmarks_color index");
    }
    const char *idx_bookmarks_public =
        "CREATE INDEX IF NOT EXISTS idx_bookmarks_public ON "
        "bookmarks(is_public);";
    if (!db_execute(db_handle, idx_bookmarks_public, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_bookmarks_public index");
    }

    break;
  }
  case DB_MYSQL:
    return mysql_create_bookmarks_table(
        (MySQLConnection *)db_handle->connection, config);
  default:
    log_message(config, "ERROR",
                "Unknown database type in create bookmarks table: %d",
                db_handle->db_type);
    return 0;
  }

  if (!create_books_fts_table(db_handle, config)) {
    return 0;
  }

  return 1;
}

int create_books_fts_table(DatabaseHandle *db_handle, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR",
                "No database connection for creating bookmarks table");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    const char *create_books_fts_sql =
        "CREATE VIRTUAL  TABLE IF NOT EXISTS books_fts USING fts5("
        "    title,"
        "    author,"
        "    genre,"
        "    series,"
        "    publisher,"
        "    description,"
        "    content='books',"
        "    content_rowid='id'"
        ");";

    if (!db_execute(db_handle, create_books_fts_sql, config)) {
      log_message(config, "ERROR", "Book to create books_fts table");
      return 0;
    }

    log_message(config, "DEBUG", "books_fts table created successfully");

    const char *books_ai =
        "CREATE TRIGGER IF NOT EXISTS books_ai AFTER INSERT ON books BEGIN "
        "INSERT INTO books_fts(rowid, title, author, genre, series, publisher, "
        "description) VALUES (new.id, new.title, new.author, new.genre, "
        "new.series, new.publisher, new.description);END;";
    if (!db_execute(db_handle, books_ai, config)) {
      log_message(config, "WARNING", "Failed to create books_ai index");
    }

    const char *books_ad =
        "CREATE TRIGGER IF NOT EXISTS books_ad AFTER DELETE ON books BEGIN "
        "INSERT INTO books_fts(books_fts, rowid, title, author, genre, series, "
        "publisher, description) VALUES ('delete', old.id, old.title, "
        "old.author, old.genre, old.series, old.publisher, old.description); "
        "END;";
    if (!db_execute(db_handle, books_ad, config)) {
      log_message(config, "WARNING", "Failed to create books_ad index");
    }

    const char *books_au =
        "CREATE TRIGGER IF NOT EXISTS books_au AFTER UPDATE ON books BEGIN "
        "INSERT INTO books_fts(books_fts, rowid, title, author, genre, series, "
        "publisher, description) VALUES ('delete', old.id, old.title, "
        "old.author, old.genre, old.series, old.publisher, old.description); "
        "INSERT INTO books_fts(rowid, title, author, genre, series, publisher, "
        "description) VALUES (new.id, new.title, new.author, new.genre, "
        "new.series, new.publisher, new.description); END;";
    if (!db_execute(db_handle, books_au, config)) {
      log_message(config, "WARNING", "Failed to create books_au index");
    }

    break;
  }

  case DB_MYSQL:
    return mysql_create_books_fts_table(
        (MySQLConnection *)db_handle->connection, config);
  default:
    log_message(config, "ERROR",
                "Unknown database type in create books table: %d",
                db_handle->db_type);
    return 0;
  }

  if (!create_bookmark_tags_table(db_handle, config)) {
    return 0;
  }

  if (!create_bookmarks_fts_table(db_handle, config)) {
    return 0;
  }

  return 1;
}

int create_bookmarks_fts_table(DatabaseHandle *db_handle, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR",
                "No database connection for creating bookmarks table");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    const char *create_bookmarks_fts_sql =
        "CREATE VIRTUAL  TABLE IF NOT EXISTS bookmarks_fts USING fts5("
        "    note,"
        "    selected_text,"
        "    context_before,"
        "    context_after,"
        "    tags,"
        "    content='bookmarks',"
        "    content_rowid='id',"
        "    tokenize='unicode61'"
        ");";

    if (!db_execute(db_handle, create_bookmarks_fts_sql, config)) {
      log_message(config, "ERROR", "Bookmarks to create favorites table");
      return 0;
    }

    log_message(config, "DEBUG", "Bookmarks table created successfully");

    const char *bookmarks_ai =
        "CREATE TRIGGER IF NOT EXISTS bookmarks_ai AFTER INSERT ON bookmarks "
        "BEGIN INSERT INTO bookmarks_fts(rowid, note, selected_text, "
        "context_before, context_after, tags) VALUES (new.id, new.note, "
        "new.selected_text, new.context_before, new.context_after, new.tags); "
        "END;";
    if (!db_execute(db_handle, bookmarks_ai, config)) {
      log_message(config, "WARNING", "Failed to create bookmarks_ai index");
    }

    const char *bookmarks_ad =
        "CREATE TRIGGER IF NOT EXISTS bookmarks_ad AFTER DELETE ON bookmarks "
        "BEGIN INSERT INTO bookmarks_fts(bookmarks_fts, rowid, note, "
        "selected_text, context_before, context_after, tags) VALUES ('delete', "
        "old.id, old.note, old.selected_text, old.context_before, "
        "old.context_after, old.tags); END;";
    if (!db_execute(db_handle, bookmarks_ad, config)) {
      log_message(config, "WARNING", "Failed to create bookmarks_ad index");
    }

    const char *bookmarks_au =
        "CREATE TRIGGER IF NOT EXISTS bookmarks_au AFTER UPDATE ON bookmarks "
        "BEGIN INSERT INTO bookmarks_fts(bookmarks_fts, rowid, note, "
        "selected_text, context_before, context_after, tags) VALUES ('delete', "
        "old.id, old.note, old.selected_text, old.context_before, "
        "old.context_after, old.tags); INSERT INTO bookmarks_fts(rowid, note, "
        "selected_text, context_before, context_after, tags) VALUES (new.id, "
        "new.note, new.selected_text, new.context_before, new.context_after, "
        "new.tags); END;";
    if (!db_execute(db_handle, bookmarks_au, config)) {
      log_message(config, "WARNING", "Failed to create bookmarks_au index");
    }

    break;
  }
  case DB_MYSQL:
    return mysql_create_bookmarks_fts_table(
        (MySQLConnection *)db_handle->connection, config);
  default:
    log_message(config, "ERROR",
                "Unknown database type in create bookmarks table: %d",
                db_handle->db_type);
    return 0;
  }

  if (!create_bookmark_tags_table(db_handle, config)) {
    return 0;
  }

  return 1;
}

int create_bookmark_tags_table(DatabaseHandle *db_handle, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR",
                "No database connection for creating bookmarks table");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    const char *create_bookmark_tags_t_sql =
        "CREATE TABLE IF NOT EXISTS bookmark_tags ("
        "    id INTEGER PRIMARY KEY AUTOINCREMENT,"
        "    user_fingerprint VARCHAR(64) NOT NULL,"
        "    name VARCHAR(50) NOT NULL,"
        "    color VARCHAR(20) DEFAULT 'default',"
        "    usage_count INTEGER DEFAULT 0,"
        "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
        "    UNIQUE(user_fingerprint, name)"
        ");";

    if (!db_execute(db_handle, create_bookmark_tags_t_sql, config)) {
      log_message(config, "ERROR", "Bookmarks to create favorites table");
      return 0;
    }

    log_message(config, "DEBUG", "Bookmarks table created successfully");

    const char *idx_tags_user = "CREATE INDEX IF NOT EXISTS idx_tags_user ON "
                                "bookmark_tags(user_fingerprint);";
    if (!db_execute(db_handle, idx_tags_user, config)) {
      log_message(config, "WARNING", "Failed to create idx_tags_user index");
    }

    const char *idx_tags_name =
        "CREATE INDEX IF NOT EXISTS idx_tags_name ON bookmark_tags(name);";
    if (!db_execute(db_handle, idx_tags_name, config)) {
      log_message(config, "WARNING", "Failed to create idx_tags_name index");
    }

    break;
  }
  case DB_MYSQL:
    return mysql_create_bookmark_tags_table(
        (MySQLConnection *)db_handle->connection, config);
  default:
    log_message(config, "ERROR",
                "Unknown database type in create bookmarks table: %d",
                db_handle->db_type);
    return 0;
  }

  if (!create_reading_history_table(db_handle, config)) {
    return 0;
  }

  return 1;
}

int create_reading_history_table(DatabaseHandle *db_handle, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR",
                "No database connection for creating reading_history table");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    const char *create_reading_history_table_sql =
        "CREATE TABLE IF NOT EXISTS reading_history ("
        "    id INTEGER PRIMARY KEY AUTOINCREMENT,"
        "    user_fingerprint VARCHAR(64) NOT NULL,"
        "    book_id INTEGER NOT NULL,"
        "    cfi_range VARCHAR(255) NOT NULL,"
        "    page_number INTEGER DEFAULT 0,"
        "    percentage DECIMAL(5,2) DEFAULT 0,"
        "    read_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,"
        "    duration_seconds INTEGER DEFAULT 0,"
        "    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE"
        ");";

    if (!db_execute(db_handle, create_reading_history_table_sql, config)) {
      log_message(config, "ERROR", "Failed to create reading_history table");
      return 0;
    }

    log_message(config, "DEBUG", "Reading_history table created successfully");

    const char *idx_reading_history_user =
        "CREATE INDEX IF NOT EXISTS idx_reading_history_user ON "
        "reading_history(user_fingerprint);";
    if (!db_execute(db_handle, idx_reading_history_user, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_reading_history_user index");
    }

    const char *idx_reading_history_book =
        "CREATE INDEX IF NOT EXISTS idx_reading_history_book ON "
        "reading_history(book_id);";
    if (!db_execute(db_handle, idx_reading_history_book, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_reading_history_book index");
    }

    const char *idx_reading_history_time =
        "CREATE INDEX IF NOT EXISTS idx_reading_history_time ON "
        "reading_history(read_time DESC);";
    if (!db_execute(db_handle, idx_reading_history_time, config)) {
      log_message(config, "WARNING",
                  "Failed to create idx_reading_history_time index");
    }

    break;
  }
  case DB_MYSQL:
    return mysql_create_reading_history_table(
        (MySQLConnection *)db_handle->connection, config);
  default:
    log_message(config, "ERROR",
                "Unknown database type in create reading_history table: %d",
                db_handle->db_type);
    return 0;
  }

  return 1;
}

int archive_needs_rescan(DatabaseHandle *db_handle, const char *archive_path,
                         const char *current_hash, Config *config) {
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
    sqlite3 *db = (sqlite3 *)db_handle->connection;

    const char *sql = "SELECT archive_hash, last_modified, needs_rescan FROM "
                      "archives WHERE archive_path = ?";
    sqlite3_stmt *stmt;

    if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
      sqlite3_bind_text(stmt, 1, archive_path, -1, SQLITE_TRANSIENT);

      if (sqlite3_step(stmt) == SQLITE_ROW) {
        const char *stored_hash = (const char *)sqlite3_column_text(stmt, 0);
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

        if (stored_hash && current_hash &&
            strcmp(stored_hash, current_hash) == 0 &&
            stored_mtime == st.st_mtime) {

          log_message(config, "DEBUG",
                      "[ARCHIVE_NEEDS_RESCAN] Archive unchanged, skipping: %s",
                      archive_path);

          const char *update_sql = "UPDATE archives SET last_scanned = "
                                   "CURRENT_TIMESTAMP WHERE archive_path = ?";
          sqlite3_stmt *update_stmt;
          if (sqlite3_prepare_v2(db, update_sql, -1, &update_stmt, NULL) ==
              SQLITE_OK) {
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
    return mysql_archive_needs_rescan((MySQLConnection *)db_handle->connection,
                                      archive_path, current_hash, config);

  default:
    log_message(config, "ERROR",
                "[ARCHIVE_NEEDS_RESCAN] Unknown database type: %d",
                db_handle->db_type);
    return 1;
  }

  return 1;
}

void update_archive_info(DatabaseHandle *db_handle, const char *archive_path,
                         const char *hash, int file_count, long total_size,
                         Config *config) {
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

    sqlite3 *db = (sqlite3 *)db_handle->connection;
    const char *sql =
        "INSERT OR REPLACE INTO archives (archive_path, archive_hash, "
        "file_count, total_size, last_modified, last_scanned, needs_rescan) "
        "VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, 0)";
    sqlite3_stmt *stmt;

    if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
      sqlite3_bind_text(stmt, 1, archive_path, -1, SQLITE_TRANSIENT);
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
    mysql_update_archive_info((MySQLConnection *)db_handle->connection,
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

int book_exists(DatabaseHandle *db_handle, const char *filepath,
                const char *archive_path, const char *internal_path,
                const char *file_hash, Config *config) {

  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR", "No database connection for book_exists");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    sqlite3 *db = (sqlite3 *)db_handle->connection;
    sqlite3_stmt *stmt;

    // 1. Сначала проверяем по хешу (самый надёжный)
    // Нет смысла проверять по хешу, поле file_hash уникальное
    // if (file_hash && file_hash[0] != '\0') {
    //   const char *sql = "SELECT id FROM books WHERE file_hash = ?";
    //   if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
    //     sqlite3_bind_text(stmt, 1, file_hash, -1, SQLITE_TRANSIENT);
    //     if (sqlite3_step(stmt) == SQLITE_ROW) {
    //       log_message(config, "DEBUG", "Book exists by hash: %s", file_hash);
    //       sqlite3_finalize(stmt);
    //       return 1;
    //    }
    //     sqlite3_finalize(stmt);
    //   }
    // }

    // #########################################################################

    // 2. Проверяем по пути (только если filepath не NULL)
    if (filepath && filepath[0] != '\0') {
      if (archive_path && archive_path[0] != '\0') {
        const char *sql = "SELECT id FROM books WHERE file_path = ? AND "
                          "archive_path = ? AND archive_internal_path = ?";
        if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
          sqlite3_bind_text(stmt, 1, filepath, -1, SQLITE_TRANSIENT);
          sqlite3_bind_text(stmt, 2, archive_path, -1, SQLITE_TRANSIENT);
          sqlite3_bind_text(stmt, 3, internal_path, -1, SQLITE_TRANSIENT);
          if (sqlite3_step(stmt) == SQLITE_ROW) {
            log_message(config, "DEBUG", "Book exists by path: %s", filepath);
            sqlite3_finalize(stmt);
            return 1;
          }
          sqlite3_finalize(stmt);
        }
      } else {
        const char *sql = "SELECT id FROM books WHERE file_path = ? AND "
                          "archive_path IS NULL";
        if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) == SQLITE_OK) {
          sqlite3_bind_text(stmt, 1, filepath, -1, SQLITE_TRANSIENT);
          if (sqlite3_step(stmt) == SQLITE_ROW) {
            log_message(config, "DEBUG", "Book exists by path: %s", filepath);
            sqlite3_finalize(stmt);
            return 1;
          }
          sqlite3_finalize(stmt);
        }
      }
    }

    return 0;
  }
  case DB_MYSQL:
    return mysql_book_exists((MySQLConnection *)db_handle->connection, filepath,
                             archive_path, internal_path, file_hash, config);
  default:
    log_message(config, "ERROR", "Unknown database type in book_exists: %d",
                db_handle->db_type);
    return 0;
  }
}

// ============================================================
// ВСТАВКА КНИГИ В БАЗУ ДАННЫХ С УМНОЙ ЛОГИКОЙ
// ============================================================
void insert_book_to_db(DatabaseHandle *db_handle, const char *filepath,
                       BookMeta *meta, const char *archive_path,
                       const char *internal_path, const char *file_hash,
                       Config *config) {
  if (!db_handle) {
    log_message(config, "ERROR", "[INSERT_BOOK_TO_DB] Database handle is NULL");
    return;
  }

  if (!db_handle->connection) {
    log_message(config, "ERROR",
                "[INSERT_BOOK_TO_DB] Database connection is NULL");
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

  log_message(config, "DEBUG", "[INSERT_BOOK_TO_DB] Processing: %s", filepath);

  // ============================================================
  // 1. ПРОВЕРКА: ЕСТЬ ЛИ ЗАПИСИ В ТАБЛИЦЕ?
  // ============================================================
  int has_records = 0;

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    sqlite3 *db = (sqlite3 *)db_handle->connection;
    sqlite3_stmt *stmt;
    if (sqlite3_prepare_v2(db, "SELECT 1 FROM books LIMIT 1", -1, &stmt,
                           NULL) == SQLITE_OK) {
      has_records = (sqlite3_step(stmt) == SQLITE_ROW);
      sqlite3_finalize(stmt);
    }
    break;
  }
  case DB_MYSQL: {
    MySQLConnection *mysql_conn = (MySQLConnection *)db_handle->connection;
    if (mysql_conn && mysql_conn->mysql) {
      if (mysql_query(mysql_conn->mysql, "SELECT 1 FROM books LIMIT 1") == 0) {
        MYSQL_RES *result = mysql_store_result(mysql_conn->mysql);
        if (result) {
          has_records = (mysql_num_rows(result) > 0);
          mysql_free_result(result);
        }
      }
    }
    break;
  }
  default:
    break;
  }

  log_message(config, "DEBUG", "[INSERT_BOOK_TO_DB] Table has records: %d",
              has_records);

  // ============================================================
  // 2. ПРОВЕРКА ДУБЛИКАТОВ (если включена И есть записи в таблице)
  // ============================================================
  if (config->scanner.find_dup != 0 && has_records) {

    log_message(config, "DEBUG", "[INSERT_BOOK_TO_DB] Checking for duplicates");

    // 2.1. ПРОВЕРКА ПО ХЕШУ
    // Нет смысла использовать поиск по хешу, у нас поле file_hash уникальное,
    // дубликатов быть не может
    //    if (file_hash && file_hash[0] != '\0') {
    //      if (book_exists_by_hash(db_handle, file_hash)) {
    //        log_message(config, "DEBUG",
    //                    "[INSERT_BOOK_TO_DB] Book exists by hash, skipping:
    //                    %s", filepath);
    //        return;
    //      }
    //    }

    // 2.2. ПОИСК ПО АВТОРУ И НАЗВАНИЮ
    if (meta->title && meta->title[0] != '\0' && meta->author &&
        meta->author[0] != '\0') {

      BookRecord *existing = find_book_by_title_author(db_handle, meta->title,
                                                       meta->author, config);

      if (existing) {
        log_message(config, "DEBUG",
                    "[INSERT_BOOK_TO_DB] Found existing book: ID=%d, size=%ld, "
                    "new size=%ld",
                    existing->id, existing->file_size, meta->file_size);

        // Если новая книга больше на 10% - обновляем
        if (meta->file_size > existing->file_size * 1.1) {
          log_message(config, "INFO",
                      "[INSERT_BOOK_TO_DB] New version is larger, updating: %s "
                      "- %s (old: %ld, new: %ld)",
                      meta->title, meta->author, existing->file_size,
                      meta->file_size);
          update_book_in_db(db_handle, existing->id, meta, filepath, file_hash,
                            config);
          free_book_record(existing);
          return;
        } else {
          log_message(config, "DEBUG",
                      "[INSERT_BOOK_TO_DB] Existing version is same or larger, "
                      "skipping");
          free_book_record(existing);
          return;
        }
      }
    }

    // 2.3. ПРОВЕРКА ПО ПУТИ
    if (book_exists(db_handle, filepath, archive_path, internal_path, NULL,
                    config)) {
      log_message(config, "DEBUG",
                  "[INSERT_BOOK_TO_DB] Book exists by path, skipping: %s",
                  filepath);
      return;
    }
  } else if (config->scanner.find_dup == 0) {
    log_message(config, "DEBUG",
                "[INSERT_BOOK_TO_DB] DUPLICATE CHECK DISABLED for: %s",
                filepath);
  }

  // ============================================================
  // 3. ВСТАВКА НОВОЙ КНИГИ (ЕДИНЫЙ БЛОК ДЛЯ ВСЕХ СЛУЧАЕВ)
  // ============================================================
  // Извлекаем имя файла
  const char *filename = "unknown";
  if (internal_path && internal_path[0] != '\0') {
    filename = internal_path;
  } else {
    const char *slash = strrchr(filepath, '/');
    filename = slash ? slash + 1 : filepath;
  }
  const char *file_type = normalize_file_type(filename);

  log_message(config, "INFO",
              "[INSERT_BOOK_TO_DB] Inserting book: %s - %s (size: %ld, "
              "table_empty: %d)",
              meta->title ? meta->title : "Unknown",
              meta->author ? meta->author : "Unknown", meta->file_size,
              !has_records);

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    sqlite3 *db = (sqlite3 *)db_handle->connection;

    const char *sql =
        "INSERT OR IGNORE INTO books ("
        "file_path, file_name, file_size, file_type, "
        "archive_path, archive_internal_path, file_hash, title, author, "
        "genre, series, series_number, year, language, publisher, description"
        ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    int rc;
    static sqlite3_stmt *stmt = NULL;

    if (stmt == NULL) {
      rc = sqlite3_prepare_v2(db, sql, -1, &stmt, NULL);
      if (rc != SQLITE_OK) {
        log_message(config, "ERROR",
                    "[INSERT_BOOK_TO_DB] Failed to prepare SQL: %s",
                    sqlite3_errmsg(db));
        return;
      }
    } else {
      // Очищаем состояние стейтмента перед новыми данными
      sqlite3_reset(stmt);
      sqlite3_clear_bindings(stmt);
    }

    int param = 1;

    // 1. file_path
    sqlite3_bind_text(stmt, param++, filepath, -1, SQLITE_STATIC);

    // 2. file_name
    sqlite3_bind_text(stmt, param++, filename, -1, SQLITE_STATIC);

    // 3. file_size
    sqlite3_bind_int64(stmt, param++,
                       meta->file_size > 0 ? meta->file_size : 0);

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

    // 7. file_hash
    if (file_hash && file_hash[0] != '\0') {
      sqlite3_bind_text(stmt, param++, file_hash, -1, SQLITE_STATIC);
    } else {
      sqlite3_bind_null(stmt, param++);
    }

    // 8. title
    sqlite3_bind_text(stmt, param++,
                      meta->title ? meta->title : "Unknown Title", -1,
                      SQLITE_STATIC);

    // 9. author
    sqlite3_bind_text(stmt, param++,
                      meta->author ? meta->author : "Unknown Author", -1,
                      SQLITE_STATIC);

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
    sqlite3_bind_int(stmt, param++,
                     meta->series_number > 0 ? meta->series_number : 0);

    // 13. year
    sqlite3_bind_int(stmt, param++, meta->year > 0 ? meta->year : 0);

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
      // Проверяем длину описания
      size_t desc_len = strlen(meta->description);
      if (desc_len > 65535) {
        log_message(config, "WARNING",
                    "[INSERT_BOOK_TO_DB] Description too long (%zu), "
                    "truncating to 65535",
                    desc_len);
        char *truncated = malloc(65536);
        if (truncated) {
          memcpy(truncated, meta->description, 65535);
          truncated[65535] = '\0';
          sqlite3_bind_text(stmt, param++, truncated, -1, SQLITE_TRANSIENT);
          free(truncated);
        } else {
          sqlite3_bind_null(stmt, param++);
        }
      } else {
        sqlite3_bind_text(stmt, param++, meta->description, -1, SQLITE_STATIC);
      }
    } else {
      sqlite3_bind_null(stmt, param++);
    }

    rc = sqlite3_step(stmt);
    if (rc != SQLITE_DONE) {
      log_message(config, "ERROR",
                  "[INSERT_BOOK_TO_DB] Insert failed: %s (rc=%d)",
                  sqlite3_errmsg(db), rc);
    } else {
      if (sqlite3_changes(db) > 0) {
        log_message(config, "INFO",
                    "[INSERT_BOOK_TO_DB] Book inserted successfully: %s - %s "
                    "(type: %s)",
                    meta->title ? meta->title : "Unknown",
                    meta->author ? meta->author : "Unknown", file_type);
      } else {
        log_message(
            config, "DEBUG",
            "[INSERT_BOOK_TO_DB] Book skipped (already exists): %s - %s",
            meta->title ? meta->title : "Unknown",
            meta->author ? meta->author : "Unknown");
      }
    }

    sqlite3_reset(stmt);
    break;
  }

  case DB_MYSQL: {
    MySQLConnection *mysql_conn = (MySQLConnection *)db_handle->connection;
    if (!mysql_conn || !mysql_conn->mysql) {
      log_message(config, "ERROR",
                  "[INSERT_BOOK_TO_DB] MySQL connection is invalid");
      return;
    }
    mysql_insert_book(mysql_conn, filepath, meta, archive_path, internal_path,
                      file_hash, config);
    break;
  }

  default:
    log_message(config, "ERROR",
                "[INSERT_BOOK_TO_DB] Unknown database type: %d",
                db_handle->db_type);
    break;
  }
}

int db_begin_transaction(DatabaseHandle *db_handle, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR", "No database connection");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    sqlite3 *db = (sqlite3 *)db_handle->connection;

    char *err_msg = NULL;
    int rc = sqlite3_exec(db, "BEGIN TRANSACTION;", NULL, NULL, &err_msg);
    if (rc != SQLITE_OK) {
      log_message(config, "ERROR", "Failed to begin transaction: %s", err_msg);
      sqlite3_free(err_msg);
      return 0;
    }
    log_message(config, "DEBUG", "Transaction started");
    return 1;
  }
  case DB_MYSQL:
    return mysql_begin_transaction((MySQLConnection *)db_handle->connection,
                                   config);
  default:
    log_message(config, "ERROR", "Unknown database type");
    return 0;
  }
}

int db_commit_transaction(DatabaseHandle *db_handle, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR", "No database connection");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    sqlite3 *db = (sqlite3 *)db_handle->connection;
    char *err_msg = NULL;

    int rc = sqlite3_exec(db, "COMMIT;", NULL, NULL, &err_msg);
    if (rc != SQLITE_OK) {
      log_message(config, "ERROR", "Failed to commit transaction: %s", err_msg);
      sqlite3_free(err_msg);
      return 0;
    }
    log_message(config, "DEBUG", "Transaction committed");
    return 1;
  }
  case DB_MYSQL:
    return mysql_commit_transaction((MySQLConnection *)db_handle->connection,
                                    config);
  default:
    log_message(config, "ERROR", "Unknown database type");
    return 0;
  }
}

int db_rollback_transaction(DatabaseHandle *db_handle, Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR", "No database connection");
    return 0;
  }

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    sqlite3 *db = (sqlite3 *)db_handle->connection;
    char *err_msg = NULL;
    int rc = sqlite3_exec(db, "ROLLBACK;", NULL, NULL, &err_msg);
    if (rc != SQLITE_OK) {
      log_message(config, "ERROR", "Failed to rollback transaction: %s",
                  err_msg);
      sqlite3_free(err_msg);
      return 0;
    }
    log_message(config, "DEBUG", "Transaction rolled back");
    return 1;
  }
  case DB_MYSQL:
    return mysql_rollback_transaction((MySQLConnection *)db_handle->connection,
                                      config);
  default:
    log_message(config, "ERROR", "Unknown database type");
    return 0;
  }
}

// ============================================================
// ПОИСК КНИГИ ПО АВТОРУ И НАЗВАНИЮ
// ============================================================
BookRecord *find_book_by_title_author(DatabaseHandle *db_handle,
                                      const char *title, const char *author,
                                      Config *config) {
  if (!db_handle) {
    log_message(config, "ERROR", "[FIND_BOOK] Database handle is NULL");
    return NULL;
  }

  if (!db_handle->connection) {
    log_message(config, "ERROR", "[FIND_BOOK] Database connection is NULL");
    return NULL;
  }

  if (!title || !author) {
    log_message(config, "DEBUG",
                "[FIND_BOOK] Title or author is NULL, skipping search");
    return NULL;
  }

  if (title[0] == '\0' || author[0] == '\0') {
    log_message(config, "DEBUG",
                "[FIND_BOOK] Title or author is empty, skipping search");
    return NULL;
  }

  log_message(config, "DEBUG", "[FIND_BOOK] Searching for: '%s' by '%s'", title,
              author);

  BookRecord *record = NULL;

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    sqlite3 *db = (sqlite3 *)db_handle->connection;

    // Используем статический stmt для производительности
    static sqlite3_stmt *stmt = NULL;
    if (stmt == NULL) {
      const char *sql = "SELECT id, file_size FROM books WHERE title = ? AND "
                        "author = ? LIMIT 1;";
      if (sqlite3_prepare_v2(db, sql, -1, &stmt, NULL) != SQLITE_OK) {
        log_message(config, "ERROR",
                    "[FIND_BOOK] Failed to prepare statement: %s",
                    sqlite3_errmsg(db));
        return NULL;
      }
    } else {
      sqlite3_reset(stmt);
      sqlite3_clear_bindings(stmt);
    }

    sqlite3_bind_text(stmt, 1, title, -1, SQLITE_STATIC);
    sqlite3_bind_text(stmt, 2, author, -1, SQLITE_STATIC);

    // ✅ ИСПРАВЛЕНО: используем внешнюю переменную record
    if (sqlite3_step(stmt) == SQLITE_ROW) {
      record = calloc(1, sizeof(BookRecord));
      if (record) {
        record->id = sqlite3_column_int(stmt, 0);
        record->file_size = sqlite3_column_int64(stmt, 1);
        record->file_path = NULL;
        record->file_hash = NULL;
        log_message(config, "DEBUG", "[FIND_BOOK] Found book: ID=%d, size=%ld",
                    record->id, record->file_size);
      } else {
        log_message(config, "ERROR",
                    "[FIND_BOOK] Failed to allocate BookRecord");
      }
    } else {
      log_message(config, "DEBUG", "[FIND_BOOK] Book not found: '%s' by '%s'",
                  title, author);
    }

    sqlite3_reset(stmt);
    break;
  }

  case DB_MYSQL: {
    MySQLConnection *mysql_conn = (MySQLConnection *)db_handle->connection;
    if (!mysql_conn || !mysql_conn->mysql) {
      log_message(config, "ERROR", "[FIND_BOOK] MySQL connection is invalid");
      return NULL;
    }

    // Проверяем соединение
    if (mysql_ping(mysql_conn->mysql) != 0) {
      log_message(config, "WARNING",
                  "[FIND_BOOK] MySQL connection lost, reconnecting...");
      if (!mysql_reconnect(mysql_conn, config)) {
        log_message(config, "ERROR", "[FIND_BOOK] Reconnection failed");
        return NULL;
      }
    }

    // Используем подготовленный запрос (безопаснее и быстрее)
    const char *sql = "SELECT id, file_size, file_path, file_hash FROM books "
                      "WHERE title = ? AND author = ? LIMIT 1";

    MYSQL_STMT *stmt = mysql_stmt_init(mysql_conn->mysql);
    if (!stmt) {
      log_message(config, "ERROR", "[FIND_BOOK] mysql_stmt_init failed");
      return NULL;
    }

    if (mysql_stmt_prepare(stmt, sql, strlen(sql))) {
      log_message(config, "ERROR", "[FIND_BOOK] mysql_stmt_prepare failed: %s",
                  mysql_stmt_error(stmt));
      mysql_stmt_close(stmt);
      return NULL;
    }

    // Биндим параметры
    MYSQL_BIND bind[2];
    unsigned long lengths[2];
    bool is_null[2] = {false, false};
    memset(bind, 0, sizeof(bind));

    lengths[0] = strlen(title);
    bind[0].buffer_type = MYSQL_TYPE_STRING;
    bind[0].buffer = (char *)title;
    bind[0].buffer_length = lengths[0];
    bind[0].length = &lengths[0];
    bind[0].is_null = &is_null[0];

    lengths[1] = strlen(author);
    bind[1].buffer_type = MYSQL_TYPE_STRING;
    bind[1].buffer = (char *)author;
    bind[1].buffer_length = lengths[1];
    bind[1].length = &lengths[1];
    bind[1].is_null = &is_null[1];

    if (mysql_stmt_bind_param(stmt, bind)) {
      log_message(config, "ERROR",
                  "[FIND_BOOK] mysql_stmt_bind_param failed: %s",
                  mysql_stmt_error(stmt));
      mysql_stmt_close(stmt);
      return NULL;
    }

    if (mysql_stmt_execute(stmt)) {
      log_message(config, "ERROR", "[FIND_BOOK] mysql_stmt_execute failed: %s",
                  mysql_stmt_error(stmt));
      mysql_stmt_close(stmt);
      return NULL;
    }

    // Получаем результат
    MYSQL_RES *result = mysql_stmt_result_metadata(stmt);
    if (!result) {
      log_message(config, "DEBUG", "[FIND_BOOK] No result metadata");
      mysql_stmt_close(stmt);
      return NULL;
    }

    MYSQL_BIND result_bind[4];
    unsigned long result_lengths[4];
    bool result_is_null[4];
    memset(result_bind, 0, sizeof(result_bind));

    int id = 0;
    long file_size = 0;
    char file_path[PATH_MAX] = {0};
    char file_hash[128] = {0};

    result_bind[0].buffer_type = MYSQL_TYPE_LONG;
    result_bind[0].buffer = &id;
    result_bind[0].is_null = &result_is_null[0];
    result_bind[0].length = &result_lengths[0];

    result_bind[1].buffer_type = MYSQL_TYPE_LONG;
    result_bind[1].buffer = &file_size;
    result_bind[1].is_null = &result_is_null[1];
    result_bind[1].length = &result_lengths[1];

    result_bind[2].buffer_type = MYSQL_TYPE_STRING;
    result_bind[2].buffer = file_path;
    result_bind[2].buffer_length = sizeof(file_path);
    result_bind[2].is_null = &result_is_null[2];
    result_bind[2].length = &result_lengths[2];

    result_bind[3].buffer_type = MYSQL_TYPE_STRING;
    result_bind[3].buffer = file_hash;
    result_bind[3].buffer_length = sizeof(file_hash);
    result_bind[3].is_null = &result_is_null[3];
    result_bind[3].length = &result_lengths[3];

    if (mysql_stmt_bind_result(stmt, result_bind)) {
      log_message(config, "ERROR",
                  "[FIND_BOOK] mysql_stmt_bind_result failed: %s",
                  mysql_stmt_error(stmt));
      mysql_free_result(result);
      mysql_stmt_close(stmt);
      return NULL;
    }

    if (mysql_stmt_fetch(stmt) == 0) {
      record = calloc(1, sizeof(BookRecord));
      if (record) {
        record->id = id;
        record->file_size = file_size;
        record->file_path = result_is_null[2] ? NULL : strdup(file_path);
        record->file_hash = result_is_null[3] ? NULL : strdup(file_hash);
        log_message(config, "DEBUG",
                    "[FIND_BOOK] Found book: ID=%d, size=%ld, path=%s",
                    record->id, record->file_size,
                    record->file_path ? record->file_path : "NULL");
      } else {
        log_message(config, "ERROR",
                    "[FIND_BOOK] Failed to allocate BookRecord");
      }
    } else {
      log_message(config, "DEBUG", "[FIND_BOOK] Book not found: '%s' by '%s'",
                  title, author);
    }

    mysql_free_result(result);
    mysql_stmt_close(stmt);
    break;
  }

  default:
    log_message(config, "ERROR", "[FIND_BOOK] Unknown database type: %d",
                db_handle->db_type);
    return NULL;
  }

  return record;
}

// ============================================================
// ОСВОБОЖДЕНИЕ ПАМЯТИ BookRecord
// ============================================================
void free_book_record(BookRecord *record) {
  if (!record) {
    return;
  }

  if (record->file_path) {
    free(record->file_path);
    record->file_path = NULL;
  }

  if (record->file_hash) {
    free(record->file_hash);
    record->file_hash = NULL;
  }

  free(record);
}

// ============================================================
// ОБНОВЛЕНИЕ КНИГИ В БАЗЕ ДАННЫХ
// ============================================================
void update_book_in_db(DatabaseHandle *db_handle, int book_id, BookMeta *meta,
                       const char *filepath, const char *file_hash,
                       Config *config) {
  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR",
                "[UPDATE_BOOK] Database handle or connection is NULL");
    return;
  }

  if (book_id <= 0) {
    log_message(config, "ERROR", "[UPDATE_BOOK] Invalid book_id: %d", book_id);
    return;
  }

  if (!meta) {
    log_message(config, "ERROR", "[UPDATE_BOOK] BookMeta is NULL");
    return;
  }

  log_message(config, "INFO",
              "[UPDATE_BOOK] Updating book ID %d: %s - %s (new size: %ld)",
              book_id, meta->title ? meta->title : "Unknown",
              meta->author ? meta->author : "Unknown", meta->file_size);

  switch (db_handle->db_type) {
  case DB_SQLITE: {
    sqlite3 *db = (sqlite3 *)db_handle->connection;
    const char *sql =
        "UPDATE books SET "
        "file_path = ?, file_hash = ?, file_size = ?, "
        "title = ?, author = ?, genre = ?, series = ?, "
        "series_number = ?, year = ?, language = ?, "
        "publisher = ?, description = ?, last_modified = CURRENT_TIMESTAMP "
        "WHERE id = ?";

    sqlite3_stmt *stmt;
    int rc = sqlite3_prepare_v2(db, sql, -1, &stmt, NULL);
    if (rc != SQLITE_OK) {
      log_message(config, "ERROR", "[UPDATE_BOOK] SQLite prepare failed: %s",
                  sqlite3_errmsg(db));
      return;
    }

    int param = 1;

    // file_path
    sqlite3_bind_text(stmt, param++, filepath ? filepath : "", -1,
                      SQLITE_STATIC);

    // file_hash
    sqlite3_bind_text(stmt, param++, file_hash ? file_hash : "", -1,
                      SQLITE_STATIC);

    // file_size
    sqlite3_bind_int64(stmt, param++,
                       meta->file_size > 0 ? meta->file_size : 0);

    // title
    sqlite3_bind_text(stmt, param++,
                      meta->title ? meta->title : "Unknown Title", -1,
                      SQLITE_STATIC);

    // author
    sqlite3_bind_text(stmt, param++,
                      meta->author ? meta->author : "Unknown Author", -1,
                      SQLITE_STATIC);

    // genre
    if (meta->genre && meta->genre[0] != '\0') {
      sqlite3_bind_text(stmt, param++, meta->genre, -1, SQLITE_STATIC);
    } else {
      sqlite3_bind_null(stmt, param++);
    }

    // series
    if (meta->series && meta->series[0] != '\0') {
      sqlite3_bind_text(stmt, param++, meta->series, -1, SQLITE_STATIC);
    } else {
      sqlite3_bind_null(stmt, param++);
    }

    // series_number
    if (meta->series_number > 0) {
      sqlite3_bind_int(stmt, param++, meta->series_number);
    } else {
      sqlite3_bind_null(stmt, param++);
    }

    // year
    if (meta->year > 0) {
      sqlite3_bind_int(stmt, param++, meta->year);
    } else {
      sqlite3_bind_null(stmt, param++);
    }

    // language
    if (meta->language && meta->language[0] != '\0') {
      sqlite3_bind_text(stmt, param++, meta->language, -1, SQLITE_STATIC);
    } else {
      sqlite3_bind_null(stmt, param++);
    }

    // publisher
    if (meta->publisher && meta->publisher[0] != '\0') {
      sqlite3_bind_text(stmt, param++, meta->publisher, -1, SQLITE_STATIC);
    } else {
      sqlite3_bind_null(stmt, param++);
    }

    // description
    if (meta->description && meta->description[0] != '\0') {
      // Проверяем UTF-8
      if (!is_valid_utf8_string(meta->description)) {
        log_message(config, "WARNING",
                    "[UPDATE_BOOK] Invalid UTF-8 in description, sanitizing");
        char *cleaned = sanitize_utf8_string(meta->description);
        if (cleaned) {
          size_t desc_len = strlen(cleaned);
          if (desc_len > 65535) {
            desc_len = 65535;
            char *truncated = malloc(desc_len + 1);
            if (truncated) {
              memcpy(truncated, cleaned, desc_len);
              truncated[desc_len] = '\0';
              free(cleaned);
              cleaned = truncated;
            } else {
              sqlite3_bind_null(stmt, param++);
              free(cleaned);
              goto sqlite_update_skip_desc;
            }
          }
          sqlite3_bind_text(stmt, param++, cleaned, -1, SQLITE_TRANSIENT);
          free(cleaned);
        } else {
          sqlite3_bind_null(stmt, param++);
        }
      } else {
        size_t desc_len = strlen(meta->description);
        if (desc_len > 65535) {
          desc_len = 65535;
          char *truncated = malloc(desc_len + 1);
          if (truncated) {
            memcpy(truncated, meta->description, desc_len);
            truncated[desc_len] = '\0';
            sqlite3_bind_text(stmt, param++, truncated, -1, SQLITE_TRANSIENT);
            free(truncated);
          } else {
            sqlite3_bind_null(stmt, param++);
          }
        } else {
          sqlite3_bind_text(stmt, param++, meta->description, -1,
                            SQLITE_STATIC);
        }
      }
    } else {
      sqlite3_bind_null(stmt, param++);
    }
  sqlite_update_skip_desc:

    // id
    sqlite3_bind_int(stmt, param++, book_id);

    rc = sqlite3_step(stmt);
    if (rc != SQLITE_DONE) {
      log_message(config, "ERROR",
                  "[UPDATE_BOOK] Failed to update book: %s (error code: %d)",
                  sqlite3_errmsg(db), rc);
    } else {
      log_message(config, "INFO",
                  "[UPDATE_BOOK] Book updated successfully: %s - %s",
                  meta->title ? meta->title : "Unknown",
                  meta->author ? meta->author : "Unknown");
    }

    sqlite3_finalize(stmt);
    break;
  }

  case DB_MYSQL: {
    MySQLConnection *mysql_conn = (MySQLConnection *)db_handle->connection;
    if (!mysql_conn || !mysql_conn->mysql) {
      log_message(config, "ERROR", "[UPDATE_BOOK] MySQL connection is invalid");
      return;
    }

    // Проверяем соединение
    if (mysql_ping(mysql_conn->mysql) != 0) {
      log_message(config, "WARNING",
                  "[UPDATE_BOOK] MySQL connection lost, reconnecting...");
      if (!mysql_reconnect(mysql_conn, config)) {
        log_message(config, "ERROR", "[UPDATE_BOOK] Reconnection failed");
        return;
      }
    }

    // Используем подготовленный запрос вместо ручного экранирования
    const char *sql = "UPDATE books SET "
                      "file_path = ?, file_hash = ?, file_size = ?, "
                      "title = ?, author = ?, genre = ?, series = ?, "
                      "series_number = ?, year = ?, language = ?, "
                      "publisher = ?, description = ?, last_modified = NOW() "
                      "WHERE id = ?";

    MYSQL_STMT *stmt = mysql_stmt_init(mysql_conn->mysql);
    if (!stmt) {
      log_message(config, "ERROR", "[UPDATE_BOOK] mysql_stmt_init failed");
      return;
    }

    if (mysql_stmt_prepare(stmt, sql, strlen(sql))) {
      log_message(config, "ERROR",
                  "[UPDATE_BOOK] mysql_stmt_prepare failed: %s",
                  mysql_stmt_error(stmt));
      mysql_stmt_close(stmt);
      return;
    }

    // Подготовка параметров
    MYSQL_BIND bind[13];
    unsigned long lengths[13];
    bool is_null[13] = {false};
    bool false_val = false;

    memset(bind, 0, sizeof(bind));
    memset(lengths, 0, sizeof(lengths));

    int param = 0;

    // 1. file_path
    lengths[param] = strlen(filepath);
    bind[param].buffer_type = MYSQL_TYPE_STRING;
    bind[param].buffer = (char *)filepath;
    bind[param].buffer_length = lengths[param];
    bind[param].length = &lengths[param];
    bind[param].is_null = &false_val;
    param++;

    // 2. file_hash
    const char *hash = file_hash ? file_hash : "";
    lengths[param] = strlen(hash);
    bind[param].buffer_type = MYSQL_TYPE_STRING;
    bind[param].buffer = (char *)hash;
    bind[param].buffer_length = lengths[param];
    bind[param].length = &lengths[param];
    bind[param].is_null = &false_val;
    param++;

    // 3. file_size
    long long file_size_val = meta->file_size > 0 ? meta->file_size : 0;
    bind[param].buffer_type = MYSQL_TYPE_LONGLONG;
    bind[param].buffer = &file_size_val;
    bind[param].is_null = &false_val;
    param++;

    // 4. title
    const char *title = meta->title ? meta->title : "Unknown Title";
    lengths[param] = strlen(title);
    bind[param].buffer_type = MYSQL_TYPE_STRING;
    bind[param].buffer = (char *)title;
    bind[param].buffer_length = lengths[param];
    bind[param].length = &lengths[param];
    bind[param].is_null = &false_val;
    param++;

    // 5. author
    const char *author = meta->author ? meta->author : "Unknown Author";
    lengths[param] = strlen(author);
    bind[param].buffer_type = MYSQL_TYPE_STRING;
    bind[param].buffer = (char *)author;
    bind[param].buffer_length = lengths[param];
    bind[param].length = &lengths[param];
    bind[param].is_null = &false_val;
    param++;

    // 6. genre
    if (meta->genre && meta->genre[0] != '\0') {
      lengths[param] = strlen(meta->genre);
      bind[param].buffer_type = MYSQL_TYPE_STRING;
      bind[param].buffer = meta->genre;
      bind[param].buffer_length = lengths[param];
      bind[param].length = &lengths[param];
      is_null[param] = 0;
    } else {
      is_null[param] = 1;
    }
    bind[param].is_null = &is_null[param];
    param++;

    // 7. series
    if (meta->series && meta->series[0] != '\0') {
      lengths[param] = strlen(meta->series);
      bind[param].buffer_type = MYSQL_TYPE_STRING;
      bind[param].buffer = meta->series;
      bind[param].buffer_length = lengths[param];
      bind[param].length = &lengths[param];
      is_null[param] = 0;
    } else {
      is_null[param] = 1;
    }
    bind[param].is_null = &is_null[param];
    param++;

    // 8. series_number
    int series_num = meta->series_number > 0 ? meta->series_number : 0;
    bind[param].buffer_type = MYSQL_TYPE_LONG;
    bind[param].buffer = &series_num;
    is_null[param] = (meta->series_number <= 0) ? 1 : 0;
    bind[param].is_null = &is_null[param];
    param++;

    // 9. year
    int year_val = meta->year > 0 ? meta->year : 0;
    bind[param].buffer_type = MYSQL_TYPE_LONG;
    bind[param].buffer = &year_val;
    is_null[param] = (meta->year <= 0) ? 1 : 0;
    bind[param].is_null = &is_null[param];
    param++;

    // 10. language
    if (meta->language && meta->language[0] != '\0') {
      lengths[param] = strlen(meta->language);
      bind[param].buffer_type = MYSQL_TYPE_STRING;
      bind[param].buffer = meta->language;
      bind[param].buffer_length = lengths[param];
      bind[param].length = &lengths[param];
      is_null[param] = 0;
    } else {
      is_null[param] = 1;
    }
    bind[param].is_null = &is_null[param];
    param++;

    // 11. publisher
    if (meta->publisher && meta->publisher[0] != '\0') {
      lengths[param] = strlen(meta->publisher);
      bind[param].buffer_type = MYSQL_TYPE_STRING;
      bind[param].buffer = meta->publisher;
      bind[param].buffer_length = lengths[param];
      bind[param].length = &lengths[param];
      is_null[param] = 0;
    } else {
      is_null[param] = 1;
    }
    bind[param].is_null = &is_null[param];
    param++;

    // 12. description
    if (meta->description && meta->description[0] != '\0') {
      if (!is_valid_utf8_string(meta->description)) {
        log_message(config, "WARNING",
                    "[UPDATE_BOOK] Invalid UTF-8 in description, sanitizing");
        char *cleaned = sanitize_utf8_string(meta->description);
        if (cleaned) {
          lengths[param] = strlen(cleaned);
          bind[param].buffer_type = MYSQL_TYPE_STRING;
          bind[param].buffer = cleaned;
          bind[param].buffer_length = lengths[param];
          bind[param].length = &lengths[param];
          is_null[param] = 0;
          bind[param].is_null = &is_null[param];
          // ВНИМАНИЕ: cleaned будет освобождён после выполнения
          // Используем STATIC, чтобы не освобождать сразу
          param++;
        } else {
          is_null[param] = 1;
          bind[param].is_null = &is_null[param];
          param++;
        }
      } else {
        lengths[param] = strlen(meta->description);
        bind[param].buffer_type = MYSQL_TYPE_STRING;
        bind[param].buffer = meta->description;
        bind[param].buffer_length = lengths[param];
        bind[param].length = &lengths[param];
        is_null[param] = 0;
        bind[param].is_null = &is_null[param];
        param++;
      }
    } else {
      is_null[param] = 1;
      bind[param].is_null = &is_null[param];
      param++;
    }

    // 13. id
    bind[param].buffer_type = MYSQL_TYPE_LONG;
    bind[param].buffer = &book_id;
    bind[param].is_null = &false_val;
    param++;

    if (mysql_stmt_bind_param(stmt, bind)) {
      log_message(config, "ERROR",
                  "[UPDATE_BOOK] mysql_stmt_bind_param failed: %s",
                  mysql_stmt_error(stmt));
      mysql_stmt_close(stmt);
      return;
    }

    if (mysql_stmt_execute(stmt)) {
      log_message(config, "ERROR",
                  "[UPDATE_BOOK] mysql_stmt_execute failed: %s",
                  mysql_stmt_error(stmt));
    } else {
      my_ulonglong affected = mysql_stmt_affected_rows(stmt);
      if (affected > 0) {
        log_message(config, "INFO",
                    "[UPDATE_BOOK] Book updated successfully: %s - %s",
                    meta->title ? meta->title : "Unknown",
                    meta->author ? meta->author : "Unknown");
      } else {
        log_message(config, "WARNING",
                    "[UPDATE_BOOK] No rows updated (book may not exist): ID=%d",
                    book_id);
      }
    }

    mysql_stmt_close(stmt);
    break;
  }

  default:
    log_message(config, "ERROR", "[UPDATE_BOOK] Unknown database type: %d",
                db_handle->db_type);
    break;
  }
}
