#ifndef UTILS_H
#define UTILS_H

#include "config.h"
#include <stdarg.h>
#include <stdio.h>

#ifdef __cplusplus
extern "C" {
#endif

// Безопасные функции работы со строками
int safe_snprintf(char* str, size_t size, const char* format, ...)
    __attribute__((format(printf, 3, 4)));
char* safe_strncpy(char* dest, const char* src, size_t dest_size);
void* safe_malloc(size_t size);
void* safe_calloc(size_t nmemb, size_t size);
char* safe_strdup(const char* s);
char* safe_fgets(char* buffer, int size, FILE* stream);

// Функции для работы с файлами
char* read_file_content(const char* filepath);
int write_file_content(const char* filepath, const char* content, size_t len);
const char* normalize_file_type(const char* filename);

// Функции для работы со строками
void trim_string(char* str);
char* str_replace(const char* str, const char* old, const char* new);
char** str_split(const char* str, char delimiter, int* count);
void free_strings_array(char** array, int count);

// Функции для работы с кодировками
char* convert_encoding(const char* text, const char* from_encoding,
    const char* to_encoding);
int detect_encoding(const char* text);
int is_valid_utf8(const char* str);
const char* detect_string_encoding(const char* str);

// Функции для работы с HTML/XML
char* clean_html_tags(const char* html);
char* extract_xml_tag(const char* xml, const char* tag_name);
char* extract_xml_attribute(const char* xml, const char* tag_name,
    const char* attr_name); // ЭТОТ ПРОТОТИП УЖЕ ЕСТЬ

// Функции для проверки запуска
int is_already_running(const char* lockfile_path, Config* config);
int create_lock_file(const char* lockfile_path, Config* config);
void remove_lock_file(const char* lockfile_path, Config* config);

// Функции для работы с путями (базовые, без валидации)
char* path_join(const char* dir, const char* file);
char* path_dirname(const char* path);
char* path_basename(const char* path);
int path_is_absolute(const char* path);

// Функции для получения информации о системе
char* get_platform_name(void);
char* get_architecture_name(void);
long long get_free_memory(void);
int get_cpu_count(void);

// Функции для работы со временем
char* get_current_timestamp(void);
long long get_current_time_ms(void);
void sleep_ms(int milliseconds);

// Функции для работы с хешами
char* calculate_file_hash(const char* filepath, const char* algorithm);
char* calculate_buffer_hash(const unsigned char* buffer, size_t len, const char* algorithm);
int is_valid_hash_algorithm(const char* algorithm);
void print_hash_algorithms(void);

int is_valid_utf8_string(const char* str);
char* sanitize_utf8_string(const char* str);

#ifndef SAFE_FREE
#define SAFE_FREE(ptr)  \
    do {                \
        if (ptr) {      \
            free(ptr);  \
            ptr = NULL; \
        }               \
    } while (0)
#endif

#ifdef __cplusplus
}
#endif

#endif // UTILS_H
