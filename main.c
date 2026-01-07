#include "common.h"
#include "config.h"
#include "database.h"
#include "scanner.h"
#include "scanner_integration.h"
#include "utils.h"
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

int main(int argc, char *argv[]) {
    log_message(NULL, "INFO", "=== SCANNER STARTING ===");

    char *config_path;

    if (argc == 1) {
        config_path = find_config_file();
        if (!config_path) {
            log_message(NULL, "ERROR", "No config file specified and no default config found");
            return 1;
        }
        log_message(NULL, "INFO", "Using auto-detected config file: %s", config_path);
    } else {
        config_path = strdup(argv[1]);
    }

    log_message(NULL, "DEBUG", "Reading config from: %s", config_path);
    Config *config = read_config(config_path);
    free(config_path);

    if (!config) {
        log_message(NULL, "ERROR", "Failed to read config file");
        return 1;
    }

    log_message(config, "DEBUG", "=== CONFIGURATION DEBUG ===");
    log_message(config, "DEBUG", "DB Type: %s", config->database.type ? config->database.type : "NULL");
    if (config->database.type && strcmp(config->database.type, "mysql") == 0) {
        log_message(config, "DEBUG", "MySQL Host: %s", config->database.host ? config->database.host : "NULL");
        log_message(config, "DEBUG", "MySQL User: %s", config->database.user ? config->database.user : "NULL");
        log_message(config, "DEBUG", "MySQL Database: %s", config->database.database ? config->database.database : "NULL");
        log_message(config, "DEBUG", "MySQL Port: %d", config->database.port);
    }
    log_message(config, "DEBUG", "Books Dir: %s", config->scanner.books_dir ? config->scanner.books_dir : "NULL");
    log_message(config, "DEBUG", "===========================");

    if (!config->scanner.books_dir) {
        log_message(config, "ERROR", "books_dir is not specified in config");
        free_config(config);
        return 1;
    }

    log_message(config, "DEBUG", "Connecting to database...");
    DatabaseHandle *db_handle = db_connect(config);
    if (!db_handle) {
        log_message(config, "ERROR", "Failed to connect to database!");
        free_config(config);
        return 1;
    } else {
        log_message(config, "INFO", "Connected to database");
    }

    log_message(config, "DEBUG", "Creating database tables...");
    if (!create_database_tables(db_handle, config)) {
        log_message(config, "ERROR", "Failed to create database tables!");
        db_close(db_handle);
        free_config(config);
        return 1;
    } else {
        log_message(config, "INFO", "Database tables created");
    }

    log_message(config, "DEBUG", "Starting INPX processing...");
    int inpx_imported = process_inpx_if_enabled(db_handle, config);

    if (inpx_imported == -1) {
        log_message(config, "DEBUG", "Starting regular directory scan...");
        scan_directory(config->scanner.books_dir, db_handle, config);
    } else {
        log_message(config, "DEBUG", "INPX processing completed - imported %d books", inpx_imported);
        if (inpx_imported == 0) {
            log_message(config, "DEBUG", "No books imported from INPX, but INPX file was processed");
        }
    }

    if (inpx_imported == 0) {
        log_message(config, "DEBUG", "Starting regular directory scan...");
        scan_directory(config->scanner.books_dir, db_handle, config);
    } else {
        log_message(config, "DEBUG", "Skipping regular scan - imported %d books from INPX", inpx_imported);
    }

    log_message(config, "INFO", "Book scanning completed");

    db_close(db_handle);
    free_config(config);

    log_message(NULL, "INFO", "=== SCANNER FINISHED ===");
    return 0;
}
