#include "scannerdialog.h"
#include "ui_scannerdialog.h"
#include "bookparser.h"
#include "archivehandler.h"
#include "inpxparser.h"
#include <QFileDialog>
#include <QMessageBox>
#include <QDir>
#include <QSettings>
#include <QSqlQuery>
#include <QSqlError>
#include <QDebug>
#include <QFileInfo>
#include <QTimer>
#include <QDirIterator>
#include <QCryptographicHash>
#include <QTemporaryFile>
#include <QElapsedTimer>
#include <QtConcurrent>
#include <QCryptographicHash>

// Отключаем отладочный вывод в release
#ifdef QT_DEBUG
#define DEBUG_LOG qDebug
#else
#define DEBUG_LOG if(false) qDebug
#endif

// BookScanner implementation
BookScanner::BookScanner(QSqlDatabase database, const QString &booksDir, bool useInpx, const QString &inpxFile)
    : m_database(database)
    , m_booksDir(booksDir)
    , m_useInpx(useInpx)
    , m_inpxFile(inpxFile)
    , m_abort(false)
    , m_parser(new BookParser())
    , m_archiveHandler(new ArchiveHandler())
{
    m_batchSize = 100;
    qDebug() << "BookScanner created with dir:" << booksDir << "useInpx:" << useInpx << "inpxFile:" << inpxFile;
}

BookScanner::~BookScanner()
{
    delete m_parser;
    delete m_archiveHandler;
}

void BookScanner::cancelScanning()
{
    m_abort = true;
    flushBatch();  // сохранить то, что накопилось
    qDebug() << "Scanning cancellation requested";
}

void BookScanner::saveBookMetadata(int bookId, const BookMeta& meta, const QString& filePath, const QString& archivePath, const QString& internalPath)
{
    // Извлекаем описание и обложку из книги
    QByteArray content;

    if (!archivePath.isEmpty() && !internalPath.isEmpty()) {
        ArchiveHandler handler;
        if (handler.openArchive(archivePath)) {
            content = handler.readFile(internalPath);
            handler.closeArchive();
        }
    } else if (!filePath.isEmpty()) {
        QFile file(filePath);
        if (file.open(QIODevice::ReadOnly)) {
            content = file.readAll();
            file.close();
        }
    }

    if (content.isEmpty()) return;

    // Парсим описание
    BookParser parser;
    BookMeta fullMeta = parser.parseMetadataFromMemory(content, QFileInfo(filePath).suffix());

    if (!fullMeta.description.isEmpty()) {
        // Сохраняем описание в БД
        QSqlQuery updateQuery(m_database);
        updateQuery.prepare("UPDATE books SET description = ? WHERE id = ?");
        updateQuery.addBindValue(fullMeta.description);
        updateQuery.addBindValue(bookId);
        updateQuery.exec();
        qDebug() << "Saved description for book ID:" << bookId;
    }

    // Обложку пока не сохраняем - она требует отдельной обработки

}


void BookScanner::forceRescanAllArchives()
{
    QSqlQuery query(m_database);
    if (query.exec("UPDATE archives SET needs_rescan = 1")) {
        qDebug() << "All archives marked for rescan";
    } else {
        qDebug() << "Failed to mark archives for rescan:" << query.lastError().text();
    }
}

bool BookScanner::shouldRescanArchive(const QString &archivePath)
{
    QSqlQuery query(m_database);
    query.prepare("SELECT archive_hash, last_modified, needs_rescan FROM archives WHERE archive_path = ?");
    query.addBindValue(archivePath);

    if (!query.exec() || !query.next()) {
        // Архив не найден в базе - нужно сканировать
        qDebug() << "Archive not in database, will scan:" << archivePath;
        return true;
    }

    // Получаем текущую информацию об архиве
    ArchiveInfo currentInfo = m_archiveHandler->getArchiveInfo(archivePath);
    QString currentHash = QString::fromUtf8(currentInfo.hash);
    QString storedHash = query.value(0).toString();
    qint64 storedLastModified = query.value(1).toLongLong();
    bool needsRescan = query.value(2).toBool();

    qDebug() << "Archive check - Current hash:" << currentHash << "Stored hash:" << storedHash;
    qDebug() << "Current modified:" << currentInfo.lastModified << "Stored modified:" << storedLastModified;

    // Проверяем изменения
    if (needsRescan) {
        qDebug() << "Archive marked for rescan:" << archivePath;
        return true;
    }

    if (currentHash != storedHash) {
        qDebug() << "Archive hash changed, rescanning:" << archivePath;
        return true;
    }

    if (currentInfo.lastModified != storedLastModified) {
        qDebug() << "Archive modified, rescanning:" << archivePath;
        return true;
    }

    qDebug() << "Archive unchanged, skipping:" << archivePath;
    return false;
}


