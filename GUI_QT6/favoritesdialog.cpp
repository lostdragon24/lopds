#include "favoritesdialog.h"
#include "ui_favoritesdialog.h"
#include <QSqlQuery>
#include <QSqlError>
#include <QDebug>
#include <QMessageBox>
#include <QHeaderView>
#include <QNetworkInterface>
#include <QHostInfo>
#include <QTableView>
#include <QAbstractItemView>
#include <QCheckBox>
#include <QFileDialog>
#include <QDir>
#include <QFileInfo>
#include <QFile>
#include <QApplication>
#include <QStandardItem>
#include <QProgressDialog>
#include <QTimer>
#include <QSortFilterProxyModel>
#include <QTextStream>
#include <QDesktopServices>
#include <QClipboard>

#if QT_VERSION >= QT_VERSION_CHECK(6, 0, 0)
#include <QStringConverter>
#endif


// Включаем заголовки для работы с архивами
#include <archive.h>
#include <archive_entry.h>

FavoritesDialog::FavoritesDialog(QSqlDatabase database, QWidget *parent) :
    QDialog(parent),
    ui(new Ui::FavoritesDialog),
    m_database(database),
    m_favoritesModel(new QStandardItemModel(this)),
    m_ratingsModel(new QStandardItemModel(this)),
    m_favoritesProxyModel(new QSortFilterProxyModel(this)),
    m_ratingsProxyModel(new QSortFilterProxyModel(this)),
    m_contextMenu(new QMenu(this)),
    m_progressDialog(nullptr),
    m_contextBookId(-1)
{
    ui->setupUi(this);
    setWindowTitle("Избранное и рейтинги");

    // Настраиваем сортировку
    setupSorting();

    // Настраиваем контекстное меню
    setupContextMenu();

    // Настраиваем модели с прокси-моделями для сортировки
    setupTable(ui->tableViewFavorites, m_favoritesModel, m_favoritesProxyModel);
    setupTable(ui->tableViewRatings, m_ratingsModel, m_ratingsProxyModel);

    // Загружаем данные
    loadFavorites();
    loadRatings();

    // Подключаем сигналы
    connect(ui->tabWidget, &QTabWidget::currentChanged, this, &FavoritesDialog::onTabChanged);
    connect(ui->btnRemoveFavorite, &QPushButton::clicked, this, &FavoritesDialog::onRemoveFromFavorites);
    connect(ui->btnDeleteRating, &QPushButton::clicked, this, &FavoritesDialog::onDeleteRating);

    // Новые кнопки
    connect(ui->btnDownloadSelected, &QPushButton::clicked, this, &FavoritesDialog::onDownloadSelected);
    connect(ui->btnDownloadAll, &QPushButton::clicked, this, &FavoritesDialog::onDownloadAll);
    connect(ui->btnDeleteSelected, &QPushButton::clicked, this, &FavoritesDialog::onDeleteSelected);

    // Кнопки поиска и экспорта
    connect(ui->btnExportCsv, &QPushButton::clicked, this, &FavoritesDialog::onExportToCsv);
    connect(ui->searchLineEdit, &QLineEdit::textChanged,
            this, &FavoritesDialog::onSearchTextChanged);

    // Контекстное меню и двойной клик
    ui->tableViewFavorites->setContextMenuPolicy(Qt::CustomContextMenu);
    connect(ui->tableViewFavorites, &QTableView::customContextMenuRequested,
            this, &FavoritesDialog::onContextMenuRequested);
    connect(ui->tableViewFavorites, &QTableView::doubleClicked,
            this, &FavoritesDialog::onItemDoubleClicked);

    // Подключаем кнопку закрытия
    connect(ui->buttonBox, &QDialogButtonBox::rejected, this, &QDialog::reject);
}

FavoritesDialog::~FavoritesDialog()
{
    if (m_progressDialog) {
        delete m_progressDialog;
    }
    delete ui;
}

