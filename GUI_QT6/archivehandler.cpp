#include "archivehandler.h"
#include <archive.h>
#include <archive_entry.h>
#include <QDebug>
#include <QFileInfo>
#include <QDir>
#include <QElapsedTimer>

// Макрос для отключения отладки в release
#ifdef QT_DEBUG
#define DEBUG_LOG qDebug
#else
#define DEBUG_LOG if(false) qDebug
#endif

ArchiveHandler::ArchiveHandler()
    : m_archive(nullptr)
    , m_isOpen(false)
    , m_hasCachedFileList(false)
{
    // Кеш файлов - максимум 50MB
    m_fileCache.setMaxCost(50 * 1024 * 1024);
}

ArchiveHandler::~ArchiveHandler()
{
    closeArchive();
}

void ArchiveHandler::clearCache()
{
    m_fileCache.clear();
    m_cachedFileList.clear();
    m_hasCachedFileList = false;
}

void ArchiveHandler::invalidateCache()
{
    clearCache();
}

bool ArchiveHandler::openArchive(const QString &archivePath)
{
    // Если уже открыт этот же архив, просто возвращаем успех
    if (m_isOpen && m_archivePath == archivePath) {
        DEBUG_LOG() << "Archive already open:" << archivePath;
        return true;
    }

    // Закрываем текущий архив если открыт другой
    if (m_isOpen) {
        closeArchive();
    }

    // Очищаем кеш при открытии нового архива
    clearCache();

    DEBUG_LOG() << "ArchiveHandler: Opening archive:" << archivePath;

    m_archive = archive_read_new();
    if (!m_archive) {
        setError("Failed to create archive reader");
        return false;
    }

    // Поддержка всех форматов как в C версии
    archive_read_support_format_zip(m_archive);
    archive_read_support_format_rar(m_archive);
    archive_read_support_format_7zip(m_archive);
    archive_read_support_format_tar(m_archive);
    archive_read_support_format_iso9660(m_archive);
    archive_read_support_format_cpio(m_archive);
    archive_read_support_filter_all(m_archive);

    // Оптимизация: устанавливаем буфер побольше
    int r = archive_read_open_filename(m_archive, archivePath.toLocal8Bit().constData(), 1024 * 1024); // 1MB буфер
    if (r != ARCHIVE_OK) {
        setError(QString("Failed to open archive: %1").arg(archive_error_string(m_archive)));
        archive_read_free(m_archive);
        m_archive = nullptr;
        return false;
    }

    m_archivePath = archivePath;
    m_isOpen = true;

    DEBUG_LOG() << "ArchiveHandler: Archive opened successfully";
    return true;
}

void ArchiveHandler::closeArchive()
{
    if (m_archive) {
        archive_read_close(m_archive);
        archive_read_free(m_archive);
        m_archive = nullptr;
    }
    m_isOpen = false;
    clearCache();
    m_archivePath.clear();
}

QVector<ArchiveFile> ArchiveHandler::listFiles()
{
    // Возвращаем кешированный список если есть
    if (m_hasCachedFileList && !m_cachedFileList.isEmpty()) {
        DEBUG_LOG() << "Returning cached file list, size:" << m_cachedFileList.size();
        return m_cachedFileList;
    }

    QVector<ArchiveFile> files;
    m_cachedFileList.clear();

    if (!m_isOpen || !m_archive) {
        setError("Archive is not open");
        return files;
    }

    DEBUG_LOG() << "ArchiveHandler: Listing files in archive:" << m_archivePath;

    struct archive_entry *entry;
    int r;

    // Сохраняем текущую позицию
    // Для libarchive нужно переоткрыть архив для повторного чтения
    // Но мы уже открыты, поэтому просто читаем с начала
    // Сбрасываем позицию чтения - переоткрываем архив
    QString currentPath = m_archivePath;
    closeArchive();
    if (!openArchive(currentPath)) {
        return files;
    }

    QElapsedTimer timer;
    timer.start();

    while (true) {
        r = archive_read_next_header(m_archive, &entry);
        if (r == ARCHIVE_EOF) {
            break;
        }
        if (r != ARCHIVE_OK) {
            setError(QString("Failed to read archive header: %1").arg(archive_error_string(m_archive)));
            break;
        }

        const char* filename = archive_entry_pathname(entry);
        la_int64_t size = archive_entry_size(entry);
        mode_t filetype = archive_entry_filetype(entry);

        if (!filename) {
            archive_read_data_skip(m_archive);
            continue;
        }

        QString qfilename = QString::fromUtf8(filename);

        // Пропускаем директории
        if (filetype != AE_IFREG) {
            archive_read_data_skip(m_archive);
            continue;
        }

        // Поддерживаемые форматы как в C версии
        QString extension = QFileInfo(qfilename).suffix().toLower();
        QStringList supportedFormats = {"fb2", "epub", "pdf", "mobi", "txt", "fb2.zip"};

        if (supportedFormats.contains(extension) || qfilename.endsWith(".fb2.zip", Qt::CaseInsensitive)) {
            ArchiveFile file;
            file.name = QFileInfo(qfilename).fileName();
            file.path = qfilename;
            file.size = size;
            file.isDirectory = false;

            files.append(file);
            m_cachedFileList.append(file);  // Кешируем
        }

        archive_read_data_skip(m_archive);

        // Защита от слишком больших архивов
        if (files.size() > 10000) {
            DEBUG_LOG() << "Too many files in archive, stopping at 10000";
            break;
        }
    }

    m_hasCachedFileList = true;

    DEBUG_LOG() << "ArchiveHandler: Found" << files.size() << "supported files. Time:" << timer.elapsed() << "ms";

    return files;
}