void BookScanner::updateArchiveInfo(const QString &archivePath, bool needsRescan)
{
    ArchiveInfo info = m_archiveHandler->getArchiveInfo(archivePath);

    QSqlQuery query(m_database);

    // Используем INSERT OR REPLACE для SQLite и ON DUPLICATE KEY UPDATE для MySQL
    if (m_database.driverName().contains("SQLITE")) {
        query.prepare(
            "INSERT OR REPLACE INTO archives (archive_path, archive_hash, file_count, total_size, last_modified, last_scanned, needs_rescan) "
            "VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)"
        );
    } else {
        query.prepare(
            "INSERT INTO archives (archive_path, archive_hash, file_count, total_size, last_modified, last_scanned, needs_rescan) "
            "VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?) "
            "ON DUPLICATE KEY UPDATE archive_hash = VALUES(archive_hash), file_count = VALUES(file_count), "
            "total_size = VALUES(total_size), last_modified = VALUES(last_modified), last_scanned = VALUES(last_scanned), needs_rescan = VALUES(needs_rescan)"
        );
    }

    query.addBindValue(info.path);
    query.addBindValue(QString::fromUtf8(info.hash));
    query.addBindValue(info.fileCount);
    query.addBindValue(info.size);
    query.addBindValue(info.lastModified);
    query.addBindValue(needsRescan);

    if (!query.exec()) {
        qDebug() << "Failed to update archive info:" << query.lastError().text();
    } else {
        qDebug() << "Archive info updated:" << archivePath << "files:" << info.fileCount << "size:" << info.size << "hash:" << QString::fromUtf8(info.hash).left(16) + "...";
    }
}

void BookScanner::startScanning()
{
    m_abort = false;

    emit statusChanged("Подготовка к сканированию...");
    emit progressChanged(0);

    try {
        // INPX импорт (если включен) - транзакция внутри importInpx
        if (m_useInpx && !m_inpxFile.isEmpty()) {
            emit statusChanged("Импорт INPX...");
            bool importResult = importInpx(m_inpxFile);
            if (importResult) {
                emit progressChanged(100);
                emit finished();
                return;
            }
        }

        // Сканирование директории - здесь своя транзакция
        if (!m_database.transaction()) {
            emit errorOccurred("Failed to start transaction for directory scan");
            return;
        }

        emit statusChanged("Сканирование файлов...");
        scanDirectory(m_booksDir);
        flushBatch();

        if (!m_abort) {
            if (m_database.commit()) {
                emit progressChanged(100);
                emit statusChanged("Сканирование завершено");
                emit finished();
            } else {
                emit errorOccurred("Failed to commit transaction");
            }
        } else {
            m_database.rollback();
        }

    } catch (const std::exception &e) {
        m_database.rollback();
        if (!m_abort) {
            emit errorOccurred(QString("Ошибка: %1").arg(e.what()));
        }
    }
}

bool BookScanner::importInpx(const QString &inpxFile)
{
    DEBUG_LOG() << "=== START INPX IMPORT ===";
    DEBUG_LOG() << "INPX file:" << inpxFile;

    QFileInfo inpxInfo(inpxFile);
    if (!inpxInfo.exists()) {
        emit errorOccurred("INPX файл не существует: " + inpxFile);
        return false;
    }

    // ========== ПРОВЕРЯЕМ, ОТКРЫТА ЛИ БД ==========
    if (!m_database.isOpen()) {
        emit errorOccurred("База данных не открыта");
        return false;
    }

    // ========== ОПТИМИЗАЦИЯ 1: Настройка БД перед массовой вставкой ==========

    // Для SQLite - оптимизируем настройки
    if (m_database.driverName().contains("SQLITE")) {
        QSqlQuery pragma(m_database);
        pragma.exec("PRAGMA synchronous = OFF");           // Отключаем синхронную запись
        pragma.exec("PRAGMA journal_mode = MEMORY");       // Журнал в памяти
        pragma.exec("PRAGMA cache_size = 100000");         // Увеличиваем кэш до 100MB
        pragma.exec("PRAGMA temp_store = MEMORY");         // Временные таблицы в памяти
        pragma.exec("PRAGMA locking_mode = EXCLUSIVE");    // Эксклюзивная блокировка
        DEBUG_LOG() << "SQLite optimized for bulk insert";
    }

    // Для MySQL - отключаем проверки
    if (m_database.driverName().contains("MYSQL")) {
        QSqlQuery mysqlOpt(m_database);
        mysqlOpt.exec("SET autocommit=0");
        mysqlOpt.exec("SET unique_checks=0");
        mysqlOpt.exec("SET foreign_key_checks=0");
        mysqlOpt.exec("SET sql_log_bin=0");
        mysqlOpt.exec("SET innodb_flush_log_at_trx_commit=2");
        DEBUG_LOG() << "MySQL optimized for bulk insert";
    }

    // ========== ОПТИМИЗАЦИЯ 2: Проверяем, не в транзакции ли уже ==========
    // В SQLite нет прямого способа проверить, поэтому просто начинаем новую
    // Если предыдущая транзакция не закрыта, сначала её закроем

    // Сначала откатываем любую незавершенную транзакцию
    if (!m_database.rollback()) {
        DEBUG_LOG() << "Rollback failed or no active transaction";
    }

    // Теперь начинаем новую транзакцию
    if (!m_database.transaction()) {
        QString error = m_database.lastError().text();
        DEBUG_LOG() << "Failed to start transaction:" << error;
        emit errorOccurred("Ошибка начала транзакции: " + error);

        // Восстанавливаем настройки БД
        if (m_database.driverName().contains("SQLITE")) {
            QSqlQuery pragma(m_database);
            pragma.exec("PRAGMA synchronous = NORMAL");
            pragma.exec("PRAGMA journal_mode = DELETE");
        }
        return false;
    }
    DEBUG_LOG() << "Transaction started successfully";

    // ========== ОПТИМИЗАЦИЯ 3: Подготовленный запрос ==========
    QSqlQuery insertQuery(m_database);
    insertQuery.prepare(
        "INSERT OR IGNORE INTO books (file_path, file_name, file_size, file_type, title, author, "
        "series, series_number, genre, language, year, archive_path, archive_internal_path, "
        "added_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
        );


    // ========== ОПТИМИЗАЦИЯ 4: Кэш уже существующих книг ==========
    QSet<QString> existingBooksCache;
    QSqlQuery cacheQuery(m_database);
    cacheQuery.exec("SELECT title || '|' || author FROM books");
    while (cacheQuery.next()) {
        existingBooksCache.insert(cacheQuery.value(0).toString());
    }
    DEBUG_LOG() << "Cached" << existingBooksCache.size() << "existing books";

    InpxParser parser(m_database);
    parser.setAbortFlag(&m_abort);

    // Прогресс
    parser.setProgressCallback([this](int progress, const QString& status) {
        int scaledProgress = 10 + (progress * 80 / 100);
        emit progressChanged(scaledProgress);
        emit statusChanged(status);
    });

    emit statusChanged("Начинаем импорт INPX: " + inpxInfo.fileName());

    QElapsedTimer timer;
    timer.start();

    // Импортируем с оптимизациями
    bool success = parser.importInpxCollectionOptimized(inpxFile, m_booksDir, insertQuery, existingBooksCache);

    // ========== ОПТИМИЗАЦИЯ 5: Финальный коммит ==========
    if (success) {
        if (m_database.commit()) {
            DEBUG_LOG() << "Final commit successful";
        } else {
            DEBUG_LOG() << "Final commit failed:" << m_database.lastError().text();
            success = false;
        }
    } else {
        m_database.rollback();
        DEBUG_LOG() << "Transaction rolled back due to errors";
    }

    // Восстанавливаем настройки БД
    if (m_database.driverName().contains("SQLITE")) {
        QSqlQuery pragma(m_database);
        pragma.exec("PRAGMA synchronous = NORMAL");
        pragma.exec("PRAGMA journal_mode = DELETE");
    }

    if (m_database.driverName().contains("MYSQL")) {
        QSqlQuery mysqlOpt(m_database);
        mysqlOpt.exec("SET autocommit=1");
        mysqlOpt.exec("SET unique_checks=1");
        mysqlOpt.exec("SET foreign_key_checks=1");
        mysqlOpt.exec("SET sql_log_bin=1");
        mysqlOpt.exec("SET innodb_flush_log_at_trx_commit=1");
    }

    DEBUG_LOG() << "INPX import completed in" << timer.elapsed() << "ms, success:" << success
                << "Inserted:" << parser.m_insertedCount
                << "Skipped:" << parser.m_skippedCount;

    if (success) {
        emit statusChanged(QString("INPX импорт завершен. Добавлено: %1, Пропущено: %2")
                               .arg(parser.m_insertedCount).arg(parser.m_skippedCount));
        return true;
    } else {
        emit errorOccurred("Не удалось выполнить импорт из INPX файла");
        return false;
    }
}

