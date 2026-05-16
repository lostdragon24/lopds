#ifndef MAINWINDOW_H
#define MAINWINDOW_H

#include <QMainWindow>
#include <QSqlDatabase>
#include <QSqlQueryModel>
#include <QStandardItemModel>
#include <QButtonGroup>
#include <QCache>
#include <QProgressBar>
#include <QLabel>

#include <QGridLayout>
#include <QShortcut>
#include <QTimer>

#include "scannerdialog.h"
#include "favoritesdialog.h"
#include "fb2reader.h"
#include <QTemporaryFile>
#include <QNetworkInterface>


QT_BEGIN_NAMESPACE
namespace Ui {
class MainWindow;
}
QT_END_NAMESPACE

class SettingsDialog;

enum class TreeViewMode {
    Authors,
    Series,
    Genres
};

class MainWindow : public QMainWindow
{
    Q_OBJECT

public:
    MainWindow(QWidget *parent = nullptr);
    ~MainWindow();
    void openBookById(int bookId);

protected:
    void showEvent(QShowEvent *event) override;
    void resizeEvent(QResizeEvent *event) override;

private slots:
    void on_actionSettings_triggered();
    void on_actionReconnect_triggered();
    void on_actionScan_triggered();
    void on_searchLineEdit_textChanged(const QString &text);
    void on_searchLineEdit_returnPressed();
    void on_btn_search_clicked();
    void initScannerDialog();
    void on_treeView_clicked(const QModelIndex &index);
    void on_treeView_doubleClicked(const QModelIndex &index);  // Добавляем слот для двойного клика
    void on_btn_download_clicked();
    void on_btn_delete_clicked();
    void on_pushButton_clicked();
    void onSplitterMoved(int pos, int index);
    void onLetterButtonClicked(const QString &letter);
    void onLoadAllChecked(bool checked);
    void onTreeViewExpanded(const QModelIndex &index);
    void onTreeViewModeChanged();
    void about();


private:
    Ui::MainWindow *ui;
    QSqlDatabase db;
    QSqlQueryModel *booksModel;
    QStandardItemModel *treeModel;
    QButtonGroup *letterButtonGroup;
    QButtonGroup *treeModeButtonGroup;
    SettingsDialog *settingsDialog;
    ScannerDialog *m_scannerDialog;
    TreeViewMode currentTreeMode;

    // Элементы UI для статуса
    QProgressBar *progressBar;
    QLabel *statusLabel;
    QButtonGroup *ratingGroup;


    // Иконки для дерева
    QIcon authorIcon;
    QIcon bookIcon;
    QIcon seriesIcon;
    QIcon genreIcon;

    // Хэш-таблица для подмены жанров
    QHash<QString, QString> genreMap;

    // Кэши
    QCache<QString, QPixmap> *coverCache;
    QCache<QString, QString> *descriptionCache;

    // Структура для хранения данных извлеченной книги
    struct BookContent {
        QByteArray data;
        QPixmap cover;
        QString description;
        bool hasCover = false;
        bool hasDescription = false;

        BookContent() = default;
    };

    QString getCacheDir() const;
    QString getCoverCachePath(int bookId) const;
    void saveCoverToCache(int bookId, const QPixmap& cover);
    QPixmap loadCoverFromCache(int bookId) const;

    // Кэш для содержимого книг
    QCache<QString, BookContent> *bookContentCache;

        void openDatabase();
    bool setupDatabaseConnection();
    void setupTreeView();
    void setupAlphabetButtons();
    void setupTreeViewModeSelector();
    void loadAuthorsByLetter(const QString &letter);
    void loadAllAuthors();
    void loadAuthorBooks(const QString &author, QStandardItem *authorItem);
    void loadSeriesByLetter(const QString &letter);
    void loadAllSeries();
    void loadSeriesBooks(const QString &series, QStandardItem *seriesItem);
    void loadGenresByLetter(const QString &letter);
    void loadAllGenres();
    void loadGenreBooks(const QString &genre, QStandardItem *genreItem);
    void updateSelectionStatistics(const QString &letter);
    void loadStatistics();
    void loadBookDetails(int bookId);
    void updateBookDetails(const QSqlQuery &query);
    void clearBookDetails();
    bool isDatabaseOpen();
    void showError(const QString &message);
    void showInfo(const QString &message);
    void clearBookRating(int bookId);
    void updateStarsDisplay(int rating);
    void resetRatingButtons();

