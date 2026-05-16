<?php
/**
 * Duplicate Check API Endpoint
 * Checks for recent similar incidents within geographic radius
 * Called via AJAX from report.php
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';

header('Content-Type: application/json');

// Get parameters
$latitude = filter_var($_GET['lat'] ?? 0, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($_GET['lng'] ?? 0, FILTER_VALIDATE_FLOAT);
$incident_type = $_GET['type'] ?? '';
$radius_km = 0.5; // 500 meters
$time_window = 30; // minutes

if (!$latitude || !$longitude || !$incident_type) {
    echo json_encode(['has_duplicate' => false, 'error' => 'Missing parameters']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, incident_type, severity, location_name, reported_at,
            ( 6371 * acos( cos(radians(:lat)) * cos(radians(latitude)) 
              * cos(radians(longitude) - radians(:lng)) + sin(radians(:lat)) 
              * sin(radians(latitude)) ) ) AS distance,
            TIMESTAMPDIFF(MINUTE, reported_at, NOW()) AS minutes_ago
        FROM incidents
        WHERE incident_type = :type
          AND status NOT IN ('resolved', 'rejected', 'closed')
          AND reported_at > DATE_SUB(NOW(), INTERVAL :time_window MINUTE)
        HAVING distance < :radius
        ORDER BY distance ASC
        LIMIT 1
    ");
    
    $stmt->execute([
        ':lat' => $latitude,
        ':lng' => $longitude,
        ':type' => $incident_type,
        ':radius' => $radius_km,
        ':time_window' => $time_window
    ]);
    
    $duplicate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($duplicate) {
        echo json_encode([
            'has_duplicate' => true,
            'incident_id' => $duplicate['id'],
            'type' => $duplicate['incident_type'],
            'distance_km' => round($duplicate['distance'], 2),
            'minutes_ago' => $duplicate['minutes_ago'],
            'severity' => $duplicate['severity'],
            'location' => $duplicate['location_name']
        ]);
    } else {
        echo json_encode(['has_duplicate' => false]);
    }
    
} catch (PDOException $e) {
    error_log('Duplicate check error: ' . $e->getMessage());
    echo json_encode(['has_duplicate' => false, 'error' => 'Database error']);
}
?>