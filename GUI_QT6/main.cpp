#include "mainwindow.h"
#include <QApplication>
#include <QSqlDatabase>
#include <QMessageBox>
#include <QDebug>
#include <QLoggingCategory>

// Фильтр сообщений для release сборки
void messageHandler(QtMsgType type, const QMessageLogContext &context, const QString &msg)
{
#ifdef QT_DEBUG
    // В debug режиме выводим всё
    QByteArray localMsg = msg.toLocal8Bit();
    switch (type) {
    case QtDebugMsg:
        fprintf(stderr, "Debug: %s (%s:%u, %s)\n", localMsg.constData(), context.file, context.line, context.function);
        break;
    case QtInfoMsg:
        fprintf(stderr, "Info: %s\n", localMsg.constData());
        break;
    case QtWarningMsg:
        fprintf(stderr, "Warning: %s\n", localMsg.constData());
        break;
    case QtCriticalMsg:
        fprintf(stderr, "Critical: %s\n", localMsg.constData());
        break;
    case QtFatalMsg:
        fprintf(stderr, "Fatal: %s\n", localMsg.constData());
        abort();
    }
#else
    // В release - только ошибки и критические сообщения
    Q_UNUSED(context);
    if (type == QtCriticalMsg || type == QtFatalMsg) {
        fprintf(stderr, "%s\n", msg.toLocal8Bit().constData());
        if (type == QtFatalMsg) abort();
    }
#endif
}

int main(int argc, char *argv[])
{
    // Устанавливаем обработчик сообщений
    qInstallMessageHandler(messageHandler);

    // Отключаем ненужные категории логирования
    QLoggingCategory::setFilterRules(
        "qt.qpa.input.events=false\n"
        "qt.qpa.fonts=false\n"
        "qt.qpa.xcb=false"
        );

    QApplication a(argc, argv);

    QStringList drivers = QSqlDatabase::drivers();

    if (!drivers.contains("QSQLITE")) {
        QMessageBox::critical(nullptr, "Ошибка", "Драйвер SQLite не доступен!");
        return -1;
    }

    MainWindow w;
    w.show();
    return a.exec();
}
