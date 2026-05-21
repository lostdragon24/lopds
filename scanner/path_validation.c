#include "path_validation.h"
#include "common.h"
#include <errno.h>
#include <limits.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>

#ifdef _WIN32
#include <direct.h>
#include <windows.h>
#define stat _stat
#define access _access
#define F_OK 0
#define R_OK 4
#define W_OK 2
#else
#include <libgen.h>
#include <unistd.h>
#endif

char* safe_realpath(const char* path, Config* config)
{
    if (!path) {
        if (config)
            log_message(config, "ERROR", "NULL path provided to safe_realpath");
        else
            fprintf(stderr, "ERROR: NULL path provided to safe_realpath\n");
        return NULL;
    }

    // Проверка на слишком длинный путь
    size_t path_len = strlen(path);
    if (path_len >= PATH_MAX) {
        if (config) {
            log_message(config, "ERROR", "Path too long: %s (max %d)", path,
                PATH_MAX);
        } else {
            fprintf(stderr, "ERROR: Path too long: %s (max %d)\n", path, PATH_MAX);
        }
        return NULL;
    }

    char resolved_path[PATH_MAX];
    char* result = NULL;

#ifdef _WIN32
    // Windows: используем GetFullPathNameA
    DWORD ret = GetFullPathNameA(path, PATH_MAX, resolved_path, NULL);
    if (ret > 0 && ret < PATH_MAX) {
        result = strdup(resolved_path);
        if (!result) {
            if (config)
                log_message(config, "ERROR",
                    "Memory allocation failed in safe_realpath");
            else
                fprintf(stderr, "ERROR: Memory allocation failed\n");
        }
    } else {
        DWORD error = GetLastError();
        if (config) {
            log_message(config, "ERROR", "Failed to resolve path: %s (error: %lu)",
                path, error);
        } else {
            fprintf(stderr, "ERROR: Failed to resolve path: %s (error: %lu)\n", path,
                error);
        }
    }
#else
    // Unix: используем realpath
    char* real_result = realpath(path, resolved_path);
    if (real_result) {
        result = strdup(resolved_path);
        if (!result) {
            if (config)
                log_message(config, "ERROR",
                    "Memory allocation failed in safe_realpath");
            else
                fprintf(stderr, "ERROR: Memory allocation failed\n");
        }
    } else {
        if (config) {
            log_message(config, "ERROR", "Failed to resolve path: %s (%s)", path,
                strerror(errno));
        } else {
            fprintf(stderr, "ERROR: Failed to resolve path: %s (%s)\n", path,
                strerror(errno));
        }
    }
#endif

    return result;
}

int is_path_safe(const char* path, Config* config)
{
    if (!path) {
        if (config)
            log_message(config, "ERROR", "NULL path in is_path_safe");
        else
            fprintf(stderr, "ERROR: NULL path in is_path_safe\n");
        return 0;
    }

    // Проверка на пустую строку
    if (path[0] == '\0') {
        if (config)
            log_message(config, "WARNING", "Empty path");
        else
            fprintf(stderr, "WARNING: Empty path\n");
        return 0;
    }

    // ИСПРАВЛЕНО: Проверка на directory traversal
    // Ищем ".." как отдельный компонент пути, а не как часть слова
    const char* p = path;
    while ((p = strstr(p, "..")) != NULL) {
        // Проверяем, что ".." образует отдельный компонент пути
        // Должно быть: начало строки или перед '/' и после '/' или конец строки
        int is_component = 0;

        // Проверяем символ перед ".."
        if (p == path || *(p - 1) == '/' || *(p - 1) == '\\') {
            // Проверяем символ после ".."
            char after = *(p + 2);
            if (after == '\0' || after == '/' || after == '\\') {
                is_component = 1;
            }
        }

        if (is_component) {
            if (config) {
                log_message(config, "WARNING", "Path contains directory traversal '..': %s", path);
            } else {
                fprintf(stderr, "WARNING: Path contains directory traversal '..': %s\n", path);
            }
            return 0;
        }

        p += 2; // Продолжаем поиск
    }

    // Проверка на null bytes
    if (memchr(path, '\0', strlen(path)) != NULL) {
        if (config) {
            log_message(config, "WARNING", "Path contains null bytes: %s", path);
        } else {
            fprintf(stderr, "WARNING: Path contains null bytes: %s\n", path);
        }
        return 0;
    }

    // Остальные проверки...
    // Проверка на управляющие символы
    const unsigned char* p2 = (const unsigned char*)path;
    while (*p2) {
        if (*p2 < 32 && *p2 != '\t' && *p2 != '\n' && *p2 != '\r') {
            if (config) {
                log_message(config, "WARNING", "Path contains control characters: %s", path);
            } else {
                fprintf(stderr, "WARNING: Path contains control characters: %s\n", path);
            }
            return 0;
        }
        p2++;
    }

    return 1;
}

