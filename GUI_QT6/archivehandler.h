#ifndef ARCHIVEHANDLER_H
#define ARCHIVEHANDLER_H

#include <QString>
#include <QVector>
#include <QByteArray>
#include <QCryptographicHash>
#include <QCache>

struct ArchiveFile {
    QString name;
    QString path;  // полный путь внутри архива
    qint64 size;
    bool isDirectory;
};

struct ArchiveInfo {
    QString path;
    qint64 size;
    qint64 fileCount;
    QByteArray hash;
    qint64 lastModified;
};

class ArchiveHandler
{
public:
    ArchiveHandler();
    ~ArchiveHandler();

    bool openArchive(const QString &archivePath);
    void closeArchive();
    void invalidateCache();  // Очистка кеша

    QVector<ArchiveFile> listFiles();
    QByteArray readFile(const QString &internalPath);
    QByteArray readFileCached(const QString &internalPath);  // С кешированием
    bool extractFile(const QString &internalPath, const QString &outputPath);

    QString getLastError() const { return m_lastError; }
    bool isOpen() const { return m_isOpen; }

    ArchiveInfo getArchiveInfo(const QString &archivePath);
    QByteArray calculateArchiveHash(const QString &archivePath);

    // Новые методы для быстрого итеративного сканирования
    bool readNextHeader(ArchiveFile &fileInfo); // Читает заголовок следующего файла
    QByteArray readCurrentData();               // Читает данные текущего файла (после readNextHeader)
    bool isSupportedFormat(const QString &fileName); // Вынесем проверку сюда

private:
    struct archive *m_archive;
    QString m_archivePath;
    QString m_lastError;
    bool m_isOpen;

    // Кеши
    QCache<QString, QByteArray> m_fileCache;      // Кеш прочитанных файлов (макс 50MB)
    QVector<ArchiveFile> m_cachedFileList;        // Кеш списка файлов
    bool m_hasCachedFileList;

    void setError(const QString &error);
    void clearCache();
};

#endif // ARCHIVEHANDLER_H
