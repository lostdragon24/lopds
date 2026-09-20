# ============================================================================
# УНИВЕРСАЛЬНЫЙ MAKEFILE ДЛЯ ВСЕХ АРХИТЕКТУР
# ============================================================================

CC = gcc
ARCH ?= $(shell uname -m)

# Базовые флаги (безопасные для всех)
CFLAGS = -Wall -Wextra -std=c99 -D_POSIX_C_SOURCE=200809L -D_GNU_SOURCE
LDFLAGS =

# Автоматическое определение флагов MySQL/MariaDB через mysql_config
# Если утилиты нет в системе, сработает запасной вариант (fallback)
MYSQL_CFLAGS := $(shell mysql_config --cflags 2>/dev/null || echo "-I/usr/include/mysql -I/usr/include/mysql/mysql")
MYSQL_LIBS   := $(shell mysql_config --libs 2>/dev/null || echo "-lmysqlclient")

# Добавляем полученные флаги компиляции к базовым CFLAGS
CFLAGS += $(MYSQL_CFLAGS)

# ============================================================================
# ОПТИМИЗАЦИЯ ПО АРХИТЕКТУРЕ (БЕЗОПАСНЫЕ ФЛАГИ)
# ============================================================================
ifeq ($(ARCH),riscv64)
    # RISC-V 64-bit (OrangePI RV2 и др.)
    ARCH_CFLAGS = -march=rv64gc -mabi=lp64d
    ARCH_NAME = riscv64
else ifeq ($(ARCH),riscv32)
    # RISC-V 32-bit
    ARCH_CFLAGS = -march=rv32gc -mabi=ilp32d
    ARCH_NAME = riscv32
else ifeq ($(ARCH),aarch64)
    # ARM64 (Raspberry Pi 3/4, OrangePi и др.)
    ARCH_CFLAGS = -march=armv8-a
    ARCH_NAME = arm64
else ifeq ($(ARCH),armv7l)
    # ARM 32-bit (Raspberry Pi 2, Zero и др.)
    ARCH_CFLAGS = -march=armv7-a -mfpu=neon -mfloat-abi=hard
    ARCH_NAME = arm32
else ifeq ($(ARCH),x86_64)
    # x86_64 (AMD/Intel) - БЕЗОПАСНЫЕ ФЛАГИ
    ARCH_CFLAGS = -march=x86-64 -mtune=generic
    ARCH_NAME = x86_64
else ifeq ($(ARCH),i686)
    # x86 32-bit
    ARCH_CFLAGS = -march=i686 -mtune=generic
    ARCH_NAME = x86_32
else
    # Универсальные флаги для неизвестных архитектур
    ARCH_CFLAGS =
    ARCH_NAME = generic
endif

CFLAGS += $(ARCH_CFLAGS)

# ============================================================================
# ИСХОДНЫЕ ФАЙЛЫ
# ============================================================================
SRCS = main.c config.c database.c scanner.c metadata.c utils.c \
       scanner_integration.c inpx_parser.c database_mysql.c \
       path_validation.c
OBJS = $(SRCS:.c=.o)
TARGET = book_scanner
# Автоопределение ОС для корректной линковки iconv
OS := $(shell uname -s)

LIBS = -lsqlite3 -larchive -lssl -lcrypto

LIBS = -lsqlite3 -larchive -lssl -lcrypto

# === НАСТРОЙКА ICONV (СОВМЕСТИМОСТЬ) ===
ifeq ($(OS),Linux)
    # Проверяем, существует ли заголовочный файл от собранного из исходников iconv
    ifneq ($(wildcard /usr/local/include/iconv.h),)
        # У вас установлена внешняя libiconv из исходников:
        # Добавляем пути к ней и подменяем стандартные вызовы на lib-версии
        CFLAGS += -I/usr/local/include -Diconv_t=libiconv_t -Diconv_open=libiconv_open -Diconv=libiconv -Diconv_close=libiconv_close
        LIBS += -L/usr/local/lib -liconv
        LDFLAGS += -Wl,-rpath,/usr/local/lib
    endif
else
    # Настройки для macOS / других Unix систем
    LIBS += -liconv
endif

# ============================================================================
# ПРАВИЛА СБОРКИ
# ============================================================================
all: release

debug: CFLAGS += -DDEBUG -g -O0
debug: $(TARGET)

release: CFLAGS += -O2
release: $(TARGET)

