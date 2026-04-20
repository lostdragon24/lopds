#ifndef SCANNER_H
#define SCANNER_H

#include "config.h"
#include "database.h"
#include <archive.h>
#include <archive_entry.h>

#define SUPPORTED_FORMATS 8

extern const char *supported_formats[SUPPORTED_FORMATS];

// Основные функции сканирования
void scan_directory(const char *path, DatabaseHandle *db_handle,
                    Config *config);
void process_file(const char *filepath, DatabaseHandle *db_handle,
                  Config *config);
void process_archive(const char *archive_path, DatabaseHandle *db_handle,
                     Config *config);

// Функции для проверки форматов
int is_supported_format(const char *filename);
int is_archive_format(const char *filename);

// Новые функции для обработки больших архивов (возвращают int для индикации
// успеха)
int process_small_archive_file(struct archive *a, struct archive_entry *entry,
                               const char *archive_path, const char *filename,
                               DatabaseHandle *db_handle, Config *config);
int process_large_archive_file(struct archive *a, struct archive_entry *entry,
                               const char *archive_path, const char *filename,
                               DatabaseHandle *db_handle, Config *config);
char *calculate_fast_file_hash(const char *filepath, const char *algorithm);

#endif