void FavoritesDialog::setupSorting()
{
    // Настраиваем сортировку для обеих таблиц
    m_favoritesProxyModel->setSourceModel(m_favoritesModel);
    m_favoritesProxyModel->setSortCaseSensitivity(Qt::CaseInsensitive);
    m_favoritesProxyModel->setFilterCaseSensitivity(Qt::CaseInsensitive);

    m_ratingsProxyModel->setSourceModel(m_ratingsModel);
    m_ratingsProxyModel->setSortCaseSensitivity(Qt::CaseInsensitive);
    m_ratingsProxyModel->setFilterCaseSensitivity(Qt::CaseInsensitive);

    // Включаем сортировку по клику на заголовок
    ui->tableViewFavorites->setSortingEnabled(true);
    ui->tableViewRatings->setSortingEnabled(true);
}


void FavoritesDialog::setupContextMenu()
{
    m_downloadAction = new QAction("Скачать книгу", this);
    m_deleteAction = new QAction("Удалить из избранного", this);
    m_openBookAction = new QAction("Открыть книгу", this);

    QAction *copyTitleAction = new QAction("Копировать название", this);
    QAction *copyAuthorAction = new QAction("Копировать автора", this);

    connect(copyTitleAction, &QAction::triggered, [this]() {
        if (m_contextBookId > 0) {
            QClipboard *clipboard = QApplication::clipboard();
            clipboard->setText(m_contextBookTitle);
        }
    });

    connect(copyAuthorAction, &QAction::triggered, [this]() {
        if (m_contextBookId > 0) {
            // Находим автора в модели
            for (int row = 0; row < m_favoritesModel->rowCount(); ++row) {
                QStandardItem *idItem = m_favoritesModel->item(row, 1);
                if (idItem && idItem->text().toInt() == m_contextBookId) {
                    QStandardItem *authorItem = m_favoritesModel->item(row, 3);
                    if (authorItem) {
                        QClipboard *clipboard = QApplication::clipboard();
                        clipboard->setText(authorItem->text());
                    }
                    break;
                }
            }
        }
    });

    m_contextMenu->addAction(m_openBookAction);
    m_contextMenu->addAction(m_downloadAction);
    m_contextMenu->addSeparator();
    m_contextMenu->addAction(copyTitleAction);
    m_contextMenu->addAction(copyAuthorAction);
    m_contextMenu->addSeparator();
    m_contextMenu->addAction(m_deleteAction);

    connect(m_downloadAction, &QAction::triggered, this, &FavoritesDialog::onDownloadAction);
    connect(m_deleteAction, &QAction::triggered, this, &FavoritesDialog::onDeleteAction);
    connect(m_openBookAction, &QAction::triggered, this, &FavoritesDialog::onOpenBookAction);
}

void FavoritesDialog::setupTable(QTableView *tableView, QStandardItemModel *model, QSortFilterProxyModel *proxyModel)
{
    if (!tableView) return;

    tableView->setModel(proxyModel);
    tableView->verticalHeader()->setVisible(false);
    tableView->setSelectionBehavior(QAbstractItemView::SelectRows);
    tableView->setSelectionMode(QAbstractItemView::ExtendedSelection);

    QHeaderView *header = tableView->horizontalHeader();
    if (header) {
        header->setStretchLastSection(true);
        header->setSectionsClickable(true);
        header->setSortIndicatorShown(true);
        header->setSectionResizeMode(QHeaderView::ResizeToContents);
    }

    // Настройки для таблицы избранного
    if (tableView == ui->tableViewFavorites) {
        QStringList headers = {"✓", "ID", "Название", "Автор", "Жанр", "Дата добавления", "Размер"};
        model->setHorizontalHeaderLabels(headers);

        tableView->setColumnHidden(1, true); // Скрываем ID
        tableView->setColumnWidth(0, 30); // Ширина для чекбокса

        // Настраиваем фильтрацию для поиска
        proxyModel->setFilterKeyColumn(-1); // Фильтровать по всем столбцам
    }
    // Настройки для таблицы рейтингов
    else if (tableView == ui->tableViewRatings) {
        QStringList headers = {"ID", "Название", "Автор", "Рейтинг", "Дата"};
        model->setHorizontalHeaderLabels(headers);
        tableView->setColumnHidden(0, true); // Скрываем ID
    }
}

