#include "fb2reader.h"
#include <QScrollBar>
#include <QApplication>
#include <QDebug>
#include <QFontDatabase>
#include <QHBoxLayout>
#include <QWidget>
#include <QTextBlock>
#include <QTextCursor>

FB2Reader::FB2Reader(QWidget *parent)
    : QMainWindow(parent)
    , textEdit(nullptr)
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
    , currentFontSize(12)
    , currentLineSpacing(150) // 150%
{
    setupUI();
    setupToolbar();
}

void FB2Reader::setupUI()
{
    // Создание текстового редактора
    textEdit = new QTextEdit(this);
    textEdit->setReadOnly(true);
    textEdit->setLineWrapMode(QTextEdit::WidgetWidth);
    setCentralWidget(textEdit);

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
            "Текстовые файлы (*.txt)"
        );

        if (!fileName.isEmpty()) {
            QFile file(fileName);
            if (file.open(QIODevice::WriteOnly | QIODevice::Text)) {
                QTextStream stream(&file);
                stream << textEdit->toPlainText();
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
    colorSchemeComboBox->addItems({"Светлая", "Темная", "Сепия", "Зеленая"});
    connect(colorSchemeComboBox, QOverload<int>::of(&QComboBox::currentIndexChanged), [this](int index) {
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

    // Инициализируем цветовые схемы
    colorSchemes["Светлая"] = QColor("#ffffff");
    colorSchemes["Темная"] = QColor("#2b2b2b");
    colorSchemes["Сепия"] = QColor("#fbf0d9");
    colorSchemes["Зеленая"] = QColor("#e8f5e8");

    // Применяем начальные настройки
    applyCurrentStyles();
}

void FB2Reader::changeFont(const QFont &font)
{
    qDebug() << "Changing font to:" << font.family();
    currentFont = font;
    applyCurrentStyles();
}

void FB2Reader::changeFontSize(int size)
{
    qDebug() << "Changing font size to:" << size;
    currentFontSize = size;
    applyCurrentStyles();
}

void FB2Reader::changeLineSpacing(int spacing)
{
    qDebug() << "Changing line spacing to:" << spacing;
    currentLineSpacing = spacing;
    applyCurrentStyles();
}

void FB2Reader::changeColorScheme(const QString &scheme)
{
    qDebug() << "Changing color scheme to:" << scheme;
    currentColorScheme = scheme;
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
    fontSizeSpinBox->setValue(12);
}

void FB2Reader::applyCurrentStyles()
{
    // Сохраняем позицию прокрутки
    int scrollValue = textEdit->verticalScrollBar()->value();
    QTextCursor savedCursor = textEdit->textCursor();
    int cursorPosition = savedCursor.position();

    // Определяем цвета
    QColor bgColor, textColor;

    if (currentColorScheme == "Темная") {
        bgColor = colorSchemes["Темная"];
        textColor = QColor("#e0e0e0");
    } else if (currentColorScheme == "Сепия") {
        bgColor = colorSchemes["Сепиia"];
        textColor = QColor("#5c4b37");
    } else if (currentColorScheme == "Зеленая") {
        bgColor = colorSchemes["Зеленая"];
        textColor = QColor("#2d5016");
    } else { // Светлая
        bgColor = colorSchemes["Светлая"];
        textColor = QColor("#333333");
    }

    // Устанавливаем цвета
    QPalette palette = textEdit->palette();
    palette.setColor(QPalette::Base, bgColor);
    palette.setColor(QPalette::Text, textColor);
    textEdit->setPalette(palette);

    // ЕСЛИ ЕСТЬ ЗАГРУЖЕННЫЙ КОНТЕНТ - ПЕРЕЗАГРУЖАЕМ ЕГО
    if (!currentContent.isEmpty()) {
        qDebug() << "Reloading content with new font settings...";

        QDomDocument xmlDoc;
        if (xmlDoc.setContent(currentContent, false)) {
            // Сохраняем HTML представление для восстановления позиции
            QString oldHtml = textEdit->toHtml();

            // Очищаем и перезагружаем
            textEdit->clear();
            loadFB2Direct(xmlDoc);

            qDebug() << "Content reloaded with font:" << currentFont.family();
        }
    }

    // Восстанавливаем позицию
    QTextCursor restoreCursor = textEdit->textCursor();
    restoreCursor.setPosition(qMin(cursorPosition, textEdit->document()->characterCount() - 1));
    textEdit->setTextCursor(restoreCursor);
    textEdit->verticalScrollBar()->setValue(scrollValue);

    qDebug() << "Styles applied - Font:" << currentFont.family()
             << "Size:" << currentFontSize
             << "Color scheme:" << currentColorScheme;
}

void FB2Reader::reloadContent()
{
    if (currentContent.isEmpty()) return;

    QApplication::setOverrideCursor(Qt::WaitCursor);

    QDomDocument xmlDoc;
    if (!xmlDoc.setContent(currentContent, false)) {
        QApplication::restoreOverrideCursor();
        return;
    }

    // Очищаем и загружаем заново
    textEdit->clear();
    loadFB2Direct(xmlDoc);

    QApplication::restoreOverrideCursor();
}



void FB2Reader::loadFB2Content(const QByteArray &content, const QString &title)
{
    QApplication::setOverrideCursor(Qt::WaitCursor);

    qDebug() << "Loading FB2 content, size:" << content.size() << "bytes";

    // Сохраняем контент и заголовок для возможной перезагрузки
    currentContent = content;
    currentTitle = title;

    QDomDocument xmlDoc;
    QString errorMsg;
    int errorLine;
    int errorColumn;

    if (!xmlDoc.setContent(content, false, &errorMsg, &errorLine, &errorColumn)) {
        QApplication::restoreOverrideCursor();
        QMessageBox::warning(this, "Ошибка",
                           QString("Ошибка парсинга XML:\n%1\nСтрока: %2, Колонка: %3")
                           .arg(errorMsg).arg(errorLine).arg(errorColumn));
        return;
    }

    qDebug() << "XML parsed successfully, generating content...";

    // Очищаем перед загрузкой нового содержимого
    textEdit->clear();

    // Загружаем содержимое напрямую в QTextDocument
    loadFB2Direct(xmlDoc);

    qDebug() << "Content loaded successfully";

    if (!title.isEmpty()) {
        setWindowTitle(QString("FB2 Reader - %1").arg(title));
    }

    QTextDocument *textDocument = textEdit->document();
    qDebug() << "Document statistics:"
             << "Blocks:" << textDocument->blockCount()
             << "Characters:" << textDocument->characterCount();

    QApplication::restoreOverrideCursor();
}

void FB2Reader::loadFB2Direct(const QDomDocument &doc)
{
    QDomElement root = doc.documentElement();

    if (root.tagName() != "FictionBook") {
        textEdit->setPlainText("Это не валидный FB2 файл");
        return;
    }

    QTextCursor cursor(textEdit->document());

    // Извлекаем информацию о книге
    QDomElement description = root.firstChildElement("description");
    if (!description.isNull()) {
        QDomElement titleInfo = description.firstChildElement("title-info");
        if (!titleInfo.isNull()) {
            // Заголовок - используем базовый шрифт с увеличенным размером
            QDomElement bookTitle = titleInfo.firstChildElement("book-title");
            if (!bookTitle.isNull()) {
                QTextCharFormat titleFormat;
                QFont titleFont(currentFont.family(), currentFontSize + 6); // Используем текущий шрифт
                titleFont.setBold(true);
                titleFormat.setFont(titleFont);
                cursor.setCharFormat(titleFormat);
                cursor.insertText(bookTitle.text() + "\n\n");
            }

            // Автор
            QDomElement author = titleInfo.firstChildElement("author");
            if (!author.isNull()) {
                QString authorName;
                QDomElement firstName = author.firstChildElement("first-name");
                QDomElement lastName = author.firstChildElement("last-name");
                QDomElement middleName = author.firstChildElement("middle-name");

                if (!firstName.isNull()) authorName += firstName.text() + " ";
                if (!middleName.isNull()) authorName += middleName.text() + " ";
                if (!lastName.isNull()) authorName += lastName.text();

                if (!authorName.isEmpty()) {
                    QTextCharFormat authorFormat;
                    QFont authorFont(currentFont.family(), currentFontSize + 2); // Используем текущий шрифт
                    authorFont.setItalic(true);
                    authorFormat.setFont(authorFont);
                    cursor.setCharFormat(authorFormat);
                    cursor.insertText(authorName.trimmed() + "\n\n");
                }
            }

            // Разделитель
            cursor.insertText("---\n\n");
        }
    }

    // Основной текст - используем текущий шрифт и размер
    QTextCharFormat normalFormat;
    QFont normalFont(currentFont.family(), currentFontSize); // Используем текущий шрифт
    normalFormat.setFont(normalFont);
    cursor.setCharFormat(normalFormat);

    // Извлекаем тело книги
    QDomElement body = root.firstChildElement("body");
    if (!body.isNull()) {
        extractTextToCursor(body, cursor);
    } else {
        cursor.insertText("Содержание не найдено");
    }
}

void FB2Reader::extractTextToCursor(const QDomElement &element, QTextCursor &cursor)
{
    QDomNode node = element.firstChild();

    while (!node.isNull()) {
        if (node.isElement()) {
            QDomElement el = node.toElement();
            QString tagName = el.tagName();

            if (tagName == "p") {
                cursor.insertText("    ");
                extractTextToCursor(el, cursor);
                cursor.insertText("\n\n");
            }
            else if (tagName == "empty-line") {
                cursor.insertText("\n");
            }
            else if (tagName == "section") {
                extractTextToCursor(el, cursor);
            }
            else if (tagName == "title") {
                QTextCharFormat titleFormat;
                QFont titleFont(currentFont.family(), currentFontSize + 3); // Используем текущий шрифт
                titleFont.setBold(true);
                titleFormat.setFont(titleFont);
                cursor.setCharFormat(titleFormat);
                extractTextToCursor(el, cursor);
                cursor.insertText("\n\n");
            }
            else if (tagName == "subtitle") {
                QTextCharFormat subtitleFormat;
                QFont subtitleFont(currentFont.family(), currentFontSize + 1); // Используем текущий шрифт
                subtitleFont.setBold(true);
                subtitleFormat.setFont(subtitleFont);
                cursor.setCharFormat(subtitleFormat);
                extractTextToCursor(el, cursor);
                cursor.insertText("\n\n");
            }
            else if (tagName == "emphasis") {
                QTextCharFormat emphasisFormat;
                QFont emphasisFont(currentFont.family(), currentFontSize); // Используем текущий шрифт
                emphasisFont.setItalic(true);
                emphasisFormat.setFont(emphasisFont);
                cursor.setCharFormat(emphasisFormat);
                extractTextToCursor(el, cursor);
            }
            else if (tagName == "strong") {
                QTextCharFormat strongFormat;
                QFont strongFont(currentFont.family(), currentFontSize); // Используем текущий шрифт
                strongFont.setBold(true);
                strongFormat.setFont(strongFont);
                cursor.setCharFormat(strongFormat);
                extractTextToCursor(el, cursor);
            }
            else if (tagName == "poem" || tagName == "stanza" || tagName == "v") {
                QTextCharFormat poemFormat;
                QFont poemFont(currentFont.family(), currentFontSize); // Используем текущий шрифт
                poemFont.setItalic(true);
                poemFormat.setFont(poemFont);
                cursor.setCharFormat(poemFormat);
                extractTextToCursor(el, cursor);
                cursor.insertText("\n");
            }
            else {
                extractTextToCursor(el, cursor);
            }
        }
        else if (node.isText()) {
            cursor.insertText(node.toText().data());
        }

        node = node.nextSibling();
    }
}

void FB2Reader::openFile()
{
    QString filePath = QFileDialog::getOpenFileName(
        this,
        "Открыть FB2 файл",
        "",
        "FictionBook Files (*.fb2 *.fb2.zip)"
    );

    if (!filePath.isEmpty()) {
        loadFB2(filePath);
    }
}

void FB2Reader::loadFB2(const QString &filePath)
{
    QFile file(filePath);
    if (!file.open(QIODevice::ReadOnly | QIODevice::Text)) {
        QMessageBox::warning(this, "Ошибка", "Не удалось открыть файл");
        return;
    }

    QByteArray content = file.readAll();
    file.close();

    loadFB2Content(content, QFileInfo(filePath).fileName());
}
