# **Little OPDS 📚**

Утилита для автоматического сканирования и организации коллекции электронных книг с веб\-интерфейсом и десктоп-клиентом (сканер встроен в клиент).

## **✨ Возможности**

### **Основные функции**

* 📁 Автоматическое сканирование директорий с книгами  
* 📦 Работа с архивами: ZIP, RAR, 7Z (извлечение книг без полной распаковки)  
* 📊 Парсинг метаданных: заголовки, авторы, серии, жанры и многое другое  
* 🗄️ Поддержка СУБД: SQLite и MySQL  
* 🔍 Умное сканирование: пропуск неизмененных файлов через отслеживание хешей  
* 📝 Логирование всех операций  
* 🔄 Импорт из INPX: поддержка библиотечных коллекций в формате .inpx

## **📚 Поддерживаемые форматы**

### **Книжные форматы**

| Формат | Описание | Статус |
| :---- | :---- | :---- |
| FB2 | FictionBook | ✅ Полный парсинг метаданных |
| EPUB | Electronic Publication | ✅ Полный парсинг метаданных |
| PDF | Portable Document Format | 🚧 В планах |
| MOBI | Mobipocket | 🚧 В планах |
| TXT | Plain Text | 🚧 В планах |

### **Архивные форматы**

* ZIP  
* RAR  
* 7Z

## **🛠 Технические особенности**

### **Архитектура**

* 🧩 Модульная архитектура с разделением:  
  * Парсеры форматов  
  * Работа с базами данных  
  * Сканер файловой системы

### **Кроссплатформенность**

* Поддержка архитектур:  
  * x86\_64 (обычные ПК)  
  * RISC-V (OrangePi RV2 и другие)  
  * ARM (Raspberry Pi и другие)  
* Поддерживаемые ОС:  
  * Linux  
  * FreeBSD  
  * macOS  
  * Windows (с небольшими корректировками)

### **Технологии**

* libarchive \- работа с архивами  
* SQLite3/MySQL C API \- работа с базами данных  
* OpenSSL \- вычисление хешей (MD5, SHA1, SHA256, SHA512)  
* iconv \- конвертация кодировок (UTF-8, Windows-1251)

## **📦 Установка и запуск**

### **Зависимости**

Linux:
>
>libsqlite3-dev libarchive-dev libssl-dev libmysqlclient-dev libiconv-dev
>

>Если в вашем дистрибутиве нет собранного пакета libiconv то скачайте и соберите самостоятельно<br>
>https://ftp.gnu.org/gnu/libiconv/libiconv-1.19.tar.gz<br>



### **Компиляция**
><br>
>make        *\# сборка release версии*<br>
>make debug  *\# сборка с отладочной информацией*<br>
><br>
>make clean  *\# очистка*<br>
><br>

## **⚙ Конфигурация**

Создайте файл config.ini:
>
>\[database\]<br>
>type = sqlite              \# или mysql<br>
>path = ./books.db          \# для SQLite<br>
><br>
>*; Для MySQL*<br>
>*; type = mysql*<br>
>*; host = localhost*<br>
>*; user = username*<br>
>*; password = password*<br>
>*; database = booklib*<br>
>*; port = 3306*<br>
><br>
>\[scanner\]<br>
>books\_dir = /path/to/your/books<br>
>log\_file = ./scanner.log<br>
>rescan\_unchanged = no<br>
>enable\_inpx = yes<br>
>clear\_database\_inpx = no<br>
>hash\_algorithm = md5       \# md5, sha1, sha256, sha512<br>
><br>
>log\_level = info           \# debug, info, warning, error<br>
>
### **Запуск**

>./book\_scanner              *\# автоматический поиск config.ini*<br>
>./book\_scanner config.ini   *\# с указанием конфигурации*<br>

## **📊 Структура базы данных**

### **Таблица books**

| Поле | Описание |
| :---- | :---- |
| id | Первичный ключ |
| file\_path | Путь к файлу/архиву |
| file\_name | Имя файла |
| file\_size | Размер в байтах |
| file\_type | Расширение файла |
| archive\_path | Путь к архиву |
| archive\_internal\_path | Путь внутри архива |
| file\_hash | Хеш файла |
| title | Название книги |
| author | Автор |
| genre | Жанр |
| series | Серия |
| series\_number | Номер в серии |
| year | Год издания |
| language | Язык |
| publisher | Издатель |
| description | Описание |
| added\_date | Дата добавления |
| last\_modified | Дата изменения |
| last\_scanned | Дата последнего сканирования |

### **Таблица archives**

* Отслеживание состояния архивных файлов  
* Хеши для определения изменений  
* Статистика по файлам

