<?php
/**
 * SMS Delivery Receipt Webhook
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Receives delivery receipts from Africa's Talking API
 * Endpoint: /modules/alerts/sms_callback.php
 */

// No session needed for webhook - this is called by Africa's Talking
header('Content-Type: application/json');

// Load configuration
require_once __DIR__ . '/../../includes/config/config.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get the raw input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Alternative: Africa's Talking sends data as form-data
if (empty($data)) {
    $data = $_POST;
}

// Log the incoming request for debugging
error_log("SMS Callback received: " . print_r($data, true));

// Process delivery receipts
if (isset($data['data'])) {
    // Format from Africa's Talking (standard)
    $results = $data['data'];
    
    foreach ($results as $result) {
        $phone_number = $result['phoneNumber'] ?? '';
        $status = $result['status'] ?? '';
        $message_id = $result['id'] ?? '';
        $cost = $result['cost'] ?? '';
        
        // Update SMS queue status
        if ($message_id) {
            // Map Africa's Talking status to our system
            $our_status = 'sent';
            if (in_array($status, ['Failed', 'Rejected', 'Rejected-DND'])) {
                $our_status = 'failed';
            } elseif ($status === 'Sent') {
                $our_status = 'sent';
            }
            
            $stmt = $pdo->prepare("
                UPDATE sms_queue 
                SET status = ?, 
                    sent_at = NOW(),
                    delivery_status = ?,
                    delivery_cost = ?
                WHERE message_id = ? OR (recipient_phone = ? AND status = 'pending')
            ");
            $stmt->execute([$our_status, $status, $cost, $message_id, $phone_number]);
        }
    }
    
    echo json_encode(['status' => 'success']);
    exit;
}

// Alternative format
if (isset($data['status']) && isset($data['phoneNumber'])) {
    $stmt = $pdo->prepare("
        UPDATE sms_queue 
        SET status = ?, 
            sent_at = NOW(),
            delivery_status = ?
        WHERE recipient_phone = ? AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$data['status'] == 'Success' ? 'sent' : 'failed', $data['status'], $data['phoneNumber']]);
    
    echo json_encode(['status' => 'success']);
    exit;
}

// If no matching format, just acknowledge
echo json_encode(['status' => 'received']);