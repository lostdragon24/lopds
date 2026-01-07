#include "common.h"
#include "metadata.h"
#include "utils.h"
#include <stdlib.h>
#include <string.h>
#include <ctype.h>
#include <unistd.h>
#include <archive.h>
#include <archive_entry.h>
#include <locale.h>
#include <iconv.h>


// Функция для конвертации строки между кодировками
static char* convert_string_encoding(const char *str, const char *from_encoding, const char *to_encoding) {
    if (!str || !from_encoding || !to_encoding) {
        return NULL;
    }

    if (strcasecmp(from_encoding, to_encoding) == 0) {
        return strdup(str);
    }

    iconv_t cd = iconv_open(to_encoding, from_encoding);
    if (cd == (iconv_t)-1) {
        return strdup(str); // Возвращаем оригинал если не можем конвертировать
    }

    size_t in_len = strlen(str);
    size_t out_len = in_len * 4;
    char *out_buf = malloc(out_len + 1);
    if (!out_buf) {
        iconv_close(cd);
        return strdup(str);
    }

    char *in_ptr = (char*)str;
    char *out_ptr = out_buf;
    size_t in_remaining = in_len;
    size_t out_remaining = out_len;

    memset(out_buf, 0, out_len + 1);

    if (iconv(cd, &in_ptr, &in_remaining, &out_ptr, &out_remaining) == (size_t)-1) {
        free(out_buf);
        iconv_close(cd);
        return strdup(str);
    }

    *out_ptr = '\0';
    iconv_close(cd);

    // Удаляем лишние нулевые символы, если они есть
    size_t actual_len = strlen(out_buf);
    if (actual_len < out_len) {
        char *trimmed = strdup(out_buf);
        free(out_buf);
        return trimmed;
    }

    return out_buf;
}


BookMeta* parse_metadata(const char *filepath, const char *file_type) {
    BookMeta *meta = calloc(1, sizeof(BookMeta));
    if (!meta) {
        return NULL;
    }

    // Инициализируем все поля
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
            // Копируем данные из fb2_meta в meta
            if (fb2_meta->title) {
                // Проверяем кодировку и конвертируем в UTF-8 если нужно
                int encoding = detect_encoding(fb2_meta->title);
                if (encoding == 2) { // WINDOWS-1251
                    meta->title = convert_string_encoding(fb2_meta->title, "WINDOWS-1251", "UTF-8");
                } else {
                    meta->title = strdup(fb2_meta->title);
                }
            }

            if (fb2_meta->author) {
                int encoding = detect_encoding(fb2_meta->author);
                if (encoding == 2) {
                    meta->author = convert_string_encoding(fb2_meta->author, "WINDOWS-1251", "UTF-8");
                } else {
                    meta->author = strdup(fb2_meta->author);
                }
            }

            if (fb2_meta->genre) {
                int encoding = detect_encoding(fb2_meta->genre);
                if (encoding == 2) {
                    meta->genre = convert_string_encoding(fb2_meta->genre, "WINDOWS-1251", "UTF-8");
                } else {
                    meta->genre = strdup(fb2_meta->genre);
                }
            }

            if (fb2_meta->series) {
                int encoding = detect_encoding(fb2_meta->series);
                if (encoding == 2) {
                    meta->series = convert_string_encoding(fb2_meta->series, "WINDOWS-1251", "UTF-8");
                } else {
                    meta->series = strdup(fb2_meta->series);
                }
            }

            if (fb2_meta->language) {
                meta->language = strdup(fb2_meta->language);
            }

            if (fb2_meta->publisher) {
                int encoding = detect_encoding(fb2_meta->publisher);
                if (encoding == 2) {
                    meta->publisher = convert_string_encoding(fb2_meta->publisher, "WINDOWS-1251", "UTF-8");
                } else {
                    meta->publisher = strdup(fb2_meta->publisher);
                }
            }

            meta->series_number = fb2_meta->series_number;
            meta->year = fb2_meta->year;

            free_book_meta(fb2_meta);
        }
    }
    else if (strcasecmp(file_type, "epub") == 0) {
        BookMeta *epub_meta = parse_epub(filepath);
        if (epub_meta) {
            // EPUB обычно уже в UTF-8, но на всякий случай проверяем
            if (epub_meta->title) {
                meta->title = strdup(epub_meta->title);
            }
            if (epub_meta->author) {
                meta->author = strdup(epub_meta->author);
            }
            if (epub_meta->genre) {
                meta->genre = strdup(epub_meta->genre);
            }
            if (epub_meta->series) {
                meta->series = strdup(epub_meta->series);
            }
            if (epub_meta->language) {
                meta->language = strdup(epub_meta->language);
            }
            if (epub_meta->publisher) {
                meta->publisher = strdup(epub_meta->publisher);
            }
            if (epub_meta->description) {
                meta->description = strdup(epub_meta->description);
            }
            meta->series_number = epub_meta->series_number;
            meta->year = epub_meta->year;

            free_book_meta(epub_meta);
        }
    }

    // Fallback: если не удалось распарсить или неподдерживаемый формат
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

    // Гарантируем, что title и author не NULL
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

