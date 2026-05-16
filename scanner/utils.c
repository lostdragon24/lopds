#include "utils.h"
#include "common.h"
#include "path_validation.h"
#include <ctype.h>
#include <errno.h>
#include <fcntl.h>
#include <iconv.h>
#include <locale.h>
#include <openssl/evp.h>
#include <openssl/md5.h>
#include <openssl/sha.h>
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <time.h>
#include <unistd.h>

#ifdef _WIN32
#include <psapi.h>
#include <windows.h>
#else
#include <sys/sysinfo.h>
#include <sys/utsname.h>
#endif

//------------------------------------------------------------------------------
// Безопасные функции работы с памятью и строками
//------------------------------------------------------------------------------

int safe_snprintf(char *str, size_t size, const char *format, ...) {
  if (!str || size == 0 || !format) {
    errno = EINVAL;
    return -1;
  }

  va_list args;
  va_start(args, format);
  int result = vsnprintf(str, size, format, args);
  va_end(args);

  if (result < 0) {
    str[0] = '\0';
    return -1;
  }

  if ((size_t)result >= size) {
    str[size - 1] = '\0';
    errno = ENOBUFS;
    return -1;
  }

  return result;
}

char *safe_strncpy(char *dest, const char *src, size_t dest_size) {
  if (!dest || !src || dest_size == 0) {
    errno = EINVAL;
    return NULL;
  }

  strncpy(dest, src, dest_size - 1);
  dest[dest_size - 1] = '\0';
  return dest;
}

void *safe_malloc(size_t size) {
  if (size == 0) {
    errno = EINVAL;
    return NULL;
  }

  if (size > (1024 * 1024 * 100)) { // 100 MB max
    errno = ENOMEM;
    return NULL;
  }

  void *ptr = malloc(size);
  if (!ptr) {
    errno = ENOMEM;
  }
  return ptr;
}

void *safe_calloc(size_t nmemb, size_t size) {
  if (nmemb == 0 || size == 0) {
    errno = EINVAL;
    return NULL;
  }

  // Проверка на переполнение
  if (nmemb > SIZE_MAX / size) {
    errno = ENOMEM;
    return NULL;
  }

  size_t total = nmemb * size;
  if (total > (1024 * 1024 * 100)) { // 100 MB max
    errno = ENOMEM;
    return NULL;
  }

  void *ptr = calloc(nmemb, size);
  if (!ptr) {
    errno = ENOMEM;
  }
  return ptr;
}

char *safe_strdup(const char *s) {
  if (!s) {
    errno = EINVAL;
    return NULL;
  }

  size_t len = strlen(s);
  if (len > (1024 * 1024 * 10)) { // 10 MB max for strings
    errno = ENOMEM;
    return NULL;
  }

  char *dup = malloc(len + 1);
  if (!dup) {
    errno = ENOMEM;
    return NULL;
  }

  memcpy(dup, s, len + 1);
  return dup;
}

char *safe_fgets(char *buffer, int size, FILE *stream) {
  if (!buffer || size <= 0 || !stream) {
    errno = EINVAL;
    return NULL;
  }

  char *result = fgets(buffer, size, stream);
  if (result) {
    buffer[size - 1] = '\0';

    // Удаляем \n в конце если есть
    size_t len = strlen(buffer);
    if (len > 0 && buffer[len - 1] == '\n') {
      buffer[len - 1] = '\0';
    }
    // Удаляем \r в конце если есть
    if (len > 1 && buffer[len - 2] == '\r') {
      buffer[len - 2] = '\0';
    }
  }
  return result;
}

//------------------------------------------------------------------------------
// Функции для работы с файлами
//------------------------------------------------------------------------------

char *read_file_content(const char *filepath) {
  if (!filepath)
    return NULL;

  // Проверяем безопасность пути
  if (!is_path_safe(filepath, NULL)) {
    fprintf(stderr, "Unsafe file path: %s\n", filepath);
    return NULL;
  }

  FILE *file = fopen(filepath, "rb");
  if (!file) {
    fprintf(stderr, "Cannot open file: %s (%s)\n", filepath, strerror(errno));
    return NULL;
  }

  // Получаем размер файла
  if (fseek(file, 0, SEEK_END) != 0) {
    fclose(file);
    return NULL;
  }

  long file_size = ftell(file);
  if (file_size < 0) {
    fclose(file);
    return NULL;
  }

  rewind(file);

  // Ограничиваем размер читаемого файла (80 MB для текстовых файлов)
  long read_size = file_size;
  if (read_size > 80 * 1024 * 1024) {
    read_size = 80 * 1024 * 1024;
    fprintf(stderr, "Warning: File too large, reading only first 80MB: %s\n",
            filepath);
  }

  char *content = safe_malloc(read_size + 1);
  if (!content) {
    fclose(file);
    return NULL;
  }

  size_t bytes_read = fread(content, 1, read_size, file);
  content[bytes_read] = '\0';
  fclose(file);

  return content;
}

