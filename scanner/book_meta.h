#ifndef BOOK_META_H
#define BOOK_META_H

typedef struct {
    char *title;
    char *author;
    char *genre;
    char *series;
    int series_number;
    int year;
    char *language;
    char *publisher;
    char *description;
    long file_size;
} BookMeta;

#endif
