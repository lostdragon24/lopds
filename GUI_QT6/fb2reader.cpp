#include "fb2reader.h"
#include <QDebug>
#include <QFontDatabase>
#include <QHBoxLayout>
#include <QApplication>
#include <QRegularExpression>
#include <QTextDocumentFragment>
#include <QBuffer>
#include <QDesktopServices>

FB2Reader::FB2Reader(QWidget *parent)
    : QMainWindow(parent)
    , textBrowser(nullptr)
    , fileMenu(nullptr)
    , viewMenu(nullptr)
    , openAction(nullptr)
    , saveAction(nullptr)
    , zoomInAction(nullptr)
    , zoomOutAction(nullptr)
    , resetZoomAction(nullptr)
    , fontComboBox(nullptr)
    , fontSizeSpinBox(nullptr)
    , lineSpacingSpinBox(nullptr)
    , colorSchemeComboBox(nullptr)
    , currentFont("Arial")
    , currentFontSize(14)
    , currentLineSpacing(150)
    , isContentLoaded(false)
{
    setupUI();
    setupToolbar();
    initColorSchemes();
    applyCurrentStyles();
}

void FB2Reader::initColorSchemes()
{
    // Определяем цветовые схемы
    colorSchemes["Светлая"] = QColor("#ffffff");
    colorSchemes["Темная"] = QColor("#1e1e1e");
    colorSchemes["Сепия"] = QColor("#fbf0d9");
    colorSchemes["Зеленая"] = QColor("#e8f5e8");

    colorSchemeStyles["Светлая"] =
        "body { background-color: #ffffff; color: #333333; } "
        "a { color: #0066cc; } "
        "blockquote { border-left: 3px solid #cccccc; padding-left: 20px; }";

    colorSchemeStyles["Темная"] =
        "body { background-color: #1e1e1e; color: #d4d4d4; } "
        "a { color: #4a9eff; } "
        "blockquote { border-left: 3px solid #404040; padding-left: 20px; }";

    colorSchemeStyles["Сепия"] =
        "body { background-color: #fbf0d9; color: #5c4b37; } "
        "a { color: #8b6914; } "
        "blockquote { border-left: 3px solid #d4c5a9; padding-left: 20px; }";

    colorSchemeStyles["Зеленая"] =
        "body { background-color: #e8f5e8; color: #2d5016; } "
        "a { color: #2e7d32; } "
        "blockquote { border-left: 3px solid #a5d6a7; padding-left: 20px; }";
}

void FB2Reader::setupUI()
{
    // Создание текстового браузера (вместо QTextEdit)
    textBrowser = new QTextBrowser(this);
    textBrowser->setReadOnly(true);
    textBrowser->setLineWrapMode(QTextBrowser::WidgetWidth);
    textBrowser->setOpenLinks(false); // Теперь это работает
    textBrowser->setOpenExternalLinks(false);
    connect(textBrowser, &QTextBrowser::anchorClicked, this, &FB2Reader::onAnchorClicked); // Теперь сигнал доступен
    setCentralWidget(textBrowser);

    // Создание меню
    fileMenu = menuBar()->addMenu("Файл");
    viewMenu = menuBar()->addMenu("Вид");

    // Действие "Открыть"
    openAction = new QAction("Открыть FB2", this);
    openAction->setShortcut(QKeySequence::Open);
    connect(openAction, &QAction::triggered, this, &FB2Reader::openFile);
    fileMenu->addAction(openAction);

    // Действие "Сохранить как текст"
    saveAction = new QAction("Сохранить как текст", this);
    saveAction->setShortcut(QKeySequence::Save);
    connect(saveAction, &QAction::triggered, [this]() {
        QString fileName = QFileDialog::getSaveFileName(
            this,
            "Сохранить как текст",
            "",
            "Текстовые файлы (*.txt);;HTML файлы (*.html)"
            );

        if (!fileName.isEmpty()) {
            QFile file(fileName);
            if (file.open(QIODevice::WriteOnly | QIODevice::Text)) {
                QTextStream stream(&file);
                if (fileName.endsWith(".html", Qt::CaseInsensitive)) {
                    stream << textBrowser->toHtml();
                } else {
                    stream << textBrowser->toPlainText();
                }
                file.close();
                QMessageBox::information(this, "Успех", "Файл сохранен: " + fileName);
            } else {
                QMessageBox::warning(this, "Ошибка", "Не удалось сохранить файл");
            }
        }
    });
    fileMenu->addAction(saveAction);

    fileMenu->addSeparator();

    // Действия масштабирования
    zoomInAction = new QAction("Увеличить шрифт", this);
    zoomInAction->setShortcut(QKeySequence::ZoomIn);
    connect(zoomInAction, &QAction::triggered, this, &FB2Reader::zoomIn);
    viewMenu->addAction(zoomInAction);

    zoomOutAction = new QAction("Уменьшить шрифт", this);
    zoomOutAction->setShortcut(QKeySequence::ZoomOut);
    connect(zoomOutAction, &QAction::triggered, this, &FB2Reader::zoomOut);
    viewMenu->addAction(zoomOutAction);

    resetZoomAction = new QAction("Сбросить масштаб", this);
    resetZoomAction->setShortcut(QKeySequence("Ctrl+0"));
    connect(resetZoomAction, &QAction::triggered, this, &FB2Reader::resetZoom);
    viewMenu->addAction(resetZoomAction);

    // Настройки окна
    setWindowTitle("FB2 Reader");
    resize(1000, 700);
}

