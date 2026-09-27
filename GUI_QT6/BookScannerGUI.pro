QT += core gui sql network widgets xml concurrent webenginewidgets

QMAKE_CXXFLAGS += -Wl,--stack,16777216  # 16MB stack

CONFIG += c++17

SOURCES += \
    EpubReader.cpp \
    EpubZipReader.cpp \
    FormatEPub.cpp \
    archivehandler.cpp \
    bookparser.cpp \
    favoritesdialog.cpp \
    fb2reader.cpp \
    inpxparser.cpp \
    main.cpp \
    mainwindow.cpp \
    settingsdialog.cpp \
    scannerdialog.cpp

HEADERS += \
    EpubReader.h \
    EpubZipReader.h \
    FormatEPub.h \
    archivehandler.h \
    bookparser.h \
    favoritesdialog.h \
    fb2reader.h \
    inpxparser.h \
    mainwindow.h \
    settingsdialog.h \
    scannerdialog.h

FORMS += \
    mainwindow.ui \
    settingsdialog.ui \
    scannerdialog.ui \
    favoritesdialog.ui

# Для работы с архивами - исправляем линковку
unix {
    LIBS += -larchive
    # Альтернативные варианты если не работает:
    # LIBS += -L/usr/lib64 -larchive
    # LIBS += -L/usr/lib -larchive
}

# Для MySQL
unix {
    LIBS += -lmariadb
    # Или для MySQL:
    # LIBS += -lmysqlclient
}

# Для OpenSSL (хеширование)
unix {
    LIBS += -lssl -lcrypto
}

# Убедимся что компилятор видит заголовочные файлы
INCLUDEPATH += /usr/include
