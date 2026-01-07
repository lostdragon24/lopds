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

    iconv_t cd = iconv_open(to_encoding, from_encoding);
    if (cd == (iconv_t)-1) {
        return NULL;
    }

    size_t in_len = strlen(text);
    size_t out_len = in_len * 4;
    char *out_buf = malloc(out_len + 1);
    if (!out_buf) {
        iconv_close(cd);
        return NULL;
    }

    char *in_ptr = (char*)text;
    char *out_ptr = out_buf;

    memset(out_buf, 0, out_len + 1);

    if (iconv(cd, &in_ptr, &in_len, &out_ptr, &out_len) == (size_t)-1) {
        free(out_buf);
        iconv_close(cd);
        return NULL;
    }

    *out_ptr = '\0';
    iconv_close(cd);

    return out_buf;
}

int detect_encoding(const char *text) {
    if (!text) return 0;

    int is_utf8 = 1;
    const unsigned char *p = (const unsigned char *)text;
    while (*p) {
        if (*p < 0x80) {
            p++;
        } else if (*p < 0xC0) {
            is_utf8 = 0;
            break;
        } else if (*p < 0xE0) {
            if (p[1] == 0 || (p[1] & 0xC0) != 0x80) {
                is_utf8 = 0;
                break;
            }
            p += 2;
        } else if (*p < 0xF0) {
            if (p[1] == 0 || p[2] == 0 ||
                (p[1] & 0xC0) != 0x80 || (p[2] & 0xC0) != 0x80) {
                is_utf8 = 0;
                break;
            }
            p += 3;
        } else {
            is_utf8 = 0;
            break;
        }
    }

    if (is_utf8) return 1;

    return 2;
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
