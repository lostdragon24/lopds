# Little OPDS 📚

Утилита для автоматического сканирования и организации коллекции электронных
книг с веб-интерфейсом и десктоп-клиентом (сканер встроен в клиента).

1.  Сканер электронной библиотеки - написан на языке C.
2.  Веб Интерфейс написан на PHP с использованием JavaScript (Чтение PDF и EPUB
    с использованием сторонних библиотек написанных на JAVA). Доступны 5 языков
    интерфейса (Русский, Украинский, Белорусский, Казахский и Английский). Язык
    выбирается автоматически, считывая локаль браузера. Администрирование
    (управление ) через панель администратора.
3.  GUI Клиент, скорее это самостоятельное приложение дублирующее код сканера и
    веб клиента, написан на C++ с использованием QT6

## ✨ Возможности

### Основные функции

* 📁 Автоматическое сканирование директорий с книгами
* 📦 Работа с архивами: ZIP, RAR, 7Z (извлечение книг без полной распаковки)
* 📊 Парсинг метаданных: заголовки, авторы, серии, жанры и многое другое
* 🗄️ Поддержка СУБД: SQLite и MySQL
* 🔍 Умное сканирование: пропуск не измененных файлов через отслеживание хешей
* 📝 Логирование всех операций
* 🔄 Импорт из INPX: поддержка библиотечных коллекций в формате .inpx

## 📚 Поддерживаемые форматы

### Книжные форматы


|Формат|Описание                |Статус                                                                                                                                                                                             |
|------|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
|FB2   |FictionBook             |✅ Полный парсинг метаданных                                                                                                                                                                        |
|EPUB  |Electronic Publication  |✅ Полный парсинг метаданных                                                                                                                                                                        |
|PDF   |Portable Document Format|Частичный парсинг, считывание имени файла как наименования книги. Вебинтерфейс может попытаться получить данные из файла с помощью механизмов операционной системы (pdftotext, Ghostscript, Imagic)|
|MOBI  |Mobipocket              |🚧 В планах                                                                                                                                                                                        |
|TXT   |Plain Text              |Частичный парсинг, считывание имени файла как наименования книги                                                                                                                                   |

### Архивные форматы

* ZIP
* RAR
* 7Z

## 🛠 Технические особенности

### Архитектура

* 🧩 Модульная архитектура с разделением:
  * Парсеры форматов
  * Работа с базами данных
  * Сканер файловой системы

### Кроссплатформенность

* Поддержка архитектур:
  * x86_64 (обычные ПК)
  * RISC-V (OrangePi RV2 и другие)
  * ARM (Raspberry Pi и другие)
* Поддерживаемые ОС:
  * Linux
  * FreeBSD
  * macOS
  * Windows (с небольшими корректировками)

### Технологии

* libarchive - работа с архивами
* SQLite3/MySQL C API - работа с базами данных
* OpenSSL - вычисление хешей (MD5, SHA1, SHA256, SHA512)
* iconv - конвертация кодировок (UTF-8, Windows-1251)

## 📦 Установка и запуск

### Требования

Для Ubuntu/Debian:

sudo apt-get install libsqlite3-dev libarchive-dev libssl-dev
libmysqlclient-dev libiconv-dev

Для RISC-V (OrangePi RV2):

sudo apt-get install libsqlite3-dev libarchive-dev libssl-dev
default-libmysqlclient-dev

### Компиляция

make        *\# сборка release версии*

*\#Или с максимальной оптимизацией под ваш процессор (другие опции см. в
Makefile)*

make lto EXTRA_CFLAGS="-march=native -mtune=native"

make debug  *\# сборка с отладочной информацией*

make clean  *\# очистка*

## Могут быть ошибки при сборке связанные с разным подходом к формату (bool, my_bool) в MySQL и MariaDB.

## ⚙ Конфигурация

Создайте файл config.ini:

\[database]

type = sqlite              ; или mysql

path = ./books.db          ; для SQLite

*; Для MySQL*

