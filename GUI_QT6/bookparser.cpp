#include "bookparser.h"
#include <QFile>
#include <QFileInfo>
#include <QDebug>
#include <QXmlStreamReader>
#include <QRegularExpression>
#include <QBuffer>
#include <archive.h>
#include <archive_entry.h>
#include <QTemporaryFile>


#ifdef QT_DEBUG
#define DEBUG_LOG qDebug
#else
#define DEBUG_LOG if(false) qDebug
#endif

static inline QString cleanTagName(const QString& name) {
    if (name.contains('}')) {
        return name.split('}').last();
    }
    return name;
}

// Добавим структуру для работы с памятью
struct MemoryReader {
    const char* data;
    size_t size;
    size_t offset;
};

// Callback-функции для работы с памятью через libarchive
static la_ssize_t memory_read(struct archive* /*a*/, void* client_data, const void** buff) {
    MemoryReader* reader = static_cast<MemoryReader*>(client_data);

    if (reader->offset >= reader->size) {
        return 0; // EOF
    }

    *buff = reader->data + reader->offset;
    size_t to_read = qMin(size_t(10240), reader->size - reader->offset);
    reader->offset += to_read;

    return to_read;
}

static int memory_close(struct archive* /*a*/, void* /*client_data*/) {
    return ARCHIVE_OK;
}


BookParser::BookParser()
{
    initGenreMap();
}

BookMeta BookParser::parseMetadata(const QString &filePath)
{
    BookMeta meta;
    QFileInfo fileInfo(filePath);
    QString extension = fileInfo.suffix().toLower();

    // Сначала пробуем распарсить файл
    if (extension == "fb2") {
        meta = parseFb2(filePath);
    } else if (extension == "epub") {
        meta = parseEpub(filePath);
    } else if (extension == "pdf") {
        meta = parsePdf(filePath);
    }

    // Если не удалось распарсить, используем имя файла
    if (meta.title.isEmpty()) {
        meta.title = extractFromFileName(fileInfo.fileName());
        meta.author = "Неизвестный автор";
    }

    return meta;
}

BookMeta BookParser::parseMetadataFromMemory(const QByteArray &data, const QString &fileExtension)
{
    BookMeta meta;

    QString extension = fileExtension.toLower();

    if (extension == "fb2") {
           meta = parseFb2FromMemory(data);
       } else if (extension == "epub") {
           // Для EPUB в памяти создаем временный файл
           QTemporaryFile tempFile;
           if (tempFile.open()) {
               tempFile.write(data);
               tempFile.flush();
               meta = parseEpub(tempFile.fileName());
               tempFile.close();
           }
       }
    // Для других форматов можно добавить аналогичные методы

    // Fallback
    if (meta.title.isEmpty()) {
        meta.title = "Книга из архива";
        meta.author = "Неизвестный автор";
    }

    return meta;
}


static QString extractTextFromElement(QXmlStreamReader& xml) {
    QString result;
    while (!xml.atEnd()) {
        xml.readNext();
        if (xml.isEndElement()) {
            break;
        }
        if (xml.isCharacters()) {
            result += xml.text().toString();
        } else if (xml.isStartElement()) {
            // Рекурсивно обрабатываем вложенные элементы
            result += extractTextFromElement(xml);
        }
    }
    return result.simplified();
}


BookMeta BookParser::parseFb2(const QString &filePath)
{
    BookMeta meta;
    QFile file(filePath);
    if (!file.open(QIODevice::ReadOnly | QIODevice::Text)) return meta;

    QXmlStreamReader xml(&file);
    bool inTitleInfo = false;    // флаг, что мы внутри <title-info>
    bool inAuthor = false;
    QString firstName, lastName, middleName;

    while (!xml.atEnd() && !xml.hasError()) {
        QXmlStreamReader::TokenType token = xml.readNext();

        if (token == QXmlStreamReader::StartElement) {
            QString elementName = xml.name().toString();

            if (elementName == "title-info") {
                inTitleInfo = true;
            }
            else if (inTitleInfo && elementName == "author") {
                inAuthor = true;
                firstName.clear();
                lastName.clear();
                middleName.clear();
            }
            else if (inAuthor) {
                if (elementName == "first-name") {
                    firstName = xml.readElementText();
                } else if (elementName == "last-name") {
                    lastName = xml.readElementText();
                } else if (elementName == "middle-name") {
                    middleName = xml.readElementText();
                }
            }
            else if (inTitleInfo && elementName == "book-title") {
                meta.title = xml.readElementText();
            }
            else if (inTitleInfo && elementName == "sequence") {
                QXmlStreamAttributes attrs = xml.attributes();
                meta.series = attrs.value("name").toString();
                meta.seriesNumber = attrs.value("number").toInt();
                xml.readElementText();
            }
            else if (inTitleInfo && elementName == "annotation") {
                meta.description = extractTextFromElement(xml);
            }
            else if (inTitleInfo && elementName == "genre") {
                QString genre = xml.readElementText();
                meta.genre = genreMap.value(genre, genre);
            }
            // Можно добавить обработку year, lang из title-info
        }
        else if (token == QXmlStreamReader::EndElement) {
            QString elementName = xml.name().toString();
            if (elementName == "title-info") {
                // Завершили title-info — сохраняем автора
                if (!lastName.isEmpty() && !firstName.isEmpty()) {
                    meta.author = QString("%1 %2").arg(lastName, firstName);
                    if (!middleName.isEmpty()) meta.author += " " + middleName;
                } else if (!lastName.isEmpty()) {
                    meta.author = lastName;
                } else if (!firstName.isEmpty()) {
                    meta.author = firstName;
                }
                inTitleInfo = false;
            }
            else if (inTitleInfo && elementName == "author") {
                inAuthor = false;
            }
        }
    }

    file.close();
    return meta;
}

