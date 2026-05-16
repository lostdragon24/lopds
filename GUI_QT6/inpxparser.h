#ifndef INPXPARSER_H
#define INPXPARSER_H

#include <QString>
#include <QVector>
#include <QHash>
#include <QSqlDatabase>
#include <functional>

struct InpxBookMeta {
    QString title;
    QString author;
    QString series;
    int seriesNumber = 0;
    QString genre;
    QString language;
    QString publisher;
    int year = 0;
    qint64 fileSize = 0;
    QString fileName;
    QString fileExt;
    QString archiveName;
    QString inpFileName;
};

enum FieldType {
    FieldNone,
    FieldAuthor,
    FieldGenre,
    FieldTitle,
    FieldSeries,
    FieldSeriesNumber,
    FieldFile,
    FieldSize,
    FieldExt,
    FieldLang,
    FieldDate
};

struct ImportContext {
    QVector<FieldType> fields;
    int useStoredFolder = 0;
    int genresType = 0;
};

class InpxParser
{
public:
    InpxParser(QSqlDatabase database);

    void setProgressCallback(std::function<void(int, const QString&)> callback);
    bool importInpxCollection(const QString &inpxFilePath, const QString &booksDir);

    // Оптимизированная версия
    bool importInpxCollectionOptimized(const QString &inpxFilePath, const QString &booksDir,
                                       QSqlQuery &insertQuery, QSet<QString> &existingBooksCache);


    void setAbortFlag(bool *abort) { m_abort = abort; }

    int m_insertedCount = 0;
    int m_skippedCount = 0;
    int m_updateCount = 0;


private:
    QSqlDatabase m_database;
    std::function<void(int, const QString&)> m_progressCallback;
    bool *m_abort = nullptr;

    // Вспомогательные методы
    void getInpxFields(const QString &structureInfo, ImportContext &ctx);
    bool parseInpData(const QString &line, const ImportContext &ctx, InpxBookMeta &meta);
    QString cleanAuthorName(const QString &authorStr);
    bool insertBookToDatabase(const InpxBookMeta &meta, const QString &booksDir);

    QByteArray readFileFromArchive(const QString &archivePath, const QString &internalPath);

    // Новые методы для оптимизированной версии
    void processInpFile(const QString &inpFilePath, const QString &archivePath,
                                    const QString &booksDir, const ImportContext &ctx,
                                    QSqlQuery &insertQuery);



    QSet<QString> m_existingBooksCache;

};

#endif // INPXPARSER_H