void BookScanner::scanDirectory(const QString &path)
{
    if (m_abort) return;

    QDir dir(path);
    if (!dir.exists()) {
        emit errorOccurred("Директория не существует: " + path);
        return;
    }

    // Сначала собираем список файлов
    QStringList supportedFormats = {
        "*.fb2", "*.epub", "*.pdf", "*.txt", "*.mobi",
        "*.zip", "*.rar", "*.7z", "*.tar"
    };

    QStringList files;
    QDirIterator it(path, supportedFormats, QDir::Files, QDirIterator::Subdirectories);

    emit statusChanged("Поиск файлов...");
    while (it.hasNext()) {
        files.append(it.next());
        if (files.size() % 1000 == 0) {
            emit statusChanged(QString("Найдено файлов: %1").arg(files.size()));
        }
    }

    if (files.isEmpty()) {
        emit statusChanged("Файлы не найдены");
        return;
    }

    emit statusChanged(QString("Найдено %1 файлов. Обработка...").arg(files.size()));

    // Обрабатываем файлы
    QElapsedTimer totalTimer;
    totalTimer.start();

    for (int i = 0; i < files.size() && !m_abort; ++i) {
        const QString &filePath = files[i];

        if (isArchiveFile(filePath)) {
            processArchive(filePath);
        } else {
            processFile(filePath);
        }

        // Обновляем прогресс каждые 10 файлов
        if (i % 10 == 0) {
            int progress = (i * 100) / files.size();
            emit progressChanged(progress);
            emit statusChanged(QString("Обработано %1 из %2 файлов").arg(i + 1).arg(files.size()));

            if (i % 100 == 0) {
                DEBUG_LOG() << "Progress:" << i << "/" << files.size()
                << "Time:" << totalTimer.elapsed() / 1000 << "s";
            }
        }

    }



    DEBUG_LOG() << "Scan completed in" << totalTimer.elapsed() << "ms";
}