BookMeta BookParser::parseFb2FromMemory(const QByteArray &data)
{
    BookMeta meta;
    QBuffer buffer;
    buffer.setData(data);
    if (!buffer.open(QIODevice::ReadOnly | QIODevice::Text)) {
        return meta;
    }

    QXmlStreamReader xml(&buffer);
    bool inTitleInfo = false;
    bool inAuthor = false;
    QString firstName, lastName, middleName;

    while (!xml.atEnd() && !xml.hasError()) {
        QXmlStreamReader::TokenType token = xml.readNext();

        if (token == QXmlStreamReader::StartElement) {
            QString elementName = xml.name().toString();

            if (elementName == "title-info") {
                inTitleInfo = true;
            }
            else if (inTitleInfo && elementName == "author") {
                inAuthor = true;
                firstName.clear();
                lastName.clear();
                middleName.clear();
            }
            else if (inAuthor) {
                if (elementName == "first-name") {
                    firstName = xml.readElementText();
                } else if (elementName == "last-name") {
                    lastName = xml.readElementText();
                } else if (elementName == "middle-name") {
                    middleName = xml.readElementText();
                }
            }
            else if (inTitleInfo && elementName == "book-title") {
                meta.title = xml.readElementText();
            }
            else if (inTitleInfo && elementName == "sequence") {
                QXmlStreamAttributes attrs = xml.attributes();
                meta.series = attrs.value("name").toString();
                meta.seriesNumber = attrs.value("number").toInt();
                xml.readElementText();
            }
            else if (inTitleInfo && elementName == "annotation") {
                meta.description = extractTextFromElement(xml);
            }
            else if (inTitleInfo && elementName == "genre") {
                QString genre = xml.readElementText();
                meta.genre = genreMap.value(genre, genre);
            }
            // Можно добавить обработку year, lang
        }
        else if (token == QXmlStreamReader::EndElement) {
            QString elementName = xml.name().toString();
            if (elementName == "title-info") {
                // Сохраняем автора, собранного внутри title-info
                if (!lastName.isEmpty() && !firstName.isEmpty()) {
                    meta.author = QString("%1 %2").arg(lastName, firstName);
                    if (!middleName.isEmpty()) meta.author += " " + middleName;
                } else if (!lastName.isEmpty()) {
                    meta.author = lastName;
                } else if (!firstName.isEmpty()) {
                    meta.author = firstName;
                }
                inTitleInfo = false;
            }
            else if (inTitleInfo && elementName == "author") {
                inAuthor = false;
            }
        }
    }

    if (xml.hasError()) {
        qDebug() << "XML parsing error in memory:" << xml.errorString();
    }

    buffer.close();
    return meta;
}

QString BookParser::findOpfPathInEpub(const QString &filePath)
{
    qDebug() << "Looking for container.xml in EPUB:" << filePath;

    QString containerContent = readFileFromEpub(filePath, "META-INF/container.xml");
    if (containerContent.isEmpty()) {
        qDebug() << "Cannot find container.xml in EPUB";
        return QString();
    }

    qDebug() << "Found container.xml, parsing...";
    return parseContainerXml(containerContent);
}

QString BookParser::readFileFromEpub(const QString &archivePath, const QString &internalPath)
{
    qDebug() << "Reading file from EPUB:" << internalPath;

    struct archive *a = archive_read_new();
    if (!a) {
        qDebug() << "Failed to create archive reader";
        return QString();
    }

    // Настраиваем архив
    archive_read_support_format_zip(a);
    archive_read_support_filter_all(a);

    // Открываем архив
    QByteArray archivePathBytes = archivePath.toLocal8Bit();
    int r = archive_read_open_filename(a, archivePathBytes.constData(), 10240);
    if (r != ARCHIVE_OK) {
        qDebug() << "Failed to open EPUB archive:" << archive_error_string(a);
        archive_read_free(a);
        return QString();
    }

    QString content;
    struct archive_entry *entry;
    bool found = false;

    // Ищем нужный файл
    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        const char* filename = archive_entry_pathname(entry);
        if (!filename) {
            archive_read_data_skip(a);
            continue;
        }

        QString entryName = QString::fromUtf8(filename);

        if (entryName == internalPath) {
            qDebug() << "Found target file:" << internalPath;

            // Читаем содержимое
            QByteArray fileContent;
            const void *buff;
            size_t size;
            la_int64_t offset;

            while (archive_read_data_block(a, &buff, &size, &offset) == ARCHIVE_OK) {
                fileContent.append(static_cast<const char*>(buff), size);
            }

            content = QString::fromUtf8(fileContent);
            found = true;
            break;
        }

        archive_read_data_skip(a);
    }

    if (!found) {
        qDebug() << "File not found in EPUB:" << internalPath;
    }

    // Всегда закрываем архив
    archive_read_close(a);
    archive_read_free(a);

    return content;
}

