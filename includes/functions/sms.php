<?php
/**
 * SMS Functions - Africa's Talking Integration
 * Disaster Response & Resource Coordination System
 */

/**
 * Send SMS using Africa's Talking API
 * 
 * @param string $phone Recipient phone number (Kenyan format: 2547XXXXXXXX)
 * @param string $message SMS message content (max 160 characters per segment)
 * @return array ['success' => bool, 'message' => string, 'data' => array]
 */
function sendSMS($phone, $message) {
    // Configuration
    $username = 'sandbox';
    $api_key = 'atsk_b59122ea545a590f16cc77b4cf492afdc07a44ccad8e37cbcef8519b0708ea6ea434e551';
    $from = 'DisasterResp';
    
    // Store original phone for logging
    $original_phone = $phone;
    
    // Format phone number (remove any non-digit characters)
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Create logs directory if it doesn't exist
    $log_dir = __DIR__ . '/../../logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0777, true);
    }
    
    // Log original and cleaned phone
    file_put_contents($log_dir . '/sms_debug.log', date('Y-m-d H:i:s') . " - Original: $original_phone, Cleaned: $phone\n", FILE_APPEND);
    
    // Ensure Kenyan format (254XXXXXXXX)
    if (strlen($phone) == 9) {
        // Assuming it's 7XXXXXXX (missing 254)
        $phone = '254' . $phone;
    } elseif (strlen($phone) == 10 && substr($phone, 0, 1) == '0') {
        // Format: 07XXXXXXXX -> 2547XXXXXXXX
        $phone = '254' . substr($phone, 1);
    } elseif (strlen($phone) == 10 && substr($phone, 0, 3) == '254') {
        // Format: 254XXXXXXX (missing one digit)
        $phone = $phone;
    } elseif (strlen($phone) == 12 && substr($phone, 0, 3) == '254') {
        // Format: 2547XXXXXXXX (correct)
        $phone = $phone;
    } elseif (strlen($phone) == 13 && substr($phone, 0, 4) == '+254') {
        // Format: +2547XXXXXXXX
        $phone = substr($phone, 1);
    } elseif (strlen($phone) == 11 && substr($phone, 0, 1) == '0') {
        // Format: 07XXXXXXXXX (too long, but try)
        $phone = '254' . substr($phone, 1);
    } else {
        file_put_contents($log_dir . '/sms_debug.log', date('Y-m-d H:i:s') . " - Invalid format for: $original_phone (length: " . strlen($phone) . ")\n", FILE_APPEND);
        return ['success' => false, 'message' => 'Invalid phone number format. Use format: 2547XXXXXXXX', 'data' => null];
    }
    
    // Final validation - must be 12 digits starting with 254
    if (strlen($phone) != 12 || substr($phone, 0, 3) != '254') {
        file_put_contents($log_dir . '/sms_debug.log', date('Y-m-d H:i:s') . " - Invalid after formatting: $phone (length: " . strlen($phone) . ")\n", FILE_APPEND);
        return ['success' => false, 'message' => 'Invalid phone number format. Must be 12 digits starting with 254. Got: ' . $phone, 'data' => null];
    }
    
    // Truncate message if too long
    $message = substr($message, 0, 160);
    
    // Prepare API request
    $url = 'https://api.sandbox.africastalking.com/version1/messaging';
    
    $data = [
        'username' => $username,
        'to' => $phone,
        'message' => $message,
        'from' => $from
    ];
    
    // Log the request data
    file_put_contents($log_dir . '/sms_debug.log', date('Y-m-d H:i:s') . " - Sending to: $phone, Message: " . substr($message, 0, 50) . "\n", FILE_APPEND);
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apiKey: ' . $api_key,
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Execute request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Log the response
    file_put_contents($log_dir . '/sms_debug.log', date('Y-m-d H:i:s') . " - Response HTTP $http_code: " . $response . "\n", FILE_APPEND);
    
    // Log the attempt
    $log_entry = date('Y-m-d H:i:s') . " | To: $phone | Message: " . substr($message, 0, 50) . "... | HTTP: $http_code\n";
    file_put_contents($log_dir . '/sms.log', $log_entry, FILE_APPEND);
    
    // Parse response
    if ($curl_error) {
        file_put_contents($log_dir . '/sms_debug.log', date('Y-m-d H:i:s') . " - cURL error: $curl_error\n", FILE_APPEND);
        return ['success' => false, 'message' => 'cURL error: ' . $curl_error, 'data' => null];
    }
    
    $result = json_decode($response, true);
    
    // Check for successful response
    if ($http_code == 201 || $http_code == 200) {
        if (isset($result['SMSMessageData']['Recipients'][0])) {
            $recipient = $result['SMSMessageData']['Recipients'][0];
            if ($recipient['status'] == 'Success') {
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully',
                    'data' => $recipient
                ];
            } else {
                $error_msg = $recipient['status'] ?? 'Unknown error';
                return ['success' => false, 'message' => 'API Error: ' . $error_msg, 'data' => $recipient];
            }
        } elseif (isset($result['errorMessage'])) {
            return ['success' => false, 'message' => 'API Error: ' . $result['errorMessage'], 'data' => $result];
        } else {
            return ['success' => false, 'message' => 'Unknown API response format', 'data' => $result];
        }
    } else {
        $error_msg = $result['errorMessage'] ?? 'HTTP Error: ' . $http_code;
        return ['success' => false, 'message' => $error_msg, 'data' => $result];
    }
}