char* join_and_validate_path(const char* dir, const char* file,
    Config* config)
{
    if (!dir || !file) {
        if (config) {
            log_message(config, "ERROR", "NULL parameters to join_and_validate_path");
        }
        return NULL;
    }

    size_t dir_len = strlen(dir);
    size_t file_len = strlen(file);

    if (dir_len == 0 || file_len == 0) {
        if (config) {
            log_message(config, "ERROR", "Empty path component");
        }
        return NULL;
    }

    // ✅ Явная проверка на переполнение ДО snprintf
    size_t total_len = dir_len + file_len + 2; // +1 для '/', +1 для '\0'
    if (total_len > PATH_MAX) {
        if (config) {
            log_message(config, "ERROR", "Path too long: %zu + %zu > %d", dir_len,
                file_len, PATH_MAX);
        }
        return NULL;
    }

    char combined[PATH_MAX];
    int need_slash = (dir[dir_len - 1] != '/'
#ifdef _WIN32
        && dir[dir_len - 1] != '\\'
#endif
    );

    int written;
    if (need_slash) {
        written = snprintf(combined, sizeof(combined), "%s/%s", dir, file);
    } else {
        written = snprintf(combined, sizeof(combined), "%s%s", dir, file);
    }

    // ✅ Проверка результата snprintf
    if (written < 0 || (size_t)written >= sizeof(combined)) {
        if (config) {
            log_message(config, "ERROR", "Path join failed: buffer overflow");
        }
        return NULL;
    }

    if (!is_path_safe(combined, config)) {
        return NULL;
    }

    return safe_realpath(combined, config);
}

int safe_file_exists(const char* path, Config* config)
{
    if (!path) {
        if (config)
            log_message(config, "ERROR", "NULL path in safe_file_exists");
        else
            fprintf(stderr, "ERROR: NULL path in safe_file_exists\n");
        return 0;
    }

    // Сначала проверяем безопасность пути
    if (!is_path_safe(path, config)) {
        return 0;
    }

#ifdef _WIN32
    struct _stat st;
    if (_stat(path, &st) == 0) {
        return (st.st_mode & _S_IFREG) != 0;
    }
#else
    struct stat st;
    if (stat(path, &st) == 0) {
        return S_ISREG(st.st_mode);
    }
#endif

    return 0;
}

FILE* safe_fopen(const char* path, const char* mode, Config* config)
{
    if (!path || !mode) {
        if (config) {
            log_message(config, "ERROR",
                "NULL parameters to safe_fopen (path=%p, mode=%p)",
                (void*)path, (void*)mode);
        } else {
            fprintf(stderr, "ERROR: NULL parameters to safe_fopen\n");
        }
        return NULL;
    }

    // Проверяем безопасность пути
    if (!is_path_safe(path, config)) {
        return NULL;
    }

    // Проверяем режим открытия (запрещаем опасные комбинации)
    if (strchr(mode, 'w') || strchr(mode, 'a') || strchr(mode, '+')) {
        // Для записи дополнительно проверяем parent directory
        char* path_copy = strdup(path);
        if (!path_copy) {
            if (config)
                log_message(config, "ERROR", "Memory allocation failed in safe_fopen");
            else
                fprintf(stderr, "ERROR: Memory allocation failed\n");
            return NULL;
        }

        // Находим последний разделитель
        char* last_sep = strrchr(path_copy, '/');
#ifdef _WIN32
        if (!last_sep)
            last_sep = strrchr(path_copy, '\\');
#endif

        if (last_sep) {
            *last_sep = '\0';
            // Проверяем, что директория существует и доступна для записи
#ifdef _WIN32
            struct _stat st;
            if (_stat(path_copy, &st) != 0 || !(st.st_mode & _S_IFDIR)) {
#else
            struct stat st;
            if (stat(path_copy, &st) != 0 || !S_ISDIR(st.st_mode)) {
#endif
                if (config) {
                    log_message(
                        config, "ERROR",
                        "Parent directory does not exist or is not a directory: %s",
                        path_copy);
                } else {
                    fprintf(stderr, "ERROR: Parent directory does not exist\n");
                }
                free(path_copy);
                return NULL;
            }

#ifdef _WIN32
            if (_access(path_copy, 02) != 0) { // 02 = write permission
#else
            if (access(path_copy, W_OK) != 0) {
#endif
                if (config) {
                    log_message(config, "ERROR", "Parent directory is not writable: %s",
                        path_copy);
                } else {
                    fprintf(stderr, "ERROR: Parent directory is not writable\n");
                }
                free(path_copy);
                return NULL;
            }
        }
        free(path_copy);
    }

    // Открываем файл
    FILE* file = fopen(path, mode);
    if (!file) {
        if (config) {
            log_message(config, "ERROR", "Cannot open file '%s' with mode '%s': %s",
                path, mode, strerror(errno));
        } else {
            fprintf(stderr, "ERROR: Cannot open file '%s': %s\n", path,
                strerror(errno));
        }
    }

    return file;
}
