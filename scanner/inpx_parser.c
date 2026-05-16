#include "inpx_parser.h"
#include "common.h"
#include "database.h"
#include "metadata.h"
#include "path_validation.h"
#include "utils.h"
#include <archive.h>
#include <archive_entry.h>
#include <ctype.h>
#include <errno.h>
#include <stdlib.h>
#include <string.h>

#define FIELD_SEP '\x04'
#define RECORD_SEP1 '\x0D'
#define RECORD_SEP2 '\x0A'

static const char *DEFAULT_STRUCTURE = "AUTHOR;GENRE;TITLE;SERIES;SERNO;FILE;"
                                       "SIZE;LIBID;DEL;EXT;DATE;LANG;KEYWORDS";

typedef struct {
  TFields field_type;
  const char *field_name;
} FieldMapping;

static const FieldMapping field_mappings[] = {
    {flAuthor, "AUTHOR"},     {flGenre, "GENRE"}, {flTitle, "TITLE"},
    {flSeries, "SERIES"},     {flSerNo, "SERNO"}, {flFile, "FILE"},
    {flSize, "SIZE"},         {flLibID, "LIBID"}, {flDeleted, "DEL"},
    {flExt, "EXT"},           {flDate, "DATE"},   {flLang, "LANG"},
    {flKeyWords, "KEYWORDS"}, {flNone, NULL}};

void get_inpx_fields(const char *structure_info, TImportContext *ctx) {
  //Инициализируем структуру в ноль
  memset(ctx, 0, sizeof(TImportContext));

  const char *structure = structure_info && strlen(structure_info) > 0
                              ? structure_info
                              : DEFAULT_STRUCTURE;

  ctx->fields_count = 1;
  for (const char *p = structure; *p; p++) {
    if (*p == ';')
      ctx->fields_count++;
  }

  // Выделяем память ТОЛЬКО если есть поля
  if (ctx->fields_count > 0) {
    ctx->fields = malloc(ctx->fields_count * sizeof(TFields));
    if (!ctx->fields) {
      ctx->fields_count = 0;
      return;
    }
  } else {
    ctx->fields = NULL;
    return;
  }

  ctx->use_stored_folder = 0;

  char *s = strdup(structure);
  if (!s) {
    free(ctx->fields);
    ctx->fields = NULL;
    ctx->fields_count = 0;
    return;
  }

  char *token, *saveptr;
  int i = 0;

  token = strtok_r(s, ";", &saveptr);
  while (token && i < ctx->fields_count) {
    TFields field_type = flNone;
    for (int j = 0; field_mappings[j].field_name != NULL; j++) {
      if (strcmp(token, field_mappings[j].field_name) == 0) {
        field_type = field_mappings[j].field_type;
        break;
      }
    }
    ctx->fields[i] = field_type;
    i++;
    token = strtok_r(NULL, ";", &saveptr);
  }
  free(s);
}

int parse_csv_line(const char *line, char **fields, int max_fields) {
  if (!line || !fields)
    return 0;

  int field_count = 0;
  const char *start = line;

  for (int i = 0; line[i] != '\0' && field_count < max_fields; i++) {
    if (line[i] == FIELD_SEP || line[i] == '\0') {
      int len = &line[i] - start;
      fields[field_count] = malloc(len + 1);
      if (fields[field_count]) {
        strncpy(fields[field_count], start, len);
        fields[field_count][len] = '\0';
        field_count++;
      }
      start = &line[i + 1];

      if (line[i] == '\0')
        break;
    }
  }

  if (field_count < max_fields && start < line + strlen(line)) {
    int len = strlen(start);
    fields[field_count] = malloc(len + 1);
    if (fields[field_count]) {
      strcpy(fields[field_count], start);
      field_count++;
    }
  }

  return field_count;
}

void free_csv_fields(char **fields, int count) {
  if (!fields)
    return;

  for (int i = 0; i < count; i++) {
    free(fields[i]);
  }
}

