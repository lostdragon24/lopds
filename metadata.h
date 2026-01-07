#ifndef METADATA_H
#define METADATA_H

#include "database.h"


BookMeta* parse_metadata(const char *filepath, const char *file_type);
BookMeta* parse_fb2(const char *filepath);
BookMeta* parse_fb2_from_memory(const char *content, size_t content_size);
void free_book_meta(BookMeta *meta);
char* extract_xml_tag_content(const char *xml, const char *tag_name);
char* extract_fb2_author(const char *xml);
char* extract_fb2_sequence(const char *xml);
int extract_fb2_sequence_number(const char *xml);

// Добавьте этот прототип
void trim_string(char *str);

#endif
