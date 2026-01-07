#include "common.h"
#include "utils.h"
#include <stdlib.h>
#include <string.h>
#include <ctype.h>
#include <unistd.h>
#include <fcntl.h>
#include <sys/file.h>
#include <openssl/evp.h>
#include <openssl/md5.h>
#include <openssl/sha.h>
#include <iconv.h>
#include <stdio.h>
#include <locale.h>


// Функция для проверки, является ли строка валидной UTF-8
int is_valid_utf8(const char *str) {
    if (!str) return 0;

    const unsigned char *bytes = (const unsigned char *)str;
    while (*bytes) {
        if ((bytes[0] & 0x80) == 0x00) {
            // 0xxxxxxx
            bytes += 1;
        } else if ((bytes[0] & 0xE0) == 0xC0) {
            // 110xxxxx 10xxxxxx
            if ((bytes[1] & 0xC0) != 0x80) return 0;
            bytes += 2;
        } else if ((bytes[0] & 0xF0) == 0xE0) {
            // 1110xxxx 10xxxxxx 10xxxxxx
            if ((bytes[1] & 0xC0) != 0x80 || (bytes[2] & 0xC0) != 0x80) return 0;
            bytes += 3;
        } else if ((bytes[0] & 0xF8) == 0xF0) {
            // 11110xxx 10xxxxxx 10xxxxxx 10xxxxxx
            if ((bytes[1] & 0xC0) != 0x80 ||
                (bytes[2] & 0xC0) != 0x80 ||
                (bytes[3] & 0xC0) != 0x80) return 0;
            bytes += 4;
        } else {
            return 0;
        }
    }
    return 1;
}

// Функция для определения кодировки строки
const char* detect_string_encoding(const char *str) {
    if (!str) return "ASCII";

    // Проверяем UTF-8
    if (is_valid_utf8(str)) {
        return "UTF-8";
    }

    // Проверяем возможные однобайтовые кодировки с кириллицей
    const unsigned char *p = (const unsigned char *)str;
    int has_cp866 = 0;
    int has_windows1251 = 0;
    int has_koi8r = 0;

    while (*p) {
        // CP866 (DOS Russian): 0x80-0xAF, 0xE0-0xEF
        if ((*p >= 0x80 && *p <= 0xAF) || (*p >= 0xE0 && *p <= 0xEF)) {
            has_cp866 = 1;
        }
        // Windows-1251: 0xC0-0xFF
        if (*p >= 0xC0 && *p <= 0xFF) {
            has_windows1251 = 1;
        }
        // KOI8-R: 0xC0-0xFF (другое распределение)
        if (*p >= 0xC0 && *p <= 0xFF) {
            has_koi8r = 1;
        }
        p++;
    }

    // Эвристики для определения кодировки
    if (has_cp866 && !has_windows1251) {
        return "CP866";
    } else if (has_windows1251 && !has_cp866) {
        return "WINDOWS-1251";
    } else if (has_koi8r) {
        return "KOI8-R";
    }

    // По умолчанию предполагаем Windows-1251 для кириллицы
    return "WINDOWS-1251";
}

char* read_file_content(const char *filepath) {
    FILE *file = fopen(filepath, "rb");
    if (!file) return NULL;

    fseek(file, 0, SEEK_END);
    long file_size = ftell(file);
    fseek(file, 0, SEEK_SET);

    long read_size = file_size > 65536 ? 65536 : file_size;
    char *content = malloc(read_size + 1);
    if (!content) {
        fclose(file);
        return NULL;
    }

    size_t bytes_read = fread(content, 1, read_size, file);
    content[bytes_read] = '\0';
    fclose(file);

    return content;
}

void trim_string(char *str) {
    if (!str) return;

    char *end;

    while (isspace((unsigned char)*str)) str++;

    end = str + strlen(str) - 1;
    while (end > str && isspace((unsigned char)*end)) end--;

    *(end + 1) = '\0';

    char *dest = str;
    int prev_space = 0;
    for (char *src = str; *src; src++) {
        if (isspace((unsigned char)*src)) {
            if (!prev_space) {
                *dest++ = ' ';
                prev_space = 1;
            }
        } else {
            *dest++ = *src;
            prev_space = 0;
        }
    }
    *dest = '\0';
}

