#include "metadata.h"
#include "utils.h"
#include <stdlib.h>
#include <string.h>
#include <ctype.h>

BookMeta* parse_metadata(const char *filepath, const char *file_type) {
    BookMeta *meta = calloc(1, sizeof(BookMeta));
    if (!meta) {
        return NULL;
    }

    meta->title = NULL;
    meta->author = NULL;
    meta->genre = NULL;
    meta->series = NULL;
    meta->language = NULL;
    meta->publisher = NULL;
    meta->description = NULL;
    meta->file_size = 0;
    meta->series_number = 0;
    meta->year = 0;

    if (strcasecmp(file_type, "fb2") == 0) {
        BookMeta *fb2_meta = parse_fb2(filepath);
        if (fb2_meta) {
            if (fb2_meta->title) meta->title = strdup(fb2_meta->title);
            if (fb2_meta->author) meta->author = strdup(fb2_meta->author);
            if (fb2_meta->genre) meta->genre = strdup(fb2_meta->genre);
            if (fb2_meta->series) meta->series = strdup(fb2_meta->series);
            if (fb2_meta->language) meta->language = strdup(fb2_meta->language);
            if (fb2_meta->publisher) meta->publisher = strdup(fb2_meta->publisher);
            meta->series_number = fb2_meta->series_number;
            meta->year = fb2_meta->year;

            free_book_meta(fb2_meta);
        }
    }

    if (!meta->title) {
        const char *filename = strrchr(filepath, '/');
        filename = filename ? filename + 1 : filepath;

        char *dash = strstr(filename, " - ");
        if (dash) {
            meta->author = strndup(filename, dash - filename);
            const char *title_start = dash + 3;
            const char *dot = strrchr(title_start, '.');
            if (dot) {
                meta->title = strndup(title_start, dot - title_start);
            } else {
                meta->title = strdup(title_start);
            }
        } else {
            const char *dot = strrchr(filename, '.');
            if (dot) {
                meta->title = strndup(filename, dot - filename);
            } else {
                meta->title = strdup(filename);
            }
        }
    }

    if (!meta->title) meta->title = strdup("Unknown Title");
    if (!meta->author) meta->author = strdup("Unknown Author");

    return meta;
}

BookMeta* parse_fb2(const char *filepath) {
    BookMeta *meta = calloc(1, sizeof(BookMeta));
    if (!meta) return NULL;

    char *content = read_file_content(filepath);
    if (!content) {
        free_book_meta(meta);
        return NULL;
    }

    int content_encoding = detect_encoding(content);

    char *converted_content = NULL;
    if (content_encoding == 2) {
        converted_content = convert_encoding(content, "WINDOWS-1251", "UTF-8");
    }

    char *content_to_parse = converted_content ? converted_content : content;

    meta->title = extract_xml_tag_content(content_to_parse, "book-title");
    meta->author = extract_fb2_author(content_to_parse);
    meta->genre = extract_xml_tag_content(content_to_parse, "genre");
    meta->series = extract_fb2_sequence(content_to_parse);
    meta->series_number = extract_fb2_sequence_number(content_to_parse);

    char *date = extract_xml_tag_content(content_to_parse, "date");
    if (date) {
        char *year_ptr = date;
        while (*year_ptr) {
            if (isdigit((unsigned char)*year_ptr) &&
                isdigit((unsigned char)*(year_ptr+1)) &&
                isdigit((unsigned char)*(year_ptr+2)) &&
                isdigit((unsigned char)*(year_ptr+3))) {
                meta->year = atoi(year_ptr);
                break;
            }
            year_ptr++;
        }
        free(date);
    }

    meta->language = extract_xml_tag_content(content_to_parse, "lang");
    meta->publisher = extract_xml_tag_content(content_to_parse, "publisher");

    char *annotation = extract_xml_tag_content(content_to_parse, "annotation");
    if (annotation) {
        if (strlen(annotation) > 1000) {
            meta->description = strndup(annotation, 1000);
        } else {
            meta->description = annotation;
        }
    }

    if (!meta->title) {
        const char *filename = strrchr(filepath, '/');
        filename = filename ? filename + 1 : filepath;
        const char *dot = strrchr(filename, '.');
        if (dot) {
            meta->title = strndup(filename, dot - filename);
        } else {
            meta->title = strdup(filename);
        }
    }

    free(content);
    if (converted_content) {
        free(converted_content);
    }

    return meta;
}

BookMeta* parse_fb2_from_memory(const char *content, size_t content_size) {
    BookMeta *meta = calloc(1, sizeof(BookMeta));
    if (!meta) return NULL;

    char *content_copy = malloc(content_size + 1);
    if (!content_copy) {
        free_book_meta(meta);
        return NULL;
    }
    memcpy(content_copy, content, content_size);
    content_copy[content_size] = '\0';

    int content_encoding = detect_encoding(content_copy);

    char *converted_content = NULL;
    if (content_encoding == 2) {
        converted_content = convert_encoding(content_copy, "WINDOWS-1251", "UTF-8");
    }

    char *content_to_parse = converted_content ? converted_content : content_copy;

    meta->title = extract_xml_tag_content(content_to_parse, "book-title");
    meta->author = extract_fb2_author(content_to_parse);
    meta->genre = extract_xml_tag_content(content_to_parse, "genre");
    meta->series = extract_fb2_sequence(content_to_parse);
    meta->series_number = extract_fb2_sequence_number(content_to_parse);

    char *date = extract_xml_tag_content(content_to_parse, "date");
    if (date) {
        char *year_ptr = date;
        while (*year_ptr) {
            if (isdigit((unsigned char)*year_ptr) &&
                isdigit((unsigned char)*(year_ptr+1)) &&
                isdigit((unsigned char)*(year_ptr+2)) &&
                isdigit((unsigned char)*(year_ptr+3))) {
                meta->year = atoi(year_ptr);
                break;
            }
            year_ptr++;
        }
        free(date);
    }

    meta->language = extract_xml_tag_content(content_to_parse, "lang");
    meta->publisher = extract_xml_tag_content(content_to_parse, "publisher");

    char *annotation = extract_xml_tag_content(content_to_parse, "annotation");
    if (annotation) {
        if (strlen(annotation) > 1000) {
            meta->description = strndup(annotation, 1000);
        } else {
            meta->description = annotation;
        }
    }

    free(content_copy);
    if (converted_content) {
        free(converted_content);
    }

    return meta;
}