void parse_inpx_data(const char *input, TImportContext *ctx,
                     int online_collection, BookMeta *meta,
                     char **file_name_ptr, char **file_ext_ptr) {
  (void)online_collection;

  if (!input || !ctx || !meta || !file_name_ptr || !file_ext_ptr)
    return;

   // Проверка на валидность ctx
  if (!ctx->fields || ctx->fields_count == 0) {
    return;
  }

  char *fields[20] = {0};
  int field_count = parse_csv_line(input, fields, 20);

  if (field_count == 0) {
    return;
  }

  int max_fields =
      (field_count <= ctx->fields_count) ? field_count : ctx->fields_count;

  for (int i = 0; i < max_fields; i++) {
    if (!fields[i] || strlen(fields[i]) == 0)
      continue;

    switch (ctx->fields[i]) {
    case flAuthor: {
      if (!meta->author) {
        char *author_str = fields[i];
        char *last_name = author_str;
        char *first_name = strchr(author_str, ':');

        if (first_name) {
          *first_name = '\0';
          first_name++;
          char *middle_name = strchr(first_name, ':');

          char clean_last[100] = {0};
          char clean_first[100] = {0};
          char clean_middle[100] = {0};

          char *dst = clean_last;
          for (char *src = last_name;
               *src && dst < clean_last + sizeof(clean_last) - 1; src++) {
            *dst++ = (*src == ',') ? ' ' : *src;
          }
          *dst = '\0';

          if (middle_name) {
            *middle_name = '\0';
            middle_name++;

            dst = clean_first;
            for (char *src = first_name;
                 *src && dst < clean_first + sizeof(clean_first) - 1; src++) {
              *dst++ = (*src == ',') ? ' ' : *src;
            }
            *dst = '\0';

            dst = clean_middle;
            for (char *src = middle_name;
                 *src && dst < clean_middle + sizeof(clean_middle) - 1; src++) {
              *dst++ = (*src == ',') ? ' ' : *src;
            }
            *dst = '\0';

            meta->author = malloc(strlen(clean_last) + strlen(clean_first) +
                                  strlen(clean_middle) + 3);
            sprintf(meta->author, "%s %s %s", clean_last, clean_first,
                    clean_middle);
          } else {
            dst = clean_first;
            for (char *src = first_name;
                 *src && dst < clean_first + sizeof(clean_first) - 1; src++) {
              *dst++ = (*src == ',') ? ' ' : *src;
            }
            *dst = '\0';

            meta->author = malloc(strlen(clean_last) + strlen(clean_first) + 2);
            sprintf(meta->author, "%s %s", clean_last, clean_first);
          }
        } else {
          char clean_last[100] = {0};
          char *dst = clean_last;
          for (char *src = last_name;
               *src && dst < clean_last + sizeof(clean_last) - 1; src++) {
            *dst++ = (*src == ',') ? ' ' : *src;
          }
          *dst = '\0';
          meta->author = strdup(clean_last);
        }
      }
      break;
    }

    case flTitle:
      if (!meta->title) {
        meta->title = strdup(fields[i]);
      }
      break;

    case flSeries:
      if (!meta->series) {
        char *series_str = fields[i];

        char *first_bracket = strchr(series_str, '(');

        if (first_bracket) {
          size_t series_len = first_bracket - series_str;

          while (series_len > 0 &&
                 isspace((unsigned char)series_str[series_len - 1])) {
            series_len--;
          }

          meta->series = malloc(series_len + 1);
          strncpy(meta->series, series_str, series_len);
          meta->series[series_len] = '\0';
        } else {
          meta->series = strdup(series_str);
        }
      }
      break;

    case flSize:
      if (strlen(fields[i]) > 0) {
        meta->file_size = atol(fields[i]);
      }
      break;

    case flSerNo:
      if (strlen(fields[i]) > 0) {
        int serno_value = atoi(fields[i]);
        if (serno_value > 0) {
          meta->series_number = serno_value;
        }
      }
      break;

    case flFile:
      *file_name_ptr = strdup(fields[i]);
      break;

    case flExt:
      *file_ext_ptr = strdup(fields[i]);
      break;

    case flGenre:
      if (!meta->genre) {
        char *genre_str = fields[i];
        char *first_colon = strchr(genre_str, ':');
        if (first_colon) {
          size_t genre_len = first_colon - genre_str;
          meta->genre = malloc(genre_len + 1);
          strncpy(meta->genre, genre_str, genre_len);
          meta->genre[genre_len] = '\0';
        } else {
          meta->genre = strdup(genre_str);
        }
      }
      break;

    case flLang:
      if (!meta->language) {
        meta->language = strdup(fields[i]);
      }
      break;

    case flDate:
      if (strlen(fields[i]) >= 4) {
        meta->year = atoi(fields[i]);
      }
      break;

    default:
      break;
    }
  }

  free_csv_fields(fields, field_count);
}