*; type = mysql*

*; host = localhost*

*; user = username*

*; password = password*

*; database = booklib*

*; port = 3306*

\[scanner]

books_dir = /path/to/your/books

log_file = ./scanner.log

rescan_unchanged = no

enable_inpx = yes

clear_database_inpx = no

hash_algorithm = md5       ; md5, sha1, sha256, sha512

log_level = info           ; debug, info, warning, error

; Размер пачки для добавления в базу (размер пачки зависит от оперативной
памяти вашего оборудования, оптимально от 1000 до 100000)

batch_size = 100

; Количество потоков (если у вас коллекция размещена не на SSD, то оптимальным
будет 1 (один) поток, иначе вместо прироста скорости получите метание головки
по жесткому диску), иначе количество ядер вашего процессора -1 (для потока
работающего с БД)

num_workers = 4

; Искать ли дубликаты в базе и заменять их большими по размеру книгами. (Если
да, то работает медленнее, но не существенно)

find_dup = no

### Запуск

bash

./book_scanner              *\# автоматический поиск config.ini*

./book_scanner config.ini   *\# с указанием конфигурации*

### Примерное измерение скорости работы сканера (нагрузочный тест, объём данных в архивах 406.28 GB):	 	


|Процессор                                |Оперативная 			память (объём)|Тип носителя|СУБД  |Количество обработанных архивов\\файлов (по факту добавления в БД)|Время (час:мин)|
|-----------------------------------------|---------------------|------------|------|-----------------------------------------------------------------|-----|
|Ky X1 8-core RISC-V                      |8 ГБ                 |SSD         |sqlite|226\\552463                                                      |1:48 |
|Ky X1 8-core RISC-V                      |8 ГБ                 |SSD         |MySQL |226\\552463                                                      |2:32 |
|Intel(R) Celeron(R) CPU 1007U @ 1.50GHz  |4 ГБ                 |HDD         |sqlite|226\\552463                                                      |4:27 |
|Intel(R) Celeron(R) CPU 1007U @ 1.50GHz  |4 ГБ                 |HDD         |MySQL |226\\552463                                                      |4:43 |

## 📊 Структура базы данных

### Таблица books


|Поле                 |Описание                    |
|---------------------|----------------------------|
|id                   |Первичный ключ              |
|file_path            |Путь к файлу/архиву         |
|file_name            |Имя файла                   |
|file_size            |Размер в байтах             |
|file_type            |Расширение файла            |
|archive_path         |Путь к архиву               |
|archive_internal_path|Путь внутри архива          |
|file_hash            |Хеш файла                   |
|title                |Название книги              |
|author               |Автор                       |
|genre                |Жанр                        |
|series               |Серия                       |
|series_number        |Номер в серии               |
|year                 |Год издания                 |
|language             |Язык                        |
|publisher            |Издатель                    |
|description          |Описание                    |
|added_date           |Дата добавления             |
|last_modified        |Дата изменения              |
|last_scanned         |Дата последнего сканирования|

### Таблица archives

* Отслеживание состояния архивных файлов
* Хеши для определения изменений
* Статистика по файлам

### Таблица book_ratings

* Рейтинги книг (1-5)
* Привязка по fingerprint устройства (браузера)
* Защита от повторного голосования

### Таблица book_favorites

* Избранное пользователей
* Привязка по fingerprint устройства (браузера)

### Таблица bookmarks

* Закладки
* Привязка по fingerprint устройства (браузера)

## 🔄 INPX поддержка

Проект поддерживает импорт библиотечных коллекций в формате INPX:

ini

\[scanner]

enable_inpx = yes

clear_database_inpx = no    # очистка БД перед импортом

## 💡 Примеры использования

### Поиск книг по автору

sql

SELECT title, series, series_number

FROM books

WHERE author LIKE '%Толстой%'

ORDER BY series, series_number;

### Статистика по коллекции

sql

SELECT

COUNT(*) as total_books,

COUNT(DISTINCT author) as unique_authors,

