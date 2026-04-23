<?php

// admin/DashboardWidgets.php

class DashboardWidgets
{
    private $db;
    private $scanner;

    public function __construct($db, $scanner)
    {
        $this->db = $db;
        $this->scanner = $scanner;
    }

    public function getStatistics()
    {
        $stats = [
            ['label' => __('dashboard_total_books'), 'value' => '0', 'icon' => 'fa-book', 'color' => 'primary'],
            ['label' => __('dashboard_total_authors'), 'value' => '0', 'icon' => 'fa-users', 'color' => 'success'],
            ['label' => __('dashboard_total_genres'), 'value' => '0', 'icon' => 'fa-tags', 'color' => 'info'],
            ['label' => __('dashboard_total_series'), 'value' => '0', 'icon' => 'fa-layer-group', 'color' => 'warning']
        ];

        try {
            $stmt = $this->db->getConnection()->query("SELECT COUNT(*) as count FROM books");
            $stats[0]['value'] = number_format($stmt->fetchColumn());

            $stmt = $this->db->getConnection()->query("SELECT COUNT(DISTINCT author) as count FROM books WHERE author IS NOT NULL AND author != ''");
            $stats[1]['value'] = number_format($stmt->fetchColumn());

            $stmt = $this->db->getConnection()->query("SELECT COUNT(DISTINCT genre) as count FROM books WHERE genre IS NOT NULL AND genre != ''");
            $stats[2]['value'] = number_format($stmt->fetchColumn());

            $stmt = $this->db->getConnection()->query("SELECT COUNT(DISTINCT series) as count FROM books WHERE series IS NOT NULL AND series != ''");
            $stats[3]['value'] = number_format($stmt->fetchColumn());
        } catch (Exception $e) {
            error_log("Error getting stats: " . $e->getMessage());
            // Оставляем значения по умолчанию
        }

        return $stats;
    }

    public function getSystemInfo()
    {
        $info = [
            __('dashboard_php_version') => PHP_VERSION,
            __('dashboard_db_type') => strtoupper(Config::getDbType()),
            __('dashboard_caching') => Config::isCacheEnabled() ? __('dashboard_enabled') : __('dashboard_disabled'),
            __('dashboard_apcu') => extension_loaded('apcu') ? __('dashboard_available') : __('dashboard_unavailable'),
            __('dashboard_memory_limit') => ini_get('memory_limit'),
            __('dashboard_books_dir') => basename(Config::getBooksDir())
        ];

        return $info;
    }

    public function getRecentBooks($limit = 10)
    {
        try {
            $stmt = $this->db->getConnection()->prepare(
                "SELECT id, title, author, added_date 
                 FROM books 
                 ORDER BY added_date DESC 
                 LIMIT ?"
            );
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error getting recent books: " . $e->getMessage());
            return [];
        }
    }

    public function getScannerStatus()
    {
        $status = [
            'available' => $this->scanner->isAvailable(),
            'running' => $this->scanner->isRunning(),
            'scanner_path' => Config::getScannerPath(),
            'last_scan' => null,
            'status_text' => '',
            'status_class' => ''
        ];

        // Добавляем текст статуса
        if ($status['available']) {
            if ($status['running']) {
                $status['status_text'] = __('dashboard_scanner_running');
                $status['status_class'] = 'warning';
            } else {
                $status['status_text'] = __('dashboard_scanner_ready');
                $status['status_class'] = 'success';
            }
        } else {
            $status['status_text'] = __('dashboard_scanner_unavailable');
            $status['status_class'] = 'danger';
        }

        // Получаем время последнего сканирования
        try {
            $stmt = $this->db->getConnection()->query(
                "SELECT MAX(last_scanned) as last FROM archives"
            );
            $result = $stmt->fetch();
            if ($result && $result['last']) {
                $status['last_scan'] = $result['last'];
                $status['last_scan_formatted'] = date('d.m.Y H:i', strtotime($result['last']));
            }
        } catch (Exception $e) {
            // Игнорируем ошибки
        }

        return $status;
    }

    public function getCacheStats()
    {
        return Cache::getStats();
    }


    /**
     * Получить класс CSS для уровня лога
     */
    private function getLogLevelClass($level)
    {
        switch (strtoupper($level)) {
            case 'ERROR':
                return 'danger';
            case 'WARNING':
                return 'warning';
            case 'SUCCESS':
                return 'success';
            case 'INFO':
                return 'info';
            default:
                return 'secondary';
        }
    }


    /**
     * Форматировать байты
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