QByteArray ArchiveHandler::readFileCached(const QString &internalPath)
{
    // Проверяем кеш
    if (m_fileCache.contains(internalPath)) {
        DEBUG_LOG() << "Cache hit for:" << internalPath;
        QByteArray *cached = m_fileCache[internalPath];
        if (cached) {
            return *cached;
        }
    }

    // Читаем файл
    QByteArray content = readFile(internalPath);

    // Кешируем только маленькие файлы (< 500KB)
    if (!content.isEmpty() && content.size() < 500 * 1024) {
        int cost = content.size();
        m_fileCache.insert(internalPath, new QByteArray(content), cost);
        DEBUG_LOG() << "Cached file:" << internalPath << "size:" << cost;
    }

    return content;
}

QByteArray ArchiveHandler::readFile(const QString &internalPath)
{
    QByteArray content;

    if (!m_isOpen || !m_archive) {
        setError("Archive is not open");
        return content;
    }

    DEBUG_LOG() << "ArchiveHandler: Looking for file:" << internalPath;

    // Сохраняем текущий путь и переоткрываем архив для поиска
    QString currentArchivePath = m_archivePath;
    // bool wasOpen = m_isOpen;

    // Закрываем текущий архив
    closeArchive();

    // Открываем заново
    if (!openArchive(currentArchivePath)) {
        return content;
    }

    struct archive_entry *entry;
    bool found = false;
    QElapsedTimer timer;
    timer.start();

    while (true) {
        int r = archive_read_next_header(m_archive, &entry);
        if (r == ARCHIVE_EOF) {
            break;
        }
        if (r != ARCHIVE_OK) {
            break;
        }

        const char* filename = archive_entry_pathname(entry);
        if (!filename) {
            archive_read_data_skip(m_archive);
            continue;
        }

        QString entryPath = QString::fromUtf8(filename);

        if (entryPath == internalPath) {
            la_int64_t size = archive_entry_size(entry);

            if (size > 0 && size < 100 * 1024 * 1024) { // До 100MB
                content.resize(size);
                la_ssize_t read_size = archive_read_data(m_archive, content.data(), size);

                if (read_size == size) {
                    found = true;
                    DEBUG_LOG() << "Successfully read file:" << internalPath << "size:" << size;
                } else {
                    content.clear();
                }
            }
            break;
        }

        archive_read_data_skip(m_archive);

        // Таймаут для поиска
        if (timer.elapsed() > 10000) {
            DEBUG_LOG() << "Timeout while searching for file:" << internalPath;
            break;
        }
    }

    if (!found) {
        DEBUG_LOG() << "File not found:" << internalPath;
    }

    return content;
}

bool ArchiveHandler::extractFile(const QString &internalPath, const QString &outputPath)
{
    QByteArray content = readFile(internalPath);
    if (content.isEmpty()) {
        return false;
    }

    // Создаем директорию если нужно
    QFileInfo outputInfo(outputPath);
    QDir().mkpath(outputInfo.absolutePath());

    QFile file(outputPath);
    if (!file.open(QIODevice::WriteOnly)) {
        setError("Failed to create output file: " + outputPath);
        return false;
    }

    qint64 written = file.write(content);
    file.close();

    if (written != content.size()) {
        setError("Failed to write complete file");
        return false;
    }

    return true;
}

void ArchiveHandler::setError(const QString &error)
{
    m_lastError = error;
    DEBUG_LOG() << "ArchiveHandler error:" << error;
}

ArchiveInfo ArchiveHandler::getArchiveInfo(const QString &archivePath)
{
    ArchiveInfo info;
    info.path = archivePath;

    QFileInfo fileInfo(archivePath);
    info.size = fileInfo.size();
    info.lastModified = fileInfo.lastModified().toSecsSinceEpoch();

    // Получаем количество файлов (с кешированием)
    if (openArchive(archivePath)) {
        QVector<ArchiveFile> files = listFiles();
        info.fileCount = files.size();
        // Не закрываем архив - оставляем для последующего использования
    } else {
        info.fileCount = 0;
    }

    // Вычисляем хеш
    info.hash = calculateArchiveHash(archivePath);

    return info;
}

