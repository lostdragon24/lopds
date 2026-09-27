#ifndef EPUBZIPREADER_H
#define EPUBZIPREADER_H

#include "FormatEPub.h"
#include "archivehandler.h"
#include <QTemporaryFile>

// Адаптер для использования ArchiveHandler с FormatEPub
class EpubZipReader : public qbrunzip
{
public:
    // Для загрузки из памяти (из архива)
    EpubZipReader(const QByteArray &data);
    // Для загрузки из файла
    EpubZipReader(const QString &filePath);
    ~EpubZipReader();

    bool isLoaded() const override;
    QByteArray getFileData(const QString &fileName) const override;
    bool fileExists(const QString &fileName) const override;

private:
    mutable ArchiveHandler m_handler;
    QTemporaryFile *m_tempFile;  // Храним указатель на временный файл
    bool m_loaded;
};

#endif // EPUBZIPREADER_H
