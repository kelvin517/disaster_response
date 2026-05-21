<?php
class Logger {
    private static $instance = null;
    private $log_dir;
    private function __construct() {
        $this->log_dir = dirname(__DIR__) . '/logs/';
        if (!is_dir($this->log_dir)) mkdir($this->log_dir, 0755, true);
    }
    public static function getInstance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    public function info($message, $file = 'app.log') { $this->writeLog('INFO', $message, $file); }
    public function error($message, $file = 'app.log') { $this->writeLog('ERROR', $message, $file); }
    public function audit($message, $details = null, $file = 'audit.log') { $this->writeLog('AUDIT', $message . ($details ? " - " . json_encode($details) : ""), $file); }
    public function sms($phone, $message, $status, $file = 'sms.log') { $this->writeLog('SMS', "to={$phone}, status={$status}, msg=" . substr($message, 0, 100), $file); }
    private function writeLog($level, $message, $file) {
        $timestamp = date('Y-m-d H:i:s');
        $user_id = $_SESSION['user_id'] ?? 'system';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $log_entry = sprintf("[%s] [%s] [User: %s] [IP: %s] %s\n", $timestamp, $level, $user_id, $ip, $message);
        file_put_contents($this->log_dir . '/' . $file, $log_entry, FILE_APPEND | LOCK_EX);
    }
}
if (!function_exists('logger')) { function logger() { return Logger::getInstance(); } }
?>
