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
            ['label' => __('dashboard_total_series'), 'value' => '0', 'icon' => 'fa-layer-group', 'color' => 'warning'],
        ];

        try {
            $stmt = $this->db->getConnection()->query('SELECT COUNT(*) as count FROM books');
            $stats[0]['value'] = number_format($stmt->fetchColumn());

            $stmt = $this->db->getConnection()->query("SELECT COUNT(DISTINCT author) as count FROM books WHERE author IS NOT NULL AND author != ''");
            $stats[1]['value'] = number_format($stmt->fetchColumn());

            $stmt = $this->db->getConnection()->query("SELECT COUNT(DISTINCT genre) as count FROM books WHERE genre IS NOT NULL AND genre != ''");
            $stats[2]['value'] = number_format($stmt->fetchColumn());

            $stmt = $this->db->getConnection()->query("SELECT COUNT(DISTINCT series) as count FROM books WHERE series IS NOT NULL AND series != ''");
            $stats[3]['value'] = number_format($stmt->fetchColumn());
        } catch (Exception $e) {
            error_log('Error getting stats: '.$e->getMessage());
            // Оставляем значения по умолчанию
        }

        return $stats;
    }

    public function getSystemInfo()
    {
        $info = [
            __('dashboard_php_version') => PHP_VERSION,
            __('dashboard_db_type') => strtoupper(Config::getDbType()),
            __('dashboard_caching') => Config::ENABLE_CACHE ? __('dashboard_enabled') : __('dashboard_disabled'),
            __('dashboard_apcu') => extension_loaded('apcu') ? __('dashboard_available') : __('dashboard_unavailable'),
            __('dashboard_memory_limit') => ini_get('memory_limit'),
            __('dashboard_books_dir') => basename(Config::getBooksDir()),
        ];

        return $info;
    }

    public function getRecentBooks($limit = 10)
    {
        try {
            $stmt = $this->db->getConnection()->prepare(
                'SELECT id, title, author, added_date 
                 FROM books 
                 ORDER BY added_date DESC 
                 LIMIT ?'
            );
            $stmt->execute([$limit]);

            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('Error getting recent books: '.$e->getMessage());

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
            'status_class' => '',
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
                'SELECT MAX(last_scanned) as last FROM archives'
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

    public function getChartData()
    {
        // Получаем данные для графика добавлений книг по дням недели
        $chartData = [
            'labels' => [
                __('dashboard_monday'),
                __('dashboard_tuesday'),
                __('dashboard_wednesday'),
                __('dashboard_thursday'),
                __('dashboard_friday'),
                __('dashboard_saturday'),
                __('dashboard_sunday'),
            ],
            'data' => [0, 0, 0, 0, 0, 0, 0],
        ];

        try {
            $dbType = Config::getDbType();

            if ('sqlite' === $dbType) {
                $sql = "SELECT 
                            strftime('%w', added_date) as dow,
                            COUNT(*) as count
                        FROM books
                        WHERE added_date >= date('now', '-7 days')
                        GROUP BY dow
                        ORDER BY dow";
            } else {
                $sql = 'SELECT 
                            WEEKDAY(added_date) as dow,
                            COUNT(*) as count
                        FROM books
                        WHERE added_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        GROUP BY dow
                        ORDER BY dow';
            }

            $stmt = $this->db->getConnection()->query($sql);
            $results = $stmt->fetchAll();

            foreach ($results as $row) {
                $dow = (int) $row['dow'];
                // В SQLite воскресенье = 0, в MySQL понедельник = 0
                if ('sqlite' === $dbType) {
                    // Преобразуем воскресенье (0) в 6 для массива
                    $index = 0 == $dow ? 6 : $dow - 1;
                } else {
                    $index = $dow;
                }
                if ($index >= 0 && $index < 7) {
                    $chartData['data'][$index] = (int) $row['count'];
                }
            }
        } catch (Exception $e) {
            error_log('Error getting chart data: '.$e->getMessage());
        }

        return $chartData;
    }

    /**
     * Получить статистику по форматам книг для графика.
     */
    public function getFormatStats()
    {
        $formats = [];

        try {
            $stmt = $this->db->getConnection()->query(
                'SELECT file_type, COUNT(*) as count 
                 FROM books 
                 WHERE file_type IS NOT NULL 
                 GROUP BY file_type 
                 ORDER BY count DESC 
                 LIMIT 10'
            );
            $results = $stmt->fetchAll();

            foreach ($results as $row) {
                $formats[] = [
                    'format' => strtoupper($row['file_type']),
                    'count' => (int) $row['count'],
                ];
            }
        } catch (Exception $e) {
            error_log('Error getting format stats: '.$e->getMessage());
        }

        return $formats;
    }

    /**
     * Получить последние действия в логах.
     */
    public function getRecentLogs($limit = 5)
    {
        $logs = [];
        $logFile = Config::getCacheDir().'/system.log';

        if (file_exists($logFile) && is_readable($logFile)) {
            try {
                $handle = fopen($logFile, 'r');
                if ($handle) {
                    // Перемещаемся в конец файла
                    fseek($handle, -min(filesize($logFile), 10240), SEEK_END);
                    $lines = [];
                    while (!feof($handle)) {
                        $line = fgets($handle);
                        if (false !== $line) {
                            $lines[] = trim($line);
                            if (count($lines) > $limit) {
                                array_shift($lines);
                            }
                        }
                    }
                    fclose($handle);

                    // Разбираем строки лога
                    foreach ($lines as $line) {
                        if (preg_match('/\[(.*?)\] \[(.*?)\] (.*?) - (.*)/', $line, $matches)) {
                            $logs[] = [
                                'time' => $matches[1],
                                'level' => $matches[2],
                                'ip' => $matches[3],
                                'message' => $matches[4],
                                'level_class' => $this->getLogLevelClass($matches[2]),
                            ];
                        } else {
                            $logs[] = [
                                'time' => '',
                                'level' => 'INFO',
                                'ip' => '',
                                'message' => $line,
                                'level_class' => 'secondary',
                            ];
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('Error reading logs: '.$e->getMessage());
            }
        }

        return $logs;
    }

    /**
     * Получить класс CSS для уровня лога.
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
     * Получить статистику использования диска.
     */
    public function getDiskStats()
    {
        $stats = [
            'total' => 0,
            'free' => 0,
            'used' => 0,
            'percent_used' => 0,
            'total_formatted' => '0 B',
            'free_formatted' => '0 B',
            'used_formatted' => '0 B',
        ];

        $booksDir = Config::getBooksDir();

        if (file_exists($booksDir)) {
            $total = disk_total_space($booksDir);
            $free = disk_free_space($booksDir);
            $used = $total - $free;

            $stats['total'] = $total;
            $stats['free'] = $free;
            $stats['used'] = $used;
            $stats['percent_used'] = $total > 0 ? round(($used / $total) * 100, 1) : 0;
            $stats['total_formatted'] = $this->formatBytes($total);
            $stats['free_formatted'] = $this->formatBytes($free);
            $stats['used_formatted'] = $this->formatBytes($used);
        }

        return $stats;
    }

    /**
     * Форматировать байты.
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision).' '.$units[$pow];
    }
}
