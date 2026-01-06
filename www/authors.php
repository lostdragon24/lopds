<?php
require_once 'config/config.php';
require_once 'lib/Database.php';

$db = Database::getInstance();
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$authors = $db->getAuthors($page, 100); // 100 авторов на страницу

require 'templates/header.php';
?>

<div class="container mt-3">
    <h5>Авторы</h5>
    
    <div class="row">
        <?php foreach ($authors as $author): ?>
            <div class="col-md-6 mb-2">
                <a href="index.php?q=<?php echo urlencode($author['author']); ?>&field=author" 
                   class="text-decoration-none">
                    <?php echo htmlspecialchars($author['author']); ?>
                    <small class="text-muted">(<?php echo $author['book_count']; ?>)</small>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Простая пагинация -->
    <div class="mt-3 text-center">
        <a href="?page=<?php echo $page - 1; ?>" class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            Назад
        </a>
        <span class="mx-3">Страница <?php echo $page; ?></span>
        <a href="?page=<?php echo $page + 1; ?>" class="btn btn-sm btn-outline-secondary">
            Вперед
        </a>
    </div>
</div>

<?php require 'templates/footer.php'; ?>