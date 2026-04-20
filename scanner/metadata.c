#include "metadata.h"
#include "common.h"
#include "utils.h"
#include <archive.h>
#include <archive_entry.h>
#include <ctype.h>
#include <iconv.h>
#include <locale.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

// Функция для конвертации строки между кодировками
static char *convert_string_encoding(const char *str, const char *from_encoding,
                                     const char *to_encoding) {
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

  char *in_ptr = (char *)str;
  char *out_ptr = out_buf;
  size_t in_remaining = in_len;
  size_t out_remaining = out_len;

  memset(out_buf, 0, out_len + 1);

  if (iconv(cd, &in_ptr, &in_remaining, &out_ptr, &out_remaining) ==
      (size_t)-1) {
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

// Вспомогательная функция для парсинга EPUB метаданных
static BookMeta *parse_epub_metadata(const char *content_opf) {
  if (!content_opf)
    return NULL;

  BookMeta *meta = calloc(1, sizeof(BookMeta));
  if (!meta)
    return NULL;

  // Извлекаем метаданные
  meta->title = extract_xml_tag(content_opf, "title");
  if (!meta->title) {
    meta->title = extract_xml_tag(content_opf, "dc:title");
  }

  meta->author = extract_xml_tag(content_opf, "creator");
  if (!meta->author) {
    meta->author = extract_xml_tag(content_opf, "dc:creator");
  }

  meta->genre = extract_xml_tag(content_opf, "subject");
  if (!meta->genre) {
    meta->genre = extract_xml_tag(content_opf, "dc:subject");
  }

  meta->description = extract_xml_tag(content_opf, "description");
  if (!meta->description) {
    meta->description = extract_xml_tag(content_opf, "dc:description");
  }

  meta->language = extract_xml_tag(content_opf, "language");
  if (!meta->language) {
    meta->language = extract_xml_tag(content_opf, "dc:language");
  }

  meta->publisher = extract_xml_tag(content_opf, "publisher");
  if (!meta->publisher) {
    meta->publisher = extract_xml_tag(content_opf, "dc:publisher");
  }

  // Дата
  char *date = extract_xml_tag(content_opf, "date");
  if (!date) {
    date = extract_xml_tag(content_opf, "dc:date");
  }
  if (date) {
    // Ищем 4 цифры подряд (год)
    char *p = date;
    while (*p) {
      if (isdigit(p[0]) && isdigit(p[1]) && isdigit(p[2]) && isdigit(p[3])) {
        meta->year = atoi(p);
        break;
      }
      p++;
    }
    free(date);
  }

  // Серия (Calibre EPUB 2: name=, EPUB 3: property=)
  char *series = extract_xml_meta_by_name(content_opf, "calibre:series");
  if (!series) {
    // Fallback для EPUB 3 collections
    series = extract_xml_meta_by_name(content_opf, "belongs-to-collection");
  }
  if (series) {
    meta->series = series;
  }

  // Номер в серии
  char *series_index =
      extract_xml_meta_by_name(content_opf, "calibre:series_index");
  if (!series_index) {
    // EPUB 3: group-position
    series_index = extract_xml_meta_by_name(content_opf, "group-position");
  }
  if (series_index) {
    meta->series_number = atoi(series_index);
    free(series_index);
  }

  // Значения по умолчанию
  if (!meta->title)
    meta->title = strdup("Unknown Title");
  if (!meta->author)
    meta->author = strdup("Unknown Author");

  return meta;
}

BookMeta *parse_metadata(const char *filepath, const char *file_type) {
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
          meta->title =
              convert_string_encoding(fb2_meta->title, "WINDOWS-1251", "UTF-8");
        } else {
          meta->title = strdup(fb2_meta->title);
        }
      }

      if (fb2_meta->author) {
        int encoding = detect_encoding(fb2_meta->author);
        if (encoding == 2) {
          meta->author = convert_string_encoding(fb2_meta->author,
                                                 "WINDOWS-1251", "UTF-8");
        } else {
          meta->author = strdup(fb2_meta->author);
        }
      }

      if (fb2_meta->genre) {
        int encoding = detect_encoding(fb2_meta->genre);
        if (encoding == 2) {
          meta->genre =
              convert_string_encoding(fb2_meta->genre, "WINDOWS-1251", "UTF-8");
        } else {
          meta->genre = strdup(fb2_meta->genre);
        }
      }

      if (fb2_meta->series) {
        int encoding = detect_encoding(fb2_meta->series);
        if (encoding == 2) {
          meta->series = convert_string_encoding(fb2_meta->series,
                                                 "WINDOWS-1251", "UTF-8");
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
          meta->publisher = convert_string_encoding(fb2_meta->publisher,
                                                    "WINDOWS-1251", "UTF-8");
        } else {
          meta->publisher = strdup(fb2_meta->publisher);
        }
      }

      meta->series_number = fb2_meta->series_number;
      meta->year = fb2_meta->year;

      free_book_meta(fb2_meta);
    }
  } else if (strcasecmp(file_type, "epub") == 0) {
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
  if (!meta->title)
    meta->title = strdup("Unknown Title");
  if (!meta->author)
    meta->author = strdup("Unknown Author");

  return meta;
}