void BookScanner::processFile(const QString &filePath)
{
    if (m_abort) return;

    QFileInfo fileInfo(filePath);
    QString extension = fileInfo.suffix().toLower();

    // Поддерживаемые форматы
    static const QSet<QString> supported = {"fb2", "epub", "pdf", "txt", "mobi"};
    if (!supported.contains(extension)) return;

    // Парсим метаданные
    BookMeta meta = m_parser->parseMetadata(filePath);

    // Fallback для пустых полей
    if (meta.title.isEmpty()) meta.title = fileInfo.completeBaseName();
    if (meta.author.isEmpty()) meta.author = QStringLiteral("Неизвестный автор");
    meta.title = meta.title.replace(u'_', u' ').simplified();
    meta.author = meta.author.replace(u'_', u' ').simplified();

    // Рассчитываем хеш файла
    QString fileHash = calculateFileHash(filePath);

    // Проверяем дубликат по хешу (надёжнее, чем title+author)
    if (!fileHash.isEmpty()) {
        QSqlQuery checkQuery(m_database);
        checkQuery.prepare("SELECT id, file_size FROM books WHERE file_hash = ? LIMIT 1");
        checkQuery.addBindValue(fileHash);

        if (checkQuery.exec() && checkQuery.next()) {
            qint64 existingSize = checkQuery.value(1).toLongLong();
            // Если размер совпадает — это точно дубликат
            if (fileInfo.size() == existingSize) {
                DEBUG_LOG() << "Skipping duplicate by hash:" << filePath;
                return;
            }
            // Если размер больше — удаляем старую запись (обновление)
            int existingId = checkQuery.value(0).toInt();
            QSqlQuery deleteQuery(m_database);
            deleteQuery.prepare("DELETE FROM books WHERE id = ?");
            deleteQuery.addBindValue(existingId);
            deleteQuery.exec();
        }
    }

    // Добавляем в batch с хешем
    PendingBook book;
    book.meta = std::move(meta);
    book.filePath = filePath;
    book.fileSize = fileInfo.size();
    book.fileType = extension;
    book.fileHash = fileHash;  // Сохраняем хеш
    book.archivePath.clear();
    book.internalPath.clear();

    addToBatch(book);
}


// void BookScanner::processFile(const QString &filePath)
// {
//     if (m_abort) return;

//     QFileInfo fileInfo(filePath);
//     QString extension = fileInfo.suffix().toLower();

//     // Парсим метаданные
//     BookMeta meta = m_parser->parseMetadata(filePath);


//     // ОТЛАДКА: Выводим описание
//     qDebug() << "=== BOOK METADATA ===";
//     qDebug() << "Title:" << meta.title;
//     qDebug() << "Author:" << meta.author;
//     qDebug() << "Description length:" << meta.description.length();
//     qDebug() << "Description preview:" << meta.description.left(200);
//     qDebug() << "=== END BOOK METADATA ===";


//     if (meta.title.isEmpty()) {
//         meta.title = fileInfo.completeBaseName();
//     }
//     if (meta.author.isEmpty()) {
//         meta.author = "Неизвестный автор";
//     }

//     // Очистка
//     meta.title = meta.title.replace('_', ' ').simplified();
//     meta.author = meta.author.replace('_', ' ').simplified();

//     // Проверяем существование книги БЫСТРЫМ ЗАПРОСОМ
//     QSqlQuery checkQuery(m_database);
//     checkQuery.prepare("SELECT id FROM books WHERE title = ? AND author = ? LIMIT 1");
//     checkQuery.addBindValue(meta.title);
//     checkQuery.addBindValue(meta.author);

//     if (checkQuery.exec() && checkQuery.next()) {
//         // Книга существует - проверяем размер
//         int existingId = checkQuery.value(0).toInt();

//         QSqlQuery sizeQuery(m_database);
//         sizeQuery.prepare("SELECT file_size FROM books WHERE id = ?");
//         sizeQuery.addBindValue(existingId);

//         if (sizeQuery.exec() && sizeQuery.next()) {
//             qint64 existingSize = sizeQuery.value(0).toLongLong();
//             if (fileInfo.size() <= existingSize) {
//                 return; // Пропускаем
//             }

//             // Обновляем существующую книгу
//             QSqlQuery updateQuery(m_database);
//             updateQuery.prepare(
//                 "UPDATE books SET file_path = ?, file_size = ?, last_modified = CURRENT_TIMESTAMP "
//                 "WHERE id = ?"
//                 );
//             updateQuery.addBindValue(filePath);
//             updateQuery.addBindValue(fileInfo.size());
//             updateQuery.addBindValue(existingId);
//             updateQuery.exec();
//             return;
//         }
//     }

//     // Добавляем в batch
//     PendingBook book;
//     book.meta = meta;
//     book.filePath = filePath;
//     book.fileSize = fileInfo.size();
//     book.fileType = extension;
//     book.archivePath = QString();
//     book.internalPath = QString();

//     addToBatch(book);
// }