# Оптимизированная сборка (агрессивная)
optimized: CFLAGS += -O3 -funroll-loops
optimized: $(TARGET)

# Сборка с LTO (требует GCC 5+)
lto: CFLAGS += -O3 -flto=auto -ffat-lto-objects
lto: LDFLAGS += -flto=auto
lto: $(TARGET)

# Нативная оптимизация (только для текущей машины)
native: CFLAGS += -O3 -march=native -mtune=native
native: $(TARGET)

$(TARGET): $(OBJS)
	$(CC) $(CFLAGS) $(EXTRA_CFLAGS) $(LDFLAGS) $(OBJS) -o $(TARGET) $(MYSQL_LIBS) $(LIBS)

%.o: %.c
	$(CC) $(CFLAGS) $(EXTRA_CFLAGS) $(MYSQL_INCLUDE) -c $< -o $@

# ============================================================================
# ОЧИСТКА
# ============================================================================
clean:
	rm -f $(OBJS) $(TARGET)

distclean: clean
	rm -f *~ *.bak *.orig

# ============================================================================
# УСТАНОВКА
# ============================================================================
install: release
	cp $(TARGET) /usr/local/bin/

# ============================================================================
# ЗАВИСИМОСТИ
# ============================================================================
main.o: main.c common.h config.h database.h scanner.h utils.h scanner_integration.h path_validation.h
config.o: config.c common.h config.h
database.o: database.c common.h database.h database_mysql.h
scanner.o: scanner.c common.h scanner.h metadata.h utils.h path_validation.h
metadata.o: metadata.c common.h metadata.h utils.h path_validation.h
utils.o: utils.c common.h utils.h path_validation.h
scanner_integration.o: scanner_integration.c common.h scanner_integration.h inpx_parser.h utils.h path_validation.h
inpx_parser.o: inpx_parser.c common.h inpx_parser.h utils.h database.h metadata.h path_validation.h
database_mysql.o: database_mysql.c common.h database_mysql.h config.h database.h path_validation.h
path_validation.o: path_validation.c common.h path_validation.h

# ============================================================================
# ТЕСТЫ И УТИЛИТЫ
# ============================================================================
test: debug
	./$(TARGET) --test

test-mysql: debug
	./$(TARGET) config_mysql.ini

test-sqlite: debug
	./$(TARGET) config_sqlite.ini

info:
	@echo "============================================"
	@echo "Информация о сборке"
	@echo "============================================"
	@echo "Архитектура: $(ARCH_NAME) ($(ARCH))"
	@echo "Компилятор:  $(CC) $(shell $(CC) --version | head -1)"
	@echo "CFLAGS:      $(CFLAGS)"
	@echo "LDFLAGS:     $(LDFLAGS)"
	@echo "LIBS:        $(LIBS)"
	@echo "============================================"
	@echo ""
	@echo "Проверка поддержки флагов:"
	@echo "  make check-flags  - проверить поддержку -march"

check-flags:
	@echo "Проверка поддержки флагов $(ARCH_CFLAGS)..."
	@$(CC) $(ARCH_CFLAGS) -E - </dev/null 2>&1 && echo "✅ Поддерживается" || echo "❌ Не поддерживается"


help:
	@echo "Доступные цели:"
	@echo "  all        - сборка release (по умолчанию)"
	@echo "  debug      - сборка с отладкой (-g -O0)"
	@echo "  release    - оптимизированная сборка (-O2)"
	@echo "  optimized  - агрессивная оптимизация (-O3)"
	@echo "  lto        - сборка с LTO оптимизацией"
	@echo "  native     - оптимизация под текущий CPU"
	@echo "  clean      - очистка объектных файлов"
	@echo "  install    - установка в /usr/local/bin/"
	@echo "  info       - показать информацию о сборке"
	@echo "  check-flags- проверить поддержку флагов"
	@echo ""
	@echo "Переменные:"
	@echo "  ARCH=<arch> - указать архитектуру вручную"
	@echo ""
	@echo "Примеры:"
	@echo "  make                    - автоопределение архитектуры"
	@echo "  make ARCH=x86_64        - принудительно x86_64"
	@echo "  make ARCH=riscv64       - принудительно RISC-V 64"
	@echo "  make ARCH=aarch64       - принудительно ARM64"
	@echo "  make native             - оптимизация под текущий CPU"

.PHONY: all debug release optimized lto native clean distclean install \
        test test-mysql test-sqlite info check-flags help
