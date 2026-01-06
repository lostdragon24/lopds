<?php

require_once __DIR__ . '/../config/config.php';

class BookScanner {
    
    public static function runScan() {
        if (!file_exists(Config::SCANNER_PATH)) {
            throw new Exception('Scanner binary not found: ' . Config::SCANNER_PATH);
        }
        
        if (!file_exists(Config::SCANNER_CONFIG)) {
            throw new Exception('Scanner config not found: ' . Config::SCANNER_CONFIG);
        }
        
        $command = escapeshellcmd(Config::SCANNER_PATH) . ' ' . 
                  escapeshellarg(Config::SCANNER_CONFIG) . ' 2>&1';
        
        $output = [];
        $returnCode = 0;
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception('Scanner failed with code: ' . $returnCode . '. Output: ' . implode("\n", $output));
        }
        
        return $output;
    }
    
    public static function getScanStatus() {
        // Проверяем, запущен ли сканер
        $output = [];
        exec('pgrep -f "' . basename(Config::SCANNER_PATH) . '"', $output);
        return !empty($output);
    }
}
?>