void FB2Reader::setupToolbar()
{
    // Создаем панель инструментов для настроек отображения
    QToolBar *formatToolbar = addToolBar("Форматирование");
    formatToolbar->setMovable(false);

    // Выбор шрифта
    formatToolbar->addWidget(new QLabel(" Шрифт: ", this));
    fontComboBox = new QFontComboBox(this);
    fontComboBox->setFontFilters(QFontComboBox::ScalableFonts);
    fontComboBox->setCurrentFont(currentFont);
    connect(fontComboBox, &QFontComboBox::currentFontChanged, this, &FB2Reader::changeFont);
    formatToolbar->addWidget(fontComboBox);

    // Размер шрифта
    formatToolbar->addWidget(new QLabel(" Размер: ", this));
    fontSizeSpinBox = new QSpinBox(this);
    fontSizeSpinBox->setRange(8, 72);
    fontSizeSpinBox->setValue(currentFontSize);
    fontSizeSpinBox->setSuffix(" пт");
    connect(fontSizeSpinBox, QOverload<int>::of(&QSpinBox::valueChanged), this, &FB2Reader::changeFontSize);
    formatToolbar->addWidget(fontSizeSpinBox);

    // Межстрочный интервал
    formatToolbar->addWidget(new QLabel(" Интервал: ", this));
    lineSpacingSpinBox = new QSpinBox(this);
    lineSpacingSpinBox->setRange(100, 300);
    lineSpacingSpinBox->setValue(currentLineSpacing);
    lineSpacingSpinBox->setSuffix(" %");
    connect(lineSpacingSpinBox, QOverload<int>::of(&QSpinBox::valueChanged), this, &FB2Reader::changeLineSpacing);
    formatToolbar->addWidget(lineSpacingSpinBox);

    // Цветовая схема
    formatToolbar->addWidget(new QLabel(" Тема: ", this));
    colorSchemeComboBox = new QComboBox(this);
    colorSchemeComboBox->addItems(colorSchemes.keys());
    connect(colorSchemeComboBox, QOverload<int>::of(&QComboBox::currentIndexChanged),
            [this](int index) {
                changeColorScheme(colorSchemeComboBox->itemText(index));
            });
    formatToolbar->addWidget(colorSchemeComboBox);

    formatToolbar->addSeparator();

    // Кнопки масштабирования
    QAction *zoomOutBtn = formatToolbar->addAction("A-");
    zoomOutBtn->setToolTip("Уменьшить шрифт");
    connect(zoomOutBtn, &QAction::triggered, this, &FB2Reader::zoomOut);

    QAction *resetZoomBtn = formatToolbar->addAction("A○");
    resetZoomBtn->setToolTip("Сбросить масштаб");
    connect(resetZoomBtn, &QAction::triggered, this, &FB2Reader::resetZoom);

    QAction *zoomInBtn = formatToolbar->addAction("A+");
    zoomInBtn->setToolTip("Увеличить шрифт");
    connect(zoomInBtn, &QAction::triggered, this, &FB2Reader::zoomIn);
}

