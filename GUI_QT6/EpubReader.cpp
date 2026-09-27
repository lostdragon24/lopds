#include "EpubReader.h"

#include <QFile>
#include <QFileInfo>
#include <QFileDialog>
#include <QMessageBox>
#include <QProgressDialog>
#include <QApplication>
#include <QStatusBar>
#include <QRegularExpression>
#include <QDebug>
#include <QTemporaryFile>
#include <QDir>

// ============================================================
// КОНСТРУКТОР / ДЕСТРУКТОР
// ============================================================

EpubReader::EpubReader(QWidget *parent)
    : QMainWindow(parent)
    , m_webView(nullptr)
    , m_toolbar(nullptr)
    , m_fileMenu(nullptr)
    , m_viewMenu(nullptr)
    , m_themeMenu(nullptr)
    , m_tocWidget(nullptr)
    , m_tocDock(nullptr)
    , m_openAction(nullptr)
    , m_prevAction(nullptr)
    , m_nextAction(nullptr)
    , m_zoomInAction(nullptr)
    , m_zoomOutAction(nullptr)
    , m_resetZoomAction(nullptr)
    , m_tocAction(nullptr)
    , m_currentChapter(0)
    , m_zoomLevel(100)
    , m_theme(0)
    , m_isLoaded(false)
{
    setupUI();
    setupMenu();
    setupToolbar();
    initThemes();
    applyTheme();
}

EpubReader::~EpubReader()
{
    clearCache();
}

// ============================================================
// ИНИЦИАЛИЗАЦИЯ
// ============================================================

void EpubReader::initThemes()
{
    m_themes[0] = ThemeColors{"#ffffff", "#333333", "#0066cc", "#cce5ff"};
    m_themes[1] = ThemeColors{"#f4ecd8", "#4b3a26", "#8b6914", "#e8dcc8"};
    m_themes[2] = ThemeColors{"#1c1c1e", "#d8d8da", "#4a9eff", "#2a2a2e"};
}

void EpubReader::setupUI()
{
    setWindowTitle("EPUB Reader");
    resize(1000, 750);

    m_webView = new QWebEngineView(this);
    m_webView->settings()->setAttribute(QWebEngineSettings::JavascriptEnabled, true);
    m_webView->settings()->setAttribute(QWebEngineSettings::LocalStorageEnabled, true);
    m_webView->settings()->setAttribute(QWebEngineSettings::PluginsEnabled, false);
    m_webView->settings()->setAttribute(QWebEngineSettings::AutoLoadImages, true);
    m_webView->settings()->setAttribute(QWebEngineSettings::ErrorPageEnabled, false);
    m_webView->page()->setBackgroundColor(Qt::white);

    connect(m_webView, &QWebEngineView::loadFinished,
            this, &EpubReader::onLoadFinished);

    setCentralWidget(m_webView);

    m_tocDock = new QDockWidget(tr("Оглавление"), this);
    m_tocDock->setVisible(false);
    m_tocWidget = new QListWidget(m_tocDock);
    connect(m_tocWidget, &QListWidget::itemClicked,
            this, &EpubReader::onTocItemClicked);
    m_tocDock->setWidget(m_tocWidget);
    addDockWidget(Qt::LeftDockWidgetArea, m_tocDock);
}

void EpubReader::setupMenu()
{
    m_fileMenu = menuBar()->addMenu(tr("Файл"));

    m_openAction = new QAction(tr("Открыть EPUB"), this);
    m_openAction->setShortcut(QKeySequence::Open);
    connect(m_openAction, &QAction::triggered, this, &EpubReader::openFile);
    m_fileMenu->addAction(m_openAction);

    m_fileMenu->addSeparator();

    QAction *closeAction = new QAction(tr("Закрыть"), this);
    closeAction->setShortcut(QKeySequence::Close);
    connect(closeAction, &QAction::triggered, this, &QWidget::close);
    m_fileMenu->addAction(closeAction);

    m_viewMenu = menuBar()->addMenu(tr("Вид"));

    m_themeMenu = m_viewMenu->addMenu(tr("Тема"));
    m_themeMenu->addAction(tr("Светлая"), this, [this]() { changeTheme(0); });
    m_themeMenu->addAction(tr("Сепия"),   this, [this]() { changeTheme(1); });
    m_themeMenu->addAction(tr("Тёмная"),  this, [this]() { changeTheme(2); });

    m_tocAction = new QAction(tr("Оглавление"), this);
    m_tocAction->setCheckable(true);
    connect(m_tocAction, &QAction::toggled, m_tocDock, &QDockWidget::setVisible);
    m_viewMenu->addAction(m_tocAction);
}

