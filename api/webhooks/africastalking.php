<?php
/**
 * Africa's Talking Webhook Handler
 * Disaster Response & Resource Coordination System
 * 
 * Handles:
 *   - Inbound SMS from users
 *   - Delivery receipts for sent SMS
 * 
 * Endpoint: POST /api/webhooks/africastalking.php
 */

// No session needed for webhooks
header('Content-Type: application/json');

// Load configuration
require_once __DIR__ . '/../../includes/config/config.php';

// Log incoming webhook for debugging
error_log("Africa's Talking Webhook received: " . file_get_contents('php://input'));

// Handle inbound SMS
if (isset($_POST['text']) && isset($_POST['from'])) {
    $phone = $_POST['from'];
    $message = trim($_POST['text']);
    $date = $_POST['date'] ?? date('Y-m-d H:i:s');
    
    // Process the incoming message
    try {
        // Check if user exists with this phone number
        $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Log the incoming SMS
            $stmt = $pdo->prepare("
                INSERT INTO sms_inbound (user_id, phone, message, received_at, processed)
                VALUES (?, ?, ?, ?, 0)
            ");
            $stmt->execute([$user['id'], $phone, $message, $date]);
            
            // Process command if it starts with a keyword
            $message_lower = strtolower($message);
            
            if (strpos($message_lower, 'help') === 0) {
                // Send help response
                $response = "DisasterResponse Help:\n";
                $response .= "REPORT [type] [location] - Report an incident\n";
                $response .= "STATUS [id] - Check incident status\n";
                $response .= "HELP - Show this message\n";
                $response .= "STOP - Unsubscribe from alerts";
                
                // Queue response SMS
                $stmt = $pdo->prepare("
                    INSERT INTO sms_queue (recipient_phone, message, status, created_at)
                    VALUES (?, ?, 'pending', NOW())
                ");
                $stmt->execute([$phone, $response]);
                
            } elseif (strpos($message_lower, 'stop') === 0) {
                // Unsubscribe user from SMS alerts
                $stmt = $pdo->prepare("UPDATE users SET sms_subscribed = 0 WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                $response = "You have been unsubscribed from SMS alerts. Reply START to resubscribe.";
                $stmt = $pdo->prepare("INSERT INTO sms_queue (recipient_phone, message, status, created_at) VALUES (?, ?, 'pending', NOW())");
                $stmt->execute([$phone, $response]);
                
            } elseif (strpos($message_lower, 'start') === 0) {
                // Resubscribe user
                $stmt = $pdo->prepare("UPDATE users SET sms_subscribed = 1 WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                $response = "You have been resubscribed to SMS alerts.";
                $stmt = $pdo->prepare("INSERT INTO sms_queue (recipient_phone, message, status, created_at) VALUES (?, ?, 'pending', NOW())");
                $stmt->execute([$phone, $response]);
                
            } elseif (strpos($message_lower, 'report') === 0) {
                // Simple incident reporting via SMS
                // Format: REPORT flood Mathare area
                $parts = explode(' ', $message, 3);
                if (count($parts) >= 2) {
                    $incident_type = strtolower($parts[1]);
                    $location = $parts[2] ?? 'Unknown';
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO incidents (reporter_id, incident_type, description, location_name, status, reported_at)
                        VALUES (?, ?, ?, ?, 'reported', NOW())
                    ");
                    $stmt->execute([$user['id'], $incident_type, "Reported via SMS: " . $message, $location]);
                    $incident_id = $pdo->lastInsertId();
                    
                    $response = "Thank you! Incident #{$incident_id} has been reported. Responders have been notified.";
                    $stmt = $pdo->prepare("INSERT INTO sms_queue (recipient_phone, message, status, created_at) VALUES (?, ?, 'pending', NOW())");
                    $stmt->execute([$phone, $response]);
                }
            }
        } else {
            // Unknown user - log anonymous message
            $stmt = $pdo->prepare("
                INSERT INTO sms_inbound (phone, message, received_at, processed)
                VALUES (?, ?, ?, 0)
            ");
            $stmt->execute([$phone, $message, $date]);
        }
        
        // Acknowledge receipt to Africa's Talking
        echo json_encode(['status' => 'success']);
        exit();
        
    } catch (PDOException $e) {
        error_log("SMS inbound processing error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Processing failed']);
        exit();
    }
}

// Handle delivery receipts
if (isset($_POST['id']) && isset($_POST['status'])) {
    $message_id = $_POST['id'];
    $status = $_POST['status'];
    $cost = $_POST['cost'] ?? null;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE sms_queue 
            SET status = ?, 
                delivery_status = ?, 
                delivery_cost = ?,
                sent_at = NOW()
            WHERE message_id = ? OR (recipient_phone = ? AND status = 'pending')
            LIMIT 1
        ");
        $stmt->execute([
            $status === 'Success' ? 'sent' : 'failed',
            $status,
            $cost,
            $message_id,
            $_POST['to'] ?? null
        ]);
        
        echo json_encode(['status' => 'success']);
        exit();
        
    } catch (PDOException $e) {
        error_log("Delivery receipt error: " . $e->getMessage());
        echo json_encode(['status' => 'error']);
        exit();
    }
}

// No valid webhook data received
echo json_encode(['status' => 'ignored', 'message' => 'No valid webhook data']);