// Добавим после существующих функций в metadata.c

// Функция для извлечения атрибута из XML тега
static char* extract_xml_attribute(const char *xml, const char *tag_name, const char *attr_name) {
    char open_tag[256];
    snprintf(open_tag, sizeof(open_tag), "<%s", tag_name);

    char *start = strstr(xml, open_tag);
    if (!start) return NULL;

    char attr_search[256];
    snprintf(attr_search, sizeof(attr_search), "%s=\"", attr_name);

    char *attr_start = strstr(start, attr_search);
    if (!attr_start) return NULL;

    attr_start += strlen(attr_search);
    char *attr_end = strchr(attr_start, '"');
    if (!attr_end) return NULL;

    size_t len = attr_end - attr_start;
    char *value = malloc(len + 1);
    if (!value) return NULL;

    strncpy(value, attr_start, len);
    value[len] = '\0';

    return value;
}

// Функция для парсинга EPUB метаданных из content.opf
static BookMeta* parse_epub_metadata(const char *content_opf) {
    BookMeta *meta = calloc(1, sizeof(BookMeta));
    if (!meta) return NULL;

    // Извлекаем метаданные из content.opf
    // Название
    meta->title = extract_xml_tag_content(content_opf, "title");
    if (!meta->title) {
        // Пробуем dc:title
        meta->title = extract_xml_tag_content(content_opf, "dc:title");
    }

    // Автор
    meta->author = extract_xml_tag_content(content_opf, "creator");
    if (!meta->author) {
        meta->author = extract_xml_tag_content(content_opf, "dc:creator");
    }

    // Жанр
    meta->genre = extract_xml_tag_content(content_opf, "subject");
    if (!meta->genre) {
        meta->genre = extract_xml_tag_content(content_opf, "dc:subject");
    }

    // Описание
    meta->description = extract_xml_tag_content(content_opf, "description");
    if (!meta->description) {
        meta->description = extract_xml_tag_content(content_opf, "dc:description");
    }

    // Язык
    meta->language = extract_xml_tag_content(content_opf, "language");
    if (!meta->language) {
        meta->language = extract_xml_tag_content(content_opf, "dc:language");
    }

    // Издатель
    meta->publisher = extract_xml_tag_content(content_opf, "publisher");
    if (!meta->publisher) {
        meta->publisher = extract_xml_tag_content(content_opf, "dc:publisher");
    }

    // Дата/год
    char *date = extract_xml_tag_content(content_opf, "date");
    if (!date) {
        date = extract_xml_tag_content(content_opf, "dc:date");
    }
    if (date) {
        // Пытаемся извлечь год из даты
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

    // Ищем серию (часто хранится в meta тегах)
    char *series_start = strstr(content_opf, "calibre:series");
    if (!series_start) series_start = strstr(content_opf, "series");

    if (series_start) {
        char *content_start = strstr(series_start, "content=\"");
        if (content_start) {
            content_start += 9; // Пропускаем 'content="'
            char *content_end = strchr(content_start, '"');
            if (content_end) {
                size_t len = content_end - content_start;
                meta->series = malloc(len + 1);
                if (meta->series) {
                    strncpy(meta->series, content_start, len);
                    meta->series[len] = '\0';
                }
            }
        }
    }

    // Номер в серии
    char *series_index = strstr(content_opf, "calibre:series_index");
    if (!series_index) series_index = strstr(content_opf, "series_index");

    if (series_index) {
        char *content_start = strstr(series_index, "content=\"");
        if (content_start) {
            content_start += 9;
            char *content_end = strchr(content_start, '"');
            if (content_end) {
                *content_end = '\0';
                meta->series_number = atoi(content_start);
                *content_end = '"';
            }
        }
    }

    return meta;
}

BookMeta* parse_epub(const char *filepath) {


    struct archive *a;
    struct archive_entry *entry;
    BookMeta *meta = NULL;
    char *container_xml = NULL;
    char *content_opf_path = NULL;
    char *content_opf = NULL;

    // 1. Открываем EPUB как ZIP архив
    a = archive_read_new();
    archive_read_support_format_zip(a);
    archive_read_support_filter_all(a);

    if (archive_read_open_filename(a, filepath, 10240) != ARCHIVE_OK) {
        archive_read_free(a);
        return NULL;
    }

    // 2. Ищем container.xml в META-INF/
    int found_container = 0;
    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        const char *filename = archive_entry_pathname(entry);

        if (filename && (strstr(filename, "META-INF/container.xml") ||
                        strstr(filename, "container.xml"))) {
            size_t size = archive_entry_size(entry);

            if (size > 0 && size < 65536) {
                container_xml = malloc(size + 1);
                if (container_xml) {
                    la_ssize_t bytes_read = archive_read_data(a, container_xml, size);
                    if (bytes_read == size) {
                        container_xml[size] = '\0';
                        found_container = 1;
                    } else {
                        free(container_xml);
                        container_xml = NULL;
                    }
                }
            }
            archive_read_data_skip(a);
            break;
        }
        archive_read_data_skip(a);
    }

    if (!found_container) {
        archive_read_free(a);
        return NULL;
    }

    // 3. Извлекаем путь к OPF файлу из container.xml
    // Ищем: <rootfile full-path="OPS/content.opf" ... />
    content_opf_path = extract_xml_attribute(container_xml, "rootfile", "full-path");
    free(container_xml);

    if (!content_opf_path) {

        // Попробуем найти путь другим способом
        // container.xml обычно выглядит так:
        // <container>
        //   <rootfiles>
        //     <rootfile full-path="OPS/content.opf" media-type="..."/>
        //   </rootfiles>
        // </container>

        // Простой парсинг строкой
        char *full_path_start = strstr(container_xml, "full-path=\"");
        if (full_path_start) {
            full_path_start += 11; // "full-path=\""
            char *full_path_end = strchr(full_path_start, '"');
            if (full_path_end) {
                size_t len = full_path_end - full_path_start;
                content_opf_path = malloc(len + 1);
                strncpy(content_opf_path, full_path_start, len);
                content_opf_path[len] = '\0';
            }
        }
    }

    if (!content_opf_path) {
        content_opf_path = strdup("content.opf");
    }

    // 4. Переоткрываем архив для поиска OPF файла
    archive_read_free(a);
    a = archive_read_new();
    archive_read_support_format_zip(a);
    archive_read_support_filter_all(a);

    if (archive_read_open_filename(a, filepath, 10240) != ARCHIVE_OK) {
        free(content_opf_path);
        return NULL;
    }

    // 5. Ищем OPF файл
    int found_content = 0;

    // Варианты имен для поиска
    const char* search_patterns[] = {
        content_opf_path,          // Точный путь из container.xml
        "content.opf",             // Просто content.opf
        "OPS/content.opf",         // OPS/content.opf
        "OEBPS/content.opf",       // Другой common вариант
        NULL
    };

    for (int pattern_idx = 0; !found_content && search_patterns[pattern_idx]; pattern_idx++) {
        const char *pattern = search_patterns[pattern_idx];
        if (!pattern) continue;

        // Переоткрываем архив для каждого паттерна
        archive_read_free(a);
        a = archive_read_new();
        archive_read_support_format_zip(a);
        archive_read_support_filter_all(a);

        if (archive_read_open_filename(a, filepath, 10240) != ARCHIVE_OK) {
            continue;
        }

        // Ищем файл
        while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
            const char *filename = archive_entry_pathname(entry);

            if (filename) {

                // Проверяем совпадение
                int match = 0;
                if (strcmp(filename, pattern) == 0) {
                    match = 1;
                } else if (strstr(filename, pattern)) {
                    match = 1;
                } else if (strstr(filename, "content.opf") || strstr(filename, ".opf")) {
                    // Любой .opf файл
                    match = 1;
                }

                if (match) {
                    size_t size = archive_entry_size(entry);

                    if (size > 0 && size < 1048576) {
                        content_opf = malloc(size + 1);
                        if (content_opf) {
                            la_ssize_t bytes_read = archive_read_data(a, content_opf, size);
                            if (bytes_read == size) {
                                content_opf[size] = '\0';
                                found_content = 1;
                                break;
                            } else {
                                free(content_opf);
                                content_opf = NULL;
                            }
                        }
                    }
                }
            }
            archive_read_data_skip(a);
        }

        if (found_content) break;
    }

    free(content_opf_path);
    archive_read_free(a);

    if (!found_content) {
        return NULL;
    }

    // 6. Парсим метаданные из OPF файла
    meta = parse_epub_metadata(content_opf);
    free(content_opf);

    return meta;
}

