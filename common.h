#ifndef COMMON_H
#define COMMON_H

// ============================================================================
// КРОССПЛАТФОРМЕННЫЕ НАСТРОЙКИ
// ============================================================================

// Для Unix-систем включаем POSIX расширения
#ifndef _WIN32
#ifndef _POSIX_C_SOURCE
#define _POSIX_C_SOURCE 200809L
#endif

#ifndef _GNU_SOURCE
#define _GNU_SOURCE
#endif
#endif

// ============================================================================
// СТАНДАРТНЫЕ ЗАГОЛОВОЧНЫЕ ФАЙЛЫ
// ============================================================================

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <ctype.h>
#include <stdarg.h>
#include <errno.h>

// ============================================================================
// ПЛАТФОРМО-ЗАВИСИМЫЕ ЗАГОЛОВКИ
// ============================================================================

#ifndef _WIN32
// Unix/Linux/macOS/BSD системы
#include <unistd.h>
#include <sys/stat.h>
#include <dirent.h>
#include <fcntl.h>
#include <sys/file.h>
#include <pwd.h>
#include <grp.h>
#else
// Windows системы
#include <windows.h>
#include <direct.h>
#include <io.h>
#include <process.h>

// Определяем Unix-совместимые константы для Windows
#define R_OK 04
#define W_OK 02
#define X_OK 01
#define F_OK 00

// Сигнатуры (заглушки для совместимости)
#define S_ISDIR(mode) (((mode) & S_IFMT) == S_IFDIR)
#define S_ISREG(mode) (((mode) & S_IFMT) == S_IFREG)
#define S_IFMT 0170000
#define S_IFDIR 0040000
#define S_IFREG 0100000

// Windows-специфичные макросы для совместимости
#define strcasecmp _stricmp
#define strncasecmp _strnicmp
#define snprintf _snprintf
#define vsnprintf _vsnprintf

// Эмуляция Unix-флагов для open/access
#ifndef O_RDONLY
#define O_RDONLY _O_RDONLY
#endif

#ifndef O_WRONLY
#define O_WRONLY _O_WRONLY
#endif

#ifndef O_RDWR
#define O_RDWR _O_RDWR
#endif

#ifndef O_CREAT
#define O_CREAT _O_CREAT
#endif

#ifndef O_TRUNC
#define O_TRUNC _O_TRUNC
#endif

#ifndef O_APPEND
#define O_APPEND _O_APPEND
#endif

// Эмуляция flock для Windows
struct flock {
    short l_type;   /* Тип блокировки: F_RDLCK, F_WRLCK, F_UNLCK */
    short l_whence; /* Как интерпретировать l_start: SEEK_SET, SEEK_CUR, SEEK_END */
    off_t l_start;  /* Смещение для начала блокировки */
    off_t l_len;    /* Количество байтов для блокировки */
    pid_t l_pid;    /* PID процесса, владеющего блокировкой (F_GETLK only) */
};

#define F_RDLCK 0
#define F_WRLCK 1
#define F_UNLCK 2
#define F_GETLK 3
#define F_SETLK 4
#define F_SETLKW 5

// Windows версия fcntl для flock
static inline int fcntl(int fd, int cmd, ...) {
    va_list ap;
    va_start(ap, cmd);

    if (cmd == F_SETLK || cmd == F_SETLKW) {
        struct flock* lock = va_arg(ap, struct flock*);
        OVERLAPPED ov = {0};

        if (lock->l_type == F_WRLCK) {
            // Эксклюзивная блокировка
            if (LockFileEx((HANDLE)_get_osfhandle(fd), LOCKFILE_EXCLUSIVE_LOCK,
                          0, lock->l_len, 0, &ov)) {
                return 0;
            }
        } else if (lock->l_type == F_RDLCK) {
            // Разделяемая блокировка
            if (LockFileEx((HANDLE)_get_osfhandle(fd), 0, 0, lock->l_len, 0, &ov)) {
                return 0;
            }
        } else if (lock->l_type == F_UNLCK) {
            // Разблокировка
            if (UnlockFileEx((HANDLE)_get_osfhandle(fd), 0, lock->l_len, 0, &ov)) {
                return 0;
            }
        }
        return -1;
    }

    va_end(ap);
    return -1;
}

#endif // _WIN32

// ============================================================================
// СТАНДАРТНЫЕ КОНСТАНТЫ И МАКРОСЫ
// ============================================================================

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

// Макросы для безопасности строк
#define STR_SAFE_COPY(dest, src, size) \
    do { \
        strncpy((dest), (src), (size) - 1); \
        (dest)[(size) - 1] = '\0'; \
    } while(0)

#define STR_SAFE_CAT(dest, src, size) \
    do { \
        size_t dest_len = strlen(dest); \
        size_t src_len = strlen(src); \
        if (dest_len + src_len < (size) - 1) { \
            strcat(dest, src); \
        } else { \
            strncat(dest, src, (size) - dest_len - 1); \
            (dest)[(size) - 1] = '\0'; \
        } \
    } while(0)

// ============================================================================
// ЛОГИРОВАНИЕ И ОТЛАДКА
// ============================================================================

#ifndef LOGGING_H
#define LOGGING_H

#include "config.h"

// Макросы для удобного логирования
#define LOG_DEBUG(config, ...) log_message(config, "DEBUG", __VA_ARGS__)
#define LOG_INFO(config, ...) log_message(config, "INFO", __VA_ARGS__)
#define LOG_WARNING(config, ...) log_message(config, "WARNING", __VA_ARGS__)
#define LOG_ERROR(config, ...) log_message(config, "ERROR", __VA_ARGS__)