void FavoritesDialog::onSearchTextChanged(const QString &text)
{
    if (ui->tabWidget->currentIndex() == 0) {
        // Поиск в избранном
        m_favoritesProxyModel->setFilterFixedString(text);
        ui->lblFavoritesCount->setText(
            QString("Найдено: %1 из %2")
            .arg(m_favoritesProxyModel->rowCount())
            .arg(m_favoritesModel->rowCount())
        );
    } else {
        // Поиск в рейтингах
        m_ratingsProxyModel->setFilterFixedString(text);
        ui->lblRatingsCount->setText(
            QString("Найдено: %1 из %2")
            .arg(m_ratingsProxyModel->rowCount())
            .arg(m_ratingsModel->rowCount())
        );
    }
}



QString FavoritesDialog::getCurrentUserIdentifier()
{
    // Используем IP адрес как идентификатор пользователя
    QString hostName = QHostInfo::localHostName();
    QString ipAddress;

    QList<QHostAddress> ipAddressesList = QNetworkInterface::allAddresses();
    for (const QHostAddress &address : ipAddressesList) {
        if (address != QHostAddress::LocalHost && address.protocol() == QAbstractSocket::IPv4Protocol) {
            ipAddress = address.toString();
            break;
        }
    }

    if (ipAddress.isEmpty()) {
        ipAddress = "127.0.0.1";
    }

    return QString("%1@%2").arg(hostName).arg(ipAddress);
}

void FavoritesDialog::loadFavorites()
{
    if (!m_database.isOpen()) return;

    m_favoritesModel->removeRows(0, m_favoritesModel->rowCount());

    QString userIdentifier = getCurrentUserIdentifier();
    QSqlQuery query(m_database);
    query.prepare(
        "SELECT b.id, b.title, b.author, b.genre, f.created_at, b.file_size "
        "FROM book_favorites f "
        "JOIN books b ON f.book_id = b.id "
        "WHERE f.user_ip = ? "
        "ORDER BY f.created_at DESC"
    );
    query.addBindValue(userIdentifier);

    if (query.exec()) {
        int row = 0;
        while (query.next()) {
            m_favoritesModel->insertRow(row);

            // Колонка 0: чекбокс
            QStandardItem *checkItem = new QStandardItem();
            checkItem->setCheckable(true);
            checkItem->setCheckState(Qt::Unchecked);
            checkItem->setTextAlignment(Qt::AlignCenter);
            m_favoritesModel->setItem(row, 0, checkItem);

            // Колонка 1: ID (скрыта)
            m_favoritesModel->setItem(row, 1, new QStandardItem(query.value(0).toString()));

            // Колонка 2: Название
            m_favoritesModel->setItem(row, 2, new QStandardItem(query.value(1).toString()));

            // Колонка 3: Автор
            m_favoritesModel->setItem(row, 3, new QStandardItem(query.value(2).toString()));

            // Колонка 4: Жанр
            m_favoritesModel->setItem(row, 4, new QStandardItem(query.value(3).toString()));

            // Колонка 5: Дата добавления
            QDateTime date = QDateTime::fromString(query.value(4).toString(), Qt::ISODate);
            QString dateStr = date.toString("dd.MM.yyyy HH:mm");
            m_favoritesModel->setItem(row, 5, new QStandardItem(dateStr));

            // Колонка 6: Размер файла
            qint64 fileSize = query.value(5).toLongLong();
            QString sizeStr;
            if (fileSize < 1024) {
                sizeStr = QString("%1 Б").arg(fileSize);
            } else if (fileSize < 1024 * 1024) {
                sizeStr = QString("%1 КБ").arg(fileSize / 1024.0, 0, 'f', 1);
            } else {
                sizeStr = QString("%1 МБ").arg(fileSize / (1024.0 * 1024.0), 0, 'f', 1);
            }
            m_favoritesModel->setItem(row, 6, new QStandardItem(sizeStr));

            row++;
        }
    } else {
        qDebug() << "Error loading favorites:" << query.lastError().text();
    }

    ui->lblFavoritesCount->setText(
        QString("Найдено: %1").arg(m_favoritesModel->rowCount())
    );

    // Сбрасываем поиск при загрузке новых данных
    ui->searchLineEdit->clear();
}



