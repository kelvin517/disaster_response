<?php
/**
 * Logger Class
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Handles application logging for debugging, audit trails, and error tracking
 */

class Logger {
    private static $instance = null;
    private $log_dir;
    private $log_levels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];
    private $current_level = 1; // Default: INFO
    
    private function __construct() {
        // Determine log directory path
        $this->log_dir = dirname(__DIR__) . '/logs/';
        
        // Create logs directory if it doesn't exist
        if (!is_dir($this->log_dir)) {
            mkdir($this->log_dir, 0755, true);
        }
        
        // Create default log files if they don't exist
        $log_files = ['app.log', 'audit.log', 'sms.log', 'api.log', 'cron.log', 'php_errors.log'];
        foreach ($log_files as $file) {
            $file_path = $this->log_dir . $file;
            if (!file_exists($file_path)) {
                file_put_contents($file_path, "# Log file created: " . date('Y-m-d H:i:s') . "\n");
                chmod($file_path, 0644);
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function setLevel($level) {
        if (isset($this->log_levels[strtoupper($level)])) {
            $this->current_level = $this->log_levels[strtoupper($level)];
        }
        return $this;
    }
    
    private function log($level, $message, $file = 'app.log') {
        if ($this->log_levels[$level] < $this->current_level) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $user_id = $_SESSION['user_id'] ?? 'system';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $script = $_SERVER['SCRIPT_NAME'] ?? 'unknown';
        
        $log_entry = sprintf(
            "[%s] [%s] [User: %s] [IP: %s] [File: %s] %s\n",
            $timestamp,
            $level,
            $user_id,
            $ip,
            basename($script),
            $message
        );
        
        $log_path = $this->log_dir . '/' . $file;
        file_put_contents($log_path, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    public function debug($message, $file = 'app.log') {
        $this->log('DEBUG', $message, $file);
    }
    
    public function info($message, $file = 'app.log') {
        $this->log('INFO', $message, $file);
    }
    
    public function warning($message, $file = 'app.log') {
        $this->log('WARNING', $message, $file);
    }
    
    public function error($message, $file = 'app.log') {
        $this->log('ERROR', $message, $file);
    }
    
    public function critical($message, $file = 'app.log') {
        $this->log('CRITICAL', $message, $file);
    }
    
    public function audit($action, $details = null, $file = 'audit.log') {
        $details_str = $details ? " - " . json_encode($details) : "";
        $this->log('INFO', "AUDIT: {$action}{$details_str}", $file);
    }
    
    public function sms($phone, $message, $status, $file = 'sms.log') {
        $this->log('INFO', "SMS: to={$phone}, status={$status}, msg=" . substr($message, 0, 100), $file);
    }
    
    public function api($endpoint, $method, $status, $response_time, $file = 'api.log') {
        $this->log('INFO', "API: {$method} {$endpoint} - {$status} - {$response_time}ms", $file);
    }
    
    public function cron($task, $status, $details = null, $file = 'cron.log') {
        $details_str = $details ? " - " . $details : "";
        $this->log('INFO', "CRON: {$task} - {$status}{$details_str}", $file);
    }
}

// Helper function for easy logging
if (!function_exists('logger')) {
    function logger() {
        return Logger::getInstance();
    }
}
?>