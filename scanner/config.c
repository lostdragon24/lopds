#include "config.h"
#include "common.h"
#include "utils.h"
#include <errno.h>
#include <limits.h>
#include <stdarg.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

#ifdef __APPLE__
#include <mach-o/dyld.h>
#include <pwd.h>
#include <unistd.h>
#elif defined(_WIN32)
#include <windows.h>
#define strcasecmp _stricmp
#define strncasecmp _strnicmp
#define snprintf _snprintf
#define access _access
#define R_OK 04
#else
#include <pwd.h>
#include <unistd.h>
#endif

#ifndef PATH_MAX
#ifdef _WIN32
#define PATH_MAX _MAX_PATH
#else
#define PATH_MAX 4096
#endif
#endif

#ifndef MAX_PATH
#define MAX_PATH PATH_MAX
#endif

// УБИРАЕМ static и используем функцию из utils.h
// static char* safe_strdup(const char* s) {
//     if (!s) return NULL;
//     size_t len = strlen(s) + 1;
//     char* p = malloc(len);
//     return p ? memcpy(p, s, len) : NULL;
// }

// Вспомогательная: получить домашнюю директорию
static const char *get_home_dir(void) {
#ifdef _WIN32
  const char *home = getenv("USERPROFILE");
  if (!home)
    home = getenv("HOMEDRIVE");
  return home;
#else
  const char *home = getenv("HOME");
  if (!home) {
    struct passwd *pwd = getpwuid(getuid());
    if (pwd)
      home = pwd->pw_dir;
  }
  return home;
#endif
}

// Вспомогательная: XDG config dir (Linux/macOS)
static const char *get_xdg_config_home(void) {
#ifdef _WIN32
  return NULL;
#else
  const char *xdg = getenv("XDG_CONFIG_HOME");
  if (xdg && xdg[0] == '/')
    return xdg;
  const char *home = get_home_dir();
  if (home) {
    static char xdg_path[PATH_MAX];
    safe_snprintf(xdg_path, sizeof(xdg_path), "%s/.config", home);
    return xdg_path;
  }
  return NULL;
#endif
}

// Вспомогательная: получить директорию исполняемого файла
static char *get_executable_dir(void) {
  static char exec_dir[PATH_MAX] = {0};

#ifdef __linux__
  ssize_t len = readlink("/proc/self/exe", exec_dir, sizeof(exec_dir) - 1);
  if (len > 0) {
    exec_dir[len] = '\0';
    char *last_slash = strrchr(exec_dir, '/');
    if (last_slash) {
      *last_slash = '\0';
      return exec_dir;
    }
  }
#elif defined(__APPLE__)
  uint32_t size = sizeof(exec_dir);
  if (_NSGetExecutablePath(exec_dir, &size) == 0) {
    char real_path[PATH_MAX];
    if (realpath(exec_dir, real_path)) {
      char *last_slash = strrchr(real_path, '/');
      if (last_slash) {
        *last_slash = '\0';
        strncpy(exec_dir, real_path, sizeof(exec_dir) - 1);
        exec_dir[sizeof(exec_dir) - 1] = '\0';
        return exec_dir;
      }
    }
  }
#elif defined(_WIN32)
  if (GetModuleFileNameA(NULL, exec_dir, sizeof(exec_dir))) {
    char *last_sep = strrchr(exec_dir, '\\');
    if (!last_sep)
      last_sep = strrchr(exec_dir, '/');
    if (last_sep) {
      *last_sep = '\0';
      return exec_dir;
    }
  }
#endif

  return NULL;
}

// === ОСНОВНАЯ ФУНКЦИЯ поиска конфига ===
char *find_config_file(void) {
  char possible_paths[16][PATH_MAX];
  int count = 0;

  // Инициализируем массив
  memset(possible_paths, 0, sizeof(possible_paths));

  // 1. Текущая директория
  safe_snprintf(possible_paths[count++], PATH_MAX, "./config.ini");
  safe_snprintf(possible_paths[count++], PATH_MAX, "./config/config.ini");

  // 2. XDG (Linux/macOS)
  const char *xdg = get_xdg_config_home();
  if (xdg) {
    safe_snprintf(possible_paths[count++], PATH_MAX,
                  "%s/book_scanner/config.ini", xdg);
  }

  // 3. ~/.config fallback
  const char *home = get_home_dir();
  if (home) {
    safe_snprintf(possible_paths[count++], PATH_MAX,
                  "%s/.config/book_scanner/config.ini", home);
    safe_snprintf(possible_paths[count++], PATH_MAX,
                  "%s/.book_scanner/config.ini", home);
  }

  // 4. Системный путь (только Unix)
#ifndef _WIN32
  safe_snprintf(possible_paths[count++], PATH_MAX,
                "/etc/book_scanner/config.ini");
  safe_snprintf(possible_paths[count++], PATH_MAX, "/etc/config.ini");
#endif

  // 5. Директория исполняемого файла
  char *exec_dir = get_executable_dir();
  if (exec_dir) {
    safe_snprintf(possible_paths[count++], PATH_MAX, "%s/config.ini", exec_dir);
    safe_snprintf(possible_paths[count++], PATH_MAX, "%s/config/config.ini",
                  exec_dir);
  }

  // Ищем первый существующий файл
  for (int i = 0; i < count && i < 16; i++) {
    if (access(possible_paths[i], R_OK) == 0) {
      fprintf(stderr, "Found config at: %s\n", possible_paths[i]);
      return safe_strdup(
          possible_paths[i]);
    }
  }

  fprintf(stderr, "No config file found in %d locations\n", count);
  return NULL;
}

