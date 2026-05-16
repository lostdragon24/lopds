#ifndef CONFIG_H
#define CONFIG_H

#include <stdio.h>

#define MAX_LINE 1024

// Уровни логирования
typedef enum {
  LOG_DEBUG = 0,
  LOG_INFO = 1,
  LOG_WARNING = 2,
  LOG_ERROR = 3
} LogLevel;

typedef struct {
  char *type;
  char *path;
  char *host;
  char *user;
  char *password;
  char *database;
  int port;
  char *socket;
  int flags;
} DatabaseConfig;

typedef struct {
  char *books_dir;
  char *log_file;
  int rescan_unchanged;
  int enable_inpx;
  int clear_database_inpx;
  char *hash_algorithm;
  LogLevel log_level;
} ScannerConfig;

typedef struct {
  DatabaseConfig database;
  ScannerConfig scanner;
  FILE *log_stream;
} Config;

Config *read_config(const char *config_path);
char *find_config_file();
void free_config(Config *config);
void log_message(Config *config, const char *level, const char *format, ...);

#endif