char* convert_encoding(const char *text, const char *from_encoding, const char *to_encoding) {
    if (!text || strlen(text) == 0) return NULL;

    // Если кодировки совпадают, возвращаем копию
    if (strcasecmp(from_encoding, to_encoding) == 0) {
        return strdup(text);
    }

    iconv_t cd = iconv_open(to_encoding, from_encoding);
    if (cd == (iconv_t)-1) {
        // Пробуем альтернативные имена кодировок
        if (strcasecmp(from_encoding, "WINDOWS-1251") == 0) {
            cd = iconv_open(to_encoding, "CP1251");
        } else if (strcasecmp(from_encoding, "CP866") == 0) {
            cd = iconv_open(to_encoding, "IBM866");
        }

        if (cd == (iconv_t)-1) {
            return strdup(text); // Возвращаем оригинал если не можем конвертировать
        }
    }

    size_t in_len = strlen(text);
    size_t out_len = in_len * 4; // UTF-8 может быть больше
    char *out_buf = malloc(out_len + 1);
    if (!out_buf) {
        iconv_close(cd);
        return strdup(text);
    }

    char *in_ptr = (char*)text;
    char *out_ptr = out_buf;

    memset(out_buf, 0, out_len + 1);

    size_t in_remaining = in_len;
    size_t out_remaining = out_len;

    if (iconv(cd, &in_ptr, &in_remaining, &out_ptr, &out_remaining) == (size_t)-1) {
        free(out_buf);
        iconv_close(cd);
        return strdup(text);
    }

    *out_ptr = '\0';
    iconv_close(cd);

    return out_buf;
}

int detect_encoding(const char *text) {
    if (!text) return 0;

    // Проверяем UTF-8
    if (is_valid_utf8(text)) {
        return 1; // UTF-8
    }

    // Проверяем Windows-1251 по характерным символам
    const unsigned char *p = (const unsigned char *)text;
    int has_cyrillic = 0;

    while (*p) {
        // Диапазон русских букв в Windows-1251: А-Я а-я
        if ((*p >= 0xC0 && *p <= 0xFF) ||
            (*p == 0xA8) || (*p == 0xB8)) { // Ё ё
            has_cyrillic = 1;
            break;
        }
        p++;
    }

    if (has_cyrillic) {
        return 2; // Windows-1251 или другая однобайтовая кириллица
    }

    return 0; // ASCII или неизвестно
}

char* clean_html_tags(const char *html) {
    if (!html) return NULL;

    size_t len = strlen(html);
    char *result = malloc(len + 1);
    if (!result) return NULL;

    char *dest = result;
    int in_tag = 0;

    for (size_t i = 0; i < len; i++) {
        if (html[i] == '<') {
            in_tag = 1;
            continue;
        }
        if (html[i] == '>') {
            in_tag = 0;
            continue;
        }
        if (!in_tag) {
            *dest++ = html[i];
        }
    }
    *dest = '\0';

    trim_string(result);

    if (strlen(result) == 0) {
        free(result);
        return NULL;
    }

    return result;
}

int is_already_running(const char *lockfile_path) {
    int lockfile = open(lockfile_path, O_CREAT | O_RDWR, 0644);
    if (lockfile == -1) {
        return 1;
    }

    struct flock fl;
    fl.l_type = F_WRLCK;
    fl.l_whence = SEEK_SET;
    fl.l_start = 0;
    fl.l_len = 0;

    if (fcntl(lockfile, F_SETLK, &fl) == -1) {
        close(lockfile);
        return 1;
    }

    return 0;
}

char* calculate_file_hash(const char *filepath, const char *algorithm) {
    FILE *file = fopen(filepath, "rb");
    if (!file) {
        return NULL;
    }

    const EVP_MD *md_algorithm = NULL;

    if (strcasecmp(algorithm, "md5") == 0) {
        md_algorithm = EVP_md5();
    } else if (strcasecmp(algorithm, "sha1") == 0) {
        md_algorithm = EVP_sha1();
    } else if (strcasecmp(algorithm, "sha256") == 0) {
        md_algorithm = EVP_sha256();
    } else if (strcasecmp(algorithm, "sha512") == 0) {
        md_algorithm = EVP_sha512();
    } else {
        md_algorithm = EVP_sha256();
    }

    EVP_MD_CTX *mdctx = EVP_MD_CTX_new();
    if (!mdctx) {
        fclose(file);
        return NULL;
    }

    if (EVP_DigestInit_ex(mdctx, md_algorithm, NULL) != 1) {
        EVP_MD_CTX_free(mdctx);
        fclose(file);
        return NULL;
    }

    unsigned char buffer[65536];
    size_t bytes_read;

    while ((bytes_read = fread(buffer, 1, sizeof(buffer), file))) {
        if (EVP_DigestUpdate(mdctx, buffer, bytes_read) != 1) {
            EVP_MD_CTX_free(mdctx);
            fclose(file);
            return NULL;
        }
    }

    unsigned char hash[EVP_MAX_MD_SIZE];
    unsigned int hash_len;

    if (EVP_DigestFinal_ex(mdctx, hash, &hash_len) != 1) {
        EVP_MD_CTX_free(mdctx);
        fclose(file);
        return NULL;
    }

    EVP_MD_CTX_free(mdctx);
    fclose(file);

    char *hash_str = malloc(hash_len * 2 + 1);
    for (unsigned int i = 0; i < hash_len; i++) {
        sprintf(hash_str + (i * 2), "%02x", hash[i]);
    }
    hash_str[hash_len * 2] = '\0';

    return hash_str;
}