/**
 * Send bulk SMS to multiple recipients
 * 
 * @param array $recipients Array of phone numbers
 * @param string $message SMS message content
 * @return array Results for each recipient
 */
function sendBulkSMS($recipients, $message) {
    $results = [];
    foreach ($recipients as $recipient) {
        $results[] = sendSMS($recipient, $message);
    }
    return $results;
}

/**
 * Broadcast emergency alert to affected users
 * 
 * @param string $title Alert title
 * @param string $message Alert message
 * @param string $priority Priority level (danger, warning, evacuation, shelter, info)
 * @param array $targetArea Target area (county, coordinates, etc.)
 * @param PDO $pdo Database connection
 * @return array Result of broadcast
 */
function broadcastAlert($title, $message, $priority, $targetArea, $pdo) {
    // Get user_id from session or use a default system user
    $user_id = $_SESSION['user_id'] ?? 0;
    
    // If user_id is 0 or doesn't exist, find a valid admin user
    if ($user_id == 0) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' OR role = 'administrator' LIMIT 1");
        $stmt->execute();
        $admin = $stmt->fetch();
        if ($admin) {
            $user_id = $admin['id'];
        } else {
            // If no admin, get the first user
            $stmt = $pdo->prepare("SELECT id FROM users LIMIT 1");
            $stmt->execute();
            $first_user = $stmt->fetch();
            if ($first_user) {
                $user_id = $first_user['id'];
            } else {
                return [
                    'success' => false,
                    'error' => 'No users found in database to assign as creator',
                    'alert_id' => null
                ];
            }
        }
    }
    
    // Map priority to alert_type
    $valid_alert_types = ['danger', 'warning', 'evacuation', 'shelter', 'info'];
    $alert_type = in_array($priority, $valid_alert_types) ? $priority : 'warning';
    
    // Handle affected area bounds
    $lat_min = null;
    $lat_max = null;
    $lon_min = null;
    $lon_max = null;
    
    if (isset($targetArea['bounds'])) {
        $lat_min = $targetArea['bounds']['lat_min'] ?? null;
        $lat_max = $targetArea['bounds']['lat_max'] ?? null;
        $lon_min = $targetArea['bounds']['lon_min'] ?? null;
        $lon_max = $targetArea['bounds']['lon_max'] ?? null;
    } elseif (isset($targetArea['lat']) && isset($targetArea['lng'])) {
        $lat = $targetArea['lat'];
        $lng = $targetArea['lng'];
        $lat_min = $lat - 0.05;
        $lat_max = $lat + 0.05;
        $lon_min = $lng - 0.05;
        $lon_max = $lng + 0.05;
    }
    
    try {
        // First, check if the created_by user exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        if ($stmt->rowCount() == 0) {
            return [
                'success' => false,
                'error' => "User with ID $user_id does not exist",
                'alert_id' => null
            ];
        }
        
        // Insert into alerts table
        $stmt = $pdo->prepare("
            INSERT INTO alerts (
                alert_type, 
                title, 
                message, 
                affected_area_lat_min,
                affected_area_lat_max,
                affected_area_lon_min,
                affected_area_lon_max,
                created_by, 
                created_at, 
                expires_at,
                send_sms
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR), 1
            )
        ");
        
        $stmt->execute([
            $alert_type,
            $title,
            $message,
            $lat_min,
            $lat_max,
            $lon_min,
            $lon_max,
            $user_id
        ]);
        
        $alert_id = $pdo->lastInsertId();
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage(),
            'alert_id' => null
        ];
    }
    
    // Determine which users to notify
    $users = [];
    $sms_results = [];
    
    try {
        if ($targetArea['type'] === 'all') {
            $stmt = $pdo->prepare("
                SELECT id, full_name, phone FROM users 
                WHERE phone IS NOT NULL AND phone != '' AND (sms_subscribed = 1 OR sms_subscribed IS NULL)
            ");
            $stmt->execute();
            $users = $stmt->fetchAll();
            
        } elseif ($targetArea['type'] === 'county') {
            $stmt = $pdo->prepare("
                SELECT id, full_name, phone FROM users 
                WHERE county = ? AND phone IS NOT NULL AND phone != '' AND (sms_subscribed = 1 OR sms_subscribed IS NULL)
            ");
            $stmt->execute([$targetArea['county']]);
            $users = $stmt->fetchAll();
            
        } else {
            // Default to all users
            $stmt = $pdo->prepare("
                SELECT id, full_name, phone FROM users 
                WHERE phone IS NOT NULL AND phone != '' AND (sms_subscribed = 1 OR sms_subscribed IS NULL)
            ");
            $stmt->execute();
            $users = $stmt->fetchAll();
        }
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'error' => 'Error fetching users: ' . $e->getMessage(),
            'alert_id' => $alert_id
        ];
    }
    
    // Send SMS notifications
    $sms_sent = 0;
    $sms_failed = 0;
    $alert_type_upper = strtoupper($alert_type);
    $alert_message = "[$alert_type_upper] $title\n\n$message\n\nReply STOP to unsubscribe";
    
    foreach ($users as $user) {
        if (!empty($user['phone'])) {
            // Format the phone number
            $formatted_phone = preg_replace('/[^0-9]/', '', $user['phone']);
            
            // Format to 254 format
            if (strlen($formatted_phone) == 10 && substr($formatted_phone, 0, 1) == '0') {
                $formatted_phone = '254' . substr($formatted_phone, 1);
            } elseif (strlen($formatted_phone) == 9) {
                $formatted_phone = '254' . $formatted_phone;
            } elseif (strlen($formatted_phone) == 13 && substr($formatted_phone, 0, 4) == '+254') {
                $formatted_phone = substr($formatted_phone, 1);
            }
            
            // Only send if phone number is valid (12 digits starting with 254)
            if (strlen($formatted_phone) == 12 && substr($formatted_phone, 0, 3) == '254') {
                $result = sendSMS($formatted_phone, $alert_message);
                
                if ($result['success']) {
                    $sms_sent++;
                } else {
                    $sms_failed++;
                    $log_dir = __DIR__ . '/../../logs';
                    if (!is_dir($log_dir)) {
                        mkdir($log_dir, 0777, true);
                    }
                    file_put_contents($log_dir . '/failed_sms.log', date('Y-m-d H:i:s') . " - User: {$user['id']}, Phone: {$user['phone']}, Formatted: $formatted_phone, Error: {$result['message']}\n", FILE_APPEND);
                }
                
                $sms_results[] = $result;
            } else {
                $sms_failed++;
                $sms_results[] = [
                    'success' => false,
                    'message' => "Invalid phone number format after formatting: $formatted_phone",
                    'data' => null
                ];
            }
        }
    }
    
    return [
        'success' => true,
        'alert_id' => $alert_id,
        'alert_type' => $alert_type,
        'total_recipients' => count($users),
        'sms_sent' => $sms_sent,
        'sms_failed' => $sms_failed,
        'details' => $sms_results
    ];
}

/**
 * Check SMS credit balance
 * 
 * @return array Balance information
 */
function checkSMSBalance() {
    $username = 'sandbox';
    $api_key = 'atsk_b59122ea545a590f16cc77b4cf492afdc07a44ccad8e37cbcef8519b0708ea6ea434e551';
    
    $url = 'https://api.sandbox.africastalking.com/version1/user';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apiKey: ' . $api_key,
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        $result = json_decode($response, true);
        
        if (isset($result['UserData']['balance'])) {
            return [
                'success' => true,
                'balance' => $result['UserData']['balance']
            ];
        }
    }
    
    return ['success' => false, 'message' => 'Could not fetch balance'];
}

