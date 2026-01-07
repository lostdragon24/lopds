// utils.h - обновить объявление функции
#ifndef UTILS_H
#define UTILS_H

char* read_file_content(const char *filepath);
void trim_string(char *str);
char* convert_encoding(const char *text, const char *from_encoding, const char *to_encoding);
char* clean_html_tags(const char *html);
int detect_encoding(const char *text);
int is_already_running(const char *lockfile_path);
char* calculate_file_hash(const char *filepath, const char *algorithm);  // Добавить второй параметр

// Добавить новые функции
int is_valid_hash_algorithm(const char *algorithm);
void print_hash_algorithms();

#endif