QString BookParser::parseContainerXml(const QString& containerContent)
{
    qDebug() << "Parsing container.xml...";

    QXmlStreamReader xml(containerContent);
    QString opfPath;

    while (!xml.atEnd() && !xml.hasError()) {
        QXmlStreamReader::TokenType token = xml.readNext();

        if (token == QXmlStreamReader::StartElement) {
            if (xml.name().toString() == "rootfile") {
                QXmlStreamAttributes attributes = xml.attributes();
                opfPath = attributes.value("full-path").toString();
                qDebug() << "Found rootfile with full-path:" << opfPath;
                break;
            }
        }
    }

    if (xml.hasError()) {
        qDebug() << "XML parsing error in container.xml:" << xml.errorString();
    }

    return opfPath;
}

void BookParser::parseOpfContent(const QString& opfContent, BookMeta& meta)
{
    qDebug() << "Parsing OPF content...";

    QXmlStreamReader xml(opfContent);

    while (!xml.atEnd() && !xml.hasError()) {
        QXmlStreamReader::TokenType token = xml.readNext();

        if (token == QXmlStreamReader::StartElement) {
            QString elementName = xml.name().toString();

            if (elementName == "title") {
                meta.title = xml.readElementText();
                qDebug() << "Found title:" << meta.title;
            }
            else if (elementName == "creator") {
                meta.author = xml.readElementText();
                qDebug() << "Found author:" << meta.author;
            }
            else if (elementName == "subject") {
                QString genre = xml.readElementText();
                meta.genre = genreMap.value(genre, genre);
                qDebug() << "Found genre:" << meta.genre;
            }
            else if (elementName == "description") {
                meta.annotation = xml.readElementText();
                qDebug() << "Found description";
            }
            else if (elementName == "date") {
                QString dateStr = xml.readElementText();
                QRegularExpression yearRegex(R"(\b(\d{4})\b)");
                QRegularExpressionMatch match = yearRegex.match(dateStr);
                if (match.hasMatch()) {
                    meta.year = match.captured(1).toInt();
                    qDebug() << "Found year:" << meta.year;
                }
            }
            else if (elementName == "publisher") {
                meta.publisher = xml.readElementText();
                qDebug() << "Found publisher:" << meta.publisher;
            }
            else if (elementName == "language") {
                meta.language = xml.readElementText();
                qDebug() << "Found language:" << meta.language;
            }
        }
    }

    if (xml.hasError()) {
        qDebug() << "XML parsing error in OPF:" << xml.errorString();
    }

    // Если не нашли автора или название, используем имя файла
    if (meta.title.isEmpty()) {
        QFileInfo fileInfo(currentEpubPath);
        meta.title = fileInfo.baseName();
        qDebug() << "Using filename as title:" << meta.title;
    }

    if (meta.author.isEmpty()) {
        meta.author = "Неизвестный автор";
    }
}


QString BookParser::findOpfPathInEpubData(const QByteArray &epubData)
{
    qDebug() << "Looking for container.xml in EPUB data";

    QString containerContent = readFileFromEpubData(epubData, "META-INF/container.xml");
    if (containerContent.isEmpty()) {
        qDebug() << "Cannot find container.xml in EPUB data";
        return QString();
    }

    qDebug() << "Found container.xml, parsing...";
    return parseContainerXml(containerContent);
}

QString BookParser::readFileFromEpubData(const QByteArray &epubData, const QString &internalPath)
{
    QByteArray fileContent = extractFileFromEpubData(epubData, internalPath);
    if (fileContent.isEmpty()) {
        return QString();
    }

    return QString::fromUtf8(fileContent);
}

QByteArray BookParser::extractFileFromEpubData(const QByteArray &epubData, const QString &internalPath)
{
    qDebug() << "Extracting file from EPUB data:" << internalPath;

    struct archive* a = archive_read_new();
    if (!a) {
        qDebug() << "Failed to create archive reader";
        return QByteArray();
    }

    // Настраиваем архив
    archive_read_support_format_zip(a);
    archive_read_support_filter_all(a);

    // Подготавливаем структуру для чтения из памяти
    MemoryReader reader;
    reader.data = epubData.constData();
    reader.size = epubData.size();
    reader.offset = 0;

    // Открываем архив из памяти - используем правильную сигнатуру
    int r = archive_read_open(a, &reader, nullptr, memory_read, memory_close);
    if (r != ARCHIVE_OK) {
        qDebug() << "Failed to open EPUB from memory:" << archive_error_string(a);
        archive_read_free(a);
        return QByteArray();
    }

    QByteArray content;
    struct archive_entry* entry;
    bool found = false;

    // Ищем нужный файл
    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        const char* filename = archive_entry_pathname(entry);
        if (!filename) {
            archive_read_data_skip(a);
            continue;
        }

        QString entryName = QString::fromUtf8(filename);

        if (entryName == internalPath) {
            qDebug() << "Found target file:" << internalPath;

            // Читаем содержимое
            const void* buff;
            size_t size;
#if ARCHIVE_VERSION_NUMBER >= 3000000
            int64_t offset;
#else
            off_t offset;
#endif

            while (archive_read_data_block(a, &buff, &size, &offset) == ARCHIVE_OK) {
                content.append(static_cast<const char*>(buff), size);
            }

            found = true;
            qDebug() << "Successfully extracted file, size:" << content.size();
            break;
        }

        archive_read_data_skip(a);
    }

    if (!found) {
        qDebug() << "File not found in EPUB data:" << internalPath;
    }

    // Всегда закрываем архив
    archive_read_close(a);
    archive_read_free(a);

    return content;
}

