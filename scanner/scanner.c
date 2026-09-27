#include "scanner.h"
#include "common.h"
#include "database_mysql.h"
#include "metadata.h"
#include "path_validation.h"
#include "utils.h"
#include <archive.h>
#include <archive_entry.h>
#include <dirent.h>
#include <fcntl.h>
#include <iconv.h>
#include <limits.h>
#include <locale.h>
#include <openssl/evp.h>
#include <pthread.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

#ifdef __linux__
#include <malloc.h>
#endif

// ============================================================
// ИНИЦИАЛИЗАЦИЯ ОЧЕРЕДИ ЗАДАЧ
// ============================================================
void task_queue_init(TaskQueue *q) {
  q->head = 0;
  q->tail = 0;
  q->count = 0;
  q->shutdown = 0;
  q->total_processed = 0;
  pthread_mutex_init(&q->mutex, NULL);
  pthread_cond_init(&q->not_empty, NULL);
  pthread_cond_init(&q->not_full, NULL);
}

// ============================================================
// ДОБАВЛЕНИЕ ЗАДАЧИ В ОЧЕРЕДЬ
// ============================================================
void task_queue_push(TaskQueue *q, FileTask *task) {
  pthread_mutex_lock(&q->mutex);

  while (q->count == MAX_QUEUE_SIZE && !q->shutdown) {
    pthread_cond_wait(&q->not_full, &q->mutex);
  }

  if (q->shutdown) {
    pthread_mutex_unlock(&q->mutex);
    if (task) {
      free(task->filepath);
      free(task->archive_path);
      free(task->internal_path);
      free(task);
    }
    return;
  }

  q->tasks[q->tail] = task;
  q->tail = (q->tail + 1) % MAX_QUEUE_SIZE;
  q->count++;

  pthread_cond_signal(&q->not_empty);
  pthread_mutex_unlock(&q->mutex);
}

// ============================================================
// ИЗВЛЕЧЕНИЕ ЗАДАЧИ ИЗ ОЧЕРЕДИ
// ============================================================
FileTask *task_queue_pop(TaskQueue *q) {
  pthread_mutex_lock(&q->mutex);

  while (q->count == 0 && !q->shutdown) {
    pthread_cond_wait(&q->not_empty, &q->mutex);
  }

  if (q->count == 0 && q->shutdown) {
    pthread_mutex_unlock(&q->mutex);
    return NULL;
  }

  FileTask *task = q->tasks[q->head];
  q->head = (q->head + 1) % MAX_QUEUE_SIZE;
  q->count--;
  q->total_processed++;

  pthread_cond_signal(&q->not_full);
  pthread_mutex_unlock(&q->mutex);

  return task;
}

// ============================================================
// ЗАВЕРШЕНИЕ РАБОТЫ С ОЧЕРЕДЬЮ ЗАДАЧ
// ============================================================
void task_queue_shutdown(TaskQueue *q) {
  pthread_mutex_lock(&q->mutex);
  q->shutdown = 1;
  pthread_cond_broadcast(&q->not_empty);
  pthread_mutex_unlock(&q->mutex);
}

// ============================================================
// ИНИЦИАЛИЗАЦИЯ ОЧЕРЕДИ РЕЗУЛЬТАТОВ
// ============================================================
void result_queue_init(ResultQueue *q) {
  q->head = 0;
  q->tail = 0;
  q->count = 0;
  q->shutdown = 0;
  pthread_mutex_init(&q->mutex, NULL);
  pthread_cond_init(&q->not_empty, NULL);
}

// ============================================================
// ДОБАВЛЕНИЕ РЕЗУЛЬТАТА В ОЧЕРЕДЬ
// ============================================================
void result_queue_push(ResultQueue *q, BookResult *result) {
  pthread_mutex_lock(&q->mutex);

  // Используем таймаут вместо бесконечного ожидания
  struct timespec ts;
  clock_gettime(CLOCK_REALTIME, &ts);
  ts.tv_sec += 5; // 5 секунд таймаут

  while (q->count == MAX_QUEUE_SIZE && !q->shutdown) {
    if (pthread_cond_timedwait(&q->not_empty, &q->mutex, &ts) == ETIMEDOUT) {
      log_message(NULL, "WARNING", "Result queue timeout, queue full");
      // Можно либо отбросить результат, либо выйти
      if (q->shutdown)
        break;
    }
  }

  if (q->shutdown) {
    pthread_mutex_unlock(&q->mutex);
    return;
  }

  q->results[q->tail] = result;
  q->tail = (q->tail + 1) % MAX_QUEUE_SIZE;
  q->count++;

  pthread_cond_signal(&q->not_empty);
  pthread_mutex_unlock(&q->mutex);
}

// ============================================================
// ИЗВЛЕЧЕНИЕ РЕЗУЛЬТАТА ИЗ ОЧЕРЕДИ
// ============================================================
BookResult *result_queue_pop(ResultQueue *q) {
  pthread_mutex_lock(&q->mutex);

  while (q->count == 0 && !q->shutdown) {
    pthread_cond_wait(&q->not_empty, &q->mutex);
  }

  if (q->count == 0 && q->shutdown) {
    pthread_mutex_unlock(&q->mutex);
    return NULL;
  }

  BookResult *result = q->results[q->head];
  q->head = (q->head + 1) % MAX_QUEUE_SIZE;
  q->count--;

  pthread_mutex_unlock(&q->mutex);
  return result;
}

// ============================================================
// ЗАВЕРШЕНИЕ РАБОТЫ С ОЧЕРЕДЬЮ РЕЗУЛЬТАТОВ
// ============================================================
void result_queue_shutdown(ResultQueue *q) {
  pthread_mutex_lock(&q->mutex);
  q->shutdown = 1;
  pthread_cond_broadcast(&q->not_empty);
  pthread_mutex_unlock(&q->mutex);
}

const char *supported_formats[SUPPORTED_FORMATS] = {
    ".epub", ".fb2", ".pdf", ".txt", ".zip", ".rar", ".7z"};

// Определения функций проверки форматов
int is_supported_format(const char *filename) {
  if (!filename)
    return 0;

  const char *ext = strrchr(filename, '.');
  if (!ext)
    return 0;

  for (int i = 0; i < SUPPORTED_FORMATS; i++) {
    if (strcasecmp(ext, supported_formats[i]) == 0) {
      return 1;
    }
  }
  return 0;
}

int is_archive_format(const char *filename) {
  if (!filename)
    return 0;

  const char *ext = strrchr(filename, '.');
  if (!ext)
    return 0;

  return (strcasecmp(ext, ".zip") == 0 || strcasecmp(ext, ".rar") == 0 ||
          strcasecmp(ext, ".7z") == 0 || strcasecmp(ext, ".tar") == 0 ||
          strcasecmp(ext, ".gz") == 0 || strcasecmp(ext, ".bz2") == 0 ||
          strcasecmp(ext, ".xz") == 0);
}

