#ifndef INPX_PARSER_H
#define INPX_PARSER_H

#include "config.h"
#include "database.h"
#include "metadata.h"
#include <archive.h>
#include <archive_entry.h>


typedef enum {
    flNone,
    flAuthor,
    flTitle,
    flSeries,
    flSerNo,
    flGenre,
    flLibID,
    flInsideNo,
    flFile,
    flFolder,
    flExt,
    flSize,
    flLang,
    flDate,
    flCode,
    flDeleted,
    flRate,
    flURI,
    flLibRate,
    flKeyWords
} TFields;

typedef struct {
    char *Code;
    TFields FType;
} TFieldDescr;

typedef struct {
    TFields *fields;
    int fields_count;
    int use_stored_folder;
    int genres_type; // 0 - fb2, 1 - other
} TImportContext;

// Основные функции INPX парсера
int import_inpx_collection(const char *inpx_filename, DatabaseHandle *db_handle, Config *config);
void parse_inpx_data(const char *input, TImportContext *ctx, int online_collection, BookMeta *meta,
                    char **file_name_ptr, char **file_ext_ptr);
void get_inpx_fields(const char *structure_info, TImportContext *ctx);
void free_import_context(TImportContext *ctx);

// Вспомогательные функции
char** extract_strings(const char *content, char separator, int *count);
void free_strings_array(char **array, int count);
char* read_inp_file_from_zip(struct archive *a, const char *filename, Config *config);

#endif
