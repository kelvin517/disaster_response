<?php
/**
 * Custom Logger Class
 * Disaster Response & Resource Coordination System
 * 
 * Handles application logging with different levels
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
        $this->log_dir = __DIR__ . '/../logs/';
        if (!is_dir($this->log_dir)) {
            mkdir($this->log_dir, 0755, true);
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
        
        $log_entry = sprintf(
            "[%s] [%s] [User: %s] [IP: %s] %s\n",
            $timestamp,
            $level,
            $user_id,
            $ip,
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
    
    public function audit($action, $details = null) {
        $details_str = $details ? " - " . json_encode($details) : "";
        $this->log('INFO', "AUDIT: {$action}{$details_str}", 'audit.log');
    }
    
    public function sms($phone, $message, $status) {
        $this->log('INFO', "SMS: to={$phone}, status={$status}, msg=" . substr($message, 0, 100), 'sms.log');
    }
    
    public function api($endpoint, $method, $status, $response_time) {
        $this->log('INFO', "API: {$method} {$endpoint} - {$status} - {$response_time}ms", 'api.log');
    }
}

// Helper function for easy logging
function logger() {
    return Logger::getInstance();
}