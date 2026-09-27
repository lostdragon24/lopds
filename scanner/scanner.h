// scanner.h

#ifndef SCANNER_H
#define SCANNER_H

#include "config.h"
#include "database.h"
#include <archive.h>
#include <archive_entry.h>
#include <pthread.h>

#define SUPPORTED_FORMATS 7
#define MAX_QUEUE_SIZE 2000
#define NUM_THREADS 4 // Можно сделать конфигурируемым

extern const char *supported_formats[SUPPORTED_FORMATS];

// ============================================================
// СТРУКТУРА ЗАДАЧИ ДЛЯ ОЧЕРЕДИ
// ============================================================
typedef struct {
  char *filepath;
  char *archive_path;  // Если книга внутри архива
  char *internal_path; // Путь внутри архива
  int is_archive;      // 1 если это архив, 0 если обычный файл
  long file_size;
  time_t mtime;
} FileTask;

// ============================================================
// ПОТОКОБЕЗОПАСНАЯ ОЧЕРЕДЬ ЗАДАЧ
// ============================================================
typedef struct {
  FileTask *tasks[MAX_QUEUE_SIZE];
  int head;
  int tail;
  int count;
  int shutdown;
  int total_processed;
  pthread_mutex_t mutex;
  pthread_cond_t not_empty;
  pthread_cond_t not_full;
} TaskQueue;

// ============================================================
// СТРУКТУРА ДЛЯ РЕЗУЛЬТАТОВ РАБОТЫ ВОРКЕРА
// ============================================================

typedef struct {
  char *archive_path;
  char *archive_hash;
  int file_count;
  long total_size;
  int needs_update; // 1 если нужно обновить информацию об архиве
} ArchiveInfo;

typedef struct {
  BookMeta *meta;
  char *filepath;
  char *archive_path;
  char *internal_path;
  char *file_hash;
  int is_archive;
  int success;
  ArchiveInfo *archive_info;
} BookResult;

// ============================================================
// ОЧЕРЕДЬ ДЛЯ РЕЗУЛЬТАТОВ (для потока БД)
// ============================================================
typedef struct {
  BookResult *results[MAX_QUEUE_SIZE];
  int head;
  int tail;
  int count;
  int shutdown;
  pthread_mutex_t mutex;
  pthread_cond_t not_empty;
} ResultQueue;

// ============================================================
// ФУНКЦИИ ДЛЯ РАБОТЫ С ОЧЕРЕДЯМИ
// ============================================================
void task_queue_init(TaskQueue *q);
void task_queue_push(TaskQueue *q, FileTask *task);
FileTask *task_queue_pop(TaskQueue *q);
void task_queue_shutdown(TaskQueue *q);

void result_queue_init(ResultQueue *q);
void result_queue_push(ResultQueue *q, BookResult *result);
BookResult *result_queue_pop(ResultQueue *q);
void result_queue_shutdown(ResultQueue *q);

typedef struct {
  TaskQueue *task_queue;
  ResultQueue *result_queue;
  Config *config;
  DatabaseHandle *db_handle;
  int batch_size;
  // Убираем db_handle_read
} WorkerContext;

// ============================================================
// ФУНКЦИИ ВОРКЕРОВ
// ============================================================
void *file_worker_thread(void *arg);
int process_archive_multithreaded(const char *archive_path,
                                  DatabaseHandle *db_handle, Config *config,
                                  ResultQueue *result_queue);

void *db_worker_thread(void *arg);

// ============================================================
// ОСНОВНЫЕ ФУНКЦИИ СКАНИРОВАНИЯ
// ============================================================
void scan_directory_multithreaded(const char *path, DatabaseHandle *db_handle,
                                  Config *config, int num_workers,
                                  int batch_size);

// Старые функции остаются для совместимости
void scan_directory(const char *path, DatabaseHandle *db_handle, Config *config,
                    int in_transaction);
void process_single_file(const char *filepath, struct stat *statbuf,
                         Config *config);

void process_archive(const char *archive_path, DatabaseHandle *db_handle,
                     Config *config, int in_transaction);

// Вспомогательные
int is_supported_format(const char *filename);
int is_archive_format(const char *filename);
int process_small_archive_file(struct archive *a, struct archive_entry *entry,
                               const char *archive_path, const char *filename,
                               DatabaseHandle *db_handle, Config *config);
int process_large_archive_file(struct archive *a, struct archive_entry *entry,
                               const char *archive_path, const char *filename,
                               DatabaseHandle *db_handle, Config *config);
char *calculate_fast_file_hash(const char *filepath, const char *algorithm);

#endif // SCANNER_H