void BookScanner::processArchive(const QString &archivePath) {
    if (m_abort) return;

    // 1. Быстрая проверка: нужно ли вообще сканировать этот архив?
    if (!shouldRescanArchive(archivePath)) {
        DEBUG_LOG() << "Skipping unchanged archive:" << archivePath;
        return;
    }

    DEBUG_LOG() << "=== START PROCESS ARCHIVE (Optimized) ===";
    QElapsedTimer timer;
    timer.start();

    // 2. Открываем архив ОДИН РАЗ
    if (!m_archiveHandler->openArchive(archivePath)) {
        emit errorOccurred("Не удалось открыть архив: " + m_archiveHandler->getLastError());
        return;
    }

    emit statusChanged("Чтение содержимого архива: " + QFileInfo(archivePath).fileName());

    ArchiveFile fileInfo;
    int processedCount = 0;

    // 3. Итерируемся по файлам БЕЗ переоткрытия
    while (m_archiveHandler->readNextHeader(fileInfo)) {
        if (m_abort) break;

        DEBUG_LOG() << "Processing file inside archive:" << fileInfo.path;

        // Читаем данные файла (мы уже стоим на нем)
        QByteArray content = m_archiveHandler->readCurrentData();
        QString fileHash = calculateMemoryHash(content);

        if (content.isEmpty()) {
            DEBUG_LOG() << "Empty content for:" << fileInfo.name;
            continue;
        }

        // 4. Парсим метаданные из памяти
        BookMeta meta;
        QString extension = QFileInfo(fileInfo.name).suffix().toLower();

        if (extension == "epub") {
            meta = m_parser->parseEpubFromMemory(content);
        } else {
            // Для FB2 и TXT используем общий парсер, передав расширение
            meta = m_parser->parseMetadataFromMemory(content, extension);
        }

        if (meta.title.isEmpty()) {
            meta.title = fileInfo.name;
        }
        if (meta.author.isEmpty()) meta.author = "Неизвестный автор";

        // Очистка
        meta.title = meta.title.replace('_', ' ').simplified();
        meta.author = meta.author.replace('_', ' ').simplified();

        // 5. Проверка наличия в БД (оптимизация, чтобы не вставлять дубли)
        QSqlQuery checkQuery(m_database);
        checkQuery.prepare("SELECT id, file_size FROM books WHERE title = ? AND author = ? LIMIT 1");
        checkQuery.addBindValue(meta.title);
        checkQuery.addBindValue(meta.author);

        bool shouldAdd = true;
        if (checkQuery.exec() && checkQuery.next()) {
            qint64 existingSize = checkQuery.value(1).toLongLong();
            // Если размер совпадает или новый меньше — скорее всего это дубль, пропускаем
            if (fileInfo.size <= existingSize) {
                shouldAdd = false;
            } else {
                // Если новый файл больше, удаляем старый (обновление)
                int existingId = checkQuery.value(0).toInt();
                QSqlQuery deleteQuery(m_database);
                deleteQuery.prepare("DELETE FROM books WHERE id = ?");
                deleteQuery.addBindValue(existingId);
                deleteQuery.exec();
            }
        }

        if (shouldAdd) {
            // Вставляем в БД
            PendingBook book;
            book.meta = meta;
            book.filePath = archivePath; // Для архива путь — это сам архив
            book.archivePath = archivePath;
            book.internalPath = fileInfo.path;
            book.fileSize = fileInfo.size;
            book.fileType = extension;
            book.fileHash = fileHash;

            addToBatch(book);
            processedCount++;
        }

        // Обновляем статус каждые 10 файлов
        if (processedCount % 100 == 0) {
            emit statusChanged(QString("Обработано %1 файлов в архиве...").arg(processedCount));
        }
    }

    m_archiveHandler->closeArchive();
    updateArchiveInfo(archivePath, false);

    DEBUG_LOG() << "=== ARCHIVE COMPLETE === Files:" << processedCount
                << "Time:" << timer.elapsed() << "ms";
}


void BookScanner::addToBatch(const PendingBook &book)
{
    m_pendingBatch.append(book);
    qDebug() << "addToBatch: pending=" << m_pendingBatch.size() << "/" << m_batchSize;
    if (m_pendingBatch.size() >= m_batchSize) {
        qDebug() << "Flushing batch due to size limit";
        flushBatch();
    }
}

void BookScanner::flushBatch()
{
    if (m_pendingBatch.isEmpty()) return;
    if (insertBookBatch()) {
        m_pendingBatch.clear();
    } else {
        qDebug() << "Batch insertion failed, keeping " << m_pendingBatch.size() << " books for retry";
        // По желанию: можно сделать повтор через некоторое время или просто оставить
    }
}

bool BookScanner::insertBookBatch()
{
    if (m_pendingBatch.isEmpty()) return true;

    QSqlQuery query(m_database);
    query.prepare(
        "INSERT INTO books (file_path, file_name, file_size, file_type, file_hash, "
        "title, author, series, series_number, genre, language, year, description, "
        "archive_path, publisher, archive_internal_path, added_date) "
        "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
        );


    bool ok = true;

    for (const PendingBook &book : m_pendingBatch) {

        QString fileName = QFileInfo(book.filePath).fileName();
        if (!book.archivePath.isEmpty() && !book.internalPath.isEmpty())
            fileName = QFileInfo(book.internalPath).fileName();


        query.addBindValue(book.filePath);              // file_path
        query.addBindValue(fileName);                   // file_name
        query.addBindValue(book.fileSize);              // file_size
        query.addBindValue(book.fileType);              // file_type
        query.addBindValue(book.fileHash);              // file_hash
        query.addBindValue(book.meta.title);            // title
        query.addBindValue(book.meta.author);           // author
        query.addBindValue(book.meta.series);           // series
        query.addBindValue(book.meta.seriesNumber);     // series_number
        query.addBindValue(book.meta.genre);            // genre
        query.addBindValue(book.meta.language);         // language
        query.addBindValue(book.meta.year);             // year
        query.addBindValue(book.meta.description);      // description
        query.addBindValue(book.archivePath);           // archive_path
        query.addBindValue(book.publisher);             // publisher
        query.addBindValue(book.internalPath);          // archive_internal_path

        if (!query.exec()) {
            DEBUG_LOG() << "Failed to insert book:" << query.lastError().text();
            ok = false;
            break;
        }

        emit bookFound(book.meta.title, book.meta.author, book.archivePath.isEmpty() ? book.meta.title : fileName + " [архив]");

    }

    if (ok) {
        // Фиксируем все вставки пачки
        if (!m_database.commit()) {
            DEBUG_LOG() << "Failed to commit batch:" << m_database.lastError().text();
            m_database.rollback();
            return false;
        }
        m_pendingBatch.clear();
        DEBUG_LOG() << "Batch committed, " << m_pendingBatch.size() << " books saved";
        return true;
    } else {
        // При ошибке откатываем всю пачку
        m_database.rollback();
        m_pendingBatch.clear(); // или можно сохранить для повтора, но лучше очистить
        return false;
    }
}


