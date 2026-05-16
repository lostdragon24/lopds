#ifndef BOOKPARSER_H
#define BOOKPARSER_H

#include <QString>
#include <QHash>
#include <QByteArray>
#include <QBuffer>
#include <QDebug>

struct BookMeta {
    QString title;
    QString author;
    QString series;
    int seriesNumber = 0;
    QString genre;
    QString language;
    QString publisher;
    int year = 0;
    QString description;
    QString isbn;
    QString annotation;
};

class BookParser
{
public:
    BookParser();
    BookMeta parseMetadata(const QString &filePath);
    BookMeta parseMetadataFromMemory(const QByteArray &data, const QString &fileExtension);
    BookMeta parseEpubFromMemory(const QByteArray &epubData);
    QString extractFromFileName(const QString &fileName);

private:
    BookMeta parseFb2(const QString &filePath);
    BookMeta parseFb2FromMemory(const QByteArray &data);
    BookMeta parseEpub(const QString &filePath);
    BookMeta parsePdf(const QString &filePath);

    QString findOpfPathInEpub(const QString &filePath);
    QString readFileFromEpub(const QString &archivePath, const QString &internalPath);
    QString parseContainerXml(const QString& containerContent);
    void parseOpfContent(const QString& opfContent, BookMeta& meta);

    QByteArray extractFileFromEpubData(const QByteArray &epubData, const QString &internalPath);
    QString findOpfPathInEpubData(const QByteArray &epubData);
    QString readFileFromEpubData(const QByteArray &epubData, const QString &internalPath);

    QHash<QString, QString> genreMap;
    void initGenreMap();

    QString currentEpubPath;
};

#endif // BOOKPARSER_H