QByteArray ArchiveHandler::calculateArchiveHash(const QString &archivePath)
{
    QFile file(archivePath);
    if (!file.open(QIODevice::ReadOnly)) {
        return QByteArray();
    }

    QCryptographicHash hash(QCryptographicHash::Md5);

    const qint64 CHUNK_SIZE = 10 * 1024 * 1024; // 10 MB
    const qint64 bufferSize = 65536;            // 64 KB буфер
    char buffer[bufferSize];

    qint64 fileSize = file.size();

    // Маленький файл - хешируем целиком
    if (fileSize <= CHUNK_SIZE) {
        qint64 bytesRead;
        while ((bytesRead = file.read(buffer, bufferSize)) > 0) {
            hash.addData(QByteArrayView(buffer, bytesRead));
        }
        file.close();
        return hash.result().toHex();
    }

    // Большой файл - начало + конец (как в C версии)
    // 1. Первые 10 MB
    qint64 remaining = CHUNK_SIZE;
    while (remaining > 0) {
        qint64 toRead = qMin(remaining, bufferSize);
        qint64 bytesRead = file.read(buffer, toRead);
        if (bytesRead <= 0) break;
        hash.addData(QByteArrayView(buffer, bytesRead));
        remaining -= bytesRead;
    }

    // 2. Последние 10 MB
    if (file.seek(fileSize - CHUNK_SIZE)) {
        remaining = CHUNK_SIZE;
        while (remaining > 0) {
            qint64 toRead = qMin(remaining, bufferSize);
            qint64 bytesRead = file.read(buffer, toRead);
            if (bytesRead <= 0) break;
            hash.addData(QByteArrayView(buffer, bytesRead));
            remaining -= bytesRead;
        }
    } else {
        // Если seek не удался - хешируем весь файл
        file.seek(0);
        qint64 bytesRead;
        while ((bytesRead = file.read(buffer, bufferSize)) > 0) {
            hash.addData(QByteArrayView(buffer, bytesRead));
        }
    }

    file.close();
    return hash.result().toHex();
}

bool ArchiveHandler::isSupportedFormat(const QString &fileName) {
    QString extension = QFileInfo(fileName).suffix().toLower();
    return extension == "fb2" || extension == "epub" || extension == "txt" ||
           fileName.endsWith(".fb2.zip", Qt::CaseInsensitive);
}

bool ArchiveHandler::readNextHeader(ArchiveFile &fileInfo) {
    if (!m_isOpen || !m_archive) {
        setError("Archive is not open");
        return false;
    }

    struct archive_entry *entry;
    int r = archive_read_next_header(m_archive, &entry);

    if (r == ARCHIVE_EOF) {
        return false; // Конец архива
    }
    if (r != ARCHIVE_OK) {
        // Пропускаем битые заголовки
        return false;
    }

    const char* filename = archive_entry_pathname(entry);
    if (!filename) return false;

    QString qfilename = QString::fromUtf8(filename);

    // Сразу проверяем тип файла, чтобы не читать лишнее
    if (!isSupportedFormat(qfilename)) {
        archive_read_data_skip(m_archive); // Пропускаем данные
        return readNextHeader(fileInfo); // Рекурсивно берем следующий (или переделать в цикл)
    }

    // Заполняем структуру
    fileInfo.name = QFileInfo(qfilename).fileName();
    fileInfo.path = qfilename;
    fileInfo.size = archive_entry_size(entry);
    fileInfo.isDirectory = (archive_entry_filetype(entry) != AE_IFREG);

    if (fileInfo.isDirectory) {
        archive_read_data_skip(m_archive);
        return readNextHeader(fileInfo);
    }

    return true; // Мы остановились на файле, данные которого еще не прочитаны
}

QByteArray ArchiveHandler::readCurrentData() {
    QByteArray content;
    if (!m_isOpen || !m_archive) return content;

    // Читаем данные для ТЕКУЩЕГО заголовка (который мы получили через readNextHeader)
    const void *buff;
    size_t size;
    la_int64_t offset;

    // Определяем размер заранее, если известно
    // (В идеале размер должен быть передан или взят из entry,
    // но здесь для простоты будем аппендить, либо использовать buffer)

    // Более эффективный способ:
    //struct archive_entry *current_entry;
    // Примечание: в libarchive нет функции "get current entry" в публичном API напрямую
    // без сохранения указателя. Поэтому лучше передавать размер из readNextHeader.
    // Но для упрощения используем стандартный цикл чтения блока.

    while (true) {
        int r = archive_read_data_block(m_archive, &buff, &size, &offset);
        if (r == ARCHIVE_EOF) break;
        if (r != ARCHIVE_OK) break;
        content.append(static_cast<const char*>(buff), size);
    }

    return content;
}
