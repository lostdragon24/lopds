#include "FormatEPub.h"
#include <QFile>
#include <QDir>
#include <QBuffer>
#include <QDebug>
#include <QTextStream>
#include <QRegularExpression>
#include <QUrl>

FormatEPub::FormatEPub()
{
    bookInfo = new QBRBook();
}

FormatEPub::~FormatEPub()
{
    delete bookInfo;
}

bool FormatEPub::loadFile(const QString& fileName, const QByteArray& fileData, const qbrunzip* zipData)
{
    Q_UNUSED(fileName);

    bookInfo->clear();
    bookInfo->metadata->FileFormat = getFormatTitle();

    if (!isZipFile(fileData)) {
        return false;
    }

    if (!zipData->isLoaded()) {
        return false;
    }

    if (!isValidFile(zipData)) {
        return false;
    }

    return parseFile(zipData);
}

QStringList FormatEPub::getExtensions()
{
    return QStringList("epub");
}

QString FormatEPub::getFormatTitle()
{
    return "Electronic Publication (EPUB)";
}

QBRBook* FormatEPub::getBook()
{
    return bookInfo;
}

bool FormatEPub::needUnzip()
{
    return true;
}

bool FormatEPub::isValidFile(const qbrunzip *zipData)
{
    if (!zipData->getFileData("mimetype").contains("application/epub+zip")) {
        return false;
    }

    if (!zipData->fileExists("META-INF/container.xml")) {
        return false;
    }

    return true;
}

bool FormatEPub::isZipFile(const QByteArray& data)
{
    return data.size() > 4 &&
           static_cast<unsigned char>(data[0]) == 0x50 &&
           static_cast<unsigned char>(data[1]) == 0x4B;
}

bool FormatEPub::QDomDocumentSetContent(QDomDocument* doc, const QByteArray& data)
{
    // НЕ используем UseNamespaceProcessing — иначе не найдём элементы
    // по простому имени (dc:title, dc:creator и т.д.)
    auto result = doc->setContent(data);
    if (result) {
        return true;
    }
    qDebug() << "Failed to parse XML";
    return false;
}

QString FormatEPub::cleanTitle(const QString& title)
{
    QString result = title;
    result.replace(QRegularExpression("\\s+"), " ");
    result = result.trimmed();
    return result.isEmpty() ? "..." : result;
}

QStringList FormatEPub::getRootFiles(const qbrunzip *zipData)
{
    QStringList rootFiles;
    QDomDocument containerXml;

    if (!QDomDocumentSetContent(&containerXml, zipData->getFileData("META-INF/container.xml"))) {
        qDebug() << "Can't read META-INF/container.xml";
        return rootFiles;
    }

    QDomElement rootFileNode = containerXml.firstChildElement("container")
                                   .firstChildElement("rootfiles")
                                   .firstChildElement("rootfile");

    while (!rootFileNode.isNull()) {
        if (rootFileNode.attribute("media-type", "") == "application/oebps-package+xml") {
            QString rootFilePath = rootFileNode.attribute("full-path", "");
            if (!rootFilePath.isEmpty()) {
                rootFiles.append(rootFilePath);
            }
        }
        rootFileNode = rootFileNode.nextSiblingElement("rootfile");
    }

    return rootFiles;
}

QStringList FormatEPub::getEncryptedFiles(const qbrunzip *zipData)
{
    QStringList encryptedFiles;
    if (!zipData->fileExists("META-INF/encryption.xml")) {
        return encryptedFiles;
    }

    QDomDocument encryptionXml;
    if (!QDomDocumentSetContent(&encryptionXml, zipData->getFileData("META-INF/encryption.xml"))) {
        qDebug() << "Can't read META-INF/encryption.xml";
        return encryptedFiles;
    }

    QDomElement encryptedData = encryptionXml.firstChildElement("encryption")
                                    .firstChildElement("EncryptedData");

    while (!encryptedData.isNull()) {
        QDomElement cipherReference = encryptedData.firstChildElement("CipherData")
        .firstChildElement("CipherReference");
        if (cipherReference.hasAttribute("URI")) {
            QString encryptedFile = cipherReference.attribute("URI", "");
            if (!encryptedFile.isEmpty()) {
                qDebug() << "Found encrypted object:" << encryptedFile;
                encryptedFiles.append(encryptedFile);
            }
        }
        encryptedData = encryptedData.nextSiblingElement("EncryptedData");
    }

    return encryptedFiles;
}

