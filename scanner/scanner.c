#include "scanner.h"
#include "common.h"
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
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

const char* supported_formats[SUPPORTED_FORMATS] = {
    ".epub", ".fb2", ".pdf", ".txt", ".zip", ".rar", ".7z", ".mobi" // +8-й элемент
};

// Определения функций проверки форматов
int is_supported_format(const char* filename)
{
    if (!filename)
        return 0;

    const char* ext = strrchr(filename, '.');
    if (!ext)
        return 0;

    for (int i = 0; i < SUPPORTED_FORMATS; i++) {
        if (strcasecmp(ext, supported_formats[i]) == 0) {
            return 1;
        }
    }
    return 0;
}

int is_archive_format(const char* filename)
{
    if (!filename)
        return 0;

    const char* ext = strrchr(filename, '.');
    if (!ext)
        return 0;

    return (strcasecmp(ext, ".zip") == 0 || strcasecmp(ext, ".rar") == 0 || strcasecmp(ext, ".7z") == 0 || strcasecmp(ext, ".tar") == 0 || strcasecmp(ext, ".gz") == 0 || strcasecmp(ext, ".bz2") == 0 || strcasecmp(ext, ".xz") == 0);
}

void scan_directory(const char* path, DatabaseHandle* db_handle,
    Config* config)
{
    DIR* dir = opendir(path);
    if (!dir) {
        log_message(config, "ERROR", "Cannot open directory: %s", path);
        return;
    }

    struct dirent* entry;
    while ((entry = readdir(dir)) != NULL) {
        if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0)
            continue;

        char full_path[4096];
        snprintf(full_path, sizeof(full_path), "%s/%s", path, entry->d_name);

        struct stat statbuf;
        if (stat(full_path, &statbuf) == -1) {
            log_message(config, "WARNING", "Cannot stat file: %s", full_path);
            continue;
        }

        if (S_ISDIR(statbuf.st_mode)) {
            log_message(config, "DEBUG", "Entering directory: %s", full_path);
            scan_directory(full_path, db_handle, config);
        } else if (S_ISREG(statbuf.st_mode)) {
            if (is_supported_format(entry->d_name)) {
                log_message(config, "INFO", "Processing file: %s", full_path);
                process_file(full_path, db_handle, config);
            } else {
                log_message(config, "DEBUG", "Skipping unsupported format: %s",
                    full_path);
            }
        }
    }

    closedir(dir);
}

void process_file(const char* filepath, DatabaseHandle* db_handle, Config* config)
{
    const char* ext = strrchr(filepath, '.');
    if (!ext)
        return;

    struct stat file_stat;
    if (stat(filepath, &file_stat) == -1) {
        log_message(config, "WARNING", "Cannot stat file: %s", filepath);
        return;
    }

    if (is_archive_format(filepath)) {
        log_message(config, "INFO", "Processing archive: %s", filepath);
        process_archive(filepath, db_handle, config);
    } else {
        log_message(config, "INFO", "Processing book: %s", filepath);
        BookMeta* meta = parse_metadata(filepath, ext + 1);
        if (meta) {
            meta->file_size = file_stat.st_size;

            // ВЫЧИСЛЯЕМ ХЕШ
            meta->file_hash = calculate_file_hash(filepath, config->scanner.hash_algorithm);
            if (!meta->file_hash) {
                log_message(config, "WARNING", "Failed to calculate hash for %s", filepath);
                meta->file_hash = strdup(""); // Пустая строка вместо NULL
            }

            insert_book_to_db(db_handle, filepath, meta, NULL, NULL, meta->file_hash, config);
            free_book_meta(meta);
        } else {
            log_message(config, "WARNING", "Failed to parse metadata for: %s", filepath);
        }
    }
}

