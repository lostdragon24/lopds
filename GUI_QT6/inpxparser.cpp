#include "inpxparser.h"
#include <QFile>
#include <QFileInfo>
#include <QDir>
#include <QSqlQuery>
#include <QSqlError>
#include <QDebug>
#include <QTextStream>
#include <QRegularExpression>
#include <archive.h>
#include <archive_entry.h>

#ifdef QT_DEBUG
#define DEBUG_LOG qDebug
#else
#define DEBUG_LOG if(false) qDebug
#endif

InpxParser::InpxParser(QSqlDatabase database)
    : m_insertedCount(0)
    , m_skippedCount(0)
    , m_updateCount(0)
    , m_database(database)
{
}

void InpxParser::setProgressCallback(std::function<void(int, const QString&)> callback)
{
    m_progressCallback = callback;
}

void InpxParser::getInpxFields(const QString &structureInfo, ImportContext &ctx)
{
    QString structure = structureInfo.isEmpty() ?
                            "AUTHOR;GENRE;TITLE;SERIES;SERNO;FILE;SIZE;LIBID;DEL;EXT;DATE;LANG;KEYWORDS" :
                            structureInfo;

    QStringList fieldsList = structure.split(';');
    ctx.fields.clear();

    QHash<QString, FieldType> fieldMap = {
        {"AUTHOR", FieldAuthor},
        {"GENRE", FieldGenre},
        {"TITLE", FieldTitle},
        {"SERIES", FieldSeries},
        {"SERNO", FieldSeriesNumber},
        {"FILE", FieldFile},
        {"SIZE", FieldSize},
        {"EXT", FieldExt},
        {"LANG", FieldLang},
        {"DATE", FieldDate}
    };

    for (const QString &field : fieldsList) {
        ctx.fields.append(fieldMap.value(field, FieldNone));
    }
}

bool InpxParser::parseInpData(const QString &line, const ImportContext &ctx, InpxBookMeta &meta)
{
    // Разделяем строку по символу \x04 как в C коде
    QStringList fields;
    int start = 0;
    int end = 0;

    for (int i = 0; i < line.length(); ++i) {
        if (line[i] == QChar(0x04) || i == line.length() - 1) {
            end = (i == line.length() - 1) ? i + 1 : i;
            QString field = line.mid(start, end - start).trimmed();
            fields.append(field);
            start = i + 1;
        }
    }

    if (fields.size() < ctx.fields.size()) {
        qDebug() << "Not enough fields in line:" << fields.size() << "expected:" << ctx.fields.size();
        return false;
    }

    qDebug() << "Parsing line with" << fields.size() << "fields";

    for (int i = 0; i < qMin(fields.size(), ctx.fields.size()); ++i) {
        const QString &fieldValue = fields[i].trimmed();
        if (fieldValue.isEmpty()) continue;

        switch (ctx.fields[i]) {
        case FieldAuthor:
            if (meta.author.isEmpty()) {
                meta.author = cleanAuthorName(fieldValue);
                qDebug() << "Parsed author:" << meta.author;
            }
            break;

        case FieldTitle:
            if (meta.title.isEmpty()) {
                meta.title = fieldValue;
                qDebug() << "Parsed title:" << meta.title;
            }
            break;

        case FieldSeries:
            if (meta.series.isEmpty()) {
                // Убираем часть в скобках
                int bracketPos = fieldValue.indexOf('(');
                if (bracketPos > 0) {
                    meta.series = fieldValue.left(bracketPos).trimmed();
                } else {
                    meta.series = fieldValue;
                }
                qDebug() << "Parsed series:" << meta.series;
            }
            break;

        case FieldSeriesNumber:
            if (meta.seriesNumber == 0) {
                bool ok;
                int num = fieldValue.toInt(&ok);
                if (ok && num > 0) {
                    meta.seriesNumber = num;
                    qDebug() << "Parsed series number:" << meta.seriesNumber;
                }
            }
            break;

        case FieldGenre:
            if (meta.genre.isEmpty()) {
                // Берем часть до первого двоеточия
                int colonPos = fieldValue.indexOf(':');
                if (colonPos > 0) {
                    meta.genre = fieldValue.left(colonPos).trimmed();
                } else {
                    meta.genre = fieldValue;
                }
                qDebug() << "Parsed genre:" << meta.genre;
            }
            break;

        case FieldFile:
            meta.fileName = fieldValue;
            qDebug() << "Parsed file name:" << meta.fileName;
            break;

        case FieldSize:
            meta.fileSize = fieldValue.toLongLong();
            qDebug() << "Parsed file size:" << meta.fileSize;
            break;

        case FieldExt:
            meta.fileExt = fieldValue;
            qDebug() << "Parsed file extension:" << meta.fileExt;
            break;

        case FieldLang:
            meta.language = fieldValue;
            qDebug() << "Parsed language:" << meta.language;
            break;

        case FieldDate:
            if (fieldValue.length() >= 4) {
                meta.year = fieldValue.left(4).toInt();
                qDebug() << "Parsed year:" << meta.year;
            }
            break;

        default:
            break;
        }
    }

    // Проверяем обязательные поля
    bool hasRequiredFields = !meta.title.isEmpty() && !meta.author.isEmpty() && !meta.fileName.isEmpty();

    if (hasRequiredFields) {
        qDebug() << "Successfully parsed book:" << meta.title << "by" << meta.author;
    } else {
        qDebug() << "Missing required fields - title:" << meta.title
                 << "author:" << meta.author << "file:" << meta.fileName;
    }

    return hasRequiredFields;
}


