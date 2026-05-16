<?php
/**
 * GeoJSON API Endpoint - Active Incidents
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Returns all active incidents as GeoJSON FeatureCollection for Leaflet.js maps
 * Endpoint: incidents.geojson.php?status=active&bounds=lat1,lng1,lat2,lng2
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Get parameters
$status_filter = $_GET['status'] ?? 'active';
$bounds = $_GET['bounds'] ?? null; // For viewport optimization (optional)
$incident_type = $_GET['type'] ?? null;
$severity = isset($_GET['severity']) ? (int)$_GET['severity'] : null;

// Build query
$sql = "
    SELECT 
        i.id,
        i.incident_type,
        i.severity,
        i.description,
        i.latitude,
        i.longitude,
        i.location_name,
        i.status,
        i.photo_path,
        i.reported_at,
        u.full_name as reporter_name,
        u.phone as reporter_phone,
        r.full_name as responder_name
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    LEFT JOIN users r ON i.assigned_to = r.id
    WHERE 1=1
";

$params = [];

// Status filter
if ($status_filter === 'active') {
    $sql .= " AND i.status NOT IN ('resolved', 'cancelled', 'rejected')";
} elseif ($status_filter === 'pending') {
    $sql .= " AND i.status = 'reported'";
} elseif ($status_filter === 'resolved') {
    $sql .= " AND i.status = 'resolved'";
}

// Type filter
if ($incident_type) {
    $sql .= " AND i.incident_type = ?";
    $params[] = $incident_type;
}

// Severity filter
if ($severity && $severity >= 1 && $severity <= 4) {
    $sql .= " AND i.severity = ?";
    $params[] = $severity;
}

// Order by severity (critical first) and then by date
$sql .= " ORDER BY i.severity DESC, i.reported_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$incidents = $stmt->fetchAll();

// Build GeoJSON FeatureCollection
$features = [];
$severity_colors = [
    1 => '#28a745', // Low - Green
    2 => '#ffc107', // Medium - Yellow
    3 => '#fd7e14', // High - Orange
    4 => '#dc3545'  // Critical - Red
];

$severity_icons = [
    1 => '🟢',
    2 => '🟡',
    3 => '🟠',
    4 => '🔴'
];

$type_icons = [
    'flood' => '🌊',
    'fire' => '🔥',
    'earthquake' => '🏚️',
    'landslide' => '⛰️',
    'drought' => '☀️',
    'accident' => '🚗',
    'building_collapse' => '🏗️',
    'disease_outbreak' => '🦠',
    'other' => '⚠️'
];

foreach ($incidents as $incident) {
    $icon = $type_icons[$incident['incident_type']] ?? '📍';
    $severity_label = '';
    switch($incident['severity']) {
        case 4: $severity_label = 'Critical'; break;
        case 3: $severity_label = 'High'; break;
        case 2: $severity_label = 'Medium'; break;
        default: $severity_label = 'Low';
    }
    
    $features[] = [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [(float)$incident['longitude'], (float)$incident['latitude']]
        ],
        'properties' => [
            'id' => $incident['id'],
            'type' => $incident['incident_type'],
            'type_label' => ucfirst(str_replace('_', ' ', $incident['incident_type'])),
            'type_icon' => $icon,
            'severity' => $incident['severity'],
            'severity_label' => $severity_label,
            'severity_color' => $severity_colors[$incident['severity']],
            'severity_icon' => $severity_icons[$incident['severity']],
            'description' => $incident['description'],
            'location_name' => $incident['location_name'] ?? 'Location provided',
            'status' => $incident['status'],
            'status_label' => ucfirst(str_replace('-', ' ', $incident['status'])),
            'reported_at' => date('c', strtotime($incident['reported_at'])),
            'reported_at_formatted' => date('F j, Y \a\t g:i A', strtotime($incident['reported_at'])),
            'reporter_name' => $incident['reporter_name'] ?? 'Anonymous',
            'reporter_phone' => $incident['reporter_phone'] ?? null,
            'responder_name' => $incident['responder_name'] ?? 'Not assigned',
            'has_photo' => !empty($incident['photo_path']),
            'photo_url' => $incident['photo_path'] ?? null
        ]
    ];
}

$response = [
    'type' => 'FeatureCollection',
    'features' => $features,
    'total' => count($features),
    'generated_at' => date('c'),
    'bounds' => $bounds
];

echo json_encode($response, JSON_PRETTY_PRINT);
?>