QString FormatEPub::expandFileName(const QString& baseFileName, QString expandableFileName)
{
    if (expandableFileName.startsWith("/")) {
        return expandableFileName.remove(0, 1);
    }

    QString expandedFileName = QFileInfo(baseFileName).dir().filePath(expandableFileName);
    expandedFileName = QDir::cleanPath(expandedFileName);

    if (expandedFileName.startsWith("./")) {
        return expandedFileName.remove(0, 2);
    }

    return expandedFileName;
}

QString FormatEPub::prepareLink(const QString& baseFileName, QString link)
{
    QUrl linkUrl(link);
    if (!linkUrl.scheme().isEmpty()) {
        return link;
    }
    else if (linkUrl.hasFragment()) {
        return QString("#%1").arg(linkUrl.fragment());
    }

    return QString("#file_%1").arg(expandFileName(baseFileName, link));
}

QString FormatEPub::prepareDataLink(const qbrunzip *zipData, QString dataFileName, const QStringList& encryptedFiles) const
{
    if (encryptedFiles.contains(dataFileName, Qt::CaseInsensitive)) {
        return EMPTYGIF;
    }

    QUrl dataFileUrl(dataFileName);

    if (QStringList({"http", "https"}).contains(dataFileUrl.scheme(), Qt::CaseInsensitive)) {
        return dataFileName;
    }

    if (!dataFileUrl.scheme().isEmpty()) {
        return EMPTYGIF;
    }

    QString contentType;
    if (dataFileName.endsWith(".png", Qt::CaseInsensitive)) {
        contentType = "image/png";
    }
    else if (dataFileName.endsWith(".gif", Qt::CaseInsensitive)) {
        contentType = "image/gif";
    }
    else if (dataFileName.endsWith(".webp", Qt::CaseInsensitive)) {
        contentType = "image/webp";
    }
    else if (dataFileName.endsWith(".jpg", Qt::CaseInsensitive) ||
             dataFileName.endsWith(".jpeg", Qt::CaseInsensitive)) {
        contentType = "image/jpeg";
    }
    else {
        return EMPTYGIF;
    }

    QByteArray imageData = zipData->getFileData(dataFileName);
    if (imageData.isEmpty()) {
        return EMPTYGIF;
    }

    return QString("data:%1;base64,%2").arg(contentType, QString::fromLatin1(imageData.toBase64()));
}