void EpubReader::setupToolbar()
{
    m_toolbar = addToolBar(tr("Навигация"));
    m_toolbar->setMovable(false);

    m_toolbar->addAction(m_openAction);
    m_toolbar->addSeparator();

    m_prevAction = new QAction(QString::fromUtf8("\xe2\x97\x80"), this);  // ◀
    m_prevAction->setToolTip(tr("Предыдущая глава"));
    m_prevAction->setEnabled(false);
    connect(m_prevAction, &QAction::triggered, this, &EpubReader::prevChapter);
    m_toolbar->addAction(m_prevAction);

    m_nextAction = new QAction(QString::fromUtf8("\xe2\x96\xb6"), this);  // ▶
    m_nextAction->setToolTip(tr("Следующая глава"));
    m_nextAction->setEnabled(false);
    connect(m_nextAction, &QAction::triggered, this, &EpubReader::nextChapter);
    m_toolbar->addAction(m_nextAction);

    m_toolbar->addSeparator();

    m_zoomOutAction = new QAction("A-", this);
    connect(m_zoomOutAction, &QAction::triggered, this, &EpubReader::zoomOut);
    m_toolbar->addAction(m_zoomOutAction);

    m_resetZoomAction = new QAction(QString::fromUtf8("A\xe2\x97\x8b"), this);  // A○
    connect(m_resetZoomAction, &QAction::triggered, this, &EpubReader::resetZoom);
    m_toolbar->addAction(m_resetZoomAction);

    m_zoomInAction = new QAction("A+", this);
    connect(m_zoomInAction, &QAction::triggered, this, &EpubReader::zoomIn);
    m_toolbar->addAction(m_zoomInAction);

    m_toolbar->addSeparator();
    m_toolbar->addAction(m_tocAction);
}

// ============================================================
// ЗАГРУЗКА
// ============================================================

bool EpubReader::loadEpub(const QString &filePath)
{
    QFile file(filePath);
    if (!file.open(QIODevice::ReadOnly)) {
        QMessageBox::warning(this, tr("Ошибка"),
                             tr("Не удалось открыть файл:\n%1").arg(filePath));
        return false;
    }
    QByteArray data = file.readAll();
    file.close();
    return loadEpubFromMemory(data, QFileInfo(filePath).fileName());
}

bool EpubReader::loadEpubFromMemory(const QByteArray &data, const QString &title)
{
    QProgressDialog progress(tr("Загрузка EPUB..."), tr("Отмена"), 0, 100, this);
    progress.setWindowModality(Qt::WindowModal);
    progress.setMinimumDuration(500);
    progress.setValue(5);

    QApplication::setOverrideCursor(Qt::WaitCursor);

    clearCache();
    m_chapters.clear();
    m_anchorToChapter.clear();
    m_epubData = data;
    m_baseCss.clear();
    m_currentChapter = 0;
    m_isLoaded = false;

    progress.setValue(15);

    EpubZipReader zipReader(data);
    if (!zipReader.isLoaded()) {
        QApplication::restoreOverrideCursor();
        QMessageBox::warning(this, tr("Ошибка"),
                             tr("Не удалось открыть EPUB архив.\nВозможно, файл повреждён."));
        return false;
    }

    progress.setValue(30);

    if (!parseEpub()) {
        QApplication::restoreOverrideCursor();
        QMessageBox::warning(this, tr("Ошибка"),
                             tr("Не удалось разобрать EPUB файл.\n"
                                "Формат не поддерживается или файл повреждён."));
        return false;
    }

    progress.setValue(85);

    if (m_chapters.isEmpty()) {
        Chapter ch;
        ch.title = tr("Содержание");
        ch.content = tr("<p>Не удалось найти содержимое книги.</p>");
        ch.anchorId = "chapter_0";
        ch.index = 0;
        m_chapters.append(ch);
    }

    if (m_bookTitle.isEmpty())
        m_bookTitle = title.isEmpty() ? tr("Без названия") : title;
    if (m_bookAuthor.isEmpty())
        m_bookAuthor = tr("Неизвестный автор");

    progress.setValue(95);

    renderChapter(0);

    progress.setValue(100);

    setWindowTitle(QString("EPUB Reader — %1").arg(m_bookTitle));
    statusBar()->showMessage(tr("Загружено: %1 (%2)").arg(m_bookTitle, m_bookAuthor));

    m_isLoaded = true;
    updateNavigationButtons();

    QApplication::restoreOverrideCursor();
    emit bookLoaded(m_bookTitle, m_bookAuthor);
    return true;
}