QString InpxParser::cleanAuthorName(const QString &authorStr)
{
    QStringList parts = authorStr.split(':');
    for (QString &part : parts) {
        part = part.replace(',', ' ').trimmed();
    }

    if (parts.size() >= 3) {
        return QString("%1 %2 %3").arg(parts[0], parts[1], parts[2]);
    } else if (parts.size() == 2) {
        return QString("%1 %2").arg(parts[0], parts[1]);
    }
    return parts[0];
}

QByteArray InpxParser::readFileFromArchive(const QString &archivePath, const QString &internalPath)
{
    QByteArray content;
    struct archive *a = archive_read_new();
    archive_read_support_format_zip(a);
    archive_read_support_filter_all(a);

    int r = archive_read_open_filename(a, archivePath.toLocal8Bit().constData(), 10240);
    if (r != ARCHIVE_OK) {
        archive_read_free(a);
        return content;
    }

    struct archive_entry *entry;
    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        const char* entry_path = archive_entry_pathname(entry);
        if (entry_path && QString::fromUtf8(entry_path) == internalPath) {
            la_int64_t size = archive_entry_size(entry);
            if (size > 0) {
                content.resize(size);
                archive_read_data(a, content.data(), size);
            }
            break;
        }
        archive_read_data_skip(a);
    }

    archive_read_close(a);
    archive_read_free(a);
    return content;
}

bool InpxParser::importInpxCollection(const QString &inpxFilePath, const QString &booksDir)
{
    // Базовая реализация
    QFileInfo inpxInfo(inpxFilePath);
    if (!inpxInfo.exists()) {
        return false;
    }

    ImportContext ctx;
    getInpxFields("AUTHOR;GENRE;TITLE;SERIES;SERNO;FILE;SIZE;LIBID;DEL;EXT;DATE;LANG;KEYWORDS", ctx);

    QByteArray content = readFileFromArchive(inpxFilePath, "collection.inp");
    if (content.isEmpty()) {
        return false;
    }

    QString data = QString::fromUtf8(content);
    QStringList lines = data.split(QRegularExpression("[\r\n]"), Qt::SkipEmptyParts);

    for (const QString &line : lines) {
        InpxBookMeta meta;
        if (parseInpData(line, ctx, meta)) {
            insertBookToDatabase(meta, booksDir);
        }
    }

    return true;
}