char* extract_fb2_sequence(const char *xml) {
    char *sequence_start = strstr(xml, "<sequence");
    if (!sequence_start) {
        sequence_start = strstr(xml, "<sequence>");
        if (!sequence_start) return NULL;
    }

    char *name_start = NULL;
    char *name_end = NULL;

    name_start = strstr(sequence_start, "name=\"");
    if (name_start) {
        name_start += 6;
        name_end = strchr(name_start, '"');
        if (name_end) {
            size_t name_len = name_end - name_start;
            char *series_name = malloc(name_len + 1);
            if (series_name) {
                strncpy(series_name, name_start, name_len);
                series_name[name_len] = '\0';
                return series_name;
            }
        }
    }

    char *tag_end = strstr(sequence_start, ">");
    if (tag_end) {
        tag_end++;
        char *close_tag = strstr(tag_end, "</sequence>");
        if (close_tag) {
            size_t content_len = close_tag - tag_end;
            if (content_len > 0 && content_len < 1000) {
                char *series_name = malloc(content_len + 1);
                if (series_name) {
                    strncpy(series_name, tag_end, content_len);
                    series_name[content_len] = '\0';
                    trim_string(series_name);
                    if (strlen(series_name) > 0) {
                        return series_name;
                    }
                    free(series_name);
                }
            }
        }
    }

    return NULL;
}

int extract_fb2_sequence_number(const char *xml) {
    char *sequence_start = strstr(xml, "<sequence");
    if (!sequence_start) return 0;

    char *number_start = strstr(sequence_start, "number=\"");
    if (number_start) {
        number_start += 8;
        char *number_end = strchr(number_start, '"');
        if (number_end) {
            size_t num_len = number_end - number_start;
            if (num_len > 0 && num_len < 20) {
                char number_str[32];
                strncpy(number_str, number_start, num_len);
                number_str[num_len] = '\0';

                for (size_t i = 0; i < num_len; i++) {
                    if (!isdigit((unsigned char)number_str[i])) {
                        return 0;
                    }
                }

                int number = atoi(number_str);
                return (number > 0) ? number : 0;
            }
        }
    }

    return 0;
}

char* extract_xml_tag_content(const char *xml, const char *tag_name) {
    char open_tag[256], close_tag[256];
    snprintf(open_tag, sizeof(open_tag), "<%s>", tag_name);
    snprintf(close_tag, sizeof(close_tag), "</%s>", tag_name);

    char *start = strstr(xml, open_tag);
    if (!start) return NULL;

    start += strlen(open_tag);
    char *end = strstr(start, close_tag);
    if (!end) return NULL;

    size_t len = end - start;
    char *content = malloc(len + 1);
    if (!content) return NULL;

    strncpy(content, start, len);
    content[len] = '\0';

    trim_string(content);

    if (strcmp(tag_name, "annotation") == 0) {
        char *cleaned = clean_html_tags(content);
        if (cleaned) {
            free(content);
            content = cleaned;
        }
    }

    return content;
}

char* extract_fb2_author(const char *xml) {
    char *author_start = strstr(xml, "<author>");
    if (!author_start) return NULL;

    char *author_end = strstr(author_start, "</author>");
    if (!author_end) return NULL;

    char *first_name = extract_xml_tag_content(author_start, "first-name");
    char *last_name = extract_xml_tag_content(author_start, "last-name");

    if (!first_name && !last_name) {
        return NULL;
    }

    char *author = NULL;
    if (first_name && last_name) {
        author = malloc(strlen(first_name) + strlen(last_name) + 2);
        sprintf(author, "%s %s", first_name, last_name);
    } else if (first_name) {
        author = strdup(first_name);
    } else {
        author = strdup(last_name);
    }

    free(first_name);
    free(last_name);

    return author;
}

void free_book_meta(BookMeta *meta) {
    if (!meta) return;

    if (meta->title) {
        free(meta->title);
        meta->title = NULL;
    }
    if (meta->author) {
        free(meta->author);
        meta->author = NULL;
    }
    if (meta->genre) {
        free(meta->genre);
        meta->genre = NULL;
    }
    if (meta->series) {
        free(meta->series);
        meta->series = NULL;
    }
    if (meta->language) {
        free(meta->language);
        meta->language = NULL;
    }
    if (meta->publisher) {
        free(meta->publisher);
        meta->publisher = NULL;
    }
    if (meta->description) {
        free(meta->description);
        meta->description = NULL;
    }
}