void process_archive(const char* archive_path, DatabaseHandle* db_handle,
    Config* config)
{
    log_message(config, "DEBUG", "[PROCESS_ARCHIVE] Starting: %s", archive_path);

    // Получаем размер файла для информации
    struct stat archive_stat;
    if (stat(archive_path, &archive_stat) == 0) {
        double size_mb = archive_stat.st_size / (1024.0 * 1024.0);
        log_message(config, "INFO", "Processing archive: %s (%.2f MB)",
            archive_path, size_mb);
    }

    // Для очень больших архивов (>1GB) используем упрощенное хеширование
    int use_fast_hash = 0;
    if (archive_stat.st_size > 1024 * 1024 * 1024) { // > 1GB
        log_message(config, "INFO",
            "Large archive detected (>1GB), using fast comparison");
        use_fast_hash = 1;
    }

    char* archive_hash = NULL;

    if (use_fast_hash) {
        // Для больших файлов используем хеш только первых и последних 10MB
        archive_hash = calculate_fast_file_hash(archive_path, config->scanner.hash_algorithm);
    } else {
        archive_hash = calculate_file_hash(archive_path, config->scanner.hash_algorithm);
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

    struct archive* a;
    struct archive_entry* entry;
    int r;

    a = archive_read_new();
    archive_read_support_format_all(a);
    archive_read_support_filter_all(a);

    // Устанавливаем опции для обработки кодировок
    archive_read_set_options(a, "hdrcharset=UTF-8");

    r = archive_read_open_filename(a, archive_path, 10240);
    if (r != ARCHIVE_OK) {
        log_message(config, "ERROR", "Failed to open archive: %s - %s",
            archive_path, archive_error_string(a));
        archive_read_free(a);
        free(archive_hash);
        return;
    }

    int file_count = 0;
    long total_size = 0;
    time_t start_time = time(NULL);
    int error_count = 0;
    const int MAX_ERRORS = 10; // Максимальное количество ошибок перед остановкой

    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        const char* filename = archive_entry_pathname(entry);
        la_int64_t size = archive_entry_size(entry);

        if (!filename) {
            log_message(config, "WARNING", "Entry with NULL filename, skipping");
            archive_read_data_skip(a);
            continue;
        }

        // Показываем прогресс каждые 100 файлов
        if (file_count % 100 == 0 && file_count > 0) {
            time_t elapsed = time(NULL) - start_time;
            log_message(config, "DEBUG", "Processed %d files in %ld seconds",
                file_count, elapsed);
        }

        if (archive_entry_filetype(entry) != AE_IFREG) {
            archive_read_data_skip(a);
            continue;
        }

        // Для очень больших файлов внутри архива (>100MB) пропускаем
        if (size > 200 * 1024 * 1024) {
            log_message(config, "DEBUG",
                "Skipping very large file in archive: %s (%lld MB)", filename,
                size / (1024 * 1024));
            archive_read_data_skip(a);
            continue;
        }

        const char* ext = strrchr(filename, '.');
        if (!ext || !is_supported_format(filename)) {
            archive_read_data_skip(a);
            continue;
        }

        log_message(config, "INFO", "Found book in archive: %s/%s (size: %.2f MB)",
            archive_path, filename, size / (1024.0 * 1024.0));

        file_count++;

        // Защита от переполнения total_size
        if (total_size > LONG_MAX - size) {
            log_message(config, "WARNING",
                "Total size overflow (%ld + %lld), resetting counter",
                total_size, size);
            total_size = size; // Сбрасываем, но продолжаем подсчет
        } else {
            total_size += size;
        }

        // Для файлов больше 10MB используем потоковую обработку
        if (size > 10 * 1024 * 1024) {
            if (!process_large_archive_file(a, entry, archive_path, filename,
                    db_handle, config)) {
                error_count++;
                log_message(config, "WARNING",
                    "Failed to process large file: %s (error %d/%d)", filename,
                    error_count, MAX_ERRORS);
            }
        } else {
            if (!process_small_archive_file(a, entry, archive_path, filename,
                    db_handle, config)) {
                error_count++;
                log_message(config, "WARNING",
                    "Failed to process small file: %s (error %d/%d)", filename,
                    error_count, MAX_ERRORS);
            }
        }

        // Если слишком много ошибок, прекращаем обработку архива
        if (error_count >= MAX_ERRORS) {
            log_message(config, "ERROR",
                "Too many errors (%d) processing archive, aborting",
                error_count);
            break;
        }
    }

    // Проверяем, не было ли ошибок при чтении архива
    if (archive_errno(a) != 0) {
        log_message(config, "ERROR", "Archive read error: %s",
            archive_error_string(a));
    }

    archive_read_close(a);
    archive_read_free(a);

    // Обновляем информацию об архиве (даже если были ошибки, частично
    // обработанные файлы сохранятся)
    update_archive_info(db_handle, archive_path, archive_hash, file_count,
        total_size, config);
    free(archive_hash);

    time_t total_time = time(NULL) - start_time;
    log_message(
        config, "INFO",
        "Archive processed: %s (%d files, %.2f MB, %d errors) in %ld seconds",
        archive_path, file_count, total_size / (1024.0 * 1024.0), error_count,
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
char* calculate_fast_file_hash(const char* filepath, const char* algorithm)
{
    if (!filepath || !algorithm) {
        fprintf(stderr, "calculate_fast_file_hash: NULL parameters\n");
        return NULL;
    }

    FILE* file = safe_fopen(filepath, "rb", NULL);
    if (!file) {
        fprintf(stderr, "Cannot open file for fast hash: %s\n", filepath);
        return NULL;
    }

    // Выбор алгоритма
    const EVP_MD* md_algorithm = NULL;
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

    EVP_MD_CTX* mdctx = EVP_MD_CTX_new();
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
        while (remaining > 0 && (bytes_read = fread(buffer, 1, (remaining < (long)sizeof(buffer)) ? (size_t)remaining : sizeof(buffer), file)) > 0) {
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
            while (remaining > 0 && (bytes_read = fread(buffer, 1, (remaining < (long)sizeof(buffer)) ? (size_t)remaining : sizeof(buffer), file)) > 0) {
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
    char* hash_str = safe_malloc(hash_len * 2 + 1);
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
int process_small_archive_file(struct archive* a, struct archive_entry* entry,
    const char* archive_path, const char* filename,
    DatabaseHandle* db_handle, Config* config)
{
    la_int64_t size = archive_entry_size(entry);

    if (size > 10 * 1024 * 1024) {
        log_message(config, "WARNING", "File too large for small processing: %s", filename);
        archive_read_data_skip(a);
        return 0;
    }

    if (size > 100 * 1024 * 1024) {
        log_message(config, "ERROR", "File too large to allocate memory: %s (%lld MB)",
            filename, size / (1024 * 1024));
        archive_read_data_skip(a);
        return 0;
    }

    char* content = malloc(size + 1);
    if (!content) {
        log_message(config, "ERROR", "Failed to allocate %lld bytes for: %s", size, filename);
        archive_read_data_skip(a);
        return 0;
    }

    la_ssize_t bytes_read = archive_read_data(a, content, size);
    if (bytes_read != size) {
        log_message(config, "WARNING", "Failed to read file from archive: %s (read %zd of %lld)",
            filename, bytes_read, size);
        free(content);
        archive_read_data_skip(a);
        return 0;
    }
    content[size] = '\0';

    const char* ext = strrchr(filename, '.');
    BookMeta* meta = NULL;

    if (ext) {
        if (strcasecmp(ext + 1, "fb2") == 0) {
            meta = parse_fb2_from_memory(content, size);
        } else if (strcasecmp(ext + 1, "epub") == 0) {
            meta = parse_epub_from_memory(content, size);
        }
    }

    if (meta) {
        meta->file_size = size;

        // ВЫЧИСЛЯЕМ ХЕШ ДЛЯ ФАЙЛА ИЗ АРХИВА
        meta->file_hash = calculate_buffer_hash((unsigned char*)content, size,
            config->scanner.hash_algorithm);
        if (!meta->file_hash) {
            log_message(config, "WARNING", "Failed to calculate hash for archive file: %s", filename);
            meta->file_hash = strdup(""); // Пустая строка вместо NULL
        }

        // ПЕРЕДАЁМ ХЕШ (НЕ NULL)
        insert_book_to_db(db_handle, archive_path, meta, archive_path, filename,
            meta->file_hash, config);

        free_book_meta(meta);
        free(content);
        return 1;
    } else {
        log_message(config, "WARNING", "Failed to parse metadata for: %s", filename);
        free(content);
        return 0;
    }
}

// Функция для обработки больших файлов из архива - возвращает 1 при успехе, 0
// при ошибке
int process_large_archive_file(struct archive* a, struct archive_entry* entry,
    const char* archive_path, const char* filename,
    DatabaseHandle* db_handle, Config* config)
{
    (void)entry;

    log_message(config, "DEBUG", "Processing large file with streaming: %s", filename);

    char temp_path[] = "/tmp/archive_extract_XXXXXX";
    int fd = mkstemp(temp_path);
    if (fd == -1) {
        log_message(config, "ERROR", "Cannot create temp file for large archive entry: %s",
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
            log_message(config, "ERROR", "Failed to write temp file: %s", strerror(errno));
            write_error = 1;
            break;
        }
        total_written += written;

        if (total_written > 500 * 1024 * 1024) {
            log_message(config, "WARNING", "Extracted file too large (>500MB), stopping: %s", filename);
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

    const char* ext = strrchr(filename, '.');
    BookMeta* meta = NULL;

    if (ext) {
        if (strcasecmp(ext + 1, "fb2") == 0) {
            meta = parse_fb2(temp_path);
        } else if (strcasecmp(ext + 1, "epub") == 0) {
            meta = parse_epub(temp_path);
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
        meta->file_hash = calculate_file_hash(temp_path, config->scanner.hash_algorithm);
        if (!meta->file_hash) {
            log_message(config, "WARNING", "Failed to calculate hash for large file: %s", filename);
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
        log_message(config, "WARNING", "Failed to parse metadata for large file: %s", filename);
        return 0;
    }
}