QDomNode FormatEPub::processXHTMLNode(const qbrunzip *zipData, const QString& xHTMLFileName,
                                      const QDomNode& currentNode, const QStringList& encryptedFiles)
{
    // Расширенный список разрешённых тегов
    const QList<QString> allowedTags = {
        "ul", "li", "p", "b", "i", "u", "s", "span", "pre", "strong", "em",
        "blockquote", "sub", "sup", "br", "hr", "a", "img",
        "table", "tr", "th", "td", "colgroup", "col", "thead", "tbody",
        "div", "section", "article", "h1", "h2", "h3", "h4", "h5", "h6"
    };

    const QList<QString> allowedAttributes = {"id", "name", "class", "style", "title"};

    const QHash<QString, QString> tagToClass = {
                                                {"body", "document_body"},
                                                {"h1", "doc_title"},
                                                {"h2", "doc_subtitle"},
                                                {"h3", "doc_subsubtitle"},
                                                };

    switch (currentNode.nodeType()) {
    case QDomNode::ElementNode: {
        QString returnTagName = "div";
        const QString currentNodeTag = currentNode.nodeName().toLower();

        // Сохраняем оригинальные теги, чтобы не терять структуру
        if (allowedTags.contains(currentNodeTag)) {
            returnTagName = currentNodeTag;
        }

        QDomElement returnValue = templateCreateElement(returnTagName);

        if (tagToClass.contains(currentNodeTag)) {
            returnValue.setAttribute("class", tagToClass.value(currentNodeTag));
        }

        // Обрабатываем ссылки
        if (returnTagName == "a") {
            if (currentNode.attributes().contains("href")) {
                const QString aHref = prepareLink(xHTMLFileName,
                                                  currentNode.attributes().namedItem("href").nodeValue());
                returnValue.setAttribute("href", aHref);
                returnValue.setAttribute("title", aHref);
            }
        }
        // Обрабатываем изображения
        else if (returnTagName == "img") {
            if (currentNode.attributes().contains("src")) {
                const QString imgSrc = currentNode.attributes().namedItem("src").nodeValue();
                const QString imgSrcFull = expandFileName(xHTMLFileName, imgSrc);
                returnValue.setAttribute("src", prepareDataLink(zipData, imgSrcFull, encryptedFiles));
                returnValue.setAttribute("alt", "Изображение");
            }
        }
        // Сохраняем атрибуты для всех тегов
        else {
            for (int i = 0; i < allowedAttributes.count(); i++) {
                const QString& attrName = allowedAttributes.at(i);
                if (currentNode.attributes().contains(attrName)) {
                    returnValue.setAttribute(attrName,
                                             currentNode.attributes().namedItem(attrName).nodeValue());
                }
            }
        }

        // Рекурсивно обрабатываем дочерние узлы
        if (currentNode.hasChildNodes()) {
            for (int i = 0; i < currentNode.childNodes().count(); i++) {
                QDomNode localNode = currentNode.childNodes().at(i);
                QDomNode processedNode = processXHTMLNode(zipData, xHTMLFileName, localNode, encryptedFiles);
                if (!processedNode.isNull()) {
                    returnValue.appendChild(processedNode);
                }
            }
        }

        return returnValue;
    }
    case QDomNode::TextNode: {
        // Сохраняем текст
        QString text = currentNode.nodeValue();
        if (!text.trimmed().isEmpty()) {
            return currentNode.cloneNode();
        }
        return QDomNode();
    }
    case QDomNode::EntityReferenceNode:
        return currentNode.cloneNode();
    default:
        break;
    }

    return QDomNode();
}

bool FormatEPub::processXHTMLFile(QDomNode *xHTMLFileData, const qbrunzip *zipData,
                                  const QString& xHTMLFileName, const QStringList& encryptedFiles)
{
    if (encryptedFiles.contains(xHTMLFileName, Qt::CaseInsensitive)) {
        return false;
    }

    QDomDocument xHTMLFile;
    if (!QDomDocumentSetContent(&xHTMLFile, zipData->getFileData(xHTMLFileName))) {
        qDebug() << "Can't parse" << xHTMLFileName;
        return false;
    }

    const QDomNodeList docBodies = xHTMLFile.elementsByTagName("body");

    QDomElement processResult = templateCreateElement("div");
    processResult.setAttribute("id", QString("file_%1").arg(xHTMLFileName.toHtmlEscaped()));

    for (int i = 0; i < docBodies.length(); i++) {
        QDomNode convertedNode = processXHTMLNode(zipData, xHTMLFileName, docBodies.at(i), encryptedFiles);
        processResult.appendChild(convertedNode);
    }

    xHTMLFileData->appendChild(processResult);
    return true;
}

