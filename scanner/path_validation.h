#ifndef PATH_VALIDATION_H
#define PATH_VALIDATION_H

#include "config.h"

#ifdef __cplusplus
extern "C" {
#endif

/**
 * Безопасно получает канонический путь к файлу
 * @param path Исходный путь
 * @param config Конфигурация для логирования
 * @return Канонический путь (нужно освободить с free()) или NULL при ошибке
 */
char *safe_realpath(const char *path, Config *config);

/**
 * Проверяет, безопасен ли путь (нет directory traversal)
 * @param path Путь для проверки
 * @param config Конфигурация для логирования
 * @return 1 если безопасен, 0 если нет
 */
int is_path_safe(const char *path, Config *config);

/**
 * Безопасно объединяет директорию и файл и проверяет результат
 * @param dir Директория
 * @param file Имя файла
 * @param config Конфигурация для логирования
 * @return Полный канонический путь (нужно освободить с free()) или NULL при
 * ошибке
 */
char *join_and_validate_path(const char *dir, const char *file, Config *config);

/**
 * Проверяет существование файла с безопасным путем
 * @param path Путь к файлу
 * @param config Конфигурация для логирования
 * @return 1 если файл существует и доступен для чтения, 0 если нет
 */
int safe_file_exists(const char *path, Config *config);

/**
 * Безопасно открывает файл с проверкой пути
 * @param path Путь к файлу
 * @param mode Режим открытия
 * @param config Конфигурация для логирования
 * @return FILE* или NULL при ошибке
 */
FILE *safe_fopen(const char *path, const char *mode, Config *config);

#ifdef __cplusplus
}
#endif

#endif // PATH_VALIDATION_H