//оконание парсера формата epub


BookMeta BookParser::parsePdf(const QString &filePath)
{
    // Упрощенный парсинг PDF
    BookMeta meta;
    QFileInfo fileInfo(filePath);

    // В реальности нужно использовать библиотеки для парсинга PDF метаданных
    QString fileName = fileInfo.fileName();
    meta.title = extractFromFileName(fileName);
    meta.author = "Неизвестный автор";

    qDebug() << "PDF file, using filename for title:" << meta.title;

    return meta;
}

QString BookParser::extractFromFileName(const QString &fileName)
{
    QString name = fileName;

    // Убираем расширение
    int dotIndex = name.lastIndexOf('.');
    if (dotIndex != -1) {
        name = name.left(dotIndex);
    }

    qDebug() << "Extracting from filename:" << fileName << "->" << name;

    // Обрабатываем разные форматы имен файлов

    // Формат: "01. Название книги.epub"
    if (name.contains('.')) {
        QStringList parts = name.split('.');
        if (parts.size() >= 2) {
            // Пропускаем номер (первую часть) и берем остальное как название
            QString title = parts.mid(1).join('.').trimmed();
            if (!title.isEmpty()) {
                qDebug() << "Extracted title from numbered format:" << title;
                return title;
            }
        }
    }

    // Формат: "Автор - Название.epub"
    if (name.contains(" - ")) {
        int separatorPos = name.indexOf(" - ");
        if (separatorPos != -1) {
            QString title = name.mid(separatorPos + 3).trimmed();
            if (!title.isEmpty()) {
                qDebug() << "Extracted title from author-title format:" << title;
                return title;
            }
        }
    }

    // Формат: "Название_книги.epub" - заменяем подчеркивания на пробелы
    if (name.contains('_')) {
        QString title = name.replace('_', ' ').trimmed();
        qDebug() << "Extracted title from underscore format:" << title;
        return title;
    }

    // Если ничего не подошло, возвращаем очищенное имя
    qDebug() << "Using cleaned filename as title:" << name;
    return name;
}


//парсер формата epub


BookMeta BookParser::parseEpub(const QString &filePath)
{
    BookMeta meta;

    qDebug() << "Starting EPUB parsing:" << filePath;

    // Сохраняем путь для использования в других методах
    currentEpubPath = filePath;

    // Шаг 1: Находим путь к OPF файлу через container.xml
    QString opfPath = findOpfPathInEpub(filePath);
    if (opfPath.isEmpty()) {
        qDebug() << "Cannot find OPF path in EPUB";
        return meta;
    }

    qDebug() << "Found OPF path:" << opfPath;

    // Шаг 2: Читаем OPF файл
    QString opfContent = readFileFromEpub(filePath, opfPath);
    if (opfContent.isEmpty()) {
        qDebug() << "Cannot read OPF file:" << opfPath;
        return meta;
    }

    qDebug() << "Successfully read OPF file, size:" << opfContent.size();

    // Шаг 3: Парсим OPF содержимое
    parseOpfContent(opfContent, meta);

    return meta;
}



