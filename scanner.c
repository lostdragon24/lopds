#include "common.h"
#include "scanner.h"
#include "metadata.h"
#include "utils.h"
#include <dirent.h>
#include <sys/stat.h>
#include <archive.h>
#include <archive_entry.h>
#include <string.h>

const char *supported_formats[SUPPORTED_FORMATS] = {
    ".epub", ".fb2", ".pdf", ".mobi", ".txt", ".zip", ".rar", ".7z"
};

void scan_directory(const char *path, DatabaseHandle *db_handle, Config *config) {
    DIR *dir = opendir(path);
    if (!dir) {
        log_message(config, "ERROR", "Cannot open directory: %s", path);
        return;
    }

    struct dirent *entry;
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
                log_message(config, "DEBUG", "Skipping unsupported format: %s", full_path);
            }
        }
    }

    closedir(dir);
}

void process_file(const char *filepath, DatabaseHandle *db_handle, Config *config) {
    const char *ext = strrchr(filepath, '.');
    if (!ext) return;

    struct stat file_stat;
    if (stat(filepath, &file_stat) == -1) {
        log_message(config, "WARNING", "Cannot stat file: %s", filepath);
        return;
    }

    log_message(config, "DEBUG", "[PROCESS_FILE] File: %s, Size: %ld", filepath, file_stat.st_size);

    log_message(config, "INFO", "Processing file: %s", filepath);

    if (is_archive_format(filepath)) {
        log_message(config, "INFO", "Processing archive: %s", filepath);
        process_archive(filepath, db_handle, config);
    } else {
        log_message(config, "DEBUG", "[PROCESS_FILE] Parsing metadata for: %s", filepath);
        BookMeta *meta = parse_metadata(filepath, ext + 1);
        if (meta) {
            meta->file_size = file_stat.st_size;
            log_message(config, "DEBUG", "[FILE] File size set to: %ld for %s", meta->file_size, filepath);

            log_message(config, "DEBUG", "[PROCESS_FILE] Inserting book to database: %s", filepath);
            insert_book_to_db(db_handle, filepath, meta, NULL, NULL, config);
            log_message(config, "DEBUG", "[PROCESS_FILE] Freeing book metadata for: %s", filepath);
            free_book_meta(meta);
            log_message(config, "DEBUG", "[PROCESS_FILE] Successfully processed: %s", filepath);
        } else {
            log_message(config, "WARNING", "Failed to parse metadata for: %s", filepath);
        }
    }
}

void process_archive(const char *archive_path, DatabaseHandle *db_handle, Config *config) {
    log_message(config, "DEBUG", "[PROCESS_ARCHIVE] Starting: %s", archive_path);

    char *archive_hash = calculate_file_hash(archive_path, config->scanner.hash_algorithm);
    if (!archive_hash) {
        log_message(config, "ERROR", "Cannot calculate hash for archive: %s", archive_path);
        return;
    }

    log_message(config, "DEBUG", "[PROCESS_ARCHIVE] Using %s hash: %s", config->scanner.hash_algorithm, archive_hash);

    if (!archive_needs_rescan(db_handle, archive_path, archive_hash, config)) {
        log_message(config, "DEBUG", "[PROCESS_ARCHIVE] Archive doesn't need rescan: %s", archive_path);
        free(archive_hash);
        return;
    }

    log_message(config, "INFO", "Processing archive: %s", archive_path);

    struct archive *a;
    struct archive_entry *entry;
    int r;

    a = archive_read_new();
    archive_read_support_format_all(a);
    archive_read_support_filter_all(a);

    r = archive_read_open_filename(a, archive_path, 10240);
    if (r != ARCHIVE_OK) {
        log_message(config, "ERROR", "Failed to open archive: %s", archive_path);
        archive_read_free(a);
        free(archive_hash);
        return;
    }

    int file_count = 0;
    long total_size = 0;

    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        const char *filename = archive_entry_pathname(entry);
        la_int64_t size = archive_entry_size(entry);

        if (archive_entry_filetype(entry) != AE_IFREG || size > 10485760) {
            archive_read_data_skip(a);
            continue;
        }

        const char *ext = strrchr(filename, '.');
        if (!ext || !is_supported_format(filename)) {
            archive_read_data_skip(a);
            continue;
        }

        log_message(config, "INFO", "Found book in archive: %s/%s (size: %lld)", archive_path, filename, size);

        file_count++;
        total_size += size;

        size_t content_size = (size_t)size;
        char *content = malloc(content_size + 1);
        if (!content) {
            log_message(config, "WARNING", "Failed to allocate memory for: %s", filename);
            archive_read_data_skip(a);
            continue;
        }

        la_ssize_t bytes_read = archive_read_data(a, content, content_size);
        if (bytes_read != size) {
            log_message(config, "WARNING", "Failed to read file from archive: %s (read %zd of %lld bytes)",
                       filename, bytes_read, size);
            free(content);
            archive_read_data_skip(a);
            continue;
        }

        content[content_size] = '\0';

        BookMeta *meta = NULL;
        if (strcasecmp(ext + 1, "fb2") == 0) {
            meta = parse_fb2_from_memory(content, content_size);
        } else {
            meta = calloc(1, sizeof(BookMeta));
            if (meta) {
                const char *base_name = strrchr(filename, '/');
                base_name = base_name ? base_name + 1 : filename;
                const char *dot = strrchr(base_name, '.');
                if (dot) {
                    meta->title = strndup(base_name, dot - base_name);
                } else {
                    meta->title = strdup(base_name);
                }
            }
        }

        free(content);

        if (meta) {
            meta->file_size = size;
            log_message(config, "DEBUG", "[ARCHIVE] File size set to: %ld for %s", meta->file_size, filename);

            insert_book_to_db(db_handle, archive_path, meta, archive_path, filename, config);
            free_book_meta(meta);
        } else {
            log_message(config, "WARNING", "Failed to parse metadata for archive file: %s/%s",
                       archive_path, filename);
        }
    }

    archive_read_close(a);
    archive_read_free(a);

    update_archive_info(db_handle, archive_path, archive_hash, file_count, total_size, config);
    free(archive_hash);
}

int is_archive_format(const char *filename) {
    const char *ext = strrchr(filename, '.');
    if (!ext) return 0;

    return (strcasecmp(ext, ".zip") == 0 ||
            strcasecmp(ext, ".rar") == 0 ||
            strcasecmp(ext, ".7z") == 0);
}

int is_supported_format(const char *filename) {
    if (!filename) return 0;

    const char *ext = strrchr(filename, '.');
    if (!ext) return 0;

    for (int i = 0; i < SUPPORTED_FORMATS; i++) {
        if (strcasecmp(ext, supported_formats[i]) == 0) {
            return 1;
        }
    }
    return 0;
}
