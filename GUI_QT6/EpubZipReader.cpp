#include "EpubZipReader.h"
#include <QTemporaryFile>
#include <QDebug>
#include <QFile>

EpubZipReader::EpubZipReader(const QByteArray &data)
    : m_tempFile(nullptr)
    , m_loaded(false)
{
    // Создаем временный файл с данными EPUB
    m_tempFile = new QTemporaryFile();
    if (!m_tempFile->open()) {
        qDebug() << "Failed to create temp file";
        delete m_tempFile;
        m_tempFile = nullptr;
        return;
    }

    m_tempFile->write(data);
    m_tempFile->flush();

    QString tempFilePath = m_tempFile->fileName();
    qDebug() << "Temp file created:" << tempFilePath;

    // Открываем архив
    if (m_handler.openArchive(tempFilePath)) {
        m_loaded = true;
        qDebug() << "EPUB archive opened successfully";
    } else {
        qDebug() << "Failed to open EPUB archive:" << m_handler.getLastError();
        m_tempFile->close();
        delete m_tempFile;
        m_tempFile = nullptr;
    }
}

EpubZipReader::EpubZipReader(const QString &filePath)
    : m_tempFile(nullptr)
    , m_loaded(false)
{
    qDebug() << "Opening EPUB file directly:" << filePath;

    // Открываем архив напрямую
    if (m_handler.openArchive(filePath)) {
        m_loaded = true;
        qDebug() << "EPUB archive opened successfully from file";
    } else {
        qDebug() << "Failed to open EPUB archive:" << m_handler.getLastError();
    }
}

EpubZipReader::~EpubZipReader()
{
    m_handler.closeArchive();
    if (m_tempFile) {
        m_tempFile->close();
        delete m_tempFile;
        m_tempFile = nullptr;
    }
}

bool EpubZipReader::isLoaded() const
{
    return m_loaded && m_handler.isOpen();
}

QByteArray EpubZipReader::getFileData(const QString &fileName) const
{
    if (!isLoaded()) {
        qDebug() << "Archive not loaded, cannot read:" << fileName;
        return QByteArray();
    }

    QByteArray data = m_handler.readFile(fileName);
    if (data.isEmpty()) {
        // Пробуем альтернативные пути
        QStringList altPaths = {
            "OEBPS/" + fileName,
            "EPUB/" + fileName,
            fileName
        };

        for (const QString &altPath : altPaths) {
            if (altPath != fileName) {
                data = m_handler.readFile(altPath);
                if (!data.isEmpty()) {
                    qDebug() << "Found file at alternative path:" << altPath;
                    break;
                }
            }
        }
    }

    if (data.isEmpty()) {
        qDebug() << "File not found or empty:" << fileName;
    }
    return data;
}

bool EpubZipReader::fileExists(const QString &fileName) const
{
    if (!isLoaded()) {
        return false;
    }

    // Пробуем проверить существование файла
    QByteArray data = getFileData(fileName);
    return !data.isEmpty();
}