// ============================================================
// ПАРСИНГ
// ============================================================

bool EpubReader::parseEpub()
{
    EpubZipReader zipReader(m_epubData);
    if (!zipReader.isLoaded()) {
        qWarning() << "EpubReader: ZIP not loaded";
        return false;
    }

    FormatEPub parser;
    if (!parser.loadFile("", m_epubData, &zipReader)) {
        qWarning() << "EpubReader: FormatEPub failed";
        return false;
    }

    QBRBook *book = parser.getBook();
    if (!book || !book->metadata) {
        qWarning() << "EpubReader: no book data";
        return false;
    }

    m_bookTitle  = book->metadata->Title;
    m_bookAuthor = book->metadata->Author;

    qDebug() << "EpubReader: title =" << m_bookTitle
             << " author =" << m_bookAuthor;

    QString fullHtml = book->html;
    if (fullHtml.isEmpty()) {
        qWarning() << "EpubReader: empty HTML";
        return false;
    }

    // --- Извлекаем <style> из шаблона FormatEPub ---
    {
        QRegularExpression styleRe(
            QStringLiteral("<style[^>]*>([\\s\\S]*?)</style>"),
            QRegularExpression::CaseInsensitiveOption);
        QRegularExpressionMatch m = styleRe.match(fullHtml);
        if (m.hasMatch())
            m_baseCss = m.captured(1);
    }

    // --- Разбиваем на главы по <div id="file_..."> ---
    // Используем НЕ-жадный поиск: ищем открывающий тег, потом
    // содержимое до следующего <div id="file_ или до </body>
    {
        // Паттерн для поиска начала главы
        QRegularExpression chapterStartRe(
            QStringLiteral("<div\\s+id=\"(file_[^\"]+)\"[^>]*>"),
            QRegularExpression::CaseInsensitiveOption);

        QRegularExpressionMatchIterator it = chapterStartRe.globalMatch(fullHtml);
        QVector<QPair<QString,int>> starts;  // <anchorId, позиция_после_тега>

        while (it.hasNext()) {
            QRegularExpressionMatch m = it.next();
            starts.append({m.captured(1), m.capturedEnd()});
        }

        for (int i = 0; i < starts.size(); ++i) {
            const QString &anchorId = starts[i].first;
            int contentStart = starts[i].second;

            // Конец содержимого: начало следующего <div id="file_ или </body>
            int contentEnd = fullHtml.size();
            if (i + 1 < starts.size()) {
                // Ищем назад от начала следующего тега
                int nextTagStart = starts[i + 1].second;
                // Найдём позицию открывающего тега следующего div
                int searchBack = fullHtml.lastIndexOf("<div", nextTagStart - 1);
                if (searchBack > contentStart)
                    contentEnd = searchBack;
            } else {
                int bodyEnd = fullHtml.indexOf("</body>", contentStart,
                                               Qt::CaseInsensitive);
                if (bodyEnd > contentStart)
                    contentEnd = bodyEnd;
            }

            QString content = fullHtml.mid(contentStart, contentEnd - contentStart).trimmed();

            // Убираем закрывающий </div> в конце
            if (content.endsWith("</div>"))
                content.chop(6);
            content = content.trimmed();

            if (content.isEmpty())
                continue;

            Chapter ch;
            ch.anchorId = anchorId;
            ch.content  = content;
            ch.index    = m_chapters.size();
            ch.title    = QString();
            m_chapters.append(ch);
            m_anchorToChapter[anchorId] = m_chapters.size() - 1;
        }
    }

    qDebug() << "EpubReader: chapters via file_*:" << m_chapters.size();

    // --- Fallback: одна глава из <body> ---
    if (m_chapters.isEmpty()) {
        QRegularExpression bodyRe(
            QStringLiteral("<body[^>]*>([\\s\\S]*)</body>"),
            QRegularExpression::CaseInsensitiveOption);
        QRegularExpressionMatch bm = bodyRe.match(fullHtml);

        QString bodyContent = bm.hasMatch() ? bm.captured(1) : fullHtml;

        // Удаляем служебные блоки
        bodyContent.remove(QRegularExpression(
            QStringLiteral("<div\\s+class=\"doc_(title|subtitle|subsubtitle)\"[^>]*>[\\s\\S]*?</div>"),
            QRegularExpression::CaseInsensitiveOption));

        if (!bodyContent.trimmed().isEmpty()) {
            Chapter ch;
            ch.anchorId = "chapter_0";
            ch.content  = bodyContent;
            ch.index    = 0;
            ch.title    = tr("Содержание");
            m_chapters.append(ch);
            m_anchorToChapter["chapter_0"] = 0;
        }
    }

    // --- Заголовки из TOC ---
    const QList<QBRTocItem> &tocItems = book->metadata->Toc;

    if (!tocItems.isEmpty()) {
        QMap<QString, QString> tocTitles;
        std::function<void(const QList<QBRTocItem>&)> collect =
            [&](const QList<QBRTocItem> &items) {
                for (const QBRTocItem &item : items) {
                    if (!item.Anchor.isEmpty() && !item.Title.isEmpty())
                        tocTitles[normalizeAnchor(item.Anchor)] = item.Title;
                    if (!item.Childs.isEmpty())
                        collect(item.Childs);
                }
            };
        collect(tocItems);

        for (int i = 0; i < m_chapters.size(); ++i) {
            QString norm = normalizeAnchor(m_chapters[i].anchorId);
            if (tocTitles.contains(norm))
                m_chapters[i].title = tocTitles[norm];
        }

        for (int i = 0; i < m_chapters.size(); ++i) {
            if (m_chapters[i].title.isEmpty())
                m_chapters[i].title = tr("Глава %1").arg(i + 1);
        }

        buildTocTree(tocItems);
        buildAnchorMap(tocItems);
    } else {
        for (int i = 0; i < m_chapters.size(); ++i) {
            if (m_chapters[i].title.isEmpty())
                m_chapters[i].title = tr("Глава %1").arg(i + 1);
        }
        m_tocWidget->clear();
        for (int i = 0; i < m_chapters.size(); ++i) {
            auto *item = new QListWidgetItem(m_chapters[i].title, m_tocWidget);
            item->setData(Qt::UserRole, i);
        }
    }

    qDebug() << "EpubReader: total chapters:" << m_chapters.size();
    return !m_chapters.isEmpty();
}

