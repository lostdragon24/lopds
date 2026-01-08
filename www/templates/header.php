<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(Config::SITE_TITLE); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .book-cover { max-width: 100px; height: auto; }
        .book-card { margin-bottom: 20px; }
        .search-form { margin-bottom: 30px; }
        .stats { font-size: 0.85rem; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php"><?php echo htmlspecialchars(Config::SITE_TITLE); ?></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Главная</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="stats.php">Статистика</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="./api/opds.php" target="_blank">OPDS</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container mt-4">

<style>
/* Исправления для обложек */
.book-cover {
    max-width: 100px;
    height: auto;
    border-radius: 5px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: transform 0.2s ease;
}

.book-cover:hover {
    transform: scale(1.05);
}

.book-cover-placeholder {
    width: 100px;
    height: 150px;
    border-radius: 5px;
    border: 1px dashed #ccc;
    color: #666;
}

/* Убираем сообщения об ошибках */
.book-cover + .book-cover-placeholder {
    display: none !important;
}

/* Показываем placeholder только если обложка скрыта */
.book-cover[style*="display: none"] + .book-cover-placeholder {
    display: flex !important;
}
</style>