void FavoritesDialog::loadRatings()
{
    if (!m_database.isOpen()) return;

    m_ratingsModel->removeRows(0, m_ratingsModel->rowCount());

    // Устанавливаем заголовки для таблицы рейтингов
    QStringList headers = {"ID", "Название", "Автор", "Рейтинг", "Дата"};
    m_ratingsModel->setHorizontalHeaderLabels(headers);

    QString userIdentifier = getCurrentUserIdentifier();
    QSqlQuery query(m_database);
    query.prepare(
        "SELECT b.id, b.title, b.author, r.rating, r.created_at "
        "FROM book_ratings r "
        "JOIN books b ON r.book_id = b.id "
        "WHERE r.user_ip = ? "
        "ORDER BY r.created_at DESC"
    );
    query.addBindValue(userIdentifier);

    if (query.exec()) {
        int row = 0;
        while (query.next()) {
            m_ratingsModel->insertRow(row);

            // ID
            m_ratingsModel->setItem(row, 0, new QStandardItem(query.value(0).toString()));

            // Название
            m_ratingsModel->setItem(row, 1, new QStandardItem(query.value(1).toString()));

            // Автор
            m_ratingsModel->setItem(row, 2, new QStandardItem(query.value(2).toString()));

            // Рейтинг (звездочки)
            int rating = query.value(3).toInt();
            QString stars;
            for (int i = 0; i < 5; i++) {
                stars += (i < rating) ? "★" : "☆";
            }
            QStandardItem *ratingItem = new QStandardItem(stars);
            ratingItem->setTextAlignment(Qt::AlignCenter);
            m_ratingsModel->setItem(row, 3, ratingItem);

            // Дата
            QString dateStr = query.value(4).toString();
            QStandardItem *dateItem = new QStandardItem(dateStr);
            dateItem->setTextAlignment(Qt::AlignCenter);
            m_ratingsModel->setItem(row, 4, dateItem);

            row++;
        }
    } else {
        qDebug() << "Error loading ratings:" << query.lastError().text();
    }

    ui->lblRatingsCount->setText(QString("Найдено: %1").arg(m_ratingsModel->rowCount()));
}

void FavoritesDialog::onRemoveFromFavorites()
{
    QModelIndexList selected = ui->tableViewFavorites->selectionModel()->selectedRows();
    if (selected.isEmpty()) {
        QMessageBox::information(this, "Информация", "Выберите книгу для удаления из избранного");
        return;
    }

    int row = selected.first().row();
    int bookId = m_favoritesModel->item(row, 1)->text().toInt();
    QString bookTitle = m_favoritesModel->item(row, 2)->text();

    if (QMessageBox::question(this, "Подтверждение",
        QString("Удалить книгу \"%1\" из избранного?").arg(bookTitle)) == QMessageBox::Yes) {

        QString userIdentifier = getCurrentUserIdentifier();
        QSqlQuery query(m_database);
        query.prepare("DELETE FROM book_favorites WHERE book_id = ? AND user_ip = ?");
        query.addBindValue(bookId);
        query.addBindValue(userIdentifier);

        if (query.exec()) {
            loadFavorites();
            QMessageBox::information(this, "Успех", "Книга удалена из избранного");
        } else {
            QMessageBox::critical(this, "Ошибка", "Не удалось удалить книгу из избранного");
        }
    }
}