BookMeta* parse_epub_from_memory(const char *content, size_t content_size) {
    printf("[PARSE_EPUB_FROM_MEMORY] Processing %zu bytes\n", content_size);

    // Создаем уникальное имя файла
    char temp_path[] = "/tmp/epub_mem_XXXXXX.epub";
    int fd = mkstemps(temp_path, 5);

    if (fd == -1) {
        // Пробуем в текущей директории
        strcpy(temp_path, "./epub_temp_XXXXXX.epub");
        fd = mkstemps(temp_path, 5);
    }

    if (fd == -1) {
        printf("[PARSE_EPUB_FROM_MEMORY] Cannot create temp file\n");
        return NULL;
    }

    printf("[PARSE_EPUB_FROM_MEMORY] Temp file: %s\n", temp_path);

    // Пишем данные
    ssize_t written = 0;
    const char *ptr = content;
    size_t remaining = content_size;

    while (remaining > 0) {
        written = write(fd, ptr, remaining > 8192 ? 8192 : remaining);
        if (written <= 0) break;
        ptr += written;
        remaining -= written;
    }

    close(fd);

    if (remaining > 0) {
        printf("[PARSE_EPUB_FROM_MEMORY] Write incomplete: %zu bytes remaining\n", remaining);
        unlink(temp_path);
        return NULL;
    }

    // Парсим
    BookMeta *meta = parse_epub(temp_path);

    // Удаляем временный файл
    unlink(temp_path);

    if (meta) {
        printf("[PARSE_EPUB_FROM_MEMORY] Success!\n");
    } else {
        printf("[PARSE_EPUB_FROM_MEMORY] Failed to parse\n");
    }

    return meta;
}