BookMeta *parse_fb2(const char *filepath) {
  BookMeta *meta = calloc(1, sizeof(BookMeta));
  if (!meta)
    return NULL;

  char *content = read_file_content(filepath);
  if (!content) {
    free_book_meta(meta);
    return NULL;
  }

  int content_encoding = detect_encoding(content);
  char *converted_content = NULL;
  char *content_to_parse = content;

  if (content_encoding == 2) {
    converted_content = convert_encoding(content, "WINDOWS-1251", "UTF-8");
    if (converted_content) {
      content_to_parse = converted_content;
    }
  }

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
          isdigit((unsigned char)*(year_ptr + 1)) &&
          isdigit((unsigned char)*(year_ptr + 2)) &&
          isdigit((unsigned char)*(year_ptr + 3))) {
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
      free(annotation);
    } else {
      meta->description = annotation;
    }
  }

  // Fallback для title если не найден
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

  // Fallback для author если не найден
  if (!meta->author) {
    meta->author = strdup("Unknown Author");
  }

  free(content);
  if (converted_content) {
    free(converted_content);
  }

  return meta;
}

BookMeta *parse_fb2_from_memory(const char *content, size_t content_size) {
  BookMeta *meta = calloc(1, sizeof(BookMeta));
  if (!meta)
    return NULL;

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
          isdigit((unsigned char)*(year_ptr + 1)) &&
          isdigit((unsigned char)*(year_ptr + 2)) &&
          isdigit((unsigned char)*(year_ptr + 3))) {
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

char *extract_fb2_sequence(const char *xml) {
  char *sequence_start = strstr(xml, "<sequence");
  if (!sequence_start) {
    sequence_start = strstr(xml, "<sequence>");
    if (!sequence_start)
      return NULL;
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
  if (!sequence_start)
    return 0;

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

char *extract_xml_tag_content(const char *xml, const char *tag_name) {
  char open_tag[256], close_tag[256];
  snprintf(open_tag, sizeof(open_tag), "<%s>", tag_name);
  snprintf(close_tag, sizeof(close_tag), "</%s>", tag_name);

  char *start = strstr(xml, open_tag);
  if (!start)
    return NULL;

  start += strlen(open_tag);
  char *end = strstr(start, close_tag);
  if (!end)
    return NULL;

  size_t len = end - start;
  char *content = malloc(len + 1);
  if (!content)
    return NULL;

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

char *extract_fb2_author(const char *xml) {
  char *author_start = strstr(xml, "<author>");
  if (!author_start)
    return NULL;

  char *author_end = strstr(author_start, "</author>");
  if (!author_end)
    return NULL;

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
  if (!meta)
    return;

  // Освобождаем все строковые поля с проверкой
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

  // Освобождаем саму структуру
  free(meta);
}

BookMeta *parse_epub(const char *filepath) {
  if (!filepath) {
    log_message(NULL, "ERROR", "[PARSE_EPUB] NULL filepath");
    return NULL;
  }

  log_message(NULL, "DEBUG", "[PARSE_EPUB] Opening: %s", filepath);

  struct archive *a;
  struct archive_entry *entry;
  BookMeta *meta = NULL;
  char *container_xml = NULL;
  char *content_opf_path = NULL;
  char *content_opf = NULL;
  int r;

  // 1. Открываем EPUB как ZIP архив
  a = archive_read_new();
  if (!a) {
    log_message(NULL, "ERROR", "[PARSE_EPUB] Failed to create archive object");
    return NULL;
  }

  archive_read_support_format_zip(a);
  archive_read_support_filter_all(a);

  r = archive_read_open_filename(a, filepath, 10240);
  if (r != ARCHIVE_OK) {
    log_message(NULL, "ERROR", "[PARSE_EPUB] Cannot open file: %s",
                archive_error_string(a));
    archive_read_free(a);
    return NULL;
  }

  // 2. Ищем container.xml в META-INF/
  int found_container = 0;
  while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
    const char *filename = archive_entry_pathname(entry);
    if (!filename) {
      archive_read_data_skip(a);
      continue;
    }

    if (strstr(filename, "META-INF/container.xml") ||
        strcmp(filename, "container.xml") == 0) {

      size_t size = archive_entry_size(entry);
      if (size > 0 && size < 65536) { // Максимум 64KB для container.xml
        container_xml = malloc(size + 1);
        if (container_xml) {
          la_ssize_t bytes_read = archive_read_data(a, container_xml, size);
          if (bytes_read == (la_ssize_t)size) {
            container_xml[size] = '\0';
            found_container = 1;
            log_message(NULL, "DEBUG", "[PARSE_EPUB] Found container.xml");
          } else {
            log_message(NULL, "WARNING",
                        "[PARSE_EPUB] Failed to read container.xml");
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

  if (!found_container || !container_xml) {
    log_message(NULL, "WARNING", "[PARSE_EPUB] No container.xml found");
    archive_read_free(a);
    return NULL;
  }

  // 3. Извлекаем путь к OPF файлу из container.xml
  content_opf_path =
      extract_xml_attribute(container_xml, "rootfile", "full-path");
  free(container_xml);

  if (!content_opf_path) {
    log_message(NULL, "WARNING",
                "[PARSE_EPUB] Could not extract OPF path, trying default");
    content_opf_path = strdup("content.opf");
  }

  log_message(NULL, "DEBUG", "[PARSE_EPUB] OPF path: %s", content_opf_path);

  // 4. Закрываем архив
  archive_read_free(a);

  // 5. Открываем архив снова для поиска OPF файла
  a = archive_read_new();
  if (!a) {
    log_message(NULL, "ERROR", "[PARSE_EPUB] Failed to create archive object");
    free(content_opf_path);
    return NULL;
  }

  archive_read_support_format_zip(a);
  archive_read_support_filter_all(a);

  r = archive_read_open_filename(a, filepath, 10240);
  if (r != ARCHIVE_OK) {
    log_message(NULL, "ERROR", "[PARSE_EPUB] Cannot reopen file: %s",
                archive_error_string(a));
    archive_read_free(a);
    free(content_opf_path);
    return NULL;
  }

  // 6. Ищем OPF файл
  int found_content = 0;

  while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
    const char *filename = archive_entry_pathname(entry);
    if (!filename) {
      archive_read_data_skip(a);
      continue;
    }

    // Проверяем, соответствует ли файл искомому
    if (strcmp(filename, content_opf_path) == 0 ||
        strstr(filename, content_opf_path) != NULL ||
        strstr(filename, ".opf") != NULL) {

      size_t size = archive_entry_size(entry);
      if (size > 0 && size < 1048576) { // Максимум 1MB для OPF
        content_opf = malloc(size + 1);
        if (content_opf) {
          la_ssize_t bytes_read = archive_read_data(a, content_opf, size);
          if (bytes_read == (la_ssize_t)size) {
            content_opf[size] = '\0';
            found_content = 1;
            log_message(NULL, "DEBUG", "[PARSE_EPUB] Found OPF file: %s",
                        filename);
            break;
          } else {
            log_message(NULL, "WARNING",
                        "[PARSE_EPUB] Failed to read OPF file");
            free(content_opf);
            content_opf = NULL;
          }
        }
      }
    }
    archive_read_data_skip(a);
  }

  archive_read_free(a);
  free(content_opf_path);

  if (!found_content || !content_opf) {
    log_message(NULL, "WARNING", "[PARSE_EPUB] No OPF file found");
    return NULL;
  }

  // 7. Парсим метаданные из OPF файла
  meta = parse_epub_metadata(content_opf);
  free(content_opf);

  if (!meta) {
    log_message(NULL, "WARNING", "[PARSE_EPUB] Failed to parse metadata");
    return NULL;
  }

  return meta;
}

BookMeta *parse_epub_from_memory(const char *content, size_t content_size) {
  log_message(NULL, "DEBUG", "[PARSE_EPUB_FROM_MEMORY] Processing %zu bytes",
              content_size);

  if (!content || content_size == 0) {
    log_message(NULL, "ERROR", "[PARSE_EPUB_FROM_MEMORY] Empty content");
    return NULL;
  }

  // Создаем временный файл с уникальным именем
  char temp_path[] = "/tmp/epub_mem_XXXXXX.epub";
  int fd = mkstemps(temp_path, 5); // 5 = длина ".epub"

  if (fd == -1) {
    // Пробуем в текущей директории
    strcpy(temp_path, "./epub_temp_XXXXXX.epub");
    fd = mkstemps(temp_path, 5);
  }

  if (fd == -1) {
    log_message(NULL, "ERROR",
                "[PARSE_EPUB_FROM_MEMORY] Cannot create temp file");
    return NULL;
  }

  log_message(NULL, "DEBUG", "[PARSE_EPUB_FROM_MEMORY] Temp file: %s",
              temp_path);

  // Пишем данные в временный файл
  ssize_t written = write(fd, content, content_size);
  if (written != (ssize_t)content_size) {
    log_message(NULL, "ERROR",
                "[PARSE_EPUB_FROM_MEMORY] Write failed: wrote %zd of %zu bytes",
                written, content_size);
    close(fd);
    unlink(temp_path);
    return NULL;
  }

  close(fd);

  // Парсим EPUB из временного файла
  BookMeta *meta = parse_epub(temp_path);

  // Удаляем временный файл
  unlink(temp_path);

  if (meta) {
    log_message(NULL, "DEBUG", "[PARSE_EPUB_FROM_MEMORY] Success!");
  } else {
    log_message(NULL, "WARNING", "[PARSE_EPUB_FROM_MEMORY] Failed to parse");
  }

  return meta;
}

/**
 * Извлекает значение атрибута 'content' из тега <meta>,
 * у которого атрибут 'name' (или 'property') равен target_name
 * Поддерживает как EPUB 2 (name=) так и EPUB 3 (property=)
 */
char *extract_xml_meta_by_name(const char *xml, const char *target_name) {
  if (!xml || !target_name)
    return NULL;

  const char *search_start = xml;

  while ((search_start = strstr(search_start, "<meta")) != NULL) {
    // Находим конец открывающего тега
    const char *tag_end = strchr(search_start, '>');
    if (!tag_end)
      break;

    // Проверяем, есть ли в этом теге нужный name/property
    int found_name = 0;
    const char *name_attr = strstr(search_start, "name=\"");
    const char *prop_attr = strstr(search_start, "property=\"");

    if (name_attr && name_attr < tag_end) {
      name_attr += 6; // пропускаем 'name="'
      const char *name_end = strchr(name_attr, '"');
      if (name_end && name_end < tag_end) {
        size_t name_len = name_end - name_attr;
        if (name_len == strlen(target_name) &&
            strncmp(name_attr, target_name, name_len) == 0) {
          found_name = 1;
        }
      }
    } else if (prop_attr && prop_attr < tag_end) {
      prop_attr += 10; // пропускаем 'property="'
      const char *prop_end = strchr(prop_attr, '"');
      if (prop_end && prop_end < tag_end) {
        size_t prop_len = prop_end - prop_attr;
        if (prop_len == strlen(target_name) &&
            strncmp(prop_attr, target_name, prop_len) == 0) {
          found_name = 1;
        }
      }
    }

    if (found_name) {
      // Ищем атрибут content
      const char *content_attr = strstr(search_start, "content=\"");
      if (content_attr && content_attr < tag_end) {
        content_attr += 9; // пропускаем 'content="'
        const char *content_end = strchr(content_attr, '"');
        if (content_end && content_end < tag_end) {
          size_t content_len = content_end - content_attr;
          char *value = malloc(content_len + 1);
          if (value) {
            strncpy(value, content_attr, content_len);
            value[content_len] = '\0';
            return value;
          }
        }
      }
      // EPUB 3: значение может быть между тегами <meta
      // property="...">value</meta>
      else {
        const char *value_start = tag_end + 1;
        const char *value_end = strstr(value_start, "</meta>");
        if (value_end) {
          size_t value_len = value_end - value_start;
          // Убираем пробелы по краям
          while (value_len > 0 &&
                 isspace((unsigned char)value_start[value_len - 1]))
            value_len--;
          while (value_len > 0 && isspace((unsigned char)*value_start)) {
            value_start++;
            value_len--;
          }

          if (value_len > 0) {
            char *value = malloc(value_len + 1);
            if (value) {
              strncpy(value, value_start, value_len);
              value[value_len] = '\0';
              return value;
            }
          }
        }
      }
    }

    search_start = tag_end + 1;
  }

  return NULL;
}