void FavoritesDialog::onDeleteRating()
{
    QModelIndexList selected = ui->tableViewRatings->selectionModel()->selectedRows();
    if (selected.isEmpty()) {
        QMessageBox::information(this, "Информация", "Выберите рейтинг для удаления");
        return;
    }

    int row = selected.first().row();
    int bookId = m_ratingsModel->item(row, 0)->text().toInt();
    QString bookTitle = m_ratingsModel->item(row, 1)->text();

    if (QMessageBox::question(this, "Подтверждение",
        QString("Удалить рейтинг для книги \"%1\"?").arg(bookTitle)) == QMessageBox::Yes) {

        QString userIdentifier = getCurrentUserIdentifier();
        QSqlQuery query(m_database);
        query.prepare("DELETE FROM book_ratings WHERE book_id = ? AND user_ip = ?");
        query.addBindValue(bookId);
        query.addBindValue(userIdentifier);

        if (query.exec()) {
            loadRatings();
            QMessageBox::information(this, "Успех", "Рейтинг удален");
        } else {
            QMessageBox::critical(this, "Ошибка", "Не удалось удалить рейтинг");
        }
    }
}

void FavoritesDialog::onTabChanged(int index)
{
    if (index == 0) {
        loadFavorites();
    } else if (index == 1) {
        loadRatings();
    }
}

void FavoritesDialog::onCheckAll(bool checked)
{
    Qt::CheckState state = checked ? Qt::Checked : Qt::Unchecked;
    for (int row = 0; row < m_favoritesModel->rowCount(); ++row) {
        QStandardItem *item = m_favoritesModel->item(row, 0);
        if (item) {
            item->setCheckState(state);
        }
    }
}

QVector<int> FavoritesDialog::getSelectedBookIds()
{
    QVector<int> selectedIds;

    for (int row = 0; row < m_favoritesModel->rowCount(); ++row) {
        QStandardItem *checkItem = m_favoritesModel->item(row, 0);
        if (checkItem && checkItem->checkState() == Qt::Checked) {
            QStandardItem *idItem = m_favoritesModel->item(row, 1); // ID в скрытой колонке
            if (idItem) {
                selectedIds.append(idItem->text().toInt());
            }
        }
    }

    return selectedIds;
}

void FavoritesDialog::onDownloadSelected()
{
    QVector<int> selectedIds = getSelectedBookIds(); // Без параметра

    if (selectedIds.isEmpty()) {
        QMessageBox::information(this, "Информация", "Выберите книги для скачивания");
        return;
    }

    // Запрашиваем директорию для сохранения
    QString dirPath = QFileDialog::getExistingDirectory(this, "Выберите папку для сохранения",
                                                       QDir::homePath());
    if (dirPath.isEmpty()) return;

    // Запускаем массовое скачивание
    downloadBooksBatch(selectedIds, dirPath);
}

void FavoritesDialog::onDownloadAll()
{
    // Собираем все ID книг
    QVector<int> allIds;
    for (int row = 0; row < m_favoritesModel->rowCount(); ++row) {
        QStandardItem *idItem = m_favoritesModel->item(row, 1);
        if (idItem) {
            allIds.append(idItem->text().toInt());
        }
    }

    if (allIds.isEmpty()) {
        QMessageBox::information(this, "Информация", "В избранном нет книг");
        return;
    }

    // Запрашиваем директорию для сохранения
    QString dirPath = QFileDialog::getExistingDirectory(this, "Выберите папку для сохранения",
                                                       QDir::homePath());
    if (dirPath.isEmpty()) return;

    // Запускаем массовое скачивание
    downloadBooksBatch(allIds, dirPath);
}

