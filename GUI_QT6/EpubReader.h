#ifndef EPUBREADER_H
#define EPUBREADER_H

#include <QMainWindow>
#include <QWebEngineView>
#include <QWebEnginePage>
#include <QWebEngineSettings>
#include <QWebEngineProfile>
#include <QToolBar>
#include <QAction>
#include <QMenu>
#include <QMenuBar>
#include <QListWidget>
#include <QDockWidget>
#include <QHash>
#include <QVector>
#include <QByteArray>
#include <QMap>
#include <QTemporaryFile>
#include <QDir>

#include "FormatEPub.h"
#include "EpubZipReader.h"

class EpubReader : public QMainWindow
{
    Q_OBJECT

public:
    explicit EpubReader(QWidget *parent = nullptr);
    ~EpubReader();

    bool loadEpub(const QString &filePath);
    bool loadEpubFromMemory(const QByteArray &data, const QString &title = QString());

signals:
    void bookLoaded(const QString &title, const QString &author);

private slots:
    void openFile();
    void nextChapter();
    void prevChapter();
    void zoomIn();
    void zoomOut();
    void resetZoom();
    void changeTheme(int theme);
    void showToc();
    void onTocItemClicked(QListWidgetItem *item);
    void onLoadFinished(bool ok);

private:
    struct Chapter {
        QString title;
        QString anchorId;
        QString content;
        int index;
    };

    struct ThemeColors {
        QString background;
        QString text;
        QString link;
        QString selection;
    };

    // UI
    QWebEngineView *m_webView;
    QToolBar *m_toolbar;
    QMenu *m_fileMenu;
    QMenu *m_viewMenu;
    QMenu *m_themeMenu;
    QListWidget *m_tocWidget;
    QDockWidget *m_tocDock;

    QAction *m_openAction;
    QAction *m_prevAction;
    QAction *m_nextAction;
    QAction *m_zoomInAction;
    QAction *m_zoomOutAction;
    QAction *m_resetZoomAction;
    QAction *m_tocAction;

    // State
    QVector<Chapter> m_chapters;
    QMap<QString, int> m_anchorToChapter;
    int m_currentChapter;
    int m_zoomLevel;
    int m_theme;

    QString m_bookTitle;
    QString m_bookAuthor;
    QString m_baseCss;
    QByteArray m_epubData;
    bool m_isLoaded;

    QHash<int, ThemeColors> m_themes;

    // Methods
    void setupUI();
    void setupToolbar();
    void setupMenu();
    void initThemes();
    void applyTheme();

    bool parseEpub();
    QString extractChapterContent(const QString &fullHtml, const QString &anchorId);
    void buildTocTree(const QList<QBRTocItem> &tocItems, QListWidgetItem *parent = nullptr);
    void buildAnchorMap(const QList<QBRTocItem> &tocItems);

    void renderChapter(int index);
    QString buildFinalHtml(const QString &chapterContent);
    void updateNavigationButtons();
    void clearCache();

    static QString stripHtmlTags(const QString &html);
    static QString normalizeAnchor(const QString &anchor);
};

#endif // EPUBREADER_H
