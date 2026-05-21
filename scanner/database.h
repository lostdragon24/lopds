#ifndef DATABASE_H
#define DATABASE_H

#include "book_meta.h"
#include "config.h"
#include <sqlite3.h>

#define DB_SQLITE 0
#define DB_MYSQL 1
#define DB_POSTGRESQL 2

typedef struct DatabaseHandle {
    void* connection;
    int db_type;
} DatabaseHandle;

// Основные функции базы данных
DatabaseHandle* db_connect(Config* config);
void db_close(DatabaseHandle* db_handle);
int create_database_tables(DatabaseHandle* db_handle, Config* config);
int create_archive_table(DatabaseHandle* db_handle, Config* config);
int create_ratings_table(DatabaseHandle* db_handle, Config* config);
int create_favorites_table(DatabaseHandle* db_handle, Config* config);

int db_execute(DatabaseHandle* db_handle, const char* sql, Config* config);

// Функции для работы с архивами
int archive_needs_rescan(DatabaseHandle* db_handle, const char* archive_path,
    const char* current_hash, Config* config);
void update_archive_info(DatabaseHandle* db_handle, const char* archive_path,
    const char* hash, int file_count, long total_size,
    Config* config);

// Функции для работы с книгами
int book_exists(DatabaseHandle* db_handle, const char* filepath,
    const char* archive_path, const char* internal_path,
    const char* file_hash, Config* config);
void insert_book_to_db(DatabaseHandle* db_handle, const char* filepath,
    BookMeta* meta, const char* archive_path,
    const char* internal_path, const char* file_hash, Config* config);
#endif // DATABASE_H