void scan_directory(const char *path, DatabaseHandle *db_handle, Config *config,
                    int in_transaction) {
  if (!path || !db_handle || !config) {
    log_message(config, "ERROR", "[SCAN_DIRECTORY] Invalid parameters");
    return;
  }

  log_message(config, "INFO", "Starting directory scan: %s", path);

  // ============================================================
  // 1. СТРУКТУРА ДЛЯ СБОРА СТАТИСТИКИ
  // ============================================================
  typedef struct {
    int total_files;
    int books_found;
    int books_inserted;
    int books_skipped;
    int books_failed;
    int archives_processed;
    int directories_scanned;
    long long total_size;
  } ScanStats;

  ScanStats stats = {0};
  time_t start_time = time(NULL);
  time_t last_progress_time = start_time;

  // Стек для рекурсивного обхода
  char **dir_stack = NULL;
  int stack_size = 0;
  int stack_capacity = 0;

  // ============================================================
  // 2. ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ ДЛЯ ОБРАБОТКИ ОДНОГО ФАЙЛА
  // ============================================================
  void process_single_file(const char *filepath, struct stat *statbuf,
                           Config *config) {
    const char *filename = strrchr(filepath, '/');
    if (!filename) {
      filename = filepath;
    } else {
      filename++;
    }

    if (!is_supported_format(filename)) {
      return;
    }

    stats.total_files++;

    if (is_archive_format(filepath)) {
      stats.archives_processed++;
      // ПЕРЕДАЁМ in_transaction = 1 (мы уже в транзакции)
      process_archive(filepath, db_handle, config, in_transaction);
      return;
    }

    const char *ext = strrchr(filename, '.');
    if (!ext)
      return;

    BookMeta *meta = parse_metadata(filepath, ext + 1, config);
    if (!meta) {
      stats.books_failed++;
      log_message(config, "WARNING", "Failed to parse metadata for: %s",
                  filepath);
      return;
    }

    meta->file_size = statbuf->st_size;

    meta->file_hash =
        calculate_file_hash(filepath, config->scanner.hash_algorithm);
    if (!meta->file_hash) {
      log_message(config, "WARNING", "Failed to calculate hash for %s",
                  filepath);
      meta->file_hash = strdup("");
    }

    if (book_exists(db_handle, filepath, NULL, NULL, meta->file_hash, config)) {
      stats.books_skipped++;
      log_message(config, "DEBUG", "Book already exists, skipping: %s",
                  filepath);
      free_book_meta(meta);
      return;
    }

    insert_book_to_db(db_handle, filepath, meta, NULL, NULL, meta->file_hash,
                      config);
    stats.books_inserted++;
    stats.total_size += statbuf->st_size;

    free_book_meta(meta);
  }

  // ============================================================
  // 3. ИНИЦИАЛИЗАЦИЯ СТЕКА
  // ============================================================
  dir_stack = malloc(sizeof(char *) * 64);
  if (!dir_stack) {
    log_message(config, "ERROR", "Failed to allocate directory stack");
    return;
  }
  stack_capacity = 64;
  dir_stack[stack_size++] = strdup(path);

  // ============================================================
  // 4. НАЧАЛО ТРАНЗАКЦИИ (только если не в транзакции)
  // ============================================================
  int transaction_started = 0;
  if (!in_transaction) {
    if (db_begin_transaction(db_handle, config)) {
      transaction_started = 1;
    } else {
      log_message(config, "ERROR", "Failed to start transaction");
      free(dir_stack[0]);
      free(dir_stack);
      return;
    }
  }

  int batch_counter = 0;
  const int BATCH_SIZE = config->scanner.batch_size;

  // ============================================================
  // 5. ОСНОВНОЙ ЦИКЛ ОБХОДА
  // ============================================================
  while (stack_size > 0) {
    char *current_dir = dir_stack[--stack_size];
    stats.directories_scanned++;

    log_message(config, "DEBUG", "Scanning directory: %s", current_dir);

    DIR *dir = opendir(current_dir);
    if (!dir) {
      log_message(config, "WARNING", "Cannot open directory: %s", current_dir);
      free(current_dir);
      continue;
    }

    struct dirent *entry;
    while ((entry = readdir(dir)) != NULL) {
      if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0) {
        continue;
      }

      char full_path[4096];
      snprintf(full_path, sizeof(full_path), "%s/%s", current_dir,
               entry->d_name);

      struct stat statbuf;
      if (stat(full_path, &statbuf) == -1) {
        log_message(config, "WARNING", "Cannot stat: %s", full_path);
        continue;
      }

      if (S_ISDIR(statbuf.st_mode)) {
        if (stack_size >= stack_capacity) {
          stack_capacity *= 2;
          char **new_stack =
              realloc(dir_stack, sizeof(char *) * stack_capacity);
          if (!new_stack) {
            log_message(config, "ERROR",
                        "Failed to reallocate directory stack");
            closedir(dir);
            free(current_dir);
            for (int i = 0; i < stack_size; i++) {
              free(dir_stack[i]);
            }
            free(dir_stack);
            if (transaction_started) {
              db_rollback_transaction(db_handle, config);
            }
            return;
          }
          dir_stack = new_stack;
        }
        dir_stack[stack_size++] = strdup(full_path);
      } else if (S_ISREG(statbuf.st_mode)) {
        process_single_file(full_path, &statbuf, config);
        batch_counter++;

        // ============================================================
        // ПЕРИОДИЧЕСКИЙ COMMIT (только если мы сами начали транзакцию)
        // ============================================================
        if (transaction_started && batch_counter >= BATCH_SIZE) {
          if (!db_commit_transaction(db_handle, config)) {
            log_message(config, "ERROR", "Failed to commit transaction");
            db_rollback_transaction(db_handle, config);
            closedir(dir);
            free(current_dir);
            for (int i = 0; i < stack_size; i++) {
              free(dir_stack[i]);
            }
            free(dir_stack);
            return;
          }

          if (!db_begin_transaction(db_handle, config)) {
            log_message(config, "ERROR", "Failed to start new transaction");
            closedir(dir);
            free(current_dir);
            for (int i = 0; i < stack_size; i++) {
              free(dir_stack[i]);
            }
            free(dir_stack);
            return;
          }

          batch_counter = 0;

          time_t now = time(NULL);
          if (difftime(now, last_progress_time) >= 5) {
            double elapsed = difftime(now, start_time);
            double rate =
                (elapsed > 0) ? (double)stats.total_files / elapsed : 0;
            log_message(config, "INFO",
                        "Progress: %d files scanned, "
                        "%d books inserted, "
                        "%d dirs scanned, %.1f files/sec",
                        stats.total_files, stats.books_inserted,
                        stats.directories_scanned, rate);
            last_progress_time = now;
          }
        }
      }
    }

    closedir(dir);
    free(current_dir);
  }

  // ============================================================
  // 6. ЗАВЕРШЕНИЕ ТРАНЗАКЦИИ (только если мы сами начали)
  // ============================================================
  if (transaction_started) {
    if (batch_counter > 0) {
      if (!db_commit_transaction(db_handle, config)) {
        log_message(config, "ERROR", "Failed to commit final transaction");
        db_rollback_transaction(db_handle, config);
      } else {
        log_message(config, "DEBUG", "Final transaction committed (%d books)",
                    batch_counter);
      }
    }
  }

  // Очищаем стек
  for (int i = 0; i < stack_size; i++) {
    free(dir_stack[i]);
  }
  free(dir_stack);

  // ============================================================
  // 7. ИТОГОВАЯ СТАТИСТИКА
  // ============================================================
  time_t total_time = time(NULL) - start_time;
  double avg_rate =
      (total_time > 0) ? (double)stats.total_files / total_time : 0;

  log_message(config, "INFO",
              "=== DIRECTORY SCAN COMPLETED ===\n"
              "  Path: %s\n"
              "  Directories scanned: %d\n"
              "  Files scanned: %d\n"
              "  Books inserted: %d\n"
              "  Books skipped: %d\n"
              "  Books failed: %d\n"
              "  Archives processed: %d\n"
              "  Total size: %.2f MB\n"
              "  Time: %ld seconds\n"
              "  Average rate: %.1f files/sec",
              path, stats.directories_scanned, stats.total_files,
              stats.books_inserted, stats.books_skipped, stats.books_failed,
              stats.archives_processed, stats.total_size / (1024.0 * 1024.0),
              total_time, avg_rate);
}

