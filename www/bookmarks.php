<?php
// bookmarks.php - объединённая страница закладок и заметок

define('LOPDS_ROOT', __DIR__);

require_once __DIR__ . '/init.php';
require_once 'templates/header.php';

$fingerprint = $_COOKIE['device_fp'] ?? '';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath === '') {
    $basePath = '';
}

if (empty($fingerprint)) {
    echo '<div class="container mt-4"><div class="alert alert-warning">Не найден идентификатор устройства. Пожалуйста, обновите страницу.</div></div>';
    require 'templates/footer.php';
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();

// ===== ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ =====
function getTableColumns($pdo, $tableName)
{
    static $cache = [];

    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    $dbType = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $columns = [];

    try {
        if ($dbType === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(\"$tableName\")");
            while ($row = $stmt->fetch()) {
                $columns[] = $row['name'];
            }
        } else {
            $stmt = $pdo->query("DESCRIBE `$tableName`");
            while ($row = $stmt->fetch()) {
                $columns[] = $row['Field'];
            }
        }
    } catch (Exception $e) {
        // Игнорируем
    }

    $cache[$tableName] = $columns;
    return $columns;
}

// ===== ПОЛУЧАЕМ АКТИВНУЮ ВКЛАДКУ =====
$activeTab = $_GET['tab'] ?? 'bookmarks';

// ===== ФИЛЬТРЫ ДЛЯ ЗАМЕТОК =====
$filter = [
    'type' => $_GET['type'] ?? 'all',
    'book_id' => (int)($_GET['book_id'] ?? 0),
    'search' => trim($_GET['q'] ?? ''),
    'sort' => $_GET['sort'] ?? 'date_desc'
];

try {
    // Проверяем наличие колонки type
    $columns = getTableColumns($pdo, 'bookmarks');
    $hasType = in_array('type', $columns);

    // ===== 1. ПОЛУЧАЕМ ПОСЛЕДНЕЕ ПРОЧИТАННОЕ =====
    // Ищем по note = 'Последнее прочитанное' (более надёжно)
    $stmt = $pdo->prepare("
        SELECT b.*, bk.title as book_title, bk.author as book_author, bk.file_type
        FROM bookmarks b
        JOIN books bk ON b.book_id = bk.id
        WHERE b.user_fingerprint = :fingerprint
          AND b.is_deleted = 0
          AND b.note = 'Последнее прочитанное'
        ORDER BY b.updated_at desc
        LIMIT 1
    ");
    $stmt->execute([':fingerprint' => $fingerprint]);
    $lastRead = $stmt->fetch();

    // ===== 2. ПОЛУЧАЕМ ВСЕ ЗАКЛАДКИ (исключая "Последнее прочитанное") =====
    $sql = "
        SELECT b.*, bk.title as book_title, bk.author as book_author, bk.file_type
        FROM bookmarks b
        JOIN books bk ON b.book_id = bk.id
        WHERE b.user_fingerprint = :fingerprint
          AND b.is_deleted = 0
    ";

    // Если есть колонка type — фильтруем по ней
    if ($hasType) {
        $sql .= " AND (b.type IS NULL OR b.type = '' OR b.type = 'bookmark' OR b.type = 'last_read')";
    }

    $sql .= " ORDER BY b.updated_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':fingerprint' => $fingerprint]);
    $bookmarks = $stmt->fetchAll();

    // ===== 3. ПОЛУЧАЕМ ВСЕ АННОТАЦИИ (заметки, цитаты, подсветки) =====
    $sql = "SELECT
                b.*,
                bk.id as book_id,
                bk.title as book_title,
                bk.author as book_author,
                bk.file_type,
                bk.added_date as book_added_date
            FROM bookmarks b
            JOIN books bk ON b.book_id = bk.id
            WHERE b.user_fingerprint = :fingerprint
              AND b.is_deleted = 0
              AND b.note != 'Последнее прочитанное'";

    // Если есть колонка type — фильтруем по типу аннотаций
    if ($hasType) {
        $sql .= " AND b.type IN ('quote', 'note', 'highlight')";
    }

    $params = [':fingerprint' => $fingerprint];

    // Фильтр по типу для аннотаций
    if ($hasType && $filter['type'] !== 'all') {
        $sql .= " AND b.type = :type";
        $params[':type'] = $filter['type'];
    }

    // Фильтр по книге
    if ($filter['book_id'] > 0) {
        $sql .= " AND b.book_id = :book_id";
        $params[':book_id'] = $filter['book_id'];
    }

    // Поиск
    if (!empty($filter['search'])) {
        $sql .= " AND (b.selected_text LIKE :search OR b.note LIKE :search)";
        $params[':search'] = '%' . $filter['search'] . '%';
    }

    // Сортировка
    switch ($filter['sort']) {
        case 'date_asc':
            $sql .= " ORDER BY b.updated_at ASC";
            break;
        case 'page_asc':
            $sql .= " ORDER BY b.page_number ASC";
            break;
        case 'book_asc':
            $sql .= " ORDER BY bk.title ASC, b.page_number ASC";
            break;
        case 'date_desc':
        default:
            $sql .= " ORDER BY b.updated_at DESC";
            break;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $annotations = $stmt->fetchAll();

    // ===== СТАТИСТИКА ДЛЯ АННОТАЦИЙ =====
    $stats = ['total' => 0, 'quotes' => 0, 'notes' => 0, 'highlights' => 0];
    foreach ($annotations as $ann) {
        $type = $ann['type'] ?? 'note';
        if (isset($stats[$type . 's'])) {
            $stats[$type . 's']++;
        }
    }
    $stats['total'] = count($annotations);

    // ===== КНИГИ С ЗАМЕТКАМИ (для фильтра) =====
    $booksWithNotes = [];
    foreach ($annotations as $ann) {
        $bookId = $ann['book_id'];
        if (!isset($booksWithNotes[$bookId])) {
            $booksWithNotes[$bookId] = [
                'id' => $bookId,
                'title' => $ann['book_title'],
                'author' => $ann['book_author'],
                'count' => 0
            ];
        }
        $booksWithNotes[$bookId]['count']++;
    }
    usort($booksWithNotes, function ($a, $b) {
        return $b['count'] - $a['count'];
    });

} catch (Exception $e) {
    error_log("Error in bookmarks.php: " . $e->getMessage());
    $error = $e->getMessage();
}

// ===== ИКОНКИ ДЛЯ ТИПОВ =====
$typeIcons = [
    'quote' => ['icon' => 'fa-quote-left', 'color' => 'warning', 'label' => __('quote_q')],
    'note' => ['icon' => 'fa-sticky-note', 'color' => 'info', 'label' => __('note_q')],
    'highlight' => ['icon' => 'fa-highlighter', 'color' => 'success', 'label' => __('highlight_q')]
];

$highlightColors = [
    'yellow' => '#fff59d',
    'green' => '#a5d6a7',
    'blue' => '#90caf9',
    'pink' => '#f48fb1',
    'orange' => '#ffcc80'
];

$totalBookmarks = count($bookmarks);
$totalAnnotations = $stats['total'];
?>

<div class="container mt-4">
    <h1 class="mb-4">
        <i class="fas fa-bookmark me-2"></i>
        <?php echo __('book_marks'); ?>
        <span class="badge bg-primary ms-2 fs-6"><?php echo $totalBookmarks + $totalAnnotations + ($lastRead ? 1 : 0); ?></span>
    </h1>

    <!-- Вкладки -->
    <ul class="nav nav-tabs mb-4" id="notesTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeTab === 'bookmarks' ? 'active' : ''; ?>"
                    id="bookmarks-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#bookmarks"
                    type="button" role="tab">
                <i class="fas fa-bookmark me-2"></i>
                <?php echo __('bookmarks'); ?>
                <span class="badge bg-secondary ms-1"><?php echo $totalBookmarks + ($lastRead ? 1 : 0); ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $activeTab === 'notes' ? 'active' : ''; ?>"
                    id="notes-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#notes"
                    type="button" role="tab">
                <i class="fas fa-sticky-note me-2"></i>
                <?php echo __('notes_and_quotes'); ?>
                <span class="badge bg-secondary ms-1"><?php echo $totalAnnotations; ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="notesTabsContent">

        <!-- ===== ВКЛАДКА 1: ЗАКЛАДКИ ===== -->
        <div class="tab-pane fade <?php echo $activeTab === 'bookmarks' ? 'show active' : ''; ?>"
             id="bookmarks" role="tabpanel">

            <!-- Последнее прочитанное -->
            <?php if ($lastRead): ?>
            <div class="card border-primary mb-4">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-clock me-2"></i>
                    <?php echo __('last_read'); ?>
                </div>
                <div class="card-body text-muted">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5><?php echo htmlspecialchars($lastRead['book_title'] ?: 'Без названия'); ?></h5>
                            <p class="text-muted"><?php echo htmlspecialchars($lastRead['book_author'] ?: 'Неизвестен'); ?></p>
                            <div>
                                <span class="badge bg-info"><?php echo round($lastRead['percentage']) . '%'; ?></span>
                                <span class="badge bg-secondary"><?php echo strtoupper($lastRead['file_type']); ?></span>
                                <span class="text-muted ms-2"><?php echo __('reader_page'); ?> <?php echo $lastRead['page_number'] ?? 1; ?></span>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <a href="reader.php?id=<?php echo $lastRead['book_id']; ?>&page=<?php echo max(1, $lastRead['page_number'] ?? 1); ?>"
                               class="btn btn-primary">
                                <i class="fas fa-book-open me-1"></i>
                              <?php echo __('restore_read'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Список закладок -->
            <?php if (empty($bookmarks)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-book-open fa-4x text-muted mb-3 d-block"></i>
                    <h4 class="text-muted"><?php echo __('no_bookmarks1'); ?></h4>
                    <p class="text-muted"><?php echo __('no_bookmarks2'); ?></p>
                    <a href="index.php" class="btn btn-primary mt-3">
                        <i class="fas fa-search me-2"></i><?php echo __('favorites_find_books'); ?>

                    </a>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($bookmarks as $bm): ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="card h-100 shadow-sm">
                            <div class="card-body text-muted">

                                <h5><?php echo htmlspecialchars($bm['book_title'] ?: "<?php echo __('no_name_book'); ?>"); ?></h5>
                                <p class="text-muted"><?php echo htmlspecialchars($bm['book_author'] ?: "<?php echo __('incognito'); ?>"); ?></p>
                                <div>
                                    <span class="badge bg-info"><?php echo round($bm['percentage']) . '%'; ?></span>
                                    <span class="badge bg-secondary"><?php echo strtoupper($bm['file_type']); ?></span>
                                    <?php if (!empty($bm['note']) && $bm['note'] !== "<?php echo __('last_read'); ?>"): ?>
                                        <span class="badge bg-warning text-dark ms-1" title="<?php echo htmlspecialchars($bm['note']); ?>">
                                            <i class="fas fa-tag me-1"></i>
                                            <?php echo htmlspecialchars(mb_substr($bm['note'], 0, 15)); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted d-block mt-2">
                                    <i class="fas fa-clock me-1"></i>
                                    <?php echo date('d.m.Y H:i', strtotime($bm['updated_at'] ?? $bm['created_at'])); ?>
                                </small>
                            </div>
                            <div class="card-footer bg-transparent">
                                <a href="reader.php?id=<?php echo $bm['book_id']; ?>&page=<?php echo max(1, $bm['page_number'] ?? 1); ?>"
                                   class="btn btn-sm btn-primary">
                                    <i class="fas fa-book-open me-1"></i><?php echo __('bookmark_read'); ?>
                                </a>
                                <button class="btn btn-sm btn-danger float-end delete-bookmark"
                                        data-id="<?php echo $bm['id']; ?>"
                                        title=<?php echo __('delete_bookmark'); ?>>
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== ВКЛАДКА 2: ЗАМЕТКИ И ЦИТАТЫ ===== -->
        <div class="tab-pane fade <?php echo $activeTab === 'notes' ? 'show active' : ''; ?>"
             id="notes" role="tabpanel">

            <!-- Фильтры для заметок -->
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <form method="get" class="row g-3 align-items-end">
                        <input type="hidden" name="tab" value="notes">

                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-muted"><?php echo __('search_notes'); ?></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" name="q"
                                       value="<?php echo htmlspecialchars($filter['search']); ?>"
                                       placeholder="<?php echo __('search_to_notes'); ?>">
                            </div>
                        </div>


                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted"><?php echo __('filter_by_type'); ?></label>
                            <select class="form-select" name="type">
                                <option value="all" <?php echo $filter['type'] === 'all' ? 'selected' : ''; ?>><?php echo __('notes_all'); ?></option>
                                <option value="quote" <?php echo $filter['type'] === 'quote' ? 'selected' : ''; ?>><?php echo __('notes_c'); ?></option>
                                <option value="note" <?php echo $filter['type'] === 'note' ? 'selected' : ''; ?>><?php echo __('notes_z'); ?></option>
                                <option value="highlight" <?php echo $filter['type'] === 'highlight' ? 'selected' : ''; ?>><?php echo __('notes_color'); ?></option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted"><?php echo __('sort_by'); ?></label>
                            <select class="form-select" name="sort">
                                <option value="date_desc" <?php echo $filter['sort'] === 'date_desc' ? 'selected' : ''; ?>><?php echo __('sort_newest'); ?></option>
                                <option value="date_asc" <?php echo $filter['sort'] === 'date_asc' ? 'selected' : ''; ?>><?php echo __('sort_oldest'); ?></option>
                                <option value="page_asc" <?php echo $filter['sort'] === 'page_asc' ? 'selected' : ''; ?>><?php echo __('sort_by_page'); ?></option>
                                <option value="book_asc" <?php echo $filter['sort'] === 'book_asc' ? 'selected' : ''; ?>><?php echo __('sort_by_book'); ?></option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-1"></i> <?php echo __('filter_apply'); ?>
                            </button>
                        </div>
                    </form>

                    <!-- Быстрые фильтры по книгам -->
                    <?php if (!empty($booksWithNotes)): ?>
                    <div class="mt-3">
                        <small class="text-muted d-block mb-2"><?php echo __('books_with_notes'); ?></small>
                        <div class="d-flex flex-wrap gap-1">
                            <a href="?tab=notes&type=<?php echo $filter['type']; ?>&sort=<?php echo $filter['sort']; ?>"
                               class="btn btn-sm <?php echo $filter['book_id'] == 0 ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                                <?php echo __('notes_all'); ?>

                            </a>
                            <?php foreach ($booksWithNotes as $book): ?>
                                <a href="?tab=notes&type=<?php echo $filter['type']; ?>&sort=<?php echo $filter['sort']; ?>&book_id=<?php echo $book['id']; ?>"
                                   class="btn btn-sm <?php echo $filter['book_id'] == $book['id'] ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                                   title="<?php echo htmlspecialchars($book['title']); ?> (<?php echo $book['count']; ?>)">
                                    <?php echo htmlspecialchars(mb_substr($book['title'], 0, 15)); ?>
                                    <span class="badge bg-light text-dark ms-1"><?php echo $book['count']; ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($annotations)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-sticky-note fa-4x text-muted mb-3 d-block"></i>
                    <h4 class="text-muted"><?php echo __('no_notes'); ?></h4>
                    <p class="text-muted"><?php echo __('no_notes_desc'); ?></p>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($annotations as $ann):
                        $type = $ann['type'] ?? 'note';
                        $typeInfo = $typeIcons[$type] ?? $typeIcons['note'];
                        $color = $ann['color'] ?? 'yellow';
                        $bgColor = $highlightColors[$color] ?? '#f8f9fa';
                        ?>
                        <div class="col-12 mb-3">
                            <div class="card shadow-sm annotation-card" data-type="<?php echo $type; ?>">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center mb-2">
                                                <span class="badge bg-<?php echo $typeInfo['color']; ?> me-2">
                                                    <i class="fas <?php echo $typeInfo['icon']; ?> me-1"></i>
                                                    <?php echo $typeInfo['label']; ?>
                                                </span>

                                                <a href="book_detail.php?id=<?php echo $ann['book_id']; ?>"
                                                   class="text-decoration-none fw-bold">
                                                    <?php echo htmlspecialchars($ann['book_title'] ?: 'Без названия'); ?>
                                                </a>

                                                <?php if (!empty($ann['book_author'])): ?>
                                                    <span class="text-muted ms-2 small">
                                                        — <?php echo htmlspecialchars($ann['book_author']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!empty($ann['selected_text'])): ?>
                                                <div class="selected-text mb-2 p-2 rounded"
                                                     style="background-color: <?php echo $bgColor; ?>; border-left: 4px solid <?php echo $bgColor; ?>;">
                                                    <i class="fas fa-quote-left text-muted me-1"></i>
                                                    <?php echo nl2br(htmlspecialchars($ann['selected_text'])); ?>
                                                    <i class="fas fa-quote-right text-muted ms-1"></i>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($ann['note']) && $ann['note'] !== 'Последнее прочитанное'): ?>
                                                <div class="note-content mt-2">
                                                    <i class="fas fa-pen text-muted me-1"></i>
                                                    <?php echo nl2br(htmlspecialchars($ann['note'])); ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="mt-2 d-flex flex-wrap gap-3">
                                                <small class="text-muted">
                                                    <i class="fas fa-bookmark me-1"></i>
                                                    <?php echo __('bookmark_read'); ?> <?php echo $ann['page_number'] ?? 1; ?>
                                                    (<?php echo round($ann['percentage'] ?? 0); ?>%)
                                                </small>

                                                <small class="text-muted">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?php echo date('d.m.Y H:i', strtotime($ann['updated_at'] ?? $ann['created_at'])); ?>
                                                </small>

                                                <?php if ($ann['file_type']): ?>
                                                    <small class="text-muted">
                                                        <span class="badge bg-secondary"><?php echo strtoupper($ann['file_type']); ?></span>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="reader.php?id=<?php echo $ann['book_id']; ?>&page=<?php echo max(1, $ann['page_number'] ?? 1); ?>"
                                                   class="btn btn-outline-primary"
                                                   title="Перейти к месту в книге">
                                                    <i class="fas fa-book-open me-1"></i> <?php echo __('bookmark_read'); ?>
                                                </a>

                                                <button class="btn btn-outline-danger delete-annotation"
                                                        data-id="<?php echo $ann['id']; ?>"
                                                        title="Удалить заметку">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="text-center mt-4">
                    <button id="exportNotesBtn" class="btn btn-success">
                        <i class="fas fa-file-download me-2"></i>
                        Экспортировать все заметки (Markdown)
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Стили -->
<style>
.annotation-card {
    transition: all 0.2s ease;
}
.annotation-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important;
}
.selected-text {
    font-style: italic;
    font-size: 0.95rem;
    color: #333;
    background-color: #f8f9fa;
    padding: 10px 14px;
    border-radius: 6px;
    border-left: 4px solid #ffc107;
}
.note-content {
    font-size: 0.9rem;
    color: #555;
    background: #f8f9fa;
    padding: 8px 12px;
    border-radius: 6px;
    border-left: 3px solid #17a2b8;
}
.btn-group .btn {
    border-radius: 4px;
}
.btn-group .btn:first-child {
    border-radius: 4px 0 0 4px;
}
.btn-group .btn:last-child {
    border-radius: 0 4px 4px 0;
}
.nav-tabs .nav-link {
    color: #495057;
}
.nav-tabs .nav-link.active {
    font-weight: 600;
}
@media (max-width: 768px) {
    .btn-group {
        width: 100%;
    }
    .btn-group .btn {
        flex: 1;
    }
}
</style>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== УДАЛЕНИЕ ЗАКЛАДКИ =====
    document.querySelectorAll('.delete-bookmark').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Удалить закладку?')) return;

            const id = this.dataset.id;
            const fingerprint = getCookie('device_fp');

            fetch('./api/bookmarks.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'delete',
                    bookmark_id: id,
                    fingerprint: fingerprint
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const card = this.closest('.col-md-6, .col-lg-4');
                    if (card) {
                        card.style.opacity = '0';
                        card.style.transition = 'opacity 0.3s';
                        setTimeout(() => {
                            card.remove();
                            const remaining = document.querySelectorAll('#bookmarks .col-md-6, #bookmarks .col-lg-4').length;
                            if (remaining === 0) {
                                location.reload();
                            }
                        }, 300);
                    }
                } else {
                    alert('Ошибка при удалении');
                }
            })
            .catch(console.error);
        });
    });

    // ===== УДАЛЕНИЕ ЗАМЕТКИ =====
    document.querySelectorAll('.delete-annotation').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Удалить заметку?')) return;

            const id = this.dataset.id;
            const fingerprint = getCookie('device_fp');

            fetch('./api/bookmarks.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'delete',
                    bookmark_id: id,
                    fingerprint: fingerprint
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const card = this.closest('.col-12');
                    if (card) {
                        card.style.opacity = '0';
                        card.style.transition = 'opacity 0.3s';
                        setTimeout(() => {
                            card.remove();
                            const remaining = document.querySelectorAll('#notes .annotation-card').length;
                            if (remaining === 0) {
                                location.reload();
                            }
                        }, 300);
                    }
                } else {
                    alert('Ошибка при удалении');
                }
            })
            .catch(console.error);
        });
    });

    // ===== ЭКСПОРТ =====
    document.getElementById('exportNotesBtn')?.addEventListener('click', function() {
        const params = new URLSearchParams({
            action: 'export_annotations',
            format: 'markdown'
        });
        window.location.href = './api/bookmarks.php?' + params.toString();
    });
});

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? match[2] : null;
}
</script>

<?php require 'templates/footer.php'; ?>
