#!/bin/bash

# Скрипт для просмотра статистики базы данных книг
# Автор: Book Scanner
# Версия: 1.0

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Функция для вывода заголовка
print_header() {
    echo -e "${CYAN}"
    echo "=================================================="
    echo "           СТАТИСТИКА БАЗЫ ДАННЫХ КНИГ"
    echo "=================================================="
    echo -e "${NC}"
}

# Функция для вывода ошибки
print_error() {
    echo -e "${RED}ОШИБКА: $1${NC}" >&2
}

# Функция для проверки наличия SQLite
check_sqlite() {
    if ! command -v sqlite3 &> /dev/null; then
        print_error "sqlite3 не установлен. Установите его: sudo zypper install sqlite3"
        exit 1
    fi
}

# Функция для проверки файла базы данных
check_db_file() {
    if [ ! -f "$DB_FILE" ]; then
        print_error "Файл базы данных не найден: $DB_FILE"
        echo "Доступные файлы в текущей директории:"
        ls -la *.db *.sqlite 2>/dev/null || echo "   (нет файлов .db или .sqlite)"
        exit 1
    fi
}

# Функция для получения общей статистики
get_general_stats() {
    echo -e "${GREEN}ОБЩАЯ СТАТИСТИКА:${NC}"
    echo "=================="

    # Общее количество книг
    TOTAL_BOOKS=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM books;")
    echo -e "📚 Всего книг: ${YELLOW}$TOTAL_BOOKS${NC}"

    # Книги вне архивов
    REGULAR_BOOKS=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM books WHERE archive_path IS NULL;")
    echo -e "📖 Обычные файлы: ${YELLOW}$REGULAR_BOOKS${NC}"

    # Книги в архивах
    ARCHIVE_BOOKS=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM books WHERE archive_path IS NOT NULL;")
    echo -e "🗜️  Книги в архивах: ${YELLOW}$ARCHIVE_BOOKS${NC}"

    # Количество архивов
    TOTAL_ARCHIVES=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM archives;")
    echo -e "📦 Всего архивов: ${YELLOW}$TOTAL_ARCHIVES${NC}"

    echo
}

# Функция для получения статистики по форматам
get_format_stats() {
    echo -e "${GREEN}СТАТИСТИКА ПО ФОРМАТАМ:${NC}"
    echo "======================"

    sqlite3 -header -column "$DB_FILE" "
    SELECT
        file_type as 'Формат',
        COUNT(*) as 'Количество',
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM books), 2) as 'Процент'
    FROM books
    GROUP BY file_type
    ORDER BY COUNT(*) DESC;
    " 2>/dev/null || echo "    (нет данных)"

    echo
}

# Функция для получения статистики по авторам
get_author_stats() {
    echo -e "${GREEN}ТОП-10 АВТОРОВ:${NC}"
    echo "================"

    sqlite3 -header -column "$DB_FILE" "
    SELECT
        author as 'Автор',
        COUNT(*) as 'Количество книг'
    FROM books
    WHERE author IS NOT NULL AND author != ''
    GROUP BY author
    ORDER BY COUNT(*) DESC
    LIMIT 10;
    " 2>/dev/null || echo "    (нет данных)"

    echo
}

# Функция для получения статистики по жанрам
get_genre_stats() {
    echo -e "${GREEN}ТОП-10 ЖАНРОВ:${NC}"
    echo "==============="

    sqlite3 -header -column "$DB_FILE" "
    SELECT
        genre as 'Жанр',
        COUNT(*) as 'Количество книг'
    FROM books
    WHERE genre IS NOT NULL AND genre != ''
    GROUP BY genre
    ORDER BY COUNT(*) DESC
    LIMIT 10;
    " 2>/dev/null || echo "    (нет данных)"

    echo
}

# Функция для получения статистики по языкам
get_language_stats() {
    echo -e "${GREEN}РАСПРЕДЕЛЕНИЕ ПО ЯЗЫКАМ:${NC}"
    echo "========================="

    sqlite3 -header -column "$DB_FILE" "
    SELECT
        language as 'Язык',
        COUNT(*) as 'Количество',
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM books WHERE language IS NOT NULL), 2) as 'Процент'
    FROM books
    WHERE language IS NOT NULL AND language != ''
    GROUP BY language
    ORDER BY COUNT(*) DESC;
    " 2>/dev/null || echo "    (нет данных)"

    echo
}

# Функция для получения статистики по годам
get_year_stats() {
    echo -e "${GREEN}РАСПРЕДЕЛЕНИЕ ПО ГОДАМ:${NC}"
    echo "========================"

    sqlite3 -header -column "$DB_FILE" "
    SELECT
        year as 'Год',
        COUNT(*) as 'Количество книг'
    FROM books
    WHERE year IS NOT NULL AND year > 0
    GROUP BY year
    ORDER BY year DESC
    LIMIT 15;
    " 2>/dev/null || echo "    (нет данных)"

    echo
}

