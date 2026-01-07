#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <dirent.h>
#include <sys/stat.h>
#include <ctype.h>

// Функция для сравнения расширений без учета регистра
int has_extension(const char *filename, const char *extension) {
    const char *ext = strrchr(filename, '.');
    if (!ext) return 0;

    // Пропускаем точку
    ext++;

    // Сравниваем без учета регистра
    while (*ext && *extension) {
        if (tolower((unsigned char)*ext) != tolower((unsigned char)*extension)) {
            return 0;
        }
        ext++;
        extension++;
    }

    return (*ext == '\0' && *extension == '\0');
}

char* find_inpx_file_test(const char *books_dir) {
    printf("=== TEST INPX SEARCH ===\n");
    printf("Searching for ANY .inpx files in: %s\n\n", books_dir);

    DIR *dir = opendir(books_dir);
    if (!dir) {
        printf("ERROR: Cannot open directory\n");
        return NULL;
    }

    struct dirent *entry;
    char *found_path = NULL;
    int file_count = 0;

    printf("Files in directory:\n");
    while ((entry = readdir(dir)) != NULL) {
        if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0)
            continue;

        char full_path[4096];
        snprintf(full_path, sizeof(full_path), "%s/%s", books_dir, entry->d_name);

        struct stat statbuf;
        if (stat(full_path, &statbuf) == -1) {
            continue;
        }

        if (S_ISDIR(statbuf.st_mode)) {
            printf("DIR:  %s\n", entry->d_name);
        } else {
            printf("FILE: %s", entry->d_name);

            if (has_extension(entry->d_name, "inpx")) {
                printf("  -> INPX FILE FOUND!\n");
                if (!found_path) {
                    found_path = strdup(full_path);
                }
                file_count++;
            } else {
                printf("\n");
            }
        }
    }
    closedir(dir);

    if (found_path) {
        printf("\nRESULT: Found %d .inpx file(s), using: %s\n", file_count, found_path);
    } else {
        printf("\nRESULT: No .inpx files found\n");

        // Покажем какие файлы вообще есть в директории
        printf("\nAll files in directory:\n");
        dir = opendir(books_dir);
        if (dir) {
            while ((entry = readdir(dir)) != NULL) {
                if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0)
                    continue;
                printf("  %s\n", entry->d_name);
            }
            closedir(dir);
        }
    }

    return found_path;
}

int main(int argc, char *argv[]) {
    if (argc != 2) {
        printf("Usage: %s <books_directory>\n", argv[0]);
        return 1;
    }

    char *found = find_inpx_file_test(argv[1]);
    if (found) {
        free(found);
    }

    return 0;
}
