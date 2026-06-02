<?php
require_once 'includes/config/config.php';
require_once 'includes/functions/sms.php';

// Test single SMS
$result = sendSMS('2547XXXXXXXX', 'Test message from Disaster Response System');
echo "<pre>";
print_r($result);
echo "</pre>";

// Test alert broadcast
$targetArea = ['type' => 'all'];
$broadcast = broadcastAlert('Test Alert', 'This is a test alert message', 'warning', $targetArea, $pdo);
print_r($broadcast);
?>