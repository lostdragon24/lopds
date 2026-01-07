#ifndef DATABASE_MYSQL_H
#define DATABASE_MYSQL_H

#include "config.h"
#include "database.h"
#include <mysql/mysql.h>
#include <stdbool.h>

// Структура для MySQL соединения
typedef struct {
    MYSQL *mysql;
    MYSQL_STMT *stmt;
} MySQLConnection;

// Основные функции подключения
MySQLConnection* mysql_conn_connect(Config *config);
void mysql_conn_close(MySQLConnection *mysql_conn);
int mysql_execute_query(MySQLConnection *mysql_conn, const char *sql, Config *config);

// Функции для работы с таблицами
int mysql_create_tables(MySQLConnection *mysql_conn, Config *config);
int mysql_create_archive_table(MySQLConnection *mysql_conn, Config *config);

// Функции для работы с архивами
int mysql_archive_needs_rescan(MySQLConnection *mysql_conn, const char *archive_path,
                              const char *current_hash, Config *config);
void mysql_update_archive_info(MySQLConnection *mysql_conn, const char *archive_path,
                              const char *hash, int file_count, long total_size,
                              Config *config);

// Функции для работы с книгами
int mysql_book_exists(MySQLConnection *mysql_conn, const char *filepath,
                     const char *archive_path, const char *internal_path,
                     const char *file_hash, Config *config);
void mysql_insert_book(MySQLConnection *mysql_conn, const char *filepath,
                      BookMeta *meta, const char *archive_path,
                      const char *internal_path, Config *config);

// Функции для проверки существования книг
int check_book_exists(MySQLConnection *mysql_conn, const char *filepath,
                     BookMeta *meta, const char *archive_path,
                     const char *internal_path, Config *config);
int check_book_exists_smart(MySQLConnection *mysql_conn, BookMeta *meta,
                           Config *config);

// Функция переподключения
int mysql_reconnect(MySQLConnection *mysql_conn, Config *config);

#endif // DATABASE_MYSQL_H