bool BookScanner::addBookToDatabase(const BookMeta &meta, const ArchiveFile &file,
                                   const QString &archivePath, const QFileInfo &archiveInfo)
{
    QSqlQuery insertQuery(m_database);
    insertQuery.prepare(
        "INSERT INTO books (file_path, file_name, file_size, file_type, title, author, "
        "series, series_number, genre, language, year, description, archive_path, publisher, archive_internal_path, "
        "added_date, file_mtime) "
        "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)"
    );

    QString extension = QFileInfo(file.name).suffix().toLower();
    QString displayPath = archivePath + "/" + file.name;

    insertQuery.addBindValue(displayPath);
    insertQuery.addBindValue(file.name);
    insertQuery.addBindValue(file.size);
    insertQuery.addBindValue(extension);
    insertQuery.addBindValue(meta.title);
    insertQuery.addBindValue(meta.author);
    insertQuery.addBindValue(meta.series);
    insertQuery.addBindValue(meta.seriesNumber);
    insertQuery.addBindValue(meta.genre);
    insertQuery.addBindValue(meta.language);
    insertQuery.addBindValue(meta.year);
    insertQuery.addBindValue(meta.description);
    insertQuery.addBindValue(archivePath);
    insertQuery.addBindValue(meta.publisher);
    insertQuery.addBindValue(file.path);
    insertQuery.addBindValue(archiveInfo.lastModified().toSecsSinceEpoch());

    if (insertQuery.exec()) {
        qDebug() << "Book from archive added to database:" << meta.title << "-" << meta.author;
        emit bookFound(meta.title, meta.author, file.name + " [архив]");
        return true;
    } else {
        qDebug() << "Error adding book from archive:" << insertQuery.lastError().text();
        return false;
    }
}

bool BookScanner::updateBookInDatabase(const BookMeta &meta, const ArchiveFile &file,
                                      const QString &archivePath, const QFileInfo &archiveInfo,
                                      int existingId)
{
    QSqlQuery updateQuery(m_database);
    updateQuery.prepare(
        "UPDATE books SET file_path = ?, file_name = ?, file_size = ?, file_type = ?, "
        "archive_path = ?, archive_internal_path = ?, "
        "series = ?, series_number = ?, genre = ?, language = ?, year = ?, "
        "last_modified = CURRENT_TIMESTAMP, file_mtime = ? "
        "WHERE id = ?"
    );

    QString extension = QFileInfo(file.name).suffix().toLower();
    QString displayPath = archivePath + "/" + file.name;

    updateQuery.addBindValue(displayPath);
    updateQuery.addBindValue(file.name);
    updateQuery.addBindValue(file.size);
    updateQuery.addBindValue(extension);
    updateQuery.addBindValue(archivePath);
    updateQuery.addBindValue(file.name);
    updateQuery.addBindValue(meta.series);
    updateQuery.addBindValue(meta.seriesNumber);
    updateQuery.addBindValue(meta.genre);
    updateQuery.addBindValue(meta.language);
    updateQuery.addBindValue(meta.year);
    updateQuery.addBindValue(archiveInfo.lastModified().toSecsSinceEpoch());
    updateQuery.addBindValue(existingId);

    if (updateQuery.exec()) {
        qDebug() << "Book from archive updated (larger file):" << meta.title << "-" << meta.author;
        emit bookFound(meta.title + " [ОБНОВЛЕНО]", meta.author, file.name + " [архив]");
        return true;
    } else {
        qDebug() << "Error updating book from archive:" << updateQuery.lastError().text();
        return false;
    }
}



//окончание EPUB сканер из архива



bool BookScanner::isArchiveFile(const QString &filePath)
{
    QString extension = QFileInfo(filePath).suffix().toLower();
    return (extension == "zip" || extension == "rar" || extension == "7z" ||
            extension == "tar" || extension == "gz" || extension == "bz2");
}