// ============================================================
// ОГЛАВЛЕНИЕ
// ============================================================

void EpubReader::buildTocTree(const QList<QBRTocItem> &tocItems,
                              QListWidgetItem *parent)
{
    for (const QBRTocItem &item : tocItems) {
        QString prefix = parent ? "    " : "";
        auto *listItem = new QListWidgetItem(prefix + item.Title, m_tocWidget);
        listItem->setData(Qt::UserRole, normalizeAnchor(item.Anchor));

        if (!item.Childs.isEmpty())
            buildTocTree(item.Childs, listItem);
    }
}

void EpubReader::buildAnchorMap(const QList<QBRTocItem> &tocItems)
{
    for (const QBRTocItem &item : tocItems) {
        if (!item.Anchor.isEmpty()) {
            QString normalized = normalizeAnchor(item.Anchor);
            for (int i = 0; i < m_chapters.size(); ++i) {
                QString chAnchor = normalizeAnchor(m_chapters[i].anchorId);
                if (chAnchor == normalized || normalized.startsWith(chAnchor)) {
                    m_anchorToChapter[normalized] = i;
                    break;
                }
            }
        }
        if (!item.Childs.isEmpty())
            buildAnchorMap(item.Childs);
    }
}

// ============================================================
// РЕНДЕРИНГ
// ============================================================