bool InpxParser::insertBookToDatabase(const InpxBookMeta &meta, const QString &booksDir)
{
    QSqlQuery query(m_database);
    query.prepare(
        "INSERT OR IGNORE INTO books (title, author, series, series_number, genre, language, year, file_size) "
        "VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

    query.addBindValue(meta.title);
    query.addBindValue(meta.author);
    query.addBindValue(meta.series);
    query.addBindValue(meta.seriesNumber);
    query.addBindValue(meta.genre);
    query.addBindValue(meta.language);
    query.addBindValue(meta.year);
    query.addBindValue(meta.fileSize);

    return query.exec();
}

bool InpxParser::importInpxCollectionOptimized(const QString &inpxFilePath, const QString &booksDir,
                                               QSqlQuery &insertQuery, QSet<QString> &existingBooksCache)
{
    m_existingBooksCache = existingBooksCache;
    m_insertedCount = 0;
    m_skippedCount = 0;
    m_updateCount = 0;

    QFileInfo inpxInfo(inpxFilePath);
    if (!inpxInfo.exists()) {
        qDebug() << "INPX file does not exist:" << inpxFilePath;
        return false;
    }

    qDebug() << "Opening INPX archive:" << inpxFilePath;

    // Открываем INPX как архив
    struct archive *a = archive_read_new();
    archive_read_support_format_zip(a);
    archive_read_support_filter_all(a);

    int r = archive_read_open_filename(a, inpxFilePath.toLocal8Bit().constData(), 10240);
    if (r != ARCHIVE_OK) {
        qDebug() << "Failed to open INPX archive:" << archive_error_string(a);
        archive_read_free(a);
        return false;
    }

    // Ищем ВСЕ файлы в архиве, не только .inp
    QVector<QString> allFiles;
    struct archive_entry *entry;

    qDebug() << "Listing archive contents...";

    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        const char *filename = archive_entry_pathname(entry);
        if (filename) {
            QString qfilename = QString::fromUtf8(filename);
            allFiles.append(qfilename);
            qDebug() << "Found file in archive:" << qfilename;
        }
        archive_read_data_skip(a);
    }

    qDebug() << "Total files in archive:" << allFiles.size();

    // Ищем INP файлы (могут быть с разными расширениями)
    QVector<QString> inpFiles;
    for (const QString &file : allFiles) {
        QString lowerFile = file.toLower();
        if (lowerFile.endsWith(".inp") || lowerFile.endsWith(".txt") ||
            lowerFile.contains("collection") || lowerFile.contains("lib")) {
            inpFiles.append(file);
            qDebug() << "Potential data file:" << file;
        }
    }

    archive_read_close(a);
    archive_read_free(a);

    if (inpFiles.isEmpty()) {
        qDebug() << "No data files found in INPX archive";

        // Пробуем другой подход - читаем первый попавшийся файл
        struct archive *a2 = archive_read_new();
        archive_read_support_format_zip(a2);
        archive_read_open_filename(a2, inpxFilePath.toLocal8Bit().constData(), 10240);

        while (archive_read_next_header(a2, &entry) == ARCHIVE_OK) {
            const char *filename = archive_entry_pathname(entry);
            if (filename) {
                QString qfilename = QString::fromUtf8(filename);
                // Пробуем прочитать любой текстовый файл
                la_int64_t size = archive_entry_size(entry);
                if (size > 1000 && size < 100 * 1024 * 1024) {
                    qDebug() << "Trying to read file as data source:" << qfilename;
                    inpFiles.append(qfilename);
                    break;
                }
            }
            archive_read_data_skip(a2);
        }
        archive_read_close(a2);
        archive_read_free(a2);
    }

    if (inpFiles.isEmpty()) {
        qDebug() << "Still no data files found, cannot import";
        return false;
    }

    qDebug() << "Found" << inpFiles.size() << "data file(s) to process";

    // Импортируем файлы
    ImportContext ctx;
    getInpxFields("AUTHOR;GENRE;TITLE;SERIES;SERNO;FILE;SIZE;LIBID;DEL;EXT;DATE;LANG;KEYWORDS", ctx);

    for (int i = 0; i < inpFiles.size() && (!m_abort || !*m_abort); ++i) {
        if (m_progressCallback) {
            m_progressCallback(10 + (i * 80 / inpFiles.size()),
                               QString("Обработка: %1").arg(inpFiles[i]));
        }

        qDebug() << "Processing file:" << inpFiles[i];
        processInpFile(inpFiles[i], inpxFilePath, booksDir, ctx, insertQuery);
    }

    qDebug() << "INPX import completed - Inserted:" << m_insertedCount
             << "Skipped:" << m_skippedCount << "Updated:" << m_updateCount;

    return m_insertedCount > 0;
}

