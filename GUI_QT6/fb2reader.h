#ifndef FB2READER_H
#define FB2READER_H

#include <QMainWindow>
#include <QTextEdit>
#include <QMenu>
#include <QMenuBar>
#include <QAction>
#include <QFileDialog>
#include <QMessageBox>
#include <QDomDocument>
#include <QFileInfo>
#include <QTextStream>
#include <QToolBar>
#include <QLabel>
#include <QList>
#include <QFontComboBox>
#include <QSpinBox>
#include <QComboBox>
#include <QTextBlock>
#include <QTextCursor>

class FB2Reader : public QMainWindow
{
    Q_OBJECT

public:
    explicit FB2Reader(QWidget *parent = nullptr);
    void loadFB2Content(const QByteArray &content, const QString &title = "");

private slots:
    void openFile();
    void loadFB2(const QString &filePath);
    void changeFont(const QFont &font);
    void changeFontSize(int size);
    void changeLineSpacing(int spacing);
    void changeColorScheme(const QString &scheme);
    void zoomIn();
    void zoomOut();
    void resetZoom();

private:
    void setupUI();
    void setupToolbar();
    void loadFB2Direct(const QDomDocument &doc); // Добавьте этот метод
    void extractTextToCursor(const QDomElement &element, QTextCursor &cursor); // И этот
    void applyCurrentStyles();
    void reloadContent();

    QTextEdit *textEdit;
    QMenu *fileMenu;
    QMenu *viewMenu;
    QAction *openAction;
    QAction *saveAction;
    QAction *zoomInAction;
    QAction *zoomOutAction;
    QAction *resetZoomAction;
    QByteArray currentContent;
    QString currentTitle;


    // Элементы управления шрифтом
    QFontComboBox *fontComboBox;
    QSpinBox *fontSizeSpinBox;
    QSpinBox *lineSpacingSpinBox;
    QComboBox *colorSchemeComboBox;

    // Текущие настройки
    QFont currentFont;
    int currentFontSize;
    int currentLineSpacing;
    QString currentColorScheme;

    // Цветовые схемы
    QHash<QString, QColor> colorSchemes;
};

#endif // FB2READER_H