void process_archive(const char *archive_path, DatabaseHandle *db_handle,
                     Config *config, int in_transaction) {
  if (!archive_path || !db_handle || !config) {
    log_message(config, "ERROR", "[PROCESS_ARCHIVE] Invalid parameters");
    return;
  }

  log_message(config, "DEBUG", "[PROCESS_ARCHIVE] Starting: %s", archive_path);

  struct stat archive_stat;
  if (stat(archive_path, &archive_stat) == 0) {
    double size_mb = archive_stat.st_size / (1024.0 * 1024.0);
    log_message(config, "INFO", "Processing archive: %s (%.2f MB)",
                archive_path, size_mb);
  }

  int use_fast_hash = 0;
  if (archive_stat.st_size > 1024 * 1024 * 1024) {
    log_message(config, "INFO",
                "Large archive detected (>1GB), using fast comparison");
    use_fast_hash = 1;
  }

  char *archive_hash = NULL;

  if (use_fast_hash) {
    archive_hash =
        calculate_fast_file_hash(archive_path, config->scanner.hash_algorithm);
  } else {
    archive_hash =
        calculate_file_hash(archive_path, config->scanner.hash_algorithm);
  }

  if (!archive_hash) {
    log_message(config, "ERROR", "Cannot calculate hash for archive: %s",
                archive_path);
    return;
  }

  log_message(config, "DEBUG", "[PROCESS_ARCHIVE] Using %s hash: %s",
              config->scanner.hash_algorithm, archive_hash);

  if (!archive_needs_rescan(db_handle, archive_path, archive_hash, config)) {
    log_message(config, "DEBUG",
                "[PROCESS_ARCHIVE] Archive doesn't need rescan: %s",
                archive_path);
    free(archive_hash);
    return;
  }

  log_message(config, "INFO", "Processing archive: %s", archive_path);

  // ============================================================
  // НАЧАЛО ТРАНЗАКЦИИ (только если не в транзакции)
  // ============================================================
  int transaction_started = 0;
  if (!in_transaction) {
    if (db_begin_transaction(db_handle, config)) {
      transaction_started = 1;
    } else {
      log_message(config, "ERROR", "Failed to start transaction");
      free(archive_hash);
      return;
    }
  }

  struct archive *a;
  struct archive_entry *entry;
  int r;

  a = archive_read_new();
  archive_read_support_format_all(a);
  archive_read_support_filter_all(a);
  archive_read_set_options(a, "hdrcharset=UTF-8");

  r = archive_read_open_filename(a, archive_path, 10240);
  if (r != ARCHIVE_OK) {
    log_message(config, "ERROR", "Failed to open archive: %s - %s",
                archive_path, archive_error_string(a));
    archive_read_free(a);
    free(archive_hash);
    if (transaction_started) {
      db_rollback_transaction(db_handle, config);
    }
    return;
  }

  int file_count = 0;
  int books_inserted = 0;
  int books_failed = 0;
  int batch_counter = 0;
  const int BATCH_SIZE = config->scanner.batch_size;
  const int MAX_ERRORS = 20;
  long total_size = 0;
  time_t start_time = time(NULL);

  while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
    const char *filename = archive_entry_pathname(entry);
    la_int64_t size = archive_entry_size(entry);

    if (!filename) {
      archive_read_data_skip(a);
      continue;
    }

    if (archive_entry_filetype(entry) != AE_IFREG) {
      archive_read_data_skip(a);
      continue;
    }

    if (size > 200 * 1024 * 1024) {
      log_message(config, "DEBUG", "Skipping very large file: %s (%lld MB)",
                  filename, size / (1024 * 1024));
      archive_read_data_skip(a);
      continue;
    }

    const char *ext = strrchr(filename, '.');
    if (!ext || !is_supported_format(filename)) {
      archive_read_data_skip(a);
      continue;
    }

    log_message(config, "INFO", "Found book in archive: %s/%s", archive_path,
                filename);

    file_count++;
    total_size += size;

    int success = 0;
    if (size > 10 * 1024 * 1024) {
      success = process_large_archive_file(a, entry, archive_path, filename,
                                           db_handle, config);
    } else {
      success = process_small_archive_file(a, entry, archive_path, filename,
                                           db_handle, config);
    }

    if (success) {
      books_inserted++;
    } else {
      books_failed++;
    }

    batch_counter++;

    // ============================================================
    // ПЕРИОДИЧЕСКИЙ COMMIT (только если мы сами начали транзакцию)
    // ============================================================
    if (transaction_started && batch_counter >= BATCH_SIZE) {
      if (!db_commit_transaction(db_handle, config)) {
        log_message(config, "ERROR", "Failed to commit transaction");
        db_rollback_transaction(db_handle, config);
        break;
      }

      if (!db_begin_transaction(db_handle, config)) {
        log_message(config, "ERROR", "Failed to start new transaction");
        break;
      }

      batch_counter = 0;
    }

    if (books_failed >= MAX_ERRORS) {
      log_message(config, "ERROR",
                  "Too many errors (%d), aborting archive processing",
                  books_failed);
      if (transaction_started) {
        db_rollback_transaction(db_handle, config);
      }
      break;
    }
  }

  if (archive_errno(a) != 0) {
    log_message(config, "ERROR", "Archive read error: %s",
                archive_error_string(a));
  }

  archive_read_close(a);
  archive_read_free(a);

  // ============================================================
  // ЗАВЕРШЕНИЕ ТРАНЗАКЦИИ (только если мы сами начали)
  // ============================================================
  if (transaction_started && books_failed < MAX_ERRORS) {
    if (!db_commit_transaction(db_handle, config)) {
      log_message(config, "ERROR", "Failed to commit final transaction");
      db_rollback_transaction(db_handle, config);
    } else {
      log_message(config, "DEBUG", "Transaction committed: %d books inserted",
                  books_inserted);
    }
  }

  update_archive_info(db_handle, archive_path, archive_hash, file_count,
                      total_size, config);
  free(archive_hash);

  time_t total_time = time(NULL) - start_time;
  log_message(config, "INFO",
              "Archive processed: %s (%d files, %d books inserted, %d errors) "
              "in %ld seconds",
              archive_path, file_count, books_inserted, books_failed,
              total_time);
}

/**
 * Вычисляет хеш файла, читая только начало и конец (для больших файлов).
 * Для файлов <= 10MB хешируется целиком.
 * Для файлов > 10MB хешируются первые и последние 10MB.
 *
 * @param filepath Путь к файлу
 * @param algorithm Алгоритм хеширования: "md5", "sha1", "sha256", "sha512"
 * @return Строка с hex-хешем (нужно освободить через free()) или NULL при
 * ошибке
 */
