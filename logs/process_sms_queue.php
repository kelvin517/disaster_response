#!/usr/bin/env php
<?php
/**
 * Process SMS Queue - Run every 5 minutes via cron
 *5 * * * * php /path/to/cron/process_sms_queue.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/config/config.php';
require_once __DIR__ . '/../includes/functions/sms.php';

// Check if $pdo exists
if (!isset($pdo)) {
    die("Error: \$pdo not defined in config.php\n");
}

// Process the queue
$result = processSMSQueue($pdo);

// Output result
echo $result['message'] . "\n";

// Log to file
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0777, true);
}

$log_entry = date('Y-m-d H:i:s') . " - " . $result['message'] . "\n";
file_put_contents($log_dir . '/queue_processor.log', $log_entry, FILE_APPEND);
?>