void FavoritesDialog::downloadBooksBatch(const QVector<int> &bookIds, const QString &outputDir)
{
    if (bookIds.isEmpty() || outputDir.isEmpty()) return;

    // Создаем диалог прогресса
    m_progressDialog = new QProgressDialog("Скачивание книг...", "Отмена", 0, bookIds.size(), this);
    m_progressDialog->setWindowTitle("Массовое скачивание");
    m_progressDialog->setWindowModality(Qt::WindowModal);
    m_progressDialog->setMinimumDuration(0);
    m_progressDialog->setValue(0);

    // Запускаем скачивание в отдельном потоке (упрощенная версия)
    QTimer::singleShot(100, [this, bookIds, outputDir]() {
        int successCount = 0;
        int failCount = 0;
        QStringList savedFiles;

        for (int i = 0; i < bookIds.size(); ++i) {
            if (m_progressDialog && m_progressDialog->wasCanceled()) {
                break;
            }

            int bookId = bookIds[i];

            // Обновляем прогресс
            if (m_progressDialog) {
                m_progressDialog->setValue(i);
                m_progressDialog->setLabelText(
                    QString("Скачивание книг...\nОбработано: %1 из %2")
                    .arg(i + 1)
                    .arg(bookIds.size())
                );
            }

            // Скачиваем книгу
            QString savedPath;
            if (downloadBook(bookId, outputDir, savedPath)) {
                successCount++;
                savedFiles.append(savedPath);
            } else {
                failCount++;
            }

            QApplication::processEvents(); // Обрабатываем события UI
        }

        // Завершаем прогресс
        if (m_progressDialog) {
            m_progressDialog->setValue(bookIds.size());
        }

        // Показываем результат
        QString message = QString("Скачивание завершено!\n\n"
                                "Успешно: %1 книг\n"
                                "Не удалось: %2 книг\n\n"
                                "Файлы сохранены в:\n%3")
                         .arg(successCount)
                         .arg(failCount)
                         .arg(outputDir);

        QMessageBox::information(this, "Результат", message);

        // Закрываем диалог прогресса
        if (m_progressDialog) {
            m_progressDialog->close();
            delete m_progressDialog;
            m_progressDialog = nullptr;
        }
    });
}






void FavoritesDialog::onDeleteSelected()
{
    QVector<int> selectedIds = getSelectedBookIds();

    if (selectedIds.isEmpty()) {
        QMessageBox::information(this, "Информация", "Выберите книги для удаления");
        return;
    }

    if (QMessageBox::question(this, "Подтверждение",
        QString("Удалить %1 выбранных книг из избранного?").arg(selectedIds.size())) != QMessageBox::Yes) {
        return;
    }

    QString userIdentifier = getCurrentUserIdentifier();
    QSqlQuery query(m_database);

    int deletedCount = 0;
    for (int bookId : selectedIds) {
        query.prepare("DELETE FROM book_favorites WHERE book_id = ? AND user_ip = ?");
        query.addBindValue(bookId);
        query.addBindValue(userIdentifier);

        if (query.exec()) {
            deletedCount++;
        }
    }

    if (deletedCount > 0) {
        loadFavorites(); // Перезагружаем список
        QMessageBox::information(this, "Успех",
                                QString("Удалено %1 книг из избранного").arg(deletedCount));
    }
}