char *calculate_fast_file_hash(const char *filepath, const char *algorithm) {
  if (!filepath || !algorithm) {
    fprintf(stderr, "calculate_fast_file_hash: NULL parameters\n");
    return NULL;
  }

  FILE *file = safe_fopen(filepath, "rb", NULL);
  if (!file) {
    fprintf(stderr, "Cannot open file for fast hash: %s\n", filepath);
    return NULL;
  }

  // Выбор алгоритма
  const EVP_MD *md_algorithm = NULL;
  if (strcasecmp(algorithm, "md5") == 0)
    md_algorithm = EVP_md5();
  else if (strcasecmp(algorithm, "sha1") == 0)
    md_algorithm = EVP_sha1();
  else if (strcasecmp(algorithm, "sha256") == 0)
    md_algorithm = EVP_sha256();
  else if (strcasecmp(algorithm, "sha512") == 0)
    md_algorithm = EVP_sha512();
  else
    md_algorithm = EVP_sha256(); // default

  EVP_MD_CTX *mdctx = EVP_MD_CTX_new();
  if (!mdctx || EVP_DigestInit_ex(mdctx, md_algorithm, NULL) != 1) {
    if (mdctx)
      EVP_MD_CTX_free(mdctx);
    fclose(file);
    return NULL;
  }

  // === ПОЛУЧЕНИЕ РАЗМЕРА ФАЙЛА (исправлено!) ===
  long file_size = 0;
#ifdef _WIN32
  if (_fseeki64(file, 0, SEEK_END) != 0) {
    EVP_MD_CTX_free(mdctx);
    fclose(file);
    return NULL;
  }
  __int64 size64 = _ftelli64(file);
  if (size64 < 0) {
    EVP_MD_CTX_free(mdctx);
    fclose(file);
    return NULL;
  }
  if (size64 > LONG_MAX) {
    file_size = LONG_MAX;
    fprintf(
        stderr,
        "Warning: File too large (>2GB), hashing first/last 10MB only: %s\n",
        filepath);
  } else {
    file_size = (long)size64;
  }
#else
  if (fseeko(file, 0, SEEK_END) != 0) {
    EVP_MD_CTX_free(mdctx);
    fclose(file);
    return NULL;
  }
  off_t size_off = ftello(file);
  if (size_off < 0) {
    EVP_MD_CTX_free(mdctx);
    fclose(file);
    return NULL;
  }
  if (size_off > LONG_MAX) {
    file_size = LONG_MAX;
    fprintf(
        stderr,
        "Warning: File too large (>2GB), hashing first/last 10MB only: %s\n",
        filepath);
  } else {
    file_size = (long)size_off;
  }
#endif
  rewind(file); // Возвращаемся в начало для чтения

  // === ОСНОВНАЯ ЛОГИКА ХЕШИРОВАНИЯ ===
  unsigned char buffer[65536];
  size_t bytes_read;
  const long CHUNK_SIZE = 10 * 1024 * 1024; // 10MB

  if (file_size <= CHUNK_SIZE) {
    // Файл <= 10MB: хешируем целиком
    while ((bytes_read = fread(buffer, 1, sizeof(buffer), file)) > 0) {
      if (EVP_DigestUpdate(mdctx, buffer, bytes_read) != 1)
        goto cleanup_error;
    }
  } else {
    // Файл > 10MB: начало + конец

    // 1. Первые 10MB
    rewind(file);
    long remaining = CHUNK_SIZE;
    while (remaining > 0 &&
           (bytes_read =
                fread(buffer, 1,
                      (remaining < (long)sizeof(buffer)) ? (size_t)remaining
                                                         : sizeof(buffer),
                      file)) > 0) {
      if (EVP_DigestUpdate(mdctx, buffer, bytes_read) != 1)
        goto cleanup_error;
      remaining -= (long)bytes_read;
    }

    // 2. Последние 10MB
    int seek_ok = 0;
#ifdef _WIN32
    seek_ok = (_fseeki64(file, -CHUNK_SIZE, SEEK_END) == 0);
#else
    seek_ok = (fseeko(file, -CHUNK_SIZE, SEEK_END) == 0);
#endif

    if (!seek_ok) {
      // Fallback: хешируем весь файл, если не удалось перейти в конец
      rewind(file);
      while ((bytes_read = fread(buffer, 1, sizeof(buffer), file)) > 0) {
        if (EVP_DigestUpdate(mdctx, buffer, bytes_read) != 1)
          goto cleanup_error;
      }
    } else {
      remaining = CHUNK_SIZE;
      while (remaining > 0 &&
             (bytes_read =
                  fread(buffer, 1,
                        (remaining < (long)sizeof(buffer)) ? (size_t)remaining
                                                           : sizeof(buffer),
                        file)) > 0) {
        if (EVP_DigestUpdate(mdctx, buffer, bytes_read) != 1)
          goto cleanup_error;
        remaining -= (long)bytes_read;
      }
    }
  }

  // Завершение хеширования
  unsigned char hash[EVP_MAX_MD_SIZE];
  unsigned int hash_len;
  if (EVP_DigestFinal_ex(mdctx, hash, &hash_len) != 1)
    goto cleanup_error;

  EVP_MD_CTX_free(mdctx);
  fclose(file);

  // Конвертация в hex
  char *hash_str = safe_malloc(hash_len * 2 + 1);
  if (!hash_str)
    return NULL;
  for (unsigned int i = 0; i < hash_len; i++) {
    sprintf(hash_str + (i * 2), "%02x", hash[i]);
  }
  hash_str[hash_len * 2] = '\0';
  return hash_str;

cleanup_error:
  EVP_MD_CTX_free(mdctx);
  fclose(file);
  return NULL;
}

// Функция для обработки маленьких файлов из архива - возвращает 1 при успехе, 0
// при ошибке
int process_small_archive_file(struct archive *a, struct archive_entry *entry,
                               const char *archive_path, const char *filename,
                               DatabaseHandle *db_handle, Config *config) {
  la_int64_t size = archive_entry_size(entry);

  // ПРОПУСКАЕМ ПУСТЫЕ ФАЙЛЫ
  if (size == 0) {
    log_message(config, "DEBUG", "Skipping empty file in archive: %s",
                filename);
    archive_read_data_skip(a);
    return 0;
  }

  if (size > 10 * 1024 * 1024) {
    log_message(config, "WARNING", "File too large for small processing: %s",
                filename);
    archive_read_data_skip(a);
    return 0;
  }

  if (size > 100 * 1024 * 1024) {
    log_message(config, "ERROR",
                "File too large to allocate memory: %s (%lld MB)", filename,
                size / (1024 * 1024));
    archive_read_data_skip(a);
    return 0;
  }

  char *content = malloc(size + 1);
  if (!content) {
    log_message(config, "ERROR", "Failed to allocate %lld bytes for: %s", size,
                filename);
    archive_read_data_skip(a);
    return 0;
  }

  la_ssize_t bytes_read = archive_read_data(a, content, size);
  if (bytes_read != size) {
    log_message(config, "WARNING",
                "Failed to read file from archive: %s (read %zd of %lld)",
                filename, bytes_read, size);
    free(content);
    archive_read_data_skip(a);
    return 0;
  }
  content[size] = '\0';

  const char *ext = strrchr(filename, '.');
  BookMeta *meta = NULL;

  if (ext) {
    if (strcasecmp(ext + 1, "fb2") == 0) {
      meta = parse_fb2_from_memory(content, size);
    } else if (strcasecmp(ext + 1, "epub") == 0) {
      meta = parse_epub_from_memory(content, size, config);
    }
  }

  if (meta) {
    meta->file_size = size;

    if (content && size > 0) {
      meta->file_hash = calculate_buffer_hash((unsigned char *)content, size,
                                              config->scanner.hash_algorithm);
    }
    if (!meta->file_hash) {
      meta->file_hash = strdup("");
    }

    insert_book_to_db(db_handle, archive_path, meta, archive_path, filename,
                      meta->file_hash, config);

    free_book_meta(meta);
    free(content);
    return 1;
  } else {
    log_message(config, "WARNING", "Failed to parse metadata for: %s",
                filename);
    free(content);
    return 0;
  }
}