// === ЧТЕНИЕ КОНФИГУРАЦИИ ===
Config *read_config(const char *config_path) {
  char actual_config_path[MAX_PATH];

  // Если путь абсолютный или относительный
  if (!config_path) {
    fprintf(stderr, "Config path is NULL\n");
    return NULL;
  }

#ifdef _WIN32
  // Проверка абсолютного пути на Windows
  if (strlen(config_path) >= 3 &&
      ((config_path[1] == ':' && config_path[2] == '\\') ||
       (config_path[0] == '\\' && config_path[1] == '\\'))) {
    safe_snprintf(actual_config_path, sizeof(actual_config_path), "%s",
                  config_path);
  } else
#else
  if (config_path[0] == '/') {
    safe_snprintf(actual_config_path, sizeof(actual_config_path), "%s",
                  config_path);
  } else
#endif
  {
    // Относительный путь - ищем относительно директории исполняемого файла
    char *exec_dir = get_executable_dir();
    if (exec_dir) {
      safe_snprintf(actual_config_path, sizeof(actual_config_path), "%s/%s",
                    exec_dir, config_path);
    } else {
      safe_snprintf(actual_config_path, sizeof(actual_config_path), "%s",
                    config_path);
    }
  }

  // Проверяем длину пути
  if (strlen(actual_config_path) >= sizeof(actual_config_path)) {
    fprintf(stderr, "Config path too long: %s\n", actual_config_path);
    return NULL;
  }

  FILE *file = fopen(actual_config_path, "r");
  if (!file) {
    fprintf(stderr, "Cannot open config file: %s\n", actual_config_path);
    return NULL;
  }

  Config *config = calloc(1, sizeof(Config));
  if (!config) {
    fclose(file);
    return NULL;
  }

  // Устанавливаем значения по умолчанию
  config->database.type = safe_strdup("sqlite");
  if (!config->database.type) {
    fclose(file);
    free(config);
    return NULL;
  }

  config->database.port = 0;
  config->scanner.log_file = NULL;
  config->scanner.rescan_unchanged = 0;
  config->scanner.enable_inpx = 0;
  config->scanner.clear_database_inpx = 0;
  config->scanner.hash_algorithm = safe_strdup("md5");
  if (!config->scanner.hash_algorithm) {
    fclose(file);
    free(config->database.type);
    free(config);
    return NULL;
  }

  config->scanner.log_level = LOG_INFO;
  config->log_stream = stderr;

  char line[MAX_LINE];
  char current_section[64] = {0};

  while (fgets(line, sizeof(line), file)) {
    char *trimmed = line;
    while (*trimmed == ' ' || *trimmed == '\t')
      trimmed++;

    char *end = trimmed + strlen(trimmed) - 1;
    while (end > trimmed &&
           (*end == '\n' || *end == '\r' || *end == ' ' || *end == '\t')) {
      *end = '\0';
      end--;
    }

    if (strlen(trimmed) == 0 || trimmed[0] == '#' || trimmed[0] == ';') {
      continue;
    }

    if (trimmed[0] == '[' && trimmed[strlen(trimmed) - 1] == ']') {
      strncpy(current_section, trimmed + 1, strlen(trimmed) - 2);
      current_section[strlen(trimmed) - 2] = '\0';
      continue;
    }

    char *equals = strchr(trimmed, '=');
    if (!equals)
      continue;

    *equals = '\0';
    char *key = trimmed;
    char *value = equals + 1;

    while (*key == ' ' || *key == '\t')
      key++;
    char *key_end = key + strlen(key) - 1;
    while (key_end > key && (*key_end == ' ' || *key_end == '\t')) {
      *key_end = '\0';
      key_end--;
    }

    while (*value == ' ' || *value == '\t')
      value++;
    char *value_end = value + strlen(value) - 1;
    while (value_end > value && (*value_end == ' ' || *value_end == '\t')) {
      *value_end = '\0';
      value_end--;
    }

    if (strcmp(current_section, "database") == 0) {
      if (strcmp(key, "type") == 0) {
        free(config->database.type);
        config->database.type = safe_strdup(value);
      } else if (strcmp(key, "path") == 0) {
        config->database.path = safe_strdup(value);
      } else if (strcmp(key, "socket") == 0) {
        config->database.socket = safe_strdup(value);
      } else if (strcmp(key, "flags") == 0) {
        config->database.flags = atoi(value);
      } else if (strcmp(key, "host") == 0) {
        config->database.host = safe_strdup(value);
      } else if (strcmp(key, "user") == 0) {
        config->database.user = safe_strdup(value);
      } else if (strcmp(key, "password") == 0) {
        config->database.password = safe_strdup(value);
      } else if (strcmp(key, "database") == 0) {
        config->database.database = safe_strdup(value);
      } else if (strcmp(key, "port") == 0) {
        config->database.port = atoi(value);
      }
    } else if (strcmp(current_section, "scanner") == 0) {
      if (strcmp(key, "books_dir") == 0) {
        config->scanner.books_dir = safe_strdup(value);
      } else if (strcmp(key, "hash_algorithm") == 0) {
        free(config->scanner.hash_algorithm);
        config->scanner.hash_algorithm = safe_strdup(value);
      } else if (strcmp(key, "log_file") == 0) {
        if (strcasecmp(value, "NULL") != 0 &&
            strcasecmp(value, "STDERR") != 0) {
          config->scanner.log_file = safe_strdup(value);
        }
      } else if (strcmp(key, "rescan_unchanged") == 0) {
        config->scanner.rescan_unchanged =
            (strcasecmp(value, "yes") == 0 || strcasecmp(value, "true") == 0 ||
             strcmp(value, "1") == 0);
      } else if (strcmp(key, "enable_inpx") == 0) {
        config->scanner.enable_inpx =
            (strcasecmp(value, "yes") == 0 || strcasecmp(value, "true") == 0 ||
             strcmp(value, "1") == 0);
      } else if (strcmp(key, "clear_database_inpx") == 0) {
        config->scanner.clear_database_inpx =
            (strcasecmp(value, "yes") == 0 || strcasecmp(value, "true") == 0 ||
             strcmp(value, "1") == 0);
      } else if (strcmp(key, "log_level") == 0) {
        if (strcasecmp(value, "debug") == 0) {
          config->scanner.log_level = LOG_DEBUG;
        } else if (strcasecmp(value, "info") == 0) {
          config->scanner.log_level = LOG_INFO;
        } else if (strcasecmp(value, "warning") == 0) {
          config->scanner.log_level = LOG_WARNING;
        } else if (strcasecmp(value, "error") == 0) {
          config->scanner.log_level = LOG_ERROR;
        }
      }
    }
  }

  fclose(file);

  if (config->scanner.log_file) {
#ifdef _WIN32
    config->log_stream = fopen(config->scanner.log_file, "a");
#else
    config->log_stream = fopen(config->scanner.log_file, "a");
#endif
    if (!config->log_stream) {
      fprintf(stderr, "Cannot open log file: %s\n", config->scanner.log_file);
      config->log_stream = stderr;
    }
  }

  return config;
}