const char *normalize_file_type(const char *filename) {
  if (!filename)
    return "unknown";

  const char *ext = strrchr(filename, '.');
  if (!ext || strlen(ext) <= 1) {
    return "unknown";
  }

  const char *raw_type = ext + 1;

  // Проверяем известные форматы
  if (strcasecmp(raw_type, "pdf") == 0)
    return "pdf";
  if (strcasecmp(raw_type, "fb2") == 0)
    return "fb2";
  if (strcasecmp(raw_type, "epub") == 0)
    return "epub";
  if (strcasecmp(raw_type, "txt") == 0)
    return "txt";
  if (strcasecmp(raw_type, "mobi") == 0)
    return "mobi";
  if (strcasecmp(raw_type, "zip") == 0)
    return "zip";
  if (strcasecmp(raw_type, "rar") == 0)
    return "rar";
  if (strcasecmp(raw_type, "7z") == 0)
    return "7z";

  // Для неизвестных - возвращаем как есть в нижнем регистре
  static char lower_type[32];
  for (int i = 0; raw_type[i] && i < 31; i++) {
    lower_type[i] = tolower(raw_type[i]);
  }
  lower_type[strlen(raw_type)] = '\0';

  return lower_type;
}

int write_file_content(const char *filepath, const char *content, size_t len) {
  if (!filepath || !content)
    return 0;

  // Проверяем безопасность пути
  if (!is_path_safe(filepath, NULL)) {
    fprintf(stderr, "Unsafe file path: %s\n", filepath);
    return 0;
  }

  FILE *file = fopen(filepath, "wb");
  if (!file) {
    fprintf(stderr, "Cannot create file: %s (%s)\n", filepath, strerror(errno));
    return 0;
  }

  size_t written = fwrite(content, 1, len, file);
  fclose(file);

  return written == len;
}

//------------------------------------------------------------------------------
// Функции для работы со строками
//------------------------------------------------------------------------------

void trim_string(char *str) {
  if (!str)
    return;

  // Trim leading spaces
  char *start = str;
  while (isspace((unsigned char)*start))
    start++;

  // Trim trailing spaces
  char *end = start + strlen(start) - 1;
  while (end > start && isspace((unsigned char)*end))
    end--;

  // Move trimmed string to beginning
  if (start != str) {
    memmove(str, start, end - start + 1);
  }
  str[end - start + 1] = '\0';
}

char *str_replace(const char *str, const char *old, const char *new) {
  if (!str || !old || !new)
    return NULL;

  size_t str_len = strlen(str);
  size_t old_len = strlen(old);
  size_t new_len = strlen(new);

  if (old_len == 0)
    return safe_strdup(str);

  // Подсчитываем количество замен
  int count = 0;
  const char *p = str;
  while ((p = strstr(p, old)) != NULL) {
    count++;
    p += old_len;
  }

  if (count == 0)
    return safe_strdup(str);

  // Выделяем память для результата
  size_t result_len = str_len + count * (new_len - old_len) + 1;
  char *result = safe_malloc(result_len);
  if (!result)
    return NULL;

  // Выполняем замены
  char *dest = result;
  const char *src = str;
  const char *next;

  while ((next = strstr(src, old)) != NULL) {
    size_t len = next - src;
    memcpy(dest, src, len);
    dest += len;
    memcpy(dest, new, new_len);
    dest += new_len;
    src = next + old_len;
  }
  strcpy(dest, src);

  return result;
}

char **str_split(const char *str, char delimiter, int *count) {
  if (!str || !count)
    return NULL;

  *count = 0;

  // Подсчитываем количество элементов
  const char *p = str;
  while (*p) {
    if (*p == delimiter)
      (*count)++;
    p++;
  }
  (*count)++; // Добавляем последний элемент

  // Выделяем память для массива указателей
  char **result = safe_malloc((*count + 1) * sizeof(char *));
  if (!result) {
    *count = 0;
    return NULL;
  }

  // Разбиваем строку
  int idx = 0;
  const char *start = str;
  p = str;

  while (*p) {
    if (*p == delimiter) {
      size_t len = p - start;
      result[idx] = safe_malloc(len + 1);
      if (result[idx]) {
        memcpy(result[idx], start, len);
        result[idx][len] = '\0';
      }
      idx++;
      start = p + 1;
    }
    p++;
  }

  // Добавляем последний элемент
  size_t len = p - start;
  result[idx] = safe_malloc(len + 1);
  if (result[idx]) {
    memcpy(result[idx], start, len);
    result[idx][len] = '\0';
  }
  result[++idx] = NULL;

  return result;
}