void FormatEPub::processRootFileMetadata(const qbrunzip *zipData, const QString& rootFileName,
                                         const QDomDocument *rootFileXml,
                                         const QMap<QString,QDomElement> *manifestMap,
                                         const QStringList& encryptedFiles)
{
    // Ищем <package> по локальному имени (может быть с namespace)
    QDomElement packageNode = rootFileXml->documentElement();
    if (packageNode.localName() != "package") {
        packageNode = rootFileXml->firstChildElement("package");
    }
    if (packageNode.isNull()) return;

    // Ищем <metadata> — может быть просто "metadata" или с prefix
    QDomElement metadataNode;
    for (QDomElement child = packageNode.firstChildElement();
         !child.isNull();
         child = child.nextSiblingElement()) {
        if (child.localName() == "metadata") {
            metadataNode = child;
            break;
        }
    }
    if (metadataNode.isNull()) return;

    // Вспомогательная лямбда: ищет дочерний элемент по локальному имени
    auto findChild = [](const QDomElement& parent, const QString& localName) -> QDomElement {
        for (QDomElement child = parent.firstChildElement();
             !child.isNull();
             child = child.nextSiblingElement()) {
            if (child.localName() == localName) {
                return child;
            }
        }
        return QDomElement();
    };

    if (bookInfo->metadata->Title.isEmpty()) {
        QDomElement titleNode = findChild(metadataNode, "title");
        if (!titleNode.isNull()) {
            bookInfo->metadata->Title = cleanTitle(titleNode.text());
        }
    }

    if (bookInfo->metadata->Author.isEmpty()) {
        QDomElement creatorNode = findChild(metadataNode, "creator");
        if (!creatorNode.isNull()) {
            bookInfo->metadata->Author = cleanTitle(creatorNode.text());
        }
    }

    // Cover image - EPUB 2 way (meta name="cover")
    for (QDomElement child = metadataNode.firstChildElement();
         !child.isNull();
         child = child.nextSiblingElement()) {
        if (child.localName() == "meta" &&
            child.hasAttribute("name") &&
            child.attribute("name") == "cover" &&
            child.hasAttribute("content")) {
            QString coverItemId = child.attribute("content");
            if (manifestMap->contains(coverItemId)) {
                QString coverImageName = expandFileName(rootFileName,
                                                        manifestMap->value(coverItemId).attribute("href"));
                if (!encryptedFiles.contains(coverImageName)) {
                    QByteArray imageData = zipData->getFileData(coverImageName);
                    if (!imageData.isEmpty()) {
                        bookInfo->metadata->Cover.loadFromData(imageData);
                    }
                }
            }
            break;
        }
    }

    // Cover image - EPUB 3 way (properties="cover-image")
    if (bookInfo->metadata->Cover.isNull()) {
        for (auto it = manifestMap->begin(); it != manifestMap->end(); ++it) {
            QDomElement manifestItem = it.value();
            if (manifestItem.attribute("properties", "") == "cover-image") {
                QString coverImageName = expandFileName(rootFileName,
                                                        manifestItem.attribute("href"));
                if (!encryptedFiles.contains(coverImageName)) {
                    QByteArray imageData = zipData->getFileData(coverImageName);
                    if (!imageData.isEmpty()) {
                        bookInfo->metadata->Cover.loadFromData(imageData);
                    }
                }
                break;
            }
        }
    }
}

bool FormatEPub::processRootFile(QDomNode *returnValue, const qbrunzip *zipData,
                                 const QString& rootFileName, const QStringList& encryptedFiles)
{
    QDomDocument rootFileXml;
    if (encryptedFiles.contains(rootFileName, Qt::CaseInsensitive)) {
        return false;
    }

    if (!QDomDocumentSetContent(&rootFileXml, zipData->getFileData(rootFileName))) {
        qDebug() << "Can't read rootfile:" << rootFileName;
        return false;
    }

    QMap<QString, QDomElement> manifestMap;
    QString tocFileName;
    QString tocOldFileName;

    QDomElement manifestItem = rootFileXml.firstChildElement("package")
                                   .firstChildElement("manifest")
                                   .firstChildElement("item");

    while (!manifestItem.isNull()) {
        QString manifestItemFileId = manifestItem.attribute("id", "");
        QString manifestItemProperties = manifestItem.attribute("properties", "");

        if (manifestItemProperties.split(" ").contains("nav")) {
            tocFileName = expandFileName(rootFileName, manifestItem.attribute("href", ""));
        }

        QString manifestItemMediaType = manifestItem.attribute("media-type", "");
        if (manifestItemMediaType == "application/x-dtbncx+xml") {
            tocOldFileName = expandFileName(rootFileName, manifestItem.attribute("href"));
        }

        manifestMap[manifestItemFileId] = manifestItem;
        manifestItem = manifestItem.nextSiblingElement("item");
    }

    QDomElement spineItem = rootFileXml.firstChildElement("package")
                                .firstChildElement("spine")
                                .firstChildElement("itemref");

    while (!spineItem.isNull()) {
        QString manifestItemFileId = spineItem.attribute("idref", "");
        if (manifestMap.contains(manifestItemFileId)) {
            QDomElement item = manifestMap.value(manifestItemFileId);
            if (item.attribute("media-type", "") == "application/xhtml+xml") {
                QString manifestItemFileName = item.attribute("href", "");
                if (!processXHTMLFile(returnValue, zipData,
                                      expandFileName(rootFileName, manifestItemFileName),
                                      encryptedFiles)) {
                    return false;
                }
            }
        }
        spineItem = spineItem.nextSiblingElement("itemref");
    }

    if (!tocFileName.isEmpty()) {
        loadToc(zipData, tocFileName, rootFileName);
    }
    else if (!tocOldFileName.isEmpty()) {
        loadTocOld(zipData, tocOldFileName);
    }

    processRootFileMetadata(zipData, rootFileName, &rootFileXml, &manifestMap, encryptedFiles);

    return true;
}

