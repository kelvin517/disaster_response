<?php
/**
 * Alert Functions - SMS and Notification Broadcasting
 */

require_once __DIR__ . '/sms.php';

/**
 * Broadcast alert to affected users with SMS capability
 * 
 * @param string $title Alert title
 * @param string $message Alert message
 * @param string $priority Priority level (emergency, urgent, warning, info)
 * @param array $targetArea Target area configuration
 * @param PDO $pdo Database connection
 * @return array Broadcast result
 */
function broadcastAlert($title, $message, $priority, $targetArea, $pdo) {
    $user_id = $_SESSION['user_id'] ?? 0;
    
    // Determine if SMS should be sent based on priority
    $send_sms = in_array($priority, ['emergency', 'urgent', 'warning']);
    
    // Format target area for database
    $target_area_json = json_encode($targetArea);
    
    // Insert into alerts table
    $stmt = $pdo->prepare("
        INSERT INTO alerts (alert_type, title, message, priority, affected_area, created_by, send_sms, expires_at, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())
    ");
    $stmt->execute([$priority, $title, $message, $priority, $target_area_json, $user_id, $send_sms]);
    $alert_id = $pdo->lastInsertId();
    
    $result = [
        'success' => true,
        'alert_id' => $alert_id,
        'sms_sent' => 0,
        'sms_failed' => 0,
        'recipients' => 0
    ];
    
    // Only send SMS for high-priority alerts
    if ($send_sms) {
        // Determine target users based on area
        $users = getTargetUsers($targetArea, $pdo);
        $result['recipients'] = count($users);
        
        $alert_message = "[$priority] $title\n\n$message\n\nReply STOP to unsubscribe";
        
        foreach ($users as $user) {
            if (!empty($user['phone'])) {
                $sms_result = sendSMS($user['phone'], $alert_message);
                
                // Log to queue
                $status = $sms_result['success'] ? 'sent' : 'failed';
                $error_msg = $sms_result['success'] ? null : $sms_result['message'];
                
                $stmt = $pdo->prepare("
                    INSERT INTO sms_queue (alert_id, recipient_phone, recipient_name, message, status, error_message, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$alert_id, $user['phone'], $user['full_name'], $alert_message, $status, $error_msg]);
                
                if ($sms_result['success']) {
                    $result['sms_sent']++;
                } else {
                    $result['sms_failed']++;
                }
            }
        }
    }
    
    return $result;
}

/**
 * Get target users based on area criteria
 * 
 * @param array $targetArea Area configuration
 * @param PDO $pdo Database connection
 * @return array List of users
 */
function getTargetUsers($targetArea, $pdo) {
    $users = [];
    $type = $targetArea['type'] ?? 'all';
    
    if ($type === 'all') {
        $stmt = $pdo->prepare("
            SELECT id, full_name, phone FROM users 
            WHERE phone IS NOT NULL AND phone != '' AND sms_subscribed = 1
        ");
        $stmt->execute();
        $users = $stmt->fetchAll();
    } elseif ($type === 'county') {
        $stmt = $pdo->prepare("
            SELECT id, full_name, phone FROM users 
            WHERE county = ? AND phone IS NOT NULL AND phone != '' AND sms_subscribed = 1
        ");
        $stmt->execute([$targetArea['county']]);
        $users = $stmt->fetchAll();
    } elseif ($type === 'radius') {
        // Simplified - in production, implement Haversine formula with user locations
        $stmt = $pdo->prepare("
            SELECT id, full_name, phone FROM users 
            WHERE phone IS NOT NULL AND phone != '' AND sms_subscribed = 1
        ");
        $stmt->execute();
        $users = $stmt->fetchAll();
    }
    
    return $users;
}
?>