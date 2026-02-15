# Компилятор и флаги
CC = gcc
CFLAGS = -Wall -Wextra -std=c99 -D_POSIX_C_SOURCE=200809L -D_GNU_SOURCE
LDFLAGS =
MYSQL_LIBS = -lmysqlclient
MYSQL_INCLUDE = -I/usr/include/mysql -I/usr/include/mysql/mysql

# Исходные файлы
SRCS = main.c config.c database.c scanner.c metadata.c utils.c scanner_integration.c inpx_parser.c database_mysql.c
OBJS = $(SRCS:.c=.o)

# Имя исполняемого файла
TARGET = book_scanner

# Стандартные библиотеки
LIBS = -lsqlite3 -larchive -lssl -lcrypto -liconv

# Правила по умолчанию
all: release

# Debug версия - с отладочной информацией и макросом DEBUG
debug: CFLAGS += -DDEBUG -g -O0
debug: $(TARGET)

# Release версия - оптимизированная
release: CFLAGS += -O2
release: $(TARGET)

# Сборка исполняемого файла
$(TARGET): $(OBJS)
	$(CC) $(OBJS) -o $(TARGET) $(LDFLAGS) $(MYSQL_LIBS) $(LIBS)

# Компиляция объектных файлов
%.o: %.c
	$(CC) $(CFLAGS) $(MYSQL_INCLUDE) -c $< -o $@

# Очистка
clean:
	rm -f $(OBJS) $(TARGET)

# Полная очистка (включая бэкапы)
distclean: clean
	rm -f *~ *.bak *.orig

# Установка (если нужно)
install: release
	cp $(TARGET) /usr/local/bin/

# Создание дистрибутива
dist: distclean
	mkdir -p book_scanner-1.0
	cp *.c *.h Makefile README.md config.ini.example book_scanner-1.0/
	tar -czf book_scanner-1.0.tar.gz book_scanner-1.0/
	rm -rf book_scanner-1.0/

# Зависимости
main.o: main.c common.h config.h database.h scanner.h utils.h scanner_integration.h
config.o: config.c common.h config.h
database.o: database.c common.h database.h database_mysql.h
scanner.o: scanner.c common.h scanner.h metadata.h utils.h
metadata.o: metadata.c common.h metadata.h utils.h
utils.o: utils.c common.h utils.h
scanner_integration.o: scanner_integration.c common.h scanner_integration.h inpx_parser.h utils.h
inpx_parser.o: inpx_parser.c common.h inpx_parser.h utils.h database.h metadata.h
database_mysql.o: database_mysql.c common.h database_mysql.h config.h database.h

# Тестовые цели
test: debug
	./$(TARGET) --test

test-mysql: debug
	./$(TARGET) config_mysql.ini

test-sqlite: debug
	./$(TARGET) config_sqlite.ini

# Профилирование
profile: CFLAGS += -pg
profile: LDFLAGS += -pg
profile: debug

# Статический анализ
analyze: CFLAGS += -fanalyzer
analyze: debug

# Показать помощь
help:
	@echo "Доступные цели:"
	@echo "  all       - сборка release версии (по умолчанию)"
	@echo "  debug     - сборка с отладочной информацией"
	@echo "  release   - сборка оптимизированной версии"
	@echo "  clean     - удаление объектных файлов и исполняемого файла"
	@echo "  distclean - полная очистка"
	@echo "  install   - установка в /usr/local/bin/"
	@echo "  test      - запуск тестов"
	@echo "  test-mysql - тест с MySQL конфигурацией"
	@echo "  test-sqlite - тест с SQLite конфигурацией"
	@echo "  profile   - сборка с поддержкой профилирования"
	@echo "  analyze   - статический анализ кода"
	@echo "  dist      - создание дистрибутива"

# Файлы которые не являются реальными файлами
.PHONY: all debug release clean distclean install dist test test-mysql test-sqlite profile analyze help