// ScannerDialog implementation
ScannerDialog::ScannerDialog(QSqlDatabase database, QWidget *parent) :
    QDialog(parent),
    ui(new Ui::ScannerDialog),
    m_database(database),
    m_scannerThread(nullptr),
    m_scanner(nullptr)
{
    ui->setupUi(this);

    // Настройка таблицы
    ui->tableBooks->setColumnCount(3);
    ui->tableBooks->setHorizontalHeaderLabels(QStringList() << "Название" << "Автор" << "Файл");
    ui->tableBooks->horizontalHeader()->setStretchLastSection(true);

    // Загрузка настроек
    QSettings settings("Squee&Dragon", "BookLibrary");
    ui->txtBooksDir->setText(settings.value("scanner/books_dir", QDir::homePath()).toString());
    ui->txtInpxFile->setText(settings.value("scanner/inpx_file", "").toString());
    ui->chkUseInpx->setChecked(settings.value("scanner/use_inpx", false).toBool());

    // ИСПОЛЬЗУЕМ СУЩЕСТВУЮЩУЮ КНОПКУ ИЗ UI, А НЕ СОЗДАЕМ НОВУЮ

    // Включаем кнопку (если она была отключена в UI)
    ui->btnForceRescan->setEnabled(true);

    // Исправляем CSS (если есть ошибки)
    ui->btnForceRescan->setStyleSheet(
        "QPushButton {"
        "    background-color: #f78222;"
        "    color: white;"
        "    font-weight: bold;"
        "    padding: 8px 16px;"
        "    border: none;"
        "    border-radius: 4px;"
        "}"
        "QPushButton:hover {"
        "    background-color: #e6731a;"
        "}"
        "QPushButton:pressed {"
        "    background-color: #d56412;"
        "}"
    );

    // ПОДКЛЮЧАЕМ СИГНАЛ К СУЩЕСТВУЮЩЕЙ КНОПКЕ ИЗ UI
    connect(ui->btnForceRescan, &QPushButton::clicked, [this]() {
        qDebug() << "Force rescan button clicked!";

        if (QMessageBox::question(this, "Подтверждение",
            "Вы уверены, что хотите пометить все архивы для принудительного пересканирования?") == QMessageBox::Yes) {

            qDebug() << "Executing force rescan...";

            BookScanner tempScanner(m_database, "", false, "");
            tempScanner.forceRescanAllArchives();

            QMessageBox::information(this, "Пересканирование", "Все архивы помечены для пересканирования");

            qDebug() << "Force rescan completed";
        }
    });

    updateControls(false);

    // Отладочная информация
    qDebug() << "ScannerDialog initialized";
    qDebug() << "Force rescan button enabled:" << ui->btnForceRescan->isEnabled();
    qDebug() << "Force rescan button visible:" << ui->btnForceRescan->isVisible();
}

QString BookScanner::calculateFileHash(const QString &filePath)
{
    QFile file(filePath);
    if (!file.open(QIODevice::ReadOnly)) return QString();

    QCryptographicHash hash(QCryptographicHash::Md5);
    const qint64 CHUNK = 10 * 1024 * 1024; // 10 MB
    const qint64 bufferSize = 65536;
    char buffer[bufferSize];

    qint64 fileSize = file.size();

    // Маленький файл — хешируем целиком
    if (fileSize <= CHUNK * 2) {
        while (!file.atEnd()) {
            qint64 bytesRead = file.read(buffer, bufferSize);
            if (bytesRead <= 0) break;
            hash.addData(buffer, bytesRead);
        }
    }
    // Большой файл — начало + конец
    else {
        // Первые 10 MB
        qint64 remaining = CHUNK;
        while (remaining > 0) {
            qint64 toRead = qMin(remaining, bufferSize);
            qint64 bytesRead = file.read(buffer, toRead);
            if (bytesRead <= 0) break;
            hash.addData(buffer, bytesRead);
            remaining -= bytesRead;
        }
        // Последние 10 MB
        if (file.seek(fileSize - CHUNK)) {
            remaining = CHUNK;
            while (remaining > 0) {
                qint64 toRead = qMin(remaining, bufferSize);
                qint64 bytesRead = file.read(buffer, toRead);
                if (bytesRead <= 0) break;
                hash.addData(buffer, bytesRead);
                remaining -= bytesRead;
            }
        }
    }
    file.close();
    return QString::fromLatin1(hash.result().toHex());
}

QString BookScanner::calculateMemoryHash(const QByteArray &data)
{
    return QString::fromLatin1(
        QCryptographicHash::hash(data, QCryptographicHash::Md5).toHex()
        );
}





void ScannerDialog::onForceRescanClicked()
{
    if (QMessageBox::question(this, "Подтверждение",
        "Вы уверены, что хотите пометить все архивы для принудительного пересканирования?") == QMessageBox::Yes) {

        if (m_scanner) {
            m_scanner->forceRescanAllArchives();
            QMessageBox::information(this, "Пересканирование", "Все архивы помечены для пересканирования");
        } else {
            // Создаем временный сканер для выполнения операции
            BookScanner tempScanner(m_database, "", false, "");
            tempScanner.forceRescanAllArchives();
            QMessageBox::information(this, "Пересканирование", "Все архивы помечены для пересканирования");
        }
    }
}


ScannerDialog::~ScannerDialog()
{
    if (m_scannerThread) {
        m_scannerThread->quit();
        m_scannerThread->wait();
    }
    delete ui;
}

void ScannerDialog::on_btnBrowseBooksDir_clicked()
{
    QString dir = QFileDialog::getExistingDirectory(this, "Выберите директорию с книгами",
                                                   ui->txtBooksDir->text());
    if (!dir.isEmpty()) {
        ui->txtBooksDir->setText(dir);

        // Автоматически ищем INPX файл
        QDir booksDir(dir);
        QStringList inpxFiles = booksDir.entryList(QStringList() << "*.inpx", QDir::Files);
        if (!inpxFiles.isEmpty()) {
            ui->txtInpxFile->setText(dir + "/" + inpxFiles.first());
            ui->chkUseInpx->setChecked(true);
        }

        // Сохраняем настройки
        QSettings settings("Squee&Dragon", "BookLibrary");
        settings.setValue("scanner/books_dir", dir);
    }
}