BookMeta BookParser::parseEpubFromMemory(const QByteArray &epubData)
{
    BookMeta meta;

    if (epubData.isEmpty()) {
        DEBUG_LOG() << "Empty EPUB data";
        return meta;
    }

    DEBUG_LOG() << "Parsing EPUB from memory, size:" << epubData.size();

    struct archive *a = archive_read_new();
    if (!a) {
        DEBUG_LOG() << "Failed to create archive";
        return meta;
    }

    archive_read_support_format_zip(a);
    archive_read_support_filter_all(a);

    // Читаем архив из памяти
    int r = archive_read_open_memory(a, const_cast<char*>(epubData.constData()), epubData.size());
    if (r != ARCHIVE_OK) {
        DEBUG_LOG() << "Failed to open EPUB from memory:" << archive_error_string(a);
        archive_read_free(a);
        return meta;
    }

    // Ищем OPF файл через container.xml
    QString opfPath;
    struct archive_entry *entry;

    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        const char* filename = archive_entry_pathname(entry);
        if (filename) {
            QString qfilename = QString::fromUtf8(filename);

            if (qfilename == "META-INF/container.xml") {
                // Читаем container.xml
                QByteArray containerData;
                const void *buff;
                size_t size;
                la_int64_t offset;

                while (archive_read_data_block(a, &buff, &size, &offset) == ARCHIVE_OK) {
                    containerData.append(static_cast<const char*>(buff), size);
                }

                QString containerXml = QString::fromUtf8(containerData);
                opfPath = parseContainerXml(containerXml);

                if (!opfPath.isEmpty()) {
                    break;
                }
            }
        }
        archive_read_data_skip(a);
    }

    if (opfPath.isEmpty()) {
        DEBUG_LOG() << "OPF path not found";
        archive_read_free(a);
        return meta;
    }

    DEBUG_LOG() << "Found OPF path:" << opfPath;

    // Ищем OPF файл в архиве
    archive_read_free(a);

    a = archive_read_new();
    archive_read_support_format_zip(a);
    archive_read_open_memory(a, const_cast<char*>(epubData.constData()), epubData.size());

    QString opfContent;
    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        const char* filename = archive_entry_pathname(entry);
        if (filename && QString::fromUtf8(filename) == opfPath) {
            QByteArray opfData;
            const void *buff;
            size_t size;
            la_int64_t offset;

            while (archive_read_data_block(a, &buff, &size, &offset) == ARCHIVE_OK) {
                opfData.append(static_cast<const char*>(buff), size);
            }

            opfContent = QString::fromUtf8(opfData);
            break;
        }
        archive_read_data_skip(a);
    }

    archive_read_free(a);

    // Парсим OPF
    if (!opfContent.isEmpty()) {
        parseOpfContent(opfContent, meta);
    }

    // Fallback
    if (meta.title.isEmpty()) {
        meta.title = "Неизвестное название";
    }
    if (meta.author.isEmpty()) {
        meta.author = "Неизвестный автор";
    }

    DEBUG_LOG() << "Parsed EPUB - Title:" << meta.title << "Author:" << meta.author;

    return meta;
}