QString FB2Reader::parseFB2ToHtml(const QByteArray &content, QStringList &toc)
{
    QXmlStreamReader sr(content);
    QString html;
    QStringList stack;
    QStringList currentPath;
    bool inDescription = false;
    bool inNotes = false;
    QString noteId;
    QString noteText;
    QString currentImageId;
    QString currentImageType;
    QString currentImageData;
    bool inTitle = false;
    QString currentTitleText;
    QHash<QString, QString> imageCache;
    QHash<QString, QString> imagePlaceholders;

    int fontSize = 20;
    if (QSysInfo::productType() == "android") {
        fontSize *= 1.8;
    }

    html = QString("<!DOCTYPE HTML><html><head><meta charset=\"UTF-8\">"
                   "<style>body { font-size: %1px; font-family: '%2', sans-serif; "
                   "margin: 20px; line-height: 1.6; } "
                   "h1 { font-size: %3px; } "
                   "h2 { font-size: %4px; } "
                   "h3 { font-size: %5px; } "
                   "p { text-align: justify; margin: 0 0 8px 0; } "
                   ".poem { margin-left: 30px; font-style: italic; } "
                   ".note { font-size: 0.9em; color: #666; border: 1px solid #ccc; "
                   "padding: 10px; margin: 10px 0; border-radius: 5px; } "
                   ".annotation { background: #f5f5f5; padding: 15px; margin: 10px 0; "
                   "border-left: 4px solid #999; } "
                   ".back-link { font-size: 0.8em; } "
                   "img { max-width: 100%%; height: auto; display: block; margin: 10px auto; }"
                   "table { border-collapse: collapse; margin: 10px auto; }"
                   "td, th { border: 1px solid #ccc; padding: 5px; }"
                   "</style></head><body>")
               .arg(fontSize)
               .arg(currentFont.family())
               .arg(int(fontSize * 1.5))
               .arg(int(fontSize * 1.3))
               .arg(int(fontSize * 1.1));

    // Сохраняем весь текст и изображения в правильном порядке
    QStringList contentParts;

    while (!sr.atEnd()) {
        switch (sr.readNext()) {
        case QXmlStreamReader::StartElement: {
            QString name = sr.name().toString();
            currentPath.append(name);
            stack.append(name);

            if (name == "description") {
                inDescription = true;
                break;
            }

            if (inDescription) {
                break;
            }

            if (name == "body") {
                if (sr.attributes().hasAttribute("name") &&
                    sr.attributes().value("name").toString() == "notes") {
                    inNotes = true;
                }
                break;
            }

            if (name == "title" && currentPath.contains("section")) {
                currentTitleText = "";
                inTitle = true;
                html += QString("<h2>");
                toc.append("");
                break;
            }

            if (name == "subtitle") {
                html += "<h3>";
                break;
            }

            if (name == "p") {
                html += "<p>";
                break;
            }

            if (name == "empty-line") {
                html += "<br/>";
                break;
            }

            if (name == "annotation") {
                html += "<div class=\"annotation\">";
                break;
            }

            if (name == "strong") {
                html += "<strong>";
                break;
            }

            if (name == "emphasis") {
                html += "<em>";
                break;
            }

            if (name == "strikethrough") {
                html += "<strike>";
                break;
            }

            if (name == "sup") {
                html += "<sup>";
                break;
            }

            if (name == "sub") {
                html += "<sub>";
                break;
            }

            if (name == "code") {
                html += "<code>";
                break;
            }

            if (name == "cite") {
                html += "<cite>";
                break;
            }

            if (name == "v") {
                html += "<div class=\"poem\">";
                break;
            }

            if (name == "poem" || name == "stanza") {
                html += "<div class=\"poem-block\">";
                break;
            }

            if (name == "epigraph") {
                html += "<blockquote>";
                break;
            }

            if (name == "text-author") {
                html += "<p style=\"text-align: right; font-style: italic;\">";
                break;
            }

            if (name == "date") {
                html += "<p style=\"text-align: right;\">";
                break;
            }

            if (name == "image") {
                QString href;
                const QXmlStreamAttributes &attrs = sr.attributes();
                for (const QXmlStreamAttribute &attr : attrs) {
                    QString attrName = attr.name().toString();
                    if (attrName == "href" || attrName.endsWith(":href")) {
                        href = attr.value().toString();
                        break;
                    }
                }

                if (!href.isEmpty() && href.startsWith("#")) {
                    QString imageId = href.mid(1);
                    // Вставляем плейсхолдер прямо в HTML
                    QString placeholder = QString("__IMAGE_%1__").arg(imageId);
                    imagePlaceholders[imageId] = placeholder;
                    html += placeholder;
                    qDebug() << "Added image placeholder for:" << imageId;
                }
                break;
            }

            if (name == "binary") {
                currentImageId = "";
                currentImageType = "";
                currentImageData = "";

                const QXmlStreamAttributes &attrs = sr.attributes();
                for (const QXmlStreamAttribute &attr : attrs) {
                    QString attrName = attr.name().toString();
                    if (attrName == "id") {
                        currentImageId = attr.value().toString();
                    } else if (attrName == "content-type") {
                        currentImageType = attr.value().toString();
                    }
                }
                break;
            }

            if (name == "a") {
                QString href;
                const QXmlStreamAttributes &attrs = sr.attributes();
                for (const QXmlStreamAttribute &attr : attrs) {
                    QString attrName = attr.name().toString();
                    if (attrName == "href" || attrName.endsWith(":href")) {
                        href = attr.value().toString();
                        break;
                    }
                }
                if (!href.isEmpty()) {
                    html += QString("<a href=\"%1\">").arg(href);
                }
                break;
            }

            if (name == "section" && inNotes) {
                const QXmlStreamAttributes &attrs = sr.attributes();
                for (const QXmlStreamAttribute &attr : attrs) {
                    if (attr.name().toString() == "id") {
                        noteId = attr.value().toString();
                        noteText = "";
                        break;
                    }
                }
                break;
            }

            break;
        }

        case QXmlStreamReader::EndElement: {
            QString name = sr.name().toString();
            stack.removeLast();

            if (name == "description") {
                inDescription = false;
                break;
            }

            if (inDescription) {
                break;
            }

            if (name == "title" && currentPath.contains("section")) {
                html += "</h2>";
                inTitle = false;
                if (!toc.isEmpty()) {
                    QString cleanTitle = currentTitleText.trimmed();
                    cleanTitle.remove(QRegularExpression("<[^>]*>"));
                    toc.replace(toc.size() - 1, cleanTitle);
                }
                currentPath.removeAll("title");
                break;
            }

            if (name == "subtitle") {
                html += "</h3>";
                break;
            }

            if (name == "p") {
                html += "</p>";
                break;
            }

            if (name == "annotation") {
                html += "</div>";
                break;
            }

            if (name == "strong") {
                html += "</strong>";
                break;
            }

            if (name == "emphasis") {
                html += "</em>";
                break;
            }

            if (name == "strikethrough") {
                html += "</strike>";
                break;
            }

            if (name == "sup") {
                html += "</sup>";
                break;
            }

            if (name == "sub") {
                html += "</sub>";
                break;
            }

            if (name == "code") {
                html += "</code>";
                break;
            }

            if (name == "cite") {
                html += "</cite>";
                break;
            }

            if (name == "v") {
                html += "</div>";
                break;
            }

            if (name == "poem" || name == "stanza") {
                html += "</div>";
                break;
            }

            if (name == "epigraph") {
                html += "</blockquote>";
                break;
            }

            if (name == "text-author" || name == "date") {
                html += "</p>";
                break;
            }

            if (name == "a") {
                html += "</a>";
                break;
            }

            if (name == "binary") {
                if (!currentImageId.isEmpty() && !currentImageData.isEmpty()) {
                    QString imageType = currentImageType;
                    if (imageType.isEmpty()) {
                        if (currentImageData.startsWith("/9j/")) {
                            imageType = "image/jpeg";
                        } else if (currentImageData.startsWith("iVBORw0KGgo")) {
                            imageType = "image/png";
                        } else {
                            imageType = "image/jpeg";
                        }
                    }

                    QString imgTag = QString("<p align=\"center\"><img src=\"data:%1;base64,%2\" alt=\"Изображение\"/></p>")
                                         .arg(imageType)
                                         .arg(currentImageData);
                    imageCache[currentImageId] = imgTag;
                    qDebug() << "Cached image:" << currentImageId << "type:" << imageType << "size:" << currentImageData.size();
                }
                currentImageId = "";
                currentImageType = "";
                currentImageData = "";
                break;
            }

            if (name == "section" && inNotes) {
                if (!noteId.isEmpty() && !noteText.isEmpty()) {
                    html += QString("<div class=\"note\" id=\"%1\"><strong>Примечание:</strong> %2 "
                                    "<a href=\"#%1_back\">[назад]</a></div>")
                                .arg(noteId)
                                .arg(noteText.trimmed());
                    noteId = "";
                    noteText = "";
                }
                break;
            }

            if (name == "body" && inNotes) {
                inNotes = false;
                break;
            }

            if (!currentPath.isEmpty() && currentPath.last() == name) {
                currentPath.removeLast();
            }
            break;
        }

        case QXmlStreamReader::Characters: {
            QString text = sr.text().toString();

            if (inDescription) {
                break;
            }

            if (!currentImageId.isEmpty()) {
                QString cleanedText = text.trimmed();
                if (!cleanedText.isEmpty()) {
                    currentImageData += cleanedText;
                }
                break;
            }

            if (text.trimmed().isEmpty()) {
                break;
            }

            if (inNotes && currentPath.contains("section")) {
                noteText += text;
                break;
            }

            if (inTitle) {
                currentTitleText += text;
                html += text;
                break;
            }

            html += text;
            break;
        }

        default:
            break;
        }
    }

    // ЗАМЕНЯЕМ ПЛЕЙСХОЛДЕРЫ НА РЕАЛЬНЫЕ ИЗОБРАЖЕНИЯ
    qDebug() << "=== REPLACING IMAGE PLACEHOLDERS ===";
    qDebug() << "Placeholders count:" << imagePlaceholders.size();
    qDebug() << "Cache count:" << imageCache.size();

    for (auto it = imagePlaceholders.begin(); it != imagePlaceholders.end(); ++it) {
        QString imageId = it.key();
        QString placeholder = it.value();
        QString imgTag = imageCache.value(imageId, "");

        if (!imgTag.isEmpty()) {
            // Заменяем плейсхолдер на изображение
            html.replace(placeholder, imgTag);
            qDebug() << "Replaced placeholder for:" << imageId;
        } else {
            // Удаляем плейсхолдер
            html.replace(placeholder, "");
            qDebug() << "Image not found, removed placeholder for:" << imageId;
        }
    }

    // Удаляем все оставшиеся плейсхолдеры (на случай, если что-то пропустили)
    QRegularExpression placeholderRegex("__IMAGE_[^_]*__");
    html.replace(placeholderRegex, "");

    qDebug() << "Images processed - placeholders:" << imagePlaceholders.size()
             << "cached:" << imageCache.size()
             << "HTML contains 'data:image':" << html.contains("data:image");

    // Проверяем, есть ли текст в HTML
    qDebug() << "HTML contains text content:" << html.contains("<p>") << html.contains("</p>");

    html += "</body></html>";

    if (sr.hasError()) {
        qDebug() << "XML parsing error:" << sr.errorString();
        return QString();
    }

    return html;
}