void free_strings_array(char **array, int count) {
  if (!array)
    return;

  for (int i = 0; i < count; i++) {
    if (array[i])
      free(array[i]);
  }
  free(array);
}

//------------------------------------------------------------------------------
// Функции для работы с кодировками
//------------------------------------------------------------------------------

int is_valid_utf8(const char *str) {
  if (!str)
    return 0;

  const unsigned char *bytes = (const unsigned char *)str;
  while (*bytes) {
    if ((bytes[0] & 0x80) == 0x00) {
      bytes += 1;
    } else if ((bytes[0] & 0xE0) == 0xC0) {
      if ((bytes[1] & 0xC0) != 0x80)
        return 0;
      bytes += 2;
    } else if ((bytes[0] & 0xF0) == 0xE0) {
      if ((bytes[1] & 0xC0) != 0x80 || (bytes[2] & 0xC0) != 0x80)
        return 0;
      bytes += 3;
    } else if ((bytes[0] & 0xF8) == 0xF0) {
      if ((bytes[1] & 0xC0) != 0x80 || (bytes[2] & 0xC0) != 0x80 ||
          (bytes[3] & 0xC0) != 0x80)
        return 0;
      bytes += 4;
    } else {
      return 0;
    }
  }
  return 1;
}

const char *detect_string_encoding(const char *str) {
  if (!str)
    return "ASCII";

  if (is_valid_utf8(str)) {
    return "UTF-8";
  }

  const unsigned char *p = (const unsigned char *)str;
  int has_cp866 = 0;
  int has_windows1251 = 0;

  while (*p) {
    // Для unsigned char сравнение с 0xFF всегда true, поэтому используем маску
    if ((*p & 0x80) != 0) { // Если старший бит установлен
      if ((*p >= 0x80 && *p <= 0xAF) || (*p >= 0xE0 && *p <= 0xEF)) {
        has_cp866 = 1;
      }
      if (*p >= 0xC0) { // Все русские буквы в win1251 начинаются с 0xC0
        has_windows1251 = 1;
      }
      if (*p == 0xA8 || *p == 0xB8) { // Ё ё
        has_windows1251 = 1;
      }
    }
    p++;
  }

  if (has_cp866 && !has_windows1251) {
    return "CP866";
  } else if (has_windows1251) {
    return "WINDOWS-1251";
  }

  return "ASCII";
}

int detect_encoding(const char *text) {
  if (!text)
    return 0;

  if (is_valid_utf8(text)) {
    return 1; // UTF-8
  }

  const unsigned char *p = (const unsigned char *)text;
  while (*p) {
    if ((*p & 0x80) != 0) { // Если старший бит установлен
      return 2;             // Windows-1251 или другая кириллица
    }
    p++;
  }

  return 0; // ASCII или неизвестно
}

char *convert_encoding(const char *text, const char *from_encoding,
                       const char *to_encoding) {
  if (!text || strlen(text) == 0)
    return NULL;

  if (strcasecmp(from_encoding, to_encoding) == 0) {
    return safe_strdup(text);
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
      return safe_strdup(text);
    }
  }

  size_t in_len = strlen(text);
  size_t out_len = in_len * 4 + 1;
  char *out_buf = safe_malloc(out_len);
  if (!out_buf) {
    iconv_close(cd);
    return safe_strdup(text);
  }

  char *in_ptr = (char *)text;
  char *out_ptr = out_buf;
  size_t in_remaining = in_len;
  size_t out_remaining = out_len;

  memset(out_buf, 0, out_len);

  size_t result = iconv(cd, &in_ptr, &in_remaining, &out_ptr, &out_remaining);
  iconv_close(cd);

  if (result == (size_t)-1) {
    free(out_buf);
    return safe_strdup(text);
  }

  *out_ptr = '\0';
  return out_buf;
}

//------------------------------------------------------------------------------
// Функции для работы с HTML/XML
//------------------------------------------------------------------------------