void InpxParser::processInpFile(const QString &inpFilePath, const QString &archivePath,
                                const QString &booksDir, const ImportContext &ctx,
                                QSqlQuery &insertQuery)
{
    qDebug() << "Reading file from archive:" << inpFilePath;

    QByteArray fileContent = readFileFromArchive(archivePath, inpFilePath);
    if (fileContent.isEmpty()) {
        qDebug() << "Failed to read file:" << inpFilePath;
        return;
    }

    qDebug() << "Read" << fileContent.size() << "bytes from file";

    // Пробуем кодировки
    QString content = QString::fromUtf8(fileContent);
    if (content.contains(QChar::ReplacementCharacter)) {
        qDebug() << "UTF-8 failed, trying local8Bit";
        content = QString::fromLocal8Bit(fileContent);
    }

    QStringList lines = content.split(QRegularExpression("[\r\n]"), Qt::SkipEmptyParts);
    qDebug() << "Total lines:" << lines.size();

    // ========== ФОРМИРУЕМ ИМЯ АРХИВА ИЗ ИМЕНИ INP ФАЙЛА ==========
    // inpFilePath содержит имя файла внутри INPX архива, например "fb2-000007-788888_lost.inp"
    QString archiveBaseName = QFileInfo(inpFilePath).completeBaseName();

    QString archiveFullPath = booksDir + "/" + archiveBaseName + ".zip";
    qDebug() << "Archive name from INP file:" << inpFilePath << "->" << archiveFullPath;

    int lineCount = 0;
    for (const QString &line : lines) {
        QString trimmedLine = line.trimmed();
        if (trimmedLine.isEmpty() || trimmedLine.length() < 10) continue;

        InpxBookMeta meta;
        if (!parseInpData(trimmedLine, ctx, meta)) {
            continue;
        }

        lineCount++;

        // Быстрая проверка дубликата через кэш
        QString uniqueKey = meta.title + "|" + meta.author;
        if (m_existingBooksCache.contains(uniqueKey)) {
            m_skippedCount++;
            continue;
        }

        // ========== ФОРМИРУЕМ ВНУТРЕННИЙ ПУТЬ ==========
        QString internalPath;
        if (!meta.fileName.isEmpty()) {
            if (!meta.fileExt.isEmpty()) {
                internalPath = meta.fileName + "." + meta.fileExt;
            } else {
                internalPath = meta.fileName + ".fb2";
            }
        } else {
            // Если нет имени файла, используем заголовок
            internalPath = meta.title + ".fb2";
        }

        // Очищаем от недопустимых символов
        // internalPath.replace('\\', '_');
        // internalPath.replace('/', '_');
        // internalPath.replace(':', '_');
        // internalPath.replace('*', '_');
        // internalPath.replace('?', '_');
        // internalPath.replace('"', '_');
        // internalPath.replace('<', '_');
        // internalPath.replace('>', '_');
        // internalPath.replace('|', '_');

        QString displayPath = archiveFullPath + "/" + internalPath;

        qDebug() << "Adding book:" << meta.title << "by" << meta.author;
        qDebug() << "  archiveFullPath:" << archiveFullPath;
        qDebug() << "  internalPath:" << internalPath;

        // ========== ПРАВИЛЬНЫЙ ПОРЯДОК ПОЛЕЙ ==========
        insertQuery.addBindValue(displayPath);           // file_path
        insertQuery.addBindValue(internalPath);          // file_name
        insertQuery.addBindValue(meta.fileSize);         // file_size
        insertQuery.addBindValue(meta.fileExt.isEmpty() ? "fb2" : meta.fileExt);  // file_type
        insertQuery.addBindValue(meta.title);            // title
        insertQuery.addBindValue(meta.author);           // author
        insertQuery.addBindValue(meta.series);           // series
        insertQuery.addBindValue(meta.seriesNumber);     // series_number
        insertQuery.addBindValue(meta.genre);            // genre
        insertQuery.addBindValue(meta.language);         // language
        insertQuery.addBindValue(meta.year);             // year
        insertQuery.addBindValue(archiveFullPath);       // archive_path
        insertQuery.addBindValue(internalPath);          // archive_internal_path

        if (insertQuery.exec()) {
            m_insertedCount++;
            m_existingBooksCache.insert(uniqueKey);

            if (m_insertedCount % 100 == 0) {
                qDebug() << "Inserted" << m_insertedCount << "books...";
                if (m_progressCallback) {
                    m_progressCallback(5, QString("Импортировано %1 книг").arg(m_insertedCount));
                }
            }
        } else {
            qDebug() << "Insert failed:" << insertQuery.lastError().text();
            qDebug() << "  Error details:" << insertQuery.lastError().driverText();
        }
    }

    qDebug() << "Processed" << lineCount << "books from file, inserted:" << m_insertedCount;
}