bool FB2Reader::loadFB2Content(const QByteArray &content, const QString &title)
{
    QApplication::setOverrideCursor(Qt::WaitCursor);

    currentTitle = title;
    QStringList toc;

    QString html = parseFB2ToHtml(content, toc);

    if (html.isEmpty()) {
        QApplication::restoreOverrideCursor();
        QMessageBox::warning(this, "Ошибка", "Не удалось разобрать FB2 файл");
        return false;
    }

    // Сохраняем для отладки
    qDebug() << "HTML length:" << html.length();
    qDebug() << "Contains data:image:" << html.contains("data:image");
    qDebug() << "HTML preview (first 500 chars):" << html.left(500);

    // Проверяем, есть ли изображения в HTML
    if (html.contains("data:image")) {
        qDebug() << "Images found in HTML!";
    } else {
        qDebug() << "WARNING: No images found in HTML after processing!";
    }

    currentHtmlContent = html;
    currentToc = toc;
    isContentLoaded = true;

    updateContent();

    if (!title.isEmpty()) {
        setWindowTitle(QString("FB2 Reader - %1").arg(title));
    }

    QApplication::restoreOverrideCursor();
    return true;
}

void FB2Reader::updateContent()
{
    if (!isContentLoaded || currentHtmlContent.isEmpty()) {
        return;
    }

    // Сохраняем позицию прокрутки и курсора
    int scrollValue = textBrowser->verticalScrollBar()->value();
    QTextCursor cursor = textBrowser->textCursor();
    int cursorPos = cursor.position();

    // Берем базовый HTML
    QString styledHtml = currentHtmlContent;

    // Определяем цвета для текущей схемы
    QColor bgColor, textColor, linkColor;
    if (currentColorScheme == "Темная") {
        bgColor = colorSchemes["Темная"];
        textColor = QColor("#e0e0e0");
        linkColor = QColor("#4a9eff");
    } else if (currentColorScheme == "Сепия") {
        bgColor = colorSchemes["Сепия"];
        textColor = QColor("#5c4b37");
        linkColor = QColor("#8b6914");
    } else if (currentColorScheme == "Зеленая") {
        bgColor = colorSchemes["Зеленая"];
        textColor = QColor("#2d5016");
        linkColor = QColor("#2e7d32");
    } else { // Светлая
        bgColor = colorSchemes["Светлая"];
        textColor = QColor("#333333");
        linkColor = QColor("#0066cc");
    }

    // Формируем полный CSS
    QString fullCss = QString(
                          "body { "
                          "    font-family: '%1'; "
                          "    font-size: %2px; "
                          "    line-height: %3%; "
                          "    background-color: %4; "
                          "    color: %5; "
                          "    margin: 30px; "
                          "    padding: 0; "
                          "} "
                          "a { color: %6; } "
                          "h1 { font-size: %7px; } "
                          "h2 { font-size: %8px; } "
                          "h3 { font-size: %9px; } "
                          "p { text-align: justify; margin: 0 0 10px 0; } "
                          ".poem { margin-left: 30px; font-style: italic; } "
                          ".note { "
                          "    font-size: 0.9em; "
                          "    color: %5; "
                          "    border: 1px solid #ccc; "
                          "    padding: 10px; "
                          "    margin: 10px 0; "
                          "    border-radius: 5px; "
                          "    background-color: %10; "
                          "} "
                          ".annotation { "
                          "    background: %10; "
                          "    padding: 15px; "
                          "    margin: 10px 0; "
                          "    border-left: 4px solid #999; "
                          "} "
                          "img { max-width: 100%%; height: auto; display: block; margin: 10px auto; } "
                          "table { border-collapse: collapse; margin: 10px auto; } "
                          "td, th { border: 1px solid #ccc; padding: 5px; } "
                          "blockquote { "
                          "    border-left: 3px solid %6; "
                          "    padding-left: 20px; "
                          "    margin: 10px 0; "
                          "} "
                          ".back-link { font-size: 0.8em; } "
                          )
                          .arg(currentFont.family())          // %1 - шрифт
                          .arg(currentFontSize)               // %2 - размер шрифта
                          .arg(currentLineSpacing)            // %3 - межстрочный интервал
                          .arg(bgColor.name())                // %4 - цвет фона
                          .arg(textColor.name())              // %5 - цвет текста
                          .arg(linkColor.name())              // %6 - цвет ссылок
                          .arg(int(currentFontSize * 1.5))    // %7 - размер h1
                          .arg(int(currentFontSize * 1.3))    // %8 - размер h2
                          .arg(int(currentFontSize * 1.1))    // %9 - размер h3
                          .arg(bgColor.lighter(110).name());  // %10 - фон для аннотаций и заметок

    // Заменяем или добавляем стили
    QRegularExpression styleRegex("<style[^>]*>([^<]*)</style>");
    QRegularExpressionMatch match = styleRegex.match(styledHtml);

    if (match.hasMatch()) {
        // Заменяем содержимое существующего тега <style>
        styledHtml.replace(match.capturedStart(1), match.capturedLength(1), fullCss);
    } else {
        // Добавляем новый тег <style>
        styledHtml.replace("<head>", "<head><style>" + fullCss + "</style>");
    }

    // Удаляем старые inline-стили у body
    QRegularExpression bodyStyleRegex("<body[^>]*style\\s*=\\s*\"[^\"]*\"");
    styledHtml.replace(bodyStyleRegex, "<body");

    // Добавляем новые inline-стили к body
    QString bodyStyle = QString("style=\"font-family: '%1'; font-size: %2px; line-height: %3%; background-color: %4; color: %5;\"")
                            .arg(currentFont.family())
                            .arg(currentFontSize)
                            .arg(currentLineSpacing)
                            .arg(bgColor.name())
                            .arg(textColor.name());

    styledHtml.replace("<body>", "<body " + bodyStyle + ">");

    // Устанавливаем HTML
    textBrowser->setHtml(styledHtml);

    // Восстанавливаем позицию
    QTextDocument *doc = textBrowser->document();
    if (doc && cursorPos < doc->characterCount()) {
        QTextCursor newCursor(doc);
        newCursor.setPosition(cursorPos);
        textBrowser->setTextCursor(newCursor);
    }
    textBrowser->verticalScrollBar()->setValue(scrollValue);

    // Принудительно обновляем
    textBrowser->update();
}

