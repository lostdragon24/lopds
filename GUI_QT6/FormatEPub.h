#ifndef FORMATEPUB_H
#define FORMATEPUB_H

#include <QString>
#include <QStringList>
#include <QByteArray>
#include <QDomDocument>
#include <QMap>
#include <QList>
#include <QPixmap>
#include <QUrl>
#include <QRegularExpression>

struct QBRTocItem {
    QString Title;
    QString Anchor;
    QList<QBRTocItem> Childs;
};

struct QBRMetadata {
    QString Title;
    QString Author;
    QPixmap Cover;
    QList<QBRTocItem> Toc;
    QString FileFormat;

    QBRMetadata() {}
};

struct QBRBook {
    QString html;
    QBRMetadata *metadata;

    QBRBook() {
        metadata = new QBRMetadata();
    }

    ~QBRBook() {
        delete metadata;
    }

    void clear() {
        html.clear();
        metadata->Title.clear();
        metadata->Author.clear();
        metadata->Cover = QPixmap();
        metadata->Toc.clear();
        metadata->FileFormat.clear();
    }
};

class qbrunzip {
public:
    virtual ~qbrunzip() = default;
    virtual bool isLoaded() const = 0;
    virtual QByteArray getFileData(const QString &fileName) const = 0;
    virtual bool fileExists(const QString &fileName) const = 0;
};

class FormatEPub
{
public:
    FormatEPub();
    ~FormatEPub();

    bool loadFile(const QString& fileName, const QByteArray& fileData, const qbrunzip* zipData);
    QStringList getExtensions();
    QString getFormatTitle();
    QBRBook* getBook();
    bool needUnzip();
    bool isValidFile(const qbrunzip *zipData);

private:
    static constexpr const char* EMPTYGIF = "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7";

    QStringList getRootFiles(const qbrunzip *zipData);
    QStringList getEncryptedFiles(const qbrunzip *zipData);
    QString expandFileName(const QString& baseFileName, QString expandableFileName);
    QString prepareLink(const QString& baseFileName, QString link);
    QString prepareDataLink(const qbrunzip *zipData, QString dataFileName, const QStringList& encryptedFiles) const;

    QDomNode processXHTMLNode(const qbrunzip *zipData, const QString& xHTMLFileName,
                              const QDomNode& currentNode, const QStringList& encryptedFiles);
    bool processXHTMLFile(QDomNode *xHTMLFileData, const qbrunzip *zipData,
                          const QString& xHTMLFileName, const QStringList& encryptedFiles);
    void processRootFileMetadata(const qbrunzip *zipData, const QString& rootFileName,
                                 const QDomDocument *rootFileXml, const QMap<QString,QDomElement> *manifestMap,
                                 const QStringList& encryptedFiles);
    bool processRootFile(QDomNode *returnValue, const qbrunzip *zipData,
                         const QString& rootFileName, const QStringList& encryptedFiles);

    void loadToc(const qbrunzip *zipData, const QString& tocFileName, const QString& rootFileName);
    void loadTocItem(const QDomElement& curItem, QList<QBRTocItem>* tocList, const QString& rootFileName);
    void loadTocOld(const qbrunzip *zipData, const QString& tocFileName);
    void loadTocOldItem(const QString& tocFileName, const QDomElement& curItem, QList<QBRTocItem>* tocList);

    bool parseFile(const qbrunzip *zipData);

    // Template helpers
    void templateInit();
    QDomElement templateCreateElement(const QString& tagName);
    void templateBodyAppend(const QDomElement& element);
    void templateSetMeta(QBRMetadata* metadata);
    QString templateAsString();

    // Helper
    bool QDomDocumentSetContent(QDomDocument* doc, const QByteArray& data);
    QString cleanTitle(const QString& title);
    bool isZipFile(const QByteArray& data);

    QBRBook* bookInfo;
    QDomDocument m_templateDoc;
    QDomElement m_templateBody;
};

#endif // FORMATEPUB_H