void FormatEPub::loadToc(const qbrunzip *zipData, const QString& tocFileName, const QString& rootFileName)
{
    if (!zipData->fileExists(tocFileName)) {
        return;
    }

    QDomDocument tocFileXml;
    if (!QDomDocumentSetContent(&tocFileXml, zipData->getFileData(tocFileName))) {
        qDebug() << "Can't parse TOC:" << tocFileName;
        return;
    }

    QDomNodeList navList = tocFileXml.elementsByTagName("nav");
    for (int i = 0; i < navList.size(); i++) {
        QDomElement navItem = navList.at(i).toElement();
        if (navItem.attribute("epub:type", "") == "toc") {
            QDomElement oList = navItem.firstChildElement("ol");
            if (oList.isNull()) {
                continue;
            }

            QDomElement listItem = oList.firstChildElement("li");
            while (!listItem.isNull()) {
                loadTocItem(listItem, &bookInfo->metadata->Toc, rootFileName);
                listItem = listItem.nextSiblingElement("li");
            }
        }
    }
}

void FormatEPub::loadTocItem(const QDomElement& curItem, QList<QBRTocItem>* tocList, const QString& rootFileName)
{
    QBRTocItem tocItem;

    QDomElement itemA = curItem.firstChildElement("a");
    QDomElement itemSpan = curItem.firstChildElement("span");
    QDomElement oList = curItem.firstChildElement("ol");

    if (!itemSpan.isNull()) {
        tocItem.Title = cleanTitle(itemSpan.text());
    }

    if (!itemA.isNull()) {
        tocItem.Title = cleanTitle(itemA.text());
        QString itemHref = itemA.attribute("href", "");
        if (itemHref.contains("#")) {
            tocItem.Anchor = itemHref.split("#").at(1);
        }
        else {
            tocItem.Anchor = QString("file_%1").arg(expandFileName(rootFileName, itemHref));
        }
    }

    if (!oList.isNull()) {
        QDomElement listItem = oList.firstChildElement("li");
        while (!listItem.isNull()) {
            loadTocItem(listItem, &tocItem.Childs, rootFileName);
            listItem = listItem.nextSiblingElement("li");
        }
    }

    tocList->append(tocItem);
}

void FormatEPub::loadTocOld(const qbrunzip *zipData, const QString& tocFileName)
{
    if (!zipData->fileExists(tocFileName)) {
        return;
    }

    QDomDocument tocFileXml;
    if (!QDomDocumentSetContent(&tocFileXml, zipData->getFileData(tocFileName))) {
        qDebug() << "Can't parse old TOC:" << tocFileName;
        return;
    }

    QDomElement rootTocItem = tocFileXml.firstChildElement("ncx").firstChildElement("navMap");
    if (rootTocItem.isNull() || !rootTocItem.hasChildNodes()) {
        return;
    }

    loadTocOldItem(tocFileName, rootTocItem, &bookInfo->metadata->Toc);
}