void FB2Reader::applyCurrentStyles()
{
    qDebug() << "=== APPLYING STYLES ===";
    qDebug() << "Font:" << currentFont.family() << "Size:" << currentFontSize << "Line spacing:" << currentLineSpacing;
    qDebug() << "Color scheme:" << currentColorScheme;

    if (isContentLoaded) {
        updateContent();
    }

    // Применяем цвета к фону редактора
    QColor bgColor = colorSchemes.value(currentColorScheme, colorSchemes["Светлая"]);
    QPalette palette = textBrowser->palette();
    palette.setColor(QPalette::Base, bgColor);
    textBrowser->setPalette(palette);

    textBrowser->update();
}

void FB2Reader::changeFont(const QFont &font)
{
    currentFont = font;
    qDebug() << "Changing font to:" << font.family();

    // Обновляем комбобокс
    if (fontComboBox && fontComboBox->currentFont() != font) {
        fontComboBox->setCurrentFont(font);
    }

    applyCurrentStyles();
}

void FB2Reader::changeFontSize(int size)
{
    currentFontSize = size;
    qDebug() << "Changing font size to:" << size;

    // Обновляем спинбокс
    if (fontSizeSpinBox && fontSizeSpinBox->value() != size) {
        fontSizeSpinBox->blockSignals(true);
        fontSizeSpinBox->setValue(size);
        fontSizeSpinBox->blockSignals(false);
    }

    applyCurrentStyles();
}