bool FavoritesDialog::downloadBook(int bookId, const QString &outputDir, QString &savedPath)
{
    QSqlQuery query(m_database);
    query.prepare(
        "SELECT title, file_path, archive_path, archive_internal_path, file_type "
        "FROM books WHERE id = ?"
    );
    query.addBindValue(bookId);

    if (!query.exec() || !query.next()) {
        return false;
    }

    QString title = query.value(0).toString();
    QString filePath = query.value(1).toString();
    QString archivePath = query.value(2).toString();
    QString internalPath = query.value(3).toString();
    QString fileType = query.value(4).toString();

    // Формируем безопасное имя файла
    QString safeTitle = title;
    safeTitle.replace(QRegularExpression("[\\\\/:*?\"<>|]"), "_");

    // Создаем уникальное имя файла
    QString fileName = QString("%1/%2.%3")
                      .arg(outputDir)
                      .arg(safeTitle)
                      .arg(fileType);

    // Проверяем, существует ли файл с таким именем
    int counter = 1;
    QString baseFileName = fileName;
    while (QFile::exists(fileName) && counter < 100) {
        fileName = QString("%1/%2_%3.%4")
                  .arg(outputDir)
                  .arg(safeTitle)
                  .arg(counter)
                  .arg(fileType);
        counter++;
    }

    // Если книга в архиве
    if (!archivePath.isEmpty() && !internalPath.isEmpty()) {
        if (extractFromArchive(archivePath, internalPath, fileName)) {
            savedPath = fileName;
            return true;
        }
    }
    // Если отдельный файл
    else if (!filePath.isEmpty()) {
        if (QFile::copy(filePath, fileName)) {
            savedPath = fileName;
            return true;
        }
    }

    return false;
}

QString FavoritesDialog::getBookFileName(int bookId)
{
    QSqlQuery query(m_database);
    query.prepare("SELECT title, file_type FROM books WHERE id = ?");
    query.addBindValue(bookId);

    if (query.exec() && query.next()) {
        QString title = query.value(0).toString();
        QString fileType = query.value(1).toString();

        QString safeTitle = title;
        safeTitle.replace(QRegularExpression("[\\\\/:*?\"<>|]"), "_");

        return QString("%1.%2").arg(safeTitle).arg(fileType);
    }

    return QString("book_%1").arg(bookId);
}

void FavoritesDialog::onExportToCsv()
{
    QString filePath = QFileDialog::getSaveFileName(this,
        "Экспорт в CSV",
        QDir::homePath() + "/избранное.csv",
        "CSV файлы (*.csv);;Все файлы (*.*)");

    if (filePath.isEmpty()) return;

    exportToCsv(filePath);

    QMessageBox::information(this, "Экспорт завершен",
        "Данные успешно экспортированы в файл:\n" + filePath);
}

void FavoritesDialog::exportToCsv(const QString &filePath)
{
    QFile file(filePath);
    if (!file.open(QIODevice::WriteOnly | QIODevice::Text)) {
        QMessageBox::warning(this, "Ошибка", "Не удалось создать файл для экспорта");
        return;
    }

    QTextStream stream(&file);
    #if QT_VERSION < QT_VERSION_CHECK(6, 0, 0)
    stream.setCodec("UTF-8");
    #else
    stream.setEncoding(QStringConverter::Utf8);
    #endif

    // Записываем заголовки
    QStringList headers;
    for (int col = 2; col < m_favoritesModel->columnCount(); ++col) {
        headers.append(m_favoritesModel->horizontalHeaderItem(col)->text());
    }
    stream << headers.join(";") << "\n";

    // Записываем данные
    for (int row = 0; row < m_favoritesModel->rowCount(); ++row) {
        QStringList rowData;
        for (int col = 2; col < m_favoritesModel->columnCount(); ++col) {
            QStandardItem *item = m_favoritesModel->item(row, col);
            QString text = item ? item->text() : "";
            // Экранируем кавычки и заменяем разделители
            text.replace("\"", "\"\"");
            if (text.contains(';') || text.contains('"') || text.contains('\n')) {
                text = "\"" + text + "\"";
            }
            rowData.append(text);
        }
        stream << rowData.join(";") << "\n";
    }

    file.close();
}



