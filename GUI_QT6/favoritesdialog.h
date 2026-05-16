#ifndef FAVORITESDIALOG_H
#define FAVORITESDIALOG_H

#include <QDialog>
#include <QSqlDatabase>
#include <QStandardItemModel>
#include <QTableView>
#include <QButtonGroup>
#include <QMenu>
#include <QAction>
#include <QProgressDialog>
#include <QSortFilterProxyModel>

namespace Ui {
class FavoritesDialog;
}

class FavoritesDialog : public QDialog
{
    Q_OBJECT

public:
    explicit FavoritesDialog(QSqlDatabase database, QWidget *parent = nullptr);
    ~FavoritesDialog();

private slots:
    void loadFavorites();
    void loadRatings();
    void onRemoveFromFavorites();
    void onDeleteRating();
    void onTabChanged(int index);

    // Новые слоты
    void onDownloadSelected();
    void onDownloadAll();
    void onDeleteSelected();
    void onCheckAll(bool checked);
    void onContextMenuRequested(const QPoint &pos);
    void onItemDoubleClicked(const QModelIndex &index);

    // Слоты контекстного меню
    void onDownloadAction();
    void onDeleteAction();
    void onOpenBookAction();

    // Новые слоты для поиска и экспорта
    void onSearchTextChanged(const QString &text);
    void onExportToCsv();
    // void onSortChanged(int logicalIndex);

    // Слот для прогресса скачивания (оставляем объявление, но не используем в сигналах)
    // void onDownloadProgress(int value, const QString &status);

private:
    Ui::FavoritesDialog *ui;
    QSqlDatabase m_database;
    QStandardItemModel *m_favoritesModel;
    QStandardItemModel *m_ratingsModel;
    QSortFilterProxyModel *m_favoritesProxyModel;
    QSortFilterProxyModel *m_ratingsProxyModel;

    // Контекстное меню
    QMenu *m_contextMenu;
    QAction *m_downloadAction;
    QAction *m_deleteAction;
    QAction *m_openBookAction;

    // Диалог прогресса
    QProgressDialog *m_progressDialog;

    // Текущий выбранный элемент для контекстного меню
    int m_contextBookId;
    QString m_contextBookTitle;

    QString getCurrentUserIdentifier();
    void setupTable(QTableView *tableView, QStandardItemModel *model, QSortFilterProxyModel *proxyModel);

    // Новые методы
    void setupContextMenu();
    void setupSorting();
    QVector<int> getSelectedBookIds();
    bool downloadBook(int bookId, const QString &outputDir, QString &savedPath);
    bool extractFromArchive(const QString &archivePath,
                           const QString &internalPath,
                           const QString &outputPath);
    QString getBookFileName(int bookId);

    // Методы для массового скачивания
    void downloadBooksBatch(const QVector<int> &bookIds, const QString &outputDir);

    // Метод для экспорта
    void exportToCsv(const QString &filePath);
};

#endif // FAVORITESDIALOG_H