// Макрос для отладочных сообщений (только при DEBUG режиме)
#ifdef DEBUG
#define DBG(...) printf("DEBUG: " __VA_ARGS__)
#else
#define DBG(...) ((void)0)  // ничего не делать в release
#endif

// Макрос для логов без конфигурации (до её инициализации)
#define LOG_EARLY(level, ...) \
    do { \
        time_t now = time(NULL); \
        struct tm *tm_info = localtime(&now); \
        char timestamp[20]; \
        strftime(timestamp, sizeof(timestamp), "%Y-%m-%d %H:%M:%S", tm_info); \
        fprintf(stderr, "[%s] %s: ", timestamp, level); \
        fprintf(stderr, __VA_ARGS__); \
        fprintf(stderr, "\n"); \
    } while(0)

#define LOG_EARLY_DEBUG(...) LOG_EARLY("DEBUG", __VA_ARGS__)
#define LOG_EARLY_INFO(...) LOG_EARLY("INFO", __VA_ARGS__)
#define LOG_EARLY_WARNING(...) LOG_EARLY("WARNING", __VA_ARGS__)
#define LOG_EARLY_ERROR(...) LOG_EARLY("ERROR", __VA_ARGS__)

#endif // LOGGING_H

// ============================================================================
// ТИПЫ ДАННЫХ ДЛЯ КРОССПЛАТФОРМЕННОСТИ
// ============================================================================

#ifdef _WIN32
// Типы для Windows совместимости
typedef long long ssize_t;
typedef unsigned long long size_t;
#else
// Стандартные типы для Unix
#include <sys/types.h>
#endif

// Кроссплатформенный тип для дескрипторов файлов
#ifndef _WIN32
typedef int file_handle_t;
#else
typedef HANDLE file_handle_t;
#endif

// Структура для статистики файла
struct file_stat {
#ifdef _WIN32
    struct _stat64 st;
#else
    struct stat st;
#endif
    int is_valid;
};

// ============================================================================
// ПРОТОТИПЫ ФУНКЦИЙ ДЛЯ КРОССПЛАТФОРМЕННОСТИ
// ============================================================================

#ifdef __cplusplus
extern "C" {
#endif

// УБИРАЕМ прототипы функций, которые определены как static в config.c
// Они используются только внутри config.c
/*
char* get_executable_dir(void);
const char* get_home_dir(void);
const char* get_xdg_config_home(void);
char* safe_strdup(const char* s);
*/

// Вместо этого добавим общие вспомогательные функции
int crossplat_access(const char* path, int mode);
FILE* crossplat_fopen(const char* path, const char* mode);
int crossplat_stat(const char* path, struct file_stat* fstat);
int crossplat_mkdir(const char* path, mode_t mode);
int crossplat_rmdir(const char* path);
int crossplat_unlink(const char* path);

// Функции для работы с путями
char* path_join(const char* dir, const char* file);
char* path_dirname(const char* path);
char* path_basename(const char* path);
int path_is_absolute(const char* path);
char* path_normalize(const char* path);

// Утилиты
void sleep_ms(int milliseconds);
char* get_platform_name(void);
char* get_architecture_name(void);

#ifdef __cplusplus
}
#endif

// ============================================================================
// МАКРОСЫ ДЛЯ ПРОВЕРКИ ПЛАТФОРМЫ
// ============================================================================

#if defined(_WIN32)
#define PLATFORM_WINDOWS 1
#define PLATFORM_UNIX 0
#define PLATFORM_APPLE 0
#define PLATFORM_LINUX 0
#elif defined(__APPLE__)
#define PLATFORM_WINDOWS 0
#define PLATFORM_UNIX 1
#define PLATFORM_APPLE 1
#define PLATFORM_LINUX 0
#elif defined(__linux__)
#define PLATFORM_WINDOWS 0
#define PLATFORM_UNIX 1
#define PLATFORM_APPLE 0
#define PLATFORM_LINUX 1
#else
#define PLATFORM_WINDOWS 0
#define PLATFORM_UNIX 1
#define PLATFORM_APPLE 0
#define PLATFORM_LINUX 0
#endif

// Макросы для условной компиляции
#ifdef _WIN32
#define WINDOWS_ONLY(code) code
#define UNIX_ONLY(code)
#define LINUX_ONLY(code)
#define APPLE_ONLY(code)
#elif defined(__APPLE__)
#define WINDOWS_ONLY(code)
#define UNIX_ONLY(code) code
#define LINUX_ONLY(code)
#define APPLE_ONLY(code) code
#elif defined(__linux__)
#define WINDOWS_ONLY(code)
#define UNIX_ONLY(code) code
#define LINUX_ONLY(code) code
#define APPLE_ONLY(code)
#else
#define WINDOWS_ONLY(code)
#define UNIX_ONLY(code) code
#define LINUX_ONLY(code)
#define APPLE_ONLY(code)
#endif

// ============================================================================
// ВЕРСИЯ И СБОРОЧНАЯ ИНФОРМАЦИЯ
// ============================================================================

#define PROJECT_NAME "Book Scanner"
#define PROJECT_VERSION "0.0.13"
#define PROJECT_AUTHOR "Sqee&Dragon"
#define PROJECT_LICENSE "GNU GPLv2"

// Макрос для вывода информации о сборке
#define PRINT_BUILD_INFO() \
    do { \
        printf("%s v%s\n", PROJECT_NAME, PROJECT_VERSION); \
        printf("Build: %s %s\n", __DATE__, __TIME__); \
        printf("Platform: %s\n", get_platform_name()); \
        printf("Architecture: %s\n", get_architecture_name()); \
    } while(0)

#endif // COMMON_H
