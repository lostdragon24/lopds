#ifndef METADATA_H
#define METADATA_H

#include "database.h"

// Основные функции парсинга
BookMeta *parse_metadata(const char *filepath, const char *file_type);
BookMeta *parse_fb2(const char *filepath);
BookMeta *parse_fb2_from_memory(const char *content, size_t content_size);

// Функции парсинга для EPUB
BookMeta *parse_epub(const char *filepath);
BookMeta *parse_epub_from_memory(const char *content, size_t content_size);

// В секцию "Функции для работы с HTML/XML":
char *extract_xml_meta_by_name(const char *xml, const char *target_name);

// Вспомогательные функции
void free_book_meta(BookMeta *meta);
char *extract_xml_tag_content(const char *xml, const char *tag_name);
char *extract_fb2_author(const char *xml);
char *extract_fb2_sequence(const char *xml);
int extract_fb2_sequence_number(const char *xml);
void trim_string(char *str);

#endif // METADATA_H
