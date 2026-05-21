<?php
/**
 * API v1 - Incidents
 * Disaster Response & Resource Coordination System
 * 
 * Endpoints:
 *   GET  /api/v1/incidents          - Get all active incidents (GeoJSON)
 *   GET  /api/v1/incidents?id=123   - Get single incident
 *   GET  /api/v1/incidents?status=reported - Filter by status
 *   POST /api/v1/incidents          - Submit new incident
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../includes/config/config.php';

// Helper function to send JSON response
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit();
}

// GET request - fetch incidents
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $status = $_GET['status'] ?? 'active';
    $incident_type = $_GET['type'] ?? null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    
    try {
        if ($id > 0) {
            // Fetch single incident
            $stmt = $pdo->prepare("
                SELECT i.*, u.full_name as reporter_name, u.phone as reporter_phone,
                       r.full_name as responder_name
                FROM incidents i
                LEFT JOIN users u ON i.reporter_id = u.id
                LEFT JOIN users r ON i.assigned_to = r.id
                WHERE i.id = ?
            ");
            $stmt->execute([$id]);
            $incident = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$incident) {
                sendResponse(['error' => 'Incident not found'], 404);
            }
            
            sendResponse($incident);
        } else {
            // Fetch multiple incidents as GeoJSON
            $sql = "
                SELECT id, incident_type, severity, description, latitude, longitude, 
                       location_name, status, reported_at, photo_path
                FROM incidents
                WHERE 1=1
            ";
            $params = [];
            
            if ($status === 'active') {
                $sql .= " AND status NOT IN ('resolved', 'cancelled', 'rejected')";
            } elseif ($status !== 'all') {
                $sql .= " AND status = ?";
                $params[] = $status;
            }
            
            if ($incident_type) {
                $sql .= " AND incident_type = ?";
                $params[] = $incident_type;
            }
            
            $sql .= " ORDER BY severity DESC, reported_at DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Build GeoJSON FeatureCollection
            $features = [];
            $severity_colors = [1 => '#28a745', 2 => '#ffc107', 3 => '#fd7e14', 4 => '#dc3545'];
            
            foreach ($incidents as $incident) {
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float)$incident['longitude'], (float)$incident['latitude']]
                    ],
                    'properties' => [
                        'id' => $incident['id'],
                        'type' => $incident['incident_type'],
                        'severity' => $incident['severity'],
                        'severity_color' => $severity_colors[$incident['severity']],
                        'description' => $incident['description'],
                        'location' => $incident['location_name'],
                        'status' => $incident['status'],
                        'reported_at' => $incident['reported_at'],
                        'has_photo' => !empty($incident['photo_path'])
                    ]
                ];
            }
            
            sendResponse([
                'type' => 'FeatureCollection',
                'features' => $features,
                'total' => count($features),
                'timestamp' => date('c')
            ]);
        }
    } catch (PDOException $e) {
        sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// POST request - submit new incident
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    // Validate required fields
    $required_fields = ['incident_type', 'latitude', 'longitude', 'description'];
    foreach ($required_fields as $field) {
        if (empty($input[$field])) {
            sendResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    // Check if user is authenticated (optional for public reports)
    $user_id = $_SESSION['user_id'] ?? null;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO incidents (reporter_id, incident_type, severity, description, 
                                   latitude, longitude, location_name, status, reported_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'reported', NOW())
        ");
        $stmt->execute([
            $user_id,
            $input['incident_type'],
            $input['severity'] ?? 2,
            $input['description'],
            $input['latitude'],
            $input['longitude'],
            $input['location_name'] ?? null
        ]);
        
        $incident_id = $pdo->lastInsertId();
        
        sendResponse([
            'success' => true,
            'message' => 'Incident reported successfully',
            'incident_id' => $incident_id,
            'tracking_url' => "/modules/incidents/view.php?id=$incident_id"
        ], 201);
        
    } catch (PDOException $e) {
        sendResponse(['error' => 'Failed to submit incident: ' . $e->getMessage()], 500);
    }
}
?>