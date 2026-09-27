#ifndef FB2READER_H
#define FB2READER_H

#include <QMainWindow>
#include <QTextBrowser>
#include <QMenu>
#include <QMenuBar>
#include <QAction>
#include <QFileDialog>
#include <QMessageBox>
#include <QFileInfo>
#include <QTextStream>
#include <QToolBar>
#include <QLabel>
#include <QFontComboBox>
#include <QSpinBox>
#include <QComboBox>
#include <QTextBlock>
#include <QTextCursor>
#include <QXmlStreamReader>
#include <QSysInfo>
#include <QScrollBar>

class FB2Reader : public QMainWindow
{
    Q_OBJECT

public:
    explicit FB2Reader(QWidget *parent = nullptr);
    bool loadFB2Content(const QByteArray &content, const QString &title = "");

private slots:
    void openFile();
    void loadFB2File(const QString &filePath);
    void changeFont(const QFont &font);
    void changeFontSize(int size);
    void changeLineSpacing(int spacing);
    void changeColorScheme(const QString &scheme);
    void zoomIn();
    void zoomOut();
    void resetZoom();
    void onAnchorClicked(const QUrl &link);


private:
    void setupUI();
    void setupToolbar();
    QString parseFB2ToHtml(const QByteArray &content, QStringList &toc);
    void applyCurrentStyles();
    void updateContent();
    QString getColorSchemeStyles();
    void initColorSchemes();

    // Элементы UI
    QTextBrowser *textBrowser;  // Изменено с QTextEdit на QTextBrowser
    QMenu *fileMenu;
    QMenu *viewMenu;
    QAction *openAction;
    QAction *saveAction;
    QAction *zoomInAction;
    QAction *zoomOutAction;
    QAction *resetZoomAction;

    // Элементы управления форматированием
    QFontComboBox *fontComboBox;
    QSpinBox *fontSizeSpinBox;
    QSpinBox *lineSpacingSpinBox;
    QComboBox *colorSchemeComboBox;

    // Текущие настройки
    QFont currentFont;
    int currentFontSize;
    int currentLineSpacing;
    QString currentColorScheme;
    QString currentHtmlContent;
    QStringList currentToc;
    QString currentTitle;

    // Цветовые схемы
    QHash<QString, QColor> colorSchemes;
    QHash<QString, QString> colorSchemeStyles;

    // Состояние
    bool isContentLoaded;
};

#endif // FB2READER_H
