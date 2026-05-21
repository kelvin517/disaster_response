<?php
/**
 * API v1 - Volunteers
 * Disaster Response & Resource Coordination System
 * 
 * Endpoints:
 *   GET  /api/v1/volunteers           - Get volunteers (filter by skill/location)
 *   GET  /api/v1/volunteers/match     - Get skill matching suggestions
 *   POST /api/v1/volunteers           - Register/update volunteer profile
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
    $skill = $_GET['skill'] ?? null;
    $location = $_GET['location'] ?? null;
    $match = isset($_GET['match']);
    
    try {
        if ($match) {
            // Skill matching suggestions for an incident
            $incident_type = $_GET['incident_type'] ?? null;
            $severity = isset($_GET['severity']) ? (int)$_GET['severity'] : null;
            
            // Map incident type to required skills
            $skill_mapping = [
                'flood' => ['Swift Water Rescue', 'First Aid', 'Logistics'],
                'fire' => ['Fire Rescue', 'First Aid', 'Emergency Medical'],
                'earthquake' => ['Urban SAR', 'First Aid', 'Medical'],
                'medical' => ['Medical', 'First Aid', 'CPR']
            ];
            
            $required_skills = $skill_mapping[$incident_type] ?? ['General Volunteer'];
            
            $placeholders = str_repeat('?,', count($required_skills) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT v.*, u.full_name, u.phone, u.email
                FROM volunteers v
                JOIN users u ON v.user_id = u.id
                WHERE v.availability_status = 'available'
                  AND (v.skills LIKE ? OR v.skills LIKE ? OR v.skills LIKE ?)
                LIMIT 10
            ");
            
            // Simple skill matching - in production, use full-text search
            $like_conditions = [];
            foreach ($required_skills as $skill) {
                $like_conditions[] = "v.skills LIKE '%$skill%'";
            }
            $sql = "
                SELECT v.*, u.full_name, u.phone, u.email
                FROM volunteers v
                JOIN users u ON v.user_id = u.id
                WHERE v.availability_status = 'available'
                  AND (" . implode(' OR ', $like_conditions) . ")
                LIMIT 10
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            sendResponse([
                'incident_type' => $incident_type,
                'required_skills' => $required_skills,
                'matches' => $matches,
                'total_matches' => count($matches)
            ]);
        } else {
            // Get volunteers with optional skill filter
            $sql = "
                SELECT v.*, u.full_name, u.phone, u.email
                FROM volunteers v
                JOIN users u ON v.user_id = u.id
                WHERE 1=1
            ";
            $params = [];
            
            if ($skill) {
                $sql .= " AND v.skills LIKE ?";
                $params[] = "%$skill%";
            }
            
            if ($location) {
                // Simple location matching - enhance with geospatial in production
                $sql .= " AND (v.latitude IS NOT NULL AND v.longitude IS NOT NULL)";
            }
            
            $sql .= " ORDER BY u.full_name LIMIT 100";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $volunteers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            sendResponse($volunteers);
        }
    } catch (PDOException $e) {
        sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// POST request - register/update volunteer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
    
    if (!isset($_SESSION['user_id'])) {
        sendResponse(['error' => 'Authentication required'], 401);
    }
    
    $required_fields = ['skills'];
    foreach ($required_fields as $field) {
        if (empty($input[$field])) {
            sendResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        // Check if volunteer exists
        $stmt = $pdo->prepare("SELECT id FROM volunteers WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            $stmt = $pdo->prepare("
                UPDATE volunteers 
                SET skills = ?, availability_status = ?, latitude = ?, longitude = ?, updated_at = NOW()
                WHERE user_id = ?
            ");
            $stmt->execute([
                $input['skills'],
                $input['availability_status'] ?? 'available',
                $input['latitude'] ?? null,
                $input['longitude'] ?? null,
                $_SESSION['user_id']
            ]);
            $message = "Volunteer profile updated";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO volunteers (user_id, skills, availability_status, latitude, longitude, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                $input['skills'],
                $input['availability_status'] ?? 'available',
                $input['latitude'] ?? null,
                $input['longitude'] ?? null
            ]);
            $message = "Volunteer registered successfully";
        }
        
        sendResponse(['success' => true, 'message' => $message], 200);
        
    } catch (PDOException $e) {
        sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}
?>