void log_message(Config *config, const char *level, const char *format, ...) {
  if (!config || !config->log_stream) {
    return;
  }

  LogLevel message_level;
  if (strcmp(level, "DEBUG") == 0)
    message_level = LOG_DEBUG;
  else if (strcmp(level, "INFO") == 0)
    message_level = LOG_INFO;
  else if (strcmp(level, "WARNING") == 0)
    message_level = LOG_WARNING;
  else if (strcmp(level, "ERROR") == 0)
    message_level = LOG_ERROR;
  else
    message_level = LOG_INFO;

  if (message_level < config->scanner.log_level) {
    return;
  }

  time_t now = time(NULL);
  struct tm *tm_info = localtime(&now);
  char timestamp[20];
  strftime(timestamp, sizeof(timestamp), "%Y-%m-%d %H:%M:%S", tm_info);

  fprintf(config->log_stream, "[%s] %s: ", timestamp, level);

  va_list args;
  va_start(args, format);
  vfprintf(config->log_stream, format, args);
  va_end(args);

  fprintf(config->log_stream, "\n");
  fflush(config->log_stream);
}

void free_config(Config *config) {
  if (!config)
    return;

  free(config->database.type);
  free(config->database.path);
  free(config->database.host);
  free(config->database.user);
  free(config->database.password);
  free(config->database.database);
  free(config->database.socket);
  free(config->scanner.books_dir);
  free(config->scanner.log_file);
  free(config->scanner.hash_algorithm);

  if (config->log_stream && config->log_stream != stderr) {
    fclose(config->log_stream);
  }

  free(config);
}