void BookParser::initGenreMap()
{
    // Основные жанры FB2
    genreMap["adv_animal"] = "Природа и животные";
    genreMap["adv_geo"] = "Путешествия и география";
    genreMap["adv_history"] = "История";
    genreMap["adv_indian"] = "Вестерн";
    genreMap["adv_maritime"] = "Море";
    genreMap["adv_modern"] = "Приключения в современном мире";
    genreMap["adv_story"] = "Авантюрный роман";
    genreMap["adv_western"] = "Вестерн";
    genreMap["adventure"] = "Приключения";
    genreMap["child_adv"] = "Приключения для детей и подростков";
    genreMap["tale_chivalry"] = "Рыцарский роман";
    genreMap["antique"] = "Старинное";
    genreMap["antique_ant"] = "Античность";
    genreMap["antique_east"] = "Древневосточная литература";
    genreMap["antique_european"] = "Старая европейская литература";
    genreMap["antique_russian"] = "Древнерусская литература";
    genreMap["architecture_book"] = "Архитектура";
    genreMap["art_criticism"] = "Искусствоведение";
    genreMap["art_world_culture"] = "Мировая художественная культура";
    genreMap["cine"] = "Кино";
    genreMap["cinema_theatre"] = " theatre";
    genreMap["design"] = "Дизайн";
    genreMap["music"] = "Музыка";
    genreMap["music_dancing"] = " dancing";
    genreMap["nonf_criticism"] = "Критика";
    genreMap["notes"] = "Партитуры";
    genreMap["painting"] = "Живопись";
    genreMap["sci_culture"] = "Культура";
    genreMap["theatre"] = "Театр";
    genreMap["visual_arts"] = "Живопись";
    genreMap["child_classical"] = "Классическая детская литература";
    genreMap["child_det"] = "Детектив";
    genreMap["child_education"] = "Образовательная литература";
    genreMap["child_prose"] = "Детская проза";
    genreMap["child_sf"] = "Детская научная фантастика";
    genreMap["child_tale"] = "Сказки";
    genreMap["child_tale_rus"] = "Русские сказки";
    genreMap["child_verse"] = "Стихи";
    genreMap["children"] = "Детская литература";
    genreMap["foreign_children"] = "Детские книги";
    genreMap["prose_game"] = "Игры";
    genreMap["comp_db"] = "Базы данных";
    genreMap["comp_hard"] = "Компьютеры";
    genreMap["comp_osnet"] = "Операционные системы";
    genreMap["comp_programming"] = "Программирование";
    genreMap["comp_soft"] = "Программы";
    genreMap["comp_www"] = "Интернет";
    genreMap["computers"] = "Компьютеры";
    genreMap["tbg_computers"] = "Учебные пособия";
    genreMap["det_action"] = "Боевик";
    genreMap["det_classic"] = "Классический";
    genreMap["det_crime"] = "Криминал";
    genreMap["det_espionage"] = "Шпионаж";
    genreMap["det_hard"] = "Крутой";
    genreMap["det_history"] = "Исторический";
    genreMap["det_irony"] = "Иронический";
    genreMap["det_maniac"] = "Про маньяков";
    genreMap["det_police"] = "Полицейский";
    genreMap["det_political"] = "Политический";
    genreMap["det_su"] = "Советский детектив";
    genreMap["detective"] = "Детектив";
    genreMap["thriller"] = "Триллер";
    genreMap["comedy"] = "Комедия";
    genreMap["drama"] = "Драма";
    genreMap["drama_antique"] = "Античная драма";
    genreMap["dramaturgy"] = "Драматургия";
    genreMap["foreign_dramaturgy"] = "Драматургия";
    genreMap["screenplays"] = "Сценарий";
    genreMap["tragedy"] = "Трагедия";
    genreMap["vaudeville"] = "Мистерия";
    genreMap["accounting"] = "Экономика";
    genreMap["banking"] = "Банкинг";
    genreMap["economics"] = "Экономика";
    genreMap["economics_ref"] = "Деловая литература";
    genreMap["global_economy"] = "Глобальная экономика";
    genreMap["marketing"] = "Маркетинг";
    genreMap["org_behavior"] = "Организация";
    genreMap["personal_finance"] = "Личные финансы";
    genreMap["popular_business"] = "Бизнес";
    genreMap["real_estate"] = "Недвижимость";
    genreMap["small_business"] = "Малый бизнес";
    genreMap["stock"] = "Биржа";
    genreMap["auto_business"] = "Автодело";
    genreMap["equ_history"] = "История техники";
    genreMap["military_weapon"] = "Военная техника и вооружение";
    genreMap["sci_build"] = "Строительство и сопромат";
    genreMap["sci_metal"] = "Металлургия";
    genreMap["sci_radio"] = "Радиоэлектроника";
    genreMap["sci_tech"] = "Техника";
    genreMap["sci_transport"] = "Транспорт и авиация";
    genreMap["city_fantasy"] = "Городское фэнтези";
    genreMap["dragon_fantasy"] = "Драконы";
    genreMap["fairy_fantasy"] = "Мифологическое фэнтези";
    genreMap["fantasy_fight"] = "Битвы";
    genreMap["historical_fantasy"] = "Историческое фэнтези";
    genreMap["modern_tale"] = "Современная сказка";
    genreMap["russian_fantasy"] = "Русское фэнтези";
    genreMap["sf_fantasy"] = "Фэнтези";
    genreMap["sf_fantasy_city"] = "Городское фэнтези";
    genreMap["sf_mystic"] = "Мистика";
    genreMap["sf_stimpank"] = "Стимпанк";
    genreMap["sf_technofantasy"] = "Технофэнтези";
    genreMap["antique_myths"] = "Мифы";
    genreMap["child_folklore"] = "Детский фольклор";
    genreMap["epic"] = "Былины";
    genreMap["folk_songs"] = "Народные песни";
    genreMap["folk_tale"] = "Народные сказки";
    genreMap["folklore"] = "Фольклор";
    genreMap["limerick"] = "Частушки";
    genreMap["proverbs"] = "Пословицы";
    genreMap["foreign_action"] = "Боевик";
    genreMap["foreign_adventure"] = "Приключения";
    genreMap["foreign_business"] = "Бизнес";
    genreMap["foreign_comp"] = "Компьютеры";
    genreMap["foreign_contemporary"] = "Современное";
    genreMap["foreign_contemporary_lit"] = "Современная литература";
    genreMap["foreign_desc"] = "Описания";
    genreMap["foreign_detective"] = "Детектив";
    genreMap["foreign_edu"] = "Образование";
    genreMap["foreign_fantasy"] = "Фэнтези";
    genreMap["foreign_home"] = "Дом";
    genreMap["foreign_humor"] = "Юмор";
    genreMap["foreign_language"] = "Языкознание";
    genreMap["foreign_love"] = "Любовное";
    genreMap["foreign_novel"] = "Новеллы";
    genreMap["foreign_other"] = "Другое";
    genreMap["foreign_psychology"] = "Психология";
    genreMap["foreign_publicism"] = "Публицистика";
    genreMap["foreign_sf"] = "Научная фантастика";
    genreMap["geo_guides"] = "Справочники";
    genreMap["geography_book"] = "География";
    genreMap["family"] = "Семейные отношения";
    genreMap["home"] = "Дом";
    genreMap["home_collecting"] = "Коллекционирование";
    genreMap["home_cooking"] = "Кулинария";
    genreMap["home_crafts"] = "Увлечения";
    genreMap["home_diy"] = "Сделай сам";
    genreMap["home_entertain"] = "Развлечения";
    genreMap["home_garden"] = "Сад";
    genreMap["home_health"] = "Здоровье";
    genreMap["home_pets"] = "Домашние животные";
    genreMap["home_sex"] = "Секс";
    genreMap["home_sport"] = "Спорт";
    genreMap["sci_pedagogy"] = "Педагогика";
    genreMap["humor"] = "Юмор";
    genreMap["humor_anecdote"] = "Анекдоты";
    genreMap["humor_fantasy"] = "Фэнтези";
    genreMap["humor_prose"] = "Юмористическая проза";
    genreMap["humor_satire"] = "Сатира";
    genreMap["love"] = "Любовные романы";
    genreMap["love_contemporary"] = "Современные любовные романы";
    genreMap["love_detective"] = "Остросюжетные любовные романы";
    genreMap["love_erotica"] = "Эротика";
    genreMap["love_fantasy"] = "Любовное фэнтези";
    genreMap["love_hard"] = "Порно";
    genreMap["love_history"] = "Любовные исторические романы";
    genreMap["love_sf"] = "Любовно-фантастические романы";
    genreMap["love_short"] = "Короткое";
    genreMap["military_special"] = "Военное дело";
    genreMap["nonf_biography"] = "Биографии и Мемуары";
    genreMap["nonf_military"] = "Военная документалистика и аналитика";
    genreMap["nonf_publicism"] = "Публицистика";
    genreMap["nonfiction"] = "Художественная литература";
    genreMap["travel_notes"] = "Путевые заметки";
    genreMap["aphorism_quote"] = "Афоризмы";
    genreMap["auto_regulations"] = "Автомобили";
    genreMap["beginning_authors"] = "Начинающие авторы";
    genreMap["comics"] = "Комиксы";
    genreMap["essays"] = "Эссе";
    genreMap["fanfiction"] = "Фанфик";
    genreMap["industries"] = "Промышленность";
    genreMap["job_hunting"] = "Поиск работы";
    genreMap["magician_book"] = "Магия";
    genreMap["management"] = "Менеджмент";
    genreMap["narrative"] = "Повествовательное";
    genreMap["network_literature"] = "Самиздат";
    genreMap["newspapers"] = "Газеты";
    genreMap["other"] = "Неотсортированное";
    genreMap["paper_work"] = "Бумажная работа";
    genreMap["pedagogy_book"] = "Педагогика";
    genreMap["periodic"] = "Периодические издания";
    genreMap["russian_contemporary"] = "Современная российская литература";
    genreMap["short_story"] = "Короткие истории";
    genreMap["sketch"] = "Скетч";
    genreMap["unfinished"] = "Незавершенное";
    genreMap["unrecognised"] = "Неизвестный";
    genreMap["upbringing_book"] = "Воспитание";
    genreMap["vampire_book"] = "Вампиры";
    genreMap["foreign_poetry"] = "Поэзия";
    genreMap["humor_verse"] = "Стихи";
    genreMap["lyrics"] = "Лирика";
    genreMap["palindromes"] = "Визуальная и экспериментальная поэзия";
    genreMap["poem"] = "Поэма";
    genreMap["poetry"] = "Поэзия";
    genreMap["poetry_classical"] = "Классическая поэзия";
    genreMap["poetry_east"] = "Поэзия Востока";
    genreMap["poetry_for_classical"] = "Классическая зарубежная поэзия";
    genreMap["poetry_for_modern"] = "Современная зарубежная поэзия";
    genreMap["poetry_modern"] = "Современная поэзия";
    genreMap["poetry_rus_classical"] = "Классическая русская поэзия";
    genreMap["poetry_rus_modern"] = "Современная русская поэзия";
    genreMap["song_poetry"] = "Песенная поэзия";
    genreMap["aphorisms"] = "Афоризмы";
    genreMap["epistolary_fiction"] = "Эпистолярная проза";
    genreMap["foreign_antique"] = "Средневековая классическая проза";
    genreMap["foreign_prose"] = "Зарубежная классическая проза";
    genreMap["gothic_novel"] = "Готический роман";
    genreMap["great_story"] = "Роман";
    genreMap["literature_18"] = "Классическая проза XVII-XVIII веков";
    genreMap["literature_19"] = "Классическая проза ХIX века";
    genreMap["literature_20"] = "Классическая проза ХX века";
    genreMap["prose"] = "Проза";
    genreMap["prose_abs"] = "Фантасмагория";
    genreMap["prose_classic"] = "Классика";
    genreMap["prose_contemporary"] = "Современная проза";
    genreMap["prose_counter"] = "Контр-проза";
    genreMap["prose_history"] = "История";
    genreMap["prose_magic"] = "Магический реализм";
    genreMap["prose_military"] = "Военная проза";
    genreMap["prose_neformatny"] = "Экспериментальная";
    genreMap["prose_rus_classic"] = "Русская классика";
    genreMap["prose_su_classics"] = "Советская классика";
    genreMap["story"] = "Малые литературные формы";
    genreMap["psy_alassic"] = "Психология";
    genreMap["psy_childs"] = "Дети";
    genreMap["psy_generic"] = "Общее";
    genreMap["psy_personal"] = "Личное";
    genreMap["psy_sex_and_family"] = "Секс и семья";
    genreMap["psy_social"] = "Социальное";
    genreMap["psy_theraphy"] = "Терапия";
    genreMap["ref_dict"] = "Словари";
    genreMap["ref_encyc"] = "Энциклопедии";
    genreMap["ref_guide"] = "Инструкции";
    genreMap["ref_ref"] = "Справочники";
    genreMap["reference"] = "Справочники";
    genreMap["astrology"] = "Астрология и хиромантия";
    genreMap["foreign_religion"] = "Иностранная религиозная литература";
    genreMap["religion"] = "Религия";
    genreMap["religion_budda"] = "Буддизм";
    genreMap["religion_catholicism"] = "Католицизм";
    genreMap["religion_christianity"] = "Христианство";
    genreMap["religion_esoterics"] = "Эзотерика";
    genreMap["religion_hinduism"] = "Индуизм";
    genreMap["religion_islam"] = "Islam";
    genreMap["religion_judaism"] = "Иудаизм";
    genreMap["religion_orthodoxy"] = "Православие";
    genreMap["religion_paganism"] = "Язычество";
    genreMap["religion_protestantism"] = "Протестантизм";
    genreMap["religion_rel"] = "Отношения";
    genreMap["religion_self"] = "Самопознание";
    genreMap["military_history"] = "Военная история";
    genreMap["sci_biology"] = "Биология";
    genreMap["sci_botany"] = "Ботаника";
    genreMap["sci_chem"] = "Химия";
    genreMap["sci_cosmos"] = "Астрономия и Космос";
    genreMap["sci_ecology"] = "Экология";
    genreMap["sci_economy"] = "Экономика";
    genreMap["sci_geo"] = "Геология и география";
    genreMap["sci_history"] = "История";
    genreMap["sci_juris"] = "Юриспруденция";
    genreMap["sci_linguistic"] = "Лингвистика";
    genreMap["sci_math"] = "Математика";
    genreMap["sci_medicine"] = "Медицина";
    genreMap["sci_medicine_alternative"] = "Альтернативная (не)медицина";
    genreMap["sci_oriental"] = "Востоковедение";
    genreMap["sci_philology"] = "Литературоведение";
    genreMap["sci_philosophy"] = "Философия";
    genreMap["sci_phys"] = "Физика";
    genreMap["sci_politics"] = "Политика";
    genreMap["sci_popular"] = "Научно-популярная литература";
    genreMap["sci_psychology"] = "Психология и психотерапия";
    genreMap["sci_religion"] = "Религия";
    genreMap["sci_social_studies"] = "Обществознание";
    genreMap["sci_state"] = "Государство и право";
    genreMap["sci_theories"] = "Альтернативные (не)науки и (не)научные теории";
    genreMap["sci_veterinary"] = "Ветеринария";
    genreMap["sci_zoo"] = "Зоология";
    genreMap["science"] = "Наука";
    genreMap["sociology_book"] = "Социология";
    genreMap["hronoopera"] = "Хроноопера";
    genreMap["popadancy"] = "Уе… попаданцы(1)";
    genreMap["popadanec"] = "Уе… попаданцы(2)";
    genreMap["sf"] = "Научная фантастика";
    genreMap["sf_action"] = "Боевая фантастика";
    genreMap["sf_cyberpunk"] = "Киберпанк";
    genreMap["sf_detective"] = "Детектив";
    genreMap["sf_epic"] = "Эпическая фантастика";
    genreMap["sf_etc"] = "Фантастика";
    genreMap["sf_heroic"] = "Героическое";
    genreMap["sf_history"] = "История";
    genreMap["sf_horror"] = "Ужас";
    genreMap["sf_humor"] = "Юмор";
    genreMap["sf_litrpg"] = "ЛитРПГ";
    genreMap["sf_postapocalyptic"] = "Постапокалипсис";
    genreMap["sf_social"] = "Социально-психологическая фантастика";
    genreMap["sf_space"] = "Космос";
    genreMap["sci_textbook"] = "Учебники и пособия";
    genreMap["tbg_higher"] = "Учебники и пособия ВУЗов";
    genreMap["tbg_school"] = "Школьные учебники и пособия";
    genreMap["tbg_secondary"] = "Учебники и пособия для среднего и специального образования";

    genreMap["vers_libre"] = "Верлибры";
    genreMap["trade"] = "Торговля";
    genreMap["sci_crib"] = "Шпаргалки";
    genreMap["sci_biophys"] = "Биофизика";
    genreMap["sci_biochem"] = "Биохимия";
    genreMap["scenarios"] = "Сценарии";
    genreMap["roman"] = "Романы";
    genreMap["riddles"] = "Фольклор";
    genreMap["military_arts"] = "Боевые исскуства";
    genreMap["military_all"] = "Тактика и стратеги";
    genreMap["military"] = "Организация и тактика боевых действий";
    genreMap["Islam"] = "Ислам религия";
    genreMap["islam"] = "Ислам религия";
    genreMap["in_verse"] = "Трагедии";
    genreMap["fantasy_alt_hist"] = "Фентези альтернативная история";
    genreMap["fable"] = "Байки";
    genreMap["Extravaganza"] = "Феерия";
    genreMap["essay"] = "Эссе";
    genreMap["dissident"] = "Диссидентская литература";
    genreMap["det_cozy"] = "Дамский роман";
    genreMap["det_all"] = "Дамский роман";
    genreMap["comp_all"] = "Компьютерная литература";
    genreMap["sagas"] = "Саги";
    genreMap["palmistry"] = "Хиромантия";
    genreMap["mystery"] = "Тайны";
    genreMap["Ref_all"] = "Всё о бо всём";
    genreMap["Sci_business"] = "О бизнесе";
    genreMap["Adv_all"] = "Детская литература";
    genreMap["Nonf_all"] = "О бо всём";

    // Русские жанры
    genreMap["rus_classic"] = "Русская классика";
    genreMap["sf_russian"] = "Русская фантастика";
    genreMap["fantasy_russian"] = "Русское фэнтези";
}