// Функция для обработки больших файлов из архива - возвращает 1 при успехе, 0
// при ошибке
int process_large_archive_file(struct archive *a, struct archive_entry *entry,
                               const char *archive_path, const char *filename,
                               DatabaseHandle *db_handle, Config *config) {
  (void)entry;

  log_message(config, "DEBUG", "Processing large file with streaming: %s",
              filename);

  char temp_path[] = "/tmp/archive_extract_XXXXXX";
  int fd = mkstemp(temp_path);
  if (fd == -1) {
    log_message(config, "ERROR",
                "Cannot create temp file for large archive entry: %s",
                strerror(errno));
    archive_read_data_skip(a);
    return 0;
  }

  unsigned char buffer[65536];
  la_ssize_t bytes_read;
  long total_written = 0;
  int write_error = 0;

  while ((bytes_read = archive_read_data(a, buffer, sizeof(buffer))) > 0) {
    ssize_t written = write(fd, buffer, bytes_read);
    if (written != bytes_read) {
      log_message(config, "ERROR", "Failed to write temp file: %s",
                  strerror(errno));
      write_error = 1;
      break;
    }
    total_written += written;

    if (total_written > 500 * 1024 * 1024) {
      log_message(config, "WARNING",
                  "Extracted file too large (>500MB), stopping: %s", filename);
      write_error = 1;
      break;
    }
  }

  close(fd);

  if (write_error) {
    unlink(temp_path);
    archive_read_data_skip(a);
    return 0;
  }

  const char *ext = strrchr(filename, '.');
  BookMeta *meta = NULL;

  if (ext) {
    if (strcasecmp(ext + 1, "fb2") == 0) {
      meta = parse_fb2(temp_path);
    } else if (strcasecmp(ext + 1, "epub") == 0) {
      meta = parse_epub(temp_path, config);
    }
  }

  // ВЫЧИСЛЯЕМ ХЕШ ДЛЯ БОЛЬШОГО ФАЙЛА
  if (meta) {
    struct stat st;
    if (stat(temp_path, &st) == 0) {
      meta->file_size = st.st_size;
    } else {
      meta->file_size = total_written;
    }

    // ВЫЧИСЛЯЕМ ХЕШ
    meta->file_hash =
        calculate_file_hash(temp_path, config->scanner.hash_algorithm);
    if (!meta->file_hash) {
      log_message(config, "WARNING",
                  "Failed to calculate hash for large file: %s", filename);
      meta->file_hash = strdup(""); // Пустая строка вместо NULL
    }
  }

  // Удаляем временный файл
  unlink(temp_path);

  if (meta) {
    // ПЕРЕДАЁМ ХЕШ (НЕ NULL)
    insert_book_to_db(db_handle, archive_path, meta, archive_path, filename,
                      meta->file_hash, config);
    free_book_meta(meta);
    return 1;
  } else {
    log_message(config, "WARNING",
                "Failed to parse metadata for large file: %s", filename);
    return 0;
  }
}

// ============================================================
// ПОТОК ДЛЯ ОБРАБОТКИ ФАЙЛОВ (ТОЛЬКО ПАРСИНГ, БЕЗ БД)
// ============================================================
void *file_worker_thread(void *arg) {
  WorkerContext *ctx = (WorkerContext *)arg;
  TaskQueue *task_queue = ctx->task_queue;
  ResultQueue *result_queue = ctx->result_queue;
  Config *config = ctx->config;

  // ============================================================
  // ИНИЦИАЛИЗАЦИЯ MySQL ДЛЯ ЭТОГО ПОТОКА
  // ============================================================
  mysql_thread_init();

  // ============================================================
  // СОЗДАЁМ ЛИЧНОЕ СОЕДИНЕНИЕ ДЛЯ КАЖДОГО ПОТОКА
  // ============================================================
  DatabaseHandle *thread_db = db_connect(config);
  if (!thread_db) {
    log_message(config, "ERROR", "File worker: Failed to connect to database");
    mysql_thread_end();
    return NULL;
  }

  // Настраиваем соединение в зависимости от типа БД
  if (thread_db->db_type == DB_SQLITE) {
    sqlite3 *db = (sqlite3 *)thread_db->connection;
    if (db) {
      // Устанавливаем таймаут
      sqlite3_busy_timeout(db, 10000);

      // Включаем WAL
      sqlite3_exec(db, "PRAGMA journal_mode = WAL;", NULL, NULL, NULL);
      sqlite3_exec(db, "PRAGMA synchronous = NORMAL;", NULL, NULL, NULL);
      sqlite3_exec(db, "PRAGMA cache_size = -100000;", NULL, NULL, NULL);

      log_message(config, "DEBUG", "File worker: SQLite WAL enabled");
    }
  } else if (thread_db->db_type == DB_MYSQL) {
    log_message(config, "DEBUG",
                "File worker: Created personal MySQL connection");
  }

  log_message(config, "DEBUG",
              "File worker: Personal database connection created (type: %d)",
              thread_db->db_type);

  // ============================================================
  // ОСНОВНОЙ ЦИКЛ ОБРАБОТКИ
  // ============================================================
  while (1) {
    FileTask *task = task_queue_pop(task_queue);
    if (task == NULL) {
      log_message(config, "DEBUG", "File worker: No more tasks, finishing");
      break;
    }

    log_message(config, "DEBUG", "File worker processing: %s", task->filepath);

    // ============================================================
    // АРХИВ
    // ============================================================
    if (task->is_archive) {
      int books_found = process_archive_multithreaded(
          task->filepath,
          thread_db, // Используем ЛИЧНОЕ соединение
          config, result_queue);
      if (books_found > 0) {
        log_message(config, "DEBUG",
                    "Archive processed: %s, %d books extracted", task->filepath,
                    books_found);
      }
      free(task->filepath);
      free(task->archive_path);
      free(task->internal_path);
      free(task);
      continue;
    }

    // ============================================================
    // ОБЫЧНЫЙ ФАЙЛ
    // ============================================================
    const char *ext = strrchr(task->filepath, '.');
    if (!ext) {
      free(task->filepath);
      free(task);
      continue;
    }

    // ============================================================
    // ВЫЧИСЛЯЕМ ХЕШ
    // ============================================================
    char *hash =
        calculate_file_hash(task->filepath, config->scanner.hash_algorithm);
    if (!hash) {
      log_message(config, "WARNING", "Failed to calculate hash for: %s",
                  task->filepath);
      free(task->filepath);
      free(task);
      continue;
    }

    // ============================================================
    // ПАРСИМ МЕТАДАННЫЕ
    // ============================================================
    BookMeta *meta = parse_metadata(task->filepath, ext + 1, config);
    if (!meta) {
      log_message(config, "WARNING", "Failed to parse metadata: %s",
                  task->filepath);
      free(hash);
      free(task->filepath);
      free(task);
      continue;
    }

    meta->file_size = task->file_size;
    meta->file_hash = hash;

    // ============================================================
    // ОТПРАВЛЯЕМ РЕЗУЛЬТАТ В DB WORKER
    // ============================================================
    BookResult *result = calloc(1, sizeof(BookResult));
    if (!result) {
      log_message(config, "ERROR", "Failed to allocate BookResult");
      free_book_meta(meta);
      free(task->filepath);
      free(task);
      continue;
    }

    result->filepath = strdup(task->filepath);
    result->meta = meta;
    result->file_hash = strdup(meta->file_hash ? meta->file_hash : "");
    result->is_archive = 0;
    result->success = 1;
    result->archive_path = NULL;
    result->internal_path = NULL;
    result->archive_info = NULL;

    result_queue_push(result_queue, result);

    free(task->filepath);
    free(task);
  }

  log_message(config, "DEBUG", "File worker thread finished");

  // ============================================================
  // ЗАКРЫВАЕМ ЛИЧНОЕ СОЕДИНЕНИЕ
  // ============================================================
  if (thread_db) {
    db_close(thread_db);
    log_message(config, "DEBUG",
                "File worker: Closed personal database connection");
  }

  mysql_thread_end();
  return NULL;
}

