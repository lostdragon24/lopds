#ifndef SCANNERDIALOG_H
#define SCANNERDIALOG_H

#include <QDialog>
#include <QThread>
#include <QSqlDatabase>
#include <QFileInfo>
#include <QCache>
#include <QSet>
#include "bookparser.h"

// Предварительные объявления
class BookParser;
class ArchiveHandler;
struct ArchiveFile;
struct BookMeta;
class InpxParser;
struct ImportContext;

namespace Ui {
class ScannerDialog;
}

class BookScanner : public QObject
{
    Q_OBJECT

public:
    explicit BookScanner(QSqlDatabase database, const QString &booksDir, bool useInpx, const QString &inpxFile);
    ~BookScanner();

    void cancelScanning();
    void forceRescanAllArchives();
    void setBatchSize(int size) { m_batchSize = size; }

 public slots:
    void startScanning();

signals:
    void progressChanged(int value);
    void statusChanged(const QString &status);
    void bookFound(const QString &title, const QString &author, const QString &filename);
    void finished();
    void errorOccurred(const QString &error);

private:
    struct PendingBook {
        BookMeta meta;
        QString filePath;
        QString archivePath;
        QString internalPath;
        qint64 fileSize;
        QString fileType;
        QString fileHash;
        QString publisher;
    };

    struct ArchiveCache {
        QString hash;
        qint64 lastModified;
        QVector<ArchiveFile> files;
    };


    int m_totalFiles;
    int m_addedBooks;
    int m_duplicateByHash;
    int m_parseErrors;
    int m_unsupportedFormat;

    QSqlDatabase m_database;
    QString m_booksDir;
    bool m_useInpx;
    QString m_inpxFile;
    bool m_abort;
    BookParser* m_parser;
    ArchiveHandler* m_archiveHandler;

    QVector<PendingBook> m_pendingBatch;
    int m_batchSize = 100;
    QCache<QString, ArchiveCache> m_archiveCache;

    QString calculateFileHash(const QString &filePath);
    QString calculateMemoryHash(const QByteArray &data);

    void saveBookMetadata(int bookId, const BookMeta& meta, const QString& filePath, const QString& archivePath, const QString& internalPath);
    bool shouldRescanArchive(const QString &archivePath);
    void updateArchiveInfo(const QString &archivePath, bool needsRescan = false);
    bool importInpx(const QString &inpxFile);
    void scanDirectory(const QString &path);
    void processFile(const QString &filePath);
    void processArchive(const QString &archivePath);
    bool isArchiveFile(const QString &filePath);

    void addToBatch(const PendingBook &book);
    void flushBatch();
    bool insertBookBatch();

    bool updateBookInDatabase(const BookMeta &meta, const ArchiveFile &file,
                              const QString &archivePath, const QFileInfo &archiveInfo,
                              int existingId);

    bool addBookToDatabase(const BookMeta &meta, const ArchiveFile &file,
                           const QString &archivePath, const QFileInfo &archiveInfo);


};

class ScannerDialog : public QDialog
{
    Q_OBJECT

public:
    explicit ScannerDialog(QSqlDatabase database, QWidget *parent = nullptr);
    ~ScannerDialog();

signals:
    void booksUpdated();

private slots:
    void on_btnBrowseBooksDir_clicked();
    void on_btnBrowseInpx_clicked();
    void on_btnStartScan_clicked();
    void on_btnStopScan_clicked();
    void onForceRescanClicked();
    void on_chkUseInpx_toggled(bool checked);
    void onProgressChanged(int value);
    void onStatusChanged(const QString &status);
    void onBookFound(const QString &title, const QString &author, const QString &filename);
    void onFinished();
    void onErrorOccurred(const QString &error);

private:
    Ui::ScannerDialog *ui;
    QSqlDatabase m_database;
    QThread *m_scannerThread;
    BookScanner *m_scanner;

    void updateControls(bool scanning);
};

#endif // SCANNERDIALOG_H