void EpubReader::renderChapter(int index)
{
    if (m_chapters.isEmpty()) {
        m_webView->setHtml(
            "<html><body><h1>Нет глав</h1>"
            "<p>EPUB не содержит глав.</p></body></html>");
        return;
    }

    if (index < 0 || index >= m_chapters.size())
        index = 0;

    m_currentChapter = index;
    const Chapter &ch = m_chapters[index];

    qDebug() << "=== RENDER ===" << index << ch.title
             << "size:" << ch.content.size();

    QString finalHtml = buildFinalHtml(ch.content);

    qDebug() << "Final HTML size:" << finalHtml.size();

    // Для больших глав (>2 МБ) записываем во временный файл
    static QTemporaryFile *s_tempHtmlFile = nullptr;
    if (finalHtml.size() > 2 * 1024 * 1024) {
        qDebug() << "Chapter too large for setHtml, using temp file";

        if (s_tempHtmlFile) {
            s_tempHtmlFile->close();
            delete s_tempHtmlFile;
        }
        s_tempHtmlFile = new QTemporaryFile(
            QDir::tempPath() + "/epub_chapter_XXXXXX.html");
        if (s_tempHtmlFile->open()) {
            s_tempHtmlFile->write(finalHtml.toUtf8());
            s_tempHtmlFile->flush();
            m_webView->setUrl(QUrl::fromLocalFile(s_tempHtmlFile->fileName()));
        } else {
            qWarning() << "Failed to create temp file for large chapter";
            m_webView->setHtml(
                "<html><body><h1>Ошибка</h1>"
                "<p>Глава слишком большая для отображения.</p></body></html>");
        }
    } else {
        m_webView->setHtml(finalHtml, QUrl("epub://localhost/chapter"));
    }

    m_webView->setZoomFactor(m_zoomLevel / 100.0);

    statusBar()->showMessage(
        tr("Глава %1 из %2: %3")
            .arg(index + 1).arg(m_chapters.size()).arg(ch.title));

    updateNavigationButtons();
}

QString EpubReader::buildFinalHtml(const QString &chapterContent)
{
    ThemeColors c = m_themes[m_theme];

    QString themeCss = QStringLiteral(
                           "body {"
                           "  background-color: %1; color: %2;"
                           "  font-family: Georgia, 'Times New Roman', serif;"
                           "  font-size: 17px; line-height: 1.7;"
                           "  max-width: 800px; margin: 30px auto; padding: 20px;"
                           "}"
                           "a { color: %3; text-decoration: none; }"
                           "a:hover { text-decoration: underline; }"
                           "h1,h2,h3,h4,h5,h6 { color: %2; margin: 1.2em 0 0.4em; line-height: 1.3; }"
                           "h1 { font-size: 1.8em; } h2 { font-size: 1.5em; } h3 { font-size: 1.3em; }"
                           "p { text-align: justify; margin-bottom: 0.8em; text-indent: 1.5em; }"
                           "img { max-width: 100%%; height: auto; display: block; margin: 1em auto; }"
                           "::selection { background: %4; }"
                           "blockquote { border-left: 3px solid %3; padding-left: 1em; margin: 1em 0; font-style: italic; }"
                           "table { border-collapse: collapse; margin: 1em auto; }"
                           "td, th { border: 1px solid rgba(128,128,128,0.3); padding: 6px 10px; }"
                           ".doc_title { font-size: 2em; font-weight: bold; text-align: center; margin-bottom: 0.5em; }"
                           ".doc_subtitle { font-size: 1.3em; text-align: center; color: rgba(128,128,128,0.8); margin-bottom: 2em; }"
                           ).arg(c.background, c.text, c.link, c.selection);

    QString header;
    if (!m_bookTitle.isEmpty())
        header += "<div class=\"doc_title\">" + m_bookTitle.toHtmlEscaped() + "</div>\n";
    if (!m_bookAuthor.isEmpty())
        header += "<div class=\"doc_subtitle\">" + m_bookAuthor.toHtmlEscaped() + "</div>\n";

    return QStringLiteral(
               "<!DOCTYPE html>\n<html>\n<head>\n"
               "  <meta charset='UTF-8'>\n"
               "  <title>%1</title>\n"
               "  <style>\n%2\n%3\n  </style>\n"
               "</head>\n<body>\n%4\n%5\n</body>\n</html>"
               ).arg(m_bookTitle.toHtmlEscaped(), themeCss, m_baseCss, header, chapterContent);
}

void EpubReader::updateNavigationButtons()
{
    if (m_prevAction) m_prevAction->setEnabled(m_currentChapter > 0);
    if (m_nextAction) m_nextAction->setEnabled(m_currentChapter < m_chapters.size() - 1);
}

void EpubReader::clearCache()
{
    // Нечего чистить в данной реализации
}