# Функция для вывода последних добавленных книг
get_recent_books() {
    echo -e "${GREEN}ПОСЛЕДНИЕ 5 ДОБАВЛЕННЫХ КНИГ:${NC}"
    echo "==============================="

    sqlite3 -header -column "$DB_FILE" "
    SELECT
        id as 'ID',
        title as 'Название',
        author as 'Автор',
        file_type as 'Формат',
        date(added_date) as 'Дата добавления'
    FROM books
    ORDER BY added_date DESC
    LIMIT 5;
    " 2>/dev/null || echo "    (нет данных)"

    echo
}

# Функция для вывода полной информации о 5 книгах
get_detailed_books() {
    echo -e "${PURPLE}ПОЛНАЯ ИНФОРМАЦИЯ О 5 КНИГАХ:${NC}"
    echo "==============================="
    echo

    # Получаем ID 5 случайных книг
    BOOK_IDS=$(sqlite3 "$DB_FILE" "SELECT id FROM books ORDER BY RANDOM() LIMIT 5;")

    if [ -z "$BOOK_IDS" ]; then
        echo "    (в базе данных нет книг)"
        return
    fi

    for BOOK_ID in $BOOK_IDS; do
        echo -e "${BLUE}=== КНИГА ID: $BOOK_ID ===${NC}"

        # Получаем полную информацию о книге
        sqlite3 "$DB_FILE" "
        SELECT
            title,
            author,
            genre,
            series,
            series_number,
            year,
            language,
            publisher,
            file_type,
            file_path,
            archive_path,
            archive_internal_path,
            file_size,
            description,
            added_date,
            last_modified
        FROM books
        WHERE id = $BOOK_ID;
        " | while IFS='|' read -r title author genre series series_number year language publisher file_type file_path archive_path archive_internal_path file_size description added_date last_modified; do

            # Вывод информации с форматированием
            if [ -n "$title" ] && [ "$title" != "NULL" ]; then
                echo -e "  ${YELLOW}📖 Название:${NC} $title"
            fi

            if [ -n "$author" ] && [ "$author" != "NULL" ] && [ "$author" != "" ]; then
                echo -e "  ${YELLOW}✍️  Автор:${NC} $author"
            fi

            if [ -n "$genre" ] && [ "$genre" != "NULL" ]; then
                echo -e "  ${YELLOW}🏷️  Жанр:${NC} $genre"
            fi

            if [ -n "$series" ] && [ "$series" != "NULL" ]; then
                if [ -n "$series_number" ] && [ "$series_number" != "NULL" ] && [ "$series_number" != "0" ]; then
                    echo -e "  ${YELLOW}📚 Серия:${NC} $series (№ $series_number)"
                else
                    echo -e "  ${YELLOW}📚 Серия:${NC} $series"
                fi
            fi

            if [ -n "$year" ] && [ "$year" != "NULL" ] && [ "$year" != "0" ]; then
                echo -e "  ${YELLOW}📅 Год издания:${NC} $year"
            fi

            if [ -n "$language" ] && [ "$language" != "NULL" ]; then
                echo -e "  ${YELLOW}🌐 Язык:${NC} $language"
            fi

            if [ -n "$publisher" ] && [ "$publisher" != "NULL" ]; then
                echo -e "  ${YELLOW}🏢 Издательство:${NC} $publisher"
            fi

            if [ -n "$file_type" ] && [ "$file_type" != "NULL" ]; then
                echo -e "  ${YELLOW}📄 Формат файла:${NC} $file_type"
            fi

            if [ -n "$file_path" ] && [ "$file_path" != "NULL" ]; then
                echo -e "  ${YELLOW}📁 Путь к файлу:${NC} $file_path"
            fi

            if [ -n "$archive_path" ] && [ "$archive_path" != "NULL" ]; then
                echo -e "  ${YELLOW}🗜️  Архив:${NC} $archive_path"
            fi


            if [ -n "$archive_internal_path" ] && [ "$archive_internal_path" != "NULL" ]; then
                echo -e "  ${YELLOW}📋 Файл в архиве:${NC} $archive_internal_path"
            fi

            if [ -n "$file_size" ] && [ "$file_size" != "NULL" ] && [ "$file_size" != "0" ]; then
                # Конвертируем размер в читаемый формат
                if command -v numfmt >/dev/null 2>&1; then
                    size_human=$(numfmt --to=iec-i --suffix=B "$file_size")
                    echo -e "  ${YELLOW}📊 Размер:${NC} $size_human"
                else
                    echo -e "  ${YELLOW}📊 Размер:${NC} $file_size байт"
                fi
            fi

            if [ -n "$description" ] && [ "$description" != "NULL" ] && [ "$description" != "" ]; then
                # Обрезаем длинное описание и убираем лишние пробелы
                clean_desc=$(echo "$description" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | tr -s ' ')
                if [ ${#clean_desc} -gt 200 ]; then
                    clean_desc="${clean_desc:0:200}..."
                fi
                echo -e "  ${YELLOW}📝 Описание:${NC} $clean_desc"
            fi

            if [ -n "$added_date" ] && [ "$added_date" != "NULL" ]; then
                echo -e "  ${YELLOW}🕒 Дата добавления:${NC} $added_date"
            fi

            if [ -n "$last_modified" ] && [ "$last_modified" != "NULL" ] && [ "$last_modified" != "$added_date" ]; then
                echo -e "  ${YELLOW}✏️  Последнее изменение:${NC} $last_modified"
            fi

        done

        echo
        echo -e "${CYAN}--------------------------------------------------${NC}"
        echo
    done
}





# Функция для вывода статистики архивов
get_archive_stats() {
    echo -e "${GREEN}СТАТИСТИКА АРХИВОВ:${NC}"
    echo "=================="

    sqlite3 -header -column "$DB_FILE" "
    SELECT
        archive_path as 'Путь к архиву',
        file_count as 'Кол-во файлов',
        total_size as 'Общий размер',
        datetime(last_scanned, 'localtime') as 'Последнее сканирование'
    FROM archives
    ORDER BY file_count DESC
    LIMIT 10;
    " 2>/dev/null || echo "    (нет данных)"

    echo
}

# Функция для вывода справки
show_help() {
    echo "Использование: $0 [ПАРАМЕТРЫ] [ФАЙЛ_БАЗЫ_ДАННЫХ]"
    echo
    echo "Параметры:"
    echo "  -h, --help          Показать эту справку"
    echo "  -s, --short         Краткая статистика (только общие цифры)"
    echo "  -f, --full          Полная статистика (по умолчанию)"
    echo "  -b, --books         Только подробная информация о книгах"
    echo "  -a, --archives      Только статистика архивов"
    echo "  --db <файл>         Указать файл базы данных"
    echo
    echo "Примеры:"
    echo "  $0                              # Полная статистика (автопоиск БД)"
    echo "  $0 --db /path/to/books.db       # Указать конкретный файл БД"
    echo "  $0 -s                           # Краткая статистика"
    echo "  $0 -b                           # Только информация о книгах"
    echo
}

# Основная функция
main() {
    local MODE="full"
    local DB_FILE=""

    # Парсинг аргументов командной строки
    while [[ $# -gt 0 ]]; do
        case $1 in
            -h|--help)
                show_help
                exit 0
                ;;
            -s|--short)
                MODE="short"
                shift
                ;;
            -f|--full)
                MODE="full"
                shift
                ;;
            -b|--books)
                MODE="books"
                shift
                ;;
            -a|--archives)
                MODE="archives"
                shift
                ;;
            --db)
                DB_FILE="$2"
                shift 2
                ;;
            *)
                # Если аргумент не распознан, считаем его файлом БД
                if [ -z "$DB_FILE" ] && [ -f "$1" ]; then
                    DB_FILE="$1"
                fi
                shift
                ;;
        esac
    done

    # Если файл БД не указан, ищем автоматически
    if [ -z "$DB_FILE" ]; then
        find_db_file
    fi

    # Проверки
    check_sqlite
    check_db_file

    # Вывод статистики в зависимости от режима
    print_header

    case $MODE in
        "short")
            get_general_stats
            get_recent_books
            ;;
        "full")
            get_general_stats
            get_format_stats
            get_author_stats
            get_genre_stats
            get_language_stats
            get_year_stats
            get_archive_stats
            get_recent_books
            get_detailed_books
            ;;
        "books")
            get_detailed_books
            ;;
        "archives")
            get_archive_stats
            ;;
    esac

    echo -e "${GREEN}Статистика завершена!${NC}"
}

# Функция для автоматического поиска файла базы данных
find_db_file() {
    local possible_files=(
        "books.db"
        "library.db"
        "book_scanner.db"
        "*.db"
        "*.sqlite"
    )

    for file in "${possible_files[@]}"; do
        if [ -f "$file" ]; then
            DB_FILE="$file"
            echo -e "${YELLOW}Найден файл базы данных: $DB_FILE${NC}"
            echo
            return
        fi
    done

    # Если не нашли, используем первый .db файл
    local first_db=$(find . -maxdepth 1 -name "*.db" -o -name "*.sqlite" | head -n1)
    if [ -n "$first_db" ]; then
        DB_FILE="$first_db"
        echo -e "${YELLOW}Используем файл базы данных: $DB_FILE${NC}"
        echo
        return
    fi

    print_error "Не удалось найти файл базы данных автоматически."
    echo "Укажите файл явно: $0 --db /path/to/database.db"
    exit 1
}

# Запуск основной функции
main "$@"
