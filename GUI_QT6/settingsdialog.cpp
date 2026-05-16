#include "settingsdialog.h"
#include "ui_settingsdialog.h"
#include <QFileDialog>
#include <QMessageBox>
#include <QSqlDatabase>
#include <QSqlError>
#include <QSqlQuery>
#include <QDir>
#include <QFileInfo>


SettingsDialog::SettingsDialog(QWidget *parent) :
    QDialog(parent),
    ui(new Ui::SettingsDialog),
    settings("Squee&Dragon", "BookLibrary")
{
    ui->setupUi(this);

    // Настройка интерфейса
    setWindowTitle("Настройки подключения к БД");
    setFixedSize(500, 400);

    // Подключение сигналов
    connect(ui->rbSqlite, &QRadioButton::toggled, this, &SettingsDialog::onDatabaseTypeChanged);
    connect(ui->rbMysql, &QRadioButton::toggled, this, &SettingsDialog::onDatabaseTypeChanged);
    connect(ui->btnBrowseSqlite, &QPushButton::clicked, this, &SettingsDialog::onBrowseSqliteClicked);
    connect(ui->btnTestConnection, &QPushButton::clicked, this, &SettingsDialog::onTestConnectionClicked);

    // Загрузка настроек
    loadSettings();
}

SettingsDialog::~SettingsDialog()
{
    delete ui;
}

void SettingsDialog::loadSettings()
{
    // Загружаем настройки из QSettings
    QString dbType = settings.value("database/type", "sqlite").toString();

    if (dbType == "mysql") {
        ui->rbMysql->setChecked(true);
        ui->mysqlHost->setText(settings.value("mysql/host", "localhost").toString());
        ui->mysqlPort->setValue(settings.value("mysql/port", 3306).toInt());
        ui->mysqlUser->setText(settings.value("mysql/user", "root").toString());
        ui->mysqlPassword->setText(settings.value("mysql/password", "").toString());
        ui->mysqlDatabase->setText(settings.value("mysql/database", "booklibrary").toString());
    } else {
        ui->rbSqlite->setChecked(true);
        ui->sqlitePath->setText(settings.value("sqlite/path", "mybook.db").toString());
    }

    onDatabaseTypeChanged();
}

void SettingsDialog::saveSettings()
{
    if (ui->rbSqlite->isChecked()) {
        settings.setValue("database/type", "sqlite");
        settings.setValue("sqlite/path", ui->sqlitePath->text());
    } else {
        settings.setValue("database/type", "mysql");
        settings.setValue("mysql/host", ui->mysqlHost->text());
        settings.setValue("mysql/port", ui->mysqlPort->value());
        settings.setValue("mysql/user", ui->mysqlUser->text());
        settings.setValue("mysql/password", ui->mysqlPassword->text());
        settings.setValue("mysql/database", ui->mysqlDatabase->text());
    }

    settings.sync();
}

void SettingsDialog::onDatabaseTypeChanged()
{
    bool isSqlite = ui->rbSqlite->isChecked();

    // Показываем/скрываем соответствующие виджеты
    ui->sqliteGroup->setVisible(isSqlite);
    ui->mysqlGroup->setVisible(!isSqlite);

    // Активируем/деактивируем кнопку тестирования
    ui->btnTestConnection->setEnabled(!isSqlite);
}

void SettingsDialog::onBrowseSqliteClicked()
{
    QString fileName = QFileDialog::getSaveFileName(this,
        "Выберите файл базы данных SQLite",
        QDir::currentPath(),
        "SQLite Database (*.db *.sqlite);;All Files (*)");

    if (!fileName.isEmpty()) {
        ui->sqlitePath->setText(fileName);
    }
}

void SettingsDialog::onTestConnectionClicked()
{
    if (testConnection()) {
        QMessageBox::information(this, "Тест подключения", "Подключение к MySQL успешно установлено!");
    }
}

bool SettingsDialog::testConnection()
{
    // Временное подключение для тестирования
    QSqlDatabase testDb;
    if (QSqlDatabase::isDriverAvailable("QMARIADB")) {
        testDb = QSqlDatabase::addDatabase("QMARIADB", "test_connection");
    } else {
        testDb = QSqlDatabase::addDatabase("QMYSQL", "test_connection");
    }

    testDb.setHostName(ui->mysqlHost->text());
    testDb.setPort(ui->mysqlPort->value());
    testDb.setUserName(ui->mysqlUser->text());
    testDb.setPassword(ui->mysqlPassword->text());
    testDb.setDatabaseName(ui->mysqlDatabase->text());

    if (!testDb.open()) {
        QMessageBox::critical(this, "Ошибка подключения",
                            "Не удалось подключиться к MySQL:\n" + testDb.lastError().text());
        QSqlDatabase::removeDatabase("test_connection");
        return false;
    }

    // Простая проверка - пытаемся выполнить запрос
    QSqlQuery query(testDb);
    if (!query.exec("SELECT 1")) {
        QMessageBox::warning(this, "Предупреждение",
                           "Подключение установлено, но запросы не работают.");
    }

    testDb.close();
    QSqlDatabase::removeDatabase("test_connection");
    return true;
}

void SettingsDialog::accept()
{
    // Проверяем заполненность полей
    if (ui->rbSqlite->isChecked()) {
        if (ui->sqlitePath->text().isEmpty()) {
            QMessageBox::warning(this, "Предупреждение", "Укажите путь к файлу SQLite базы данных.");
            return;
        }
    } else {
        if (ui->mysqlHost->text().isEmpty() || ui->mysqlUser->text().isEmpty() ||
            ui->mysqlDatabase->text().isEmpty()) {
            QMessageBox::warning(this, "Предупреждение", "Заполните все обязательные поля для подключения к MySQL.");
            return;
        }

        // Тестируем подключение к MySQL
        if (!testConnection()) {
            int result = QMessageBox::question(this, "Подтверждение",
                "Не удалось установить подключение к MySQL. Продолжить сохранение настроек?",
                QMessageBox::Yes | QMessageBox::No);

            if (result == QMessageBox::No) {
                return;
            }
        }
    }

    saveSettings();
    QDialog::accept();
}

// Геттеры для доступа к настройкам
QString SettingsDialog::getDatabaseType() const
{
    return ui->rbSqlite->isChecked() ? "sqlite" : "mysql";
}

QString SettingsDialog::getSqlitePath() const
{
    return ui->sqlitePath->text();
}

QString SettingsDialog::getMysqlHost() const
{
    return ui->mysqlHost->text();
}

int SettingsDialog::getMysqlPort() const
{
    return ui->mysqlPort->value();
}

QString SettingsDialog::getMysqlUser() const
{
    return ui->mysqlUser->text();
}

QString SettingsDialog::getMysqlPassword() const
{
    return ui->mysqlPassword->text();
}

QString SettingsDialog::getMysqlDatabase() const
{
    return ui->mysqlDatabase->text();
}
