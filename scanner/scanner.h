#ifndef SCANNER_H
#define SCANNER_H

#include "config.h"
#include "database.h"


#define SUPPORTED_FORMATS 8

extern const char *supported_formats[SUPPORTED_FORMATS];

void scan_directory(const char *path, DatabaseHandle *db_handle, Config *config);
void process_file(const char *filepath, DatabaseHandle *db_handle, Config *config);
void process_archive(const char *archive_path, DatabaseHandle *db_handle, Config *config);
int is_supported_format(const char *filename);
int is_archive_format(const char *filename);

#endif