void FormatEPub::loadTocOldItem(const QString& tocFileName, const QDomElement& curItem,
                                QList<QBRTocItem>* tocList)
{
    QList<QDomElement> srcTocList;

    QDomElement navPoint = curItem.firstChildElement("navPoint");
    while (!navPoint.isNull()) {
        srcTocList.append(navPoint);
        navPoint = navPoint.nextSiblingElement("navPoint");
    }

    for (const auto &i : srcTocList) {
        QBRTocItem tocItem;
        tocItem.Title = cleanTitle(i.firstChildElement("navLabel")
                                       .firstChildElement("text").text());
        if (tocItem.Title.isEmpty()) {
            tocItem.Title = "...";
        }

        QString tocItemAnchor = i.firstChildElement("content").attribute("src", "");
        if (tocItemAnchor.contains("#")) {
            tocItem.Anchor = tocItemAnchor.split('#').last();
        }
        else {
            tocItem.Anchor = QString("file_%1").arg(expandFileName(tocFileName, tocItemAnchor));
        }

        loadTocOldItem(tocFileName, i, &tocItem.Childs);
        tocList->append(tocItem);
    }
}

bool FormatEPub::parseFile(const qbrunzip *zipData)
{
    const QStringList rootFiles = getRootFiles(zipData);

    if (rootFiles.count() == 0) {
        return false;
    }

    const QStringList encryptedFiles = getEncryptedFiles(zipData);

    templateInit();

    for (int i = 0; i < rootFiles.count(); i++) {
        QDomElement rootFileData = templateCreateElement("div");
        rootFileData.setAttribute("id", QString("rootfile_%1").arg(i));
        if (!processRootFile(&rootFileData, zipData, rootFiles.at(i), encryptedFiles)) {
            return false;
        }
        templateBodyAppend(rootFileData);
    }

    templateSetMeta(bookInfo->metadata);
    bookInfo->html = templateAsString();

    return true;
}

// Template helpers
void FormatEPub::templateInit()
{
    m_templateDoc = QDomDocument();
    QDomElement html = m_templateDoc.createElement("html");
    m_templateDoc.appendChild(html);

    QDomElement head = m_templateDoc.createElement("head");
    html.appendChild(head);

    QDomElement meta = m_templateDoc.createElement("meta");
    meta.setAttribute("charset", "UTF-8");
    head.appendChild(meta);

    QDomElement style = m_templateDoc.createElement("style");
    style.appendChild(m_templateDoc.createTextNode(
        "body { font-family: Arial, sans-serif; font-size: 16px; line-height: 1.8; "
        "max-width: 800px; margin: 0 auto; padding: 20px; } "
        "img { max-width: 100%; height: auto; display: block; margin: 1em auto; } "
        "p { text-align: justify; margin-bottom: 1em; } "
        "h1, h2, h3, h4 { margin-top: 1.5em; margin-bottom: 0.5em; } "
        ".doc_title { font-size: 2em; font-weight: bold; } "
        ".doc_subtitle { font-size: 1.5em; font-weight: bold; } "
        ".doc_subsubtitle { font-size: 1.2em; font-weight: bold; } "
        ));
    head.appendChild(style);

    QDomElement body = m_templateDoc.createElement("body");
    html.appendChild(body);
    m_templateBody = body;
}

QDomElement FormatEPub::templateCreateElement(const QString& tagName)
{
    return m_templateDoc.createElement(tagName);
}

void FormatEPub::templateBodyAppend(const QDomElement& element)
{
    if (!m_templateBody.isNull()) {
        m_templateBody.appendChild(element);
    }
}

void FormatEPub::templateSetMeta(QBRMetadata* metadata)
{
    if (!metadata->Title.isEmpty()) {
        QDomElement titleDiv = m_templateDoc.createElement("div");
        titleDiv.setAttribute("class", "doc_title");
        titleDiv.appendChild(m_templateDoc.createTextNode(metadata->Title));
        m_templateBody.insertBefore(titleDiv, m_templateBody.firstChild());
    }

    if (!metadata->Author.isEmpty()) {
        QDomElement authorDiv = m_templateDoc.createElement("div");
        authorDiv.setAttribute("class", "doc_subtitle");
        authorDiv.appendChild(m_templateDoc.createTextNode(metadata->Author));
        m_templateBody.insertBefore(authorDiv, m_templateBody.firstChild());
    }
}

QString FormatEPub::templateAsString()
{
    return m_templateDoc.toString();
}