void FB2Reader::changeLineSpacing(int spacing)
{
    currentLineSpacing = spacing;
    qDebug() << "Changing line spacing to:" << spacing;

    // Обновляем спинбокс
    if (lineSpacingSpinBox && lineSpacingSpinBox->value() != spacing) {
        lineSpacingSpinBox->blockSignals(true);
        lineSpacingSpinBox->setValue(spacing);
        lineSpacingSpinBox->blockSignals(false);
    }

    applyCurrentStyles();
}

void FB2Reader::changeColorScheme(const QString &scheme)
{
    currentColorScheme = scheme;
    qDebug() << "Changing color scheme to:" << scheme;

    // Обновляем комбобокс
    if (colorSchemeComboBox && colorSchemeComboBox->currentText() != scheme) {
        colorSchemeComboBox->blockSignals(true);
        colorSchemeComboBox->setCurrentText(scheme);
        colorSchemeComboBox->blockSignals(false);
    }

    applyCurrentStyles();
}

void FB2Reader::zoomIn()
{
    fontSizeSpinBox->setValue(fontSizeSpinBox->value() + 1);
}

void FB2Reader::zoomOut()
{
    fontSizeSpinBox->setValue(fontSizeSpinBox->value() - 1);
}

void FB2Reader::resetZoom()
{
    fontSizeSpinBox->setValue(14);
}