bool FavoritesDialog::extractFromArchive(const QString &archivePath,
                                        const QString &internalPath,
                                        const QString &outputPath)
{
    struct archive *a;
    struct archive_entry *entry;
    int r;
    bool success = false;

    a = archive_read_new();
    archive_read_support_format_all(a);
    archive_read_support_filter_all(a);

    r = archive_read_open_filename(a, archivePath.toLocal8Bit().constData(), 10240);
    if (r != ARCHIVE_OK) {
        archive_read_free(a);
        return false;
    }

    while (archive_read_next_header(a, &entry) == ARCHIVE_OK) {
        const char* currentPath = archive_entry_pathname(entry);
        if (currentPath && QString::fromUtf8(currentPath) == internalPath) {
            // Создаем выходной файл
            QFile file(outputPath);
            if (file.open(QIODevice::WriteOnly)) {
                const void *buff;
                size_t size;
#if ARCHIVE_VERSION_NUMBER >= 3000000
                int64_t offset;
#else
                off_t offset;
#endif

                while (archive_read_data_block(a, &buff, &size, &offset) == ARCHIVE_OK) {
                    file.write(static_cast<const char*>(buff), size);
                }

                file.close();
                success = true;
            }
            break;
        }
        archive_read_data_skip(a);
    }

    archive_read_close(a);
    archive_read_free(a);
    return success;
}

void FavoritesDialog::onContextMenuRequested(const QPoint &pos)
{
    QModelIndex index = ui->tableViewFavorites->indexAt(pos);
    if (!index.isValid()) return;

    int row = index.row();
    QStandardItem *idItem = m_favoritesModel->item(row, 1);
    QStandardItem *titleItem = m_favoritesModel->item(row, 2);

    if (idItem && titleItem) {
        m_contextBookId = idItem->text().toInt();
        m_contextBookTitle = titleItem->text();

        // Показываем меню
        m_contextMenu->exec(ui->tableViewFavorites->viewport()->mapToGlobal(pos));
    }
}

void FavoritesDialog::onDownloadAction()
{
    if (m_contextBookId <= 0) return;

    // Запрашиваем директорию для сохранения
    QString dirPath = QFileDialog::getExistingDirectory(this,
        "Выберите папку для сохранения", QDir::homePath());

    if (dirPath.isEmpty()) return;

    QString savedPath;
    if (downloadBook(m_contextBookId, dirPath, savedPath)) { // Добавляем параметры
        QMessageBox::information(this, "Успех",
            QString("Книга успешно скачана:\n%1").arg(savedPath));
    } else {
        QMessageBox::warning(this, "Ошибка", "Не удалось скачать книгу");
    }
}

void FavoritesDialog::onDeleteAction()
{
    if (m_contextBookId <= 0) return;

    if (QMessageBox::question(this, "Подтверждение",
        QString("Удалить книгу \"%1\" из избранного?").arg(m_contextBookTitle)) == QMessageBox::Yes) {

        QString userIdentifier = getCurrentUserIdentifier();
        QSqlQuery query(m_database);
        query.prepare("DELETE FROM book_favorites WHERE book_id = ? AND user_ip = ?");
        query.addBindValue(m_contextBookId);
        query.addBindValue(userIdentifier);

        if (query.exec()) {
            loadFavorites();
            QMessageBox::information(this, "Успех", "Книга удалена из избранного");
        }
    }
}

void FavoritesDialog::onOpenBookAction()
{
    if (m_contextBookId <= 0) return;

    // Здесь можно вызвать сигнал для открытия книги в главном окне
    // emit bookSelected(m_contextBookId);
    QMessageBox::information(this, "Открытие книги",
                            QString("Открытие книги: %1 (ID: %2)").arg(m_contextBookTitle).arg(m_contextBookId));
}

void FavoritesDialog::onItemDoubleClicked(const QModelIndex &index)
{
    if (!index.isValid()) return;

    int row = index.row();
    QStandardItem *idItem = m_favoritesModel->item(row, 1);
    if (idItem) {
        m_contextBookId = idItem->text().toInt();
        onOpenBookAction();
    }
}