// ============================================================
// ОБРАБОТКА АРХИВА В ВОРКЕРЕ (ТОЛЬКО ПАРСИНГ, БЕЗ БД)
// ============================================================
int process_archive_multithreaded(const char *archive_path,
                                  DatabaseHandle *db_handle, Config *config,
                                  ResultQueue *result_queue) {
  if (!archive_path || !config) {
    log_message(config, "ERROR",
                "process_archive_multithreaded: Invalid parameters");
    return 0;
  }

  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR",
                "process_archive_multithreaded: No database connection");
    return 0;
  }

  log_message(config, "DEBUG", "[ARCHIVE_WORKER] Processing: %s", archive_path);

  // ============================================================
  // 1. ВЫЧИСЛЯЕМ ХЕШ АРХИВА
  // ============================================================
  char *archive_hash =
      calculate_file_hash(archive_path, config->scanner.hash_algorithm);
  if (!archive_hash) {
    log_message(config, "ERROR", "Cannot calculate hash for archive: %s",
                archive_path);
    return 0;
  }

  // ============================================================
  // 2. ПРОВЕРЯЕМ, НУЖНО ЛИ ПЕРЕСКАНИРОВАТЬ
  // ============================================================
  if (!archive_needs_rescan(db_handle, archive_path, archive_hash, config)) {
    log_message(config, "DEBUG", "Archive unchanged, skipping: %s",
                archive_path);
    free(archive_hash);
    return 0;
  }

  log_message(config, "INFO", "Processing archive: %s", archive_path);

  // ============================================================
  // 3. ОТКРЫВАЕМ АРХИВ
  // ============================================================
  struct archive *a = archive_read_new();
  archive_read_support_format_all(a);
  archive_read_support_filter_all(a);
  archive_read_set_options(a, "hdrcharset=UTF-8");

  int r = archive_read_open_filename(a, archive_path, 65536);
  if (r != ARCHIVE_OK) {
    log_message(config, "ERROR", "Failed to open archive: %s - %s",
                archive_path, archive_error_string(a));
    archive_read_free(a);
    free(archive_hash);
    return 0;
  }

  // ============================================================
  // 4. НАСТРОЙКИ КЭШИРОВАНИЯ
  // ============================================================
  // используем size_t для сравнения
  const size_t MEMORY_THRESHOLD = 100 * 1024 * 1024;         // 100 MB
  const size_t MAX_MEMORY_USAGE = 2ULL * 1024 * 1024 * 1024; // 2 GB
  size_t current_memory_usage = 0;
  int books_found = 0;
  long total_size = 0;
  time_t start_time = time(NULL);

  // Счётчики для статистики
  int memory_loaded = 0;
  int disk_extracted = 0;

  // ============================================================
  // 5. ОБРАБАТЫВАЕМ ФАЙЛЫ В АРХИВЕ
  // ============================================================
  struct archive_entry *entry;
  while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
    const char *filename = archive_entry_pathname(entry);
    la_int64_t size_ll = archive_entry_size(entry);

    // преобразуем к size_t для безопасного сравнения
    size_t size = (size_ll < 0) ? 0 : (size_t)size_ll;

    // Пропускаем некорректные записи
    if (!filename) {
      archive_read_data_skip(a);
      continue;
    }

    if (archive_entry_filetype(entry) != AE_IFREG) {
      archive_read_data_skip(a);
      continue;
    }

    // Пропускаем пустые файлы
    if (size == 0) {
      log_message(config, "DEBUG", "Skipping empty file: %s", filename);
      archive_read_data_skip(a);
      continue;
    }

    // Пропускаем слишком большие файлы
    if (size > 200 * 1024 * 1024) {
      log_message(config, "DEBUG", "Skipping large file: %s (%zu MB)", filename,
                  size / (1024 * 1024));
      archive_read_data_skip(a);
      continue;
    }

    // Проверяем формат
    const char *ext = strrchr(filename, '.');
    if (!ext || !is_supported_format(filename)) {
      archive_read_data_skip(a);
      continue;
    }

    log_message(config, "DEBUG", "Processing: %s (size: %zu bytes)", filename,
                size);
    books_found++;
    total_size += (long)size;

    BookMeta *meta = NULL;
    char *file_hash = NULL;

    // ============================================================
    // 6. ВЫБОР МЕТОДА ЗАГРУЗКИ
    // ============================================================
    if (size <= MEMORY_THRESHOLD &&
        current_memory_usage + size < MAX_MEMORY_USAGE) {

      // **ЗАГРУЗКА В ПАМЯТЬ** (быстро!)
      log_message(config, "DEBUG", "Loading to memory: %s (%.2f MB)", filename,
                  size / (1024.0 * 1024.0));

      char *content = malloc(size + 1);
      if (!content) {
        log_message(config, "ERROR", "Failed to allocate memory for: %s",
                    filename);
        archive_read_data_skip(a);
        continue;
      }

      la_ssize_t bytes_read = archive_read_data(a, content, size);
      if (bytes_read != (la_ssize_t)size) {
        log_message(config, "WARNING",
                    "Failed to read file: %s (read %zd of %zu)", filename,
                    bytes_read, size);
        free(content);
        archive_read_data_skip(a);
        continue;
      }
      content[size] = '\0';

      // Парсим из памяти
      if (strcasecmp(ext + 1, "fb2") == 0) {
        meta = parse_fb2_from_memory(content, size);
      } else if (strcasecmp(ext + 1, "epub") == 0) {
        meta = parse_epub_from_memory(content, size, config);
      }

      // Вычисляем хеш
      if (meta && content && size > 0) {
        file_hash = calculate_buffer_hash((unsigned char *)content, size,
                                          config->scanner.hash_algorithm);
      }

      free(content);
      memory_loaded++;
      current_memory_usage += size;

    } else {
      // **РАСПАКОВКА НА ДИСК** (медленно, но необходимо)
      log_message(config, "DEBUG", "Extracting to disk: %s (%.2f MB)", filename,
                  size / (1024.0 * 1024.0));

      char temp_path[] = "/tmp/archive_extract_XXXXXX";
      int fd = mkstemp(temp_path);
      if (fd == -1) {
        log_message(config, "ERROR", "Cannot create temp file: %s", filename);
        archive_read_data_skip(a);
        continue;
      }

      unsigned char buffer[65536];
      la_ssize_t bytes_read;
      long total_written = 0;
      int write_error = 0;

      while ((bytes_read = archive_read_data(a, buffer, sizeof(buffer))) > 0) {
        ssize_t written = write(fd, buffer, bytes_read);
        if (written != bytes_read) {
          write_error = 1;
          break;
        }
        total_written += written;

        if (total_written > 500 * 1024 * 1024) {
          log_message(config, "WARNING", "File too large (>500MB): %s",
                      filename);
          write_error = 1;
          break;
        }
      }
      close(fd);

      if (write_error || total_written == 0) {
        unlink(temp_path);
        archive_read_data_skip(a);
        continue;
      }

      // Парсим с диска
      if (strcasecmp(ext + 1, "fb2") == 0) {
        meta = parse_fb2(temp_path);
      } else if (strcasecmp(ext + 1, "epub") == 0) {
        meta = parse_epub(temp_path, config);
      }

      // Вычисляем хеш
      if (meta && total_written > 0) {
        file_hash =
            calculate_file_hash(temp_path, config->scanner.hash_algorithm);
      }

      unlink(temp_path);
      disk_extracted++;
    }

    // ============================================================
    // 7. ОТПРАВКА РЕЗУЛЬТАТА
    // ============================================================
    if (meta) {
      meta->file_size = (long)size;
      if (file_hash) {
        meta->file_hash = file_hash;
      } else {
        meta->file_hash = strdup("");
      }

      BookResult *result = calloc(1, sizeof(BookResult));
      if (result) {
        result->filepath = strdup(archive_path);
        result->archive_path = strdup(archive_path);
        result->internal_path = strdup(filename);
        result->meta = meta;
        result->file_hash = strdup(meta->file_hash ? meta->file_hash : "");
        result->is_archive = 1;
        result->success = 1;
        result->archive_info = NULL;

        result_queue_push(result_queue, result);
      } else {
        free_book_meta(meta);
        if (file_hash)
          free(file_hash);
      }
    } else {
      if (file_hash)
        free(file_hash);
      log_message(config, "WARNING", "Failed to parse: %s", filename);
    }
  }

  // ============================================================
  // 8. ОТПРАВКА ИНФОРМАЦИИ ОБ АРХИВЕ
  // ============================================================
  BookResult *archive_result = calloc(1, sizeof(BookResult));
  if (archive_result) {
    archive_result->archive_info = calloc(1, sizeof(ArchiveInfo));
    if (archive_result->archive_info) {
      archive_result->archive_info->archive_path = strdup(archive_path);
      archive_result->archive_info->archive_hash = archive_hash;
      archive_result->archive_info->file_count = books_found;
      archive_result->archive_info->total_size = total_size;
      archive_result->archive_info->needs_update = 1;
      archive_result->success = 0;
      archive_result->is_archive = 0;

      result_queue_push(result_queue, archive_result);
    } else {
      free(archive_result);
      free(archive_hash);
    }
  } else {
    free(archive_hash);
  }

  archive_read_close(a);
  archive_read_free(a);

