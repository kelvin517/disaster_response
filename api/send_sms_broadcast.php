<?php
/**
 * API: Send SMS Broadcast
 * Handles SMS broadcasting from admin dashboard using sms.php functions
 */

// Disable error display for API responses
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set JSON content type header
header('Content-Type: application/json');

try {
    session_start();
    
    // Check if user is logged in and is admin
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login as admin.']);
        exit;
    }
    
    // Include config files with absolute path
    $doc_root = $_SERVER['DOCUMENT_ROOT'];
    $base_path = dirname(dirname(__FILE__));
    
    $config_file = $base_path . '/includes/config/config.php';
    $sms_file = $base_path . '/includes/functions/sms.php';
    
    if (!file_exists($config_file)) {
        echo json_encode(['success' => false, 'message' => 'Configuration file not found']);
        exit;
    }
    
    require_once $config_file;
    
    if (!file_exists($sms_file)) {
        echo json_encode(['success' => false, 'message' => 'SMS functions file not found']);
        exit;
    }
    
    require_once $sms_file;
    
    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
        exit;
    }
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }
    
    // Validate input
    if (empty($input['title']) || empty($input['message'])) {
        echo json_encode(['success' => false, 'message' => 'Title and message are required']);
        exit;
    }
    
    $alert_type = $input['alert_type'] ?? 'warning';
    $title = trim($input['title']);
    $message = trim($input['message']);
    $target_audience = $input['target_audience'] ?? 'all';
    $county = $input['county'] ?? null;
    $priority = $input['priority'] ?? 'normal';
    $save_to_db = $input['save_to_db'] ?? true;
    $individual_phone = $input['individual_phone'] ?? null;
    
    // Get target users based on audience selection
    $users = [];
    
    if ($target_audience === 'individual' && $individual_phone) {
        // Send to individual phone number
        $phone = preg_replace('/[^0-9]/', '', $individual_phone);
        
        // Format to 254 format
        if (strlen($phone) == 10 && substr($phone, 0, 1) == '0') {
            $phone = '254' . substr($phone, 1);
        } elseif (strlen($phone) == 9) {
            $phone = '254' . $phone;
        } elseif (strlen($phone) == 13 && substr($phone, 0, 4) == '+254') {
            $phone = substr($phone, 1);
        }
        
        $users = [['id' => 0, 'full_name' => 'Individual', 'phone' => $phone]];
        
    } elseif ($target_audience === 'all') {
        // Get all subscribed users with valid phone numbers
        $stmt = $pdo->prepare("
            SELECT id, full_name, phone FROM users 
            WHERE phone IS NOT NULL 
            AND phone != '' 
            AND LENGTH(phone) >= 10
            AND (sms_subscribed = 1 OR sms_subscribed IS NULL)
            AND is_active = 1
        ");
        $stmt->execute();
        $users = $stmt->fetchAll();
        
    } elseif ($target_audience === 'county' && $county) {
        // Get users in specific county
        $stmt = $pdo->prepare("
            SELECT id, full_name, phone FROM users 
            WHERE county = ? 
            AND phone IS NOT NULL 
            AND phone != '' 
            AND (sms_subscribed = 1 OR sms_subscribed IS NULL)
            AND is_active = 1
        ");
        $stmt->execute([$county]);
        $users = $stmt->fetchAll();
        
    } elseif ($target_audience === 'affected') {
        // Get all subscribed users
        $stmt = $pdo->prepare("
            SELECT id, full_name, phone FROM users 
            WHERE phone IS NOT NULL 
            AND phone != '' 
            AND (sms_subscribed = 1 OR sms_subscribed IS NULL)
            AND is_active = 1
        ");
        $stmt->execute();
        $users = $stmt->fetchAll();
    }
    
    // If no users found
    if (empty($users)) {
        echo json_encode([
            'success' => false, 
            'message' => 'No users found matching the selected criteria'
        ]);
        exit;
    }
    
    // Prepare SMS message with priority prefix
    $priority_prefix = '';
    switch ($priority) {
        case 'emergency':
            $priority_prefix = "🚨 EMERGENCY ALERT 🚨\n\n";
            break;
        case 'urgent':
            $priority_prefix = "⚠️ URGENT ⚠️\n\n";
            break;
        default:
            $priority_prefix = "📱 ALERT 📱\n\n";
    }
    
    $alert_prefix = strtoupper($alert_type);
    $full_message = $priority_prefix . "[$alert_prefix] $title\n\n$message\n\n---\nDisaster Response System\nReply STOP to unsubscribe";
    
    // Truncate message to 160 characters for SMS
    $full_message = substr($full_message, 0, 160);
    
    // Send SMS to all users using the sendSMS function from sms.php
    $sent = 0;
    $failed = 0;
    $results = [];
    
    foreach ($users as $user) {
        // Format phone number
        $phone = preg_replace('/[^0-9]/', '', $user['phone']);
        
        // Format to 254 format
        if (strlen($phone) == 10 && substr($phone, 0, 1) == '0') {
            $phone = '254' . substr($phone, 1);
        } elseif (strlen($phone) == 9) {
            $phone = '254' . $phone;
        } elseif (strlen($phone) == 13 && substr($phone, 0, 4) == '+254') {
            $phone = substr($phone, 1);
        }
        
        // Only send if valid format
        if (strlen($phone) == 12 && substr($phone, 0, 3) == '254') {
            // Use the sendSMS function from sms.php
            $result = sendSMS($phone, $full_message);
            
            if ($result['success']) {
                $sent++;
                
                // Log to SMS queue
                if ($save_to_db) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO sms_queue (recipient_phone, recipient_name, message, status, created_at)
                            VALUES (?, ?, ?, 'sent', NOW())
                        ");
                        $stmt->execute([$user['phone'], $user['full_name'], $full_message]);
                    } catch (Exception $e) {
                        // Ignore logging errors
                    }
                }
            } else {
                $failed++;
            }
            
            $results[] = $result;
        } else {
            $failed++;
        }
    }
    
    // Save alert to database if requested
    $alert_id = null;
    if ($save_to_db && $target_audience !== 'individual') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO alerts (alert_type, title, message, created_by, created_at, expires_at)
                VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))
            ");
            $stmt->execute([$alert_type, $title, $message, $_SESSION['user_id']]);
            $alert_id = $pdo->lastInsertId();
        } catch (Exception $e) {
            // Ignore alert saving errors
        }
    }
    
    // Return response
    echo json_encode([
        'success' => true,
        'message' => "SMS broadcast completed",
        'recipients' => count($users),
        'sent' => $sent,
        'failed' => $failed,
        'alert_id' => $alert_id
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>