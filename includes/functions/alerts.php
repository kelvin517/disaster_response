<?php
function sendSMS($phone, $message) {
    // Africa's Talking integration - placeholder
    // Use file_get_contents or curl to API
    return true; // Stub
}

function broadcastAlert($title, $message, $priority, $targetArea, $pdo) {
    // Insert into alerts table and send notifications
    $stmt = $pdo->prepare("INSERT INTO alerts (alert_type, message, priority, affected_area, created_by, expires_at) VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
    $stmt->execute([$title, $message, $priority, json_encode($targetArea), $_SESSION['user_id']]);
    // Then fetch affected users and send SMS/in-app
}
?>