### **Таблица book\_ratings**

* Рейтинги книг (1-5)  
* Привязка по IP пользователя  
* Защита от повторного голосования

### **Таблица book\_favorites**

* Избранное пользователей  
* Привязка по IP

## **🔄 INPX поддержка**

Проект поддерживает импорт библиотечных коллекций в формате INPX:

>
>\[scanner\]  <br>
>enable\_inpx = yes<br>
>clear\_database\_inpx = no    \# очистка БД перед импортом<br>
><br>

## **💡 Примеры использования**

### **Поиск книг по автору**

>
>SELECT title, series, series\_number
>FROM books
>WHERE author LIKE '%Толстой%'
>ORDER BY series, series\_number;

### **Статистика по коллекции**

>
>SELECT
>COUNT(\*) as total\_books,
>COUNT(DISTINCT author) as unique\_authors,
>COUNT(DISTINCT series) as unique\_series,
>COUNT(DISTINCT genre) as unique\_genres,
>SUM(file\_size) as total\_size\_bytes

FROM books;

### **Топ популярных книг (по рейтингу)**

>
>SELECT b.title, b.author, AVG(r.rating) as avg\_rating
>FROM books b
>JOIN book\_ratings r ON b.id = r.book\_id
>GROUP BY b.id
>ORDER BY avg\_rating DESC
>LIMIT 10;

## **🖥 Интерфейсы**

### **Веб-интерфейс (PHP c реализацией OPDS каталога)**

Веб\-интерфейс для управления библиотекой:

| Интерфейс | Описание |
| :---- | :---- |
| [https://i.ibb.co/xS483ZrP/homepage.png](https://i.ibb.co/xS483ZrP/homepage.png) | Главная страница \- поиск и навигация |
| [https://i.ibb.co/DH5z6nt2/search.png](https://i.ibb.co/DH5z6nt2/search.png) | Расширенный поиск \- фильтрация по различным критериям |
| [https://i.ibb.co/DDn36TR9/book-detail.png](https://i.ibb.co/DDn36TR9/book-detail.png) | Подробная информация о книге |
| [https://i.ibb.co/KpwpNC53/book-reading.png](https://i.ibb.co/KpwpNC53/book-reading.png) | Встроенная читалка \- чтение прямо в браузере |
| [https://i.ibb.co/7xCzGMqC/favorites.png](https://i.ibb.co/7xCzGMqC/favorites.png) | Избранное \- личная коллекция |
| [https://i.ibb.co/1f1vjHKq/rating.png](https://i.ibb.co/1f1vjHKq/rating.png) | Система рейтингов \- оценка книг |
| [https://i.ibb.co/jZhKFVhg/statistica.png](https://i.ibb.co/jZhKFVhg/statistica.png) | Статистика \- анализ коллекции |
| [https://i.ibb.co/VcrCF6VV/cache-stat.png](https://i.ibb.co/VcrCF6VV/cache-stat.png) | Статистика кэша \- оптимизация производительности |

### **Десктоп-клиент (Qt/C++)**

Нативное приложение на Qt6 для удобного управления:

| Интерфейс | Описание |
| :---- | :---- |
| [https://i.postimg.cc/6ppyqTJd/book1.png](https://i.postimg.cc/6ppyqTJd/book1.png) | Главное окно программы |
| [https://i.postimg.cc/kggBGD3y/scanner-book.png](https://i.postimg.cc/kggBGD3y/scanner-book.png) | Интерфейс сканера \- управление сканированием |
| [https://i.postimg.cc/GmmHt9wx/settings-book.png](https://i.postimg.cc/GmmHt9wx/settings-book.png) | Окно настроек \- конфигурация |
| [https://i.postimg.cc/Z55Cn0tc/reader-book.png](https://i.postimg.cc/Z55Cn0tc/reader-book.png) | Встроенная читалка FB2 |

## **Что сделано:**
**📌 Изменения в веб\-интерфейсе версии 1.13.1**

* Настройки вынесены в файл .env;
* Установщик для веб\-интерфейса (если нет конфигурационного файла или проблемы с БД открывается установщик);
* Панель администратора для веб\-интерфейса;  
* Работа со сканером (запуск, остановка) из панели администратора;  
* Редактирование настроек коллекции из панели администратора;  
* Редактирование книг из панели администратора;  
* Добавление книг из панели администратора;
* Управление базой данных (просмотр таблиц, бэкапы) из панели администратора.

**📌 Изменения в коде сканера версии 0.1.13:**

* Замена SQL запросов на prepared statements (защита от SQL-инъекций)
* Добавлена валидация путей с использованием realpath() и is\_path\_safe()  
* Создан модуль path\_validation.c/.h для централизованной проверки путей  
* Проверка переполнения буфера в операциях с строками
* Добавлены проверки возвращаемых значений системных вызовов (read, write, ftruncate)  
* Защита от переполнения при сложении размеров файлов (LONG\_MAX)  
* Исправлены утечки памяти
* Создан макрос SAFE\_FREE для безопасного освобождения памяти  
* Убраны дублирующиеся определения функций (extract\_xml\_attribute, free\_strings\_array)  
* Правильная обработка частичного выделения памяти (проверка каждого malloc)  
* Добавлен паттерн goto cleanup для единообразного освобождения ресурсов  
* Улучшено логирование ошибок с детальной информацией  
* Добавлен счетчик ошибок при обработке архивов (макс. 10 ошибок)  
* Корректное продолжение работы при ошибках в отдельных файлах архива  
* Проверка входных параметров функций на NULL
* Добавлены проверки кодировок и конвертация при необходимости  
* Оптимизация для архивов \>1GB \- быстрое хеширование (первые и последние 10MB)  
* Потоковая обработка файлов \>10MB в архивах  
* Ограничение на размер извлекаемых файлов (макс. 500MB)  
* Защита от выделения слишком большой памяти  
* Адаптация для RISC-V архитектуры (OrangePi RV2)  
* Универсальное определение my\_bool для разных платформ  
* Добавлены проверки для Windows (кросс-платформенные макросы)  
* Корректная работа с блокировками (flock на Unix, LockFile на Windows)  
* Исправлена функция is\_already\_running \- различает отсутствие файла и реальную блокировку  
* Чтение PID из lock-файла для информации о запущенном процессе  
* Корректная запись PID при создании блокировки  
* Проверка существования файла перед удалением  
* Улучшен парсинг EPUB \- корректное извлечение из временных файлов  
* Исправлены проблемы с archive\_read\_data \- правильное использование после чтения  
* Замена конкатенации строк на prepared statements  
* Правильное использование my\_bool для is\_null (вместо int)  
* Добавлены флаги true\_val/false\_val для указателей на is\_null  
* Улучшена функция check\_book\_exists\_smart с защитой от переполнения  
* Добавлена проверка длины SQL запросов перед выполнением  
* Увеличен буфер для чтения строк (до 10KB)  
* Добавлена обработка слишком длинных строк в конфиге  
* Замена snprintf на safe\_snprintf в коде  
* Добавлена валидация путей из конфигурации  
* Кэширование результатов проверки существования книг  
* Оптимизация хеширования для больших файлов  
* Пакетная обработка записей в БД  
* Уменьшено количество лишних логов  
* Обновлен Makefile  
* Добавлены зависимости в Makefile

### **Оптимизация базы данных**

Добавьте в crontab для регулярной оптимизации:
>
>0 2 \* \* \* php /path/to/www/cron/optimize\_tables.php \>\> /var/log/library\_optimize.log 2\>&1<br>
>0 \* \* \* \* php /path/to/www/cron/update\_top_rated\_cache.php \>\> /var/log/library\_cache.log 2\>&1<br>
><br>


### **Один маленький нюанс**

>Для обеспечения регистронезависимости кириллицы в SQLite (поиск LIKE, сортировка) стандартный UPPER()/LOWER() не работает, так как он поддерживает только ASCII. Основные решения — использование расширения ICU (International Components for Unicode) при компиляции SQLite или приведение строк к верхнему регистру в коде приложения перед запросом. Поэтому в веб-интерфейсе при использовании СУБД sqlite пишите с заглавной буквы, либо пропускайте первую букву. Либо пересоберите sqlite с поддержкой ICU.



### **Необходимые модули PHP**
>  php8-cli php8-common php8-mysql php8-sqlite3 php8-zip php8-gd php8-mbstring php8-xml php8-curl php8-fileinfo php8-apcu php8-posix php8-json php8-xmlreader php8-xmlwriter php8-pdo php8-mbstring php8-imagick php8-iconv php8-gettext php8-fileinfo<br>
<br>

### **Необходимые настройки PHP**
>php_value upload_max_filesize 100M<br>
>php_value post_max_size 100M<br>
>php_value memory_limit 256M<br>
>php_value max_execution_time 300<br>
>php_value max_input_time 300<br>
>apc.enabled = 1<br>
>apc.shm_size = 256M<br>

<br>

## **📄 Лицензия**

Проект распространяется под лицензией GNU GPL v2. Полный текст лицензии доступен в файле LICENSE.

---

Little OPDS \- сделано с ❤️ для любителей книг и open-source  