// ============================================================
// 9. ОЧИСТКА ПАМЯТИ (если доступна)
// ============================================================
#ifdef __linux__
  if (current_memory_usage > 100 * 1024 * 1024) {
    log_message(config, "INFO", "Memory usage: %.2f MB, trimming...",
                current_memory_usage / (1024.0 * 1024.0));
    malloc_trim(0);
  }
#endif

  // ============================================================
  // 10. СТАТИСТИКА
  // ============================================================
  time_t elapsed = time(NULL) - start_time;
  int total_files = memory_loaded + disk_extracted;

  log_message(config, "INFO",
              "Archive processed: %s\n"
              "  Books found: %d\n"
              "  Total size: %.2f MB\n"
              "  Memory loaded: %d files (%.1f%%)\n"
              "  Disk extracted: %d files (%.1f%%)\n"
              "  Time: %ld sec\n"
              "  Rate: %.1f books/sec",
              archive_path, books_found, total_size / (1024.0 * 1024.0),
              memory_loaded,
              (total_files > 0) ? 100.0 * memory_loaded / total_files : 0,
              disk_extracted,
              (total_files > 0) ? 100.0 * disk_extracted / total_files : 0,
              elapsed, (elapsed > 0) ? (double)books_found / elapsed : 0);

  return books_found;
}

// ============================================================
// ПОТОК ДЛЯ ЗАПИСИ В БД (ОДИН ПОТОК - ВСЕ ОПЕРАЦИИ С БД)
// ============================================================

void *db_worker_thread(void *arg) {
  WorkerContext *ctx = (WorkerContext *)arg;
  ResultQueue *result_queue = ctx->result_queue;
  Config *config = ctx->config;
  DatabaseHandle *db_handle = ctx->db_handle;
  int batch_size = ctx->batch_size;

  int batch_count = 0;
  int total_inserted = 0;
  int total_skipped = 0;
  int total_archives = 0;
  int total_errors = 0;
  const int MAX_ERRORS = 100;

  if (!db_handle || !db_handle->connection) {
    log_message(config, "ERROR", "DB worker: No database connection");
    return NULL;
  }

  log_message(config, "DEBUG", "DB worker thread started");

  // Начинаем транзакцию
  if (!db_begin_transaction(db_handle, config)) {
    log_message(config, "ERROR", "DB worker: Failed to start transaction");
    return NULL;
  }

  while (1) {
    BookResult *result = result_queue_pop(result_queue);
    if (result == NULL) {
      log_message(config, "DEBUG", "DB worker: No more results, finishing");
      break;
    }

    // ============================================================
    // ПРОВЕРКА СОЕДИНЕНИЯ - БЕЗ ЗАКРЫТИЯ СТАРОГО
    // ============================================================
    if (db_handle->db_type == DB_MYSQL) {

      mysql_thread_init();

      MySQLConnection *mysql_conn = (MySQLConnection *)db_handle->connection;
      if (!mysql_conn || !mysql_conn->mysql) {
        log_message(config, "WARNING",
                    "DB worker: MySQL connection is NULL, reconnecting...");
        // НЕ закрываем старое соединение, просто создаём новое
        mysql_conn = mysql_conn_connect(config);
        if (mysql_conn) {
          // Заменяем соединение
          if (db_handle->connection) {
            // Просто заменяем указатель, не вызывая db_close
            // Старое соединение уже невалидно
          }
          db_handle->connection = mysql_conn;
          log_message(config, "INFO", "DB worker: Reconnected successfully");
          db_begin_transaction(db_handle, config);
          batch_count = 0;
        } else {
          log_message(config, "ERROR", "DB worker: Reconnection failed");
          db_rollback_transaction(db_handle, config);
          break;
        }
      } else if (mysql_ping(mysql_conn->mysql) != 0) {
        log_message(config, "WARNING",
                    "DB worker: Connection lost, reconnecting...");
        // НЕ закрываем старое соединение

        MySQLConnection *new_conn = mysql_conn_connect(config);
        if (new_conn) {
          // Закрываем старое соединение БЕЗ вызова db_close
          if (mysql_conn->mysql) {
            mysql_close(mysql_conn->mysql);
          }
          free(mysql_conn);
          db_handle->connection = new_conn;
          log_message(config, "INFO", "DB worker: Reconnected successfully");
          db_begin_transaction(db_handle, config);
          batch_count = 0;
        } else {
          log_message(config, "ERROR", "DB worker: Reconnection failed");
          db_rollback_transaction(db_handle, config);
          break;
        }
      }
    }

    // Обработка архива
    if (result->archive_info && result->archive_info->needs_update) {
      log_message(config, "DEBUG", "DB worker: Updating archive info: %s",
                  result->archive_info->archive_path);

      update_archive_info(db_handle, result->archive_info->archive_path,
                          result->archive_info->archive_hash,
                          result->archive_info->file_count,
                          result->archive_info->total_size, config);

      total_archives++;

      // Освобождаем память
      free(result->archive_info->archive_path);
      free(result->archive_info->archive_hash);
      free(result->archive_info);
      free(result);
      continue;
    }

    // Пропускаем неудачные результаты
    if (!result->success || !result->meta) {
      log_message(config, "DEBUG", "DB worker: Skipping failed result");
      free(result->filepath);
      if (result->archive_path)
        free(result->archive_path);
      if (result->internal_path)
        free(result->internal_path);
      if (result->file_hash)
        free(result->file_hash);
      if (result->meta)
        free_book_meta(result->meta);
      free(result);
      continue;
    }

    // ВСТАВЛЯЕМ КНИГУ В БД
    if (insert_book_to_db(db_handle, result->filepath, result->meta,
                          result->archive_path, result->internal_path,
                          result->file_hash, config)) {
      total_inserted++;
    } else {
      total_skipped++;
    }

    batch_count++;

    if ((total_inserted + total_skipped) % batch_size == 0) {
      log_message(
          config, "INFO",
          "DB worker: Inserted %d books, skipped %d, updated %d archives",
          total_inserted, total_skipped, total_archives);
    }

    // COMMIT при достижении batch_size
    if (batch_count >= batch_size) {

      if (!db_commit_transaction(db_handle, config)) {
        log_message(config, "ERROR", "DB worker: Commit failed, rolling back");
        db_rollback_transaction(db_handle, config);
        total_errors++;
        if (total_errors > MAX_ERRORS) {
          log_message(config, "ERROR", "DB worker: Too many errors, exiting");
          break;
        }
        // Пытаемся начать новую транзакцию
        if (!db_begin_transaction(db_handle, config)) {
          log_message(config, "ERROR",
                      "DB worker: Failed to start new transaction");
          break;
        }
      } else {
        log_message(config, "DEBUG", "DB worker: Committed %d books",
                    batch_count);
        if (!db_begin_transaction(db_handle, config)) {
          log_message(config, "ERROR",
                      "DB worker: Failed to start new transaction");
          break;
        }
      }
      batch_count = 0;
      total_errors = 0;
    }

    // Освобождаем память
    free(result->filepath);
    if (result->archive_path)
      free(result->archive_path);
    if (result->internal_path)
      free(result->internal_path);
    if (result->file_hash)
      free(result->file_hash);
    if (result->meta)
      free_book_meta(result->meta);
    free(result);
  }

  // ============================================================
  // ФИНАЛЬНЫЙ COMMIT - ВАЖНО!
  // ============================================================
  if (batch_count > 0) {
    if (!db_commit_transaction(db_handle, config)) {
      log_message(config, "ERROR",
                  "DB worker: Final commit failed, rolling back");
      db_rollback_transaction(db_handle, config);
    } else {
      log_message(config, "INFO",
                  "DB worker: Final commit: %d books, %d archives", batch_count,
                  total_archives);
    }
  } else {
    // Если не было книг в последнем batch, но были архивы - всё равно делаем
    // COMMIT
    if (total_archives > 0) {
      if (!db_commit_transaction(db_handle, config)) {
        log_message(config, "ERROR",
                    "DB worker: Final commit (archives) failed");
        db_rollback_transaction(db_handle, config);
      } else {
        log_message(config, "INFO", "DB worker: Final commit (archives only)");
      }
    }
  }

  // Дополнительная проверка: если таблица пустая, делаем принудительный COMMIT
  if (db_handle->db_type == DB_MYSQL) {
    MySQLConnection *mysql_conn = (MySQLConnection *)db_handle->connection;
    if (mysql_conn && mysql_conn->mysql) {
      // Проверяем, есть ли данные
      if (mysql_query(mysql_conn->mysql, "SELECT COUNT(*) FROM books") == 0) {
        MYSQL_RES *res = mysql_store_result(mysql_conn->mysql);
        if (res) {
          MYSQL_ROW row = mysql_fetch_row(res);
          if (row && row[0]) {
            int count = atoi(row[0]);
            if (count == 0 && total_inserted > 0) {
              log_message(config, "WARNING",
                          "Table is empty despite %d inserts, forcing COMMIT",
                          total_inserted);
              mysql_query(mysql_conn->mysql, "COMMIT");
            }
          }
          mysql_free_result(res);
        }
      }
    }
  }

  log_message(config, "INFO",
              "DB worker finished: Inserted %d books, skipped %d duplicates, "
              "updated %d archives",
              total_inserted, total_skipped, total_archives);
  return NULL;
}

