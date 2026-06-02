<?php
/**
 * GeoJSON API Endpoint — Active Incidents
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 *
 * Returns active incidents as a GeoJSON FeatureCollection for Leaflet.js maps.
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

/* Auth: only logged-in users with a map-capable role */
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.0 403 Forbidden');
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

/* CORS / Content-Type */
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');
header('Content-Type: application/json; charset=utf-8');

/* Input sanitisation */
$status_filter = $_GET['status'] ?? 'active';
$type_filter = isset($_GET['type']) ? trim($_GET['type']) : null;
$severity_filter = isset($_GET['severity']) ? (int)$_GET['severity'] : null;
$bounds_raw = $_GET['bounds'] ?? null;
$limit = min(max((int)($_GET['limit'] ?? 500), 1), 2000);

/* Validate status */
$allowed_statuses = ['active', 'pending', 'resolved', 'all'];
if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = 'active';
}

/* Validate severity */
if ($severity_filter !== null && ($severity_filter < 1 || $severity_filter > 4)) {
    $severity_filter = null;
}

/* Parse bounding box: sw_lat,sw_lng,ne_lat,ne_lng */
$bounds_parsed = null;
if ($bounds_raw) {
    $parts = array_map('floatval', explode(',', $bounds_raw));
    if (count($parts) === 4
        && $parts[0] >= -90 && $parts[0] <= 90
        && $parts[2] >= -90 && $parts[2] <= 90
        && $parts[1] >= -180 && $parts[1] <= 180
        && $parts[3] >= -180 && $parts[3] <= 180
    ) {
        $bounds_parsed = ['sw_lat' => $parts[0], 'sw_lng' => $parts[1], 'ne_lat' => $parts[2], 'ne_lng' => $parts[3]];
    }
}

/* Build SQL */
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
        i.updated_at,
        COALESCE(u.full_name, 'Anonymous') AS reporter_name,
        COALESCE(u.phone, '') AS reporter_phone,
        COALESCE(r.full_name, 'Unassigned') AS responder_name
    FROM incidents i
    LEFT JOIN users u ON u.id = i.reporter_id
    LEFT JOIN users r ON r.id = i.assigned_to
    WHERE i.latitude IS NOT NULL
      AND i.longitude IS NOT NULL
      AND i.latitude != 0
      AND i.longitude != 0
      AND ABS(i.latitude) <= 90
      AND ABS(i.longitude) <= 180
";
$params = [];

/* Status clause */
switch ($status_filter) {
    case 'active':
        $sql .= " AND i.status NOT IN ('resolved','cancelled','rejected')";
        break;
    case 'pending':
        $sql .= " AND i.status = 'reported'";
        break;
    case 'resolved':
        $sql .= " AND i.status = 'resolved'";
        break;
}

/* Type filter */
if ($type_filter !== null && $type_filter !== '') {
    $sql .= " AND i.incident_type = ?";
    $params[] = $type_filter;
}

/* Severity filter */
if ($severity_filter !== null) {
    $sql .= " AND i.severity = ?";
    $params[] = $severity_filter;
}

/* Bounding-box clip */
if ($bounds_parsed) {
    $sql .= " AND i.latitude BETWEEN ? AND ?";
    $params[] = $bounds_parsed['sw_lat'];
    $params[] = $bounds_parsed['ne_lat'];
    $sql .= " AND i.longitude BETWEEN ? AND ?";
    $params[] = $bounds_parsed['sw_lng'];
    $params[] = $bounds_parsed['ne_lng'];
}

$sql .= " ORDER BY i.severity DESC, i.reported_at DESC LIMIT " . (int)$limit;

/* Execute */
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('GeoJSON feed error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'features' => [], 'total' => 0]);
    exit;
}

/* Lookup tables */
$severity_colors = [
    1 => '#16a34a',   // Low
    2 => '#d97706',   // Medium
    3 => '#ea580c',   // High
    4 => '#e8271d',   // Critical
];
$severity_labels = [1 => 'Low', 2 => 'Medium', 3 => 'High', 4 => 'Critical'];
$severity_emojis = [1 => '🟢', 2 => '🟡', 3 => '🟠', 4 => '🔴'];

$type_emojis = [
    'flood' => '🌊',
    'fire' => '🔥',
    'earthquake' => '🏚️',
    'landslide' => '⛰️',
    'drought' => '☀️',
    'accident' => '🚗',
    'building_collapse' => '🏗️',
    'disease_outbreak' => '🦠',
    'other' => '⚠️',
];

/* Build GeoJSON features */
$features = [];
$sev_counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

foreach ($incidents as $inc) {
    $lat = (float)$inc['latitude'];
    $lng = (float)$inc['longitude'];

    /* Extra validation */
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        continue;
    }

    $sev = (int)$inc['severity'];
    $sev = ($sev >= 1 && $sev <= 4) ? $sev : 1;
    $sev_counts[$sev]++;

    $type = $inc['incident_type'] ?? 'other';
    $photo = !empty($inc['photo_path']) ? $inc['photo_path'] : null;

    $features[] = [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [$lng, $lat],
        ],
        'properties' => [
            'id' => (int)$inc['id'],
            'type' => $type,
            'type_label' => ucfirst(str_replace('_', ' ', $type)),
            'type_emoji' => $type_emojis[$type] ?? '⚠️',
            'severity' => $sev,
            'severity_label' => $severity_labels[$sev],
            'severity_color' => $severity_colors[$sev],
            'severity_emoji' => $severity_emojis[$sev],
            'location_name' => $inc['location_name'] ?? '',
            'status' => $inc['status'],
            'status_label' => ucfirst(str_replace('-', ' ', $inc['status'])),
            'reported_at' => date('c', strtotime($inc['reported_at'])),
            'reported_at_display' => date('M j, Y \a\t H:i', strtotime($inc['reported_at'])),
            'updated_at' => $inc['updated_at'] ? date('c', strtotime($inc['updated_at'])) : null,
            'reporter_name' => $inc['reporter_name'],
            'reporter_phone' => $inc['reporter_phone'] ?: null,
            'responder_name' => $inc['responder_name'],
            'description' => mb_substr($inc['description'] ?? '', 0, 300),
            'has_photo' => $photo !== null,
            'photo_url' => $photo,
        ],
    ];
}

/* Assemble response */
$response = [
    'type' => 'FeatureCollection',
    'features' => $features,
    'total' => count($features),
    'severity_counts' => $sev_counts,
    'filters' => [
        'status' => $status_filter,
        'type' => $type_filter,
        'severity' => $severity_filter,
        'bounds' => $bounds_parsed,
        'limit' => $limit,
    ],
    'generated_at' => date('c'),
];

/* Output */
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);