void FB2Reader::onAnchorClicked(const QUrl &link)
{
    QString href = link.toString();
    if (href.isEmpty()) {
        return;
    }

    // Обработка внутренних ссылок
    if (href.startsWith("#")) {
        QString id = href.mid(1);
        textBrowser->scrollToAnchor(id);
    } else if (href.startsWith("image://")) {
        // Обработка изображений (можно добавить просмотр в полном размере)
        qDebug() << "Image clicked:" << href;
    } else {
        // Внешние ссылки - открываем в браузере
        QUrl url(href);
        if (url.isValid()) {
            QDesktopServices::openUrl(url);
        }
    }
}

void FB2Reader::openFile()
{
    QString filePath = QFileDialog::getOpenFileName(
        this,
        "Открыть FB2 файл",
        "",
        "FictionBook Files (*.fb2 *.fb2.zip);;Все файлы (*)"
        );

    if (!filePath.isEmpty()) {
        loadFB2File(filePath);
    }
}

void FB2Reader::loadFB2File(const QString &filePath)
{
    QFile file(filePath);
    if (!file.open(QIODevice::ReadOnly)) {
        QMessageBox::warning(this, "Ошибка", "Не удалось открыть файл: " + filePath);
        return;
    }

    QByteArray content = file.readAll();
    file.close();

    QString title = QFileInfo(filePath).fileName();
    loadFB2Content(content, title);
}