/**
 * Process SMS Queue - To be called by cron job
 * 
 * @param PDO $pdo Database connection
 * @return array Processing results
 */
function processSMSQueue($pdo) {
    // Check if sms_queue table exists
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'sms_queue'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'message' => "sms_queue table does not exist"
            ];
        }
        
        // Get pending messages
        $stmt = $pdo->prepare("
            SELECT id, recipient_phone, message, attempts 
            FROM sms_queue 
            WHERE status = 'pending' AND attempts < 3
            ORDER BY created_at ASC
            LIMIT 50
        ");
        $stmt->execute();
        $pending = $stmt->fetchAll();
        
        $processed = 0;
        $sent = 0;
        $failed = 0;
        
        foreach ($pending as $sms) {
            $processed++;
            
            // Update attempts
            $stmt = $pdo->prepare("UPDATE sms_queue SET attempts = attempts + 1 WHERE id = ?");
            $stmt->execute([$sms['id']]);
            
            // Format phone number
            $phone = $sms['recipient_phone'];
            $phone = preg_replace('/[^0-9]/', '', $phone);
            
            if (strlen($phone) == 10 && substr($phone, 0, 1) == '0') {
                $phone = '254' . substr($phone, 1);
            } elseif (strlen($phone) == 9) {
                $phone = '254' . $phone;
            } elseif (strlen($phone) == 13 && substr($phone, 0, 4) == '+254') {
                $phone = substr($phone, 1);
            }
            
            // Send SMS
            $result = sendSMS($phone, $sms['message']);
            
            if ($result['success']) {
                $sent++;
                $stmt = $pdo->prepare("UPDATE sms_queue SET status = 'sent', sent_at = NOW() WHERE id = ?");
                $stmt->execute([$sms['id']]);
            } else {
                $failed++;
                $stmt = $pdo->prepare("UPDATE sms_queue SET status = 'failed', error_message = ? WHERE id = ?");
                $stmt->execute([$result['message'], $sms['id']]);
            }
        }
        
        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'message' => "Processed $processed SMS messages (Sent: $sent, Failed: $failed)"
        ];
        
    } catch (PDOException $e) {
        return [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'message' => "Database error: " . $e->getMessage()
        ];
    }
}
?>