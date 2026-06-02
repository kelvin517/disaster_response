<?php
// test_sms.php - Place this in your web root directory
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include config files with correct paths
require_once __DIR__ . '/includes/config/config.php';
require_once __DIR__ . '/includes/functions/sms.php';

// Get a valid user ID from database
try {
    $stmt = $pdo->query("SELECT id FROM users LIMIT 1");
    $user = $stmt->fetch();
    
    if (!$user) {
        die("No users found in database. Please create a user first.\n");
    }
    
    // Start session and set user ID
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user_id'] = $user['id'];
    
    echo "<pre>";
    echo "Using user ID: {$user['id']}\n\n";
    
    // Check phone numbers in database first
    echo "=== Phone Numbers in Database ===\n";
    $stmt = $pdo->query("SELECT id, full_name, phone, LENGTH(phone) as length FROM users WHERE phone IS NOT NULL AND phone != ''");
    $users = $stmt->fetchAll();
    foreach ($users as $u) {
        $valid = (strlen($u['phone']) == 12 && substr($u['phone'], 0, 3) == '254');
        echo "ID: {$u['id']}, Name: {$u['full_name']}, Phone: {$u['phone']}, Length: {$u['length']}, Valid: " . ($valid ? "YES" : "NO") . "\n";
    }
    
    echo "\n=== Testing Single SMS ===\n";
    // Get a valid phone number from database or use a test number
    $test_phone = '254700000000';
    if (!empty($users) && strlen($users[0]['phone']) == 12) {
        $test_phone = $users[0]['phone'];
    }
    echo "Sending test SMS to: $test_phone\n";
    $test_result = sendSMS($test_phone, 'Test message from Disaster Response System');
    print_r($test_result);
    
    echo "\n=== Testing Broadcast ===\n";
    // Test broadcast
    $result = broadcastAlert(
        'Test Alert Title', 
        'This is a test message body for the alert system.', 
        'warning', 
        ['type' => 'all'], 
        $pdo
    );
    
    echo "\nBroadcast Result:\n";
    print_r($result);
    
    echo "</pre>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString();
}
?>