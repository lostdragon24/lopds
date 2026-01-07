#include "scanner_integration.h"
#include "inpx_parser.h"
#include "utils.h"
#include <stdlib.h>
#include <string.h>
#include <dirent.h>
#include <unistd.h>

char* find_inpx_file(const char *books_dir) {
    if (!books_dir) {
        return NULL;
    }

    DIR *dir = opendir(books_dir);
    if (!dir) {
        return NULL;
    }

    struct dirent *entry;
    char *found_path = NULL;

    while ((entry = readdir(dir)) != NULL && !found_path) {
        if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0)
            continue;

        char full_path[4096];
        snprintf(full_path, sizeof(full_path), "%s/%s", books_dir, entry->d_name);

        struct stat statbuf;
        if (stat(full_path, &statbuf) == -1) {
            continue;
        }

        if (S_ISDIR(statbuf.st_mode)) {
            char *subdir_found = find_inpx_file(full_path);
            if (subdir_found) {
                closedir(dir);
                return subdir_found;
            }
        } else if (S_ISREG(statbuf.st_mode)) {
            const char *ext = strrchr(entry->d_name, '.');
            if (ext && strcasecmp(ext, ".inpx") == 0) {
                found_path = strdup(full_path);
                break;
            }
        }
    }
    closedir(dir);

    return found_path;
}

int clear_database(DatabaseHandle *db_handle, Config *config) {
    if (!db_handle || !db_handle->connection) {
        log_message(config, "ERROR", "Database handle or connection is NULL");
        return 0;
    }

    const char *tables[] = {"books", "archives", NULL};

    for (int i = 0; tables[i]; i++) {
        char sql[256];

        if (db_handle->db_type == DB_MYSQL) {
            if (!db_execute(db_handle, "SET FOREIGN_KEY_CHECKS = 0", config)) {
                log_message(config, "WARNING", "Failed to disable foreign key checks");
            }

            snprintf(sql, sizeof(sql), "DELETE FROM %s", tables[i]);
        } else {
            snprintf(sql, sizeof(sql), "DELETE FROM %s", tables[i]);
        }

        if (!db_execute(db_handle, sql, config)) {
            log_message(config, "ERROR", "Failed to clear table: %s", tables[i]);

            if (db_handle->db_type == DB_MYSQL) {
                db_execute(db_handle, "SET FOREIGN_KEY_CHECKS = 1", config);
            }
            return 0;
        }
    }

    if (db_handle->db_type == DB_MYSQL) {
        if (!db_execute(db_handle, "SET FOREIGN_KEY_CHECKS = 1", config)) {
            log_message(config, "WARNING", "Failed to enable foreign key checks");
        }
    }

    log_message(config, "INFO", "Database cleared successfully");
    return 1;
}

int process_inpx_if_enabled(DatabaseHandle *db_handle, Config *config) {
    if (!config->scanner.enable_inpx) {
        log_message(config, "DEBUG", "INPX scanner disabled");
        return -1;
    }

    log_message(config, "INFO", "INPX scanner enabled, looking for INPX files...");

    char *inpx_file = find_inpx_file(config->scanner.books_dir);
    if (!inpx_file) {
        log_message(config, "INFO", "No INPX file found in books directory, proceeding with regular scan");
        return -1;
    }

    log_message(config, "INFO", "Found INPX file: %s", inpx_file);

    if (config->scanner.clear_database_inpx) {
        log_message(config, "INFO", "Clearing database before INPX import");
        if (!clear_database(db_handle, config)) {
            log_message(config, "ERROR", "Failed to clear database, aborting INPX import");
            free(inpx_file);
            return -1;
        }
    }

    log_message(config, "INFO", "Starting INPX import from: %s", inpx_file);
    int imported_count = import_inpx_collection(inpx_file, db_handle, config);

    free(inpx_file);

    return imported_count;
}
