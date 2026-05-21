<?php
/**
 * API v1 - Resources
 * Disaster Response & Resource Coordination System
 * 
 * Endpoints:
 *   GET  /api/v1/resources           - Get available resources
 *   GET  /api/v1/resources?request_id=123 - Get request status
 *   POST /api/v1/resources           - Submit resource request
 *   PUT  /api/v1/resources/{id}      - Update request status
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../includes/config/config.php';

function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit();
}

// GET request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
    $type = $_GET['type'] ?? 'all';
    
    try {
        if ($request_id > 0) {
            // Get specific request status
            $stmt = $pdo->prepare("
                SELECT rr.*, u.full_name as requester_name, u.phone as requester_phone
                FROM resource_requests rr
                JOIN users u ON rr.user_id = u.id
                WHERE rr.id = ?
            ");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                sendResponse(['error' => 'Request not found'], 404);
            }
            
            sendResponse($request);
        } else {
            // Get available resources inventory
            if ($type === 'inventory') {
                $stmt = $pdo->prepare("
                    SELECT resource_type, SUM(quantity) as total_quantity, status
                    FROM resources
                    GROUP BY resource_type, status
                    ORDER BY resource_type
                ");
                $stmt->execute();
                $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
                sendResponse($inventory);
            } else {
                // Get resource types and availability
                $stmt = $pdo->prepare("
                    SELECT DISTINCT resource_type, 
                           (SELECT SUM(quantity) FROM resources WHERE resource_type = r.resource_type AND status = 'available') as available
                    FROM resources r
                    ORDER BY resource_type
                ");
                $stmt->execute();
                $resources = $stmt->fetchAll(PDO::FETCH_ASSOC);
                sendResponse($resources);
            }
        }
    } catch (PDOException $e) {
        sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// POST request - submit resource request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
    
    // Check authentication
    if (!isset($_SESSION['user_id'])) {
        sendResponse(['error' => 'Authentication required'], 401);
    }
    
    $required_fields = ['resource_type', 'quantity', 'location_name'];
    foreach ($required_fields as $field) {
        if (empty($input[$field])) {
            sendResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO resource_requests 
            (user_id, resource_type, quantity, urgency, notes, location_name, latitude, longitude, status, requested_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $input['resource_type'],
            $input['quantity'],
            $input['urgency'] ?? 'medium',
            $input['notes'] ?? null,
            $input['location_name'],
            $input['latitude'] ?? null,
            $input['longitude'] ?? null
        ]);
        
        sendResponse([
            'success' => true,
            'message' => 'Resource request submitted',
            'request_id' => $pdo->lastInsertId()
        ], 201);
        
    } catch (PDOException $e) {
        sendResponse(['error' => 'Failed to submit request: ' . $e->getMessage()], 500);
    }
}

// PUT request - update request status
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    parse_str(file_get_contents('php://input'), $input);
    if (empty($input)) {
        $input = json_decode(file_get_contents('php://input'), true);
    }
    
    $request_id = $input['id'] ?? 0;
    $new_status = $input['status'] ?? '';
    
    if (!$request_id || !$new_status) {
        sendResponse(['error' => 'Missing id or status'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE resource_requests SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_status, $request_id]);
        
        sendResponse(['success' => true, 'message' => 'Request status updated']);
    } catch (PDOException $e) {
        sendResponse(['error' => 'Update failed: ' . $e->getMessage()], 500);
    }
}
?>