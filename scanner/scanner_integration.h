#ifndef SCANNER_INTEGRATION_H
#define SCANNER_INTEGRATION_H

#include "config.h"
#include "database.h"

// Функции интеграции INPX
int process_inpx_if_enabled(DatabaseHandle *db_handle, Config *config);
char* find_inpx_file(const char *books_dir);
int clear_database(DatabaseHandle *db_handle, Config *config);

#endif