void ScannerDialog::on_btnBrowseInpx_clicked()
{
    QString file = QFileDialog::getOpenFileName(this, "Выберите INPX файл",
                                               ui->txtInpxFile->text(),
                                               "INPX files (*.inpx)");
    if (!file.isEmpty()) {
        ui->txtInpxFile->setText(file);
        ui->chkUseInpx->setChecked(true);

        QSettings settings("Squee&Dragon", "BookLibrary");
        settings.setValue("scanner/inpx_file", file);
    }
}

void ScannerDialog::on_btnStartScan_clicked()
{
    if (ui->txtBooksDir->text().isEmpty()) {
        QMessageBox::warning(this, "Ошибка", "Укажите директорию с книгами");
        return;
    }

    if (!m_database.isOpen()) {
        QMessageBox::warning(this, "Ошибка", "База данных не открыта");
        return;
    }

    // Очищаем таблицу
    ui->tableBooks->setRowCount(0);
    ui->progressBar->setValue(0);

    // Создаем и запускаем сканер в отдельном потоке
    m_scannerThread = new QThread(this);
    m_scanner = new BookScanner(m_database,
                               ui->txtBooksDir->text(),
                               ui->chkUseInpx->isChecked(),
                               ui->txtInpxFile->text());
    m_scanner->moveToThread(m_scannerThread);

    connect(m_scannerThread, &QThread::started, m_scanner, &BookScanner::startScanning);
    connect(m_scanner, &BookScanner::progressChanged, this, &ScannerDialog::onProgressChanged);
    connect(m_scanner, &BookScanner::statusChanged, this, &ScannerDialog::onStatusChanged);
    connect(m_scanner, &BookScanner::bookFound, this, &ScannerDialog::onBookFound);
    qDebug() << "🔗 Signal connected, scanner thread:" << m_scanner->thread() << "dialog thread:" << this->thread();

    connect(m_scanner, &BookScanner::finished, this, &ScannerDialog::onFinished);
    connect(m_scanner, &BookScanner::errorOccurred, this, &ScannerDialog::onErrorOccurred);

    connect(m_scannerThread, &QThread::finished, m_scanner, &BookScanner::deleteLater);

    updateControls(true);
    m_scannerThread->start();
}

void ScannerDialog::on_btnStopScan_clicked()
{
    if (m_scanner) {
        qDebug() << "Stopping scanner...";
        m_scanner->cancelScanning();
        ui->statusLabel->setText("Остановка сканирования...");
        ui->btnStopScan->setEnabled(false);

        // Даем сканеру время на завершение
        QTimer::singleShot(1000, this, [this]() {
            if (m_scannerThread && m_scannerThread->isRunning()) {
                m_scannerThread->terminate();
                m_scannerThread->wait();
            }
            onFinished();
        });
    }
}

void ScannerDialog::on_chkUseInpx_toggled(bool checked)
{
    ui->txtInpxFile->setEnabled(checked);
    ui->btnBrowseInpx->setEnabled(checked);

    QSettings settings("Squee&Dragon", "BookLibrary");
    settings.setValue("scanner/use_inpx", checked);
}

void ScannerDialog::onProgressChanged(int value)
{
    ui->progressBar->setValue(value);
}

void ScannerDialog::onStatusChanged(const QString &status)
{
    ui->statusLabel->setText(status);
}

void ScannerDialog::onBookFound(const QString &title, const QString &author, const QString &filename)
{
    qDebug() << "Book found:" << title << "by" << author;

    int row = ui->tableBooks->rowCount();
    ui->tableBooks->insertRow(row);
    ui->tableBooks->setItem(row, 0, new QTableWidgetItem(title));
    ui->tableBooks->setItem(row, 1, new QTableWidgetItem(author));
    ui->tableBooks->setItem(row, 2, new QTableWidgetItem(filename));

    // Автопрокрутка к последней добавленной книге
    ui->tableBooks->scrollToBottom();

    // Обновляем статус
    ui->statusLabel->setText(QString("Найдено книг: %1").arg(row + 1));
}

void ScannerDialog::onFinished()
{
    updateControls(false);
    ui->statusLabel->setText("Сканирование завершено");

    // Используем deleteLater для безопасной очистки
    if (m_scannerThread) {
        m_scannerThread->quit();
        // Не ждём вручную. Поток завершится сам, объекты удалятся через deleteLater
        m_scannerThread = nullptr;
    }
    m_scanner = nullptr; // deleteLater уже подключен к finished потока

    // Показываем результат
    QMessageBox::information(this, "Готово", "Сканирование коллекции завершено!");
    emit booksUpdated();
}

void ScannerDialog::onErrorOccurred(const QString &error)
{
    QMessageBox::critical(this, "Ошибка", error);
    onFinished();
}

void ScannerDialog::updateControls(bool scanning)
{
    ui->btnStartScan->setEnabled(!scanning);
    ui->btnStopScan->setEnabled(scanning);
    ui->txtBooksDir->setEnabled(!scanning);
    ui->btnBrowseBooksDir->setEnabled(!scanning);
    ui->chkUseInpx->setEnabled(!scanning);
    ui->txtInpxFile->setEnabled(!scanning && ui->chkUseInpx->isChecked());
    ui->btnBrowseInpx->setEnabled(!scanning && ui->chkUseInpx->isChecked());
}