// ============================================================
// МНОГОПОТОЧНОЕ СКАНИРОВАНИЕ ДИРЕКТОРИЙ
// ============================================================
void scan_directory_multithreaded(const char *path, DatabaseHandle *db_handle,
                                  Config *config, int num_workers,
                                  int batch_size) {
  if (!path || !db_handle || !config) {
    log_message(config, "ERROR", "[SCAN_DIRECTORY] Invalid parameters");
    return;
  }

  log_message(config, "INFO", "Starting multithreaded scan: %s", path);
  log_message(config, "INFO", "Using %d file workers + 1 DB worker",
              num_workers);

  // ============================================================
  // 1. ИНИЦИАЛИЗАЦИЯ ОЧЕРЕДЕЙ
  // ============================================================
  TaskQueue task_queue;
  ResultQueue result_queue;
  task_queue_init(&task_queue);
  result_queue_init(&result_queue);

  // ============================================================
  // 2. ЗАПУСК ПОТОКОВ-ВОРКЕРОВ
  // ============================================================
  WorkerContext ctx;
  ctx.task_queue = &task_queue;
  ctx.result_queue = &result_queue;
  ctx.config = config;
  ctx.db_handle = db_handle;
  ctx.batch_size = batch_size;

  pthread_t *file_threads = malloc(num_workers * sizeof(pthread_t));
  if (!file_threads) {
    log_message(config, "ERROR", "Failed to allocate thread array");
    task_queue_shutdown(&task_queue);
    result_queue_shutdown(&result_queue);
    return;
  }

  pthread_t db_thread;

  // Запускаем file workers (КАЖДЫЙ создаст своё соединение)
  for (int i = 0; i < num_workers; i++) {
    if (pthread_create(&file_threads[i], NULL, file_worker_thread, &ctx) != 0) {
      log_message(config, "ERROR", "Failed to create file worker thread %d", i);
      num_workers = i;
      break;
    }
  }

  // Запускаем ОДИН DB worker
  if (pthread_create(&db_thread, NULL, db_worker_thread, &ctx) != 0) {
    log_message(config, "ERROR", "Failed to create DB worker thread");
    task_queue_shutdown(&task_queue);
    for (int i = 0; i < num_workers; i++) {
      pthread_join(file_threads[i], NULL);
    }
    free(file_threads);
    return;
  }

  // ============================================================
  // 3. ГЛАВНЫЙ ПОТОК — ОБХОД ДИРЕКТОРИЙ
  // ============================================================
  time_t start_time = time(NULL);
  int files_found = 0;
  int dirs_scanned = 0;

  // Рекурсивный обход (используем стек)
  char **dir_stack = NULL;
  int stack_size = 0;
  int stack_capacity = 64;

  dir_stack = malloc(sizeof(char *) * stack_capacity);
  if (!dir_stack) {
    log_message(config, "ERROR", "Failed to allocate directory stack");
    task_queue_shutdown(&task_queue);
    result_queue_shutdown(&result_queue);
    free(file_threads);
    return;
  }

  dir_stack[stack_size++] = strdup(path);

  while (stack_size > 0) {
    char *current_dir = dir_stack[--stack_size];
    DIR *dir = opendir(current_dir);

    if (!dir) {
      log_message(config, "WARNING", "Cannot open directory: %s", current_dir);
      free(current_dir);
      continue;
    }

    dirs_scanned++;
    struct dirent *entry;
    while ((entry = readdir(dir)) != NULL) {
      if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0) {
        continue;
      }

      char full_path[4096];
      snprintf(full_path, sizeof(full_path), "%s/%s", current_dir,
               entry->d_name);

      struct stat statbuf;
      if (stat(full_path, &statbuf) == -1) {
        continue;
      }

      if (S_ISDIR(statbuf.st_mode)) {
        if (stack_size >= stack_capacity) {
          stack_capacity *= 2;
          char **new_stack =
              realloc(dir_stack, sizeof(char *) * stack_capacity);
          if (!new_stack) {
            log_message(config, "ERROR", "Failed to reallocate stack");
            closedir(dir);
            free(current_dir);
            break;
          }
          dir_stack = new_stack;
        }
        dir_stack[stack_size++] = strdup(full_path);
      } else if (S_ISREG(statbuf.st_mode) &&
                 is_supported_format(entry->d_name)) {
        FileTask *task = calloc(1, sizeof(FileTask));
        if (!task) {
          log_message(config, "ERROR", "Failed to allocate FileTask");
          continue;
        }

        task->filepath = strdup(full_path);
        task->is_archive = is_archive_format(entry->d_name);
        task->file_size = statbuf.st_size;
        task->mtime = statbuf.st_mtime;

        if (task->is_archive) {
          task->archive_path = strdup(full_path);
          task->internal_path = NULL;
        }

        task_queue_push(&task_queue, task);
        files_found++;

        if (files_found % 100 == 0) {
          log_message(config, "DEBUG", "Found %d files, queue size: %d",
                      files_found, task_queue.count);
        }
      }
    }

    closedir(dir);
    free(current_dir);
  }

  // Очищаем стек
  for (int i = 0; i < stack_size; i++) {
    free(dir_stack[i]);
  }
  free(dir_stack);

  log_message(config, "INFO", "Files found: %d, directories scanned: %d",
              files_found, dirs_scanned);
  log_message(config, "INFO", "Waiting for workers to finish...");

  // ============================================================
  // 4. ЗАВЕРШЕНИЕ РАБОТЫ
  // ============================================================
  task_queue_shutdown(&task_queue);

  for (int i = 0; i < num_workers; i++) {
    pthread_join(file_threads[i], NULL);
  }

  result_queue_shutdown(&result_queue);
  pthread_join(db_thread, NULL);

  free(file_threads);

  time_t total_time = time(NULL) - start_time;
  double rate = (total_time > 0) ? (double)files_found / total_time : 0;

  log_message(config, "INFO",
              "=== MULTITHREADED SCAN COMPLETED ===\n"
              "  Path: %s\n"
              "  Directories scanned: %d\n"
              "  Files found: %d\n"
              "  Workers: %d\n"
              "  Time: %ld seconds\n"
              "  Rate: %.1f files/sec",
              path, dirs_scanned, files_found, num_workers, total_time, rate);
}