COUNT(DISTINCT series) as unique_series,

COUNT(DISTINCT genre) as unique_genres,

SUM(file_size) as total_size_bytes

FROM books;

### Топ популярных книг (по рейтингу)

sql

SELECT b.title, b.author, AVG(r.rating) as avg_rating

FROM books b

JOIN book_ratings r ON b.id = r.book_id

GROUP BY b.id

ORDER BY avg_rating DESC

LIMIT 10;

## 🖥 Интерфейсы

### Веб-интерфейс (PHP c реализацией OPDS каталога)

Доступен на 5 языках (Русский, Белорусский, Казахский, Украинский, Английский)

Современный веб-интерфейс для управления библиотекой:


|Интерфейс                                                                                            |Описание                                    |
|-----------------------------------------------------------------------------------------------------|--------------------------------------------|
|!(https://raw.githubusercontent.com/lostdragon24/lopds/refs/heads/main/doc/index_searche.png)        |Главная страница - поиск и навигация        |
|!(https://raw.githubusercontent.com/lostdragon24/lopds/refs/heads/main/doc/book_info.png)           |Информация о книге - Читать, скачать        |
|!(https://raw.githubusercontent.com/lostdragon24/lopds/refs/heads/main/doc/book_read.png)           |Встроенная читалка - чтение прямо в браузере|
|!(https://raw.githubusercontent.com/lostdragon24/lopds/refs/heads/main/doc/book_bokmark_and_citats.png)|Закладки и цитаты                           |
|!(https://raw.githubusercontent.com/lostdragon24/lopds/refs/heads/main/doc/favorites.png)            |Избранное - личная коллекция                |
|[https://raw.githubusercontent.com/lostdragon24/lopds/refs/heads/main/doc/ratings.png)|Система рейтингов - оценка книг             |
|!(https://raw.githubusercontent.com/lostdragon24/lopds/refs/heads/main/doc/bookmarks.png)            |Система закладок - при онлайн чтении        |
|!(https://raw.githubusercontent.com/lostdragon24/lopds/refs/heads/main/doc/citats.png)              |Цитаты - избранные цитаты...                |
|!(https://raw.githubusercontent.com/lostdragon24/lopds/refs/heads/main/doc/stats.png)                |Статистика коллекции                        |
|!(https://raw.githubusercontent.com/lostdragon24/lopds/refs/heads/main/doc/admin_dashboard.png)      |Главная страница - панели управления        |

### Десктоп-клиент (Qt/C++)

Нативное приложение на Qt6 для удобного управления:


|Интерфейс                                       |Описание                                    |
|------------------------------------------------|--------------------------------------------|
|!(https://i.postimg.cc/6ppyqTJd/book1.png)      |Главное окно программы                      |
|!(https://i.postimg.cc/kggBGD3y/scanner-book.png)|Интерфейс сканера - управление сканированием|
|!(https://i.postimg.cc/GmmHt9wx/settings-book.png)|Окно настроек - конфигурация                |
|!(https://i.postimg.cc/Z55Cn0tc/reader-book.png)|Встроенная читалка FB2                      |

### 🔍 Борьба с дубликатами

* **Поле file_hash в таблице books является уникальным**
  *Это препятствует
  случайному добавлению стопроцентного дубликата книги.
  *
* **Параметр find_dup = yes в конфигурационном файле сканера**
  *При включении
  данного параметра в сканере происходит поиск дубликатов по связке полей -
  Наименование - Автор. Если найденный в базе экземпляр меньше (<10%), то книга заменяется*

### Оптимизация базы данных

Добавьте в crontab для регулярной оптимизации:

bash

0 2 * * * php /path/to/www/cron/optimize_tables.php >>
/var/log/library_optimize.log 2>\&1

## 📄 Лицензия

Проект распространяется под лицензией GNU GPL v2. Полный текст лицензии
доступен в файле LICENSE.

- - -
Little OPDS - сделано с ❤️ для любителей книг и open-source