char *clean_html_tags(const char *html) {
  if (!html)
    return NULL;

  size_t len = strlen(html);
  char *result = safe_malloc(len + 1);
  if (!result)
    return NULL;

  char *dest = result;
  int in_tag = 0;
  int in_entity = 0;

  for (size_t i = 0; i < len; i++) {
    if (html[i] == '<') {
      in_tag = 1;
      continue;
    }
    if (html[i] == '>') {
      in_tag = 0;
      continue;
    }
    if (html[i] == '&') {
      in_entity = 1;
      continue;
    }
    if (html[i] == ';' && in_entity) {
      in_entity = 0;
      continue;
    }
    if (!in_tag && !in_entity) {
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

char *extract_xml_tag(const char *xml, const char *tag_name) {
  if (!xml || !tag_name)
    return NULL;

  char open_tag[256];
  char close_tag[256];

  snprintf(open_tag, sizeof(open_tag), "<%s>", tag_name);
  snprintf(close_tag, sizeof(close_tag), "</%s>", tag_name);

  char *start = strstr(xml, open_tag);
  if (!start) {
    // Пробуем с атрибутами
    snprintf(open_tag, sizeof(open_tag), "<%s ", tag_name);
    start = strstr(xml, open_tag);
    if (!start)
      return NULL;
  }

  start = strchr(start, '>');
  if (!start)
    return NULL;
  start++;

  char *end = strstr(start, close_tag);
  if (!end)
    return NULL;

  size_t len = end - start;
  char *content = safe_malloc(len + 1);
  if (!content)
    return NULL;

  memcpy(content, start, len);
  content[len] = '\0';

  trim_string(content);
  return content;
}

char *extract_xml_attribute(const char *xml, const char *tag_name,
                            const char *attr_name) {
  if (!xml || !tag_name || !attr_name)
    return NULL;

  char search_tag[256];
  snprintf(search_tag, sizeof(search_tag), "<%s", tag_name);

  char *tag_start = strstr(xml, search_tag);
  if (!tag_start)
    return NULL;

  char attr_search[256];
  snprintf(attr_search, sizeof(attr_search), "%s=\"", attr_name);

  char *attr_start = strstr(tag_start, attr_search);
  if (!attr_start)
    return NULL;

  attr_start += strlen(attr_search);
  char *attr_end = strchr(attr_start, '"');
  if (!attr_end)
    return NULL;

  size_t len = attr_end - attr_start;
  char *value = safe_malloc(len + 1);
  if (!value)
    return NULL;

  memcpy(value, attr_start, len);
  value[len] = '\0';

  return value;
}

//------------------------------------------------------------------------------
// Функции для работы с хешами
//------------------------------------------------------------------------------

int is_valid_hash_algorithm(const char *algorithm) {
  if (!algorithm)
    return 0;

  return (strcasecmp(algorithm, "md5") == 0 ||
          strcasecmp(algorithm, "sha1") == 0 ||
          strcasecmp(algorithm, "sha256") == 0 ||
          strcasecmp(algorithm, "sha512") == 0);
}

void print_hash_algorithms(void) {
  printf("Supported hash algorithms:\n");
  printf("  md5    - MD5 (128 bit)\n");
  printf("  sha1   - SHA-1 (160 bit)\n");
  printf("  sha256 - SHA-256 (256 bit)\n");
  printf("  sha512 - SHA-512 (512 bit)\n");
}

char *calculate_file_hash(const char *filepath, const char *algorithm) {
  if (!filepath || !algorithm)
    return NULL;

  // Проверяем безопасность пути
  if (!is_path_safe(filepath, NULL)) {
    fprintf(stderr, "Unsafe file path: %s\n", filepath);
    return NULL;
  }

  FILE *file = fopen(filepath, "rb");
  if (!file) {
    fprintf(stderr, "Cannot open file for hashing: %s (%s)\n", filepath,
            strerror(errno));
    return NULL;
  }

  // Получаем размер файла для прогресса
  fseek(file, 0, SEEK_END);
  long file_size = ftell(file);
  rewind(file);

  fprintf(stderr, "Calculating %s hash for %s (size: %.2f MB)...\n", algorithm,
          filepath, file_size / (1024.0 * 1024.0));

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

  unsigned char buffer[65536]; // 64KB буфер
  size_t bytes_read;
  long total_read = 0;
  int last_percent = -1;

  while ((bytes_read = fread(buffer, 1, sizeof(buffer), file)) > 0) {
    if (EVP_DigestUpdate(mdctx, buffer, bytes_read) != 1) {
      EVP_MD_CTX_free(mdctx);
      fclose(file);
      return NULL;
    }

    total_read += bytes_read;

    // Показываем прогресс каждые 5%
    if (file_size > 0) {
      int percent = (int)((total_read * 100) / file_size);
      if (percent >= last_percent + 5) {
        fprintf(stderr, "  Hash progress: %d%%\r", percent);
        fflush(stderr);
        last_percent = percent;
      }
    }
  }

  fprintf(stderr, "  Hash progress: 100%%\n");

  unsigned char hash[EVP_MAX_MD_SIZE];
  unsigned int hash_len;

  if (EVP_DigestFinal_ex(mdctx, hash, &hash_len) != 1) {
    EVP_MD_CTX_free(mdctx);
    fclose(file);
    return NULL;
  }

  EVP_MD_CTX_free(mdctx);
  fclose(file);

  char *hash_str = safe_malloc(hash_len * 2 + 1);
  if (!hash_str)
    return NULL;

  for (unsigned int i = 0; i < hash_len; i++) {
    sprintf(hash_str + (i * 2), "%02x", hash[i]);
  }
  hash_str[hash_len * 2] = '\0';

  return hash_str;
}

char *calculate_buffer_hash(const unsigned char *buffer, size_t len,
                            const char *algorithm) {
  if (!buffer || len == 0 || !algorithm)
    return NULL;

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
  if (!mdctx)
    return NULL;

  if (EVP_DigestInit_ex(mdctx, md_algorithm, NULL) != 1) {
    EVP_MD_CTX_free(mdctx);
    return NULL;
  }

  if (EVP_DigestUpdate(mdctx, buffer, len) != 1) {
    EVP_MD_CTX_free(mdctx);
    return NULL;
  }

  unsigned char hash[EVP_MAX_MD_SIZE];
  unsigned int hash_len;

  if (EVP_DigestFinal_ex(mdctx, hash, &hash_len) != 1) {
    EVP_MD_CTX_free(mdctx);
    return NULL;
  }

  EVP_MD_CTX_free(mdctx);

  char *hash_str = safe_malloc(hash_len * 2 + 1);
  if (!hash_str)
    return NULL;

  for (unsigned int i = 0; i < hash_len; i++) {
    sprintf(hash_str + (i * 2), "%02x", hash[i]);
  }
  hash_str[hash_len * 2] = '\0';

  return hash_str;
}

//------------------------------------------------------------------------------
// Функции для проверки запуска
//------------------------------------------------------------------------------

int is_already_running(const char *lockfile_path, Config *config) {
  if (!lockfile_path) {
    log_message(config, "ERROR", "NULL lockfile path");
    return 1; // В целях безопасности считаем, что уже запущен
  }

  // Проверяем безопасность пути
  if (!is_path_safe(lockfile_path, config)) {
    log_message(config, "ERROR", "Unsafe lockfile path: %s", lockfile_path);
    return 1;
  }

  // Пытаемся открыть существующий lock-файл
  int lockfile = open(lockfile_path, O_RDWR, 0644);

  if (lockfile == -1) {
    if (errno == ENOENT) {
      // Файл не существует - отлично, программа не запущена
      log_message(config, "DEBUG", "Lock file does not exist: %s",
                  lockfile_path);
      return 0;
    } else {
      // Другая ошибка при открытии
      log_message(config, "ERROR", "Cannot open lockfile %s: %s", lockfile_path,
                  strerror(errno));
      return 1; // Считаем, что запущен для безопасности
    }
  }

  // Пытаемся установить эксклюзивную блокировку
#ifdef _WIN32
  HANDLE hFile = (HANDLE)_get_osfhandle(lockfile);
  if (hFile == INVALID_HANDLE_VALUE) {
    log_message(config, "ERROR", "Invalid file handle for lockfile");
    close(lockfile);
    return 1;
  }

  OVERLAPPED ov = {0};
  if (!LockFileEx(hFile, LOCKFILE_EXCLUSIVE_LOCK | LOCKFILE_FAIL_IMMEDIATELY, 0,
                  1, 0, &ov)) {
    // Файл уже заблокирован - программа запущена
    DWORD error = GetLastError();
    log_message(config, "DEBUG",
                "Lockfile is locked by another process (error: %lu)", error);

    // Прочитаем PID из файла для информации
    char pid_buf[32] = {0};
    lseek(lockfile, 0, SEEK_SET);

    // read(lockfile, pid_buf, sizeof(pid_buf) - 1);

    // Читаем PID из lock-файла (игнорируем ошибку — это не критично)
    ssize_t r = read(lockfile, pid_buf, sizeof(pid_buf) - 1);
    if (r > 0) {
      pid_buf[r] = '\0'; // Гарантируем нуль-терминацию

      log_message(config, "INFO", "Another instance is running (PID: %s)",
                  pid_buf);

      close(lockfile);
      return 1;
    }
#else
  struct flock fl;
  fl.l_type = F_WRLCK;
  fl.l_whence = SEEK_SET;
  fl.l_start = 0;
  fl.l_len = 0;
  fl.l_pid = 0;

  if (fcntl(lockfile, F_SETLK, &fl) == -1) {
    if (errno == EACCES || errno == EAGAIN) {
      // Файл уже заблокирован
      log_message(config, "DEBUG", "Lockfile is locked by another process");

      // Пытаемся получить информацию о процессе, который держит блокировку
      fl.l_type = F_WRLCK;
      if (fcntl(lockfile, F_GETLK, &fl) != -1 && fl.l_pid > 0) {
        log_message(config, "INFO", "Another instance is running (PID: %d)",
                    fl.l_pid);
      } else {
        // Прочитаем PID из файла
        char pid_buf[32] = {0};
        lseek(lockfile, 0, SEEK_SET);

        // read(lockfile, pid_buf, sizeof(pid_buf) - 1);
        ssize_t r = read(lockfile, pid_buf, sizeof(pid_buf) - 1);
        if (r > 0) {
          pid_buf[r] = '\0'; // Гарантируем нуль-терминацию
        }

        log_message(config, "INFO", "Another instance is running (PID: %s)",
                    pid_buf);
      }

      close(lockfile);
      return 1;
    } else {
      log_message(config, "ERROR", "fcntl lock failed: %s", strerror(errno));
      close(lockfile);
      return 1;
    }
  }
#endif

    // Успешно заблокировали - мы первые
    log_message(config, "DEBUG", "Successfully acquired lock on %s",
                lockfile_path);

    // Обновляем содержимое файла с нашим PID
    char pid_str[32];
    int pid_len = snprintf(pid_str, sizeof(pid_str), "%ld", (long)getpid());

    // Очищаем файл и пишем новый PID
    // ftruncate(lockfile, 0);
    if (ftruncate(lockfile, 0) == -1) {
      // игнорируем, не критично
    }

    lseek(lockfile, 0, SEEK_SET);
    // write(lockfile, pid_str, pid_len);

    ssize_t w = write(lockfile, pid_str, pid_len);
    if (w != pid_len) {
      // игнорируем
    }

    // Оставляем файл открытым - блокировка будет снята при закрытии
    // Не закрываем lockfile!

    return 0; // Не запущено
  }

  int create_lock_file(const char *lockfile_path, Config *config) {
    if (!lockfile_path) {
      log_message(config, "ERROR", "NULL lockfile path");
      return 0;
    }

    // Проверяем безопасность пути
    if (!is_path_safe(lockfile_path, config)) {
      log_message(config, "ERROR", "Unsafe lockfile path: %s", lockfile_path);
      return 0;
    }

    // Создаем или открываем lock-файл
    int lockfile = open(lockfile_path, O_CREAT | O_RDWR, 0644);
    if (lockfile == -1) {
      log_message(config, "ERROR", "Cannot create lockfile %s: %s",
                  lockfile_path, strerror(errno));
      return 0;
    }

    // Пытаемся установить эксклюзивную блокировку
#ifdef _WIN32
    HANDLE hFile = (HANDLE)_get_osfhandle(lockfile);
    if (hFile == INVALID_HANDLE_VALUE) {
      log_message(config, "ERROR", "Invalid file handle for lockfile");
      close(lockfile);
      return 0;
    }

    OVERLAPPED ov = {0};
    if (!LockFileEx(hFile, LOCKFILE_EXCLUSIVE_LOCK, 0, 1, 0, &ov)) {
      log_message(config, "WARNING",
                  "Lockfile is already locked by another process");
      close(lockfile);
      return 0;
    }
#else
  struct flock fl;
  fl.l_type = F_WRLCK;
  fl.l_whence = SEEK_SET;
  fl.l_start = 0;
  fl.l_len = 0;
  fl.l_pid = 0;

  if (fcntl(lockfile, F_SETLK, &fl) == -1) {
    if (errno == EACCES || errno == EAGAIN) {
      log_message(config, "WARNING",
                  "Lockfile is already locked by another process");
    } else {
      log_message(config, "ERROR", "Failed to lock file: %s", strerror(errno));
    }
    close(lockfile);
    return 0;
  }
#endif

    // Успешно заблокировали - записываем PID
    char pid_str[32];
    int pid_len = snprintf(pid_str, sizeof(pid_str), "%ld\n", (long)getpid());

    // Очищаем файл и пишем новый PID
    if (ftruncate(lockfile, 0) == -1) {
      log_message(config, "WARNING", "Failed to truncate lockfile: %s",
                  strerror(errno));
    }

    lseek(lockfile, 0, SEEK_SET);
    ssize_t written = write(lockfile, pid_str, pid_len);
    if (written != pid_len) {
      log_message(config, "WARNING", "Failed to write PID to lockfile");
    }

    // Сбрасываем на диск
    fsync(lockfile);

    log_message(config, "DEBUG", "Lock file created with PID %ld",
                (long)getpid());

    // ВАЖНО: НЕ закрываем файл! Он должен оставаться открытым,
    // чтобы блокировка сохранялась на всё время работы программы.
    // close(lockfile); // НЕ ДЕЛАЕМ!

    return 1; // Успешно создали блокировку
  }

  void remove_lock_file(const char *lockfile_path, Config *config) {
    if (!lockfile_path) {
      log_message(config, "ERROR", "NULL lockfile path");
      return;
    }

    // Проверяем безопасность пути
    if (!is_path_safe(lockfile_path, config)) {
      log_message(config, "ERROR", "Unsafe lockfile path: %s", lockfile_path);
      return;
    }

    // Проверяем, что файл существует и принадлежит нам
    struct stat st;
    if (stat(lockfile_path, &st) == -1) {
      if (errno != ENOENT) {
        log_message(config, "WARNING", "Cannot stat lockfile: %s",
                    strerror(errno));
      }
      return;
    }

    // Пытаемся удалить файл
    if (unlink(lockfile_path) == -1) {
      log_message(config, "WARNING", "Failed to remove lockfile %s: %s",
                  lockfile_path, strerror(errno));
    } else {
      log_message(config, "DEBUG", "Lock file removed: %s", lockfile_path);
    }
  }

  //------------------------------------------------------------------------------
  // Функции для работы с путями (базовые)
  //------------------------------------------------------------------------------

  char *path_join(const char *dir, const char *file) {
    if (!dir || !file)
      return NULL;

    size_t dir_len = strlen(dir);
    size_t file_len = strlen(file);

    if (dir_len == 0)
      return safe_strdup(file);
    if (file_len == 0)
      return safe_strdup(dir);

    // Проверка на переполнение
    if (dir_len + file_len + 2 >= PATH_MAX) {
      errno = ENAMETOOLONG;
      return NULL;
    }

    int need_slash = 0;
    if (dir[dir_len - 1] != '/'
#ifdef _WIN32
        && dir[dir_len - 1] != '\\'
#endif
    ) {
      need_slash = 1;
    }

    char *result = safe_malloc(dir_len + file_len + 2);
    if (!result)
      return NULL;

    size_t result_size = dir_len + file_len + 2;
    if (need_slash) {
// Прагма подавляет ложное предупреждение fortify-source
#pragma GCC diagnostic push
#pragma GCC diagnostic ignored "-Wformat-truncation"
      snprintf(result, result_size, "%s/%s", dir, file);
#pragma GCC diagnostic pop
    } else {
#pragma GCC diagnostic push
#pragma GCC diagnostic ignored "-Wformat-truncation"
      snprintf(result, result_size, "%s%s", dir, file);
#pragma GCC diagnostic pop
    }

    return result;
  }

  char *path_dirname(const char *path) {
    if (!path)
      return NULL;

    char *path_copy = safe_strdup(path);
    if (!path_copy)
      return NULL;

    char *last_sep = strrchr(path_copy, '/');
#ifdef _WIN32
    if (!last_sep)
      last_sep = strrchr(path_copy, '\\');
#endif

    if (last_sep) {
      *last_sep = '\0';
      return path_copy;
    } else {
      free(path_copy);
      return safe_strdup(".");
    }
  }

  char *path_basename(const char *path) {
    if (!path)
      return NULL;

    const char *last_sep = strrchr(path, '/');
#ifdef _WIN32
    if (!last_sep)
      last_sep = strrchr(path, '\\');
#endif

    if (last_sep) {
      return safe_strdup(last_sep + 1);
    } else {
      return safe_strdup(path);
    }
  }

  int path_is_absolute(const char *path) {
    if (!path)
      return 0;

#ifdef _WIN32
    return (path[0] && path[1] == ':') || (path[0] == '\\' && path[1] == '\\');
#else
  return path[0] == '/';
#endif
  }

  //------------------------------------------------------------------------------
  // Функции для получения информации о системе
  //------------------------------------------------------------------------------

  char *get_platform_name(void) {
#ifdef _WIN32
    return safe_strdup("Windows");
#elif defined(__APPLE__)
  return safe_strdup("macOS");
#elif defined(__linux__)
  return safe_strdup("Linux");
#elif defined(__FreeBSD__)
  return safe_strdup("FreeBSD");
#else
  return safe_strdup("Unknown");
#endif
  }

  char *get_architecture_name(void) {
    // Сначала проверяем стандартные макросы
#ifdef __x86_64__
    return safe_strdup("x86_64");
#elif defined(__i386__)
  return safe_strdup("x86");
#elif defined(__aarch64__)
  return safe_strdup("ARM64");
#elif defined(__arm__)
  return safe_strdup("ARM");
#elif defined(__riscv)
// RISC-V архитектура
#ifdef __riscv_xlen
#if __riscv_xlen == 64
  return safe_strdup("RISC-V 64-bit");
#elif __riscv_xlen == 32
  return safe_strdup("RISC-V 32-bit");
#endif
#endif
  // Если не удалось определить битность, используем uname
  struct utsname buf;
  if (uname(&buf) == 0) {
    char *result = safe_strdup(buf.machine);
    if (result && strcmp(result, "riscv64") == 0) {
      free(result);
      return safe_strdup("RISC-V 64-bit");
    } else if (result && strcmp(result, "riscv32") == 0) {
      free(result);
      return safe_strdup("RISC-V 32-bit");
    }
    return result;
  }
  return safe_strdup("RISC-V");
#elif defined(__powerpc64__)
  return safe_strdup("PowerPC 64-bit");
#elif defined(__powerpc__)
  return safe_strdup("PowerPC");
#elif defined(__mips64)
  return safe_strdup("MIPS 64-bit");
#elif defined(__mips)
  return safe_strdup("MIPS");
#elif defined(__s390x__)
  return safe_strdup("IBM S/390x");
#elif defined(__s390__)
  return safe_strdup("IBM S/390");
#else
  // Fallback: используем uname
  struct utsname buf;
  if (uname(&buf) == 0) {
    return safe_strdup(buf.machine);
  }
  return safe_strdup("Unknown");
#endif
  }

  long long get_free_memory(void) {
#ifdef _WIN32
    MEMORYSTATUSEX status;
    status.dwLength = sizeof(status);
    if (GlobalMemoryStatusEx(&status)) {
      return (long long)status.ullAvailPhys;
    }
#elif defined(__linux__)
  struct sysinfo info;
  if (sysinfo(&info) == 0) {
    return (long long)info.freeram * info.mem_unit;
  }
#endif
    return -1;
  }

  int get_cpu_count(void) {
#ifdef _WIN32
    SYSTEM_INFO sysinfo;
    GetSystemInfo(&sysinfo);
    return sysinfo.dwNumberOfProcessors;
#else
  return sysconf(_SC_NPROCESSORS_ONLN);
#endif
  }

  //------------------------------------------------------------------------------
  // Функции для работы со временем
  //------------------------------------------------------------------------------

  char *get_current_timestamp(void) {
    time_t now = time(NULL);
    struct tm *tm_info = localtime(&now);

    char *timestamp = safe_malloc(20);
    if (!timestamp)
      return NULL;

    strftime(timestamp, 20, "%Y-%m-%d %H:%M:%S", tm_info);
    return timestamp;
  }

  long long get_current_time_ms(void) {
#ifdef _WIN32
    FILETIME ft;
    GetSystemTimeAsFileTime(&ft);
    unsigned long long tt = ft.dwHighDateTime;
    tt <<= 32;
    tt |= ft.dwLowDateTime;
    tt /= 10000; // Convert to milliseconds
    return (long long)tt;
#else
  struct timespec ts;
  clock_gettime(CLOCK_REALTIME, &ts);
  return (long long)ts.tv_sec * 1000 + ts.tv_nsec / 1000000;
#endif
  }

  void sleep_ms(int milliseconds) {
    if (milliseconds <= 0)
      return;

#ifdef _WIN32
    Sleep(milliseconds);
#else
  usleep(milliseconds * 1000);
#endif
  }
