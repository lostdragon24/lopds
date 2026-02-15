# Book Library Scanner

Утилита для автоматического сканирования и организации коллекции электронных книг.

**Возможности**  
Автоматическое сканирование директорий с книгами

**Поддержка популярных форматов**:  
FB2, EPUB (в планах PDF, MOBI, TXT)

**Работа с архивами:** ZIP, RAR, 7Z (извлечение книг без распаковки)

**Парсинг метаданных**: 
заголовки, авторы, серии, жанры и многое другое  
Импорт из INPX \- поддержка библиотечных коллекций в формате .inpx

**Поддержка СУБД**: 
SQLite и MySQL

**Умное сканирование**: 
пропуск не измененных файлов, отслеживание хешей  
Логирование.  
Поддерживаемые форматы Форматы книг FB2 (FictionBook) и epub (Electronic Publication) \- с полным парсингом метаданных

**Архивные форматы**

* ZIP  
* RAR  
* 7Z

**Технические особенности**

* Модульная архитектура с разделением парсеров, БД и сканера  
* Поддержка кодировок (UTF-8, Windows-1251 (через iconv))  
* Эффективное хранение в БД с избежанием дубликатов

Конфигурируемость через INI-файл

**Установка и запуск:**  
**Требования**  
Для Ubuntu/Debian  
*sudo apt-get install libsqlite3-dev libarchive-dev libssl-dev libmysqlclient-dev libiconv*

**Компиляция**  
*make*

**Конфигурация**  
Создайте config.ini:  
*\[database\]*  
*type \= sqlite \# или mysql*  
*path \= ./books.db \# для SQLite*  
*Для MySQL*  
*; type \= mysql*  
*; host \= localhost*  
*; user \= username*  
*; password \= password*  
*; database \= booklib*  
*\[scanner\]*  
*books\_dir \= /path/to/your/books*  
*log\_file \= ./scanner.log*  
*rescan\_unchanged \= no*  
*enable\_inpx \= yes*  
*clear\_database\_inpx \= no*

**Запуск**  
./book\_scanner \[config\_path\]

**Структура базы данных**  
Таблица books  
file\_path \- путь к файлу/архиву  
file\_name \- имя файла title, author, genre, series \- метаданные  
series\_number \- номер в серии year, language, publisher file\_size \- размер в байтах  
file\_type \- расширение файла archive\_internal\_path \- путь внутри архива (для архивов)  
Таблица archives

* Отслеживание состояния архивных файлов (медленно на больших архивах)  
* Хеши для определения изменений  
* Статистика по файлам  
* INPX поддержка  
* 

**Проект поддерживает импорт библиотечных коллекций в формате INPX**:  
*\[scanner\]*  
*enable\_inpx \= yes*  
*clear\_database\_inpx \= no \# очистка БД перед импортом*

**Использование**  
Настройте конфигурацию под вашу среду  
Запустите сканер для первоначального индексирования  
Интегрируйте с веб\-интерфейсом или используйте SQL-запросы напрямую  
Настройте периодическое сканирование для обновлений

**Примеры использования**  
Поиск книг по автору  
*SELECT title, series, series\_number FROM books WHERE author LIKE ‘%Толстой%’ ORDER BY series, series\_number;*

**Статистика по коллекции**  
*SELECT COUNT(\*) as total\_books, COUNT(DISTINCT author) as unique\_authors, COUNT(DISTINCT series) as unique\_series FROM books;*


**Оптимизация базы данных**
Добавьте в crontab

*0 2 * * * php /path/to/www/cron/optimize_tables.php >> /var/log/library_optimize.log 2>&1*





**Разработка**  
Проект написан на C (может быть собран в Linux, FreeBSD, MacOS, Windows (с небольшими корректировками)) с использованием:

* libarchive \- работа с архивами  
* SQLite3/MySQL C API \- работа с базами данных  
* OpenSSL \- вычисление хешей  
* iconv \- конвертация кодировок


**Веб интерфейс написан на PHP:**

![Веб интерфейс написан на PHP](https://i.ibb.co/xS483ZrP/homepage.png)

![Веб интерфейс написан на PHP](https://i.ibb.co/DH5z6nt2/search.png)

**Подробнее о книге:**
![Веб интерфейс написан на PHP](https://i.ibb.co/DDn36TR9/book-detail.png)

**Встроенная читалка:**
![Веб интерфейс написан на PHP](https://i.ibb.co/KpwpNC53/book-reading.png)

**Избранное:**
![Веб интерфейс написан на PHP](https://i.ibb.co/7xCzGMqC/favorites.png)

**Рейтинги:**
![Веб интерфейс написан на PHP](https://i.ibb.co/1f1vjHKq/rating.png)

**Статистика библиотеки:**
![Веб интерфейс написан на PHP](https://i.ibb.co/jZhKFVhg/statistica.png)

**Статистика кэша:**
![Веб интерфейс написан на PHP](https://i.ibb.co/VcrCF6VV/cache-stat.png)


**Графический интерфейс реализован на QT Creator на языке C++ с использованием QT6.**


![Окно программы на QT](https://i.postimg.cc/6ppyqTJd/book1.png)

![Интерфейс сканера в программе](https://i.postimg.cc/kggBGD3y/scanner-book.png)

![Окно настроек](https://i.postimg.cc/GmmHt9wx/settings-book.png)

![Встроенная читалка fb2](https://i.postimg.cc/Z55Cn0tc/reader-book.png)






**Важные изменения в WEB интерфейсе в версии 1.13**

В версии WEB интерфейса 1.13 введена система рейтингов книг и система добавления в избранное.

Для работы требуется создать таблицы: http://ваш_сайт/admin/install_ratings_favorites.php
Либо руками выполнить скрипт (/sql/install_tables_manual.sql или при использовании sqlite /sql/install_tables_manual_sqlite.sql)

В версии сканера 1.13 данные таблицы создаются автоматически при создании базы...



Лицензия GNU GPL v2  