// ============================================================
// СЛОТЫ
// ============================================================

void EpubReader::openFile()
{
    QString fp = QFileDialog::getOpenFileName(
        this, tr("Открыть EPUB"), QString(),
        tr("EPUB файлы (*.epub);;Все файлы (*)"));
    if (!fp.isEmpty())
        loadEpub(fp);
}

void EpubReader::nextChapter()
{
    if (m_currentChapter + 1 < m_chapters.size())
        renderChapter(m_currentChapter + 1);
    else
        statusBar()->showMessage(tr("Это последняя глава"), 2000);
}

void EpubReader::prevChapter()
{
    if (m_currentChapter > 0)
        renderChapter(m_currentChapter - 1);
    else
        statusBar()->showMessage(tr("Это первая глава"), 2000);
}

void EpubReader::zoomIn()
{
    m_zoomLevel = qMin(200, m_zoomLevel + 10);
    if (m_webView) m_webView->setZoomFactor(m_zoomLevel / 100.0);
}

void EpubReader::zoomOut()
{
    m_zoomLevel = qMax(50, m_zoomLevel - 10);
    if (m_webView) m_webView->setZoomFactor(m_zoomLevel / 100.0);
}

void EpubReader::resetZoom()
{
    m_zoomLevel = 100;
    if (m_webView) m_webView->setZoomFactor(1.0);
}

void EpubReader::changeTheme(int theme)
{
    if (theme >= 0 && theme < m_themes.size()) {
        m_theme = theme;
        applyTheme();
        if (m_isLoaded)
            renderChapter(m_currentChapter);
    }
}

void EpubReader::applyTheme()
{
    if (!m_webView) return;
    m_webView->page()->setBackgroundColor(QColor(m_themes[m_theme].background));
}

void EpubReader::showToc()
{
    if (m_tocDock) {
        m_tocDock->setVisible(!m_tocDock->isVisible());
        if (m_tocAction)
            m_tocAction->setChecked(m_tocDock->isVisible());
    }
}

void EpubReader::onTocItemClicked(QListWidgetItem *item)
{
    if (!item) return;

    bool ok;
    int index = item->data(Qt::UserRole).toInt(&ok);
    if (ok && index >= 0 && index < m_chapters.size()) {
        renderChapter(index);
        return;
    }

    QString anchor = item->data(Qt::UserRole).toString();
    if (m_anchorToChapter.contains(anchor))
        renderChapter(m_anchorToChapter[anchor]);
}

void EpubReader::onLoadFinished(bool ok)
{
    if (!ok) {
        qWarning() << "EpubReader: chapter load failed";
        m_webView->setHtml(
            "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>"
            "<body style='font-family:Arial;text-align:center;padding:20px'>"
            "<h1>Ошибка загрузки</h1>"
            "<p>Не удалось загрузить главу.</p></body></html>");
        return;
    }

    m_webView->page()->runJavaScript(
        QStringLiteral("document.body ? document.body.innerHTML.length : 0"),
        [this](const QVariant &result) {
            int len = result.toInt();
            qDebug() << "EpubReader: loaded content length:" << len;
            if (len < 50 && m_currentChapter >= 0 && m_currentChapter < m_chapters.size()) {
                qWarning() << "EpubReader: content too small for chapter"
                           << m_chapters[m_currentChapter].title;
            }
        });
}

// ============================================================
// УТИЛИТЫ
// ============================================================

QString EpubReader::extractChapterContent(const QString &fullHtml,
                                          const QString &anchorId)
{
    QString escaped = QRegularExpression::escape(anchorId);
    QString pattern = QStringLiteral("<div\\s+id=\"%1\"[^>]*>([\\s\\S]*?)</div>")
                          .arg(escaped);
    QRegularExpression re(pattern, QRegularExpression::CaseInsensitiveOption);
    QRegularExpressionMatch m = re.match(fullHtml);
    return m.hasMatch() ? m.captured(1) : QString();
}

QString EpubReader::stripHtmlTags(const QString &html)
{
    QString r = html;
    r.remove(QRegularExpression("<[^>]*>"));
    return r.trimmed();
}

QString EpubReader::normalizeAnchor(const QString &anchor)
{
    QString r = anchor.trimmed();
    if (r.startsWith('#'))
        r = r.mid(1);
    return r;
}