    // Методы для избранного и рейтинга
      void toggleFavorite(int bookId, bool favorite);
      void setBookRating(int bookId, int rating);
      void loadBookFavoriteStatus(int bookId, QPushButton *button);
      void loadBookRating(int bookId, QButtonGroup *ratingGroup);
      QString getCurrentUserIdentifier();

      // Форма просмотра избранного и рейтинга
      void showFavoritesDialog();

      QDialog *m_favoritesDialog = nullptr;



    const QString CACHE_DIR_NAME = ".cover";
    // Методы для загрузки обложки и описания
    bool saveBookDescriptionToDb(int bookId, const QString& description);
    void loadBookCoverAndDescription(int bookId);
    void extractAndCacheBookMetadata(int bookId, const QString& filePath, const QString& archivePath, const QString& internalPath);

    QPixmap loadBookCover(const QString& filePath, const QString& archivePath,const QString& internalPath);
    QPixmap parseCoverFromFB2(const QByteArray& content);
    QString loadFullDescription(const QString& filePath, const QString& archivePath,const QString& internalPath);
    QString parseDescriptionFromFB2(const QByteArray& content);
    QPixmap parseCoverFromFB2Content(const QByteArray& content);
    QPixmap parseCoverFromEpubContent(const QByteArray& epubData);
    QString parseCoverPathFromOpf(const QString& opfContent);
    QString parseDescriptionFromFB2Content(const QByteArray& content);
    QString parseDescriptionFromEpubContent(const QByteArray& epubData);
    QString parseDescriptionFromOpf(const QString& opfContent);
    QString readFileFromArchive(const QString& archivePath, const QString& internalPath);


    void downloadBook(int bookId);
    bool deleteBook(int bookId);

    // Поиск
    void performSearch(const QString &queryText);

    // Статический метод для извлечения файлов из архивов
    static QByteArray extractFileFromArchive(const QString& archivePath, const QString& internalPath);

    // Методы для настройки интерфейса
    void setupSplitter();
    void setupStyles();
    void setupIcons();
    void saveSplitterState();
    void restoreSplitterState();

    // Методы для работы с БД
    bool createDatabaseTables();

    // Вспомогательные методы
    void refreshTreeView();
    void createLetterButton(const QString &letter, QGridLayout *layout, int row, int col);

    // Метод для форматирования размера файла
    QString formatFileSize(qint64 bytes);

    // Методы для инициализации и работы с жанрами
    void initGenreMap();
    QString getReadableGenre(const QString &genreCode);

    // Методы для отображения обложки
    void displayCover(const QPixmap& cover);

    // Методы для работы с содержимым книг
    BookContent* getBookContent(const QString& filePath, const QString& archivePath, const QString& internalPath);
    BookContent extractBookContent(const QString& filePath, const QString& archivePath, const QString& internalPath);
    QString getBookContentCacheKey(const QString& filePath, const QString& archivePath, const QString& internalPath);

    void openBook(int bookId);
        void openBookFile(const QString& filePath, const QString& archivePath, const QString& internalPath, const QString& title);
        void showSimpleBookReader(const QString& title, const QByteArray& content);
        void displayBookContent(const QByteArray& content);
        QString parseFB2Content(const QString& content);

        FB2Reader *fb2Reader;
        void openFB2Reader(const QByteArray &content, const QString &title);

    QString getCoverCacheKey(const QString& filePath, const QString& archivePath, const QString& internalPath);
    QString getDescriptionCacheKey(const QString& filePath, const QString& archivePath, const QString& internalPath);
};

#endif // MAINWINDOW_H