int import_inpx_collection(const char *inpx_filename, DatabaseHandle *db_handle,
                           Config *config) {
  log_message(config, "INFO", "Starting CSV-based INPX import: %s",
              inpx_filename);

  if (access(inpx_filename, R_OK) != 0) {
    log_message(config, "ERROR", "Cannot access INPX file: %s (errno: %d)",
                inpx_filename, errno);
    return 0;
  }

  struct stat st;
  if (stat(inpx_filename, &st) == 0) {
    log_message(config, "DEBUG", "INPX file size: %lld bytes",
                (long long)st.st_size);
    if (st.st_size == 0) {
      log_message(config, "ERROR", "INPX file is empty: %s", inpx_filename);
      return 0;
    }
  } else {
    log_message(config, "ERROR", "Cannot stat INPX file: %s (errno: %d)",
                inpx_filename, errno);
    return 0;
  }

  struct archive *a = archive_read_new();

  archive_read_support_format_zip(a);
  archive_read_support_format_all(a);
  archive_read_support_filter_all(a);

  log_message(config, "DEBUG", "Attempting to open INPX archive...");
  int r = archive_read_open_filename(a, inpx_filename, 10240);

  if (r != ARCHIVE_OK) {
    log_message(config, "ERROR", "Cannot open INPX file as archive: %s",
                archive_error_string(a));
    archive_read_free(a);
    return 0;
  }

  log_message(config, "DEBUG", "Successfully opened INPX archive");

  TImportContext ctx = {0};
  get_inpx_fields(DEFAULT_STRUCTURE, &ctx);

  struct archive_entry *entry;
  int books_imported = 0;
  int files_processed = 0;
  int total_entries = 0;

  log_message(config, "DEBUG", "Reading archive contents...");

  while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
    total_entries++;
    const char *filename = archive_entry_pathname(entry);
    long long size = archive_entry_size(entry);
    int filetype = archive_entry_filetype(entry);

    log_message(config, "DEBUG", "Archive entry %d: %s (size: %lld, type: %d)",
                total_entries, filename, size, filetype);

    if (strstr(filename, "structure.info") ||
        strstr(filename, "collection.info") ||
        strstr(filename, "version.info")) {
      archive_read_data_skip(a);
      continue;
    }

    const char *ext = strrchr(filename, '.');
    if (!ext) {
      archive_read_data_skip(a);
      continue;
    }

    if (strcasecmp(ext, ".inp") != 0) {
      archive_read_data_skip(a);
      continue;
    }

    files_processed++;
    log_message(config, "INFO", "Processing INP file: %s", filename);

    if (size == 0) {
      archive_read_data_skip(a);
      continue;
    }

    if (size > 100 * 1024 * 1024) {
      log_message(config, "DEBUG", "INP file too large: %s (%lld bytes)",
                  filename, size);
      archive_read_data_skip(a);
      continue;
    }

    char *content = malloc(size + 1);
    if (!content) {
      log_message(config, "ERROR",
                  "Cannot allocate %lld bytes for INP file: %s", size,
                  filename);
      archive_read_data_skip(a);
      continue;
    }

    la_ssize_t bytes_read = archive_read_data(a, content, size);
    if (bytes_read != size) {
      log_message(config, "ERROR",
                  "Failed to read INP file: %s (read %zd of %lld bytes)",
                  filename, bytes_read, size);
      free(content);
      archive_read_data_skip(a);
      continue;
    }
    content[size] = '\0';

    char *line = content;
    int line_num = 0;
    int books_in_file = 0;

    while (line && *line) {
      char *line_end = line;
      while (*line_end &&
             !(*line_end == RECORD_SEP1 || *line_end == RECORD_SEP2)) {
        line_end++;
      }

      if (*line_end == '\0') {
        break;
      }

      char *next_line = line_end;
      if (*next_line == RECORD_SEP1)
        next_line++;
      if (*next_line == RECORD_SEP2)
        next_line++;

      *line_end = '\0';

      if (strlen(line) > 10) {
        BookMeta meta = {0};
        char *file_name = NULL;
        char *file_ext = NULL;

        parse_inpx_data(line, &ctx, 0, &meta, &file_name, &file_ext);

        if (meta.title && strlen(meta.title) > 0 && meta.author &&
            strlen(meta.author) > 0 && file_name && strlen(file_name) > 0) {

          char internal_path[256];
          if (file_ext && strlen(file_ext) > 0) {
            snprintf(internal_path, sizeof(internal_path), "%s.%s", file_name,
                     file_ext);
          } else {
            snprintf(internal_path, sizeof(internal_path), "%s.fb2", file_name);
          }

          char zip_filename[256];
          const char *inp_ext = strrchr(filename, '.');
          snprintf(zip_filename, sizeof(zip_filename), "%.*s.zip",
                   (int)(inp_ext - filename), filename);

          char archive_path[512];
          snprintf(archive_path, sizeof(archive_path), "%s/%s",
                   config->scanner.books_dir, zip_filename);

          char file_path[512];
          snprintf(file_path, sizeof(file_path), "%s/%s",
                   config->scanner.books_dir, zip_filename);

          insert_book_to_db(db_handle, file_path, &meta, archive_path,
                            internal_path, config);
          books_imported++;
          books_in_file++;

          if (books_imported % 10 == 0) {
            log_message(config, "INFO", "Imported %d books...", books_imported);
          }
        }

        free(file_name);
        free(file_ext);
        free_book_meta(&meta);
      }

      line = next_line;
      line_num++;
    }

    log_message(config, "DEBUG",
                "Processed %d lines in INP file, imported %d books", line_num,
                books_in_file);
    free(content);
  }

  log_message(config, "DEBUG", "Total archive entries processed: %d",
              total_entries);
  log_message(config, "DEBUG", "INP files processed: %d", files_processed);
  log_message(config, "DEBUG", "Books imported: %d", books_imported);

  archive_read_close(a);
  archive_read_free(a);
  free_import_context(&ctx);

  if (books_imported > 0) {
    log_message(config, "INFO",
                "INPX import completed: %d books from %d INP files",
                books_imported, files_processed);
  } else {
    log_message(config, "WARNING", "No books imported from INPX file");
  }

  return books_imported;
}

void free_import_context(TImportContext *ctx) {
  if (!ctx) return;

  // Безопасно освобождаем массив полей
  if (ctx->fields) {
    free(ctx->fields);
    ctx->fields = NULL;
  }

  // Сбрасываем счетчики
  ctx->fields_count = 0;
  ctx->use_stored_folder = 0;
  ctx->genres_type = 0;
}

char **extract_strings(const char *content, char separator, int *count) {
  (void)content;
  (void)separator;
  *count = 0;
  return NULL;
}

char *read_inp_file_from_zip(struct archive *a, const char *filename,
                             Config *config) {
  (void)a;
  (void)filename;
  (void)config;
  return NULL;
}
